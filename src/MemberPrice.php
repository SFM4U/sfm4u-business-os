<?php
namespace SFM4U\Wholesale;

use DomainException;
use SFM4U\Wholesale\Members\{CardLink, MemberLink, Members, Tiers};

/**
 * M2 (0.9.x): SFM4U Business / Business Black members get the wholesale price at ANY quantity online
 * (plan section 4, step 1).
 *
 * Who counts as a member here:
 *  - a logged-in customer whose own card on this site is an active Business card or a paid Business Black card, or
 *  - a logged-in customer whose account is linked to an SFM4U member at account.sfm4u.com (CardLink "shop" role).
 *    Linking uses the existing one-time code: the code only reaches the real member's SFM4U account, so a typed
 *    card number alone never gives the price (one card = one account; a linked member cannot be linked twice).
 * The member's tier is re-checked at the account site every 10 minutes; if that site cannot be reached, the last
 * answer is used for up to 24 hours, then the member price stops until the next good check.
 */
final class MemberPrice {
    public const TIERS = ['business', 'black'];
    public const RECHECK = 600;
    public const STALE = 86400;
    /** Owner settings (0.9.5). Defaults are the safest choice until the owner decides. */
    public const OPT_CARD_MODE = 'sfmwh_member_card_mode';   // 'code' (default) | 'card_only'
    public const OPT_PRICE_MODE = 'sfmwh_member_price_mode'; // 'base' (default) | 'lowest'
    public const OPT_RENEW_DAYS = 'sfmwh_black_renew_days';  // reminder window, default 7
    private const NONCE = 'sfmwh_member_price';
    private static array $cache = [];

    public static function cardMode(): string {
        return get_option(self::OPT_CARD_MODE, 'code') === 'card_only' ? 'card_only' : 'code';
    }

    public static function priceMode(): string {
        return get_option(self::OPT_PRICE_MODE, 'base') === 'lowest' ? 'lowest' : 'base';
    }

    public static function register(): void {
        add_action('admin_menu', [self::class, 'adminMenu']);
        add_action('woocommerce_before_cart', [self::class, 'box'], 5);
        add_action('woocommerce_before_checkout_form', [self::class, 'box'], 5);
        add_shortcode('sfm4u_member_price', [self::class, 'shortcode']);
        add_action('template_redirect', [self::class, 'handlePost'], 5);
        add_action('woocommerce_checkout_create_order', [self::class, 'saveOrder'], 20, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'orderAdmin'], 20);
        add_action('woocommerce_admin_order_data_after_order_details', [self::class, 'orderApplyBox'], 20);
        add_action('woocommerce_process_shop_order_meta', [self::class, 'orderApplySave'], 50, 2);
    }

    /** Pure: does this cashier-safe member answer give wholesale? Older account sites send no wholesale_ok. */
    public static function qualifies(?array $m): bool {
        if (!$m || !in_array($m['tier'] ?? '', self::TIERS, true) || ($m['status'] ?? '') !== 'active') {
            return false;
        }
        // Account rules (Skill 227): an account on hold or blocked gets no member benefits.
        if (isset($m['account_state']) && !in_array($m['account_state'], ['ok', 'grace'], true)) {
            return false;
        }
        return !array_key_exists('wholesale_ok', $m) || !empty($m['wholesale_ok']);
    }

    /** Pure: mask a member or card number for orders and screens: 1234567 → 123****. */
    public static function mask(string $no): string {
        $no = preg_replace('/\D/', '', $no);
        return strlen($no) <= 3 ? $no : substr($no, 0, 3) . str_repeat('*', strlen($no) - 3);
    }

    /** Pure: should the stored link be re-checked now? Returns [recheck, usableIfCheckFails]. */
    public static function freshness(int $checkedAt, int $now): array {
        return [$now - $checkedAt >= self::RECHECK, $now - $checkedAt < self::STALE];
    }

    /** Member answer for a user (cached per request), or null. */
    public static function memberFor(int $uid): ?array {
        if ($uid <= 0) {
            return null;
        }
        if (array_key_exists($uid, self::$cache)) {
            return self::$cache[$uid];
        }
        if (get_user_meta($uid, '_sfmwh_buyer_status', true) === 'suspended') {
            return self::$cache[$uid] = null;
        }
        return self::$cache[$uid] = self::localMember($uid) ?? self::linkedMember($uid) ?? self::sessionCard($uid);
    }

    public static function current(): ?array {
        return is_user_logged_in() ? self::memberFor(get_current_user_id()) : null;
    }

    public static function forget(int $uid): void {
        unset(self::$cache[$uid]);
    }

    /** card_only mode: a card typed in this session (checked at the account site or locally, re-checked every 10 min). */
    private static function sessionCard(int $uid): ?array {
        if (self::cardMode() !== 'card_only' || !function_exists('WC') || !WC()->session) {
            return null;
        }
        $c = WC()->session->get('sfmwh_card_member');
        if (!is_array($c) || (int) ($c['uid'] ?? 0) !== $uid || empty($c['raw'])) {
            return null;
        }
        if (time() - (int) ($c['checked'] ?? 0) >= self::RECHECK) {
            try {
                $m = self::fromScan((string) $c['raw']);
            } catch (DomainException $e) {
                $m = time() - (int) $c['checked'] < self::STALE ? $c['member'] : null;
            }
            if (!$m || empty($m['qualifies'])) {
                WC()->session->set('sfmwh_card_member', null);
                return null;
            }
            $c = ['uid' => $uid, 'raw' => $c['raw'], 'checked' => time(), 'member' => $m];
            WC()->session->set('sfmwh_card_member', $c);
        }
        return ['member_no' => (string) $c['member']['member_no'], 'tier' => (string) $c['member']['tier'], 'status' => 'active', 'source' => 'card'];
    }

    private static function localMember(int $uid): ?array {
        if (!class_exists(Members::class) || !Modules::on('membership') || !Members::cardsOf($uid)) {
            return null;
        }
        $card = Members::bestCardFor($uid);
        if (!$card || $card['status'] !== 'active') {
            return null;
        }
        $ok = ($card['tier'] === 'business' || ($card['tier'] === 'black' && Tiers::blackActive($uid)))
            && (!class_exists(\SFM4U\Wholesale\Members\Identity::class) || \SFM4U\Wholesale\Members\Identity::usable($uid));
        return $ok ? ['member_no' => Members::memberNo($uid), 'tier' => $card['tier'], 'status' => 'active', 'source' => 'local'] : null;
    }

    private static function linkedMember(int $uid): ?array {
        if (!class_exists(MemberLink::class) || !Modules::on('membership')) {
            return null;
        }
        $l = MemberLink::linked($uid);
        if (!$l) {
            return null;
        }
        $checked = (int) get_user_meta($uid, 'sfmwh_member_checked', true);
        [$recheck, $usable] = self::freshness($checked ?: (int) strtotime((string) get_user_meta($uid, 'sfm_wr_link_time', true) . ' UTC'), time());
        $wholesaleOk = get_user_meta($uid, 'sfmwh_member_ok', true);
        if ($recheck && CardLink::role() === 'shop') {
            $reached = true;
            try {
                $m = CardLink::remoteLookup($l['member_no']);
            } catch (DomainException $e) {
                $reached = false; // Account site unreachable: use the last answer while it is less than a day old.
            }
            if ($reached) {
                update_user_meta($uid, 'sfmwh_member_checked', time());
                $sameKey = $m && ($m['member_key'] ?? '') !== '' && (string) $m['member_key'] === (string) get_user_meta($uid, 'sfm_wr_member_key', true);
                if (!$m || ((string) ($m['member_no'] ?? '') !== $l['member_no'] && !$sameKey)) {
                    update_user_meta($uid, 'sfmwh_member_ok', 'no');
                    return null;
                }
                try {
                    MemberLink::saveLink($uid, $m);
                } catch (DomainException $e) {
                    update_user_meta($uid, 'sfmwh_member_ok', 'no'); // same member linked to another account here
                    return null;
                }
                update_user_meta($uid, 'sfmwh_member_ok', array_key_exists('wholesale_ok', $m) ? (!empty($m['wholesale_ok']) ? 'yes' : 'no') : '');
                $l = MemberLink::linked($uid);
                $wholesaleOk = get_user_meta($uid, 'sfmwh_member_ok', true);
                $usable = true;
            }
        }
        if (!$usable) {
            return null;
        }
        $m = $l + ($wholesaleOk === '' ? [] : ['wholesale_ok' => $wholesaleOk === 'yes']) + ['source' => 'link'];
        return self::qualifies($m) ? $m : null;
    }

    /**
     * M3: member from a card scanned at the counter (or typed on an order). The physical card is the proof.
     * Local card first, then the account site (shop role). Returns cashier-safe data plus 'qualifies', or null.
     */
    public static function fromScan(string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 200 || !class_exists(CardLink::class) || !Modules::on('membership')) {
            return null;
        }
        $card = CardLink::findLocal($raw);
        if ($card) {
            $m = CardLink::publicMember($card);
            if (!array_key_exists('wholesale_ok', $m)) {
                $uid = (int) $card['user_id'];
                $m['wholesale_ok'] = $card['status'] === 'active' && ($card['tier'] === 'business' || ($card['tier'] === 'black' && Tiers::blackActive($uid)));
            }
            $m['source'] = 'local';
            $m['user_id'] = (int) $card['user_id'];
        } elseif (CardLink::role() === 'shop') {
            $m = CardLink::remoteLookup($raw); // throws DomainException when the account site cannot be reached
            if (!$m) {
                return null;
            }
            $m['source'] = 'remote';
            $m['user_id'] = preg_match('/^\d{7}$/', (string) ($m['member_no'] ?? '')) ? CardLink::localUserFor($m) : 0;
        } else {
            return null;
        }
        // A suspended wholesale buyer on this site does not get the member price, card or not (Codex review 2026-11-21).
        $m['suspended'] = !empty($m['user_id']) && get_user_meta((int) $m['user_id'], '_sfmwh_buyer_status', true) === 'suspended';
        $m['qualifies'] = self::qualifies($m) && !$m['suspended'];
        return $m;
    }

    /** Wholesale quote for a member at any quantity, using the buyer's price group when they have one. */
    public static function quote(int $productId, int $quantity, string $pack, int $uid): ?array {
        if (!self::memberFor($uid)) {
            return null;
        }
        $stored = Commerce::storedRule($productId);
        if (!$stored) {
            return null;
        }
        $rule = Rule::validate($stored);
        if ($rule['currency'] === get_woocommerce_currency()) {
            $rule = Rule::rescale($rule, wc_get_price_decimals());
        }
        if ($rule['currency'] !== get_woocommerce_currency() || $rule['scale'] !== wc_get_price_decimals()) {
            throw new DomainException(__('The wholesale rule uses a different store currency or precision.', 'sfm4u-wholesale'));
        }
        return Rule::memberQuote($rule, $quantity, $pack, self::groupPrice($rule, $uid), self::priceMode());
    }

    /** A member who is also an approved wholesale buyer keeps their own group price (if the rule has one). */
    public static function groupPrice(array $rule, int $uid): ?int {
        if (empty($rule['group_prices']) || get_user_meta($uid, '_sfmwh_buyer_status', true) !== 'approved') {
            return null;
        }
        $assigned = get_user_meta($uid, '_sfmwh_price_group', true);
        $tier = in_array($assigned, Rule::GROUPS, true) ? $assigned : (get_user_meta($uid, '_sfmwh_spend_tier', true) ?: 'bronze');
        return $rule['group_prices'][$tier] ?? null;
    }

    /* ---------------- cart / checkout box ---------------- */

    private static function linkingOn(): bool {
        return class_exists(CardLink::class) && Modules::on('membership') && CardLink::role() === 'shop' && !empty(CardLink::master()['key_id']);
    }

    public static function shortcode(): string {
        ob_start();
        self::box();
        return (string) ob_get_clean();
    }

    public static function box(): void {
        if (get_option('sfmwh_enabled', 'no') !== 'yes') {
            return;
        }
        $m = self::current();
        if (!$m && !self::linkingOn() && self::cardMode() !== 'card_only') {
            return;
        }
        echo '<div class="sfmwh-member-box" style="border:1px solid rgba(221,193,123,.6);border-radius:12px;padding:10px 14px;margin:0 0 16px">';
        $uid = get_current_user_id();
        if ($uid && is_array($msg = get_transient('sfmwh_mp_msg_' . $uid))) {
            delete_transient('sfmwh_mp_msg_' . $uid);
            echo '<p class="woocommerce-' . ($msg[0] === 'error' ? 'error' : 'message') . '" role="alert" style="margin:0 0 8px">' . esc_html((string) $msg[1]) . '</p>';
        }
        if ($m) {
            /* translators: 1: card type, 2: masked member number */
            echo '<p style="margin:0"><strong>' . esc_html(sprintf(__('SFM4U %1$s member %2$s: wholesale price at any quantity is applied.', 'sfm4u-wholesale'),
                $m['tier'] === 'black' ? 'Business Black' : 'Business', self::mask((string) $m['member_no']))) . '</strong></p></div>';
            return;
        }
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__('SFM4U Business member? Get the wholesale price at any quantity.', 'sfm4u-wholesale') . '</strong></p>';
        if (!$uid) {
            echo '<p style="margin:0"><a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">' . esc_html__('Log in to use your SFM4U card.', 'sfm4u-wholesale') . '</a></p></div>';
            return;
        }
        $form = static function (string $act, string $inner, string $button): void {
            echo '<form method="post" style="margin:6px 0">' . wp_nonce_field(self::NONCE, '_sfmwh_mp', true, false)
                . '<input type="hidden" name="sfmwh_mp_action" value="' . esc_attr($act) . '">' . $inner
                . ' <button type="submit" class="button">' . esc_html($button) . '</button></form>';
        };
        if (self::cardMode() === 'card_only') {
            $form('card', '<label>' . esc_html__('SFM4U Business card number', 'sfm4u-wholesale')
                . ' <input type="text" name="sfmwh_mp_card" required autocomplete="off" style="max-width:220px"></label>', __('Use card', 'sfm4u-wholesale'));
            echo '</div>';
            return;
        }
        $form('start', '<label>' . esc_html__('Card number, member number, email or mobile', 'sfm4u-wholesale')
            . ' <input type="text" name="sfmwh_mp_who" required autocomplete="off" style="max-width:260px"></label>', __('Send me a code', 'sfm4u-wholesale'));
        if (get_user_meta($uid, 'sfmwh_mp_who', true)) {
            $form('finish', '<label>' . esc_html__('6-digit code from your SFM4U account (account.sfm4u.com → My Membership Card)', 'sfm4u-wholesale')
                . ' <input type="text" name="sfmwh_mp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" style="max-width:120px"></label>',
                __('Use my card', 'sfm4u-wholesale'));
        }
        echo '</div>';
    }

    /** Pure: at most 5 code requests an hour per account. */
    public static function rateOk(array $times, int $now): bool {
        return count(array_filter($times, static fn($t) => (int) $t > $now - 3600)) < 5;
    }

    public static function handlePost(): void {
        if (empty($_POST['sfmwh_mp_action']) || !is_user_logged_in() || !isset($_POST['_sfmwh_mp'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_sfmwh_mp'])), self::NONCE)) {
            return;
        }
        $uid = get_current_user_id();
        $act = sanitize_key(wp_unslash((string) $_POST['sfmwh_mp_action']));
        $say = static fn(string $type, string $text) => set_transient('sfmwh_mp_msg_' . $uid, [$type, $text], 300);
        try {
            if ($act === 'card' && self::cardMode() === 'card_only') {
                $raw = trim(sanitize_text_field(wp_unslash((string) ($_POST['sfmwh_mp_card'] ?? ''))));
                $times = get_user_meta($uid, 'sfmwh_mp_times', true);
                $times = is_array($times) ? $times : [];
                if (!self::rateOk($times, time())) {
                    throw new DomainException(__('Too many tries. Try again in an hour.', 'sfm4u-wholesale'));
                }
                $times[] = time();
                update_user_meta($uid, 'sfmwh_mp_times', array_slice($times, -10));
                $m = self::fromScan($raw);
                if (!$m || empty($m['qualifies'])) {
                    throw new DomainException(__('Only an active Business card or a paid Business Black card gets the wholesale price.', 'sfm4u-wholesale'));
                }
                WC()->session->set('sfmwh_card_member', ['uid' => $uid, 'raw' => $raw, 'checked' => time(), 'member' => $m]);
                self::forget($uid);
                $say('success', __('Card accepted. The wholesale price is now applied to your cart.', 'sfm4u-wholesale'));
                wp_safe_redirect(wp_get_referer() ?: wc_get_cart_url());
                exit;
            }
            if (!self::linkingOn()) {
                throw new DomainException(__('This shop is not connected to the SFM4U account site yet.', 'sfm4u-wholesale'));
            }
            if ($act === 'start') {
                $who = trim(sanitize_text_field(wp_unslash((string) ($_POST['sfmwh_mp_who'] ?? ''))));
                if ($who === '') {
                    throw new DomainException(__('Type your card number, member number, email or mobile.', 'sfm4u-wholesale'));
                }
                $times = get_user_meta($uid, 'sfmwh_mp_times', true);
                $times = is_array($times) ? $times : [];
                if (!self::rateOk($times, time())) {
                    throw new DomainException(__('Too many code requests. Try again in an hour.', 'sfm4u-wholesale'));
                }
                $times[] = time();
                update_user_meta($uid, 'sfmwh_mp_times', array_slice($times, -10));
                MemberLink::shopStart($who);
                update_user_meta($uid, 'sfmwh_mp_who', $who);
                $say('success', __('A 6-digit code is waiting in your SFM4U account (account.sfm4u.com → My Membership Card). Type it below.', 'sfm4u-wholesale'));
            } elseif ($act === 'finish') {
                $who = (string) get_user_meta($uid, 'sfmwh_mp_who', true);
                if ($who === '') {
                    throw new DomainException(__('Ask for a code first.', 'sfm4u-wholesale'));
                }
                $m = MemberLink::shopFinish($who, preg_replace('/\D/', '', (string) wp_unslash($_POST['sfmwh_mp_code'] ?? '')));
                MemberLink::saveLink($uid, $m);
                update_user_meta($uid, 'sfmwh_member_ok', array_key_exists('wholesale_ok', $m) ? (!empty($m['wholesale_ok']) ? 'yes' : 'no') : '');
                update_user_meta($uid, 'sfmwh_member_checked', time());
                delete_user_meta($uid, 'sfmwh_mp_who');
                self::forget($uid);
                $say('success', self::qualifies($m)
                    ? __('Card linked. The wholesale price is now applied to your cart.', 'sfm4u-wholesale')
                    : __('Card linked, but only an active Business or Business Black card gets the wholesale price.', 'sfm4u-wholesale'));
            }
        } catch (DomainException $e) {
            $say('error', $e->getMessage());
        }
        wp_safe_redirect(wp_get_referer() ?: wc_get_cart_url());
        exit;
    }

    /** Order record: which member price was used (masked number only). */
    public static function saveOrder($order, $data): void {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $uid = (int) $order->get_customer_id();
        $m = $uid ? self::memberFor($uid) : null;
        if (!$m) {
            return;
        }
        $order->update_meta_data('_sfmwh_member_price', 'yes');
        $order->update_meta_data('_sfmwh_member_no_masked', self::mask((string) $m['member_no']));
        $order->update_meta_data('_sfmwh_member_tier', (string) $m['tier']);
        $order->update_meta_data('_sfmwh_member_source', (string) ($m['source'] ?? ''));
    }

    /* ---------------- 0.9.5: owner settings + Business Black renewals ---------------- */

    /**
     * Pure: Business Black members to remind. $rows = [[user_id, name, until 'Y-m-d'], ...].
     * Returns rows with 'state' => 'expired' | 'due' (within $days), sorted by date. Others are left out.
     */
    public static function renewalList(array $rows, int $now, int $days): array {
        $out = [];
        foreach ($rows as $r) {
            $t = strtotime((string) $r['until'] . ' 23:59:59 UTC');
            if (!$t) {
                continue;
            }
            if ($t < $now) {
                $out[] = $r + ['state' => 'expired'];
            } elseif ($t < $now + $days * 86400) {
                $out[] = $r + ['state' => 'due'];
            }
        }
        usort($out, static fn($a, $b) => strcmp((string) $a['until'], (string) $b['until']));
        return $out;
    }

    public static function adminMenu(): void {
        add_submenu_page('woocommerce', __('SFM4U member price', 'sfm4u-wholesale'), __('SFM4U member price', 'sfm4u-wholesale'),
            'manage_woocommerce', 'sfmwh-member-price', [self::class, 'adminPage']);
    }

    public static function adminPage(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (isset($_POST['sfmwh_mp_save']) && check_admin_referer('sfmwh_mp_settings')) {
            $card = sanitize_key(wp_unslash((string) ($_POST['card_mode'] ?? 'code')));
            $price = sanitize_key(wp_unslash((string) ($_POST['price_mode'] ?? 'base')));
            $days = max(1, min(60, (int) ($_POST['renew_days'] ?? 7)));
            update_option(self::OPT_CARD_MODE, $card === 'card_only' ? 'card_only' : 'code', false);
            update_option(self::OPT_PRICE_MODE, $price === 'lowest' ? 'lowest' : 'base', false);
            update_option(self::OPT_RENEW_DAYS, $days, false);
            echo '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'sfm4u-wholesale') . '</p></div>';
        }
        $card = self::cardMode();
        $price = self::priceMode();
        $days = (int) get_option(self::OPT_RENEW_DAYS, 7);
        $radio = static function (string $name, string $value, string $current, string $label): string {
            return '<label style="display:block;margin:4px 0"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" '
                . checked($current, $value, false) . '> ' . esc_html($label) . '</label>';
        };
        echo '<div class="wrap"><h1>' . esc_html__('SFM4U member price', 'sfm4u-wholesale') . ' / ' . esc_html__('সদস্যের দাম', 'sfm4u-wholesale') . '</h1>';
        echo '<form method="post">' . wp_nonce_field('sfmwh_mp_settings', '_wpnonce', true, false);
        echo '<h2>' . esc_html__('1. How a customer uses a card online', 'sfm4u-wholesale') . '</h2>';
        echo $radio('card_mode', 'code', $card, __('Card number + matching account: the customer links the card with a 6-digit code from their own SFM4U account (safest, default)', 'sfm4u-wholesale'));
        echo $radio('card_mode', 'card_only', $card, __('Card number alone: any active Business card number typed at checkout (easier, but a leaked number can be used by others)', 'sfm4u-wholesale'));
        echo '<h2>' . esc_html__('2. Member price for small quantities', 'sfm4u-wholesale') . '</h2>';
        echo $radio('price_mode', 'base', $price, __('Base wholesale price (or the member group price); bigger tiers only by quantity (safest, default)', 'sfm4u-wholesale'));
        echo $radio('price_mode', 'lowest', $price, __('Lowest tier price even for 1 piece', 'sfm4u-wholesale'));
        echo '<h2>' . esc_html__('3. Cash whole-taka rounding (POS)', 'sfm4u-wholesale') . '</h2><p>'
            . esc_html__('Set on the POS register settings page: "Cash rounding" (default BDT 1.00 = whole taka for cash only; bKash/card stay exact).', 'sfm4u-wholesale') . '</p>';
        echo '<h2>' . esc_html__('4. Business Black reminders', 'sfm4u-wholesale') . '</h2><p><label>' . esc_html__('Remind this many days before expiry', 'sfm4u-wholesale')
            . ' <input type="number" name="renew_days" min="1" max="60" value="' . esc_attr((string) $days) . '" style="width:5em"></label></p>';
        echo '<p><button class="button button-primary" name="sfmwh_mp_save" value="1">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        $rows = [];
        if (class_exists(Tiers::class)) {
            foreach (get_users(['meta_key' => Tiers::BLACK_UNTIL, 'number' => 500, 'fields' => ['ID', 'display_name']]) as $u) {
                $rows[] = ['user_id' => (int) $u->ID, 'name' => $u->display_name, 'until' => Tiers::blackUntil((int) $u->ID)];
            }
        }
        $list = self::renewalList($rows, time(), $days);
        echo '<h2>' . esc_html__('Business Black: expired or due', 'sfm4u-wholesale') . '</h2>';
        if (!$list) {
            echo '<p>' . esc_html__('None.', 'sfm4u-wholesale') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Member', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Paid until', 'sfm4u-wholesale')
                . '</th><th>' . esc_html__('State', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($list as $r) {
                echo '<tr><td>' . esc_html($r['name']) . '</td><td>' . esc_html($r['until']) . '</td><td>'
                    . esc_html($r['state'] === 'expired' ? __('Expired - wholesale stopped', 'sfm4u-wholesale') : __('Due soon', 'sfm4u-wholesale')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /* ---------------- M3: staff applies a member card to a received order (plan G6) ---------------- */

    /** Only unpaid orders that are still pending or on hold may be repriced. */
    public static function orderCanApply(\WC_Order $order): bool {
        return in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true) && !$order->is_paid()
            && $order->get_meta('_sfmwh_member_price') !== 'yes';
    }

    public static function orderApplyBox($order): void {
        if (!$order instanceof \WC_Order || !current_user_can('edit_shop_orders') || !self::orderCanApply($order)
            || get_option('sfmwh_enabled', 'no') !== 'yes') {
            return;
        }
        wp_nonce_field('sfmwh_apply_card_' . $order->get_id(), '_sfmwh_apply_card');
        echo '<p class="form-field form-field-wide"><label for="sfmwh_apply_card">' . esc_html__('Apply SFM4U member card (member price)', 'sfm4u-wholesale')
            . '</label><input type="text" id="sfmwh_apply_card" name="sfmwh_apply_card" autocomplete="off" placeholder="'
            . esc_attr__('Scan or type card / member number, then Update', 'sfm4u-wholesale') . '"></p>';
    }

    public static function orderApplySave($orderId, $post = null): void {
        if (empty($_POST['sfmwh_apply_card']) || !isset($_POST['_sfmwh_apply_card']) || !current_user_can('edit_shop_orders')
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_sfmwh_apply_card'])), 'sfmwh_apply_card_' . (int) $orderId)) {
            return;
        }
        $order = wc_get_order((int) $orderId);
        $raw = sanitize_text_field(wp_unslash((string) $_POST['sfmwh_apply_card']));
        try {
            if (!$order) {
                throw new DomainException(__('Order not found.', 'sfm4u-wholesale'));
            }
            $m = self::fromScan($raw);
            if (!$m) {
                throw new DomainException(__('Card not found.', 'sfm4u-wholesale'));
            }
            self::applyToOrder($order, $m, get_current_user_id());
        } catch (DomainException $e) {
            if (class_exists('WC_Admin_Meta_Boxes')) {
                \WC_Admin_Meta_Boxes::add_error(__('Member price was not applied:', 'sfm4u-wholesale') . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * Reprice an unpaid order's lines at the member price. Lines without a wholesale rule keep their price.
     * Returns the amount saved in minor units. Writes an order note with the staff member and masked card.
     */
    public static function applyToOrder(\WC_Order $order, array $member, int $staff): int {
        if (!self::orderCanApply($order)) {
            throw new DomainException(__('The member price can only be applied to an unpaid order that is pending or on hold, once.', 'sfm4u-wholesale'));
        }
        if (empty($member['qualifies']) && !self::qualifies($member)) {
            throw new DomainException(__('This card does not get the member price. Only an active Business card or a paid Business Black card does.', 'sfm4u-wholesale'));
        }
        $scale = wc_get_price_decimals();
        $saved = 0;
        $changed = 0;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $stored = Commerce::storedRule((int) ($item->get_variation_id() ?: $item->get_product_id()));
            if (!$stored) {
                continue;
            }
            $rule = Rule::validate($stored);
            try {
                $rule = Rule::rescale($rule, $scale);
            } catch (\DomainException $e) {
                continue;
            }
            if ($rule['currency'] !== $order->get_currency() || $rule['scale'] !== $scale) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            if ($qty < 1) {
                continue;
            }
            $quote = Rule::memberQuote($rule, $qty, 'piece', self::groupPrice($rule, (int) $order->get_customer_id()), self::priceMode());
            $old = (int) round((float) $item->get_total() * (10 ** $scale));
            if ($quote['line_minor'] >= $old) {
                continue; // never raise a price
            }
            $line = Rule::decimal($quote['line_minor'], $scale);
            $item->set_subtotal($line);
            $item->set_total($line);
            $item->save();
            $saved += $old - $quote['line_minor'];
            $changed++;
        }
        $order->calculate_totals(true);
        $order->update_meta_data('_sfmwh_member_price', 'yes');
        $order->update_meta_data('_sfmwh_member_no_masked', self::mask((string) $member['member_no']));
        $order->update_meta_data('_sfmwh_member_tier', (string) $member['tier']);
        $order->update_meta_data('_sfmwh_member_source', 'order');
        $order->update_meta_data('_sfmwh_member_applied_by', $staff);
        $order->save();
        $user = get_userdata($staff);
        /* translators: 1: staff name, 2: masked card, 3: lines, 4: amount */
        $order->add_order_note(sprintf(__('SFM4U member price applied by %1$s with card %2$s: %3$d line(s), saved %4$s.', 'sfm4u-wholesale'),
            $user ? $user->display_name : '#' . $staff, self::mask((string) $member['member_no']), $changed,
            wp_strip_all_tags(wc_price($saved / (10 ** $scale), ['currency' => $order->get_currency()]))));
        return $saved;
    }

    public static function orderAdmin($order): void {
        if (!$order instanceof \WC_Order || $order->get_meta('_sfmwh_member_price') !== 'yes') {
            return;
        }
        echo '<p><strong>' . esc_html__('SFM4U member price', 'sfm4u-wholesale') . ':</strong> '
            . esc_html((string) $order->get_meta('_sfmwh_member_no_masked') . ' (' . $order->get_meta('_sfmwh_member_tier') . ')') . '</p>';
    }
}
