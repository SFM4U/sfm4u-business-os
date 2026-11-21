<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

use DomainException;

defined('ABSPATH') || exit;

/**
 * SFM4U account rules (living doc "SFM4U ACCOUNT AND CARD RULES", Skill 227): one person = one account for life.
 * - NID (10/13/17 digits) or birth registration (17) + date of birth, given with consent; stored encrypted + keyed hash.
 * - 90 days from the account opening date (or from the day this rule went live) to add it; then the account is on hold.
 * - The same NID/BRN on a second account: refused with a safe message; if found later, the NEWER account is blocked and
 *   the account manager + administrator get an alert.
 * - Government check is manual: verified / problem (warning -> hold until a deadline -> blocked).
 * - The administrator can hold/block/unblock and correct an NID/BRN (reason + log).
 * The account site is the master; linked shops read the state from the card answer (account_state, id_days_left).
 */
final class Identity {
    public const GRACE_DAYS = 90;
    public const OPT_START = 'sfm_wr_identity_rule_start';
    public const OPT_ALERTS = 'sfm_wr_duplicate_alerts';
    public const CAP_MANAGER = 'sfm4u_account_manager';   // open accounts, search, record checks; never sees the full NID/BRN
    public const CAP_VIEW = 'sfm4u_view_identity';        // may see the full NID/BRN (administrator always may)
    public const SAFE_DUPLICATE = 'This NID or birth registration already has an SFM4U account. Log in with your earlier email, or contact the SFM4U admin / helpline.';
    private const M_HASH = 'sfm_wr_id_hash';
    private const M_TYPE = 'sfm_wr_id_type';
    private const M_LAST4 = 'sfm_wr_id_last4';
    private const M_ENC = 'sfm_wr_id_enc';
    private const M_DOB = 'sfm_wr_dob';
    private const M_STATE = 'sfm_wr_account_state';       // '' (normal) | hold | blocked
    private const M_STATE_REASON = 'sfm_wr_account_state_reason';
    private const M_VERIFIED = 'sfm_wr_id_verified';      // unverified | verified | problem
    private const M_DEADLINE = 'sfm_wr_id_problem_deadline';
    private const M_LOG = 'sfm_wr_identity_log';

    public static function register(): void {
        add_action('admin_init', [self::class, 'ruleStart'], 6);
        add_filter('user_has_cap', [self::class, 'grantCaps'], 10, 4);
        add_action('admin_menu', [self::class, 'menu'], 40);
        add_action('sfm4u_member_card_top', [self::class, 'memberBox'], 5);
    }

    public static function menu(): void {
        add_submenu_page('sfm-wr-scan', __('SFM4U accounts', 'sfm4u-wholesale'), __('SFM4U accounts', 'sfm4u-wholesale'), self::CAP_MANAGER, 'sfm-wr-accounts', [self::class, 'adminPage']);
    }

    private static function p(string $k): string {
        return isset($_POST[$k]) && is_string($_POST[$k]) ? trim(sanitize_text_field(wp_unslash($_POST[$k]))) : '';
    }

    private static function stateLabel(array $a): string {
        switch ($a['state']) {
            case 'grace': return sprintf(__('NID missing — %d days left', 'sfm4u-wholesale'), (int) $a['id_days_left']);
            case 'hold': return $a['reason'] === 'nid_missing' ? __('On hold: NID / birth registration missing', 'sfm4u-wholesale') : __('Account on hold', 'sfm4u-wholesale');
            case 'blocked': return __('Account blocked', 'sfm4u-wholesale');
            default: return __('Active', 'sfm4u-wholesale');
        }
    }

    /** Member's own page: days left, problem warning, and the form to add the NID/BRN + date of birth (with consent). */
    public static function memberBox(int $uid): void {
        $msg = '';
        if (isset($_POST['sfm_wr_identity_self']) && wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sfm_wr_identity_self_' . $uid)) {
            try {
                self::setIdentity($uid, self::p('id_type'), self::p('id_number'), self::p('dob'), $uid, self::p('consent') === 'yes');
                $msg = '<p style="color:#008a20">' . esc_html__('Thank you. Your NID / birth registration is saved.', 'sfm4u-wholesale') . '</p>';
            } catch (DomainException $e) {
                $msg = '<p style="color:#b32d2e">' . esc_html($e->getMessage()) . '</p>';
            }
        }
        $a = self::accountState($uid);
        if ($a['state'] === 'ok' && $a['id_verified'] !== 'problem' && $msg === '') { return; }
        echo '<div class="sfm-identity-box" style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin:0 0 16px">';
        echo '<p><strong>' . esc_html(self::stateLabel($a)) . '</strong></p>' . $msg;
        if ($a['id_verified'] === 'problem') {
            echo '<p>' . esc_html__('Your account has an NID problem. If you have a corrected NID or birth registration, give it at an SFM4U shop or below; otherwise the account will be blocked.', 'sfm4u-wholesale') . '</p>';
        }
        if (!self::hasIdentity($uid)) {
            echo '<form method="post">';
            wp_nonce_field('sfm_wr_identity_self_' . $uid);
            echo '<input type="hidden" name="sfm_wr_identity_self" value="1"><p><label><input type="radio" name="id_type" value="nid" checked> NID</label> '
                . '<label><input type="radio" name="id_type" value="brn"> ' . esc_html__('Birth registration', 'sfm4u-wholesale') . '</label></p>'
                . '<p><label>' . esc_html__('Number', 'sfm4u-wholesale') . '<br><input name="id_number" inputmode="numeric" autocomplete="off" required></label></p>'
                . '<p><label>' . esc_html__('Date of birth', 'sfm4u-wholesale') . '<br><input type="date" name="dob" required></label></p>'
                . '<p><label><input type="checkbox" name="consent" value="yes" required> ' . esc_html__('I agree that SFM4U keeps my NID / birth registration (encrypted) only to make sure one person has one account. It is never printed on the card.', 'sfm4u-wholesale') . '</label></p>'
                . '<p><button type="submit" class="button">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        }
        echo '</div>';
    }

    /** Account manager / administrator page: search, add/correct identity, record the government check, hold/block, duplicates. */
    public static function adminPage(): void {
        if (!current_user_can(self::CAP_MANAGER)) { wp_die(esc_html__('You cannot manage accounts.', 'sfm4u-wholesale')); }
        $me = get_current_user_id();
        echo '<div class="wrap"><h1>' . esc_html__('SFM4U accounts', 'sfm4u-wholesale') . '</h1>';
        $note = static function (string $t, string $type = 'success'): void { echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($t) . '</p></div>'; };
        $uid = absint($_POST['uid'] ?? ($_GET['uid'] ?? 0));
        try {
            if (isset($_POST['sfm_wr_acc_action'])) {
                check_admin_referer('sfm_wr_accounts');
                $act = self::p('sfm_wr_acc_action');
                if ($act === 'identity') { self::setIdentity($uid, self::p('id_type'), self::p('id_number'), self::p('dob'), $me, self::p('consent') === 'yes', self::p('reason')); $note(__('Saved.', 'sfm4u-wholesale')); }
                elseif ($act === 'check') { self::recordCheck($uid, self::p('result'), $me, absint($_POST['days'] ?? 30), self::p('note')); $note(__('Saved.', 'sfm4u-wholesale')); }
                elseif ($act === 'state') { self::setState($uid, self::p('state'), self::p('reason'), $me); $note(__('Saved.', 'sfm4u-wholesale')); }
                elseif ($act === 'open') {
                    $idn = self::p('id_number') !== '' ? ['type' => self::p('id_type'), 'number' => self::p('id_number'), 'dob' => self::p('dob'), 'consent' => self::p('consent') === 'yes'] : [];
                    $uid = self::openAccount(self::p('email'), self::p('name'), $me, absint($_POST['number'] ?? 0), $idn);
                    $note(sprintf(__('Account opened: member %s', 'sfm4u-wholesale'), Members::memberNo($uid)));
                }
                elseif ($act === 'services' && current_user_can('manage_options')) {
                    $sv = array_values(array_intersect(array_keys(CardView::SERVICES), array_map('sanitize_key', (array) ($_POST['services'] ?? []))));
                    update_user_meta($uid, CardView::SERVICES_META, $sv);
                    self::log($uid, $me, 'card services: ' . (implode(',', $sv) ?: 'none'));
                    $note(__('Saved.', 'sfm4u-wholesale'));
                }
                elseif ($act === 'scan') { $note(sprintf(__('%d new duplicate alerts.', 'sfm4u-wholesale'), self::scanDuplicates())); }
            }
        } catch (DomainException $e) {
            $note($e->getMessage(), 'error');
        }
        $found = [];
        if (isset($_POST['sfm_wr_acc_search'])) {
            check_admin_referer('sfm_wr_accounts');
            $found = self::search(self::p('member_no'), self::p('dob'), self::p('name'), self::p('nid'), self::p('brn'));
            if (!$found) { $note(__('No account found. If the person says they have one, it may be a duplicate: tell the administrator.', 'sfm4u-wholesale'), 'warning'); }
        } elseif ($uid) {
            $found = [$uid];
        }
        echo '<h2>' . esc_html__('Search (member number → date of birth → NID → birth registration)', 'sfm4u-wholesale') . '</h2><form method="post">';
        wp_nonce_field('sfm_wr_accounts');
        echo '<input type="hidden" name="sfm_wr_acc_search" value="1"><p>'
            . '<input name="member_no" placeholder="' . esc_attr__('Member number', 'sfm4u-wholesale') . '" style="width:120px"> '
            . '<input type="date" name="dob" title="' . esc_attr__('Date of birth', 'sfm4u-wholesale') . '"> '
            . '<input name="name" placeholder="' . esc_attr__('Name', 'sfm4u-wholesale') . '" style="width:140px"> '
            . '<input name="nid" placeholder="NID" autocomplete="off" style="width:150px"> '
            . '<input name="brn" placeholder="' . esc_attr__('Birth registration', 'sfm4u-wholesale') . '" autocomplete="off" style="width:170px"> '
            . '<button class="button button-primary">' . esc_html__('Search', 'sfm4u-wholesale') . '</button></p></form>';
        foreach ($found as $id) {
            $u = get_userdata($id);
            if (!$u) { continue; }
            $a = self::accountState($id);
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px;margin:12px 0"><h3>' . esc_html(Members::memberNo($id) . ' · ' . $u->display_name) . '</h3><p>'
                . esc_html(self::stateLabel($a)) . ' · ' . esc_html__('Check', 'sfm4u-wholesale') . ': ' . esc_html($a['id_verified'])
                . ' · ' . esc_html(self::shownId($id, $me) ?: __('No NID / birth registration', 'sfm4u-wholesale'))
                . (current_user_can(self::CAP_VIEW) && self::dob($id) !== '' ? ' · ' . esc_html__('Date of birth', 'sfm4u-wholesale') . ' ' . esc_html(self::dob($id)) : '') . '</p>';
            $form = static function (string $act, string $inner) use ($id): string {
                ob_start(); wp_nonce_field('sfm_wr_accounts'); $n = (string) ob_get_clean();
                return '<form method="post" style="margin:6px 0">' . $n . '<input type="hidden" name="uid" value="' . (int) $id . '"><input type="hidden" name="sfm_wr_acc_action" value="' . esc_attr($act) . '">' . $inner . '</form>';
            };
            echo $form('identity', '<select name="id_type"><option value="nid">NID</option><option value="brn">' . esc_html__('Birth registration', 'sfm4u-wholesale') . '</option></select> <input name="id_number" autocomplete="off" placeholder="' . esc_attr__('Number', 'sfm4u-wholesale') . '"> <input type="date" name="dob"> <input name="reason" placeholder="' . esc_attr__('Reason (for a correction)', 'sfm4u-wholesale') . '"> <label><input type="checkbox" name="consent" value="yes"> ' . esc_html__('Customer agreed (signed consent kept)', 'sfm4u-wholesale') . '</label> <button class="button">' . esc_html__('Save NID / birth registration', 'sfm4u-wholesale') . '</button>');
            echo $form('check', esc_html__('Government check:', 'sfm4u-wholesale') . ' <select name="result"><option value="verified">' . esc_html__('verified', 'sfm4u-wholesale') . '</option><option value="problem">' . esc_html__('problem (warn, hold)', 'sfm4u-wholesale') . '</option></select> ' . esc_html__('deadline days', 'sfm4u-wholesale') . ' <input type="number" name="days" value="30" min="1" max="365" style="width:70px"> <input name="note" placeholder="' . esc_attr__('Note', 'sfm4u-wholesale') . '"> <button class="button">' . esc_html__('Save check', 'sfm4u-wholesale') . '</button>');
            if (current_user_can('manage_options')) {
                echo $form('state', '<select name="state"><option value="">' . esc_html__('normal', 'sfm4u-wholesale') . '</option><option value="hold">' . esc_html__('hold', 'sfm4u-wholesale') . '</option><option value="blocked">' . esc_html__('blocked', 'sfm4u-wholesale') . '</option></select> <input name="reason" placeholder="' . esc_attr__('Reason', 'sfm4u-wholesale') . '"> <button class="button">' . esc_html__('Save account state', 'sfm4u-wholesale') . '</button>');
            }
            if (current_user_can('manage_options')) {
                $have = (array) get_user_meta($id, CardView::SERVICES_META, true);
                $boxes = '';
                foreach (CardView::SERVICES as $slug => $label) { $boxes .= '<label style="margin-right:10px"><input type="checkbox" name="services[]" value="' . esc_attr($slug) . '"' . checked(in_array($slug, $have, true), true, false) . '> ' . esc_html($label) . '</label>'; }
                echo $form('services', esc_html__('Card back badges (member enrolled; Golden Club only when the member connected it):', 'sfm4u-wholesale') . ' ' . $boxes . ' <button class="button">' . esc_html__('Save badges', 'sfm4u-wholesale') . '</button>');
            }
            echo '<details><summary>' . esc_html__('History', 'sfm4u-wholesale') . '</summary><pre>' . esc_html(implode("\n", self::history($id))) . '</pre></details></div>';
        }
        echo '<h2>' . esc_html__('Open a new account', 'sfm4u-wholesale') . '</h2><form method="post">';
        wp_nonce_field('sfm_wr_accounts');
        echo '<input type="hidden" name="sfm_wr_acc_action" value="open"><p><input type="email" name="email" required placeholder="' . esc_attr__('Email', 'sfm4u-wholesale') . '"> '
            . '<input name="name" required placeholder="' . esc_attr__('Name', 'sfm4u-wholesale') . '"> '
            . (current_user_can('manage_options') ? '<input type="number" name="number" min="1" max="9999999" placeholder="' . esc_attr__('Number (admin, optional)', 'sfm4u-wholesale') . '" style="width:170px"> ' : '')
            . '</p><p><select name="id_type"><option value="nid">NID</option><option value="brn">' . esc_html__('Birth registration', 'sfm4u-wholesale') . '</option></select> '
            . '<input name="id_number" autocomplete="off" placeholder="' . esc_attr__('Number (optional: 90 days)', 'sfm4u-wholesale') . '"> <input type="date" name="dob"> '
            . '<label><input type="checkbox" name="consent" value="yes"> ' . esc_html__('Customer agreed (signed consent kept)', 'sfm4u-wholesale') . '</label> '
            . '<button class="button button-primary">' . esc_html__('Open account', 'sfm4u-wholesale') . '</button></p></form>';
        $alerts = array_filter((array) get_option(self::OPT_ALERTS, []), fn($a) => ($a['status'] ?? '') === 'open');
        echo '<h2>' . esc_html__('Duplicate alerts', 'sfm4u-wholesale') . '</h2>';
        if (!$alerts) { echo '<p>' . esc_html__('None.', 'sfm4u-wholesale') . '</p>'; }
        else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time (UTC)', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Newer account (blocked)', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Older account', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Why', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($alerts as $a) {
                echo '<tr><td>' . esc_html($a['time']) . '</td><td><a href="' . esc_url(add_query_arg(['page' => 'sfm-wr-accounts', 'uid' => (int) $a['newer']], admin_url('admin.php'))) . '">' . esc_html(Members::memberNo((int) $a['newer'])) . '</a></td><td><a href="' . esc_url(add_query_arg(['page' => 'sfm-wr-accounts', 'uid' => (int) $a['older']], admin_url('admin.php'))) . '">' . esc_html(Members::memberNo((int) $a['older'])) . '</a></td><td>' . esc_html($a['why']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<form method="post" style="margin-top:8px">';
        wp_nonce_field('sfm_wr_accounts');
        echo '<input type="hidden" name="sfm_wr_acc_action" value="scan"><button class="button">' . esc_html__('Scan for duplicates now', 'sfm4u-wholesale') . '</button></form></div>';
    }

    /** The day the 90-day rule went live on this site (existing accounts count from here). */
    public static function ruleStart(): int {
        $t = (int) get_option(self::OPT_START, 0);
        if ($t <= 0) { $t = time(); add_option(self::OPT_START, (string) $t, '', 'no'); }
        return $t;
    }

    /** Administrators always have the account-manager and identity capabilities. */
    public static function grantCaps(array $all, array $caps, array $args, $user): array {
        if (!empty($all['manage_options'])) { $all[self::CAP_MANAGER] = true; $all[self::CAP_VIEW] = true; }
        return $all;
    }

    public static function parseDob(string $raw): string {
        $raw = trim($raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return ''; }
        $t = strtotime($raw . ' 00:00:00 UTC');
        return ($t !== false && $t <= time() && (int) $m[1] >= 1900) ? $raw : '';
    }

    public static function hasIdentity(int $uid): bool {
        if ((string) get_user_meta($uid, self::M_HASH, true) !== '') { return true; }
        foreach (Members::cardsOf($uid) as $c) { if (!empty($c['id_hash'])) { return true; } }
        return false;
    }

    /** Other account holding this NID/BRN hash (user meta or a card), 0 when none. */
    public static function holderOf(string $hash, int $exceptUid = 0): int {
        global $wpdb;
        $u = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s AND user_id<>%d LIMIT 1", self::M_HASH, $hash, $exceptUid));
        if ($u) { return $u; }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT user_id FROM ' . Members::cards() . ' WHERE id_hash=%s AND user_id<>%d LIMIT 1', $hash, $exceptUid));
    }

    /**
     * Give (or, administrator only, correct) the person's NID/BRN and date of birth. Consent is required.
     * The same number on another account: the safe message (409) and a duplicate alert; nothing is stored.
     */
    public static function setIdentity(int $uid, string $type, string $raw, string $dob, int $staffId, bool $consent, string $reason = ''): void {
        if (!get_userdata($uid)) { throw new DomainException(__('Customer not found.', 'sfm4u-wholesale'), 404); }
        if (!$consent) { throw new DomainException(__('The customer must agree before we link their NID or birth registration number.', 'sfm4u-wholesale'), 422); }
        $nid = Members::parseNationalId($raw, $type === 'brn' ? 'brn' : 'nid');
        if (!$nid) { throw new DomainException(__('Enter a valid NID (10, 13 or 17 digits) or birth registration number (17 digits).', 'sfm4u-wholesale'), 422); }
        $dob = self::parseDob($dob);
        if ($dob === '') { throw new DomainException(__('Enter the date of birth as YYYY-MM-DD.', 'sfm4u-wholesale'), 422); }
        $hash = Members::idHash($nid[1], Members::idKey());
        $other = self::holderOf($hash, $uid);
        if ($other) {
            self::alert($uid, $other, 'same NID/BRN at entry');
            throw new DomainException(__(self::SAFE_DUPLICATE, 'sfm4u-wholesale'), 409);
        }
        $old = (string) get_user_meta($uid, self::M_HASH, true);
        if ($old !== '' && $old !== $hash) {
            if (!user_can($staffId, 'manage_options')) { throw new DomainException(__('Only an administrator can correct an NID or birth registration number.', 'sfm4u-wholesale'), 403); }
            if (trim($reason) === '') { throw new DomainException(__('Write the reason for the correction.', 'sfm4u-wholesale'), 422); }
        }
        update_user_meta($uid, self::M_HASH, $hash);
        update_user_meta($uid, self::M_TYPE, $nid[0]);
        update_user_meta($uid, self::M_LAST4, substr($nid[1], -4));
        update_user_meta($uid, self::M_ENC, Members::encrypt($nid[1]));
        update_user_meta($uid, self::M_DOB, $dob);
        if ((string) get_user_meta($uid, self::M_VERIFIED, true) === '' || ($old !== '' && $old !== $hash)) { update_user_meta($uid, self::M_VERIFIED, 'unverified'); }
        if (Members::cardsOf($uid)) { Members::linkNationalId($uid, $nid); }
        self::log($uid, $staffId, $old === '' ? 'identity added' : ($old === $hash ? 'identity confirmed' : 'identity corrected: ' . $reason));
    }

    /** Start of this person's 90 days: the later of account opening and the rule start. */
    public static function graceStart(int $uid): int {
        $u = get_userdata($uid);
        $reg = $u ? (int) strtotime((string) $u->user_registered . ' UTC') : time();
        return max($reg, self::ruleStart());
    }

    /**
     * ['state' => ok|grace|hold|blocked, 'id_days_left' => int|null, 'id_verified' => unverified|verified|problem, 'reason' => string]
     */
    public static function accountState(int $uid, ?int $now = null): array {
        $now = $now ?? time();
        $verified = (string) get_user_meta($uid, self::M_VERIFIED, true) ?: 'unverified';
        $explicit = (string) get_user_meta($uid, self::M_STATE, true);
        $reason = (string) get_user_meta($uid, self::M_STATE_REASON, true);
        if (in_array($explicit, ['hold', 'blocked'], true)) {
            return ['state' => $explicit, 'id_days_left' => null, 'id_verified' => $verified, 'reason' => $reason];
        }
        if ($verified === 'problem') {
            $deadline = (int) get_user_meta($uid, self::M_DEADLINE, true);
            return ['state' => ($deadline && $now > $deadline) ? 'blocked' : 'hold', 'id_days_left' => null, 'id_verified' => $verified, 'reason' => 'id_problem'];
        }
        if (!self::hasIdentity($uid)) {
            $left = self::GRACE_DAYS - (int) floor(($now - self::graceStart($uid)) / DAY_IN_SECONDS);
            return $left > 0
                ? ['state' => 'grace', 'id_days_left' => $left, 'id_verified' => $verified, 'reason' => 'nid_missing']
                : ['state' => 'hold', 'id_days_left' => 0, 'id_verified' => $verified, 'reason' => 'nid_missing'];
        }
        return ['state' => 'ok', 'id_days_left' => null, 'id_verified' => $verified, 'reason' => ''];
    }

    /** Member benefits allowed (member price, new khata credit, services). Paying old due is always allowed. */
    public static function usable(int $uid): bool {
        return in_array(self::accountState($uid)['state'], ['ok', 'grace'], true);
    }

    /** Administrator: hold, block or clear (''); reason required. */
    public static function setState(int $uid, string $state, string $reason, int $staffId): void {
        if (!user_can($staffId, 'manage_options')) { throw new DomainException(__('Only an administrator can hold or block an account.', 'sfm4u-wholesale'), 403); }
        if (!in_array($state, ['', 'hold', 'blocked'], true)) { throw new DomainException('Invalid account state.', 422); }
        if ($state !== '' && trim($reason) === '') { throw new DomainException(__('Write the reason.', 'sfm4u-wholesale'), 422); }
        if ($state === '') { delete_user_meta($uid, self::M_STATE); delete_user_meta($uid, self::M_STATE_REASON); }
        else { update_user_meta($uid, self::M_STATE, $state); update_user_meta($uid, self::M_STATE_REASON, sanitize_text_field($reason)); }
        self::log($uid, $staffId, 'state: ' . ($state ?: 'normal') . ($reason !== '' ? ' (' . $reason . ')' : ''));
        do_action('sfm_wr_account_state_changed', $uid, $state, $reason, $staffId);
    }

    /** Account manager records the government check: verified, or problem with a deadline (days) after a warning. */
    public static function recordCheck(int $uid, string $result, int $staffId, int $deadlineDays = 30, string $note = ''): void {
        if (!user_can($staffId, self::CAP_MANAGER)) { throw new DomainException(__('Only an account manager can record the NID check.', 'sfm4u-wholesale'), 403); }
        if (!self::hasIdentity($uid)) { throw new DomainException(__('This account has no NID or birth registration yet.', 'sfm4u-wholesale'), 409); }
        if ($result === 'verified') {
            update_user_meta($uid, self::M_VERIFIED, 'verified');
            delete_user_meta($uid, self::M_DEADLINE);
        } elseif ($result === 'problem') {
            update_user_meta($uid, self::M_VERIFIED, 'problem');
            update_user_meta($uid, self::M_DEADLINE, time() + max(1, min(365, $deadlineDays)) * DAY_IN_SECONDS);
            do_action('sfm_wr_identity_warning', $uid, $deadlineDays);   // app / SMS notice hooks here
        } else {
            throw new DomainException('Invalid check result.', 422);
        }
        self::log($uid, $staffId, 'government check: ' . $result . ($note !== '' ? ' (' . sanitize_text_field($note) . ')' : ''));
    }

    /**
     * Two accounts for one person: block the NEWER account, alert the account manager and administrator.
     * Called at entry (nothing stored then) and by the duplicate scan.
     */
    public static function alert(int $uidA, int $uidB, string $why): void {
        $newer = self::newer($uidA, $uidB);
        $older = $newer === $uidA ? $uidB : $uidA;
        $list = get_option(self::OPT_ALERTS, []);
        $list = is_array($list) ? $list : [];
        $key = min($uidA, $uidB) . '-' . max($uidA, $uidB);
        foreach ($list as $a) { if (($a['key'] ?? '') === $key && ($a['status'] ?? '') === 'open') { return; } }
        array_unshift($list, ['key' => $key, 'newer' => $newer, 'older' => $older, 'why' => $why, 'time' => gmdate('Y-m-d H:i:s'), 'status' => 'open']);
        update_option(self::OPT_ALERTS, array_slice($list, 0, 500), false);
        if ($why !== 'same NID/BRN at entry') {   // at entry nothing is stored: the newer "account" just did not get the number
            update_user_meta($newer, self::M_STATE, 'blocked');
            update_user_meta($newer, self::M_STATE_REASON, 'duplicate of member ' . Members::memberNo($older));
            self::log($newer, 0, 'blocked: duplicate (' . $why . ')');
        }
        do_action('sfm_wr_duplicate_account', $newer, $older, $why);
    }

    private static function newer(int $a, int $b): int {
        $ua = get_userdata($a); $ub = get_userdata($b);
        $ta = $ua ? (string) $ua->user_registered : ''; $tb = $ub ? (string) $ub->user_registered : '';
        return ($ta === $tb) ? max($a, $b) : ($ta > $tb ? $a : $b);
    }

    /** Scan for accounts sharing one NID/BRN hash (CEO AI / daily job). Returns the number of new alerts. */
    public static function scanDuplicates(): int {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value h, GROUP_CONCAT(user_id ORDER BY user_id) ids FROM {$wpdb->usermeta} WHERE meta_key=%s GROUP BY meta_value HAVING COUNT(*)>1", self::M_HASH), ARRAY_A) ?: [];
        $before = count((array) get_option(self::OPT_ALERTS, []));
        foreach ($rows as $r) {
            $ids = array_map('intval', explode(',', (string) $r['ids']));
            for ($i = 1; $i < count($ids); $i++) { self::alert($ids[0], $ids[$i], 'same NID/BRN on two accounts'); }
        }
        return count((array) get_option(self::OPT_ALERTS, [])) - $before;
    }

    /**
     * Search in the Owner's order: member number -> date of birth (+ name) -> NID -> birth registration.
     * Returns user ids. The account manager never gets the stored NID back; matching is by keyed hash.
     */
    public static function search(string $memberNo = '', string $dob = '', string $name = '', string $nid = '', string $brn = ''): array {
        if (preg_match('/^\d{1,7}$/', $memberNo)) {
            $u = Members::userByMemberNo(Members::formatMemberNo((int) $memberNo));
            if ($u) { return [$u]; }
        }
        if (($d = self::parseDob($dob)) !== '') {
            $ids = get_users(['meta_key' => self::M_DOB, 'meta_value' => $d, 'number' => 50, 'fields' => 'ID']);
            if ($name !== '') {
                $n = mb_strtolower(trim($name));
                $ids = array_values(array_filter($ids, function ($id) use ($n) { $u = get_userdata((int) $id); return $u && str_contains(mb_strtolower($u->display_name . ' ' . $u->first_name . ' ' . $u->last_name), $n); }));
            }
            if ($ids) { return array_map('intval', $ids); }
        }
        foreach ([['nid', $nid], ['brn', $brn]] as [$t, $raw]) {
            if ($raw === '') { continue; }
            $p = Members::parseNationalId($raw, $t);
            if ($p && ($u = self::holderOf(Members::idHash($p[1], Members::idKey()), 0))) { return [$u]; }
        }
        return [];
    }

    /**
     * Account manager / administrator opens an account (owner rule §3). Only an administrator may choose the number
     * (1-100, special or any free number); otherwise the next normal number is given. NID/BRN + date of birth are optional
     * here (90 days rule) but, when given, need the customer's consent. Returns the new user id.
     */
    public static function openAccount(string $email, string $name, int $staffId, int $number = 0, array $identity = []): int {
        if (!user_can($staffId, self::CAP_MANAGER)) { throw new DomainException(__('You cannot manage accounts.', 'sfm4u-wholesale'), 403); }
        if ($number > 0 && !user_can($staffId, 'manage_options')) { throw new DomainException(__('Only an administrator can change a member number.', 'sfm4u-wholesale'), 403); }
        $email = sanitize_email($email); $name = trim(sanitize_text_field($name));
        if (!is_email($email) || $name === '') { throw new DomainException(__('Give the email and the name.', 'sfm4u-wholesale'), 422); }
        if (email_exists($email)) { throw new DomainException(__('This email already has an account. Search for it instead.', 'sfm4u-wholesale'), 409); }
        if ($identity) {   // check the NID/BRN BEFORE creating anything, so a duplicate leaves no new account behind
            $nid = Members::parseNationalId((string) ($identity['number'] ?? ''), ($identity['type'] ?? '') === 'brn' ? 'brn' : 'nid');
            if ($nid && self::holderOf(Members::idHash($nid[1], Members::idKey()), 0)) {
                throw new DomainException(__(self::SAFE_DUPLICATE, 'sfm4u-wholesale'), 409);
            }
        }
        $base = sanitize_user(strtok($email, '@'), true) ?: 'member';
        $login = $base; for ($i = 2; username_exists($login); $i++) { $login = $base . $i; }
        $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => $email, 'display_name' => $name, 'first_name' => $name, 'role' => 'subscriber']);
        if (is_wp_error($uid)) { throw new DomainException($uid->get_error_message()); }
        $uid = (int) $uid;
        try {
            if ($number > 0) { Members::assignNumber($uid, $number, $staffId); }
            Members::memberNo($uid);
            if (!Members::cardsOf($uid)) { Members::issue($uid, 'regular', 0, $staffId, 'main', null, '', 'main'); }
            if ($identity) {
                self::setIdentity($uid, (string) ($identity['type'] ?? 'nid'), (string) ($identity['number'] ?? ''), (string) ($identity['dob'] ?? ''), $staffId, !empty($identity['consent']));
            }
        } catch (DomainException $e) {
            if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
            wp_delete_user($uid);   // nothing half-made stays behind
            throw $e;
        }
        self::log($uid, $staffId, 'account opened by staff' . ($number > 0 ? ' with number ' . Members::formatMemberNo($number) : ''));
        return $uid;
    }

    /** What a viewer may see of the stored NID/BRN. */
    public static function shownId(int $uid, int $viewerId): string {
        $type = (string) get_user_meta($uid, self::M_TYPE, true);
        $last4 = (string) get_user_meta($uid, self::M_LAST4, true);
        if ($last4 === '') { return ''; }
        $label = $type === 'brn' ? __('Birth reg.', 'sfm4u-wholesale') : 'NID';
        if (user_can($viewerId, self::CAP_VIEW)) {
            $full = Members::decrypt((string) get_user_meta($uid, self::M_ENC, true));
            if ($full !== '') { return $label . ' ' . $full; }
        }
        return $label . ' •••• ' . $last4;
    }

    public static function dob(int $uid): string {
        return (string) get_user_meta($uid, self::M_DOB, true);
    }

    private static function log(int $uid, int $staffId, string $what): void {
        add_user_meta($uid, self::M_LOG, gmdate('Y-m-d H:i:s') . ' #' . $staffId . ' ' . $what);
    }

    /** @return string[] */
    public static function history(int $uid): array {
        return array_map('strval', (array) get_user_meta($uid, self::M_LOG));
    }
}
