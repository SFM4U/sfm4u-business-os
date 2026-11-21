<?php
namespace SFM4U\Wholesale\Members;

use DomainException;

/**
 * Points and the member wallet.
 *
 * POINTS (ready to use):
 *  - Owner sets, per shop: spend X taka -> 1 point; 1 point = Y taka of reward.
 *  - Company-wide program has its own rate.
 *  - Each member chooses: "shop" (points kept separately per shop) or "company"
 *    (all purchases in any SFM4U shop go into one company reward balance).
 *  - Purchases at the counter (cashier) and completed online orders earn points.
 *  - Append-only ledger, like the khata.
 *
 * WALLET (built, switched OFF):
 *  - Holding customers' money needs a Bangladesh Bank PSP licence or a licensed partner.
 *  - Nothing in the wallet works until BOTH the owner turns it on in settings AND
 *    wp-config defines SFM4U_WALLET_ENABLED true. When turned on, members see a notice.
 */
final class Rewards {
    public const POINTS_TABLE = 'sfm_wr_points';
    public const WALLET_TABLE = 'sfm_wr_wallet';
    public const OPT = 'sfm_wr_rewards';

    public static function points(): string { global $wpdb; return $wpdb->prefix . self::POINTS_TABLE; }
    public static function wallet(): string { global $wpdb; return $wpdb->prefix . self::WALLET_TABLE; }

    public static function register(): void {
        add_action('woocommerce_order_status_completed', [self::class, 'onlineOrder'], 20, 1);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::points() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            pool varchar(64) NOT NULL,
            kind varchar(16) NOT NULL,
            points bigint(20) NOT NULL,
            balance bigint(20) NOT NULL,
            purchase_minor bigint(20) NOT NULL DEFAULT 0,
            ref varchar(64) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_pool (user_id,pool,id),
            KEY ref (ref)
        ) $c;");
        dbDelta('CREATE TABLE ' . self::wallet() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            kind varchar(16) NOT NULL,
            amount_minor bigint(20) NOT NULL,
            balance_minor bigint(20) NOT NULL,
            method varchar(16) NOT NULL DEFAULT '',
            ref varchar(64) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id,id)
        ) $c;");
    }

    /* ---------- settings ---------- */

    public static function settings(): array {
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        return $s + ['points_on' => 'no', 'rates' => [], 'company' => ['per' => 100, 'value' => 1], 'wallet_on' => 'no'];
    }

    /** Rate for a pool: ['per' => taka per point, 'value' => taka per point when redeemed]. Pool = shop code or 'company'. */
    public static function rate(string $pool): array {
        $s = self::settings();
        $r = $pool === 'company' ? $s['company'] : ($s['rates'][$pool] ?? $s['company']);
        return ['per' => max(1, (int) ($r['per'] ?? 100)), 'value' => max(0, (float) ($r['value'] ?? 1))];
    }

    public static function pointsOn(): bool { return self::settings()['points_on'] === 'yes'; }

    public static function walletOn(): bool {
        return self::settings()['wallet_on'] === 'yes' && defined('SFM4U_WALLET_ENABLED') && SFM4U_WALLET_ENABLED === true;
    }

    /** Member's choice: 'shop' (separate per shop) or 'company' (one company reward). Default shop. */
    public static function mode(int $userId): string {
        return get_user_meta($userId, 'sfm_wr_points_mode', true) === 'company' ? 'company' : 'shop';
    }

    public static function poolFor(int $userId, string $store): string {
        return self::mode($userId) === 'company' ? 'company' : $store;
    }

    /* ---------- pure (unit tested) ---------- */

    /** Whole points earned for a purchase. minor = purchase in minor units; per = taka per point. */
    public static function earned(int $minor, int $decimals, int $per): int {
        if ($minor <= 0 || $per <= 0) { return 0; }
        return intdiv($minor, $per * (10 ** $decimals));
    }

    /** Reward value in minor units for a number of points. */
    public static function value(int $points, float $valuePerPoint, int $decimals): int {
        return (int) floor($points * $valuePerPoint * (10 ** $decimals) + 1e-9);
    }

    /* ---------- points ledger ---------- */

    public static function balance(int $userId, string $pool): int {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT balance FROM ' . self::points() . ' WHERE user_id=%d AND pool=%s ORDER BY id DESC LIMIT 1', $userId, $pool));
        return $v === null ? 0 : (int) $v;
    }

    public static function balances(int $userId): array {
        global $wpdb;
        $pools = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT pool FROM ' . self::points() . ' WHERE user_id=%d', $userId)) ?: [];
        $out = [];
        foreach ($pools as $p) { $out[(string) $p] = self::balance($userId, (string) $p); }
        return $out;
    }

    public static function history(int $userId, int $limit = 15, string $pool = ''): array {
        global $wpdb;
        if ($pool !== '') {
            return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::points() . ' WHERE user_id=%d AND pool=%s ORDER BY id DESC LIMIT %d', $userId, $pool, $limit), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::points() . ' WHERE user_id=%d ORDER BY id DESC LIMIT %d', $userId, $limit), ARRAY_A) ?: [];
    }

    private static function add(int $userId, string $pool, string $kind, int $points, int $purchaseMinor, string $ref, string $note, int $staff): array {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $last = $wpdb->get_var($wpdb->prepare('SELECT balance FROM ' . self::points() . ' WHERE user_id=%d AND pool=%s ORDER BY id DESC LIMIT 1' . Members::lockSql(), $userId, $pool));
        $balance = ($last === null ? 0 : (int) $last) + $points;
        if ($balance < 0) {
            $wpdb->query('ROLLBACK');
            throw new DomainException(__('Not enough points.', 'sfm4u-wholesale'));
        }
        $ok = $wpdb->insert(self::points(), ['user_id' => $userId, 'pool' => $pool, 'kind' => $kind, 'points' => $points, 'balance' => $balance,
            'purchase_minor' => $purchaseMinor, 'ref' => $ref, 'note' => mb_substr($note, 0, 255), 'staff_id' => $staff, 'created_at' => current_time('mysql', true)],
            ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s']);
        if (!$ok) { $wpdb->query('ROLLBACK'); throw new DomainException(__('The entry was not saved. Try again.', 'sfm4u-wholesale')); }
        $rowId = (int) $wpdb->insert_id;
        $wpdb->query('COMMIT');
        return ['points' => $points, 'balance' => $balance, 'id' => $rowId];
    }

    /** A purchase at a shop counter (or online). Records it in the member's history and adds points if on. */
    public static function purchase(int $userId, string $store, int $minor, string $ref, string $note, int $staff): array {
        $pool = self::poolFor($userId, $store);
        $pts = self::pointsOn() ? self::earned($minor, Members::decimals(), self::rate($pool)['per']) : 0;
        /* translators: %s: shop code */
        $note = trim($note . ' ' . sprintf(__('[shop: %s]', 'sfm4u-wholesale'), $store));
        return self::add($userId, $pool, 'earn', $pts, $minor, $ref, $note, $staff) + ['pool' => $pool];
    }

    public static function redeem(int $userId, string $pool, int $points, string $note, int $staff): array {
        if ($points <= 0) { throw new DomainException(__('Enter how many points to use.', 'sfm4u-wholesale')); }
        return self::add($userId, $pool, 'redeem', -$points, 0, '', $note, $staff);
    }

    /** Completed online order: one entry per order (ref = order id), never twice. */
    public static function onlineOrder($orderId): void {
        global $wpdb;
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (!$order || !$order->get_customer_id()) { return; }
        $ref = 'order:' . $order->get_id();
        if ($wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . self::points() . ' WHERE ref=%s LIMIT 1', $ref))) { return; }
        $minor = (int) round((float) $order->get_total() * (10 ** Members::decimals()));
        try { self::purchase((int) $order->get_customer_id(), 'online', $minor, $ref, __('Online order', 'sfm4u-wholesale'), 0); } catch (DomainException $e) { /* logged by caller */ }
    }

    /* ---------- wallet (OFF until licensed) ---------- */

    public static function walletBalance(int $userId): int {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT balance_minor FROM ' . self::wallet() . ' WHERE user_id=%d ORDER BY id DESC LIMIT 1', $userId));
        return $v === null ? 0 : (int) $v;
    }

    /** kind: deposit (+) or spend (-). Refuses everything while the wallet is off. */
    public static function walletEntry(int $userId, string $kind, int $minor, string $method, string $ref, string $note, int $staff): array {
        global $wpdb;
        if (!self::walletOn()) { throw new DomainException(__('The wallet is not active yet.', 'sfm4u-wholesale')); }
        if (!in_array($kind, ['deposit', 'spend'], true) || $minor <= 0) { throw new DomainException('Invalid wallet entry.'); }
        $delta = $kind === 'deposit' ? $minor : -$minor;
        $wpdb->query('START TRANSACTION');
        $last = $wpdb->get_var($wpdb->prepare('SELECT balance_minor FROM ' . self::wallet() . ' WHERE user_id=%d ORDER BY id DESC LIMIT 1' . Members::lockSql(), $userId));
        $bal = ($last === null ? 0 : (int) $last) + $delta;
        if ($bal < 0) { $wpdb->query('ROLLBACK'); throw new DomainException(__('Not enough money in the wallet.', 'sfm4u-wholesale')); }
        $ok = $wpdb->insert(self::wallet(), ['user_id' => $userId, 'kind' => $kind, 'amount_minor' => $delta, 'balance_minor' => $bal, 'method' => $method,
            'ref' => $ref, 'note' => mb_substr($note, 0, 255), 'staff_id' => $staff, 'created_at' => current_time('mysql', true)],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s']);
        if (!$ok) { $wpdb->query('ROLLBACK'); throw new DomainException(__('The entry was not saved. Try again.', 'sfm4u-wholesale')); }
        $wpdb->query('COMMIT');
        return ['balance_minor' => $bal];
    }
}
