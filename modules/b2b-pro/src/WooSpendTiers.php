<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

use SFM4U\Inventory\LossAwareWpdb;

final class WooSpendTiers
{
    public const HOOK = 'sfm4u_b2b_refresh_spend_tier';

    public function __construct(private \wpdb $db) {}

    public function register(): void
    {
        add_action('woocommerce_payment_complete', function (int $orderId): void {
            if (!get_option(TierSettingsPage::OPTION)) {
                return;
            }
            $order = wc_get_order($orderId);
            $buyer = $order ? (int) $order->get_customer_id() : 0;
            if ($buyer) {
                // Keep separate jobs for separate payments so a new payment during
                // another worker's spend query cannot be lost.
                as_enqueue_async_action(self::HOOK, [$buyer, $orderId], 'sfm4u-b2b', true);
            }
        });
        add_action(self::HOOK, [$this, 'refresh']);
    }

    public function refresh(int $buyer): void
    {
        $tiers = get_option(TierSettingsPage::OPTION, []);
        if (!$tiers || !user_can($buyer, 'sfm4u_b2b_buy')
            || get_user_meta($buyer, '_sfmwh_buyer_status', true) !== 'approved') {
            return;
        }
        if (!is_array($tiers)) {
            throw new \UnexpectedValueException('Invalid B2B spend tier configuration.');
        }
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !is_string($tier['tier'] ?? null)
                || !preg_match('/^[a-z0-9_-]{1,64}$/D', $tier['tier'])
                || !is_int($tier['min_spend_minor'] ?? null) || $tier['min_spend_minor'] < 0) {
                throw new \UnexpectedValueException('Invalid B2B spend tier configuration.');
            }
        }
        if (min(array_column($tiers, 'min_spend_minor')) !== 0 || wc_get_price_decimals() !== 2) {
            throw new \UnexpectedValueException('B2B spend tiers require a zero baseline and two-decimal currency.');
        }
        $policy = new TierPolicy($tiers);
        $lock = 'sfmtier:' . substr(hash('sha256', DB_NAME . ':' . $this->db->prefix . ':' . $buyer), 0, 48);
        if (!$this->db instanceof LossAwareWpdb || $this->db !== $GLOBALS['wpdb']) {
            throw new \RuntimeException('Tier evaluation requires the pinned WordPress database.');
        }
        $locked = false;
        $this->db->pinTransaction();
        try {
            $locked = (int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,5)', $lock)) === 1;
            if (!$locked) {
                throw new \RuntimeException('Unable to lock buyer tier.');
            }
            $now = time();
            $orders = [];
            $currency = get_woocommerce_currency();
            for ($page = 1; ; $page++) {
                $result = wc_get_orders([
                    'customer_id' => $buyer, 'status' => wc_get_is_paid_statuses(),
                    'date_paid' => ($now - 365 * DAY_IN_SECONDS) . '...' . $now,
                    'limit' => 100, 'page' => $page, 'paginate' => true,
                    'orderby' => 'ID', 'order' => 'ASC',
                ]);
                foreach ($result->orders as $order) {
                    if ($order->get_currency() !== $currency || !$order->get_date_paid()) {
                        continue;
                    }
                    $net = Money::parse((string) $order->get_total('edit'), $currency);
                    foreach ($order->get_refunds() as $refund) {
                        $net = $net->sub(Money::parse((string) $refund->get_amount('edit'), $currency));
                    }
                    $orders[] = ['paid_at' => $order->get_date_paid()->getTimestamp(), 'net_minor' => $net->minor];
                }
                if ($page >= $result->max_num_pages) {
                    break;
                }
            }
            wp_cache_delete($buyer, 'user_meta');
            $current = (string) get_user_meta($buyer, '_sfmwh_spend_tier', true);
            if ($current !== '' && !in_array($current, array_column($tiers, 'tier'), true)) {
                return;
            }
            $result = $policy->evaluate($current, $orders, $now);
            if ($result['changed'] && !update_user_meta($buyer, '_sfmwh_spend_tier', $result['tier'])) {
                throw new \RuntimeException('Unable to persist buyer tier.');
            }
        } finally {
            // This scope uses a session lock without a transaction. Allow its
            // cleanup even when a failed query has invalidated the pinned scope.
            $this->db->unpinTransaction();
            if ($locked) {
                $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }
}
