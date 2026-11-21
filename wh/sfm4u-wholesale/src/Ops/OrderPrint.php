<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Ops;

defined('ABSPATH') || exit;

/**
 * 80 mm order slip for a thermal printer, and an "Auto print" screen: keep it open on the shop computer and
 * each new Processing order prints once (marked as printed so two computers do not print it twice).
 * Silent printing needs the browser started in kiosk-printing mode (Chrome: --kiosk-printing); otherwise the
 * print window opens and staff press Print. Look: assets/order-slip.css. Behaviour: assets/autoprint.js.
 */
final class OrderPrint {
    public const PRINTED = '_sfm_printed';

    public static function register(): void {
        add_action('wp_ajax_sfm_wr_order_slip', [self::class, 'slip']);
        add_action('wp_ajax_sfm_wr_new_orders', [self::class, 'newOrders']);
        add_action('wp_ajax_sfm_wr_mark_printed', [self::class, 'markPrinted']);
    }

    private static function asset(string $f): string { return plugins_url('assets/' . $f, dirname(__DIR__, 2) . '/sfm4u-wholesale.php'); }

    public static function slipUrl(int $orderId): string {
        return add_query_arg(['action' => 'sfm_wr_order_slip', 'order' => $orderId, '_wpnonce' => wp_create_nonce('sfm_wr_slip')], admin_url('admin-ajax.php'));
    }

    public static function html(\WC_Order $o): string {
        $e = 'esc_html';
        $rows = '';
        foreach ($o->get_items() as $it) {
            $rows .= '<tr><td>' . $e($it->get_name()) . '<br><small>' . (int) $it->get_quantity() . ' × ' . $e(number_format((float) $o->get_item_subtotal($it, true), 2)) . '</small></td><td class="r">' . $e(number_format((float) $o->get_line_total($it, true), 2)) . '</td></tr>';
        }
        $c = Courier::info($o);
        $addr = wp_strip_all_tags(str_replace('<br/>', ', ', (string) ($o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address())));
        return '<!doctype html><html lang="bn"><head><meta charset="utf-8"><title>#' . $e($o->get_order_number()) . '</title><link rel="stylesheet" href="' . esc_url(self::asset('order-slip.css')) . '"></head><body><div class="slip">'
            . '<h1>' . $e(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)) . '</h1>'
            . '<p class="c">অর্ডার #' . $e($o->get_order_number()) . ' · ' . $e((string) wc_format_datetime($o->get_date_created(), 'd M Y H:i')) . '</p>'
            . '<p><b>' . $e(trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name())) . '</b><br>' . $e((string) $o->get_billing_phone()) . '<br>' . $e($addr) . '</p>'
            . '<table>' . $rows . '<tr class="t"><td>মোট</td><td class="r">৳' . $e(number_format((float) $o->get_total(), 2)) . '</td></tr></table>'
            . '<p>পেমেন্ট: ' . $e((string) $o->get_payment_method_title()) . ($o->is_paid() ? ' (পরিশোধিত)' : '') . '</p>'
            . (!empty($c['tracking_code']) ? '<p>Steadfast: ' . $e((string) $c['consignment_id']) . ' / ' . $e((string) $c['tracking_code']) . '</p>' : '')
            . ($o->get_customer_note() !== '' ? '<p>নোট: ' . $e((string) $o->get_customer_note()) . '</p>' : '')
            . '<p class="c">ধন্যবাদ</p></div></body></html>';
    }

    public static function slip(): void {
        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('sfm_wr_slip', '_wpnonce', false)) { wp_die('Not allowed', 403); }
        $o = wc_get_order(absint($_GET['order'] ?? 0));
        if (!$o) { wp_die('Order not found', 404); }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo self::html($o); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
        exit;
    }

    /** Processing orders from the last 2 days that no computer has printed yet. */
    public static function newOrders(): void {
        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('sfm_wr_autoprint', '_wpnonce', false)) { wp_send_json_error([], 403); }
        $out = [];
        foreach (wc_get_orders(['status' => ['processing'], 'limit' => 20, 'orderby' => 'date', 'order' => 'ASC', 'date_created' => '>' . (time() - 2 * DAY_IN_SECONDS)]) as $o) {
            if ($o->get_meta(self::PRINTED) === '') { $out[] = ['id' => $o->get_id(), 'no' => (string) $o->get_order_number(), 'slip' => self::slipUrl($o->get_id())]; }
        }
        wp_send_json_success($out);
    }

    public static function markPrinted(): void {
        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('sfm_wr_autoprint', '_wpnonce', false)) { wp_send_json_error([], 403); }
        $o = wc_get_order(absint($_POST['order'] ?? 0));
        if (!$o) { wp_send_json_error([], 404); }
        if ($o->get_meta(self::PRINTED) === '') {
            $o->update_meta_data(self::PRINTED, current_time('mysql', true));
            $o->add_order_note('Slip printed automatically.');
            $o->save();
        }
        wp_send_json_success();
    }

    public static function autoPage(): void {
        if (!current_user_can('edit_shop_orders')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        wp_enqueue_script('sfm-wr-autoprint', self::asset('autoprint.js'), [], (string) @filemtime(dirname(__DIR__, 2) . '/assets/autoprint.js'), true);
        wp_localize_script('sfm-wr-autoprint', 'sfmAutoPrint', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sfm_wr_autoprint'), 'every' => 20]);
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Auto print new orders', 'sfm4u-wholesale') . '</h1>'
            . '<p>' . esc_html__('Keep this page open on the shop computer. Every 20 seconds it checks for new Processing orders and prints each slip once.', 'sfm4u-wholesale') . '</p>'
            . '<p>' . esc_html__('For printing without the print window, start Chrome with --kiosk-printing and set the thermal printer as default.', 'sfm4u-wholesale') . '</p>'
            . '<p><label><input type="checkbox" id="sfm-ap-on"> <strong>' . esc_html__('Auto print is on', 'sfm4u-wholesale') . '</strong></label></p>'
            . '<p id="sfm-ap-status" aria-live="polite">' . esc_html__('Off.', 'sfm4u-wholesale') . '</p><ul id="sfm-ap-log"></ul><iframe id="sfm-ap-frame" title="print" style="width:1px;height:1px;border:0;position:absolute;left:-9999px"></iframe></div>';
    }
}
