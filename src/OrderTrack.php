<?php
declare(strict_types=1);
namespace SFM4U\Modules\OrderTrack;

use SFM4U\Wholesale\ModuleHome;

defined('ABSPATH') || exit;

/** E07 Order tracking: order number + phone → status, date, items, total, courier code. No login needed; rate limited. */
final class OrderTrack implements \SFM4U\Wholesale\ModuleContract {
    public const SLUG = 'ordertrack';
    public const COUNT = 'sfm_ordertrack_count';
    public const STATUS_BN = ['pending' => 'পেমেন্টের অপেক্ষায়', 'processing' => 'প্রস্তুত হচ্ছে', 'on-hold' => 'অপেক্ষায়', 'completed' => 'সম্পন্ন', 'cancelled' => 'বাতিল', 'refunded' => 'ফেরত', 'failed' => 'ব্যর্থ'];

    public static function register(): void {
        add_shortcode('sfm4u_order_tracking', [self::class, 'shortcode']);
    }

    public static function dashboard(): array {
        $page = self::pageId();
        return [
            'numbers' => [['Lookups today / আজ খোঁজা', (string) (int) (get_option(self::COUNT, [])[current_time('Y-m-d')] ?? 0)], ['Tracking page / পেজ', $page ? 'Ready ✅' : 'Not created', $page ? (string) get_permalink($page) : '']],
            'pages' => $page ? [['Open tracking page', (string) get_edit_post_link($page, 'raw') ?: 'edit.php?post_type=page']] : [['Pages', 'edit.php?post_type=page']],
            'settings' => [
                ['match', 'How the customer proves the order / যাচাই', 'select', 'last4', ['last4' => 'Last 4 digits of the phone', 'full' => 'Full phone number']],
                ['show_items', 'Show product names / পণ্যের নাম দেখাবে', 'checkbox', true],
                ['limit', 'Lookups per visitor per hour / প্রতি ঘণ্টায় সর্বোচ্চ', 'number', 10],
            ],
            'help' => ['Put the shortcode [sfm4u_order_tracking] on any page (for example "Track order"). The customer types the order number and the phone number used for the order. They see the status, date, total and the courier tracking code when the order is booked. Wrong numbers show the same message, so nobody can guess other orders.',
                'যেকোনো পেজে [sfm4u_order_tracking] বসান। কাস্টমার অর্ডার নম্বর আর অর্ডারের ফোন নম্বর দিলে অর্ডারের অবস্থা, তারিখ, মোট টাকা আর কুরিয়ার ট্র্যাকিং কোড দেখবে। ভুল দিলে একই বার্তা আসে, তাই কেউ অন্যের অর্ডার আন্দাজ করতে পারে না।'],
        ];
    }

    public static function pageId(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[sfm4u_order_tracking%' LIMIT 1"); // phpcs:ignore
    }

    private static function setting(string $k, $d) {
        return class_exists(ModuleHome::class) ? ModuleHome::setting(self::SLUG, $k, $d) : $d;
    }

    /** Pure: digits only (Bangla digits converted). */
    public static function digits(string $s): string {
        $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        return preg_replace('/\D+/', '', $s) ?? '';
    }

    /** Pure: does the typed phone match the order phone? */
    public static function phoneMatches(string $orderPhone, string $typed, string $mode): bool {
        $o = self::digits($orderPhone); $t = self::digits($typed);
        if ($o === '' || $t === '') { return false; }
        if ($mode === 'last4') { return strlen($t) >= 4 && substr($o, -4) === substr($t, -4); }
        $o = ltrim(preg_replace('/^(00)?880/', '0', $o) ?? $o, ''); $t = preg_replace('/^(00)?880/', '0', $t) ?? $t;
        return strlen($t) >= 10 && $o === $t;
    }

    /** Find the order, or null (same answer for "no order" and "wrong phone"). */
    public static function lookup(string $number, string $phone): ?array {
        $id = (int) self::digits($number);
        if ($id <= 0 || !function_exists('wc_get_order')) { return null; }
        $o = wc_get_order($id);
        if (!$o || !($o instanceof \WC_Order) || $o->get_type() !== 'shop_order') { return null; }
        if (!self::phoneMatches((string) $o->get_billing_phone(), $phone, (string) self::setting('match', 'last4'))) { return null; }
        $st = $o->get_status();
        $c = $o->get_meta('_sfm_courier');
        $items = [];
        foreach ($o->get_items() as $it) { $items[] = $it->get_name() . ' × ' . $it->get_quantity(); }
        return ['number' => $o->get_order_number(), 'status' => $st, 'status_label' => wc_get_order_status_name($st), 'status_bn' => self::STATUS_BN[$st] ?? '',
            'date' => $o->get_date_created() ? $o->get_date_created()->date_i18n('j M Y, g:i a') : '', 'total' => wp_strip_all_tags($o->get_formatted_order_total()),
            'items' => $items, 'tracking' => is_array($c) ? (string) ($c['tracking_code'] ?? '') : '', 'courier_status' => is_array($c) ? (string) ($c['status'] ?? '') : ''];
    }

    private static function rateOk(): bool {
        $k = 'sfm_ot_' . substr(md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt()), 0, 16);
        $n = (int) get_transient($k);
        if ($n >= max(1, (int) self::setting('limit', 10))) { return false; }
        set_transient($k, $n + 1, HOUR_IN_SECONDS);
        return true;
    }

    private static function count(): void {
        $c = get_option(self::COUNT, []); $c = is_array($c) ? array_slice($c, -30, null, true) : [];
        $d = current_time('Y-m-d'); $c[$d] = (int) ($c[$d] ?? 0) + 1;
        update_option(self::COUNT, $c, false);
    }

    public static function shortcode(): string {
        $num = isset($_POST['sfm_ot_number']) ? sanitize_text_field(wp_unslash((string) $_POST['sfm_ot_number'])) : '';
        $ph = isset($_POST['sfm_ot_phone']) ? sanitize_text_field(wp_unslash((string) $_POST['sfm_ot_phone'])) : '';
        $out = '';
        if (isset($_POST['sfm_ot'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_POST['_sfm_ot'] ?? ''))), 'sfm_ot')) { $out = '<p class="sfm-ot-err">Please reload the page and try again. / পেজটি রিলোড করে আবার চেষ্টা করুন।</p>'; }
            elseif (!self::rateOk()) { $out = '<p class="sfm-ot-err">Too many tries. Please wait an hour. / অনেকবার চেষ্টা হয়েছে, এক ঘণ্টা পরে চেষ্টা করুন।</p>'; }
            else {
                self::count();
                $r = self::lookup($num, $ph);
                if (!$r) { $out = '<p class="sfm-ot-err">No order found with this number and phone. Check both and try again. / এই নম্বর ও ফোনে কোনো অর্ডার পাওয়া যায়নি।</p>'; }
                else {
                    $out = '<div class="sfm-ot-result"><h3>Order #' . esc_html($r['number']) . '</h3><p><strong>' . esc_html($r['status_label']) . '</strong>' . ($r['status_bn'] ? ' / ' . esc_html($r['status_bn']) : '') . '</p>'
                        . '<p>Date / তারিখ: ' . esc_html($r['date']) . '<br>Total / মোট: ' . esc_html($r['total']) . '</p>';
                    if ($r['items'] && self::setting('show_items', true)) { $out .= '<ul>' . implode('', array_map(fn($i) => '<li>' . esc_html($i) . '</li>', $r['items'])) . '</ul>'; }
                    if ($r['tracking'] !== '') { $out .= '<p>Courier tracking / কুরিয়ার ট্র্যাকিং: <strong>' . esc_html($r['tracking']) . '</strong>' . ($r['courier_status'] ? ' (' . esc_html($r['courier_status']) . ')' : '') . '</p>'; }
                    $out .= '</div>';
                }
            }
        }
        return '<div class="sfm-ot"><style>.sfm-ot{max-width:520px}.sfm-ot label{display:block;margin:0 0 10px}.sfm-ot input{display:block;width:100%;box-sizing:border-box;padding:10px;margin-top:4px;border-radius:8px;border:1px solid #9aa}.sfm-ot button{padding:10px 18px;border-radius:8px;border:0;background:#00B080;color:#fff;font-weight:700;cursor:pointer}.sfm-ot-err{color:#b32d2e}.sfm-ot-result{border:1px solid #00B080;border-radius:10px;padding:12px;margin-top:12px}</style>'
            . '<form method="post">' . wp_nonce_field('sfm_ot', '_sfm_ot', true, false) . '<input type="hidden" name="sfm_ot" value="1">'
            . '<label>Order number / অর্ডার নম্বর<input name="sfm_ot_number" required inputmode="numeric" value="' . esc_attr($num) . '"></label>'
            . '<label>Phone used for the order / অর্ডারের ফোন নম্বর<input name="sfm_ot_phone" required inputmode="tel" value="' . esc_attr($ph) . '"></label>'
            . '<button type="submit">Track order / অর্ডার দেখুন</button></form>' . $out . '</div>';
    }
}
