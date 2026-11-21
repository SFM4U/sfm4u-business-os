<?php
namespace SFM4U\Wholesale\Members;

/**
 * Customer side:
 *  - My Account → "My card": the member's cards with QR, and their khata balance.
 *  - Verify link (?sfm_card=<token>) opened by a phone camera: shows only
 *    "valid / not active", first name and card type. No balance, no contact data.
 */
final class MembersFront {
    public const ENDPOINT = 'sfm-card';

    public static function register(): void {
        add_action('init', static function () { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); });
        add_filter('woocommerce_account_menu_items', [self::class, 'menu']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'page']);
        add_filter('query_vars', static function (array $v) { $v[] = self::ENDPOINT; return $v; });
        add_action('template_redirect', [self::class, 'verify'], 1);
        add_action('admin_init', [self::class, 'maybeFlush'], 20);
        add_shortcode('sfm4u_member_card', [self::class, 'shortcodeCard']);
        add_shortcode('sfm4u_card_guide', [self::class, 'shortcodeGuide']);
        add_filter('sfm4u_hub_tiles', [self::class, 'hubTiles']);
    }

    /** Tiles for the SFM4U Account Hub dashboard (filter sfm4u_hub_tiles), only when the pages exist. */
    public static function hubTiles($tiles): array {
        $tiles = is_array($tiles) ? $tiles : [];
        $card = get_page_by_path('membership-card');
        $guide = get_page_by_path('membership-card-guide');
        if ($card && $card->post_status === 'publish') {
            $tiles[] = ['id' => 'sfm-card', 'group' => 'account', 'label' => __('My Membership Card', 'sfm4u-wholesale'), 'icon' => '🪪', 'url' => get_permalink($card), 'status' => 'live'];
        }
        if ($guide && $guide->post_status === 'publish') {
            $tiles[] = ['id' => 'sfm-card-guide', 'group' => 'account', 'label' => __('Card guide & rules', 'sfm4u-wholesale'), 'icon' => '📘', 'url' => get_permalink($guide), 'status' => 'live'];
        }
        $tiles[] = ['id' => 'sfm-card-scan', 'group' => 'business', 'label' => __('Card scan & khata', 'sfm4u-wholesale'), 'icon' => '🔎', 'url' => admin_url('admin.php?page=sfm-wr-scan'), 'status' => 'live', 'roles' => ['administrator', 'shop_manager', 'sfm_cashier']];
        return $tiles;
    }

    /** [sfm4u_member_card]: the member's own card page on any site (no WooCommerce needed). */
    public static function shortcodeCard(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to see your SFM4U membership card.', 'sfm4u-wholesale') . ' <a href="' . esc_url(wp_login_url((string) get_permalink())) . '">' . esc_html__('Log in', 'sfm4u-wholesale') . '</a></p>';
        }
        ob_start();
        self::page();
        return (string) ob_get_clean();
    }

    /** [sfm4u_card_guide]: members-only guide: sample card, what is where, how to check a genuine card, editing, rules. */
    public static function shortcodeGuide(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('This page is for SFM4U members. Please log in.', 'sfm4u-wholesale') . ' <a href="' . esc_url(wp_login_url((string) get_permalink())) . '">' . esc_html__('Log in', 'sfm4u-wholesale') . '</a></p>';
        }
        if (!Members::cardsOf(get_current_user_id()) && !current_user_can(Members::manageCap())) {
            Members::autoIssue(get_current_user_id());
            if (!Members::cardsOf(get_current_user_id())) {
                return '<p>' . esc_html__('This page is for SFM4U card members. Ask at an SFM4U shop to get your card.', 'sfm4u-wholesale') . '</p>';
            }
        }
        nocache_headers();
        self::qrAssets();
        return CardGuide::html();
    }

    public static function maybeFlush(): void {
        if (get_option('sfm_wr_rewrite') !== '1') {
            flush_rewrite_rules(false);
            update_option('sfm_wr_rewrite', '1', false);
        }
    }

    public static function menu(array $items): array {
        $out = [];
        foreach ($items as $k => $v) {
            if ($k === 'customer-logout') { $out[self::ENDPOINT] = __('My card', 'sfm4u-wholesale'); }
            $out[$k] = $v;
        }
        if (!isset($out[self::ENDPOINT])) { $out[self::ENDPOINT] = __('My card', 'sfm4u-wholesale'); }
        return $out;
    }

    private static function qrAssets(): void {
        $entry = dirname(__DIR__, 2) . '/sfm4u-wholesale.php';
        wp_enqueue_script('sfm-wr-qrcode', plugins_url('assets/qrcode.js', $entry), [], '1.4.4', true);
        wp_enqueue_script('sfm-wr-members', plugins_url('assets/members.js', $entry), ['sfm-wr-qrcode'], '0.5.0', true);
        wp_enqueue_style('sfm-wr-members', plugins_url('assets/members.css', $entry), [], '0.3.0');
        Members::enqueueScanner();
    }

    public static function page(): void {
        $uid = get_current_user_id();
        if (!$uid) { return; }
        nocache_headers();
        self::qrAssets();
        do_action('sfm4u_member_card_top', $uid);
        $cards = Members::cardsOf($uid);
        if (!$cards) {
            Members::autoIssue($uid);
            $cards = Members::cardsOf($uid);
        }
        if (!$cards) {
            echo '<p>' . esc_html__('You do not have a member card yet. Ask at the shop counter to get one.', 'sfm4u-wholesale') . '</p>';
            return;
        }
        self::handleOwnerActions($uid, $cards);
        $cards = Members::cardsOf($uid);
        echo '<style>' . CardView::css() . '</style>';
        foreach ($cards as $card) {
            echo '<div style="margin:12px 0 18px' . ($card['status'] !== 'active' ? ';filter:grayscale(1);opacity:.6' : '') . '">' . CardView::render($card, 'en', true) . '</div>';
            if ($card['status'] !== 'active') {
                echo '<p><strong>' . esc_html__('This card is locked. To unlock it, visit or call the shop that issued it.', 'sfm4u-wholesale') . '</strong></p>';
            }
        }
        echo '<p><button type="button" class="button" onclick="window.print()">' . esc_html__('Print my card', 'sfm4u-wholesale') . '</button> <small>' . esc_html__('A new plastic card is printed at the shop (printing has a cost).', 'sfm4u-wholesale') . '</small></p>';
        echo '<form method="post" enctype="multipart/form-data" style="margin:12px 0">';
        wp_nonce_field('sfm_wr_my_photo');
        echo '<p><label>' . esc_html__('Change my photo (camera or gallery)', 'sfm4u-wholesale') . '<br><input type="file" name="sfm_wr_photo" accept="image/jpeg,image/png,image/webp" capture="user"></label> <button class="button">' . esc_html__('Upload', 'sfm4u-wholesale') . '</button></p></form>';
        self::detailsForm($uid, $cards);
        $sig = Members::signature($uid);
        echo '<details class="sfm-wr-mysign" style="margin:16px 0;padding:10px 14px;border:1px solid #ddd;border-radius:10px"' . ($sig === '' ? ' open' : '') . '><summary><strong>'
            . esc_html__('My signature', 'sfm4u-wholesale') . '</strong></summary><form method="post">';
        wp_nonce_field('sfm_wr_my_sign');
        echo '<input type="hidden" name="sfm_wr_my_sign" value="1"><p><small>' . esc_html__('Sign with your finger in the white box. Not happy? Press Clear and sign again, then Save. You can change it any time.', 'sfm4u-wholesale') . '</small></p>'
            . Members::signaturePadField($sig, false)
            . '<p><button class="button">' . esc_html__('Save my signature', 'sfm4u-wholesale') . '</button></p></form></details>';
        echo '<p><a href="#sfm-card-guide" onclick="var g=document.getElementById(\'sfm-card-guide\');if(g){g.open=true;}">' . esc_html__('How the card works, rules and how to check a genuine card', 'sfm4u-wholesale') . '</a></p>';
        echo '<details id="sfm-card-guide" style="margin:16px 0;padding:10px 14px;border:1px solid #ddd;border-radius:10px"><summary><strong>' . esc_html__('Card guide and rules', 'sfm4u-wholesale') . '</strong></summary>' . CardGuide::html() . '</details>';
        echo '<form method="post" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__('Lock your card now? Only the shop can unlock it.', 'sfm4u-wholesale') . '">';
        wp_nonce_field('sfm_wr_my_lock');
        echo '<p><button class="button" name="sfm_wr_lock" value="1">' . esc_html__('My card is lost: lock it', 'sfm4u-wholesale') . '</button></p></form>';
        $stores = Members::stores();
        $by = Members::balancesByStore($uid);
        echo '<h3>' . esc_html__('Khata balance (due)', 'sfm4u-wholesale') . '</h3>';
        if (!$by) { echo '<p>' . esc_html(Members::money(0)) . '</p>'; }
        foreach ($by as $st => $bal) { echo '<p>' . esc_html($stores[$st] ?? $st) . ': <strong>' . esc_html(Members::money($bal)) . '</strong></p>'; }
        if (isset($_POST['sfm_wr_my_mode']) && check_admin_referer('sfm_wr_my_mode')) {
            update_user_meta($uid, 'sfm_wr_points_mode', sanitize_key(wp_unslash($_POST['sfm_wr_my_mode'])) === 'company' ? 'company' : 'shop');
        }
        $mode = Rewards::mode($uid);
        echo '<h3>' . esc_html__('Points', 'sfm4u-wholesale') . '</h3>';
        $pb = Rewards::balances($uid);
        if (!$pb) { echo '<p>0</p>'; }
        foreach ($pb as $pool => $p) {
            $name = $pool === 'company' ? __('SFM4U company reward', 'sfm4u-wholesale') : ($stores[$pool] ?? ($pool === 'online' ? __('Online orders', 'sfm4u-wholesale') : $pool));
            echo '<p>' . esc_html($name) . ': <strong>' . esc_html((string) $p) . '</strong></p>';
        }
        echo '<form method="post">';
        wp_nonce_field('sfm_wr_my_mode');
        echo '<p>' . esc_html__('Collect my points:', 'sfm4u-wholesale') . '<br><label><input type="radio" name="sfm_wr_my_mode" value="shop"' . checked($mode, 'shop', false) . '> ' . esc_html__('separately in each shop', 'sfm4u-wholesale') . '</label><br><label><input type="radio" name="sfm_wr_my_mode" value="company"' . checked($mode, 'company', false) . '> ' . esc_html__('one company reward for all shops', 'sfm4u-wholesale') . '</label></p><p><button class="button">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        if (Rewards::walletOn()) {
            echo '<div class="woocommerce-info">' . esc_html__('New: you can now keep money on your SFM4U card and pay with it.', 'sfm4u-wholesale') . '</div>';
            echo '<p>' . esc_html__('Wallet', 'sfm4u-wholesale') . ': <strong>' . esc_html(Members::money(Rewards::walletBalance($uid))) . '</strong></p>';
        }
        $rows = Members::entries($uid, 'main', 10);
        if ($rows) {
            echo '<table class="shop_table"><thead><tr><th>' . esc_html__('Date', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Amount', 'sfm4u-wholesale')
                . '</th><th>' . esc_html__('Balance', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td>' . esc_html(get_date_from_gmt($r['created_at'], 'd M Y')) . '</td><td>' . esc_html(Members::money((int) $r['amount_minor']))
                    . '</td><td>' . esc_html(Members::money((int) $r['balance_minor'])) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p><small>' . esc_html__('Show this card at the counter. Keep it private: anyone with the QR can be identified as you.', 'sfm4u-wholesale') . '</small></p>';
    }

    /** The member fills their card details from their own NID: first fill + one correction, then only the shop can change them. */
    private static function detailsForm(int $uid, array $cards): void {
        $left = Members::selfEditsLeft($uid);
        $d = Members::details($uid);
        $linked = !empty($cards[0]['id_last4']);
        echo '<details class="sfm-wr-mydetails" style="margin:16px 0;padding:10px 14px;border:1px solid #ddd;border-radius:10px"' . ($left === Members::SELF_EDIT_LIMIT ? ' open' : '') . '><summary><strong>'
            . esc_html__('My card details (as on my NID)', 'sfm4u-wholesale') . '</strong></summary>';
        if ($left <= 0) {
            echo '<p>' . esc_html__('You have already corrected your details. To change anything now, visit or call the shop with your NID.', 'sfm4u-wholesale') . '</p></details>';
            return;
        }
        echo '<p><small>' . esc_html(sprintf(_n('You can save these details %d more time. After that, only the shop can change them.', 'You can save these details %d more times. After that, only the shop can change them.', $left, 'sfm4u-wholesale'), $left)) . '</small></p>';
        echo '<form method="post">';
        wp_nonce_field('sfm_wr_my_details');
        echo '<input type="hidden" name="sfm_wr_my_details" value="1">' . Members::nidScanField();
        $rows = ['sfm_wr_name_bn' => __('Name in Bangla', 'sfm4u-wholesale'), 'sfm_wr_name_en' => __('Name in English', 'sfm4u-wholesale'),
            'sfm_wr_father' => __("Father's name (Bangla)", 'sfm4u-wholesale'), 'sfm_wr_father_en' => __("Father's name (English)", 'sfm4u-wholesale'),
            'sfm_wr_mother' => __("Mother's name", 'sfm4u-wholesale'), 'sfm_wr_dob' => __('Date of birth', 'sfm4u-wholesale'),
            'sfm_wr_address' => __('Address', 'sfm4u-wholesale'), 'billing_phone' => __('Mobile number', 'sfm4u-wholesale'), 'sfm_wr_district' => __('Home district', 'sfm4u-wholesale')];
        foreach ($rows as $k => $label) {
            echo '<p><label>' . esc_html($label) . '<br>';
            if ($k === 'sfm_wr_dob') {
                echo '<input type="date" name="' . esc_attr($k) . '" value="' . esc_attr($d[$k] ?? '') . '">';
            } elseif ($k === 'sfm_wr_address') {
                echo '<textarea name="' . esc_attr($k) . '" rows="2" style="width:100%">' . esc_textarea($d[$k] ?? '') . '</textarea>';
            } elseif ($k === 'sfm_wr_district') {
                echo '<select name="' . esc_attr($k) . '"><option value="">' . esc_html__('Automatic from address', 'sfm4u-wholesale') . '</option>';
                foreach (BdMap::choices() as $key => $lbl) { echo '<option value="' . esc_attr($key) . '"' . selected($d[$k] ?? '', $key, false) . '>' . esc_html($lbl) . '</option>'; }
                echo '</select>';
            } else {
                echo '<input type="' . ($k === 'billing_phone' ? 'tel' : 'text') . '" name="' . esc_attr($k) . '" value="' . esc_attr($d[$k] ?? '') . '" style="width:100%">';
            }
            echo '</label></p>';
        }
        if (!$linked) {
            echo '<p><label style="margin-right:1em"><input type="radio" name="id_type" value="nid" checked> NID</label><label><input type="radio" name="id_type" value="brn"> ' . esc_html__('Birth registration (17 digits)', 'sfm4u-wholesale') . '</label></p>';
            echo '<p><label>' . esc_html__('NID or birth registration number', 'sfm4u-wholesale') . '<br><input name="national_id" inputmode="numeric" autocomplete="off" style="width:100%"></label>'
                . '<label><input type="checkbox" name="id_consent" value="yes"> ' . esc_html__('This is my own number and I agree to link it to my card.', 'sfm4u-wholesale') . '</label></p>';
        }
        echo '<p><button class="button">' . esc_html__('Save my details', 'sfm4u-wholesale') . '</button></p></form></details>';
    }

    /** Owner actions on their own cards: change photo, lock a lost card, fill details. */
    private static function handleOwnerActions(int $uid, array $cards): void {
        if (isset($_POST['sfm_wr_my_sign']) && check_admin_referer('sfm_wr_my_sign')) {
            $raw = isset($_POST['sfm_wr_signature']) && is_string($_POST['sfm_wr_signature']) ? wp_unslash($_POST['sfm_wr_signature']) : '';
            $sig = $raw !== '' ? Members::cleanSignature($raw) : '';
            if ($sig === '') {
                echo '<div class="woocommerce-error">' . esc_html__('Please sign in the box first, then press Save.', 'sfm4u-wholesale') . '</div>';
            } else {
                update_user_meta($uid, Members::SIGNATURE_META, $sig);
                Members::logChange($uid, 'self', ['signature']);
                echo '<div class="woocommerce-message">' . esc_html__('Your signature is saved on your card.', 'sfm4u-wholesale') . '</div>';
            }
        }
        if (isset($_POST['sfm_wr_my_details']) && check_admin_referer('sfm_wr_my_details')) {
            try {
                if (Members::selfEditsLeft($uid) <= 0) { throw new \DomainException(__('You have already corrected your details. Please contact the shop.', 'sfm4u-wholesale')); }
                $rawId = isset($_POST['national_id']) && is_string($_POST['national_id']) ? trim(sanitize_text_field(wp_unslash($_POST['national_id']))) : '';
                $nid = null;
                if ($rawId !== '' && empty($cards[0]['id_last4'])) {
                    $nid = Members::parseNationalId($rawId, (($_POST['id_type'] ?? '') === 'brn') ? 'brn' : 'nid');
                    if (!$nid) { throw new \DomainException(__('Enter a valid NID (10, 13 or 17 digits) or birth registration number (17 digits).', 'sfm4u-wholesale')); }
                    if (($_POST['id_consent'] ?? '') !== 'yes') { throw new \DomainException(__('Please tick the box to confirm this is your own number.', 'sfm4u-wholesale')); }
                }
                $changed = Members::saveDetails($uid, $_POST, Members::SELF_FIELDS, 'self');
                if ($nid) { Members::linkNationalId($uid, $nid); Members::logChange($uid, 'self', ['national_id']); $changed[] = 'national_id'; }
                if ($changed) {
                    update_user_meta($uid, 'sfm_wr_self_edits', (int) get_user_meta($uid, 'sfm_wr_self_edits', true) + 1);
                    echo '<div class="woocommerce-message">' . esc_html__('Your details are saved. Your card is updated.', 'sfm4u-wholesale') . '</div>';
                } else {
                    echo '<div class="woocommerce-info">' . esc_html__('Nothing was changed.', 'sfm4u-wholesale') . '</div>';
                }
            } catch (\DomainException $e) {
                echo '<div class="woocommerce-error">' . esc_html($e->getMessage()) . '</div>';
            }
        }
        if (isset($_POST['sfm_wr_lock']) && check_admin_referer('sfm_wr_my_lock')) {
            foreach ($cards as $c) { Members::setStatus((int) $c['id'], 'suspended'); }
            update_user_meta($uid, 'sfm_wr_locked_at', current_time('mysql', true));
            echo '<div class="woocommerce-message">' . esc_html__('Your card is locked.', 'sfm4u-wholesale') . '</div>';
        }
        if (isset($_FILES['sfm_wr_photo']) && !empty($_FILES['sfm_wr_photo']['name']) && check_admin_referer('sfm_wr_my_photo')) {
            $f = $_FILES['sfm_wr_photo'];
            $type = wp_check_filetype_and_ext($f['tmp_name'] ?? '', $f['name'] ?? '');
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || (int) ($f['size'] ?? 0) > 5 * 1024 * 1024 || !in_array($type['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                echo '<div class="woocommerce-error">' . esc_html__('Upload a JPG, PNG or WebP photo under 5 MB.', 'sfm4u-wholesale') . '</div>';
                return;
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $id = media_handle_upload('sfm_wr_photo', 0, ['post_author' => $uid]);
            if (is_wp_error($id)) {
                echo '<div class="woocommerce-error">' . esc_html($id->get_error_message()) . '</div>';
                return;
            }
            global $wpdb;
            $wpdb->update(Members::cards(), ['photo_id' => (int) $id], ['user_id' => $uid], ['%d'], ['%d']);
            echo '<div class="woocommerce-message">' . esc_html__('Photo updated on your card.', 'sfm4u-wholesale') . '</div>';
        }
    }

    public static function verify(): void {
        if (!isset($_GET['sfm_card']) || !is_string($_GET['sfm_card'])) { return; }
        $token = strtolower(sanitize_text_field(wp_unslash($_GET['sfm_card'])));
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        if (current_user_can(Members::CAP)) {
            wp_safe_redirect(add_query_arg(['page' => 'sfm-wr-scan'], admin_url('admin.php')) . '#scan=' . rawurlencode($token));
            exit;
        }
        $card = preg_match('/^[a-f0-9]{32}$/D', $token) ? Members::find('token', $token) : null;
        $user = $card ? get_userdata((int) $card['user_id']) : null;
        $ok = $card && $user && $card['status'] === 'active';
        $first = $user ? strtok((string) $user->display_name, ' ') : '';
        status_header($card ? 200 : 404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html(get_bloginfo('name')) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;background:#070c0b;color:#f4f0e6;display:grid;place-items:center;min-height:100vh;margin:0}'
            . '.b{border:1px solid #ddc17b55;border-radius:18px;padding:28px;text-align:center;max-width:320px}.ok{color:#25dfc4}.no{color:#ff8a80}</style></head><body><div class="b">'
            . '<p>' . esc_html(get_bloginfo('name')) . '</p>';
        if ($ok) {
            echo '<h1 class="ok">✓ ' . esc_html__('Valid member', 'sfm4u-wholesale') . '</h1><p><b>' . esc_html($first) . '</b><br>' . esc_html(Members::tierLabel($card['tier'])) . '</p>';
        } else {
            echo '<h1 class="no">' . esc_html__('Not an active card', 'sfm4u-wholesale') . '</h1>';
        }
        echo '</div></body></html>';
        exit;
    }
}
