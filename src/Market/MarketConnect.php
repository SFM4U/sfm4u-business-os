<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Market;

use DomainException;
use SFM4U\Wholesale\Members\{CardLink, MemberLink, Members};

defined('ABSPATH') || exit;

/**
 * SFM4U Business OS on a marketplace (AlgiBazar): sellers and customers link their SFM4U member account,
 * sellers ask for the Business / Business Black plan, and checkout can take an SFM4U member card.
 * Needs the site to be connected to account.sfm4u.com (CardLink role "shop"). Everything is off until then.
 */
final class MarketConnect {
    public const ENDPOINT = 'sfm4u-membership';
    public const OPT = 'sfm_bos_market';      // ['checkout_card' => bool, 'benefits_en' => '', 'benefits_bn' => '']
    public const RATE = 5;                    // code requests per user per hour

    public static function register(): void {
        add_action('init', static function () { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); });
        add_filter('query_vars', static function (array $v) { $v[] = self::ENDPOINT; return $v; });
        add_filter('woocommerce_account_menu_items', [self::class, 'accountMenu']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'accountPage']);
        add_filter('dokan_get_dashboard_nav', [self::class, 'dokanNav']);
        add_action('template_redirect', [self::class, 'handlePost']);
        add_action('woocommerce_after_order_notes', [self::class, 'checkoutField']);
        add_action('woocommerce_checkout_process', [self::class, 'checkoutValidate']);
        add_action('woocommerce_checkout_create_order', [self::class, 'checkoutSave'], 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'orderAdmin']);
        if (defined('SFM4U_STOCK_ONLY')) {
            add_action('admin_menu', [self::class, 'adminMenu'], 60);
            add_action('admin_init', [self::class, 'adminActions']);
        }
        add_action('admin_init', static function () {
            if (get_option('sfm_bos_market_flushed') !== '1') { flush_rewrite_rules(false); update_option('sfm_bos_market_flushed', '1', false); }
        }, 30);
    }

    public static function on(): bool { return CardLink::role() === 'shop' && !empty(CardLink::master()['key_id']); }

    public static function settings(): array {
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        return $s + ['checkout_card' => false, 'benefits_en' => self::defaultBenefits('en'), 'benefits_bn' => self::defaultBenefits('bn')];
    }

    public static function defaultBenefits(string $lang): string {
        if ($lang === 'bn') {
            return "SFM4U মেম্বার হলে যা পাবেন:\n- একটাই SFM4U কার্ড ও মেম্বার নম্বর, সব SFM4U দোকানে চলে\n- Regular (ফ্রি, অনলাইন কার্ড): কেনাকাটায় পয়েন্ট ও কুপন\n- Premium: ফ্রি ছাপানো কার্ড, বড় কেনাকাটায় উপহার\n- Business: AlgiBazar দোকানদারদের জন্য হোলসেল দাম ও বাকির খাতা\n- Business Black (মাসে ৳১০০০): হোলসেল থেকে কেনা মাল নিজে নিজে আপনার AlgiBazar দোকানের স্টক ও POS-এ উঠে যায়\n\nSFM4U — sfm4u.com";
        }
        return "What SFM4U members get:\n- One SFM4U card and member number that works in every SFM4U shop\n- Regular (free, online card): points and coupons on purchases\n- Premium: free printed card, a gift on bigger purchases\n- Business: wholesale prices and a credit book for AlgiBazar sellers\n- Business Black (৳1000 a month): goods bought from SFM4U Wholesale are added to your AlgiBazar store stock and POS automatically\n\nSFM4U — sfm4u.com";
    }

    private static function isSeller(int $uid): bool {
        if (function_exists('dokan_is_user_seller')) { return (bool) dokan_is_user_seller($uid); }
        return user_can($uid, 'seller');
    }

    /* ---------------- My Account / Dokan ---------------- */

    public static function accountMenu(array $items): array {
        if (!self::on()) { return $items; }
        $out = [];
        foreach ($items as $k => $v) {
            if ($k === 'customer-logout') { $out[self::ENDPOINT] = __('SFM4U membership', 'sfm4u-wholesale'); }
            $out[$k] = $v;
        }
        if (!isset($out[self::ENDPOINT])) { $out[self::ENDPOINT] = __('SFM4U membership', 'sfm4u-wholesale'); }
        return $out;
    }

    public static function dokanNav($nav) {
        if (!is_array($nav) || !self::on() || !function_exists('wc_get_account_endpoint_url')) { return $nav; }
        $nav['sfm4u-membership'] = ['title' => __('SFM4U membership', 'sfm4u-wholesale'), 'icon' => '<i class="fas fa-id-card"></i>',
            'url' => wc_get_account_endpoint_url(self::ENDPOINT), 'pos' => 185, 'permission' => 'dokan_view_store_settings_menu'];
        return $nav;
    }

    private static function msg(string $type, string $text): void {
        set_transient('sfm_bos_msg_' . get_current_user_id(), [$type, $text], 120);
    }

    private static function field(string $k): string {
        return isset($_POST[$k]) && is_string($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : '';
    }

    /** Pure: is another code request allowed? $times = earlier request timestamps. */
    public static function rateOk(array $times, int $now): bool {
        return count(array_filter($times, static fn ($t) => (int) $t > $now - 3600)) < self::RATE;
    }

    public static function handlePost(): void {
        if (empty($_POST['sfm_bos_action']) || !is_user_logged_in() || !self::on()) { return; }
        if (!isset($_POST['_sfm_bos']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_sfm_bos'])), 'sfm_bos_member')) { return; }
        $uid = get_current_user_id();
        $act = self::field('sfm_bos_action');
        try {
            if ($act === 'start') {
                $who = self::field('who');
                if ($who === '') { throw new DomainException(__('Type your SFM4U member number, email or mobile.', 'sfm4u-wholesale')); }
                $times = get_user_meta($uid, 'sfm_bos_code_times', true);
                $times = is_array($times) ? $times : [];
                if (!self::rateOk($times, time())) { throw new DomainException(__('Too many code requests. Try again in an hour.', 'sfm4u-wholesale')); }
                $times[] = time();
                update_user_meta($uid, 'sfm_bos_code_times', array_slice($times, -10));
                MemberLink::shopStart($who);
                update_user_meta($uid, 'sfm_bos_link_who', $who);
                self::msg('success', __('Open your SFM4U account (account.sfm4u.com → My Membership Card). A 6-digit code is waiting there. Type it below.', 'sfm4u-wholesale'));
            } elseif ($act === 'finish') {
                $who = (string) get_user_meta($uid, 'sfm_bos_link_who', true);
                if ($who === '') { throw new DomainException(__('Ask for a code first.', 'sfm4u-wholesale')); }
                $m = MemberLink::shopFinish($who, preg_replace('/\D/', '', self::field('code')));
                MemberLink::saveLink($uid, $m);
                delete_user_meta($uid, 'sfm_bos_link_who');
                self::msg('success', sprintf(__('Linked to SFM4U member %s.', 'sfm4u-wholesale'), $m['member_no']));
            } elseif ($act === 'join') {
                $m = MemberLink::shopJoin($uid);
                MemberLink::saveLink($uid, $m);
                self::msg('success', sprintf(__('Welcome! Your SFM4U member number is %s. To log in at account.sfm4u.com use "Lost password" with your email.', 'sfm4u-wholesale'), $m['member_no']));
            } elseif ($act === 'refresh') {
                $l = MemberLink::linked($uid);
                if ($l && ($m = CardLink::remoteLookup($l['member_no']))) { MemberLink::saveLink($uid, $m); self::msg('success', __('Updated from your SFM4U account.', 'sfm4u-wholesale')); }
            } elseif ($act === 'plan') {
                if (!self::isSeller($uid)) { throw new DomainException(__('Plans are for AlgiBazar sellers. Open your store first.', 'sfm4u-wholesale')); }
                $storeUrl = function_exists('dokan_get_store_url') ? (string) dokan_get_store_url($uid) : '';
                $storeName = function_exists('dokan_get_store_info') ? (string) (dokan_get_store_info($uid)['store_name'] ?? '') : '';
                self::msg('success', MemberLink::shopPlanRequest($uid, self::field('plan'), $storeName, $storeUrl));
            } elseif ($act === 'unlink') {
                MemberLink::unlink($uid);
                self::msg('success', __('Unlinked. Your SFM4U account itself is not changed.', 'sfm4u-wholesale'));
            }
        } catch (DomainException $e) {
            self::msg('error', $e->getMessage());
        }
        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    private static function form(string $action, string $inner, string $button, string $class = 'button'): string {
        return '<form method="post" class="sfm-bos-form" style="margin:8px 0">' . wp_nonce_field('sfm_bos_member', '_sfm_bos', true, false)
            . '<input type="hidden" name="sfm_bos_action" value="' . esc_attr($action) . '">' . $inner
            . ' <button type="submit" class="' . esc_attr($class) . '">' . esc_html($button) . '</button></form>';
    }

    public static function benefitsHtml(): string {
        $s = self::settings();
        $bn = function_exists('determine_locale') && strpos(determine_locale(), 'bn') === 0;
        $txt = $bn ? $s['benefits_bn'] : $s['benefits_en'];
        return '<div class="sfm-bos-benefits" style="background:#f7f7f7;border-radius:10px;padding:12px 14px;margin:10px 0">' . wpautop(esc_html($txt)) . '</div>';
    }

    public static function accountPage(): void {
        nocache_headers();
        $uid = get_current_user_id();
        echo '<div class="sfm-bos-account"><h3>' . esc_html__('SFM4U membership', 'sfm4u-wholesale') . '</h3>';
        if (is_array($m = get_transient('sfm_bos_msg_' . $uid))) {
            delete_transient('sfm_bos_msg_' . $uid);
            echo '<div class="woocommerce-' . ($m[0] === 'error' ? 'error' : 'message') . '" role="alert">' . esc_html((string) $m[1]) . '</div>';
        }
        if (!self::on()) { echo '<p>' . esc_html__('SFM4U membership is not available on this site yet.', 'sfm4u-wholesale') . '</p></div>'; return; }
        $l = MemberLink::linked($uid);
        if ($l) {
            echo '<p><strong>' . esc_html__('Member number', 'sfm4u-wholesale') . ':</strong> ' . esc_html($l['member_no']) . '<br><strong>' . esc_html__('Card', 'sfm4u-wholesale') . ':</strong> '
                . esc_html(Members::tierLabel($l['tier'])) . ($l['status'] !== 'active' ? ' (' . esc_html($l['status']) . ')' : '') . '</p>';
            echo self::form('refresh', '', __('Refresh from SFM4U', 'sfm4u-wholesale'));
            if (self::isSeller($uid)) {
                if (in_array($l['tier'], ['business', 'black'], true)) {
                    echo '<p>' . esc_html($l['tier'] === 'black' ? __('Business Black is active: goods you buy from SFM4U Wholesale are added to your store stock automatically.', 'sfm4u-wholesale') : __('Business plan is active: you can buy at wholesale prices.', 'sfm4u-wholesale')) . '</p>';
                }
                if ($l['tier'] !== 'black') {
                    $opts = ($l['tier'] !== 'business' ? '<option value="business">' . esc_html__('Business (free for AlgiBazar sellers)', 'sfm4u-wholesale') . '</option>' : '')
                        . '<option value="black">' . esc_html__('Business Black (৳1000 a month, auto stock)', 'sfm4u-wholesale') . '</option>';
                    echo '<h4>' . esc_html__('Wholesale plan', 'sfm4u-wholesale') . '</h4>' . self::form('plan', '<select name="plan" aria-label="' . esc_attr__('Plan', 'sfm4u-wholesale') . '">' . $opts . '</select>', __('Ask for this plan', 'sfm4u-wholesale'), 'button alt');
                }
            }
            echo self::benefitsHtml();
            echo '<details style="margin-top:14px"><summary>' . esc_html__('Unlink', 'sfm4u-wholesale') . '</summary>' . self::form('unlink', '', __('Unlink my SFM4U account from this site', 'sfm4u-wholesale')) . '</details></div>';
            return;
        }
        echo self::benefitsHtml();
        echo '<h4>' . esc_html__('I already have an SFM4U card', 'sfm4u-wholesale') . '</h4>';
        echo self::form('start', '<label>' . esc_html__('Member number, email or mobile', 'sfm4u-wholesale') . '<br><input type="text" name="who" required autocomplete="off" style="max-width:280px"></label>', __('Send me a code', 'sfm4u-wholesale'));
        if (get_user_meta($uid, 'sfm_bos_link_who', true)) {
            echo self::form('finish', '<label>' . esc_html__('6-digit code from your SFM4U account', 'sfm4u-wholesale') . '<br><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" style="max-width:160px"></label>', __('Link my account', 'sfm4u-wholesale'), 'button alt');
        }
        echo '<h4>' . esc_html__('I am new to SFM4U', 'sfm4u-wholesale') . '</h4><p>' . esc_html__('We will make a free SFM4U account and online card with your email and mobile from this site.', 'sfm4u-wholesale') . '</p>';
        echo self::form('join', '', __('Make me an SFM4U member (free)', 'sfm4u-wholesale'), 'button alt') . '</div>';
    }

    /* ---------------- Checkout card box ---------------- */

    public static function checkoutOn(): bool { return self::on() && !empty(self::settings()['checkout_card']); }

    public static function checkoutField($checkout): void {
        if (!self::checkoutOn()) { return; }
        $l = is_user_logged_in() ? MemberLink::linked(get_current_user_id()) : [];
        woocommerce_form_field('sfm_member_card', ['type' => 'text', 'class' => ['form-row-wide'], 'label' => __('SFM4U member card / number (optional)', 'sfm4u-wholesale'),
            'placeholder' => __('7-digit member number or card number', 'sfm4u-wholesale'), 'required' => false], $l['member_no'] ?? '');
    }

    public static function checkoutValidate(): void {
        if (!self::checkoutOn()) { return; }
        $v = isset($_POST['sfm_member_card']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['sfm_member_card']))) : '';
        if ($v === '') { return; }
        try { $m = CardLink::remoteLookup($v); } catch (DomainException $e) { $m = null; wc_add_notice(__('Could not check the SFM4U card right now. Leave the card box empty or try again.', 'sfm4u-wholesale'), 'error'); return; }
        if (!$m) { wc_add_notice(__('SFM4U card not found. Check the number or leave the box empty.', 'sfm4u-wholesale'), 'error'); return; }
        if (($m['status'] ?? '') !== 'active') { wc_add_notice(__('This SFM4U card is not active.', 'sfm4u-wholesale'), 'error'); return; }
        $GLOBALS['sfm_bos_card'] = $m;
    }

    public static function checkoutSave($order, $data): void {
        $m = $GLOBALS['sfm_bos_card'] ?? null;
        if (!is_array($m) || !$order instanceof \WC_Order) { return; }
        $order->update_meta_data('_sfm_member_no', (string) $m['member_no']);
        $order->update_meta_data('_sfm_member_tier', (string) $m['tier']);
        $order->update_meta_data('_sfm_member_name', (string) $m['name']);
    }

    public static function orderAdmin($order): void {
        if (!$order instanceof \WC_Order || !($no = (string) $order->get_meta('_sfm_member_no'))) { return; }
        echo '<p><strong>' . esc_html__('SFM4U member', 'sfm4u-wholesale') . ':</strong> ' . esc_html($no . ' — ' . $order->get_meta('_sfm_member_name') . ' (' . Members::tierLabel((string) $order->get_meta('_sfm_member_tier')) . ')') . '</p>';
    }

    /* ---------------- Admin (standalone AlgiBazar connector only) ---------------- */

    public static function adminMenu(): void {
        add_submenu_page('woocommerce', __('SFM4U Business OS', 'sfm4u-wholesale'), __('SFM4U Business OS', 'sfm4u-wholesale'), 'manage_options', 'sfm-wr-link', [self::class, 'adminPage']);
    }

    public static function adminActions(): void {
        if (($_GET['page'] ?? '') !== 'sfm-wr-link' || !current_user_can('manage_options')) { return; }
        $k = 'sfm_bos_admin_msg_' . get_current_user_id();
        try {
            if (isset($_POST['sfm_wr_pair_start'])) {
                check_admin_referer('sfm_bos_admin');
                wp_redirect(CardLink::startPair(self::field('master_url')));
                exit;
            }
            if (isset($_GET['sfm_pair_code'])) {
                $site = CardLink::finishPair(sanitize_text_field(wp_unslash((string) $_GET['sfm_pair_code'])), sanitize_text_field(wp_unslash((string) ($_GET['sfm_pair_state'] ?? ''))));
                set_transient($k, ['success', sprintf(__('Connected to %s.', 'sfm4u-wholesale'), $site)], 300);
                wp_safe_redirect(admin_url('admin.php?page=sfm-wr-link'));
                exit;
            }
            if (isset($_POST['sfm_bos_save'])) {
                check_admin_referer('sfm_bos_admin');
                $en = isset($_POST['benefits_en']) ? sanitize_textarea_field(wp_unslash((string) $_POST['benefits_en'])) : '';
                $bn = isset($_POST['benefits_bn']) ? sanitize_textarea_field(wp_unslash((string) $_POST['benefits_bn'])) : '';
                update_option(self::OPT, ['checkout_card' => !empty($_POST['checkout_card']), 'benefits_en' => $en !== '' ? $en : self::defaultBenefits('en'), 'benefits_bn' => $bn !== '' ? $bn : self::defaultBenefits('bn')], false);
                set_transient($k, ['success', __('Saved.', 'sfm4u-wholesale')], 300);
                wp_safe_redirect(admin_url('admin.php?page=sfm-wr-link'));
                exit;
            }
            if (isset($_POST['sfm_bos_test'])) {
                check_admin_referer('sfm_bos_admin');
                $p = CardLink::call('/ping');
                set_transient($k, ['success', sprintf(__('Connected to %s.', 'sfm4u-wholesale'), (string) ($p['site'] ?? ''))], 300);
                wp_safe_redirect(admin_url('admin.php?page=sfm-wr-link'));
                exit;
            }
        } catch (DomainException $e) {
            set_transient($k, ['error', $e->getMessage()], 300);
            wp_safe_redirect(admin_url('admin.php?page=sfm-wr-link'));
            exit;
        }
    }

    public static function adminPage(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $k = 'sfm_bos_admin_msg_' . get_current_user_id();
        echo '<div class="wrap"><h1>' . esc_html__('SFM4U Business OS — member link', 'sfm4u-wholesale') . '</h1>';
        if (is_array($m = get_transient($k))) { delete_transient($k); echo '<div class="notice notice-' . esc_attr((string) $m[0]) . '"><p>' . esc_html((string) $m[1]) . '</p></div>'; }
        $s = self::settings();
        echo '<h2>' . esc_html__('1. Connect to the SFM4U account site', 'sfm4u-wholesale') . '</h2>';
        if (self::on()) {
            echo '<p>✅ ' . esc_html(sprintf(__('Connected to %s', 'sfm4u-wholesale'), (string) CardLink::master()['url'])) . '</p><form method="post">';
            wp_nonce_field('sfm_bos_admin');
            echo '<button class="button" name="sfm_bos_test" value="1">' . esc_html__('Test the connection', 'sfm4u-wholesale') . '</button></form>';
        } else {
            echo '<p>' . esc_html__('Press the button. You will go to account.sfm4u.com, approve there as administrator, and come back here connected. No key to copy.', 'sfm4u-wholesale') . '</p><form method="post">';
            wp_nonce_field('sfm_bos_admin');
            echo '<input type="url" name="master_url" value="https://account.sfm4u.com" class="regular-text" required> <button class="button button-primary" name="sfm_wr_pair_start" value="1">' . esc_html__('Connect automatically', 'sfm4u-wholesale') . '</button></form>';
        }
        echo '<h2>' . esc_html__('2. Settings', 'sfm4u-wholesale') . '</h2><form method="post">';
        wp_nonce_field('sfm_bos_admin');
        echo '<p><label><input type="checkbox" name="checkout_card" value="1"' . checked(!empty($s['checkout_card']), true, false) . '> ' . esc_html__('Show "SFM4U member card" box at checkout (classic checkout)', 'sfm4u-wholesale') . '</label></p>'
            . '<p><strong>' . esc_html__('What members get — English', 'sfm4u-wholesale') . '</strong><br><textarea name="benefits_en" rows="9" class="large-text">' . esc_textarea($s['benefits_en']) . '</textarea></p>'
            . '<p><strong>' . esc_html__('What members get — Bangla', 'sfm4u-wholesale') . '</strong><br><textarea name="benefits_bn" rows="9" class="large-text">' . esc_textarea($s['benefits_bn']) . '</textarea></p>'
            . '<p class="description">' . esc_html__('Leave a box empty to go back to the default text.', 'sfm4u-wholesale') . '</p>'
            . '<button class="button button-primary" name="sfm_bos_save" value="1">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></form>';
        echo '<h2>' . esc_html__('3. Where sellers see it', 'sfm4u-wholesale') . '</h2><p>' . esc_html__('My Account → SFM4U membership, and a link in the seller dashboard. Plan requests arrive at account.sfm4u.com → SFM4U Members → Plan requests.', 'sfm4u-wholesale') . '</p></div>';
    }
}
