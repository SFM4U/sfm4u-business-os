<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

use DomainException;

defined('ABSPATH') || exit;

/**
 * SFM4U Business OS: connect a marketplace (AlgiBazar) seller or customer to their SFM4U member account.
 *
 * Master side (account.sfm4u.com, CardLink role "master"):
 *   /link-start   a connected shop asks for a one-time code for a member. The code is shown only inside the
 *                 member's own card page (and e-mailed to the member's own address if the site can send mail).
 *   /link-finish  the shop sends the code the person typed; on a match it gets the cashier-safe member data.
 *   /plan-request a linked seller asks for the Business or Business Black plan; staff see it under "Plan requests".
 * Shop side (any site with CardLink role "shop"): helpers used by the My Account page and the checkout box.
 *
 * Nobody can link someone else's account by typing their email or mobile: the code only reaches the real member.
 */
final class MemberLink {
    public const META_PENDING = 'sfm_wr_link_pending';
    public const OPT_REQUESTS = 'sfm_wr_plan_requests';
    public const TTL = 900;
    public const MAX_TRIES = 5;
    public const PLANS = ['business', 'black'];

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('sfm4u_member_card_top', [self::class, 'showCodes']);
        add_action('admin_menu', [self::class, 'menu'], 30);
    }

    public static function routes(): void {
        if (CardLink::role() !== 'master') { return; }
        foreach (['/link-start' => 'start', '/link-finish' => 'finish', '/plan-request' => 'planRequest'] as $r => $cb) {
            register_rest_route(CardLink::NS, $r, ['methods' => 'POST', 'callback' => [self::class, $cb], 'permission_callback' => [CardLink::class, 'allowed']]);
        }
    }

    /* ---------------- master ---------------- */

    /** Find a person by member number, email or mobile. 0 when not found or not unique. */
    public static function findUser(string $who): int {
        $who = trim($who);
        if (preg_match('/^\d{7}$/', $who)) { return Members::userByMemberNo($who); }
        if (is_email($who)) { $u = get_user_by('email', $who); return $u ? (int) $u->ID : 0; }
        $phone = Members::digits($who, true);
        if (strlen($phone) >= 10) {
            $f = get_users(['meta_key' => 'billing_phone', 'meta_value' => $phone, 'number' => 2, 'fields' => 'ID']);
            return count($f) === 1 ? (int) $f[0] : 0;
        }
        return 0;
    }

    private static function body(\WP_REST_Request $req): array {
        $d = json_decode((string) $req->get_body(), true);
        return is_array($d) ? $d : [];
    }

    private static function client(\WP_REST_Request $req): array {
        $id = (string) $req->get_header('x_sfm4u_key');
        $c = CardLink::clients();
        return [$id, (string) ($c[$id]['name'] ?? $id)];
    }

    /** Pure: make a pending entry. */
    public static function newPending(string $host, int $now, callable $random): array {
        $code = str_pad((string) $random(0, 999999), 6, '0', STR_PAD_LEFT);
        return ['code' => $code, 'host' => $host, 'exp' => $now + self::TTL, 'tries' => 0];
    }

    /** Pure: check a typed code against a pending entry. Returns [ok, updatedEntryOrNull, reason]. */
    public static function check(?array $p, string $typed, int $now): array {
        if (!$p) { return [false, null, 'no_code']; }
        if ($now > (int) $p['exp']) { return [false, null, 'expired']; }
        if ((int) $p['tries'] >= self::MAX_TRIES) { return [false, null, 'too_many']; }
        if (preg_match('/^\d{6}$/D', $typed) && hash_equals((string) $p['code'], $typed)) { return [true, null, 'ok']; }
        $p['tries'] = (int) $p['tries'] + 1;
        return [false, $p['tries'] >= self::MAX_TRIES ? null : $p, 'wrong'];
    }

    public static function start(\WP_REST_Request $req): \WP_REST_Response {
        $d = self::body($req);
        [$id, $host] = self::client($req);
        $uid = self::findUser((string) ($d['who'] ?? ''));
        // Same answer whether or not the member exists, so a shop cannot test who is a member.
        $answer = new \WP_REST_Response(['ok' => true, 'message' => 'If this is an SFM4U member, a 6-digit code is now waiting in their SFM4U account (My Membership Card page).']);
        if (!$uid || !Members::cardsOf($uid)) { return $answer; }
        $all = get_user_meta($uid, self::META_PENDING, true);
        $all = is_array($all) ? $all : [];
        $p = self::newPending($host, time(), 'random_int');
        $all[$id] = $p;
        update_user_meta($uid, self::META_PENDING, $all);
        $u = get_userdata($uid);
        if ($u && is_email($u->user_email)) {
            wp_mail($u->user_email, 'SFM4U link code / লিংক কোড: ' . $p['code'],
                sprintf("Your SFM4U link code for %s is %s. It works for 15 minutes. If you did not ask for it, ignore this message.\n\n%s-এ আপনার SFM4U অ্যাকাউন্ট যুক্ত করার কোড: %s। ১৫ মিনিট কাজ করবে। আপনি না চাইলে এই মেসেজ বাদ দিন।", $host, $p['code'], $host, $p['code']));
        }
        return $answer;
    }

    public static function finish(\WP_REST_Request $req): \WP_REST_Response {
        $d = self::body($req);
        [$id] = self::client($req);
        $bad = fn (string $m) => new \WP_REST_Response(['ok' => false, 'message' => $m], 422);
        $uid = self::findUser((string) ($d['who'] ?? ''));
        if (!$uid) { return $bad('The code is not correct or has expired. Ask for a new code.'); }
        // One check at a time per member and shop, so a code can be used only once (Codex review 2026-11-21).
        $lock = 'sfm_wr_link_lock_' . $uid . '_' . md5($id);
        $held = (int) get_option($lock, 0);
        if ($held && $held < time() - 30) { delete_option($lock); }
        if (!add_option($lock, time(), '', 'no')) { return $bad('Please try again in a moment.'); }
        try {
            wp_cache_delete($uid, 'user_meta');
            $all = get_user_meta($uid, self::META_PENDING, true);
            $all = is_array($all) ? $all : [];
            [$ok, $next, $why] = self::check($all[$id] ?? null, (string) ($d['code'] ?? ''), time());
            if ($next === null) { unset($all[$id]); } else { $all[$id] = $next; }
            update_user_meta($uid, self::META_PENDING, $all);
        } finally {
            delete_option($lock);
        }
        if (!$ok) { return $bad($why === 'too_many' ? 'Too many wrong codes. Ask for a new code.' : 'The code is not correct or has expired. Ask for a new code.'); }
        $card = Members::bestCardFor($uid) ?: (Members::cardsOf($uid)[0] ?? null);
        if (!$card) { return $bad('This member has no card yet.'); }
        return new \WP_REST_Response(['ok' => true, 'found' => true] + CardLink::publicMember($card));
    }

    public static function planRequest(\WP_REST_Request $req): \WP_REST_Response {
        $d = self::body($req);
        [, $host] = self::client($req);
        $no = (string) ($d['member_no'] ?? '');
        $plan = (string) ($d['plan'] ?? '');
        if (!preg_match('/^\d{7}$/', $no) || !Members::userByMemberNo($no) || !in_array($plan, self::PLANS, true)) {
            return new \WP_REST_Response(['ok' => false, 'message' => 'Member or plan not valid.'], 422);
        }
        $list = self::requests();
        foreach ($list as $r) {
            if ($r['member_no'] === $no && $r['plan'] === $plan && $r['status'] === 'new') { return new \WP_REST_Response(['ok' => true, 'message' => 'Request already received.']); }
        }
        array_unshift($list, ['member_no' => $no, 'plan' => $plan, 'host' => $host, 'store' => mb_substr(sanitize_text_field((string) ($d['store_name'] ?? '')), 0, 120),
            'store_url' => esc_url_raw((string) ($d['store_url'] ?? '')), 'time' => current_time('mysql'), 'status' => 'new']);
        update_option(self::OPT_REQUESTS, array_slice($list, 0, 500), false);
        return new \WP_REST_Response(['ok' => true, 'message' => 'Request received. SFM4U staff will contact you.']);
    }

    public static function requests(): array {
        $l = get_option(self::OPT_REQUESTS, []);
        return is_array($l) ? $l : [];
    }

    /** Member's own card page: show waiting link codes. */
    public static function showCodes(int $uid): void {
        $all = get_user_meta($uid, self::META_PENDING, true);
        if (!is_array($all) || !$all) { return; }
        $now = time();
        $live = array_filter($all, static fn ($p) => is_array($p) && (int) $p['exp'] >= $now && (int) $p['tries'] < self::MAX_TRIES);
        if (count($live) !== count($all)) { update_user_meta($uid, self::META_PENDING, $live); }
        foreach ($live as $p) {
            echo '<div class="sfm-link-code" style="border:2px solid #0a7d3b;border-radius:10px;padding:12px;margin:0 0 14px;background:#f2fbf5">'
                . '<p style="margin:0 0 6px">' . esc_html(sprintf(__('%s wants to connect to your SFM4U account. Type this code there:', 'sfm4u-wholesale'), $p['host'])) . '</p>'
                . '<p style="margin:0 0 6px">' . esc_html(sprintf('%s আপনার SFM4U অ্যাকাউন্টের সাথে যুক্ত হতে চায়। সেখানে এই কোডটি লিখুন:', $p['host'])) . '</p>'
                . '<p style="font-size:28px;letter-spacing:6px;font-weight:700;margin:4px 0">' . esc_html($p['code']) . '</p>'
                . '<p style="margin:0;font-size:13px">' . esc_html__('Works for 15 minutes. If you did not ask for this, do not share the code.', 'sfm4u-wholesale') . '</p></div>';
        }
    }

    public static function menu(): void {
        if (CardLink::role() !== 'master') { return; }
        add_submenu_page('sfm-wr-scan', __('Plan requests', 'sfm4u-wholesale'), __('Plan requests', 'sfm4u-wholesale'), Members::manageCap(), 'sfm-wr-plans', [self::class, 'plansPage']);
    }

    public static function plansPage(): void {
        if (!current_user_can(Members::manageCap())) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $list = self::requests();
        if (isset($_POST['sfm_plan_done']) && check_admin_referer('sfm_plan_done')) {
            $i = (int) $_POST['sfm_plan_done'];
            if (isset($list[$i])) { $list[$i]['status'] = 'done'; $list[$i]['done_by'] = get_current_user_id(); update_option(self::OPT_REQUESTS, $list, false); }
        }
        echo '<div class="wrap"><h1>' . esc_html__('Plan requests (Business / Business Black)', 'sfm4u-wholesale') . '</h1>'
            . '<p>' . esc_html__('Sellers who linked their SFM4U account on a marketplace and asked for a plan. Check the person, issue the card under Member cards, then press Done.', 'sfm4u-wholesale') . '</p>';
        if (!$list) { echo '<p>' . esc_html__('No requests yet.', 'sfm4u-wholesale') . '</p></div>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Member no', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Plan', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Store', 'sfm4u-wholesale') . '</th><th>' . esc_html__('From', 'sfm4u-wholesale') . '</th><th></th></tr></thead><tbody>';
        foreach ($list as $i => $r) {
            echo '<tr><td>' . esc_html($r['time']) . '</td><td>' . esc_html($r['member_no']) . '</td><td>' . esc_html(Members::tierLabel($r['plan'])) . '</td><td>'
                . ($r['store_url'] ? '<a href="' . esc_url($r['store_url']) . '" target="_blank" rel="noopener">' . esc_html($r['store'] ?: $r['store_url']) . '</a>' : esc_html($r['store'])) . '</td><td>' . esc_html($r['host']) . '</td><td>';
            if ($r['status'] === 'new') {
                echo '<form method="post">';
                wp_nonce_field('sfm_plan_done');
                echo '<button class="button" name="sfm_plan_done" value="' . (int) $i . '">' . esc_html__('Done', 'sfm4u-wholesale') . '</button></form>';
            } else { echo esc_html__('Done', 'sfm4u-wholesale'); }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ---------------- shop side ---------------- */

    public static function shopStart(string $who): string {
        $d = CardLink::post('/link-start', ['who' => $who]);
        return (string) ($d['message'] ?? '');
    }

    /** Returns the cashier-safe member on success; throws DomainException otherwise. */
    public static function shopFinish(string $who, string $code): array {
        return CardLink::post('/link-finish', ['who' => $who, 'code' => $code]);
    }

    /** Save the link on a local user (the marketplace seller or customer). */
    public static function saveLink(int $uid, array $m): void {
        if (!preg_match('/^\d{7}$/', (string) ($m['member_no'] ?? ''))) { throw new DomainException(__('The SFM4U account site did not give a member number.', 'sfm4u-wholesale')); }
        $other = get_users(['meta_key' => 'sfm_wr_member_no', 'meta_value' => $m['member_no'], 'number' => 2, 'fields' => 'ID', 'exclude' => [$uid]]);
        if ($other) { throw new DomainException(__('This SFM4U member is already linked to another account on this site. Contact SFM4U.', 'sfm4u-wholesale')); }
        update_user_meta($uid, 'sfm_wr_member_no', (string) $m['member_no']);
        update_user_meta($uid, 'sfm_wr_link_tier', in_array($m['tier'] ?? '', Members::TIERS, true) ? (string) $m['tier'] : 'regular');
        update_user_meta($uid, 'sfm_wr_link_status', sanitize_key((string) ($m['status'] ?? 'active')));
        update_user_meta($uid, 'sfm_wr_link_name', sanitize_text_field((string) ($m['name'] ?? '')));
        update_user_meta($uid, 'sfm_wr_link_time', current_time('mysql'));
    }

    public static function unlink(int $uid): void {
        foreach (['sfm_wr_member_no', 'sfm_wr_link_tier', 'sfm_wr_link_status', 'sfm_wr_link_name', 'sfm_wr_link_time'] as $k) { delete_user_meta($uid, $k); }
    }

    public static function linked(int $uid): array {
        $no = (string) get_user_meta($uid, 'sfm_wr_member_no', true);
        if ($no === '') { return []; }
        return ['member_no' => $no, 'tier' => (string) get_user_meta($uid, 'sfm_wr_link_tier', true) ?: 'regular',
            'status' => (string) get_user_meta($uid, 'sfm_wr_link_status', true) ?: 'active', 'name' => (string) get_user_meta($uid, 'sfm_wr_link_name', true)];
    }

    /** New person: ask the master to create the account (self sign-up, never takes over an existing member). */
    public static function shopJoin(int $uid): array {
        $u = get_userdata($uid);
        if (!$u) { throw new DomainException(__('Customer not found.', 'sfm4u-wholesale')); }
        $phone = (string) get_user_meta($uid, 'billing_phone', true);
        $name = trim((string) get_user_meta($uid, 'first_name', true) . ' ' . (string) get_user_meta($uid, 'last_name', true)) ?: $u->display_name;
        return CardLink::post('/members', ['email' => (string) $u->user_email, 'phone' => $phone, 'name' => $name, 'match' => false]);
    }

    public static function shopPlanRequest(int $uid, string $plan, string $storeName, string $storeUrl): string {
        $l = self::linked($uid);
        if (!$l) { throw new DomainException(__('Link your SFM4U account first.', 'sfm4u-wholesale')); }
        if (!in_array($plan, self::PLANS, true)) { throw new DomainException(__('Choose a plan.', 'sfm4u-wholesale')); }
        $d = CardLink::post('/plan-request', ['member_no' => $l['member_no'], 'plan' => $plan, 'store_name' => $storeName, 'store_url' => $storeUrl]);
        return (string) ($d['message'] ?? '');
    }
}
