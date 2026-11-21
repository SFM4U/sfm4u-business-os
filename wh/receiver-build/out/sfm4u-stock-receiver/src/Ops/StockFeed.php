<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Ops;

use DomainException;
use SFM4U\Wholesale\Members\{CardLink, Members, Tiers};

defined('ABSPATH') || exit;

/**
 * Business Black auto stock: when a Business Black member buys from our wholesale shop, the goods are added
 * to that member's own store on AlgiBazar.com — no typing stock again.
 *
 *  - Wholesale site = "sender". On a Completed order of a member with an active (paid) Business Black plan,
 *    it sends the items (SKU, name, quantity, price paid, suggested retail price, image) to AlgiBazar.
 *  - AlgiBazar.com = "receiver" (WooCommerce + Dokan). It finds the member's store and adds the quantities:
 *    an existing product of that store (same source SKU) gets more stock; a new one is created as a DRAFT
 *    product of that store, so the shopkeeper checks the price and publishes it himself.
 *  - Requests are signed (HMAC-SHA256, 5 minutes, no replay). Sites are paired with "Connect automatically"
 *    (one-time code, no secret is typed). One order is applied once (order reference is remembered).
 */
final class StockFeed {
    public const NS = 'sfm4u-stock/v1';
    public const OPT_ROLE = 'sfm_wr_stock_role';          // off | sender | receiver
    public const OPT_KEYS = 'sfm_wr_stock_keys';          // receiver: key_id => [name, secret_enc, created]
    public const OPT_TARGET = 'sfm_wr_stock_target';      // sender: [url, key_id, secret_enc]
    public const OPT_SEEN = 'sfm_wr_stock_seen';          // receiver: order_ref => [time, vendor, created, updated]
    public const OPT_LOG = 'sfm_wr_stock_log';
    public const META = '_sfm_stockfeed';
    public const CRON = 'sfm_wr_stockfeed_retry';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('woocommerce_order_status_completed', [self::class, 'onCompleted'], 20);
        add_action(self::CRON, [self::class, 'retry']);
        add_action('init', static function (): void {
            if (self::role() === 'sender' && !wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 900, 'hourly', self::CRON); }
        });
    }

    public static function role(): string {
        $r = (string) get_option(self::OPT_ROLE, 'off');
        return in_array($r, ['sender', 'receiver'], true) ? $r : 'off';
    }

    public static function log(string $m): void {
        $l = get_option(self::OPT_LOG, []);
        $l = is_array($l) ? $l : [];
        $l[] = [current_time('mysql', true), mb_substr($m, 0, 300)];
        update_option(self::OPT_LOG, array_slice($l, -80), false);
    }

    private static function okUrl(string $u): bool {
        return (bool) (preg_match('#^https://[^/\s]+#i', $u) || preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#i', $u));
    }

    /* ---------------- receiver ---------------- */

    public static function routes(): void {
        if (self::role() !== 'receiver') { return; }
        register_rest_route(self::NS, '/in', ['methods' => 'POST', 'callback' => [self::class, 'receive'], 'permission_callback' => [self::class, 'allowed']]);
        register_rest_route(self::NS, '/ping', ['methods' => 'GET', 'callback' => static fn() => new \WP_REST_Response(['ok' => true, 'site' => get_bloginfo('name')]), 'permission_callback' => [self::class, 'allowed']]);
        register_rest_route(self::NS, '/pair', ['methods' => 'POST', 'callback' => [self::class, 'redeemPair'], 'permission_callback' => '__return_true']);
    }

    public static function keys(): array { $k = get_option(self::OPT_KEYS, []); return is_array($k) ? $k : []; }

    public static function allowed(\WP_REST_Request $req) {
        $id = (string) $req->get_header('x_sfm4u_key');
        $k = self::keys();
        if ($id === '' || !isset($k[$id])) { return new \WP_Error('sfm4u_stock', 'Unknown key.', ['status' => 401]); }
        $path = '/' . self::NS . substr($req->get_route(), strlen('/' . self::NS));
        $q = $req->get_query_params(); unset($q['rest_route']); ksort($q);
        $sign = (string) $req->get_header('x_sfm4u_sign');
        try {
            CardLink::verify(Members::decrypt((string) $k[$id]['secret_enc']), $req->get_method(), $path . ($q ? '?' . http_build_query($q) : ''), (string) $req->get_header('x_sfm4u_time'), (string) $req->get_body(), $sign, time());
        } catch (DomainException $e) {
            return new \WP_Error('sfm4u_stock', 'Refused: ' . $e->getMessage(), ['status' => 401]);
        }
        $seen = 'sfm_wr_stock_sig_' . substr($sign, 0, 40);
        if (get_transient($seen)) { return new \WP_Error('sfm4u_stock', 'Refused: replay', ['status' => 401]); }
        set_transient($seen, 1, 600);
        return true;
    }

    /** Find the member's store owner on this marketplace: member number first, then email. */
    public static function findVendor(string $memberNo, string $email): int {
        $uid = 0;
        if (preg_match('/^\d{7}$/', $memberNo)) {
            $f = get_users(['meta_key' => 'sfm_wr_member_no', 'meta_value' => $memberNo, 'number' => 2, 'fields' => 'ID']);
            if (count($f) === 1) { $uid = (int) $f[0]; }
        }
        if (!$uid && is_email($email) && ($u = get_user_by('email', $email))) { $uid = (int) $u->ID; }
        if (!$uid) { return 0; }
        $isSeller = function_exists('dokan_is_user_seller') ? (bool) dokan_is_user_seller($uid) : user_can($uid, 'seller');
        return $isSeller ? $uid : 0;
    }

    /** Apply one purchase to a store. Pure enough to test without HTTP. Returns [created, updated]. */
    public static function apply(int $vendor, string $orderRef, array $items): array {
        $seen = get_option(self::OPT_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        if (isset($seen[$orderRef])) { return ['created' => 0, 'updated' => 0, 'duplicate' => true]; }
        $created = 0; $updated = 0;
        foreach (array_slice($items, 0, 200) as $it) {
            $src = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($it['sku'] ?? ''));
            $qty = (float) ($it['qty'] ?? 0);
            if ($src === '' || $qty <= 0) { continue; }
            $sku = mb_substr($src, 0, 80) . '-V' . $vendor; // SKUs are unique site-wide; one product per store per source SKU
            $pid = (int) wc_get_product_id_by_sku($sku);
            if ($pid) {
                $p = wc_get_product($pid);
                if (!$p || (int) get_post_field('post_author', $pid) !== $vendor) { continue; }
                if (!$p->managing_stock()) { $p->set_manage_stock(true); $p->set_stock_quantity(0); $p->save(); }
                $newCost = (float) ($it['cost'] ?? 0);
                if ($newCost > 0) { // keep the seller's buying cost right for profit & loss (weighted average)
                    $avg = \SFM4U\Wholesale\Market\VendorProfit::averageCost((float) $p->get_stock_quantity(), (float) get_post_meta($pid, '_sfm_cost', true), $qty, $newCost);
                    update_post_meta($pid, '_sfm_cost', (string) $avg);
                }
                wc_update_product_stock($p, $qty, 'increase');
                $updated++;
            } else {
                $p = new \WC_Product_Simple();
                $p->set_name(mb_substr(sanitize_text_field((string) ($it['name'] ?? $src)), 0, 200));
                $p->set_sku($sku);
                $p->set_status('draft');
                $retail = (float) ($it['retail'] ?? 0);
                if ($retail > 0) { $p->set_regular_price((string) $retail); }
                $p->set_manage_stock(true);
                $p->set_stock_quantity($qty);
                $p->update_meta_data('_sfm_source_sku', $src);
                $p->update_meta_data('_sfm_cost', (string) (float) ($it['cost'] ?? 0));
                $p->update_meta_data('_sfm_from_order', $orderRef);
                $newId = $p->save();
                wp_update_post(['ID' => $newId, 'post_author' => $vendor]);
                $created++;
            }
        }
        $seen[$orderRef] = [current_time('mysql', true), $vendor, $created, $updated];
        update_option(self::OPT_SEEN, array_slice($seen, -2000, null, true), false);
        return ['created' => $created, 'updated' => $updated, 'duplicate' => false];
    }

    public static function receive(\WP_REST_Request $req): \WP_REST_Response {
        $d = json_decode((string) $req->get_body(), true);
        if (!is_array($d) || empty($d['order_ref']) || !is_array($d['items'] ?? null)) { return new \WP_REST_Response(['ok' => false, 'message' => 'Bad request.'], 400); }
        $ref = mb_substr(sanitize_text_field((string) $d['order_ref']), 0, 100);
        $vendor = self::findVendor((string) ($d['member_no'] ?? ''), (string) ($d['email'] ?? ''));
        if (!$vendor) {
            self::log('No AlgiBazar store for member ' . ($d['member_no'] ?? '?') . ' (' . $ref . ')');
            return new \WP_REST_Response(['ok' => false, 'code' => 'no_store', 'message' => 'This member has no store on this marketplace.'], 404);
        }
        $r = self::apply($vendor, $ref, $d['items']);
        self::log(sprintf('%s: store #%d, %d new (draft), %d stock added%s', $ref, $vendor, $r['created'], $r['updated'], $r['duplicate'] ? ' (already done)' : ''));
        return new \WP_REST_Response(['ok' => true] + $r);
    }

    /* ---------------- pairing ---------------- */

    public static function approvePair(string $senderUrl, string $back, string $state): string {
        if (self::role() !== 'receiver') { throw new DomainException(__('This site is not set to receive stock.', 'sfm4u-wholesale')); }
        $host = CardLink::hostOf($senderUrl);
        if ($host === '' || !self::okUrl($senderUrl) || !self::okUrl($back) || CardLink::hostOf($back) !== $host || !preg_match('/^[0-9a-f]{32}$/D', $state)) {
            throw new DomainException(__('The connect request is not valid. Start again.', 'sfm4u-wholesale'));
        }
        $id = 'stk_' . bin2hex(random_bytes(6));
        $k = self::keys();
        $k[$id] = ['name' => $host, 'secret_enc' => Members::encrypt(bin2hex(random_bytes(32))), 'created' => current_time('mysql', true)];
        update_option(self::OPT_KEYS, $k, false);
        $code = bin2hex(random_bytes(32));
        set_transient('sfm_wr_stockpair_' . hash('sha256', $code), ['id' => $id, 'host' => $host], 600);
        return add_query_arg(['sfm_stock_code' => $code, 'sfm_stock_state' => $state], $back);
    }

    public static function redeemPair(\WP_REST_Request $req): \WP_REST_Response {
        $code = (string) $req->get_param('code');
        $bad = new \WP_REST_Response(['ok' => false, 'message' => 'Code not valid or expired.'], 400);
        if (!preg_match('/^[0-9a-f]{64}$/D', $code)) { return $bad; }
        $t = 'sfm_wr_stockpair_' . hash('sha256', $code);
        $p = get_transient($t);
        delete_transient($t);
        $k = self::keys();
        if (!is_array($p) || !hash_equals((string) $p['host'], CardLink::hostOf((string) $req->get_param('site'))) || !isset($k[$p['id']])) { return $bad; }
        return new \WP_REST_Response(['ok' => true, 'key_id' => $p['id'], 'secret' => Members::decrypt((string) $k[$p['id']]['secret_enc']), 'site' => get_bloginfo('name')]);
    }

    public static function startPair(string $url): string {
        $url = untrailingslashit(esc_url_raw(trim($url)));
        if (!self::okUrl($url)) { throw new DomainException(__('The address must start with https://', 'sfm4u-wholesale')); }
        $state = bin2hex(random_bytes(16));
        set_transient('sfm_wr_stockstate_' . get_current_user_id(), ['state' => $state, 'url' => $url], 900);
        return $url . '/wp-admin/admin.php?' . http_build_query(['page' => 'sfm-wr-stockfeed', 'sfm_stock_pair' => 1, 'site' => home_url('/'), 'back' => admin_url('admin.php?page=sfm-wr-stockfeed'), 'state' => $state]);
    }

    public static function finishPair(string $code, string $state): string {
        $t = 'sfm_wr_stockstate_' . get_current_user_id();
        $s = get_transient($t);
        delete_transient($t);
        if (!is_array($s) || !preg_match('/^[0-9a-f]{32}$/D', $state) || !hash_equals((string) $s['state'], $state) || !preg_match('/^[0-9a-f]{64}$/D', $code)) {
            throw new DomainException(__('The connect request expired. Press "Connect automatically" again.', 'sfm4u-wholesale'));
        }
        $res = wp_remote_post($s['url'] . '/?rest_route=' . rawurlencode('/' . self::NS . '/pair'), ['timeout' => 10, 'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['code' => $code, 'site' => home_url('/')])]);
        $d = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($d) || empty($d['ok']) || !preg_match('/^stk_[0-9a-f]{12}$/D', (string) ($d['key_id'] ?? '')) || !preg_match('/^[0-9a-f]{64}$/D', (string) ($d['secret'] ?? ''))) {
            throw new DomainException(__('AlgiBazar did not accept the code. Start again.', 'sfm4u-wholesale'));
        }
        update_option(self::OPT_TARGET, ['url' => $s['url'], 'key_id' => $d['key_id'], 'secret_enc' => Members::encrypt($d['secret'])], false);
        update_option(self::OPT_ROLE, 'sender', false);
        self::call('GET', '/ping');
        return (string) ($d['site'] ?? '');
    }

    /* ---------------- sender ---------------- */

    public static function call(string $method, string $route, ?array $body = null): array {
        $t = get_option(self::OPT_TARGET, []);
        if (!is_array($t) || empty($t['url']) || empty($t['key_id'])) { throw new DomainException(__('Not connected to AlgiBazar.', 'sfm4u-wholesale')); }
        $path = '/' . self::NS . $route;
        $nonce = bin2hex(random_bytes(8)); // two identical calls in one second must not look like a replay
        $json = $body === null ? '' : (string) wp_json_encode($body + ['_n' => $nonce]);
        $query = $body === null ? ['_n' => $nonce] : [];
        $time = time();
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Accept' => 'application/json', 'X-SFM4U-Key' => $t['key_id'], 'X-SFM4U-Time' => (string) $time,
            'X-SFM4U-Sign' => CardLink::signature(Members::decrypt((string) $t['secret_enc']), $method, $path . ($query ? '?' . http_build_query($query) : ''), $time, $json)]];
        if ($body !== null) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = $json; }
        $res = wp_remote_request($t['url'] . '/?rest_route=' . rawurlencode($path) . ($query ? '&' . http_build_query($query) : ''), $args);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach AlgiBazar.', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $d = json_decode((string) wp_remote_retrieve_body($res), true);
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200 || !is_array($d) || empty($d['ok'])) {
            throw new DomainException(sprintf(__('AlgiBazar refused (%d).', 'sfm4u-wholesale'), $code) . (is_array($d) && !empty($d['message']) ? ' ' . sanitize_text_field((string) $d['message']) : ''));
        }
        return $d;
    }

    public static function payload(\WC_Order $o): array {
        $items = [];
        foreach ($o->get_items() as $it) {
            $p = $it->get_product();
            if (!$p || $p->get_sku() === '') { continue; }
            $qty = (float) $it->get_quantity();
            $img = $p->get_image_id() ? (string) wp_get_attachment_url((int) $p->get_image_id()) : '';
            $items[] = ['sku' => $p->get_sku(), 'name' => wp_strip_all_tags($p->get_name()), 'qty' => $qty,
                'cost' => $qty > 0 ? round((float) $o->get_line_total($it, true) / $qty, 2) : 0, 'retail' => (float) $p->get_regular_price(), 'image' => $img];
        }
        $uid = (int) $o->get_customer_id();
        return ['order_ref' => CardLink::hostOf(home_url('/')) . '#' . $o->get_order_number(), 'member_no' => $uid ? Members::memberNo($uid) : '',
            'email' => (string) $o->get_billing_email(), 'items' => $items];
    }

    /** Send one order. Returns a status word saved on the order. */
    public static function send(\WC_Order $o): string {
        $uid = (int) $o->get_customer_id();
        if (self::role() !== 'sender' || !$uid || !Tiers::blackActive($uid)) { return 'skip'; }
        $done = $o->get_meta(self::META);
        if (is_array($done) && ($done['status'] ?? '') === 'sent') { return 'sent'; }
        $pl = self::payload($o);
        if (!$pl['items']) { return 'skip'; }
        try {
            $r = self::call('POST', '/in', $pl);
            $info = ['status' => 'sent', 'at' => current_time('mysql', true), 'created' => (int) ($r['created'] ?? 0), 'updated' => (int) ($r['updated'] ?? 0)];
            $o->add_order_note(sprintf('AlgiBazar stock: %d new products (draft), %d products got more stock.', $info['created'], $info['updated']));
        } catch (DomainException $e) {
            $info = ['status' => 'failed', 'at' => current_time('mysql', true), 'error' => $e->getMessage(), 'tries' => (int) (is_array($done) ? ($done['tries'] ?? 0) : 0) + 1];
            $o->add_order_note('AlgiBazar stock: not sent. ' . $e->getMessage());
        }
        $o->update_meta_data(self::META, $info);
        $o->save();
        self::log('#' . $o->get_order_number() . ': ' . $info['status']);
        return $info['status'];
    }

    public static function onCompleted(int $orderId): void {
        $o = wc_get_order($orderId);
        if ($o) { self::send($o); }
    }

    public static function retry(): void {
        if (self::role() !== 'sender' || !function_exists('wc_get_orders')) { return; }
        foreach (wc_get_orders(['limit' => 30, 'status' => ['completed'], 'meta_key' => self::META, 'meta_compare' => 'EXISTS', 'date_created' => '>' . (time() - 14 * DAY_IN_SECONDS)]) as $o) {
            $m = $o->get_meta(self::META);
            if (is_array($m) && ($m['status'] ?? '') === 'failed' && (int) ($m['tries'] ?? 0) < 24) { self::send($o); }
        }
    }
}
