<?php
declare(strict_types=1);
namespace SFM4U\Modules\CodGuard;

use SFM4U\Wholesale\ModuleHome;

defined('ABSPATH') || exit;

/** E04 COD control: hide/refuse cash on delivery when the order is too big, the phone cancels often, or the phone is blocked. */
final class CodGuard implements \SFM4U\Wholesale\ModuleContract {
    public const SLUG = 'codguard';
    public const LOG = 'sfm_codguard_blocked';

    public static function register(): void {
        add_filter('woocommerce_available_payment_gateways', [self::class, 'gateways'], 50);
        add_action('woocommerce_after_checkout_validation', [self::class, 'validate'], 50, 2);
    }

    public const DEFAULTS = ['max_amount' => 5000, 'max_cancelled' => 2, 'days' => 90, 'blocked' => '', 'message' => 'Cash on delivery is not available for this order. Please pay online. / এই অর্ডারে ক্যাশ অন ডেলিভারি নেই, অনলাইনে পেমেন্ট করুন।'];

    /** Saved setting or default. Reads the option directly (never calls dashboard(), so no loop). */
    public static function opt(string $k, $d = null) {
        $o = class_exists(ModuleHome::class) ? ModuleHome::settings(self::SLUG) : [];
        return array_key_exists($k, $o) ? $o[$k] : (self::DEFAULTS[$k] ?? $d);
    }

    public static function dashboard(): array {
        $log = get_option(self::LOG, []); $today = (int) (is_array($log) ? ($log[current_time('Y-m-d')] ?? 0) : 0);
        return [
            'numbers' => [['COD refused today / আজ COD বন্ধ', (string) $today], ['Max COD / সর্বোচ্চ COD', (string) self::opt('max_amount', 5000)]],
            'pages' => [['Payments (WooCommerce)', 'admin.php?page=wc-settings&tab=checkout']],
            'settings' => [
                ['max_amount', 'Max order total for cash on delivery (0 = no limit) / সর্বোচ্চ COD টাকা', 'number', self::DEFAULTS['max_amount']],
                ['max_cancelled', 'Refuse COD after this many cancelled/failed orders (0 = off) / কতবার বাতিল হলে COD বন্ধ', 'number', self::DEFAULTS['max_cancelled']],
                ['days', 'Look back days for cancelled orders / কত দিনের হিসাব', 'number', self::DEFAULTS['days']],
                ['blocked', 'Blocked phones, one per line / ব্লক করা ফোন (প্রতি লাইনে একটি)', 'textarea', ''],
                ['message', 'Message to the customer / কাস্টমারকে বার্তা', 'text', self::DEFAULTS['message']],
            ],
            'help' => ['Stops fake or risky cash-on-delivery orders. COD is hidden when the cart total is above the limit, and refused at checkout when the phone number has too many cancelled or failed orders or is on the block list. Other payment methods stay available.',
                'ভুয়া বা ঝুঁকির ক্যাশ অন ডেলিভারি অর্ডার আটকায়। কার্টের টাকা সীমার বেশি হলে COD দেখায় না; ফোন নম্বরে অনেক বাতিল অর্ডার থাকলে বা ব্লক তালিকায় থাকলে চেকআউটে COD নেয় না। অন্য পেমেন্ট চালু থাকে।'],
        ];
    }

    /** Pure: digits, Bangla digits and +880 normalised to 01XXXXXXXXX. */
    public static function phone(string $s): string {
        $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $d = preg_replace('/\D+/', '', $s) ?? '';
        return preg_replace('/^(00)?880/', '0', $d) ?? $d;
    }

    /** Pure: the reason COD is refused, or '' when allowed. */
    public static function decide(float $total, int $cancelled, string $phone, array $o): string {
        $max = (float) ($o['max_amount'] ?? 0);
        if ($max > 0 && $total > $max) { return 'amount'; }
        $p = self::phone($phone);
        $blocked = array_filter(array_map([self::class, 'phone'], preg_split('/[\r\n,]+/', (string) ($o['blocked'] ?? '')) ?: []));
        if ($p !== '' && in_array($p, $blocked, true)) { return 'blocked'; }
        $mc = (int) ($o['max_cancelled'] ?? 0);
        if ($mc > 0 && $cancelled >= $mc) { return 'cancelled'; }
        return '';
    }

    public static function cancelledCount(string $phone): int {
        $p = self::phone($phone);
        if (strlen($p) < 6) { return 0; }
        $since = time() - DAY_IN_SECONDS * max(1, (int) self::opt('days', 90));
        $ids = [];
        foreach (array_unique([$p, '+88' . $p, '88' . $p]) as $v) {
            $ids = array_merge($ids, wc_get_orders(['billing_phone' => $v, 'status' => ['wc-cancelled', 'wc-failed'], 'date_created' => '>' . $since, 'limit' => 20, 'return' => 'ids']));
        }
        return count(array_unique($ids));
    }

    private static function settings(): array {
        return ['max_amount' => self::opt('max_amount', 5000), 'max_cancelled' => self::opt('max_cancelled', 2), 'blocked' => self::opt('blocked', '')];
    }

    private static function logRefusal(): void {
        $l = get_option(self::LOG, []); $l = is_array($l) ? array_slice($l, -30, null, true) : [];
        $d = current_time('Y-m-d'); $l[$d] = (int) ($l[$d] ?? 0) + 1; update_option(self::LOG, $l, false);
    }

    /** Hide COD when the cart is above the limit (phone is not known yet here). */
    public static function gateways(array $g): array {
        if (is_admin() || !isset($g['cod']) || !function_exists('WC') || !WC()->cart) { return $g; }
        if (self::decide((float) WC()->cart->get_total('edit'), 0, '', self::settings() + ['blocked' => '']) === 'amount') { unset($g['cod']); }
        return $g;
    }

    /** At checkout: refuse COD for blocked phones and phones with many cancelled orders. */
    public static function validate(array $data, \WP_Error $errors): void {
        if (($data['payment_method'] ?? '') !== 'cod') { return; }
        $total = function_exists('WC') && WC()->cart ? (float) WC()->cart->get_total('edit') : 0.0;
        $phone = (string) ($data['billing_phone'] ?? '');
        $why = self::decide($total, self::cancelledCount($phone), $phone, self::settings());
        if ($why !== '') { self::logRefusal(); $errors->add('sfm_cod_' . $why, esc_html((string) self::opt('message', 'Cash on delivery is not available for this order.'))); }
    }
}
