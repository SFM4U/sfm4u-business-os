<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Ops;

use DomainException;
use SFM4U\Wholesale\Members\Members;

defined('ABSPATH') || exit;

/**
 * Courier booking for WooCommerce orders. First provider: Steadfast (Bangladesh).
 *
 * - Off until an admin turns it on and types the API key and secret (from the Steadfast merchant panel) himself.
 *   The secret is stored encrypted and never shown again.
 * - One order can be booked only once. The consignment ID and tracking code are saved on the order.
 * - Statuses are refreshed hourly for booked, unfinished orders.
 * API: https://portal.packzy.com/api/v1 — POST /create_order, GET /status_by_cid/{id}, GET /get_balance
 * (headers Api-Key, Secret-Key).
 */
final class Courier {
    public const OPT = 'sfm_wr_courier';
    public const META = '_sfm_courier';
    public const CRON = 'sfm_wr_courier_refresh';
    public const FINAL = ['delivered', 'partial_delivered', 'cancelled'];
    public const BASE = 'https://portal.packzy.com/api/v1';

    public static function register(): void {
        add_action(self::CRON, [self::class, 'refreshAll']);
        add_action('init', static function (): void {
            if (self::settings()['enabled'] === '1' && !wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 600, 'hourly', self::CRON); }
        });
        add_action('woocommerce_order_status_changed', [self::class, 'onStatus'], 20, 3);
    }

    public static function settings(): array {
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        return array_merge(['enabled' => '', 'base' => self::BASE, 'key' => '', 'secret_enc' => '', 'auto_status' => '', 'note' => ''], $s);
    }

    public static function save(array $in): void {
        $s = self::settings();
        $base = untrailingslashit(esc_url_raw(trim((string) ($in['base'] ?? ''))));
        if ($base === '') { $base = self::BASE; }
        if (!preg_match('#^https://#i', $base) && !preg_match('#^http://127\.0\.0\.1(:\d+)?(/|$)#', $base)) { throw new DomainException(__('The courier API address must start with https://', 'sfm4u-wholesale')); }
        $s['base'] = $base;
        $s['enabled'] = !empty($in['enabled']) ? '1' : '';
        $s['key'] = trim(sanitize_text_field((string) ($in['key'] ?? '')));
        if (($in['secret'] ?? '') !== '') { $s['secret_enc'] = Members::encrypt(trim((string) $in['secret'])); }
        $auto = (string) ($in['auto_status'] ?? '');
        $s['auto_status'] = in_array($auto, ['', 'processing', 'completed'], true) ? $auto : '';
        $s['note'] = mb_substr(trim(sanitize_text_field((string) ($in['note'] ?? ''))), 0, 200);
        if ($s['enabled'] === '1' && ($s['key'] === '' || $s['secret_enc'] === '')) { throw new DomainException(__('Type the API key and secret before turning the courier on.', 'sfm4u-wholesale')); }
        update_option(self::OPT, $s, false);
        if ($s['enabled'] !== '1') { wp_clear_scheduled_hook(self::CRON); }
    }

    /* ---------------- HTTP ---------------- */

    private static function request(string $method, string $path, ?array $body = null): array {
        $s = self::settings();
        if ($s['key'] === '' || $s['secret_enc'] === '') { throw new DomainException(__('The courier API key and secret are not set.', 'sfm4u-wholesale')); }
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Api-Key' => $s['key'], 'Secret-Key' => Members::decrypt($s['secret_enc']), 'Accept' => 'application/json', 'Content-Type' => 'application/json']];
        if ($body !== null) { $args['body'] = (string) wp_json_encode($body); }
        $res = wp_remote_request($s['base'] . $path, $args);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach the courier.', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code === 401 || $code === 403) { throw new DomainException(__('The courier refused the API key or secret.', 'sfm4u-wholesale')); }
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            throw new DomainException(sprintf(__('The courier answered with an error (%d).', 'sfm4u-wholesale'), $code) . (is_array($data) ? ' ' . self::errText($data) : ''));
        }
        return $data;
    }

    private static function errText(array $d): string {
        $t = (string) ($d['message'] ?? '');
        if (!empty($d['errors']) && is_array($d['errors'])) {
            foreach ($d['errors'] as $k => $v) { $t .= ' ' . $k . ': ' . (is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v); }
        }
        return trim(sanitize_text_field($t));
    }

    public static function balance(): string {
        $d = self::request('GET', '/get_balance');
        return (string) ($d['current_balance'] ?? '?');
    }

    /* ---------------- orders ---------------- */

    /** Bangladesh mobile as 11 digits (01XXXXXXXXX), from +880 / 880 / Bangla digits. '' when not valid. */
    public static function bdPhone(string $raw): string {
        $d = Members::digits($raw);
        if (strpos($d, '880') === 0) { $d = substr($d, 2); }
        return preg_match('/^01[3-9]\d{8}$/D', $d) ? $d : '';
    }

    /** What we would send for this order (pure enough to test). */
    public static function payload(\WC_Order $o): array {
        $name = trim($o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name()) ?: trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
        $phone = self::bdPhone((string) ($o->get_shipping_phone() ?: $o->get_billing_phone()));
        $parts = $o->get_shipping_address_1() !== '' ? [$o->get_shipping_address_1(), $o->get_shipping_address_2(), $o->get_shipping_city(), $o->get_shipping_state(), $o->get_shipping_postcode()]
            : [$o->get_billing_address_1(), $o->get_billing_address_2(), $o->get_billing_city(), $o->get_billing_state(), $o->get_billing_postcode()];
        $address = implode(', ', array_filter(array_map('trim', array_map('strval', $parts)), 'strlen'));
        if ($name === '') { throw new DomainException(__('The order has no customer name.', 'sfm4u-wholesale')); }
        if ($phone === '') { throw new DomainException(__('The order has no valid Bangladesh mobile number (01XXXXXXXXX).', 'sfm4u-wholesale')); }
        if ($address === '') { throw new DomainException(__('The order has no delivery address.', 'sfm4u-wholesale')); }
        $items = [];
        foreach ($o->get_items() as $it) { $items[] = $it->get_name() . ' x' . $it->get_quantity(); }
        $cod = $o->get_payment_method() === 'cod' && !$o->is_paid() ? (float) $o->get_total() : 0.0;
        $note = trim(self::settings()['note'] . ' ' . trim((string) $o->get_customer_note()));
        return ['invoice' => 'WC-' . $o->get_order_number(), 'recipient_name' => mb_substr($name, 0, 100), 'recipient_phone' => $phone,
            'recipient_email' => (string) $o->get_billing_email(), 'recipient_address' => mb_substr($address, 0, 250), 'cod_amount' => round($cod, 2),
            'item_description' => mb_substr(implode(', ', $items), 0, 250), 'note' => mb_substr($note, 0, 250)];
    }

    public static function info(\WC_Order $o): array {
        $m = $o->get_meta(self::META);
        return is_array($m) ? $m : [];
    }

    public static function trackingUrl(array $info): string {
        return !empty($info['tracking_code']) ? 'https://steadfast.com.bd/t/' . rawurlencode((string) $info['tracking_code']) : '';
    }

    /** Book the order with the courier. Throws when already booked or the courier refuses. */
    public static function book(\WC_Order $o): array {
        if (self::settings()['enabled'] !== '1') { throw new DomainException(__('The courier link is off.', 'sfm4u-wholesale')); }
        if (!empty(self::info($o)['consignment_id'])) { throw new DomainException(__('This order is already booked with the courier.', 'sfm4u-wholesale')); }
        // Lock against double clicks / two staff at once.
        $lock = 'sfm_wr_courier_lock_' . $o->get_id();
        if (get_transient($lock)) { throw new DomainException(__('This order is being booked right now. Wait a moment.', 'sfm4u-wholesale')); }
        set_transient($lock, 1, 60);
        try {
            $d = self::request('POST', '/create_order', self::payload($o));
            $c = is_array($d['consignment'] ?? null) ? $d['consignment'] : [];
            if (empty($c['consignment_id'])) { throw new DomainException(__('The courier did not give a consignment ID.', 'sfm4u-wholesale') . ' ' . self::errText($d)); }
            $info = ['provider' => 'steadfast', 'consignment_id' => (string) $c['consignment_id'], 'tracking_code' => (string) ($c['tracking_code'] ?? ''),
                'status' => (string) ($c['status'] ?? 'in_review'), 'booked_at' => current_time('mysql', true), 'checked_at' => current_time('mysql', true), 'by' => get_current_user_id()];
            $o->update_meta_data(self::META, $info);
            $o->add_order_note(sprintf('Steadfast: booked. Consignment %s, tracking %s.', $info['consignment_id'], $info['tracking_code']));
            $o->save();
            return $info;
        } finally {
            delete_transient($lock);
        }
    }

    public static function refresh(\WC_Order $o): array {
        $info = self::info($o);
        if (empty($info['consignment_id'])) { throw new DomainException(__('This order is not booked with the courier.', 'sfm4u-wholesale')); }
        $d = self::request('GET', '/status_by_cid/' . rawurlencode($info['consignment_id']));
        $new = sanitize_key((string) ($d['delivery_status'] ?? ''));
        if ($new !== '' && $new !== ($info['status'] ?? '')) {
            $o->add_order_note('Steadfast: status ' . $new . '.');
            $info['status'] = $new;
        }
        $info['checked_at'] = current_time('mysql', true);
        $o->update_meta_data(self::META, $info);
        $o->save();
        return $info;
    }

    public static function refreshAll(): void {
        if (self::settings()['enabled'] !== '1' || !function_exists('wc_get_orders')) { return; }
        $orders = wc_get_orders(['limit' => 50, 'meta_key' => self::META, 'meta_compare' => 'EXISTS', 'orderby' => 'date', 'order' => 'DESC', 'date_created' => '>' . (time() - 45 * DAY_IN_SECONDS)]);
        foreach ($orders as $o) {
            if (in_array(self::info($o)['status'] ?? '', self::FINAL, true)) { continue; }
            try { self::refresh($o); } catch (DomainException $e) { /* try again next hour */ }
        }
    }

    /** Optional: book automatically when an order reaches the chosen status. */
    public static function onStatus(int $orderId, string $from, string $to): void {
        $s = self::settings();
        if ($s['enabled'] !== '1' || $s['auto_status'] === '' || $to !== $s['auto_status']) { return; }
        $o = wc_get_order($orderId);
        if (!$o || !empty(self::info($o)['consignment_id'])) { return; }
        try { self::book($o); } catch (DomainException $e) { $o->add_order_note('Steadfast: automatic booking failed: ' . $e->getMessage()); }
    }
}
