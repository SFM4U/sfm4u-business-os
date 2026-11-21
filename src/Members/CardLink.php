<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

use DomainException;

defined('ABSPATH') || exit;

/**
 * Link between shop sites and the central SFM4U account site (account.sfm4u.com).
 *
 * - The account site is the "card master": it answers signed lookups from connected shops.
 * - A shop site keeps its own khata and points, and asks the master when a scanned card
 *   is not found locally, so one SFM4U card is recognised in every shop.
 *
 * Security: every request is signed with HMAC-SHA256 using a secret shared between the two
 * sites (shown once on the master, pasted into the shop by an administrator). Timestamps
 * older than 5 minutes and replayed signatures are refused. The master returns only what a
 * cashier needs (name, photo, card type, status, member number) — never NID, birth date,
 * address or phone.
 */
final class CardLink {
    /** True while a shop creates a local customer for a central member (auto-issue must wait). */
    public static bool $adopting = false;
    /** True only in staff flows (identity checked at the counter). Self sign-ups may not claim an existing member. */
    public static bool $staffVerified = false;
    public const NS = 'sfm4u-card/v1';
    public const OPT_ROLE = 'sfm_wr_link_role';       // off | master | shop
    public const OPT_CLIENTS = 'sfm_wr_link_clients'; // master: key_id => [name, secret_enc, created, last_used]
    public const OPT_MASTER = 'sfm_wr_link_master';   // shop: [url, key_id, secret_enc]
    public const WINDOW = 300;

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function role(): string {
        $r = (string) get_option(self::OPT_ROLE, 'off');
        return in_array($r, ['master', 'shop'], true) ? $r : 'off';
    }

    /* ---------------- signing (pure, unit-tested) ---------------- */

    public static function signature(string $secret, string $method, string $path, int $time, string $body): string {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $time . "\n" . hash('sha256', $body), $secret);
    }

    /** Throws DomainException with a reason when the request must be refused. */
    public static function verify(string $secret, string $method, string $path, string $timeHeader, string $body, string $sign, int $now): void {
        if (!preg_match('/^\d{9,11}$/D', $timeHeader)) { throw new DomainException('bad time'); }
        if (abs($now - (int) $timeHeader) > self::WINDOW) { throw new DomainException('expired'); }
        if (!preg_match('/^[0-9a-f]{64}$/D', $sign)) { throw new DomainException('bad signature'); }
        if (!hash_equals(self::signature($secret, $method, $path, (int) $timeHeader, $body), $sign)) { throw new DomainException('signature mismatch'); }
    }

    public static function newKeyId(): string { return 'shop_' . bin2hex(random_bytes(6)); }
    public static function newSecret(): string { return bin2hex(random_bytes(32)); }

    /* ---------------- master side ---------------- */

    public static function clients(): array {
        $c = get_option(self::OPT_CLIENTS, []);
        return is_array($c) ? $c : [];
    }

    /** Create a shop key. Returns [key_id, secret]; the secret is shown once and stored encrypted. */
    public static function addClient(string $name): array {
        $name = trim(sanitize_text_field($name));
        if ($name === '') { throw new DomainException(__('Give the shop site a name.', 'sfm4u-wholesale')); }
        $id = self::newKeyId();
        $secret = self::newSecret();
        $c = self::clients();
        $c[$id] = ['name' => mb_substr($name, 0, 80), 'secret_enc' => Members::encrypt($secret), 'created' => current_time('mysql', true), 'last_used' => ''];
        update_option(self::OPT_CLIENTS, $c, false);
        return [$id, $secret];
    }

    public static function removeClient(string $id): void {
        $c = self::clients();
        unset($c[$id]);
        update_option(self::OPT_CLIENTS, $c, false);
    }

    public static function routes(): void {
        if (self::role() !== 'master') { return; }
        register_rest_route(self::NS, '/ping', ['methods' => 'GET', 'callback' => [self::class, 'ping'], 'permission_callback' => [self::class, 'allowed']]);
        register_rest_route(self::NS, '/lookup', ['methods' => 'GET', 'callback' => [self::class, 'lookup'], 'permission_callback' => [self::class, 'allowed'],
            'args' => ['q' => ['type' => 'string', 'required' => true]]]);
        register_rest_route(self::NS, '/members', ['methods' => 'POST', 'callback' => [self::class, 'createMember'], 'permission_callback' => [self::class, 'allowed']]);
        // Pairing: the one-time code is the credential (256-bit, 10 minutes, single use, bound to the shop's host).
        register_rest_route(self::NS, '/pair', ['methods' => 'POST', 'callback' => [self::class, 'redeemPair'], 'permission_callback' => '__return_true']);
    }

    /* ---------------- pairing (no secret is ever shown or typed) ----------------
     * 1. Shop admin presses "Connect automatically": the shop saves a random state and sends the admin to the master.
     * 2. Master admin sees "Connect <shop host>?" and approves: the master makes a shop key and a one-time code,
     *    and sends the admin back to the shop with the code (never the secret).
     * 3. The shop checks its state, then fetches the key server-to-server with the code. The code then dies.
     */

    public static function hostOf(string $url): string {
        return strtolower((string) wp_parse_url($url, PHP_URL_HOST)) . ((string) wp_parse_url($url, PHP_URL_PORT) !== '' ? ':' . wp_parse_url($url, PHP_URL_PORT) : '');
    }

    private static function okUrl(string $url): bool {
        return (bool) (preg_match('#^https://[^/\s]+#i', $url) || preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#i', $url));
    }

    /** Master: admin approved the shop. Returns the URL to send the admin back to (with the one-time code). */
    public static function approvePair(string $shopUrl, string $back, string $state): string {
        if (self::role() !== 'master') { throw new DomainException(__('This site is not set as the SFM4U account site.', 'sfm4u-wholesale')); }
        $host = self::hostOf($shopUrl);
        if ($host === '' || !self::okUrl($shopUrl) || !self::okUrl($back) || self::hostOf($back) !== $host) {
            throw new DomainException(__('The shop address is not valid.', 'sfm4u-wholesale'));
        }
        if (!preg_match('/^[0-9a-f]{32}$/D', $state)) { throw new DomainException(__('The connect request is not valid. Start again from the shop site.', 'sfm4u-wholesale')); }
        [$id] = self::addClient($host);
        $code = bin2hex(random_bytes(32));
        set_transient('sfm_wr_pair_' . hash('sha256', $code), ['id' => $id, 'host' => $host], 600);
        return add_query_arg(['sfm_pair_code' => $code, 'sfm_pair_state' => $state], $back);
    }

    /** Master REST: exchange a one-time code for the shop key. */
    public static function redeemPair(\WP_REST_Request $req): \WP_REST_Response {
        $code = (string) $req->get_param('code');
        $shop = (string) $req->get_param('shop');
        $bad = new \WP_REST_Response(['ok' => false, 'message' => 'Pairing code not valid or expired.'], 400);
        if (!preg_match('/^[0-9a-f]{64}$/D', $code)) { return $bad; }
        $key = 'sfm_wr_pair_' . hash('sha256', $code);
        $p = get_transient($key);
        delete_transient($key); // single use, even when the check below fails
        if (!is_array($p) || !hash_equals((string) $p['host'], self::hostOf($shop))) { return $bad; }
        $c = self::clients();
        if (!isset($c[$p['id']])) { return $bad; }
        return new \WP_REST_Response(['ok' => true, 'key_id' => $p['id'], 'secret' => Members::decrypt((string) $c[$p['id']]['secret_enc']), 'site' => get_bloginfo('name')]);
    }

    /** permission_callback: valid signature from a known shop key. */
    public static function allowed(\WP_REST_Request $req) {
        $id = (string) $req->get_header('x_sfm4u_key');
        $c = self::clients();
        if ($id === '' || !isset($c[$id])) { return new \WP_Error('sfm4u_link', 'Unknown shop key.', ['status' => 401]); }
        $secret = Members::decrypt((string) $c[$id]['secret_enc']);
        $path = '/' . self::NS . substr($req->get_route(), strlen('/' . self::NS));
        $query = $req->get_query_params();
        unset($query['rest_route']);
        ksort($query);
        $signedPath = $path . ($query ? '?' . http_build_query($query) : '');
        $sign = (string) $req->get_header('x_sfm4u_sign');
        try {
            self::verify($secret, $req->get_method(), $signedPath, (string) $req->get_header('x_sfm4u_time'), (string) $req->get_body(), $sign, time());
        } catch (DomainException $e) {
            return new \WP_Error('sfm4u_link', 'Refused: ' . $e->getMessage(), ['status' => 401]);
        }
        $seen = 'sfm_wr_link_seen_' . substr($sign, 0, 40);
        if (get_transient($seen)) { return new \WP_Error('sfm4u_link', 'Refused: replay', ['status' => 401]); }
        set_transient($seen, 1, self::WINDOW * 2);
        $c[$id]['last_used'] = current_time('mysql', true);
        update_option(self::OPT_CLIENTS, $c, false);
        return true;
    }

    public static function ping(): \WP_REST_Response {
        return new \WP_REST_Response(['ok' => true, 'site' => get_bloginfo('name'), 'time' => time()]);
    }

    /** Find a member by anything a cashier can scan or type; return only cashier-safe fields. */
    public static function lookup(\WP_REST_Request $req): \WP_REST_Response {
        $card = self::findLocal((string) $req->get_param('q'));
        if (!$card) { return new \WP_REST_Response(['found' => false]); }
        return new \WP_REST_Response(['found' => true] + self::publicMember($card));
    }

    /**
     * Master: find the person's SFM4U account by email or mobile, or create it (owner decision: cards are made by the
     * central SFM4U system, shops only print them). A regular card is issued automatically for a new account.
     */
    public static function createMember(\WP_REST_Request $req): \WP_REST_Response {
        $d = json_decode((string) $req->get_body(), true);
        $d = is_array($d) ? $d : [];
        try {
            $uid = self::findOrCreateAccount((string) ($d['email'] ?? ''), (string) ($d['phone'] ?? ''), (string) ($d['name'] ?? ''), !empty($d['match']));
        } catch (DomainException $e) {
            return new \WP_REST_Response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        if (!Members::cardsOf($uid)) { Members::autoIssue($uid); }
        if (!Members::cardsOf($uid)) { Members::issue($uid, 'regular', 0, 0, 'main', null, '', 'main'); }
        $card = Members::bestCardFor($uid) ?: Members::cardsOf($uid)[0];
        return new \WP_REST_Response(['ok' => true, 'found' => true] + self::publicMember($card));
    }

    /**
     * $match = true only when shop staff checked the person (card or NID at the counter). A self sign-up on a shop
     * site must not take over an existing member by typing their email or mobile.
     */
    public static function findOrCreateAccount(string $email, string $phone, string $name, bool $match = false): int {
        $email = sanitize_email($email);
        $phone = Members::digits($phone, true);
        $name = trim(sanitize_text_field($name));
        $existing = 0;
        if ($email !== '' && ($u = get_user_by('email', $email))) { $existing = (int) $u->ID; }
        if (!$existing && $phone !== '') {
            $found = get_users(['meta_key' => 'billing_phone', 'meta_value' => $phone, 'number' => 2, 'fields' => 'ID']);
            if (count($found) > 1) { throw new DomainException('More than one account has this mobile number. Use the email.'); }
            if (count($found) === 1) { $existing = (int) $found[0]; }
        }
        if ($existing) {
            if (!$match) { throw new DomainException('This email or mobile already belongs to an SFM4U member. Ask the shop to check the card and link it.'); }
            return $existing;
        }
        if ($email === '' && $phone === '') { throw new DomainException('Give an email or a mobile number.'); }
        if ($name === '') { throw new DomainException('Give the member name.'); }
        $base = $email !== '' ? sanitize_user(strtok($email, '@'), true) : 'm' . preg_replace('/\D/', '', $phone);
        $login = $base !== '' ? $base : 'member';
        for ($i = 2; username_exists($login); $i++) { $login = $base . $i; }
        $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $email, 'display_name' => $name, 'first_name' => $name]);
        if (is_wp_error($uid)) { throw new DomainException($uid->get_error_message()); }
        if ($phone !== '') { update_user_meta((int) $uid, 'billing_phone', $phone); }
        update_user_meta((int) $uid, 'sfm_wr_created_by_shop', 1);
        return (int) $uid;
    }

    /** Same matching as the scan screen: member no, card no, token/QR, NID or birth registration. */
    public static function findLocal(string $raw): ?array {
        [$kind, $val] = Members::parseScan($raw);
        if ($kind === 'invalid') { return null; }
        if ($kind === 'member_no') {
            // Linked shop: only numbers that came from the SFM4U account site; otherwise ask the master.
            $uid = Members::userByMemberNo($val, Members::isLinkedShop());
            return $uid ? Members::bestCardFor($uid) : null;
        }
        $card = Members::find($kind, $val);
        return $card ? (Members::bestCardFor((int) $card['user_id']) ?: $card) : null;
    }

    public static function publicMember(array $card): array {
        $uid = (int) $card['user_id'];
        $u = get_userdata($uid);
        $nameEn = trim((string) get_user_meta($uid, 'sfm_wr_name_en', true));
        $nameBn = trim((string) get_user_meta($uid, 'sfm_wr_name_bn', true));
        return [
            'member_no' => Members::memberNo($uid),
            'tier' => (string) $card['tier'],
            'status' => (string) $card['status'],
            'name' => $nameEn !== '' ? $nameEn : ($u ? $u->display_name : ''),
            'name_bn' => $nameBn,
            'business_name' => (string) ($card['business_name'] ?? ''),
            'photo' => !empty($card['photo_id']) ? Members::photoUrl($card) : '',
            'district' => (string) (BdMap::districtFor($uid) ?? ''),
            'since' => substr((string) ($card['created_at'] ?? ''), 0, 10),
            // 0.9.0: tells shops whether this card gives the wholesale price now (Business active, or Business Black paid up).
            'wholesale_ok' => (string) $card['status'] === 'active'
                && ((string) $card['tier'] === 'business' || ((string) $card['tier'] === 'black' && class_exists(Tiers::class) && Tiers::blackActive($uid))),
        ];
    }

    /* ---------------- shop side ---------------- */

    public static function master(): array {
        $m = get_option(self::OPT_MASTER, []);
        return is_array($m) ? $m : [];
    }

    public static function setMaster(string $url, string $keyId, string $secret): void {
        $url = esc_url_raw(trim($url));
        if (!preg_match('#^https://#i', $url) && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#i', $url)) {
            throw new DomainException(__('The account site address must start with https://', 'sfm4u-wholesale'));
        }
        if (!preg_match('/^shop_[0-9a-f]{12}$/D', $keyId) || !preg_match('/^[0-9a-f]{64}$/D', $secret)) {
            throw new DomainException(__('The key ID or secret is not valid. Copy them again from the account site.', 'sfm4u-wholesale'));
        }
        update_option(self::OPT_MASTER, ['url' => untrailingslashit($url), 'key_id' => $keyId, 'secret_enc' => Members::encrypt($secret)], false);
    }

    /** Shop: start pairing. Returns the master page to open. */
    public static function startPair(string $masterUrl): string {
        $masterUrl = untrailingslashit(esc_url_raw(trim($masterUrl)));
        if (!self::okUrl($masterUrl)) { throw new DomainException(__('The account site address must start with https://', 'sfm4u-wholesale')); }
        $state = bin2hex(random_bytes(16));
        set_transient('sfm_wr_pair_state_' . get_current_user_id(), ['state' => $state, 'url' => $masterUrl], 900);
        return $masterUrl . '/wp-admin/admin.php?' . http_build_query(['page' => 'sfm-wr-link', 'sfm_pair' => 1, 'shop' => home_url('/'),
            'back' => admin_url('admin.php?page=sfm-wr-link'), 'state' => $state]);
    }

    /** Shop: the admin came back with a one-time code. Fetch the key server-to-server and save it. */
    public static function finishPair(string $code, string $state): string {
        $t = 'sfm_wr_pair_state_' . get_current_user_id();
        $s = get_transient($t);
        delete_transient($t);
        if (!is_array($s) || !preg_match('/^[0-9a-f]{32}$/D', $state) || !hash_equals((string) $s['state'], $state)) {
            throw new DomainException(__('The connect request expired or did not start here. Press "Connect automatically" again.', 'sfm4u-wholesale'));
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $code)) { throw new DomainException(__('The pairing code is not valid.', 'sfm4u-wholesale')); }
        $res = wp_remote_post($s['url'] . '/?rest_route=' . rawurlencode('/' . self::NS . '/pair'), ['timeout' => 10,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => (string) wp_json_encode(['code' => $code, 'shop' => home_url('/')])]);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach the SFM4U account site.', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $d = json_decode((string) wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) !== 200 || !is_array($d) || empty($d['ok'])) {
            throw new DomainException(__('The SFM4U account site did not accept the pairing code. Start again.', 'sfm4u-wholesale'));
        }
        self::setMaster((string) $s['url'], (string) $d['key_id'], (string) $d['secret']);
        update_option(self::OPT_ROLE, 'shop', false);
        $pong = self::call('/ping');
        return (string) ($pong['site'] ?? '');
    }

    /** Signed GET to the master. Returns decoded JSON or throws DomainException. */
    public static function call(string $route, array $query = []): array {
        $m = self::master();
        if (self::role() !== 'shop' || empty($m['url']) || empty($m['key_id'])) { throw new DomainException(__('This site is not connected to the SFM4U account site.', 'sfm4u-wholesale')); }
        $secret = Members::decrypt((string) $m['secret_enc']);
        $query['_n'] = bin2hex(random_bytes(8)); // two identical calls in the same second must not look like a replay
        ksort($query);
        $path = '/' . self::NS . $route . ($query ? '?' . http_build_query($query) : '');
        $time = time();
        // ?rest_route= works with and without pretty permalinks.
        $url = $m['url'] . '/?rest_route=' . rawurlencode('/' . self::NS . $route) . ($query ? '&' . http_build_query($query) : '');
        $res = wp_remote_get($url, ['timeout' => 8, 'headers' => [
            'X-SFM4U-Key' => $m['key_id'], 'X-SFM4U-Time' => (string) $time,
            'X-SFM4U-Sign' => self::signature($secret, 'GET', $path, $time, ''), 'Accept' => 'application/json',
        ]]);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach the SFM4U account site.', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($data)) {
            throw new DomainException(sprintf(__('The SFM4U account site refused the request (%d).', 'sfm4u-wholesale'), $code) . (is_array($data) && !empty($data['message']) ? ' ' . $data['message'] : ''));
        }
        return $data;
    }

    /** Signed POST (JSON body) to the master. */
    public static function post(string $route, array $body): array {
        $m = self::master();
        if (self::role() !== 'shop' || empty($m['url']) || empty($m['key_id'])) { throw new DomainException(__('This site is not connected to the SFM4U account site.', 'sfm4u-wholesale')); }
        $secret = Members::decrypt((string) $m['secret_enc']);
        $body['_n'] = bin2hex(random_bytes(8));
        $json = (string) wp_json_encode($body);
        $path = '/' . self::NS . $route;
        $time = time();
        $res = wp_remote_post($m['url'] . '/?rest_route=' . rawurlencode($path), ['timeout' => 10, 'body' => $json, 'headers' => [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-SFM4U-Key' => $m['key_id'], 'X-SFM4U-Time' => (string) $time,
            'X-SFM4U-Sign' => self::signature($secret, 'POST', $path, $time, $json),
        ]]);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach the SFM4U account site.', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200 || !is_array($data) || empty($data['ok'])) {
            throw new DomainException(sprintf(__('The SFM4U account site refused the request (%d).', 'sfm4u-wholesale'), $code) . (is_array($data) && !empty($data['message']) ? ' ' . $data['message'] : ''));
        }
        return $data;
    }

    /** Shop: the central member number for a local person (asks the account site to find or create the member). */
    public static function centralNumberFor(int $userId): string {
        $u = get_userdata($userId);
        if (!$u) { throw new DomainException(__('Customer not found.', 'sfm4u-wholesale')); }
        $data = self::post('/members', ['email' => (string) $u->user_email, 'phone' => (string) get_user_meta($userId, 'billing_phone', true),
            'name' => trim((string) get_user_meta($userId, 'sfm_wr_name_en', true)) ?: $u->display_name, 'match' => self::$staffVerified]);
        if (!preg_match('/^\d{7}$/', (string) ($data['member_no'] ?? ''))) { throw new DomainException(__('The SFM4U account site did not give a member number.', 'sfm4u-wholesale')); }
        return (string) $data['member_no'];
    }

    /** Shop: make a central member (found by scan) a customer of this shop, so khata and points can start. */
    public static function adoptRemote(array $m): int {
        $no = (string) ($m['member_no'] ?? '');
        if (!preg_match('/^\d{7}$/', $no)) { throw new DomainException(__('Card not found.', 'sfm4u-wholesale')); }
        $uid = Members::userByMemberNo($no, true);
        if (!$uid) {
            // Never reuse an existing login: someone could have registered "sfm4u-<number>" to take over a member.
            $login = 'sfm4u-' . $no;
            while (username_exists($login)) { $login = 'sfm4u-' . $no . '-' . strtolower(wp_generate_password(6, false, false)); }
            self::$adopting = true;
            $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => '', 'display_name' => (string) ($m['name'] ?? $no)]);
            self::$adopting = false;
            if (is_wp_error($uid)) { throw new DomainException($uid->get_error_message()); }
            $uid = (int) $uid;
            update_user_meta($uid, 'sfm_wr_member_no', $no);
            if (!empty($m['name_bn'])) { update_user_meta($uid, 'sfm_wr_name_bn', sanitize_text_field((string) $m['name_bn'])); }
            if (!empty($m['name'])) { update_user_meta($uid, 'sfm_wr_name_en', sanitize_text_field((string) $m['name'])); }
            update_user_meta($uid, 'sfm_wr_central', 1);
        }
        if (!Members::cardsOf($uid)) {
            $tier = in_array($m['tier'] ?? '', Members::TIERS, true) ? (string) $m['tier'] : 'regular';
            Members::issue($uid, $tier, 0, get_current_user_id(), 'main', null, (string) ($m['business_name'] ?? ''), Members::currentStore() ?: 'main');
        }
        return $uid;
    }

    /** Scan fallback on a shop: ask the master. Returns the cashier-safe member or null. */
    public static function remoteLookup(string $raw): ?array {
        $data = self::call('/lookup', ['q' => $raw]);
        return !empty($data['found']) ? $data : null;
    }
}
