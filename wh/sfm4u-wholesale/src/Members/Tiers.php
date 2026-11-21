<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

use DomainException;

defined('ABSPATH') || exit;

/**
 * Card plans (owner decision 2026-11-20):
 *  - regular  : free, ONLINE card only (no plastic). Points at every SFM4U shop, coupons online.
 *  - premium  : free, printed plastic card for wholesale-shop customers. Points + a gift when one purchase
 *               reaches the gift amount (default ৳1,000; gift text set by the owner).
 *  - business : shopkeepers / vendors with a store on AlgiBazar.com.
 *  - black    : "Business Black", paid monthly (default ৳1,000 / 30 days). While paid, what the member buys
 *               from our wholesale shop is added to their own AlgiBazar store stock automatically (StockFeed).
 * Payments are recorded by staff (cash or bKash reference). No online payment is taken here.
 */
final class Tiers {
    public const OPT = 'sfm_wr_tier_rules';
    public const BLACK_UNTIL = 'sfm_wr_black_until';
    public const BLACK_LOG = 'sfm_wr_black_payments';
    public const GIFTS = 'sfm_wr_gifts';

    public static function rules(): array {
        $r = get_option(self::OPT, []);
        $r = is_array($r) ? $r : [];
        return array_merge(['gift_min' => 1000, 'gift_text' => 'বিশেষ উপহার', 'black_fee' => 1000, 'black_days' => 30], $r);
    }

    public static function saveRules(array $in): void {
        $r = self::rules();
        $r['gift_min'] = max(0, (int) ($in['gift_min'] ?? $r['gift_min']));
        $r['gift_text'] = mb_substr(trim(sanitize_text_field((string) ($in['gift_text'] ?? $r['gift_text']))), 0, 120) ?: 'বিশেষ উপহার';
        $r['black_fee'] = max(0, (int) ($in['black_fee'] ?? $r['black_fee']));
        $r['black_days'] = min(366, max(1, (int) ($in['black_days'] ?? $r['black_days'])));
        update_option(self::OPT, $r, false);
    }

    /** Regular cards are online only: nothing is printed for them. */
    public static function isOnlineOnly(string $tier): bool { return $tier === 'regular'; }

    /* ---------------- Premium gift ---------------- */

    /**
     * After a purchase: if the member has an active Premium card and the purchase reaches the gift amount,
     * log a gift to give and return its text ('' when no gift).
     */
    public static function checkGift(int $userId, string $store, int $minor, string $ref): string {
        $card = Members::cardFor($userId, 'premium', $store) ?: Members::cardFor($userId, 'premium', 'main');
        if (!$card || $card['status'] !== 'active') { return ''; }
        $r = self::rules();
        if ($r['gift_min'] <= 0 || $minor < $r['gift_min'] * (10 ** Members::decimals())) { return ''; }
        $g = get_user_meta($userId, self::GIFTS, true);
        $g = is_array($g) ? $g : [];
        $g[] = ['at' => current_time('mysql', true), 'ref' => $ref, 'amount_minor' => $minor, 'gift' => $r['gift_text'], 'store' => $store, 'given' => ''];
        update_user_meta($userId, self::GIFTS, array_slice($g, -100));
        return (string) $r['gift_text'];
    }

    public static function gifts(int $userId): array {
        $g = get_user_meta($userId, self::GIFTS, true);
        return is_array($g) ? $g : [];
    }

    public static function markGiftGiven(int $userId, int $index, int $staff): void {
        $g = self::gifts($userId);
        if (!isset($g[$index])) { throw new DomainException(__('Gift not found.', 'sfm4u-wholesale')); }
        if ($g[$index]['given'] !== '') { return; }
        $g[$index]['given'] = current_time('mysql', true);
        $g[$index]['by'] = $staff;
        update_user_meta($userId, self::GIFTS, $g);
    }

    /* ---------------- Business Black ---------------- */

    public static function blackUntil(int $userId): string { return (string) get_user_meta($userId, self::BLACK_UNTIL, true); }

    /** Paid and not expired, with an active Business Black card. */
    public static function blackActive(int $userId, ?int $now = null): bool {
        $until = self::blackUntil($userId);
        if ($until === '' || strtotime($until . ' 23:59:59 UTC') < ($now ?? time())) { return false; }
        foreach (Members::cardsOf($userId) as $c) { if ($c['tier'] === 'black' && $c['status'] === 'active') { return true; } }
        return false;
    }

    /** Staff records a monthly payment. Extends from the later of today or the current end date. */
    public static function recordBlackPayment(int $userId, int $months, string $method, string $ref, int $staff, ?int $now = null): string {
        $months = min(12, max(1, $months));
        if (!in_array($method, ['cash', 'bkash'], true)) { throw new DomainException(__('Choose cash or bKash.', 'sfm4u-wholesale')); }
        if ($method === 'bkash' && trim($ref) === '') { throw new DomainException(__('Write the bKash transaction ID.', 'sfm4u-wholesale')); }
        $has = false;
        foreach (Members::cardsOf($userId) as $c) { if ($c['tier'] === 'black') { $has = true; } }
        if (!$has) { throw new DomainException(__('This customer has no Business Black card.', 'sfm4u-wholesale')); }
        $now = $now ?? time();
        $r = self::rules();
        $cur = self::blackUntil($userId);
        $from = ($cur !== '' && strtotime($cur . ' 00:00:00 UTC') > $now) ? strtotime($cur . ' 00:00:00 UTC') : $now;
        $until = gmdate('Y-m-d', $from + $months * (int) $r['black_days'] * DAY_IN_SECONDS);
        update_user_meta($userId, self::BLACK_UNTIL, $until);
        $log = get_user_meta($userId, self::BLACK_LOG, true);
        $log = is_array($log) ? $log : [];
        $log[] = ['at' => gmdate('Y-m-d H:i:s', $now), 'months' => $months, 'fee' => (int) $r['black_fee'] * $months, 'method' => $method,
            'ref' => mb_substr(sanitize_text_field($ref), 0, 60), 'until' => $until, 'by' => $staff];
        update_user_meta($userId, self::BLACK_LOG, array_slice($log, -60));
        return $until;
    }

    public static function blackLog(int $userId): array {
        $l = get_user_meta($userId, self::BLACK_LOG, true);
        return is_array($l) ? $l : [];
    }
}
