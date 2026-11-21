<?php
declare(strict_types=1);

namespace SFM4U\POS;

final class CounterPage
{
    private const SLUG = 'sfm4u-counter';

    public static function register(): void
    {
        add_filter('woocommerce_prevent_admin_access', static fn(bool $prevent): bool =>
            current_user_can('sfm4u_pos_cashier') && ($_GET['page'] ?? '') === self::SLUG ? false : $prevent);
        add_action('admin_menu', static function (): void {
            $hook = add_menu_page(__('POS counter', 'sfm4u-wholesale'), __('POS counter', 'sfm4u-wholesale'),
                'sfm4u_pos_cashier', self::SLUG, [self::class, 'render'], 'dashicons-cart', 56);
            add_action('load-' . $hook, static function (): void {
                nocache_headers();
                header('Cache-Control: private, no-store');
                add_action('admin_enqueue_scripts', [self::class, 'assets']);
            });
        });
    }

    public static function assets(): void
    {
        wp_enqueue_script('sfm4u-counter', plugins_url('counter.js', __FILE__), [], '0.2.2-khata-dev', true);
        wp_enqueue_style('sfm4u-counter', plugins_url('counter.css', __FILE__), [], '0.2.1-dev');
        wp_localize_script('sfm4u-counter', 'sfm4uCounter', [
            'url' => rest_url('sfm4u-pos/v1/register'), 'nonce' => wp_create_nonce('wp_rest'),
            'storage' => 'sfm4u-counter:' . hash('sha256', home_url() . '|' . get_current_user_id()) . ':',
            'labels' => [
                'empty' => __('Cart is empty.', 'sfm4u-wholesale'),
                'add' => __('Add to cart', 'sfm4u-wholesale'),
                'remove' => __('Remove', 'sfm4u-wholesale'),
                'quantity' => __('Quantity', 'sfm4u-wholesale'),
                'failed' => __('The connection is unavailable. Try again.', 'sfm4u-wholesale'),
                'queued' => __('Sale saved on this device. Stock and receipt are pending until sync.', 'sfm4u-wholesale'),
                'rejected' => __('Sale needs review. Keep its request key and contact the manager.', 'sfm4u-wholesale'),
                'accountGrace' => determine_locale() === 'bn_BD' ? 'NID যোগ করা হয়নি। %d দিন বাকি।' : __('NID missing — %d days left', 'sfm4u-wholesale'),
                'accountHold' => determine_locale() === 'bn_BD' ? 'অ্যাকাউন্ট স্থগিত আছে।' : __('Account on hold', 'sfm4u-wholesale'),
                'accountBlocked' => determine_locale() === 'bn_BD' ? 'অ্যাকাউন্ট ব্লক করা হয়েছে।' : __('Account blocked', 'sfm4u-wholesale'),
                'storage' => __('Local storage is unavailable. This device cannot queue sales.', 'sfm4u-wholesale'),
                'saved' => __('Sale confirmed.', 'sfm4u-wholesale'),
                'pending' => __('Pending sales on this device', 'sfm4u-wholesale'),
                'noResults' => __('No products found.', 'sfm4u-wholesale'),
                'open' => __('Open drawer before confirming a sale.', 'sfm4u-wholesale'),
                'invalid' => __('Check the cart and amounts.', 'sfm4u-wholesale'),
                'cartChanged' => __('Cart changed. Review its total again.', 'sfm4u-wholesale'),
                'syncFirst' => __('Sync pending sales before closing the drawer.', 'sfm4u-wholesale'),
                'receipt' => __('Receipt number', 'sfm4u-wholesale'),
                'session' => __('Session ID', 'sfm4u-wholesale'),
                'sales' => __('Cash sales', 'sfm4u-wholesale'),
                'returns' => __('Cash refunds', 'sfm4u-wholesale'),
                'date' => __('Close date (UTC)', 'sfm4u-wholesale'),
                'in' => __('Cash in', 'sfm4u-wholesale'),
                'out' => __('Cash out', 'sfm4u-wholesale'),
                'opening' => __('Opening float', 'sfm4u-wholesale'),
                'expected' => __('Expected cash (BDT)', 'sfm4u-wholesale'),
                'counted' => __('Counted cash (BDT)', 'sfm4u-wholesale'),
                'variance' => __('Variance (BDT)', 'sfm4u-wholesale'),
                'due' => __('Shop due', 'sfm4u-wholesale'),
                'advance' => __('Advance', 'sfm4u-wholesale'),
                'dueUnavailable' => __('Khata is unavailable for this shop or card.', 'sfm4u-wholesale'),
                'dueSaved' => __('Due payment recorded.', 'sfm4u-wholesale'),
                'cashDue' => __('Cash to collect', 'sfm4u-wholesale'),
                'total' => __('Total', 'sfm4u-wholesale'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('sfm4u_pos_cashier')) {
            return;
        }
        $assignments = get_user_meta(get_current_user_id(), '_sfm4u_pos_registers', true);
        echo '<div class="wrap" id="sfm4u-counter"><h1>' . esc_html__('POS counter', 'sfm4u-wholesale') . '</h1>';
        echo '<p>' . esc_html__('Counter sales support untaxed BDT and managed simple products. Payment methods are recorded as labels.', 'sfm4u-wholesale') . '</p>';
        echo '<label>' . esc_html__('Register', 'sfm4u-wholesale') . ' <select id="sfc-register">';
        foreach (is_array($assignments) ? $assignments : [] as $merchant => $registers) {
            foreach ($registers as $register => $cashier) {
                echo '<option value="' . esc_attr($merchant . ':' . $register) . '">' . esc_html($merchant . ' · ' . $register) . '</option>';
            }
        }
        echo '</select></label><p id="sfc-status" role="status" aria-live="polite"></p><div class="sfc-grid"><section>';
        echo '<form id="sfc-search"><label>' . esc_html__('SKU, product name or barcode', 'sfm4u-wholesale')
            . ' <input id="sfc-term" maxlength="100" required autocomplete="off"></label> <button class="button">'
            . esc_html__('Find product', 'sfm4u-wholesale') . '</button></form><ul id="sfc-results"></ul>';
        echo '<h2>' . esc_html__('Counter cart', 'sfm4u-wholesale') . '</h2><ul id="sfc-cart"></ul>';
        echo '<form id="sfc-quote"><label>' . esc_html__('Price tier', 'sfm4u-wholesale') . ' <select id="sfc-tier">'
            . '<option value="retail">' . esc_html__('Retail', 'sfm4u-wholesale') . '</option><option value="wholesale">'
            . esc_html__('Wholesale', 'sfm4u-wholesale') . '</option><option value="member">'
            . esc_html__('SFM4U member card', 'sfm4u-wholesale') . '</option></select></label>'
            . '<label id="sfc-member-row" hidden>' . esc_html__('Scan member card', 'sfm4u-wholesale')
            . ' <input id="sfc-member" maxlength="200" autocomplete="off"></label><p id="sfc-member-info" aria-live="polite"></p>'
            . '<p id="sfc-account-status" aria-live="polite"></p>';
        echo '<div id="sfc-khata" hidden><p id="sfc-due" aria-live="polite"></p>'
            . '<button type="button" id="sfc-due-history" class="button">' . esc_html__('Due history', 'sfm4u-wholesale')
            . '</button><ul id="sfc-history"></ul>';
        self::amount('due-only', __('Due-only payment (BDT)', 'sfm4u-wholesale'));
        echo '<button type="button" id="sfc-pay-due" class="button">' . esc_html__('Pay due only', 'sfm4u-wholesale') . '</button></div>';
        self::amount('discount', __('Discount', 'sfm4u-wholesale'));
        echo '<label>' . esc_html__('Payment method', 'sfm4u-wholesale') . ' <select id="sfc-method">';
        foreach (array_keys(CounterSale::METHODS) as $method) {
            echo '<option value="' . esc_attr($method) . '">' . esc_html(CounterSale::methodLabel($method)) . '</option>';
        }
        echo '</select></label><button class="button">' . esc_html__('Review cart total', 'sfm4u-wholesale') . '</button></form>';
        echo '<p id="sfc-total"></p><form id="sfc-sale">';
        echo '<div id="sfc-khata-sale" hidden>';
        self::amount('due-payment', __('Paid toward previous due', 'sfm4u-wholesale'));
        self::amount('credit', __('Sale on credit', 'sfm4u-wholesale'));
        echo '<p id="sfc-cash-due" aria-live="polite"></p></div>';
        self::amount('tender', __('Tender amount (BDT)', 'sfm4u-wholesale'));
        echo '<button id="sfc-confirm" class="button button-primary" disabled>' . esc_html__('Confirm sale', 'sfm4u-wholesale') . '</button></form></section>';
        echo '<section><h2>' . esc_html__('Cash drawer', 'sfm4u-wholesale') . '</h2><form id="sfc-drawer">';
        self::amount('amount', __('Drawer amount (BDT)', 'sfm4u-wholesale'));
        if (current_user_can('sfm4u_pos_credit')) {
            echo '<label>' . esc_html__('Manager approval reference', 'sfm4u-wholesale')
                . ' <input id="sfc-approval" maxlength="250"></label>';
        }
        foreach (['status' => __('Drawer status', 'sfm4u-wholesale'), 'open' => __('Open drawer', 'sfm4u-wholesale'),
            'day' => __('Daily cash summary', 'sfm4u-wholesale'), 'xreport' => __('X-report', 'sfm4u-wholesale'), 'close' => __('Close drawer', 'sfm4u-wholesale')] as $action => $label) {
            echo '<button name="drawer" value="' . esc_attr($action) . '" class="button">' . esc_html($label) . '</button> ';
        }
        echo '</form><dl id="sfc-summary"></dl><h2>' . esc_html__('Pending sales', 'sfm4u-wholesale') . '</h2><ul id="sfc-queue"></ul>'
            . '<button type="button" id="sfc-sync" class="button">' . esc_html__('Sync sales', 'sfm4u-wholesale') . '</button>';
        echo '<h2>' . esc_html__('Print receipt', 'sfm4u-wholesale') . '</h2><form id="sfc-print"><label>'
            . esc_html__('Order ID', 'sfm4u-wholesale') . ' <input id="sfc-order" type="number" min="1" step="1" required></label>'
            . '<label>' . esc_html__('Paper size', 'sfm4u-wholesale') . ' <select id="sfc-paper"><option value="thermal58">'
            . esc_html__('58 mm thermal', 'sfm4u-wholesale') . '</option><option value="a4">' . esc_html__('A4', 'sfm4u-wholesale') . '</option></select></label>'
            . '<button class="button">' . esc_html__('Print receipt', 'sfm4u-wholesale') . '</button></form></section></div><div id="sfc-receipt"></div></div>';
    }

    private static function amount(string $id, string $label): void
    {
        echo '<label>' . esc_html($label) . ' <input id="sfc-' . esc_attr($id)
            . '" type="number" inputmode="decimal" min="0" max="10000000" step="0.01" value="0.00" required></label>';
    }
}
