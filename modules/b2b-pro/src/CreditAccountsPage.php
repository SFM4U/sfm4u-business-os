<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

final class CreditAccountsPage
{
    private const ACTION = 'sfm4u_b2b_save_account';
    private const PAYMENT = 'sfm4u_b2b_record_payment';
    private const PAGE = 'sfm4u-b2b-accounts';
    private const CAP = 'sfm4u_b2b_settle';

    public function __construct(private \wpdb $db) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('B2B Credit Accounts', 'sfm4u-wholesale'),
                __('B2B Credit Accounts', 'sfm4u-wholesale'), self::CAP, self::PAGE, [$this, 'render']);
        });
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
        add_action('admin_post_' . self::PAYMENT, [$this, 'savePayment']);
    }

    public function save(): void
    {
        $buyer = absint(self::input('buyer'));
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Access denied.', 'sfm4u-wholesale'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION . '_' . $buyer);
        $user = get_user_by('id', $buyer);
        $limit = self::input('limit');
        $days = self::input('days');
        $grace = self::input('grace');
        $currency = get_woocommerce_currency();

        if (!$user || !preg_match('/^[0-9]{1,10}$/D', $limit) || !preg_match('/^[0-9]{1,3}$/D', $grace)
            || !in_array($days, ['7', '15', '30', '45', '60', '90'], true)
            || (int) $grace > 365 || wc_get_price_decimals() !== 2 || !preg_match('/^[A-Z]{3}$/D', $currency)
            || (get_user_meta($buyer, '_sfmwh_buyer_status', true) !== 'approved' && !$this->account($buyer))) {
            wp_die(esc_html__('Check the buyer, credit limit and terms.', 'sfm4u-wholesale'), '', ['response' => 422]);
        }
        // The primary key serializes this update with orders locking the same credit account.
        // Existing currency stays fixed so old invoices are always interpreted in their original currency.
        $saved = $this->db->query($this->db->prepare(
            'INSERT INTO %i (buyer,credit_limit,net_days,grace_days,currency) VALUES (%d,%d,%d,%d,%s)
             ON DUPLICATE KEY UPDATE credit_limit=%d,net_days=%d,grace_days=%d',
            $this->db->prefix . 'sfm4u_b2b_accounts', $buyer, (int) $limit, (int) $days, (int) $grace, $currency,
            (int) $limit, (int) $days, (int) $grace
        ));
        if ($saved === false) {
            wp_die(esc_html__('The credit account could not be saved.', 'sfm4u-wholesale'), '', ['response' => 503]);
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'buyer' => $buyer], admin_url('admin.php')), 303);
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $buyer = isset($_GET['buyer']) && is_string($_GET['buyer']) ? absint($_GET['buyer']) : 0;
        echo '<div class="wrap"><h1>' . esc_html__('B2B Credit Accounts', 'sfm4u-wholesale') . '</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">';
        echo '<label>' . esc_html__('Buyer user ID', 'sfm4u-wholesale') . ' <input type="number" min="1" name="buyer" required value="' . esc_attr((string) $buyer) . '"></label> ';
        submit_button(__('Open account', 'sfm4u-wholesale'), 'secondary', '', false);
        echo '</form>';
        if ($buyer && get_user_by('id', $buyer)) {
            $account = $this->account($buyer);
            $currency = $account['currency'] ?? get_woocommerce_currency();
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            echo '<input type="hidden" name="buyer" value="' . esc_attr((string) $buyer) . '">';
            wp_nonce_field(self::ACTION . '_' . $buyer);
            echo '<p><label>' . esc_html__('Credit limit in minor units', 'sfm4u-wholesale') . ' (' . esc_html($currency)
                . ') <input type="number" min="0" max="9999999999" name="limit" required value="' . esc_attr((string) ($account['credit_limit'] ?? 0)) . '"></label></p>';
            echo '<p><label>' . esc_html__('Net days', 'sfm4u-wholesale') . ' <select name="days">';
            foreach ([7, 15, 30, 45, 60, 90] as $days) {
                echo '<option value="' . $days . '"' . selected($days, $account['net_days'] ?? 30, false) . '>' . $days . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Grace days', 'sfm4u-wholesale') . ' <input type="number" min="0" max="365" name="grace" required value="'
                . esc_attr((string) ($account['grace_days'] ?? 0)) . '"></label></p>';
            submit_button(__('Save credit account', 'sfm4u-wholesale'));
            echo '</form>';
            if ($account) {
                $this->renderLedger($buyer);
            }
        }
        echo '</div>';
    }

    public function recordPayment(int $buyer, string $nonce, string $key, string $amount, string $reference): array
    {
        if (!current_user_can(self::CAP) || !wp_verify_nonce($nonce, self::PAYMENT . '_' . $buyer)) {
            throw new \DomainException('forbidden', 403);
        }
        $account = $this->account($buyer);
        if (!$account || !preg_match('/^[0-9]{1,10}$/D', $amount) || (int) $amount < 1) {
            throw new \DomainException('settlement_invalid', 422);
        }
        return (new WooQuotes($this->db, new WooCatalog()))->settle(get_current_user_id(), $buyer, $key, (int) $amount, trim($reference));
    }

    public function savePayment(): void
    {
        $buyer = absint(self::input('buyer'));
        try {
            $this->recordPayment($buyer, self::input('_wpnonce'), self::input('request_key'), self::input('amount'), self::input('reference'));
        } catch (\DomainException $error) {
            $status = $error->getCode() === 403 ? 403 : 422;
            wp_die(esc_html($status === 403 ? __('Access denied.', 'sfm4u-wholesale')
                : __('Check the amount, payment reference and open balance.', 'sfm4u-wholesale')), '', ['response' => $status]);
        } catch (\RuntimeException) {
            wp_die(esc_html__('Payment entry could not be confirmed. Retry the same submission.', 'sfm4u-wholesale'),
                '', ['response' => 503]);
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'buyer' => $buyer], admin_url('admin.php')), 303);
        exit;
    }

    private function renderLedger(int $buyer): void
    {
        try {
            $statement = (new WooQuotes($this->db, new WooCatalog()))->statement($buyer);
        } catch (\DomainException | \RuntimeException) {
            echo '<p>' . esc_html__('The data could not be loaded.', 'sfm4u-wholesale') . '</p>';
            return;
        }
        $money = static fn(int $minor): string => $statement['currency'] . ' ' . \SFM4U\Wholesale\Rule::decimal($minor, 2);
        echo '<h2>' . esc_html__('Wholesale due ledger', 'sfm4u-wholesale') . '</h2><dl>';
        foreach ([
            __('Credit limit', 'sfm4u-wholesale') => $statement['credit_limit_minor'],
            __('Open balance', 'sfm4u-wholesale') => $statement['balance_minor'],
            __('Overdue balance', 'sfm4u-wholesale') => $statement['overdue_minor'],
        ] as $label => $amount) {
            echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($money($amount)) . '</dd>';
        }
        echo '</dl><ul>';
        foreach ($statement['invoices'] as $invoice) {
            echo '<li>#' . esc_html((string) $invoice['order_id']) . ' · ' . esc_html($money($invoice['open_minor']))
                . ' · ' . esc_html(wp_date('Y-m-d', $invoice['due_at']))
                . ($invoice['overdue'] ? ' · <strong>' . esc_html__('Overdue', 'sfm4u-wholesale') . '</strong>' : '') . '</li>';
        }
        echo '</ul><h3>' . esc_html__('Recorded Payments', 'sfm4u-wholesale') . '</h3><ul>';
        foreach ($statement['payments'] as $payment) {
            echo '<li>' . esc_html($payment['reference'] . ' · ' . wp_date('Y-m-d', $payment['recorded_at'])
                . ' · ' . $money($payment['amount_minor'])) . '</li>';
        }
        echo '</ul>';
        if ($statement['balance_minor'] <= 0) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::PAYMENT) . '">'
            . '<input type="hidden" name="buyer" value="' . esc_attr((string) $buyer) . '">'
            . '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        wp_nonce_field(self::PAYMENT . '_' . $buyer);
        echo '<p><label>' . esc_html__('Payment amount in minor units', 'sfm4u-wholesale') . ' (' . esc_html($statement['currency']) . ') '
            . '<input name="amount" type="number" min="1" max="9999999999" step="1" required></label></p>'
            . '<p><label>' . esc_html__('Payment reference', 'sfm4u-wholesale') . ' <input name="reference" maxlength="80" required></label></p>'
            . '<p>' . esc_html__('Records a payment already received. No money is moved.', 'sfm4u-wholesale') . '</p>';
        submit_button(__('Record manual payment', 'sfm4u-wholesale'), 'secondary');
        echo '</form>';
    }

    private function account(int $buyer): ?array
    {
        return $this->db->get_row($this->db->prepare(
            'SELECT * FROM %i WHERE buyer=%d', $this->db->prefix . 'sfm4u_b2b_accounts', $buyer
        ), ARRAY_A);
    }

    private static function input(string $name): string
    {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
    }
}
