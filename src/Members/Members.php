<?php
namespace SFM4U\Wholesale\Members;

use DomainException;

/**
 * Member cards and the khata (credit ledger).
 *
 * Card tiers: regular (retail price), business (wholesale buyer), premium (extra benefits).
 * One person can hold one card per tier. A card has a public number (12 digits, Luhn check)
 * and a secret random token that goes inside the QR code. Khata rows are append-only:
 * a mistake is fixed with an "adjust" row, never by editing or deleting history.
 * All money is in store minor units (taka with 0 decimals = whole taka).
 */
final class Members {
    public const SCHEMA = '6'; // 6: khata.ref (idempotency key, 0.9.9-dev)
    public const SCHEMA_OPTION = 'sfm_wr_members_schema';
    public const TIERS = ['regular', 'business', 'premium', 'black'];
    public const KINDS = ['charge', 'payment', 'adjust'];
    public const METHODS = ['cash', 'bank', 'mobile', 'online', 'other'];
    public const CAP = 'sfm_wr_cashier';

    public static function cards(): string { global $wpdb; return $wpdb->prefix . 'sfm_wr_cards'; }
    public static function khata(): string { global $wpdb; return $wpdb->prefix . 'sfm_wr_khata'; }

    /** Register the whole member-card module once, whichever plugin (Wholesale or Membership Card) loads it. */
    public static function boot(): void {
        static $done = false;
        if ($done) { return; }
        $done = true;
        self::register();
        MembersAdmin::register();
        MembersFront::register();
        Rewards::register();
        CardLink::register();
        if (class_exists(MemberLink::class)) { MemberLink::register(); }
    }

    public static function register(): void {
        add_action('admin_init', [self::class, 'maybeInstall'], 5);
        // 0.9.9: also on the first front/REST request after an update (e.g. a POS sale before anyone opens wp-admin),
        // so new columns (khata.ref) exist before a sale writes to them. Cheap: one cached option check when up to date.
        add_action('init', [self::class, 'maybeInstall'], 5);
        add_filter('user_has_cap', [self::class, 'grantCap'], 10, 4);
        add_action('user_register', [self::class, 'autoIssue'], 20, 1);
    }

    /** Managers: shop managers when WooCommerce runs, otherwise site administrators (e.g. account.sfm4u.com). */
    public static function manageCap(): string {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }

    /** Row lock for ledger balances on MySQL/MariaDB (SQLite, used only in local tests, locks the whole file instead). */
    public static function lockSql(): string {
        return defined('DB_ENGINE') && DB_ENGINE === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public static function decimals(): int {
        return function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 0;
    }

    public const AUTO_OPTION = 'sfm_wr_auto_card';

    public static function autoOn(): bool {
        return get_option(self::AUTO_OPTION, '1') === '1';
    }

    /** A new account gets a regular member card automatically (owner decision 2026-11-20). Never blocks registration. */
    public static function autoIssue(int $userId): void {
        if (!self::autoOn() || CardLink::$adopting) { return; }
        try {
            self::maybeInstall();
            if (self::cardsOf($userId)) { return; }
            $store = (string) get_option('sfm_wr_auto_store', 'main');
            self::issue($userId, 'regular', 0, 0, self::validStoreCode($store) ? $store : 'main', null, '', self::validStoreCode($store) ? $store : 'main');
        } catch (\Throwable $e) {
            error_log('SFM4U card auto-issue failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }

    /** Give a regular card to existing accounts that have none. Returns how many were issued. */
    public static function backfill(int $limit = 200): int {
        global $wpdb;
        self::maybeInstall();
        $ids = $wpdb->get_col($wpdb->prepare('SELECT u.ID FROM ' . $wpdb->users . ' u LEFT JOIN ' . self::cards() . ' c ON c.user_id=u.ID WHERE c.id IS NULL ORDER BY u.ID LIMIT %d', $limit));
        $n = 0;
        foreach ($ids as $id) {
            try { self::issue((int) $id, 'regular', 0, get_current_user_id(), 'main', null, '', 'main'); $n++; } catch (\Throwable $e) { error_log('SFM4U backfill ' . $id . ': ' . $e->getMessage()); }
        }
        return $n;
    }

    /** Store managers and admins act as cashiers until per-store roles exist. */
    public static function grantCap(array $all, array $caps, array $args, $user): array {
        if (in_array(self::CAP, $caps, true) && !empty($all[self::manageCap()])) {
            $all[self::CAP] = true;
        }
        return $all;
    }

    public static function maybeInstall(): void {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA) {
            return;
        }
        self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::cards() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            card_no char(12) NOT NULL,
            token char(32) NOT NULL,
            tier varchar(16) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'active',
            store varchar(64) NOT NULL DEFAULT 'main',
            photo_id bigint(20) unsigned NOT NULL DEFAULT 0,
            id_type varchar(8) NOT NULL DEFAULT '',
            id_hash char(64) NOT NULL DEFAULT '',
            id_last4 char(4) NOT NULL DEFAULT '',
            business_name varchar(120) NOT NULL DEFAULT '',
            id_enc text NULL,
            issued_store varchar(64) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY card_no (card_no),
            UNIQUE KEY token (token),
            UNIQUE KEY user_tier_store (user_id,tier,store),
            KEY user_id (user_id),
            KEY id_hash (id_hash)
        ) $charset;");
        dbDelta('CREATE TABLE ' . self::khata() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            card_id bigint(20) unsigned NOT NULL DEFAULT 0,
            store varchar(64) NOT NULL DEFAULT 'main',
            kind varchar(16) NOT NULL,
            amount_minor bigint(20) NOT NULL,
            balance_minor bigint(20) NOT NULL,
            method varchar(16) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            staff_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            ref varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY user_store (user_id,store,id),
            KEY store_ref (store,ref)
        ) $charset;");
        Rewards::install();
        if (!get_role('sfm_cashier')) {
            add_role('sfm_cashier', __('SFM4U Cashier (staff)', 'sfm4u-wholesale'), ['read' => true, self::CAP => true]);
        }
        update_option(self::SCHEMA_OPTION, self::SCHEMA, false);
    }

    /* ---------- pure helpers (unit tested) ---------- */

    public static function luhnDigit(string $digits): int {
        $sum = 0;
        $double = true;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) { $d *= 2; if ($d > 9) { $d -= 9; } }
            $sum += $d;
            $double = !$double;
        }
        return (10 - ($sum % 10)) % 10;
    }

    public static function validCardNo(string $no): bool {
        return (bool) preg_match('/^[0-9]{12}$/D', $no) && self::luhnDigit(substr($no, 0, 11)) === (int) $no[11];
    }

    /** Tier digit up front makes cards easy to tell apart: 1 regular, 2 business, 3 premium, 4 Business Black. */
    public static function makeCardNo(string $tier, callable $random): string {
        $prefix = (string) (array_search($tier, self::TIERS, true) + 1);
        $body = $prefix;
        while (strlen($body) < 11) {
            $body .= (string) $random(0, 9);
        }
        return $body . self::luhnDigit($body);
    }

    /**
     * Bangladesh NID (10, 13 or 17 digits) or birth registration number (17 digits).
     * Returns [type, digits] or null. The full number is never stored: only a keyed hash
     * (to find the customer) and the last 4 digits (to show on the card).
     */
    public static function parseNationalId(string $raw, string $type): ?array {
        $d = strtr(preg_replace('/[\s-]/', '', trim($raw)), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if ($type === 'nid' && preg_match('/^(?:[0-9]{10}|[0-9]{13}|[0-9]{17})$/D', $d)) { return ['nid', $d]; }
        if ($type === 'brn' && preg_match('/^[0-9]{17}$/D', $d)) { return ['brn', $d]; }
        return null;
    }

    public static function idHash(string $digits, string $key): string {
        return hash_hmac('sha256', 'sfm4u-id:' . $digits, $key);
    }

    public static function idKey(): string {
        return function_exists('wp_salt') ? wp_salt('auth') . '|sfm_wr_id' : 'test-key';
    }

    /** Scanner input may be a card number, a QR payload "SFM4U-CARD:<token>" or a bare token. */
    public static function parseScan(string $raw): array {
        $raw = trim($raw);
        $raw = strtr($raw, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $digits = preg_replace('/[\s-]/', '', $raw);
        if (preg_match('/^[0-9]{12}$/D', $digits)) {
            return self::validCardNo($digits) ? ['card_no', $digits] : ['invalid', ''];
        }
        if (preg_match('/^[0-9]{7}$/D', $digits)) {
            return ['member_no', $digits];
        }
        if (preg_match('/^(?:[0-9]{10}|[0-9]{13}|[0-9]{17})$/D', $digits)) {
            return ['id_hash', self::idHash($digits, self::idKey())];
        }
        if (preg_match('/[?&]sfm_card=([a-f0-9]{32})(?:&|$)/D', $raw, $m) || preg_match('/^SFM4U-CARD:([a-f0-9]{32})$/Di', $raw, $m)
            || preg_match('/^([a-f0-9]{32})$/D', strtolower($raw), $m)) {
            return ['token', strtolower($m[1])];
        }
        return ['invalid', ''];
    }

    /** Typed taka amount → minor units. Accepts Bangla digits and commas. */
    public static function parseMoney(string $value, int $decimals): int {
        $value = str_replace([',', ' ', '৳'], '', trim($value));
        $value = strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if (!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,6})?$/D', $value)) {
            throw new DomainException(__('Enter a valid amount.', 'sfm4u-wholesale'));
        }
        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($frac) > $decimals && rtrim(substr($frac, $decimals), '0') !== '') {
            throw new DomainException(__('Enter a valid amount.', 'sfm4u-wholesale'));
        }
        $minor = (int) $whole * (10 ** $decimals) + (int) str_pad(substr($frac, 0, $decimals), $decimals, '0');
        if ($minor < 1 || $minor > 100000000000) {
            throw new DomainException(__('Enter a valid amount.', 'sfm4u-wholesale'));
        }
        return $minor;
    }

    /** Signed effect on what the customer owes. Positive balance = customer owes the shop. */
    public static function delta(string $kind, int $minor, string $direction = 'up'): int {
        if ($kind === 'charge') { return $minor; }
        if ($kind === 'payment') { return -$minor; }
        if ($kind === 'adjust') { return $direction === 'down' ? -$minor : $minor; }
        throw new DomainException('Invalid khata entry.');
    }

    /* ---------- data access ---------- */

    public static function tierLabel(string $tier): string {
        return [
            'regular' => __('Regular member', 'sfm4u-wholesale'),
            'business' => __('Business member', 'sfm4u-wholesale'),
            'premium' => __('Premium member', 'sfm4u-wholesale'),
            'black' => __('Business Black member', 'sfm4u-wholesale'),
        ][$tier] ?? $tier;
    }

    public static function issue(int $userId, string $tier, int $photoId, int $staffId, string $store = 'main', ?array $nationalId = null, string $businessName = '', string $issuedStore = ''): array {
        global $wpdb;
        if (!in_array($tier, self::TIERS, true) || !get_userdata($userId)) {
            throw new DomainException(__('Choose a customer and a card type.', 'sfm4u-wholesale'));
        }
        if ($photoId && (get_post_type($photoId) !== 'attachment' || !wp_attachment_is_image($photoId))) {
            throw new DomainException(__('The photo must be an image from the media library.', 'sfm4u-wholesale'));
        }
        $existing = self::cardFor($userId, $tier, $store);
        if ($existing) {
            throw new DomainException(__('This customer already has this card type.', 'sfm4u-wholesale'));
        }
        self::memberNo($userId); // number first (a linked shop asks the account site); no card row if this fails
        for ($try = 0; $try < 5; $try++) {
            $no = self::makeCardNo($tier, 'random_int');
            $ok = $wpdb->insert(self::cards(), [
                'user_id' => $userId, 'card_no' => $no, 'token' => bin2hex(random_bytes(16)), 'tier' => $tier,
                'status' => 'active', 'store' => $store, 'photo_id' => $photoId, 'created_by' => $staffId,
                'created_at' => current_time('mysql', true),
                'id_type' => $nationalId ? $nationalId[0] : '',
                'id_hash' => $nationalId ? self::idHash($nationalId[1], self::idKey()) : '',
                'id_last4' => $nationalId ? substr($nationalId[1], -4) : '',
                'business_name' => in_array($tier, ['business', 'black'], true) ? mb_substr($businessName, 0, 120) : '',
                'id_enc' => $nationalId ? self::encrypt($nationalId[1]) : null,
                'issued_store' => $issuedStore !== '' ? $issuedStore : 'main',
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            if ($ok) {
                $card = self::find('id', (int) $wpdb->insert_id);
                do_action('sfm_wr_card_issued', $card);
                return $card;
            }
        }
        throw new DomainException(__('Could not create the card. Try again.', 'sfm4u-wholesale'));
    }

    /* ---------- 7-digit member number (one per person, company-wide, sequential) ---------- */

    public static function formatMemberNo(int $n): string {
        if ($n < 1 || $n > 9999999) { throw new DomainException('Member number out of range.'); }
        return str_pad((string) $n, 7, '0', STR_PAD_LEFT);
    }

    /** Returns the member's number, assigning the next one atomically the first time. */
    public static function memberNo(int $userId): string {
        global $wpdb;
        $have = (string) get_user_meta($userId, 'sfm_wr_member_no', true);
        if ($have !== '') { return $have; }
        // Linked shop: numbers come only from the SFM4U account site (one number per person, company-wide).
        if (class_exists(CardLink::class) && CardLink::role() === 'shop') {
            $no = CardLink::centralNumberFor($userId);
            $other = self::userByMemberNo($no, true);
            if ($other && $other !== $userId) { throw new DomainException(__('This member number is already used by another customer here.', 'sfm4u-wholesale')); }
            update_user_meta($userId, 'sfm_wr_member_no', $no);
            update_user_meta($userId, 'sfm_wr_central', 1);
            return $no;
        }
        add_option('sfm_wr_member_seq', '0', '', 'no');
        // Compare-and-swap: only one request can move the counter from N to N+1 (works on MySQL/MariaDB and SQLite).
        // 0.9.9: a number already held by someone else (e.g. a central number kept from when this site was a linked
        // shop) is skipped, so two people never share a member number.
        $no = '';
        for ($skip = 0; $skip < 50 && $no === ''; $skip++) {
            $n = 0;
            for ($try = 0; $try < 25 && $n === 0; $try++) {
                $cur = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sfm_wr_member_seq'));
                $done = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) ($cur + 1), 'sfm_wr_member_seq', (string) $cur));
                if ($done === 1) { $n = $cur + 1; }
            }
            wp_cache_delete('sfm_wr_member_seq', 'options');
            wp_cache_delete('notoptions', 'options');
            if ($n === 0) { throw new DomainException(__('Could not give a member number. Try again.', 'sfm4u-wholesale')); }
            $candidate = self::formatMemberNo($n);
            $holder = self::userByMemberNo($candidate);
            if ($holder === 0 || $holder === $userId) { $no = $candidate; }
        }
        if ($no === '') { throw new DomainException(__('Could not give a member number. Try again.', 'sfm4u-wholesale')); }
        if (!add_user_meta($userId, 'sfm_wr_member_no', $no, true)) { return (string) get_user_meta($userId, 'sfm_wr_member_no', true); }
        return $no;
    }

    public static function userByMemberNo(string $no, bool $centralOnly = false): int {
        $u = get_users(['meta_key' => 'sfm_wr_member_no', 'meta_value' => $no, 'number' => 20, 'fields' => 'ID', 'orderby' => 'ID']);
        foreach ($u as $id) {
            if (!$centralOnly || self::hasCentralNo((int) $id)) { return (int) $id; }
        }
        return 0;
    }

    /**
     * On a linked shop, a member number is trusted only when it came from the SFM4U account site.
     * Numbers the shop gave out by itself before it was linked can be the same digits as a different
     * person's central number, so they never decide who a scanned number belongs to.
     */
    public static function hasCentralNo(int $userId): bool {
        return (bool) get_user_meta($userId, 'sfm_wr_central', true) || get_user_meta($userId, 'sfm_wr_link_status', true) !== '';
    }

    /** True on a shop linked to the SFM4U account site. */
    public static function isLinkedShop(): bool {
        return class_exists(CardLink::class) && CardLink::role() === 'shop';
    }

    /* ---------- NID stored encrypted (shown only to the owner and on the printed card) ---------- */

    private static function encKey(): string {
        return hash('sha256', (function_exists('wp_salt') ? wp_salt('secure_auth') : 'test') . '|sfm_wr_nid_enc', true);
    }

    public static function encrypt(string $plain): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::encKey()));
    }

    public static function decrypt(string $blob): string {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return ''; }
        $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::encKey());
        return $out === false ? '' : $out;
    }

    /** Full NID for the card owner / printing staff. Empty if none stored. */
    public static function nationalId(array $card): string {
        return !empty($card['id_enc']) ? self::decrypt((string) $card['id_enc']) : '';
    }

    /* ---------- shops (one khata per shop) ---------- */

    /** Shops in the SFM4U system: code => name. Each shop keeps its own khata for the same member. */
    public static function stores(): array {
        $out = [];
        foreach (self::storeInfos() as $code => $info) { $out[$code] = $info['name']; }
        return $out;
    }

    /** code => [name, phone, address, licence_name, licence_no, policy_url]. */
    public static function storeInfos(): array {
        $saved = get_option('sfm_wr_stores', []);
        $saved = is_array($saved) ? $saved : [];
        if (!isset($saved['main'])) { $saved = ['main' => get_bloginfo('name')] + $saved; }
        $out = [];
        foreach ($saved as $code => $v) {
            $v = is_array($v) ? $v : ['name' => (string) $v];
            $out[(string) $code] = $v + ['name' => (string) $code, 'phone' => '', 'address' => '', 'licence_name' => '', 'licence_no' => '', 'policy_url' => ''];
        }
        return $out;
    }

    public static function storeInfo(string $code): array {
        $all = self::storeInfos();
        return $all[$code] ?? $all['main'];
    }

    public static function validStoreCode(string $code): bool {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/D', $code);
    }

    /** The shop the current cashier works in. Managers may switch with ?store=. Cashiers are locked to theirs. */
    public static function currentStore(): string {
        $stores = self::stores();
        $mine = (string) get_user_meta(get_current_user_id(), 'sfm_wr_store', true);
        if (in_array(self::shopRole(), ['ceo', 'owner'], true)) {
            $want = isset($_REQUEST['store']) && is_string($_REQUEST['store']) ? sanitize_key(wp_unslash($_REQUEST['store'])) : '';
            if ($want !== '' && isset($stores[$want])) { return $want; }
            return isset($stores[$mine]) ? $mine : 'main';
        }
        return isset($stores[$mine]) ? $mine : '';
    }

    /** Members who owe this shop money: latest balance > 0, biggest first. */
    public static function dueList(string $store, int $limit = 500): array {
        global $wpdb;
        $k = self::khata();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT k.user_id, k.balance_minor, k.created_at FROM $k k JOIN (SELECT MAX(id) AS id FROM $k WHERE store=%s GROUP BY user_id) last ON last.id = k.id WHERE k.balance_minor > 0 ORDER BY k.balance_minor DESC LIMIT %d",
            $store, $limit), ARRAY_A) ?: [];
    }

    /** Bangladesh mobile in international form for WhatsApp links (01XXXXXXXXX -> 8801XXXXXXXXX), or ''. */
    public static function waNumber(string $phone): string {
        $d = self::digits($phone);
        if (preg_match('/^01\d{9}$/', $d)) { return '88' . $d; }
        if (preg_match('/^8801\d{9}$/', $d)) { return $d; }
        return '';
    }

    /** Every shop where this member has a khata, with its balance. */
    public static function balancesByStore(int $userId): array {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT store FROM ' . self::khata() . ' WHERE user_id=%d', $userId)) ?: [];
        $out = [];
        foreach ($rows as $st) { $out[$st] = self::balance($userId, (string) $st); }
        return $out;
    }

    /** Link (or replace) the NID / birth registration number on all of a person's cards. */
    public static function linkNationalId(int $userId, array $nid): void {
        global $wpdb;
        $hash = self::idHash($nid[1], self::idKey());
        if (self::idInUse($hash, $userId)) { throw new DomainException(__('This NID or birth registration number is already linked to another account.', 'sfm4u-wholesale')); }
        $wpdb->update(self::cards(), ['id_type' => $nid[0], 'id_hash' => $hash, 'id_last4' => substr($nid[1], -4), 'id_enc' => self::encrypt($nid[1])], ['user_id' => $userId], ['%s', '%s', '%s', '%s'], ['%d']);
    }

    public static function setBusinessName(int $cardId, string $name): void {
        global $wpdb;
        $wpdb->update(self::cards(), ['business_name' => mb_substr($name, 0, 120)], ['id' => $cardId], ['%s'], ['%d']);
    }

    /** After a lookup by NID, prefer the person's business card, then premium, then regular. */
    public static function bestCardFor(int $userId, string $store = 'main'): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::cards() . " WHERE user_id=%d AND store=%s ORDER BY (status='active') DESC, FIELD(tier,'black','business','premium','regular') LIMIT 1", $userId, $store), ARRAY_A);
        return $row ?: null;
    }

    public static function setStatus(int $cardId, string $status): void {
        global $wpdb;
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('Invalid status.');
        }
        $wpdb->update(self::cards(), ['status' => $status], ['id' => $cardId], ['%s'], ['%d']);
    }

    public static function find(string $field, $value): ?array {
        global $wpdb;
        $col = ['id' => 'id', 'card_no' => 'card_no', 'token' => 'token', 'id_hash' => 'id_hash'][$field] ?? null;
        if ($col === 'id_hash' && $value === '') { return null; }
        if (!$col) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::cards() . " WHERE $col = %s ORDER BY (status='active') DESC, FIELD(tier,'black','business','premium','regular') LIMIT 1", (string) $value), ARRAY_A);
        return $row ?: null;
    }

    public static function cardFor(int $userId, string $tier, string $store = 'main'): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::cards() . ' WHERE user_id=%d AND tier=%s AND store=%s',
            $userId, $tier, $store), ARRAY_A);
        return $row ?: null;
    }

    public static function cardsOf(int $userId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::cards() . ' WHERE user_id=%d ORDER BY id', $userId), ARRAY_A) ?: [];
    }

    public static function recentCards(int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::cards() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A) ?: [];
    }

    public static function balance(int $userId, string $store = 'main'): int {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT balance_minor FROM ' . self::khata() . ' WHERE user_id=%d AND store=%s ORDER BY id DESC LIMIT 1',
            $userId, $store));
        return $v === null ? 0 : (int) $v;
    }

    public static function entries(int $userId, string $store = 'main', int $limit = 20): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::khata() . ' WHERE user_id=%d AND store=%s ORDER BY id DESC LIMIT %d',
            $userId, $store, $limit), ARRAY_A) ?: [];
    }

    /**
     * Append a khata row in its own transaction, then notify listeners. Locks the customer first, so two cashiers
     * cannot write the same balance at once (also for a customer's very first entry).
     * Do NOT call this inside another open transaction (it would commit it): use recordInTransaction() there.
     */
    public static function record(int $userId, int $cardId, string $kind, int $minor, string $method, string $note,
        int $staffId, string $direction = 'up', string $store = 'main', string $ref = ''): array {
        global $wpdb;
        self::checkEntry($kind, $method, $note, $ref);
        $wpdb->query('START TRANSACTION');
        try {
            $row = self::appendLocked($userId, $cardId, $kind, $minor, $method, $note, $staffId, $direction, $store, $ref);
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        $wpdb->query('COMMIT');
        if (empty($row['replayed'])) {
            self::notifyRecorded($userId, $row);
        }
        return $row;
    }

    /**
     * Append a khata row inside the CALLER's open transaction (e.g. the POS sale's pinned journal transaction).
     * No START/COMMIT/ROLLBACK here: if the caller rolls back, the khata row is gone too. On error it throws and the
     * caller must roll back. Listeners are NOT notified: call notifyRecorded($userId, $row) after the caller commits
     * (skip it when $row['replayed'] is true).
     */
    public static function recordInTransaction(int $userId, int $cardId, string $kind, int $minor, string $method, string $note,
        int $staffId, string $direction = 'up', string $store = 'main', string $ref = ''): array {
        self::checkEntry($kind, $method, $note, $ref);
        return self::appendLocked($userId, $cardId, $kind, $minor, $method, $note, $staffId, $direction, $store, $ref);
    }

    /** Tell listeners a khata row was written (only after its transaction committed). */
    public static function notifyRecorded(int $userId, array $row): void {
        do_action('sfm_wr_khata_recorded', $userId, $row);
    }

    /** Allowed request key: 1-64 chars of letters, digits and . _ : - (never silently cut). */
    public static function validRef(string $ref): bool {
        return $ref === '' || (bool) preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $ref);
    }

    private static function checkEntry(string $kind, string $method, string $note, string $ref): void {
        if (!in_array($kind, self::KINDS, true) || ($method !== '' && !in_array($method, self::METHODS, true))) {
            throw new DomainException('Invalid khata entry.');
        }
        if ($kind === 'adjust' && trim($note) === '') {
            throw new DomainException(__('Write the reason for a correction.', 'sfm4u-wholesale'));
        }
        if (!self::validRef($ref)) {
            throw new DomainException(__('Invalid khata request key.', 'sfm4u-wholesale'), 400);
        }
    }

    /** Core insert; caller owns the transaction. Locks the customer's user row (exists even before the first entry). */
    private static function appendLocked(int $userId, int $cardId, string $kind, int $minor, string $method, string $note,
        int $staffId, string $direction, string $store, string $ref): array {
        global $wpdb;
        $delta = self::delta($kind, $minor, $direction);
        $locked = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d" . self::lockSql(), $userId));
        if ($locked === null) {
            throw new DomainException(__('Customer not found.', 'sfm4u-wholesale'), 404);
        }
        $last = $wpdb->get_var($wpdb->prepare('SELECT balance_minor FROM ' . self::khata() . ' WHERE user_id=%d AND store=%s ORDER BY id DESC LIMIT 1' . self::lockSql(),
            $userId, $store));
        if ($ref !== '') {
            // Idempotent replay: the same request key (e.g. a POS retry) never writes a second row.
            // Checked while this customer is locked, so two cashiers cannot both insert it.
            $seen = $wpdb->get_row($wpdb->prepare('SELECT id, kind, amount_minor, balance_minor FROM ' . self::khata() . ' WHERE user_id=%d AND store=%s AND ref=%s LIMIT 1',
                $userId, $store, $ref), ARRAY_A);
            if ($seen) {
                if ($seen['kind'] !== $kind || (int) $seen['amount_minor'] !== $delta) {
                    throw new DomainException(__('This khata request was already used for a different entry.', 'sfm4u-wholesale'), 409);
                }
                return ['id' => (int) $seen['id'], 'balance_minor' => (int) $seen['balance_minor'], 'amount_minor' => (int) $seen['amount_minor'], 'replayed' => true];
            }
        }
        $balance = ($last === null ? 0 : (int) $last) + $delta;
        $ok = $wpdb->insert(self::khata(), [
            'user_id' => $userId, 'card_id' => $cardId, 'store' => $store, 'kind' => $kind,
            'amount_minor' => $delta, 'balance_minor' => $balance, 'method' => $method,
            'note' => mb_substr($note, 0, 255), 'staff_id' => $staffId, 'created_at' => current_time('mysql', true),
            'ref' => $ref,
        ], ['%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']);
        if (!$ok) {
            throw new DomainException(__('The entry was not saved. Try again.', 'sfm4u-wholesale'));
        }
        return ['id' => (int) $wpdb->insert_id, 'balance_minor' => $balance, 'amount_minor' => $delta];
    }

    /**
     * What a cashier/shopkeeper needs about one customer's khata at this store: balance (positive = customer owes),
     * since when it is owed (date the balance last went from 0 or less to above 0), last payment, recent rows.
     * Notes are left out unless $withNotes (manager views): a cashier sees amounts, not free text.
     */
    public static function dueSummary(int $userId, string $store = 'main', int $rows = 10, bool $withNotes = false): array {
        global $wpdb;
        $all = $wpdb->get_results($wpdb->prepare('SELECT id, kind, amount_minor, balance_minor, method, note, created_at FROM ' . self::khata()
            . ' WHERE user_id=%d AND store=%s ORDER BY id ASC', $userId, $store), ARRAY_A) ?: [];
        $since = null; $lastPay = null; $prev = 0;
        foreach ($all as $r) {
            $b = (int) $r['balance_minor'];
            if ($prev <= 0 && $b > 0) { $since = $r['created_at']; }
            if ($b <= 0) { $since = null; }
            if ($r['kind'] === 'payment') { $lastPay = ['at' => $r['created_at'], 'amount_minor' => -(int) $r['amount_minor'], 'method' => $r['method']]; }
            $prev = $b;
        }
        $balance = $all ? (int) end($all)['balance_minor'] : 0;
        return ['balance_minor' => $balance, 'due_minor' => max(0, $balance), 'advance_minor' => max(0, -$balance),
            'due_since' => $balance > 0 ? $since : null, 'last_payment' => $lastPay,
            'recent' => array_map(function ($r) use ($withNotes) { if (!$withNotes) { unset($r['note']); } return $r; },
                array_reverse(array_slice($all, -$rows)))];
    }

    /** Day-by-day khata history (store local dates): charged, paid, corrections and closing balance per day. */
    public static function dueHistory(int $userId, string $store = 'main', int $days = 365): array {
        global $wpdb;
        $from = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $all = $wpdb->get_results($wpdb->prepare('SELECT kind, amount_minor, balance_minor, created_at FROM ' . self::khata()
            . ' WHERE user_id=%d AND store=%s AND created_at >= %s ORDER BY id ASC', $userId, $store, $from), ARRAY_A) ?: [];
        $out = [];
        foreach ($all as $r) {
            $day = function_exists('get_date_from_gmt') ? get_date_from_gmt($r['created_at'], 'Y-m-d') : substr($r['created_at'], 0, 10);
            $out[$day] ??= ['date' => $day, 'charged_minor' => 0, 'paid_minor' => 0, 'adjusted_minor' => 0, 'closing_minor' => 0, 'entries' => 0];
            $a = (int) $r['amount_minor'];
            if ($r['kind'] === 'charge') { $out[$day]['charged_minor'] += $a; }
            elseif ($r['kind'] === 'payment') { $out[$day]['paid_minor'] += -$a; }
            else { $out[$day]['adjusted_minor'] += $a; }
            $out[$day]['closing_minor'] = (int) $r['balance_minor'];
            $out[$day]['entries']++;
        }
        return array_values(array_reverse($out));
    }

    public static function money(int $minor): string {
        $d = self::decimals();
        $sym = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '৳';
        $neg = $minor < 0;
        $abs = abs($minor);
        $txt = number_format($abs / (10 ** $d), $d);
        return ($neg ? '-' : '') . $sym . ' ' . $txt;
    }

    /** What the card shows as its number: "NID •••• 1234" when linked, else the internal number. */
    public static function displayNumber(array $card): string {
        if (!empty($card['id_last4'])) {
            return ($card['id_type'] === 'brn' ? __('Birth reg.', 'sfm4u-wholesale') : 'NID') . ' •••• ' . $card['id_last4'];
        }
        return trim(chunk_split($card['card_no'], 4, ' '));
    }

    /** Private customer details: managers only (cashiers never see them). */
    public const DETAIL_FIELDS = ['sfm_wr_father', 'sfm_wr_dob', 'sfm_wr_address', 'sfm_wr_shop_name', 'sfm_wr_shop_address', 'sfm_wr_name_en', 'sfm_wr_father_en', 'sfm_wr_business_en', 'sfm_wr_district', 'sfm_wr_name_bn', 'sfm_wr_mother', 'billing_phone'];

    /**
     * Staff levels (owner decision): CEO = site administrator; owner = shop owner (manages every shop);
     * manager = one shop, sees private details and can correct khata; cashier = one shop, no private details.
     */
    public static function shopRole(int $userId = 0): string {
        $userId = $userId ?: get_current_user_id();
        if (!$userId) { return 'none'; }
        if (user_can($userId, 'manage_options')) { return 'ceo'; }
        if (user_can($userId, self::manageCap())) { return 'owner'; }
        if (!user_can($userId, self::CAP)) { return 'none'; }
        return get_user_meta($userId, 'sfm_wr_shop_role', true) === 'manager' ? 'manager' : 'cashier';
    }

    public static function canSeeDetails(): bool {
        return in_array(self::shopRole(), ['ceo', 'owner', 'manager'], true);
    }

    public static function canAdjust(): bool {
        return self::canSeeDetails();
    }

    /** Give a person a staff level in one shop (or remove them with level "none"). */
    public static function setStaff(int $userId, string $store, string $level): void {
        $u = get_userdata($userId);
        if (!$u) { throw new DomainException(__('Staff email or shop not found.', 'sfm4u-wholesale')); }
        if (user_can($userId, self::manageCap())) { throw new DomainException(__('This person is already a shop owner or administrator.', 'sfm4u-wholesale')); }
        if ($level === 'none') {
            $u->remove_role('sfm_cashier');
            delete_user_meta($userId, 'sfm_wr_shop_role');
            delete_user_meta($userId, 'sfm_wr_store');
            return;
        }
        if (!isset(self::stores()[$store])) { throw new DomainException(__('Staff email or shop not found.', 'sfm4u-wholesale')); }
        if (get_role('sfm_cashier')) { $u->add_role('sfm_cashier'); }
        update_user_meta($userId, 'sfm_wr_store', $store);
        update_user_meta($userId, 'sfm_wr_shop_role', $level === 'manager' ? 'manager' : 'cashier');
    }

    public static function details(int $userId): array {
        $out = [];
        foreach (self::DETAIL_FIELDS as $k) { $out[$k] = (string) get_user_meta($userId, $k, true); }
        $out['phone'] = (string) get_user_meta($userId, 'billing_phone', true);
        return $out;
    }

    /** Fields a member may fill or correct for themselves (from their NID). */
    public const SELF_FIELDS = ['sfm_wr_name_bn', 'sfm_wr_name_en', 'sfm_wr_father', 'sfm_wr_father_en', 'sfm_wr_mother', 'sfm_wr_dob', 'sfm_wr_address', 'sfm_wr_district', 'billing_phone'];
    /** First fill + one correction; after that only the shop can change details. */
    public const SELF_EDIT_LIMIT = 2;

    /**
     * Validate and save detail fields. Only keys present in $input and allowed in $allowed are touched.
     * Returns the keys that actually changed, and records them in a short change log.
     */
    public static function saveDetails(int $userId, array $input, array $allowed, string $source): array {
        $clean = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $input) || !is_string($input[$k])) { continue; }
            $v = trim(sanitize_textarea_field(wp_unslash($input[$k])));
            if ($k === 'sfm_wr_district' && $v !== '' && !BdMap::valid($v)) { throw new DomainException(__('Choose a district from the list.', 'sfm4u-wholesale')); }
            if ($k === 'sfm_wr_dob' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) { throw new DomainException(__('Enter the date of birth as YYYY-MM-DD.', 'sfm4u-wholesale')); }
            if ($k === 'billing_phone' && $v !== '') {
                $v = self::digits($v, true);
                if (!preg_match('/^\+?\d{6,15}$/D', $v)) { throw new DomainException(__('Enter a valid mobile number.', 'sfm4u-wholesale')); }
            }
            $clean[$k] = mb_substr($v, 0, 300);
        }
        $changed = [];
        foreach ($clean as $k => $v) {
            if ((string) get_user_meta($userId, $k, true) !== $v) { update_user_meta($userId, $k, $v); $changed[] = $k; }
        }
        if ($changed) { self::logChange($userId, $source, $changed); }
        return $changed;
    }

    /** Bangla digits to ASCII; keep a leading + when $phone. */
    public static function digits(string $s, bool $phone = false): string {
        $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $plus = $phone && strpos(ltrim($s), '+') === 0;
        return ($plus ? '+' : '') . preg_replace('/\D+/', '', $s);
    }

    public static function logChange(int $userId, string $source, array $fields): void {
        $log = get_user_meta($userId, 'sfm_wr_details_log', true);
        $log = is_array($log) ? $log : [];
        $log[] = ['t' => current_time('mysql', true), 'by' => get_current_user_id(), 'src' => $source, 'f' => array_values($fields)];
        update_user_meta($userId, 'sfm_wr_details_log', array_slice($log, -30));
    }

    /** Camera button that reads the NID barcode and fills the form (assets/members.js + zxing). */
    public static function nidScanField(): string {
        return '<p class="sfm-wr-nidscan-wrap"><label class="button">' . esc_html__('Scan NID barcode (camera)', 'sfm4u-wholesale')
            . '<input type="file" accept="image/*" capture="environment" class="sfm-wr-nidscan" style="display:none"'
            . ' data-wait="' . esc_attr__('Reading the barcode…', 'sfm4u-wholesale') . '"'
            . ' data-ok="' . esc_attr__('Filled from the NID barcode. Please check:', 'sfm4u-wholesale') . '"'
            . ' data-none="' . esc_attr__('Barcode read, but no NID data was found.', 'sfm4u-wholesale') . '"'
            . ' data-fail="' . esc_attr__('Could not read the barcode. Take a clear, close photo of the back of the NID.', 'sfm4u-wholesale') . '"'
            . ' data-no-lib="' . esc_attr__('The scanner did not load. Reload the page.', 'sfm4u-wholesale') . '"></label> '
            . '<span class="sfm-wr-nidscan-msg"></span><br><small>' . esc_html__('The barcode gives the English name, date of birth and NID/PIN. Bangla names, parents and address are typed from the card.', 'sfm4u-wholesale') . '</small></p>';
    }

    public static function enqueueScanner(): void {
        wp_enqueue_script('sfm-wr-zxing', plugins_url('assets/zxing.min.js', dirname(__DIR__, 2) . '/sfm4u-wholesale.php'), [], '0.23.0', true);
    }

    public static function selfEditsLeft(int $userId): int {
        return max(0, self::SELF_EDIT_LIMIT - (int) get_user_meta($userId, 'sfm_wr_self_edits', true));
    }

    public static function idInUse(string $hash, int $userId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . self::cards() . ' WHERE id_hash=%s AND user_id<>%d LIMIT 1', $hash, $userId));
    }

    public const SIGNATURE_META = 'sfm_wr_signature';

    /** Accept only a small PNG data URL (from the signature pad or an uploaded image). Returns the clean value or ''. */
    public static function cleanSignature(string $raw): string {
        $raw = trim($raw);
        if (strlen($raw) > 250000 || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#D', $raw, $m)) { return ''; }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 100) { return ''; }
        $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bin) : false;
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG || $info[0] > 1600 || $info[1] > 800) { return ''; }
        return 'data:image/png;base64,' . base64_encode($bin);
    }

    /** Signature pad (finger on phone/tab) + optional image upload. Used by the shop and by the member. */
    public static function signaturePadField(string $existing, bool $removeBox): string {
        return ($existing !== '' ? '<p><img src="' . esc_attr($existing) . '" alt="" style="max-width:240px;max-height:80px;background:#fff;border:1px solid #ddd">'
                . ($removeBox ? ' <label><input type="checkbox" name="sfm_wr_signature_clear" value="1"> ' . esc_html__('Remove', 'sfm4u-wholesale') . '</label>' : '') . '</p>' : '')
            . '<canvas class="sfm-wr-sigpad" width="600" height="200" style="width:100%;max-width:420px;height:140px;background:#fff;border:1px dashed #8c8f94;border-radius:8px;touch-action:none"></canvas><br>'
            . '<button type="button" class="button sfm-wr-sigclear">' . esc_html__('Clear', 'sfm4u-wholesale') . '</button> '
            . '<label class="button">' . esc_html__('Upload signature image', 'sfm4u-wholesale') . '<input type="file" accept="image/*" class="sfm-wr-sigfile" style="display:none"></label>'
            . '<input type="hidden" name="sfm_wr_signature" value="">';
    }

    public static function signature(int $userId): string {
        return self::cleanSignature((string) get_user_meta($userId, self::SIGNATURE_META, true));
    }

    public static function photoUrl(array $card): string {
        if (!empty($card['photo_id'])) {
            $src = wp_get_attachment_image_url((int) $card['photo_id'], 'medium');
            if ($src) { return $src; }
        }
        // No photo yet: a plain grey outline (no Gravatar lookup, nothing sent to a third party).
        return plugins_url('assets/sample-person.jpg', dirname(__DIR__, 2) . '/sfm4u-wholesale.php');
    }

    /** QR payload: a URL so a phone camera opens the verify page; USB/Bluetooth scanners type it into the scan box. */
    public static function qrPayload(array $card): string {
        return add_query_arg('sfm_card', $card['token'], home_url('/'));
    }
}
