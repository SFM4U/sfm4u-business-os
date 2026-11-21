<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Ops;

use DomainException;
use SFM4U\Wholesale\Members\Members;

defined('ABSPATH') || exit;

/**
 * WhatsApp for orders, in two levels:
 *
 * 1. Free, no account: a "WhatsApp" button on the order that opens WhatsApp with a ready message (wa.me link).
 *    Staff press Send themselves. Always available.
 * 2. Automatic (WhatsApp Cloud API by Meta): sends an approved template when an order becomes Processing or
 *    Completed — only to customers who agreed (opt-in), as Meta requires. Off until the owner sets up a
 *    WhatsApp Business account, creates the template and types the access token himself. Meta charges per
 *    delivered template message (see the admin page).
 */
final class WhatsApp {
    public const OPT = 'sfm_wr_whatsapp';
    public const OPTIN = '_sfm_wa_optin';
    public const SENT = '_sfm_wa_sent';
    public const EVENTS = ['processing', 'completed'];

    public static function register(): void {
        add_action('woocommerce_order_status_changed', [self::class, 'onStatus'], 30, 3);
        add_action('woocommerce_review_order_before_submit', [self::class, 'checkoutBox']);
        add_action('woocommerce_checkout_create_order', [self::class, 'saveCheckoutBox'], 10, 2);
    }

    public static function settings(): array {
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        return array_merge(['enabled' => '', 'base' => 'https://graph.facebook.com', 'version' => 'v26.0', 'phone_id' => '', 'token_enc' => '',
            'template' => '', 'lang' => 'bn', 'checkout_box' => ''], $s);
    }

    public static function save(array $in): void {
        $s = self::settings();
        $base = untrailingslashit(esc_url_raw(trim((string) ($in['base'] ?? ''))));
        $s['base'] = $base !== '' && (preg_match('#^https://#i', $base) || preg_match('#^http://127\.0\.0\.1(:\d+)?$#', $base)) ? $base : 'https://graph.facebook.com';
        $v = trim((string) ($in['version'] ?? ''));
        $s['version'] = preg_match('/^v\d{2}\.\d$/D', $v) ? $v : 'v26.0';
        $s['phone_id'] = preg_replace('/\D+/', '', (string) ($in['phone_id'] ?? ''));
        if (($in['token'] ?? '') !== '') { $s['token_enc'] = Members::encrypt(trim((string) $in['token'])); }
        $s['template'] = sanitize_key((string) ($in['template'] ?? ''));
        $s['lang'] = preg_match('/^[a-z]{2}(_[A-Z]{2})?$/D', (string) ($in['lang'] ?? '')) ? (string) $in['lang'] : 'bn';
        $s['checkout_box'] = !empty($in['checkout_box']) ? '1' : '';
        $s['enabled'] = !empty($in['enabled']) ? '1' : '';
        if ($s['enabled'] === '1' && ($s['phone_id'] === '' || $s['token_enc'] === '' || $s['template'] === '')) {
            throw new DomainException(__('Fill phone number ID, access token and template name before turning automatic messages on.', 'sfm4u-wholesale'));
        }
        update_option(self::OPT, $s, false);
    }

    /* ---------------- level 1: free link ---------------- */

    public static function statusText(string $status): string {
        $map = ['pending' => 'পেমেন্টের অপেক্ষায়', 'processing' => 'প্রস্তুত হচ্ছে', 'on-hold' => 'অপেক্ষায়', 'completed' => 'সম্পন্ন', 'cancelled' => 'বাতিল', 'refunded' => 'টাকা ফেরত', 'failed' => 'ব্যর্থ'];
        return $map[$status] ?? $status;
    }

    public static function orderText(\WC_Order $o): string {
        $name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
        $lines = ['আসসালামু আলাইকুম' . ($name !== '' ? ' ' . $name : '') . ',',
            sprintf('আপনার অর্ডার #%s, মোট ৳%s।', $o->get_order_number(), number_format((float) $o->get_total(), 0)),
            'অবস্থা: ' . self::statusText($o->get_status()) . '।'];
        $url = Courier::trackingUrl(Courier::info($o));
        if ($url !== '') { $lines[] = 'ট্র্যাকিং: ' . $url; }
        $lines[] = 'ধন্যবাদ, ' . wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return implode("\n", $lines);
    }

    /** wa.me link with the text ready, or '' when the order has no Bangladesh mobile. */
    public static function link(\WC_Order $o): string {
        $wa = Members::waNumber((string) ($o->get_billing_phone() ?: $o->get_shipping_phone()));
        return $wa === '' ? '' : 'https://wa.me/' . $wa . '?text=' . rawurlencode(self::orderText($o));
    }

    /* ---------------- level 2: Cloud API ---------------- */

    public static function checkoutBox(): void {
        if (self::settings()['checkout_box'] !== '1') { return; }
        echo '<p class="form-row sfm-wa-optin"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="sfm_wa_optin" value="1"> <span>'
            . esc_html__('Send me order updates on WhatsApp', 'sfm4u-wholesale') . '</span></label></p>';
    }

    public static function saveCheckoutBox(\WC_Order $o, array $data): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the checkout nonce.
        if (!empty($_POST['sfm_wa_optin'])) { $o->update_meta_data(self::OPTIN, 'yes'); }
    }

    public static function optedIn(\WC_Order $o): bool { return $o->get_meta(self::OPTIN) === 'yes'; }

    /** The template's body values: {{1}} name, {{2}} order number, {{3}} total, {{4}} status. */
    public static function templateParams(\WC_Order $o): array {
        $name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
        return [$name !== '' ? $name : 'Customer', (string) $o->get_order_number(), '৳' . number_format((float) $o->get_total(), 0), self::statusText($o->get_status())];
    }

    public static function sendTemplate(string $waNumber, array $params): string {
        $s = self::settings();
        if ($s['phone_id'] === '' || $s['token_enc'] === '' || $s['template'] === '') { throw new DomainException(__('WhatsApp automatic messages are not set up.', 'sfm4u-wholesale')); }
        if (!preg_match('/^8801\d{9}$/D', $waNumber)) { throw new DomainException(__('No valid Bangladesh WhatsApp number.', 'sfm4u-wholesale')); }
        $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $waNumber, 'type' => 'template', 'template' => [
            'name' => $s['template'], 'language' => ['code' => $s['lang']],
            'components' => [['type' => 'body', 'parameters' => array_map(static fn($p) => ['type' => 'text', 'text' => mb_substr((string) $p, 0, 200)], array_values($params))]],
        ]];
        $res = wp_remote_post($s['base'] . '/' . $s['version'] . '/' . $s['phone_id'] . '/messages', ['timeout' => 15, 'body' => (string) wp_json_encode($body),
            'headers' => ['Authorization' => 'Bearer ' . Members::decrypt($s['token_enc']), 'Content-Type' => 'application/json']]);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach WhatsApp.', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $d = json_decode((string) wp_remote_retrieve_body($res), true);
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200 || empty($d['messages'][0]['id'])) {
            $err = is_array($d) && isset($d['error']['message']) ? sanitize_text_field((string) $d['error']['message']) : '';
            throw new DomainException(sprintf(__('WhatsApp refused the message (%d).', 'sfm4u-wholesale'), $code) . ' ' . $err);
        }
        return (string) $d['messages'][0]['id'];
    }

    /** Send once per order per event. Returns the message ID, or '' when skipped. */
    public static function notify(\WC_Order $o, string $event): string {
        if (self::settings()['enabled'] !== '1' || !self::optedIn($o)) { return ''; }
        $sent = $o->get_meta(self::SENT);
        $sent = is_array($sent) ? $sent : [];
        if (isset($sent[$event])) { return ''; }
        $wa = Members::waNumber((string) ($o->get_billing_phone() ?: $o->get_shipping_phone()));
        try {
            $id = self::sendTemplate($wa, self::templateParams($o));
        } catch (DomainException $e) {
            $o->add_order_note('WhatsApp (' . $event . '): not sent. ' . $e->getMessage());
            return '';
        }
        $sent[$event] = ['id' => $id, 'at' => current_time('mysql', true)];
        $o->update_meta_data(self::SENT, $sent);
        $o->add_order_note('WhatsApp (' . $event . '): sent.');
        $o->save();
        return $id;
    }

    public static function onStatus(int $orderId, string $from, string $to): void {
        if (!in_array($to, self::EVENTS, true)) { return; }
        $o = wc_get_order($orderId);
        if ($o) { self::notify($o, $to); }
    }
}
