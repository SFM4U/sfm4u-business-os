<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Market;

defined('ABSPATH') || exit;

/**
 * SFM4U Business OS on AlgiBazar: a seller's own monthly profit and loss.
 * Sales = the seller's product lines in paid orders (processing / completed) of the month, minus refunds.
 * Cost = quantity × buying cost (_sfm_cost: filled automatically for goods that came from SFM4U Wholesale,
 * or typed by the seller). Lines without a cost are counted as "cost unknown" so the profit is never overstated.
 * Only the seller's own products are shown; nothing about other stores.
 */
final class VendorProfit {
    public const ENDPOINT = 'sfm4u-profit';
    public const STATUSES = ['wc-processing', 'wc-completed'];

    public static function register(): void {
        add_action('init', static function () { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); });
        add_filter('query_vars', static function (array $v) { $v[] = self::ENDPOINT; return $v; });
        add_filter('woocommerce_account_menu_items', [self::class, 'menu'], 20);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'page']);
        add_filter('dokan_get_dashboard_nav', [self::class, 'dokanNav'], 20);
        add_action('template_redirect', [self::class, 'saveCosts']);
        add_action('admin_init', static function () {
            if (get_option('sfm_bos_profit_flushed') !== '1') { flush_rewrite_rules(false); update_option('sfm_bos_profit_flushed', '1', false); }
        }, 31);
    }

    public static function isSeller(int $uid): bool {
        if (function_exists('dokan_is_user_seller')) { return (bool) dokan_is_user_seller($uid); }
        return user_can($uid, 'seller');
    }

    public static function menu(array $items): array {
        if (!is_user_logged_in() || !self::isSeller(get_current_user_id())) { return $items; }
        $out = [];
        foreach ($items as $k => $v) {
            if ($k === 'customer-logout') { $out[self::ENDPOINT] = __('Profit & loss', 'sfm4u-wholesale'); }
            $out[$k] = $v;
        }
        return $out + [self::ENDPOINT => __('Profit & loss', 'sfm4u-wholesale')];
    }

    public static function dokanNav($nav) {
        if (!is_array($nav) || !function_exists('wc_get_account_endpoint_url')) { return $nav; }
        $nav['sfm4u-profit'] = ['title' => __('Profit & loss', 'sfm4u-wholesale'), 'icon' => '<i class="fas fa-chart-line"></i>',
            'url' => wc_get_account_endpoint_url(self::ENDPOINT), 'pos' => 186, 'permission' => 'dokan_view_order_menu'];
        return $nav;
    }

    /** Pure: summarise lines. Each line: [product_id, name, qty, sales, refund, cost_each|null]. Money in minor units (paisa). */
    public static function summarise(array $lines): array {
        $rows = [];
        $t = ['sales' => 0, 'refund' => 0, 'cost' => 0, 'unknown_sales' => 0, 'qty' => 0];
        foreach ($lines as [$pid, $name, $qty, $sales, $refund, $costEach]) {
            $r = &$rows[$pid];
            $r = $r ?? ['name' => $name, 'qty' => 0, 'sales' => 0, 'refund' => 0, 'cost' => 0, 'unknown' => false];
            $r['qty'] += $qty; $r['sales'] += $sales; $r['refund'] += $refund;
            if ($costEach === null) { $r['unknown'] = true; $t['unknown_sales'] += $sales - $refund; }
            else { $r['cost'] += (int) round($qty * $costEach); $t['cost'] += (int) round($qty * $costEach); }
            $t['sales'] += $sales; $t['refund'] += $refund; $t['qty'] += $qty;
            unset($r);
        }
        foreach ($rows as &$r) { $r['profit'] = $r['unknown'] ? null : $r['sales'] - $r['refund'] - $r['cost']; }
        unset($r);
        $t['net_sales'] = $t['sales'] - $t['refund'];
        $t['profit_known'] = $t['net_sales'] - $t['unknown_sales'] - $t['cost'];
        uasort($rows, static fn ($a, $b) => $b['sales'] <=> $a['sales']);
        return ['rows' => $rows, 'total' => $t];
    }

    /** Pure: weighted average buying cost after a restock. */
    public static function averageCost(float $oldQty, float $oldCost, float $addQty, float $addCost): float {
        $oldQty = max(0.0, $oldQty);
        if ($oldQty + $addQty <= 0) { return $addCost; }
        if ($oldCost <= 0) { return $addCost; }
        return round(($oldQty * $oldCost + $addQty * $addCost) / ($oldQty + $addQty), 2);
    }

    private static function minor(float $v): int { return (int) round($v * 100); }

    /** Collect the seller's lines for a month (Y-m). */
    public static function lines(int $vendor, string $month): array {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) { return []; }
        $start = $month . '-01 00:00:00';
        $end = date('Y-m-d 23:59:59', strtotime($month . '-01 +1 month -1 day'));
        $orders = wc_get_orders(['status' => self::STATUSES, 'date_created' => strtotime($start) . '...' . strtotime($end), 'limit' => 2000, 'type' => 'shop_order']);
        $out = [];
        foreach ($orders as $o) {
            if (!$o instanceof \WC_Order || $o->get_meta('has_sub_order')) { continue; } // Dokan: count the store's sub-order, not the parent
            foreach ($o->get_items() as $itemId => $item) {
                if (!$item instanceof \WC_Order_Item_Product) { continue; }
                $pid = (int) ($item->get_variation_id() ?: $item->get_product_id());
                $parent = (int) $item->get_product_id();
                if ((int) get_post_field('post_author', $parent) !== $vendor) { continue; }
                $qty = (float) $item->get_quantity() + (float) $o->get_qty_refunded_for_item($itemId);
                $cost = get_post_meta($pid, '_sfm_cost', true);
                if ($cost === '' && $pid !== $parent) { $cost = get_post_meta($parent, '_sfm_cost', true); }
                $out[] = [$pid, $item->get_name(), max(0.0, $qty), self::minor((float) $item->get_total()),
                    self::minor(abs((float) $o->get_total_refunded_for_item($itemId))), ($cost !== '' && (float) $cost > 0) ? (float) $cost * 100 : null];
            }
        }
        return $out;
    }

    public static function saveCosts(): void {
        if (empty($_POST['sfm_bos_costs']) || !is_user_logged_in()) { return; }
        if (!isset($_POST['_sfm_costs']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_sfm_costs'])), 'sfm_bos_costs')) { return; }
        $uid = get_current_user_id();
        $costs = isset($_POST['cost']) && is_array($_POST['cost']) ? wp_unslash($_POST['cost']) : [];
        $n = 0;
        foreach (array_slice($costs, 0, 300, true) as $pid => $v) {
            $pid = (int) $pid;
            $v = trim((string) $v);
            if ($pid < 1 || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v)) { continue; }
            $parent = (int) (wp_get_post_parent_id($pid) ?: $pid);
            if ((int) get_post_field('post_author', $parent) !== $uid || !in_array(get_post_type($pid), ['product', 'product_variation'], true)) { continue; }
            update_post_meta($pid, '_sfm_cost', $v);
            $n++;
        }
        set_transient('sfm_bos_profit_msg_' . $uid, sprintf(__('%d buying costs saved.', 'sfm4u-wholesale'), $n), 120);
        wp_safe_redirect(add_query_arg('month', sanitize_text_field(wp_unslash((string) ($_POST['month'] ?? ''))), wc_get_account_endpoint_url(self::ENDPOINT)));
        exit;
    }

    private static function money(int $minor): string { return '৳' . number_format($minor / 100, 2); }

    public static function page(): void {
        nocache_headers();
        $uid = get_current_user_id();
        echo '<div class="sfm-bos-profit"><h3>' . esc_html__('Profit & loss', 'sfm4u-wholesale') . ' <small>/ লাভ-লোকসান</small></h3>';
        if (!self::isSeller($uid)) { echo '<p>' . esc_html__('This page is for AlgiBazar sellers.', 'sfm4u-wholesale') . '</p></div>'; return; }
        if ($m = get_transient('sfm_bos_profit_msg_' . $uid)) { delete_transient('sfm_bos_profit_msg_' . $uid); echo '<div class="woocommerce-message">' . esc_html((string) $m) . '</div>'; }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month']) ? (string) $_GET['month'] : current_time('Y-m');
        echo '<form method="get" style="margin:8px 0"><label>' . esc_html__('Month', 'sfm4u-wholesale') . ' <input type="month" name="month" value="' . esc_attr($month) . '"></label> <button class="button">' . esc_html__('Show', 'sfm4u-wholesale') . '</button></form>';
        $s = self::summarise(self::lines($uid, $month));
        $t = $s['total'];
        echo '<table class="shop_table" style="max-width:520px"><tbody>'
            . '<tr><th>' . esc_html__('Sales', 'sfm4u-wholesale') . ' / বিক্রি</th><td>' . esc_html(self::money($t['sales'])) . '</td></tr>'
            . '<tr><th>' . esc_html__('Refunds', 'sfm4u-wholesale') . ' / ফেরত</th><td>' . esc_html(self::money($t['refund'])) . '</td></tr>'
            . '<tr><th>' . esc_html__('Buying cost', 'sfm4u-wholesale') . ' / কেনা দাম</th><td>' . esc_html(self::money($t['cost'])) . '</td></tr>'
            . '<tr><th>' . esc_html__('Profit (lines with known cost)', 'sfm4u-wholesale') . ' / লাভ</th><td><strong>' . esc_html(self::money($t['profit_known'])) . '</strong></td></tr>';
        if ($t['unknown_sales'] > 0) {
            echo '<tr><th>' . esc_html__('Sales without a buying cost', 'sfm4u-wholesale') . '</th><td>' . esc_html(self::money($t['unknown_sales'])) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($t['unknown_sales'] > 0) { echo '<p>' . esc_html__('Some products have no buying cost, so their profit is not counted. Type the cost below.', 'sfm4u-wholesale') . '</p>'; }
        if (!$s['rows']) { echo '<p>' . esc_html__('No paid orders for your products in this month.', 'sfm4u-wholesale') . '</p></div>'; return; }
        echo '<form method="post">' . wp_nonce_field('sfm_bos_costs', '_sfm_costs', true, false) . '<input type="hidden" name="sfm_bos_costs" value="1"><input type="hidden" name="month" value="' . esc_attr($month) . '">'
            . '<div style="overflow-x:auto"><table class="shop_table"><thead><tr><th>' . esc_html__('Product', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Qty', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Sales', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Buying cost each', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Profit', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        foreach ($s['rows'] as $pid => $r) {
            $c = (string) get_post_meta((int) $pid, '_sfm_cost', true);
            echo '<tr><td>' . esc_html($r['name']) . '</td><td>' . esc_html((string) $r['qty']) . '</td><td>' . esc_html(self::money($r['sales'] - $r['refund'])) . '</td>'
                . '<td><input type="text" inputmode="decimal" name="cost[' . (int) $pid . ']" value="' . esc_attr($c) . '" style="width:90px" aria-label="' . esc_attr__('Buying cost each', 'sfm4u-wholesale') . '"></td>'
                . '<td>' . ($r['profit'] === null ? '—' : esc_html(self::money((int) $r['profit']))) . '</td></tr>';
        }
        echo '</tbody></table></div><button class="button">' . esc_html__('Save buying costs', 'sfm4u-wholesale') . '</button></form></div>';
    }
}
