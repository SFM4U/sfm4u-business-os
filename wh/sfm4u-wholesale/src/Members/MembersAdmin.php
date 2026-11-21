<?php
namespace SFM4U\Wholesale\Members;

use DomainException;

/**
 * Admin screens (wp-admin → "SFM4U Store"):
 *  - Card scan & khata: scan or type a card, see the member, balance and history, record dues and payments.
 *  - Member cards: issue regular / business / premium cards, print them.
 *  - Product QR labels: print QR stickers with name and price, any number of copies.
 */
final class MembersAdmin {
    private static string $receiptLink = '';
    private const SLUG = 'sfm-wr-scan';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'pairActions']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void {
        $title = class_exists('WooCommerce') ? __('SFM4U Store', 'sfm4u-wholesale') : __('SFM4U Members', 'sfm4u-wholesale');
        add_menu_page($title, $title, Members::CAP,
            self::SLUG, [self::class, 'scanPage'], 'dashicons-id-alt', 56);
        add_submenu_page(self::SLUG, __('Card scan & khata', 'sfm4u-wholesale'), __('Card scan & khata', 'sfm4u-wholesale'),
            Members::CAP, self::SLUG, [self::class, 'scanPage']);
        add_submenu_page(self::SLUG, __('Due list', 'sfm4u-wholesale'), __('Due list', 'sfm4u-wholesale'),
            Members::CAP, 'sfm-wr-due', [self::class, 'duePage']);
        add_submenu_page(self::SLUG, __('Member cards', 'sfm4u-wholesale'), __('Member cards', 'sfm4u-wholesale'),
            Members::manageCap(), 'sfm-wr-cards', [self::class, 'cardsPage']);
        add_submenu_page(self::SLUG, __('Points & wallet', 'sfm4u-wholesale'), __('Points & wallet', 'sfm4u-wholesale'),
            Members::manageCap(), 'sfm-wr-rewards', [self::class, 'rewardsPage']);
        add_submenu_page(self::SLUG, __('Shops', 'sfm4u-wholesale'), __('Shops', 'sfm4u-wholesale'),
            Members::manageCap(), 'sfm-wr-shops', [self::class, 'shopsPage']);
        add_submenu_page(self::SLUG, __('Card guide text', 'sfm4u-wholesale'), __('Card guide text', 'sfm4u-wholesale'),
            'manage_options', 'sfm-wr-guide', [self::class, 'guidePage']);
        add_submenu_page(self::SLUG, __('Account link', 'sfm4u-wholesale'), __('Account link', 'sfm4u-wholesale'),
            'manage_options', 'sfm-wr-link', [self::class, 'linkPage']);
        if (class_exists('WooCommerce')) {
            add_submenu_page(self::SLUG, __('Product QR labels', 'sfm4u-wholesale'), __('Product QR labels', 'sfm4u-wholesale'),
                Members::manageCap(), 'sfm-wr-labels', [self::class, 'labelsPage']);
        }
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'sfm-wr-') === false) { return; }
        $entry = dirname(__DIR__, 2) . '/sfm4u-wholesale.php';
        wp_enqueue_script('sfm-wr-qrcode', plugins_url('assets/qrcode.js', $entry), [], '1.4.4', true);
        wp_enqueue_script('sfm-wr-members', plugins_url('assets/members.js', $entry), ['sfm-wr-qrcode'], '0.5.0', true);
        wp_enqueue_style('sfm-wr-members', plugins_url('assets/members.css', $entry), [], '0.3.0');
        Members::enqueueScanner();
        if ($hook === 'toplevel_page_' . self::SLUG || strpos($hook, 'sfm-wr-cards') !== false) {
            wp_enqueue_media();
        }
    }

    private static function post(string $k): string {
        return isset($_POST[$k]) && is_string($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : '';
    }

    private static function notice(string $msg, string $type = 'success'): void {
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($msg) . '</p></div>';
    }

    /* ---------------- Card scan & khata ---------------- */

    public static function scanPage(): void {
        if (!current_user_can(Members::CAP)) { wp_die(esc_html__('You cannot use the cashier screen.', 'sfm4u-wholesale')); }
        if (isset($_GET['receipt']) && is_string($_GET['receipt'])) {
            $ref = Receipt::parseRef(sanitize_text_field(wp_unslash($_GET['receipt'])));
            $rc = $ref ? Receipt::load($ref[0], $ref[1]) : null;
            echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Receipt', 'sfm4u-wholesale') . '</h1>';
            if (!$rc) { echo '<p>' . esc_html__('Receipt not found.', 'sfm4u-wholesale') . '</p></div>'; return; }
            if (!current_user_can(Members::manageCap()) && $rc['store'] !== Members::currentStore()) { echo '<p>' . esc_html__('This receipt belongs to another shop.', 'sfm4u-wholesale') . '</p></div>'; return; }
            echo '<style>' . Receipt::css() . '</style>' . Receipt::html($rc)
                . '<p><button type="button" class="button button-primary" onclick="window.print()">' . esc_html__('Print receipt', 'sfm4u-wholesale') . '</button> '
                . '<a class="button" href="' . esc_url(add_query_arg(['page' => 'sfm-wr-scan'], admin_url('admin.php'))) . '">' . esc_html__('Back', 'sfm4u-wholesale') . '</a></p></div>';
            return;
        }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Card scan & khata', 'sfm4u-wholesale') . '</h1>';
        $store = Members::currentStore();
        $stores = Members::stores();
        if ($store === '') {
            self::notice(__('You are not assigned to a shop yet. Ask the manager to set your shop.', 'sfm4u-wholesale'), 'error');
            echo '</div>';
            return;
        }
        if (current_user_can(Members::manageCap()) && count($stores) > 1) {
            echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="sfm-wr-scan"><label>' . esc_html__('Shop', 'sfm4u-wholesale') . ' <select name="store" onchange="this.form.submit()">';
            foreach ($stores as $code => $name) { echo '<option value="' . esc_attr($code) . '"' . selected($code, $store, false) . '>' . esc_html($name) . '</option>'; }
            echo '</select></label></form>';
        }
        /* translators: %s: shop name */
        echo '<p><strong>' . esc_html(sprintf(__('Shop: %s', 'sfm4u-wholesale'), $stores[$store])) . '</strong> · ' . esc_html__('This screen shows only this shop\'s khata.', 'sfm4u-wholesale') . '</p>';
        $card = null;
        $remote = null;
        try {
            if (isset($_POST['sfm_wr_purchase']) || isset($_POST['sfm_wr_redeem']) || isset($_POST['sfm_wr_mode'])) {
                check_admin_referer('sfm_wr_rewards');
                $card = Members::find('id', absint($_POST['card_id'] ?? 0));
                if (!$card || $card['status'] !== 'active') { throw new DomainException(__('Card not found.', 'sfm4u-wholesale')); }
                $uid = (int) $card['user_id'];
                if (isset($_POST['sfm_wr_mode'])) {
                    update_user_meta($uid, 'sfm_wr_points_mode', self::post('sfm_wr_mode') === 'company' ? 'company' : 'shop');
                    self::notice(__('Points choice saved.', 'sfm4u-wholesale'));
                } elseif (isset($_POST['sfm_wr_purchase'])) {
                    $minor = Members::parseMoney(self::post('amount'), Members::decimals());
                    $r = Rewards::purchase($uid, $store, $minor, '', self::post('note'), get_current_user_id());
                    /* translators: 1: points earned, 2: points balance */
                    self::notice(sprintf(__('Purchase saved. Points earned: %1$d. Points now: %2$d', 'sfm4u-wholesale'), $r['points'], $r['balance']));
                    self::$receiptLink = Receipt::url('P', (int) $r['id']);
                    $gift = Tiers::checkGift($uid, $store, $minor, 'P-' . (int) $r['id']);
                    if ($gift !== '') { self::notice('🎁 ' . sprintf(__('Premium gift: give "%s" to this customer.', 'sfm4u-wholesale'), $gift)); }
                } else {
                    $pool = Rewards::poolFor($uid, $store);
                    $pts = absint(self::post('points'));
                    $r = Rewards::redeem($uid, $pool, $pts, self::post('note'), get_current_user_id());
                    $val = Rewards::value($pts, Rewards::rate($pool)['value'], Members::decimals());
                    /* translators: 1: points used, 2: reward value */
                    self::notice(sprintf(__('%1$d points used. Give a reward of %2$s.', 'sfm4u-wholesale'), $pts, Members::money($val)));
                }
            } elseif (isset($_POST['sfm_wr_black_pay']) || isset($_POST['sfm_wr_gift_given'])) {
                check_admin_referer('sfm_wr_plan');
                if (!Members::canAdjust()) { throw new DomainException(__('Only a manager can do this.', 'sfm4u-wholesale')); }
                $card = Members::find('id', absint($_POST['card_id'] ?? 0));
                if (!$card) { throw new DomainException(__('Card not found.', 'sfm4u-wholesale')); }
                if (isset($_POST['sfm_wr_black_pay'])) {
                    $until = Tiers::recordBlackPayment((int) $card['user_id'], absint($_POST['months'] ?? 1), self::post('method'), self::post('ref'), get_current_user_id());
                    self::notice(sprintf(__('Business Black paid until %s.', 'sfm4u-wholesale'), $until));
                } else {
                    Tiers::markGiftGiven((int) $card['user_id'], absint($_POST['sfm_wr_gift_given']), get_current_user_id());
                    self::notice(__('Gift marked as given.', 'sfm4u-wholesale'));
                }
            } elseif (isset($_POST['sfm_wr_details'])) {
                check_admin_referer('sfm_wr_details');
                if (!Members::canSeeDetails()) { throw new DomainException(__('Only a manager can change customer details.', 'sfm4u-wholesale')); }
                $card = Members::find('id', absint($_POST['card_id'] ?? 0));
                if (!$card) { throw new DomainException(__('Card not found.', 'sfm4u-wholesale')); }
                Members::saveDetails((int) $card['user_id'], $_POST, Members::DETAIL_FIELDS, 'staff');
                if (self::post('sfm_wr_reset_self') === '1') {
                    update_user_meta((int) $card['user_id'], 'sfm_wr_self_edits', max(0, (int) get_user_meta((int) $card['user_id'], 'sfm_wr_self_edits', true) - 1));
                }
                if (self::post('sfm_wr_signature_clear') === '1') {
                    delete_user_meta((int) $card['user_id'], Members::SIGNATURE_META);
                } else {
                    $sigRaw = isset($_POST['sfm_wr_signature']) && is_string($_POST['sfm_wr_signature']) ? wp_unslash($_POST['sfm_wr_signature']) : '';
                    if ($sigRaw !== '') {
                        $sig = Members::cleanSignature($sigRaw);
                        if ($sig === '') { throw new DomainException(__('The signature could not be read. Please sign again.', 'sfm4u-wholesale')); }
                        update_user_meta((int) $card['user_id'], Members::SIGNATURE_META, $sig);
                    }
                }
                $rawId = self::post('national_id');
                if ($rawId !== '') {
                    $nid = Members::parseNationalId($rawId, self::post('id_type') === 'brn' ? 'brn' : 'nid');
                    if (!$nid) { throw new DomainException(__('Enter a valid NID (10, 13 or 17 digits) or birth registration number (17 digits).', 'sfm4u-wholesale')); }
                    if (self::post('id_consent') !== 'yes') { throw new DomainException(__('The customer must agree before we link their NID or birth registration number.', 'sfm4u-wholesale')); }
                    Members::linkNationalId((int) $card['user_id'], $nid);
                    $card = Members::find('id', (int) $card['id']);
                }
                if (in_array($card['tier'], ['business', 'black'], true) && isset($_POST['business_name'])) {
                    Members::setBusinessName((int) $card['id'], self::post('business_name'));
                    $card = Members::find('id', (int) $card['id']);
                }
                self::notice(__('Customer details saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_action'])) {
                check_admin_referer('sfm_wr_khata');
                $card = Members::find('id', absint($_POST['card_id'] ?? 0));
                if (!$card) { throw new DomainException(__('Card not found.', 'sfm4u-wholesale')); }
                if ($card['status'] !== 'active') { throw new DomainException(__('This card is suspended.', 'sfm4u-wholesale')); }
                $kind = self::post('sfm_wr_action');
                if ($kind === 'adjust' && !Members::canAdjust()) { throw new DomainException(__('Only a manager can correct the khata.', 'sfm4u-wholesale')); }
                $minor = Members::parseMoney(self::post('amount'), Members::decimals());
                $method = $kind === 'payment' ? self::post('method') : '';
                $row = Members::record((int) $card['user_id'], (int) $card['id'], $kind, $minor, $method,
                    self::post('note'), get_current_user_id(), self::post('direction') === 'down' ? 'down' : 'up', $store);
                /* translators: %s: new balance */
                self::notice(sprintf(__('Saved. New balance: %s', 'sfm4u-wholesale'), Members::money($row['balance_minor'])));
                self::$receiptLink = Receipt::url('K', (int) $row['id']);
            } elseif (isset($_POST['sfm_wr_adopt'])) {
                check_admin_referer('sfm_wr_scan');
                $m = CardLink::remoteLookup(self::post('sfm_wr_adopt'));
                if (!$m) { throw new DomainException(__('Card not found here or on the SFM4U account site.', 'sfm4u-wholesale')); }
                $card = Members::bestCardFor(CardLink::adoptRemote($m), $store) ?: Members::cardsOf(Members::userByMemberNo((string) $m['member_no'], Members::isLinkedShop()))[0] ?? null;
                self::notice(__('Added as a customer of this shop. Khata and points can start now.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['scan'])) {
                check_admin_referer('sfm_wr_scan');
                [$type, $value] = Members::parseScan(self::post('scan'));
                if ($type === 'invalid') { throw new DomainException(__('This is not a valid SFM4U card.', 'sfm4u-wholesale')); }
                if ($type === 'member_no') {
                    $uidM = Members::userByMemberNo($value);
                    $card = $uidM ? Members::bestCardFor($uidM) : null;
                } else {
                    $card = Members::find($type, $value);
                }
                if ($card && $type === 'id_hash') { $card = Members::bestCardFor((int) $card['user_id'], $card['store']) ?: $card; }
                if (!$card && CardLink::role() === 'shop') {
                    $remote = CardLink::remoteLookup(self::post('scan'));
                    if (!$remote) { throw new DomainException(__('Card not found here or on the SFM4U account site.', 'sfm4u-wholesale')); }
                }
                if (!$card && !$remote) { throw new DomainException(__('Card not found.', 'sfm4u-wholesale')); }
            }
        } catch (DomainException $e) {
            self::notice($e->getMessage(), 'error');
        }
        if (self::$receiptLink !== '') {
            echo '<p><a class="button button-primary" href="' . esc_url(self::$receiptLink) . '">' . esc_html__('Print receipt', 'sfm4u-wholesale') . '</a></p>';
        }
        echo '<form method="post" class="sfm-wr-scanbox">';
        wp_nonce_field('sfm_wr_scan');
        echo '<input type="hidden" name="store" value="' . esc_attr($store) . '">';
        echo '<label for="sfm-wr-scan"><strong>' . esc_html__('Scan the card, or type the NID / birth registration number', 'sfm4u-wholesale') . '</strong></label>'
            . '<input id="sfm-wr-scan" name="scan" type="text" autocomplete="off" autofocus inputmode="numeric" class="regular-text"> '
            . '<button class="button button-primary">' . esc_html__('Find', 'sfm4u-wholesale') . '</button></form>';
        if ($card) { self::memberPanel($card); }
        if (!$card && !empty($remote)) { self::remotePanel($remote); }
        echo '</div>';
    }

    /** A member found on the central SFM4U account site (not yet a customer of this shop). */
    private static function remotePanel(array $m): void {
        echo '<div class="sfm-wr-member">';
        if (!empty($m['photo'])) { echo '<img class="sfm-wr-photo" src="' . esc_url((string) $m['photo']) . '" alt="">'; }
        echo '<div>' . (!empty($m['business_name']) ? '<p style="margin:0;font-weight:600">' . esc_html((string) $m['business_name']) . '</p>' : '')
            . '<h2>' . esc_html((string) $m['name']) . (!empty($m['name_bn']) ? ' <small>' . esc_html((string) $m['name_bn']) . '</small>' : '') . '</h2>'
            . '<p><span class="sfm-wr-tier sfm-wr-tier-' . esc_attr((string) $m['tier']) . '">' . esc_html(Members::tierLabel((string) $m['tier'])) . '</span> <code>' . esc_html((string) $m['member_no']) . '</code>'
            . ((string) $m['status'] !== 'active' ? ' <strong class="sfm-wr-bad">' . esc_html__('Suspended', 'sfm4u-wholesale') . '</strong>' : ' <strong style="color:#00703c">' . esc_html__('Valid SFM4U member', 'sfm4u-wholesale') . '</strong>') . '</p>'
            . '<p>' . esc_html__('This member is registered on the SFM4U account site. Check that the photo matches the person.', 'sfm4u-wholesale') . '</p>'
            . '<form method="post">' . wp_nonce_field('sfm_wr_scan', '_wpnonce', true, false) . '<button class="button button-primary" name="sfm_wr_adopt" value="' . esc_attr((string) $m['member_no']) . '">' . esc_html__('Add as a customer of this shop', 'sfm4u-wholesale') . '</button></form>'
            . '<p class="description">' . esc_html__('Khata and points need the member to be a customer of this shop.', 'sfm4u-wholesale') . '</p></div></div>';
    }

    private static function memberPanel(array $card): void {
        $user = get_userdata((int) $card['user_id']);
        if (!$user) { return; }
        $store = Members::currentStore();
        $balance = Members::balance((int) $card['user_id'], $store);
        $all = Members::cardsOf((int) $card['user_id']);
        echo '<div class="sfm-wr-member">';
        echo '<img class="sfm-wr-photo" src="' . esc_url(Members::photoUrl($card)) . '" alt="">';
        echo '<div>' . (!empty($card['business_name']) ? '<p style="margin:0;font-weight:600">' . esc_html($card['business_name']) . '</p>' : '') . '<h2>' . esc_html($user->display_name) . '</h2>';
        echo '<p><span class="sfm-wr-tier sfm-wr-tier-' . esc_attr($card['tier']) . '">' . esc_html(Members::tierLabel($card['tier'])) . '</span> '
            . '<code>' . esc_html(Members::memberNo((int) $card['user_id'])) . '</code> <small>' . esc_html(Members::displayNumber($card)) . '</small>'
            . ($card['status'] !== 'active' ? ' <strong class="sfm-wr-bad">' . esc_html__('Suspended', 'sfm4u-wholesale') . '</strong>' : '') . '</p>';
        if (Members::canSeeDetails()) {
            $phone = get_user_meta($user->ID, 'billing_phone', true);
            echo '<p>' . esc_html($user->user_email) . ($phone ? ' · ' . esc_html($phone) : '') . '</p>';
        }
        $price = in_array($card['tier'], ['business', 'black'], true) ? __('Wholesale price', 'sfm4u-wholesale') : __('Retail price', 'sfm4u-wholesale');
        echo '<p>' . esc_html__('Price for this customer:', 'sfm4u-wholesale') . ' <strong>' . esc_html($price) . '</strong></p>';
        if (count($all) > 1) {
            echo '<p>' . esc_html__('Other cards:', 'sfm4u-wholesale') . ' ';
            foreach ($all as $c) { if ((int) $c['id'] !== (int) $card['id']) { echo esc_html(Members::tierLabel($c['tier'])) . ' '; } }
            echo '</p>';
        }
        echo '</div><div class="sfm-wr-balance ' . ($balance > 0 ? 'owes' : 'clear') . '"><span>'
            . esc_html__('Khata balance (due)', 'sfm4u-wholesale') . '</span><strong>' . esc_html(Members::money($balance)) . '</strong></div></div>';

        if (Members::canSeeDetails()) { self::detailsBox((int) $card['user_id'], $card); }
        self::rewardsBox($card, $store);
        self::planBox($card);
        if ($card['status'] === 'active') {
            echo '<div class="sfm-wr-forms">';
            self::entryForm($card, 'payment', __('Take payment', 'sfm4u-wholesale'), true);
            self::entryForm($card, 'charge', __('Add to khata (goods on credit)', 'sfm4u-wholesale'), false);
            if (Members::canAdjust()) { self::entryForm($card, 'adjust', __('Correction (owner/manager)', 'sfm4u-wholesale'), false); }
            echo '</div>';
        }
        $rows = Members::entries((int) $card['user_id'], $store);
        echo '<h2>' . esc_html__('Recent khata', 'sfm4u-wholesale') . '</h2>';
        if (!$rows) { echo '<p>' . esc_html__('No entries yet.', 'sfm4u-wholesale') . '</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Date', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Type', 'sfm4u-wholesale')
            . '</th><th>' . esc_html__('Amount', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Balance', 'sfm4u-wholesale') . '</th><th>'
            . esc_html__('Method', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Note', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Cashier', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        $kinds = ['charge' => __('Credit', 'sfm4u-wholesale'), 'payment' => __('Payment', 'sfm4u-wholesale'), 'adjust' => __('Correction', 'sfm4u-wholesale')];
        foreach ($rows as $r) {
            $staff = get_userdata((int) $r['staff_id']);
            echo '<tr><td>' . esc_html(get_date_from_gmt($r['created_at'], 'd M Y, g:i a')) . '</td><td>' . esc_html($kinds[$r['kind']] ?? $r['kind'])
                . '</td><td>' . esc_html(Members::money((int) $r['amount_minor'])) . '</td><td>' . esc_html(Members::money((int) $r['balance_minor']))
                . '</td><td>' . esc_html($r['method']) . '</td><td>' . esc_html($r['note']) . '</td><td>' . esc_html($staff ? $staff->display_name : '#' . $r['staff_id']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Points (and wallet status) for the scanned member in this shop, plus purchase history. */
    private static function rewardsBox(array $card, string $store): void {
        $uid = (int) $card['user_id'];
        $mode = Rewards::mode($uid);
        $pool = Rewards::poolFor($uid, $store);
        $pts = Rewards::balance($uid, $pool);
        $rate = Rewards::rate($pool);
        $stores = Members::stores();
        echo '<div class="sfm-wr-member" style="margin-top:16px;display:block">';
        echo '<h3 style="margin-top:0">' . esc_html__('Points', 'sfm4u-wholesale') . '</h3>';
        if (!Rewards::pointsOn()) { echo '<p><em>' . esc_html__('Points are off. The owner can turn them on in Points & wallet.', 'sfm4u-wholesale') . '</em></p>'; }
        $poolName = $pool === 'company' ? __('SFM4U company reward', 'sfm4u-wholesale') : ($stores[$pool] ?? $pool);
        /* translators: 1: points, 2: pool name, 3: value */
        echo '<p><strong>' . esc_html(sprintf(__('%1$d points (%2$s) = %3$s', 'sfm4u-wholesale'), $pts, $poolName, Members::money(Rewards::value($pts, $rate['value'], Members::decimals())))) . '</strong></p>';
        echo '<form method="post" style="margin:6px 0">';
        wp_nonce_field('sfm_wr_rewards');
        echo '<input type="hidden" name="card_id" value="' . esc_attr($card['id']) . '"><input type="hidden" name="store" value="' . esc_attr($store) . '">'
            . esc_html__('Member collects points:', 'sfm4u-wholesale') . ' <label><input type="radio" name="sfm_wr_mode" value="shop"' . checked($mode, 'shop', false) . ' onchange="this.form.submit()"> ' . esc_html__('separately in each shop', 'sfm4u-wholesale') . '</label> '
            . '<label><input type="radio" name="sfm_wr_mode" value="company"' . checked($mode, 'company', false) . ' onchange="this.form.submit()"> ' . esc_html__('one company reward for all shops', 'sfm4u-wholesale') . '</label></form>';
        if ($card['status'] === 'active') {
            echo '<div class="sfm-wr-forms">';
            foreach (['purchase' => __('Record purchase (paid)', 'sfm4u-wholesale'), 'redeem' => __('Use points', 'sfm4u-wholesale')] as $k => $title) {
                echo '<form method="post" class="sfm-wr-entry"><h3>' . esc_html($title) . '</h3>';
                wp_nonce_field('sfm_wr_rewards');
                echo '<input type="hidden" name="card_id" value="' . esc_attr($card['id']) . '"><input type="hidden" name="store" value="' . esc_attr($store) . '"><input type="hidden" name="sfm_wr_' . esc_attr($k) . '" value="1">';
                echo $k === 'purchase'
                    ? '<p><label>' . esc_html__('Amount', 'sfm4u-wholesale') . ' <input name="amount" required inputmode="decimal" style="width:8em"></label></p>'
                    : '<p><label>' . esc_html__('Points', 'sfm4u-wholesale') . ' <input name="points" required inputmode="numeric" style="width:8em"></label></p>';
                echo '<p><label>' . esc_html__('Note', 'sfm4u-wholesale') . ' <input name="note" class="regular-text" maxlength="200"></label></p><p><button class="button button-primary">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
            }
            echo '</div>';
        }
        echo '<p>' . esc_html__('Wallet', 'sfm4u-wholesale') . ': ' . (Rewards::walletOn()
            ? '<strong>' . esc_html(Members::money(Rewards::walletBalance($uid))) . '</strong>'
            : '<em>' . esc_html__('not active yet (needs licence / partner approval)', 'sfm4u-wholesale') . '</em>') . '</p>';
        $hist = Rewards::history($uid, 10);
        if ($hist) {
            echo '<h4>' . esc_html__('Shopping history', 'sfm4u-wholesale') . '</h4><table class="widefat striped"><thead><tr><th>' . esc_html__('Date', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Type', 'sfm4u-wholesale')
                . '</th><th>' . esc_html__('Amount', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Points', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Note', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($hist as $h) {
                echo '<tr><td>' . esc_html(get_date_from_gmt($h['created_at'], 'd M Y, g:i a')) . '</td><td>' . esc_html($h['kind'] === 'earn' ? __('Purchase', 'sfm4u-wholesale') : __('Points used', 'sfm4u-wholesale'))
                    . '</td><td>' . esc_html((int) $h['purchase_minor'] ? Members::money((int) $h['purchase_minor']) : '—') . '</td><td>' . esc_html(sprintf('%+d', (int) $h['points'])) . '</td><td>' . esc_html($h['note']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /** Owner settings: point rates per shop and company, wallet switch. */
    public static function rewardsPage(): void {
        if (!current_user_can(Members::manageCap())) { wp_die(esc_html__('You cannot manage points.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Points & wallet', 'sfm4u-wholesale') . '</h1>';
        if (isset($_POST['sfm_wr_rewards_save'])) {
            check_admin_referer('sfm_wr_rewards_save');
            $s = Rewards::settings();
            $s['points_on'] = self::post('points_on') === 'yes' ? 'yes' : 'no';
            $num = static fn($v, $min) => max($min, (float) str_replace(',', '', (string) $v));
            $s['company'] = ['per' => (int) $num($_POST['company_per'] ?? 100, 1), 'value' => $num($_POST['company_value'] ?? 1, 0)];
            $s['rates'] = [];
            foreach (Members::stores() as $code => $n) {
                $s['rates'][$code] = ['per' => (int) $num($_POST['per'][$code] ?? 100, 1), 'value' => $num($_POST['value'][$code] ?? 1, 0)];
            }
            $s['rates']['online'] = ['per' => (int) $num($_POST['per']['online'] ?? 100, 1), 'value' => $num($_POST['value']['online'] ?? 1, 0)];
            $wasOn = Rewards::walletOn();
            $s['wallet_on'] = self::post('wallet_on') === 'yes' ? 'yes' : 'no';
            update_option(Rewards::OPT, $s, false);
            if (!$wasOn && Rewards::walletOn()) { update_option('sfm_wr_wallet_announced', current_time('mysql', true), false); }
            self::notice(__('Settings saved.', 'sfm4u-wholesale'));
        }
        $s = Rewards::settings();
        echo '<form method="post">';
        wp_nonce_field('sfm_wr_rewards_save');
        echo '<input type="hidden" name="sfm_wr_rewards_save" value="1"><h2>' . esc_html__('Points', 'sfm4u-wholesale') . '</h2><p><label><input type="checkbox" name="points_on" value="yes"' . checked($s['points_on'], 'yes', false) . '> '
            . esc_html__('Give points for purchases', 'sfm4u-wholesale') . '</label></p><table class="widefat striped" style="max-width:820px"><thead><tr><th>' . esc_html__('Shop', 'sfm4u-wholesale') . '</th><th>'
            . esc_html__('Spend this many taka for 1 point', 'sfm4u-wholesale') . '</th><th>' . esc_html__('1 point is worth (taka)', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        $rows = Members::stores() + ['online' => __('Online orders', 'sfm4u-wholesale')];
        foreach ($rows as $code => $name) {
            $r = Rewards::rate($code);
            echo '<tr><td>' . esc_html($name) . '</td><td><input name="per[' . esc_attr($code) . ']" value="' . esc_attr($r['per']) . '" style="width:7em"></td><td><input name="value[' . esc_attr($code) . ']" value="' . esc_attr($r['value']) . '" style="width:7em"></td></tr>';
        }
        $c = Rewards::rate('company');
        echo '<tr><td><strong>' . esc_html__('SFM4U company reward (all shops)', 'sfm4u-wholesale') . '</strong></td><td><input name="company_per" value="' . esc_attr($c['per']) . '" style="width:7em"></td><td><input name="company_value" value="' . esc_attr($c['value']) . '" style="width:7em"></td></tr></tbody></table>';
        echo '<p class="description">' . esc_html__('Each member chooses: points kept separately in each shop, or one company reward that grows from every SFM4U shop.', 'sfm4u-wholesale') . '</p>';
        echo '<h2>' . esc_html__('Wallet (money on the card)', 'sfm4u-wholesale') . '</h2><p>' . esc_html__('Built and kept OFF. Holding customers\' money needs a Bangladesh Bank PSP licence or a licensed partner (bank / bKash / Nagad). Turn it on only after approval: tick below AND add define( \'SFM4U_WALLET_ENABLED\', true ); to wp-config.php. Members are then told it is available.', 'sfm4u-wholesale') . '</p>'
            . '<p><label><input type="checkbox" name="wallet_on" value="yes"' . checked($s['wallet_on'], 'yes', false) . '> ' . esc_html__('Wallet approved, turn it on', 'sfm4u-wholesale') . '</label> '
            . (defined('SFM4U_WALLET_ENABLED') && SFM4U_WALLET_ENABLED === true ? '' : '<em>(' . esc_html__('wp-config switch not set: wallet stays off', 'sfm4u-wholesale') . ')</em>') . '</p>';
        echo '<p><button class="button button-primary">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form></div>';
    }

    /** Manager-only: date of birth, home address, shop name and shop address. */
    private static function detailsBox(int $userId, array $card): void {
        $d = Members::details($userId);
        echo '<details class="sfm-wr-details" style="background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:10px 16px;margin:16px 0;max-width:900px">'
            . '<summary><strong>' . esc_html__('Customer details (managers only)', 'sfm4u-wholesale') . '</strong></summary><form method="post">';
        wp_nonce_field('sfm_wr_details');
        echo '<input type="hidden" name="sfm_wr_details" value="1"><input type="hidden" name="card_id" value="' . esc_attr($card['id']) . '">' . Members::nidScanField() . '<table class="form-table">';
        $rows = ['sfm_wr_father' => __("Father's name", 'sfm4u-wholesale'), 'sfm_wr_dob' => __('Date of birth', 'sfm4u-wholesale'), 'sfm_wr_address' => __('Home address', 'sfm4u-wholesale'),
            'sfm_wr_mother' => __("Mother's name", 'sfm4u-wholesale'), 'billing_phone' => __('Mobile number', 'sfm4u-wholesale'),
            'sfm_wr_district' => __('Home district (map on card)', 'sfm4u-wholesale'),
            'sfm_wr_shop_name' => __('Shop name', 'sfm4u-wholesale'), 'sfm_wr_shop_address' => __('Shop address', 'sfm4u-wholesale'),
            'sfm_wr_name_bn' => __('Name in Bangla (card back)', 'sfm4u-wholesale'), 'sfm_wr_name_en' => __('Name in English (card front)', 'sfm4u-wholesale'), 'sfm_wr_father_en' => __("Father's name in English (card front)", 'sfm4u-wholesale')];
        if (in_array($card['tier'], ['business', 'black'], true)) { $rows['sfm_wr_business_en'] = __('Business name in English (card front)', 'sfm4u-wholesale'); }
        if (in_array($card['tier'], ['business', 'black'], true)) {
            echo '<tr><th>' . esc_html__('Business name on card', 'sfm4u-wholesale') . '</th><td><input name="business_name" class="regular-text" maxlength="120" value="' . esc_attr($card['business_name'] ?? '') . '"></td></tr>';
        }
        echo '<tr><th>' . esc_html__('NID or birth registration', 'sfm4u-wholesale') . '</th><td>' . (!empty($card['id_last4']) ? '<p>' . esc_html(Members::displayNumber($card)) . '</p>' : '')
            . '<label><input type="radio" name="id_type" value="nid" checked> NID</label> <label><input type="radio" name="id_type" value="brn"> ' . esc_html__('Birth registration', 'sfm4u-wholesale') . '</label><br>'
            . '<input name="national_id" class="regular-text" inputmode="numeric" autocomplete="off"> <label><input type="checkbox" name="id_consent" value="yes"> ' . esc_html__('Customer agreed to link this number to their card', 'sfm4u-wholesale') . '</label></td></tr>';
        foreach ($rows as $k => $label) {
            if ($k === 'sfm_wr_district') {
                $auto = BdMap::detect($d['sfm_wr_address'] ?? '');
                $opts = '<option value="">' . esc_html($auto ? sprintf(__('Automatic from address: %s', 'sfm4u-wholesale'), BdMap::info($auto)['en']) : __('Automatic from address', 'sfm4u-wholesale')) . '</option>';
                foreach (BdMap::choices() as $key => $lbl) { $opts .= '<option value="' . esc_attr($key) . '"' . selected($d[$k] ?? '', $key, false) . '>' . esc_html($lbl) . '</option>'; }
                echo '<tr><th>' . esc_html($label) . '</th><td><select name="' . esc_attr($k) . '">' . $opts . '</select></td></tr>';
                continue;
            }
            $input = $k === 'sfm_wr_dob'
                ? '<input type="date" name="' . esc_attr($k) . '" value="' . esc_attr($d[$k]) . '">'
                : (in_array($k, ['sfm_wr_father', 'sfm_wr_mother', 'billing_phone', 'sfm_wr_name_bn', 'sfm_wr_name_en', 'sfm_wr_father_en', 'sfm_wr_business_en'], true) ? '<input name="' . esc_attr($k) . '" class="regular-text" value="' . esc_attr($d[$k]) . '">'
                : '<textarea name="' . esc_attr($k) . '" rows="2" class="large-text">' . esc_textarea($d[$k]) . '</textarea>');
            echo '<tr><th>' . esc_html($label) . '</th><td>' . $input . '</td></tr>';
        }
        $sig = Members::signature((int) $card['user_id']);
        echo '<tr><th>' . esc_html__('Customer signature', 'sfm4u-wholesale') . '</th><td>'
            . Members::signaturePadField($sig, true)
            . '<p class="description">' . esc_html__('Hand the phone or tab to the customer to sign with a finger. Or upload a clear photo of their signature.', 'sfm4u-wholesale') . '</p></td></tr>';
        $log = get_user_meta((int) $card['user_id'], 'sfm_wr_details_log', true);
        if (is_array($log) && $log) {
            echo '<tr><th>' . esc_html__('Change history', 'sfm4u-wholesale') . '</th><td><small>';
            foreach (array_reverse(array_slice($log, -8)) as $row) {
                $who = $row['src'] === 'self' ? __('member', 'sfm4u-wholesale') : (($u = get_userdata((int) $row['by'])) ? $u->user_login : __('staff', 'sfm4u-wholesale'));
                echo esc_html(get_date_from_gmt($row['t'], 'd M Y H:i') . ' — ' . $who . ': ' . implode(', ', array_map(static fn($f) => str_replace(['sfm_wr_', 'billing_'], '', $f), $row['f']))) . '<br>';
            }
            echo esc_html(sprintf(__('Member self-edits left: %d', 'sfm4u-wholesale'), Members::selfEditsLeft((int) $card['user_id']))) . ' <label><input type="checkbox" name="sfm_wr_reset_self" value="1"> ' . esc_html__('Allow one more self-edit', 'sfm4u-wholesale') . '</label></small></td></tr>';
        }
        echo '</table><p class="description">' . esc_html__('Cashiers cannot see this. Fill it from the customer\'s documents with their consent.', 'sfm4u-wholesale') . '</p>'
            . '<p><button class="button">' . esc_html__('Save details', 'sfm4u-wholesale') . '</button></p></form></details>';
    }

    /** Business Black payments and Premium gifts for this member. */
    private static function planBox(array $card): void {
        $uid = (int) $card['user_id'];
        $tiers = array_column(Members::cardsOf($uid), 'tier');
        $gifts = Tiers::gifts($uid);
        if (!in_array('black', $tiers, true) && !$gifts) { return; }
        $r = Tiers::rules();
        echo '<div class="sfm-wr-box" style="background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:8px 16px;margin:12px 0">';
        if (in_array('black', $tiers, true)) {
            $until = Tiers::blackUntil($uid);
            $on = Tiers::blackActive($uid);
            echo '<h3>Business Black</h3><p>' . ($on ? '<strong style="color:#008a20">' . esc_html(sprintf(__('Active, paid until %s', 'sfm4u-wholesale'), $until)) . '</strong>'
                : '<strong class="sfm-wr-bad">' . esc_html($until !== '' ? sprintf(__('Expired on %s', 'sfm4u-wholesale'), $until) : __('Not paid yet', 'sfm4u-wholesale')) . '</strong>')
                . ' · ' . esc_html(sprintf(__('Fee: ৳%1$d per %2$d days', 'sfm4u-wholesale'), $r['black_fee'], $r['black_days'])) . '</p>';
            if (Members::canAdjust()) {
                echo '<form method="post">';
                wp_nonce_field('sfm_wr_plan');
                echo '<input type="hidden" name="card_id" value="' . (int) $card['id'] . '"><label>' . esc_html__('Months', 'sfm4u-wholesale') . ' <input type="number" name="months" value="1" min="1" max="12" style="width:60px"></label> '
                    . '<select name="method"><option value="cash">' . esc_html__('Cash', 'sfm4u-wholesale') . '</option><option value="bkash">bKash</option></select> '
                    . '<input name="ref" placeholder="' . esc_attr__('bKash transaction ID', 'sfm4u-wholesale') . '"> '
                    . '<button class="button button-primary" name="sfm_wr_black_pay" value="1">' . esc_html__('Record monthly payment', 'sfm4u-wholesale') . '</button></form>';
            }
            $log = array_slice(array_reverse(Tiers::blackLog($uid)), 0, 5);
            if ($log) {
                echo '<p><small>';
                foreach ($log as $l) { echo esc_html(substr((string) $l['at'], 0, 10) . ': ৳' . $l['fee'] . ' ' . $l['method'] . ($l['ref'] !== '' ? ' (' . $l['ref'] . ')' : '') . ' → ' . $l['until']) . '<br>'; }
                echo '</small></p>';
            }
        }
        if ($gifts) {
            echo '<h3>' . esc_html__('Premium gifts', 'sfm4u-wholesale') . '</h3><ul>';
            foreach (array_reverse($gifts, true) as $i => $g) {
                echo '<li>' . esc_html(substr((string) $g['at'], 0, 10) . ' · ' . $g['gift'] . ' · ' . Members::money((int) $g['amount_minor']) . ' · ' . $g['ref']) . ' ';
                if ($g['given'] !== '') { echo '<em>' . esc_html__('given', 'sfm4u-wholesale') . '</em>'; }
                elseif (Members::canAdjust()) {
                    echo '<form method="post" style="display:inline">';
                    wp_nonce_field('sfm_wr_plan');
                    echo '<input type="hidden" name="card_id" value="' . (int) $card['id'] . '"><button class="button-link" name="sfm_wr_gift_given" value="' . (int) $i . '">' . esc_html__('Mark given', 'sfm4u-wholesale') . '</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private static function entryForm(array $card, string $kind, string $title, bool $method): void {
        echo '<form method="post" class="sfm-wr-entry sfm-wr-entry-' . esc_attr($kind) . '"><h3>' . esc_html($title) . '</h3>';
        wp_nonce_field('sfm_wr_khata');
        echo '<input type="hidden" name="card_id" value="' . esc_attr($card['id']) . '"><input type="hidden" name="sfm_wr_action" value="' . esc_attr($kind) . '"><input type="hidden" name="store" value="' . esc_attr(Members::currentStore()) . '">';
        echo '<p><label>' . esc_html__('Amount', 'sfm4u-wholesale') . ' <input name="amount" required inputmode="decimal" class="small-text" style="width:8em"></label></p>';
        if ($method) {
            echo '<p><label>' . esc_html__('Paid by', 'sfm4u-wholesale') . ' <select name="method">';
            foreach (['cash' => __('Cash', 'sfm4u-wholesale'), 'mobile' => __('bKash / Nagad', 'sfm4u-wholesale'), 'bank' => __('Bank', 'sfm4u-wholesale'),
                'online' => __('Online', 'sfm4u-wholesale'), 'other' => __('Other', 'sfm4u-wholesale')] as $k => $l) {
                echo '<option value="' . esc_attr($k) . '">' . esc_html($l) . '</option>';
            }
            echo '</select></label></p>';
        }
        if ($kind === 'adjust') {
            echo '<p><label><input type="radio" name="direction" value="down" checked> ' . esc_html__('Reduce due', 'sfm4u-wholesale') . '</label> '
                . '<label><input type="radio" name="direction" value="up"> ' . esc_html__('Increase due', 'sfm4u-wholesale') . '</label></p>';
        }
        echo '<p><label>' . esc_html__('Note', 'sfm4u-wholesale') . ' <input name="note" class="regular-text" maxlength="255"' . ($kind === 'adjust' ? ' required' : '') . '></label></p>';
        echo '<p><button class="button button-primary">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
    }

    /* ---------------- Member cards ---------------- */

    public static function cardsPage(): void {
        if (!current_user_can(Members::manageCap())) { wp_die(esc_html__('You cannot manage cards.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Member cards', 'sfm4u-wholesale') . '</h1>';
        $print = null;
        try {
            if (isset($_POST['sfm_wr_issue'])) {
                check_admin_referer('sfm_wr_issue');
                $who = self::post('customer');
                $user = is_email($who) ? get_user_by('email', $who) : null;
                if (!$user && preg_match('/^[0-9+ ]{6,20}$/', $who)) {
                    $found = get_users(['meta_key' => 'billing_phone', 'meta_value' => $who, 'number' => 2, 'fields' => 'all']);
                    $user = count($found) === 1 ? $found[0] : null;
                }
                if (!$user) { throw new DomainException(__('No customer with that email or phone. Ask them to create an SFM4U account first.', 'sfm4u-wholesale')); }
                $tier = self::post('tier');
                $nid = null;
                $rawId = self::post('national_id');
                if ($rawId !== '') {
                    $nid = Members::parseNationalId($rawId, self::post('id_type') === 'brn' ? 'brn' : 'nid');
                    if (!$nid) { throw new DomainException(__('Enter a valid NID (10, 13 or 17 digits) or birth registration number (17 digits).', 'sfm4u-wholesale')); }
                    if (self::post('id_consent') !== 'yes') { throw new DomainException(__('The customer must agree before we link their NID or birth registration number.', 'sfm4u-wholesale')); }
                    if (Members::idInUse(Members::idHash($nid[1], Members::idKey()), $user->ID)) { throw new DomainException(__('This NID or birth registration number is already linked to another account.', 'sfm4u-wholesale')); }
                }
                CardLink::$staffVerified = true; // staff gives the card at the counter after checking the person
                $card = Members::issue($user->ID, $tier, absint($_POST['photo_id'] ?? 0), get_current_user_id(), 'main', $nid, self::post('business_name'), Members::currentStore() ?: 'main');
                CardLink::$staffVerified = false;
                if (in_array($tier, ['business', 'black'], true) && self::post('approve_wholesale') === 'yes') {
                    update_user_meta($user->ID, '_sfmwh_buyer_status', 'approved');
                }
                /* translators: %s: card number */
                self::notice(sprintf(__('Card created: %s', 'sfm4u-wholesale'), Members::displayNumber($card)));
                $print = $card;
            } elseif (isset($_POST['sfm_wr_auto'])) {
                check_admin_referer('sfm_wr_auto');
                update_option(Members::AUTO_OPTION, self::post('auto_on') === '1' ? '1' : '0', false);
                if (self::post('backfill') === '1') {
                    /* translators: %d: number of cards */
                    self::notice(sprintf(__('%d cards given to existing accounts.', 'sfm4u-wholesale'), Members::backfill(200)));
                } else {
                    self::notice(__('Saved.', 'sfm4u-wholesale'));
                }
            } elseif (isset($_POST['sfm_wr_rules'])) {
                check_admin_referer('sfm_wr_rules');
                Tiers::saveRules(['gift_min' => self::post('gift_min'), 'gift_text' => self::post('gift_text'), 'black_fee' => self::post('black_fee'), 'black_days' => self::post('black_days')]);
                self::notice(__('Card plans saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_status'])) {
                check_admin_referer('sfm_wr_status');
                Members::setStatus(absint($_POST['card_id'] ?? 0), self::post('sfm_wr_status'));
                self::notice(__('Card updated.', 'sfm4u-wholesale'));
            } elseif (isset($_GET['print'])) {
                $print = Members::find('id', absint($_GET['print']));
            }
        } catch (DomainException $e) {
            self::notice($e->getMessage(), 'error');
        }
        if ($print) { self::printCard($print); }

        echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:8px 16px;margin:12px 0;max-width:900px">';
        wp_nonce_field('sfm_wr_auto');
        echo '<input type="hidden" name="sfm_wr_auto" value="1"><p><label><input type="checkbox" name="auto_on" value="1"' . checked(Members::autoOn(), true, false) . '> <strong>'
            . esc_html__('Every new account gets a regular member card automatically', 'sfm4u-wholesale') . '</strong></label></p>'
            . '<p><label><input type="checkbox" name="backfill" value="1"> ' . esc_html__('Also give a regular card now to existing accounts that have none (up to 200 at a time)', 'sfm4u-wholesale') . '</label></p>'
            . '<p><button class="button">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        $tr = Tiers::rules();
        echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:8px 16px;margin:12px 0;max-width:900px">';
        wp_nonce_field('sfm_wr_rules');
        echo '<input type="hidden" name="sfm_wr_rules" value="1"><p><strong>' . esc_html__('Card plans', 'sfm4u-wholesale') . '</strong> — '
            . esc_html__('Regular: free online card (not printed). Premium: free printed card + gift. Business: AlgiBazar shopkeepers. Business Black: monthly fee, purchases go into their AlgiBazar store stock.', 'sfm4u-wholesale') . '</p>'
            . '<p><label>' . esc_html__('Premium gift when one purchase is at least ৳', 'sfm4u-wholesale') . ' <input type="number" name="gift_min" min="0" value="' . (int) $tr['gift_min'] . '" style="width:90px"></label> '
            . '<label>' . esc_html__('Gift', 'sfm4u-wholesale') . ' <input name="gift_text" value="' . esc_attr($tr['gift_text']) . '"></label></p>'
            . '<p><label>' . esc_html__('Business Black fee ৳', 'sfm4u-wholesale') . ' <input type="number" name="black_fee" min="0" value="' . (int) $tr['black_fee'] . '" style="width:90px"></label> '
            . '<label>' . esc_html__('per days', 'sfm4u-wholesale') . ' <input type="number" name="black_days" min="1" max="366" value="' . (int) $tr['black_days'] . '" style="width:70px"></label></p>'
            . '<p><button class="button">' . esc_html__('Save plans', 'sfm4u-wholesale') . '</button></p></form>';

        echo '<h2>' . esc_html__('Give a new card', 'sfm4u-wholesale') . '</h2><form method="post" class="sfm-wr-issue">';
        wp_nonce_field('sfm_wr_issue');
        echo '<input type="hidden" name="sfm_wr_issue" value="1"><table class="form-table"><tr><th>' . esc_html__('Customer email or phone', 'sfm4u-wholesale')
            . '</th><td><input name="customer" class="regular-text" required></td></tr><tr><th>' . esc_html__('Card type', 'sfm4u-wholesale') . '</th><td>';
        foreach (Members::TIERS as $t) {
            echo '<label style="margin-right:1em"><input type="radio" name="tier" value="' . esc_attr($t) . '"' . checked($t, 'regular', false) . '> ' . esc_html(Members::tierLabel($t)) . '</label>';
        }
        echo '<p class="description">' . esc_html__('Regular: free online card, retail price. Premium: free printed card, gift on big purchases. Business: shopkeepers, wholesale price. Business Black: shopkeepers, monthly fee, auto stock to their AlgiBazar store.', 'sfm4u-wholesale') . '</p>'
            . '<p><label>' . esc_html__('Business (shop) name, for business cards', 'sfm4u-wholesale') . ' <input name="business_name" class="regular-text" maxlength="120"></label></p>'
            . '<label><input type="checkbox" name="approve_wholesale" value="yes" checked> ' . esc_html__('Business card also approves wholesale buying', 'sfm4u-wholesale') . '</label></td></tr>'
            . '<tr><th>' . esc_html__('NID or birth registration', 'sfm4u-wholesale') . '</th><td>'
            . '<label style="margin-right:1em"><input type="radio" name="id_type" value="nid" checked> NID</label>'
            . '<label><input type="radio" name="id_type" value="brn"> ' . esc_html__('Birth registration', 'sfm4u-wholesale') . '</label><br>'
            . '<input name="national_id" class="regular-text" inputmode="numeric" autocomplete="off" style="margin-top:6px"><br>'
            . '<label><input type="checkbox" name="id_consent" value="yes"> ' . esc_html__('Customer agreed to link this number to their card', 'sfm4u-wholesale') . '</label>'
            . '<p class="description">' . esc_html__('The card then works with this number: the cashier can type it to find the customer. The number is stored encrypted; screens show only "NID •••• 1234". The full number appears only on the printed card and in the member\'s own account.', 'sfm4u-wholesale') . '</p></td></tr>'
            . '<tr><th>' . esc_html__('Photo', 'sfm4u-wholesale') . '</th><td><input type="hidden" name="photo_id" id="sfm-wr-photo-id" value="0">'
            . '<button type="button" class="button" id="sfm-wr-photo-pick">' . esc_html__('Choose or take photo', 'sfm4u-wholesale') . '</button> '
            . '<img id="sfm-wr-photo-preview" alt="" style="max-height:60px;vertical-align:middle;display:none">'
            . '<p class="description">' . esc_html__('If empty, the account profile picture is used.', 'sfm4u-wholesale') . '</p></td></tr></table>'
            . '<p><button class="button button-primary">' . esc_html__('Create card', 'sfm4u-wholesale') . '</button></p></form>';

        $cards = Members::recentCards();
        echo '<h2>' . esc_html__('Cards', 'sfm4u-wholesale') . '</h2>';
        if (!$cards) { echo '<p>' . esc_html__('No cards yet.', 'sfm4u-wholesale') . '</p></div>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Card / NID', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Customer', 'sfm4u-wholesale')
            . '</th><th>' . esc_html__('Card type', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Status', 'sfm4u-wholesale') . '</th><th>'
            . esc_html__('Khata balance (due)', 'sfm4u-wholesale') . '</th><th></th></tr></thead><tbody>';
        foreach ($cards as $c) {
            $u = get_userdata((int) $c['user_id']);
            $next = $c['status'] === 'active' ? 'suspended' : 'active';
            echo '<tr><td><code>' . esc_html(Members::memberNo((int) $c['user_id'])) . '</code> <small>' . esc_html(Members::displayNumber($c)) . '</small></td><td>' . esc_html($u ? $u->display_name : '#' . $c['user_id']) . '</td><td>'
                . esc_html(Members::tierLabel($c['tier'])) . '</td><td>' . esc_html($c['status'] === 'active' ? __('Active', 'sfm4u-wholesale') : __('Suspended', 'sfm4u-wholesale'))
                . '</td><td>' . esc_html(Members::money(Members::balance((int) $c['user_id'], $c['store']))) . '</td><td>'
                . '<a class="button" href="' . esc_url(add_query_arg(['page' => 'sfm-wr-cards', 'print' => $c['id']], admin_url('admin.php'))) . '">' . esc_html__('Print', 'sfm4u-wholesale') . '</a> '
                . '<form method="post" style="display:inline">';
            wp_nonce_field('sfm_wr_status');
            echo '<input type="hidden" name="card_id" value="' . esc_attr($c['id']) . '"><button class="button-link" name="sfm_wr_status" value="' . esc_attr($next) . '">'
                . esc_html($next === 'suspended' ? __('Suspend', 'sfm4u-wholesale') : __('Activate', 'sfm4u-wholesale')) . '</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Approved SFM4U Membership Card, front + back, Bangla or English. Printed at CR80 (85.6 × 54 mm). */
    public static function printCard(array $card): void {
        echo '<style>' . CardView::css() . '</style><div class="sfm-wr-print-wrap">'
            . '<p class="sfm-wr-noprint">' . esc_html__('Front side is printed in English, back side in Bangla.', 'sfm4u-wholesale') . '</p>'
            . CardView::render($card, 'en', true)
            . (Tiers::isOnlineOnly((string) $card['tier']) ? '<p class="sfm-wr-noprint"><strong>' . esc_html__('Regular cards are online cards: the member sees it in their account. Print only if the owner allows.', 'sfm4u-wholesale') . '</strong></p>' : '')
            . '<p class="sfm-wr-noprint"><button type="button" class="button button-primary" onclick="window.print()">' . esc_html__('Print card', 'sfm4u-wholesale') . '</button> '
            . esc_html__('Front and back print as two CR80 pages. Use an ID-card printer.', 'sfm4u-wholesale') . '</p></div>';
    }

    /* ---------------- Due list (khata reminders, sent by staff by hand) ---------------- */

    public static function duePage(): void {
        if (!current_user_can(Members::CAP)) { wp_die(esc_html__('You cannot see the due list.', 'sfm4u-wholesale')); }
        $store = Members::currentStore();
        $stores = Members::stores();
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Due list', 'sfm4u-wholesale') . '</h1>';
        if ($store === '' || !isset($stores[$store])) { echo '<p>' . esc_html__('Ask a manager to choose your shop first.', 'sfm4u-wholesale') . '</p></div>'; return; }
        echo '<p><strong>' . esc_html($stores[$store]) . '</strong> · ' . esc_html__('Members who owe this shop money. Reminders are sent by you, one by one — nothing is sent automatically.', 'sfm4u-wholesale') . '</p>';
        $rows = Members::dueList($store);
        if (!$rows) { echo '<p>' . esc_html__('Nobody owes this shop money.', 'sfm4u-wholesale') . '</p></div>'; return; }
        $total = array_sum(array_map(static fn($r) => (int) $r['balance_minor'], $rows));
        echo '<p>' . esc_html(sprintf(__('%1$d members · total due %2$s', 'sfm4u-wholesale'), count($rows), Members::money($total))) . '</p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__('Member', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Card No', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Due', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Last entry', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Remind', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $u = get_userdata($uid);
            $name = trim((string) get_user_meta($uid, 'sfm_wr_name_bn', true)) ?: ($u ? $u->display_name : '#' . $uid);
            $days = (int) floor((time() - strtotime($r['created_at'] . ' UTC')) / DAY_IN_SECONDS);
            $remind = '—';
            if (Members::canSeeDetails()) {
                $wa = Members::waNumber((string) get_user_meta($uid, 'billing_phone', true));
                $text = sprintf('আসসালামু আলাইকুম %1$s, %2$s-এ আপনার খাতায় %3$s বাকি আছে। সুবিধামতো পরিশোধ করার অনুরোধ রইল। ধন্যবাদ — %2$s', $name, $stores[$store], Members::money((int) $r['balance_minor']));
                $remind = $wa !== '' ? '<a class="button" target="_blank" rel="noopener" href="' . esc_url('https://wa.me/' . $wa . '?text=' . rawurlencode($text)) . '">WhatsApp</a>' : esc_html__('no mobile number', 'sfm4u-wholesale');
            }
            echo '<tr><td>' . esc_html($name) . '</td><td><a href="' . esc_url(add_query_arg(['page' => 'sfm-wr-scan', 'store' => $store], admin_url('admin.php')) . '#scan=' . Members::memberNo($uid)) . '"><code>' . esc_html(Members::memberNo($uid)) . '</code></a></td><td><strong>' . esc_html(Members::money((int) $r['balance_minor'])) . '</strong></td><td>'
                . esc_html(sprintf(_n('%d day ago', '%d days ago', $days, 'sfm4u-wholesale'), $days)) . '</td><td>' . $remind . '</td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('WhatsApp opens on your phone or computer with the message ready; you press Send yourself. Only managers see phone numbers.', 'sfm4u-wholesale') . '</p></div>';
    }

    /* ---------------- Account link (account.sfm4u.com <-> shops) ---------------- */

    /** Edit the members' card guide: one section per form, so saving one never changes another. */
    public static function guidePage(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Only an administrator can edit the card guide.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Card guide text', 'sfm4u-wholesale') . '</h1>';
        try {
            if (isset($_POST['sfm_wr_guide_save'])) {
                check_admin_referer('sfm_wr_guide');
                $key = sanitize_key((string) $_POST['sfm_wr_guide_save']);
                CardGuide::saveSection($key, (string) wp_unslash($_POST['title'] ?? ''), (string) wp_unslash($_POST['items'] ?? ''));
                self::notice(__('Section saved. Other sections were not changed.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_guide_reset'])) {
                check_admin_referer('sfm_wr_guide');
                CardGuide::resetSection(sanitize_key((string) $_POST['sfm_wr_guide_reset']));
                self::notice(__('Section restored to the original text.', 'sfm4u-wholesale'));
            }
        } catch (\DomainException $e) {
            self::notice($e->getMessage(), 'error');
        }
        echo '<p>' . esc_html__('Members see this text on the card guide page and under "My card". Write one point per line. No code needed. Each box has its own Save button.', 'sfm4u-wholesale') . '</p>';
        foreach (CardGuide::sections() as $key => $sec) {
            echo '<form method="post" class="card" style="max-width:900px;padding:12px 16px;margin:14px 0">';
            wp_nonce_field('sfm_wr_guide');
            echo '<p><label><strong>' . esc_html__('Title', 'sfm4u-wholesale') . '</strong><br><input name="title" class="large-text" value="' . esc_attr($sec['title']) . '"></label></p>'
                . '<p><label><strong>' . esc_html__('Points (one per line)', 'sfm4u-wholesale') . '</strong><br><textarea name="items" class="large-text" rows="' . (int) min(14, max(3, count($sec['items']) + 2)) . '" lang="bn">' . esc_textarea(implode("\n", $sec['items'])) . '</textarea></label></p>'
                . '<p><button class="button button-primary" name="sfm_wr_guide_save" value="' . esc_attr($key) . '">' . esc_html__('Save this section', 'sfm4u-wholesale') . '</button> ';
            if ($sec['edited']) {
                echo '<button class="button" name="sfm_wr_guide_reset" value="' . esc_attr($key) . '" onclick="return confirm(\'' . esc_js(__('Bring back the original text for this section?', 'sfm4u-wholesale')) . '\')">' . esc_html__('Restore default', 'sfm4u-wholesale') . '</button> <em>' . esc_html__('(edited)', 'sfm4u-wholesale') . '</em>';
            }
            echo '</p></form>';
        }
        echo '</div>';
    }

    /** Pairing steps that redirect (must run before any output). */
    public static function pairActions(): void {
        if (($_GET['page'] ?? '') !== 'sfm-wr-link' || !current_user_can('manage_options')) { return; }
        $msgKey = 'sfm_wr_pair_msg_' . get_current_user_id();
        try {
            if (isset($_POST['sfm_wr_pair_start'])) {
                check_admin_referer('sfm_wr_link');
                wp_redirect(CardLink::startPair(self::post('master_url')));
                exit;
            }
            if (isset($_POST['sfm_wr_pair_approve'])) {
                check_admin_referer('sfm_wr_pair');
                wp_redirect(CardLink::approvePair(self::post('shop'), self::post('back'), self::post('state')));
                exit;
            }
            if (isset($_GET['sfm_pair_code'])) {
                $site = CardLink::finishPair(sanitize_text_field(wp_unslash((string) $_GET['sfm_pair_code'])), sanitize_text_field(wp_unslash((string) ($_GET['sfm_pair_state'] ?? ''))));
                set_transient($msgKey, ['success', sprintf(__('Connected to %s.', 'sfm4u-wholesale'), $site)], 300);
                wp_safe_redirect(admin_url('admin.php?page=sfm-wr-link'));
                exit;
            }
        } catch (DomainException $e) {
            set_transient($msgKey, ['error', $e->getMessage()], 300);
            wp_safe_redirect(admin_url('admin.php?page=sfm-wr-link'));
            exit;
        }
    }

    public static function linkPage(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Only an administrator can change the account link.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Account link', 'sfm4u-wholesale') . '</h1>';
        $msgKey = 'sfm_wr_pair_msg_' . get_current_user_id();
        if (is_array($m = get_transient($msgKey))) { delete_transient($msgKey); self::notice((string) $m[1], (string) $m[0]); }
        if (CardLink::role() === 'master' && !empty($_GET['sfm_pair'])) {
            $shop = esc_url_raw(wp_unslash((string) ($_GET['shop'] ?? '')));
            $back = esc_url_raw(wp_unslash((string) ($_GET['back'] ?? '')));
            $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
            echo '<div class="notice notice-warning" style="padding:12px"><p><strong>' . esc_html(sprintf(__('Connect the shop site %s to this SFM4U account site?', 'sfm4u-wholesale'), CardLink::hostOf($shop))) . '</strong></p>'
                . '<p>' . esc_html__('The shop will be able to look up SFM4U cards (name, photo, card type, member number) and ask for member numbers for its customers. It will not see NID, birth date, address or mobile. You can remove it here at any time.', 'sfm4u-wholesale') . '</p><form method="post">';
            wp_nonce_field('sfm_wr_pair');
            echo '<input type="hidden" name="shop" value="' . esc_attr($shop) . '"><input type="hidden" name="back" value="' . esc_attr($back) . '"><input type="hidden" name="state" value="' . esc_attr($state) . '">'
                . '<button class="button button-primary" name="sfm_wr_pair_approve" value="1">' . esc_html__('Approve and connect', 'sfm4u-wholesale') . '</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=sfm-wr-link')) . '">' . esc_html__('Cancel', 'sfm4u-wholesale') . '</a></form></div>';
        }
        $shown = null;
        try {
            if (isset($_POST['sfm_wr_link_role'])) {
                check_admin_referer('sfm_wr_link');
                $r = self::post('sfm_wr_link_role');
                update_option(CardLink::OPT_ROLE, in_array($r, ['master', 'shop'], true) ? $r : 'off', false);
                self::notice(__('Saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_link_add'])) {
                check_admin_referer('sfm_wr_link');
                $shown = CardLink::addClient(self::post('client_name'));
            } elseif (isset($_POST['sfm_wr_link_remove'])) {
                check_admin_referer('sfm_wr_link');
                CardLink::removeClient(self::post('sfm_wr_link_remove'));
                self::notice(__('Shop key removed. That shop can no longer ask this site.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_link_master'])) {
                check_admin_referer('sfm_wr_link');
                CardLink::setMaster(self::post('master_url'), self::post('key_id'), self::post('secret'));
                self::notice(__('Saved. Press "Test connection".', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_link_test'])) {
                check_admin_referer('sfm_wr_link');
                $pong = CardLink::call('/ping');
                self::notice(sprintf(__('Connected to %s.', 'sfm4u-wholesale'), (string) ($pong['site'] ?? '')));
            }
        } catch (DomainException $e) {
            self::notice($e->getMessage(), 'error');
        }
        $role = CardLink::role();
        echo '<p>' . esc_html__('One SFM4U card for every shop: the SFM4U account site (account.sfm4u.com) keeps the member list. Shop sites ask it when a scanned card is not their own customer. Each shop keeps its own khata and points.', 'sfm4u-wholesale') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('sfm_wr_link');
        echo '<p><strong>' . esc_html__('This site is', 'sfm4u-wholesale') . '</strong><br>';
        foreach (['off' => __('Not linked', 'sfm4u-wholesale'), 'master' => __('The SFM4U account site (card master)', 'sfm4u-wholesale'), 'shop' => __('A shop that asks the account site', 'sfm4u-wholesale')] as $k => $label) {
            echo '<label style="display:block;margin:4px 0"><input type="radio" name="sfm_wr_link_role" value="' . esc_attr($k) . '"' . checked($role, $k, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</p><p><button class="button">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        if ($role === 'master') {
            if ($shown) {
                echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Copy these now into the shop site (SFM4U → Account link). The secret is shown only once.', 'sfm4u-wholesale') . '</strong></p>'
                    . '<p>' . esc_html__('Account site address', 'sfm4u-wholesale') . ': <code>' . esc_html(home_url()) . '</code><br>' . esc_html__('Key ID', 'sfm4u-wholesale') . ': <code>' . esc_html($shown[0]) . '</code><br>'
                    . esc_html__('Secret', 'sfm4u-wholesale') . ': <code class="sfm-wr-secret">' . esc_html($shown[1]) . '</code></p></div>';
            }
            echo '<h2>' . esc_html__('Connected shop sites', 'sfm4u-wholesale') . '</h2><table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__('Name', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Key ID', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Last used', 'sfm4u-wholesale') . '</th><th></th></tr></thead><tbody>';
            foreach (CardLink::clients() as $id => $c) {
                echo '<tr><td>' . esc_html((string) $c['name']) . '</td><td><code>' . esc_html($id) . '</code></td><td>' . esc_html($c['last_used'] ? get_date_from_gmt($c['last_used'], 'd M Y H:i') : '—') . '</td><td><form method="post" onsubmit="return confirm(\'' . esc_js(__('Remove this shop key?', 'sfm4u-wholesale')) . '\')">';
                wp_nonce_field('sfm_wr_link');
                echo '<button class="button-link-delete" name="sfm_wr_link_remove" value="' . esc_attr($id) . '">' . esc_html__('Remove', 'sfm4u-wholesale') . '</button></form></td></tr>';
            }
            echo '</tbody></table><form method="post" style="margin-top:12px">';
            wp_nonce_field('sfm_wr_link');
            echo '<input type="hidden" name="sfm_wr_link_add" value="1"><label>' . esc_html__('Shop site name', 'sfm4u-wholesale') . ' <input name="client_name" class="regular-text" placeholder="thenoakhali.com"></label> <button class="button button-primary">' . esc_html__('Create a key for a shop site', 'sfm4u-wholesale') . '</button></form>';
        }
        if ($role !== 'master') {
            $m = CardLink::master();
            echo '<h2>' . esc_html__('Connect automatically', 'sfm4u-wholesale') . '</h2><p>' . esc_html__('Opens the SFM4U account site. An administrator there presses "Approve and connect"; the key is then saved here by itself. Nobody needs to copy a secret.', 'sfm4u-wholesale') . '</p><form method="post">';
            wp_nonce_field('sfm_wr_link');
            echo '<input name="master_url" class="regular-text" value="' . esc_attr((string) ($m['url'] ?? 'https://account.sfm4u.com')) . '"> <button class="button button-primary" name="sfm_wr_pair_start" value="1">' . esc_html__('Connect automatically', 'sfm4u-wholesale') . '</button></form>';
        }
        if ($role === 'shop') {
            $m = CardLink::master();
            echo '<h3>' . esc_html__('Or enter a key by hand', 'sfm4u-wholesale') . '</h3>';
            echo '<h2>' . esc_html__('SFM4U account site', 'sfm4u-wholesale') . '</h2><form method="post">';
            wp_nonce_field('sfm_wr_link');
            echo '<input type="hidden" name="sfm_wr_link_master" value="1"><table class="form-table"><tr><th>' . esc_html__('Account site address', 'sfm4u-wholesale') . '</th><td><input name="master_url" class="regular-text" value="' . esc_attr((string) ($m['url'] ?? 'https://account.sfm4u.com')) . '"></td></tr>'
                . '<tr><th>' . esc_html__('Key ID', 'sfm4u-wholesale') . '</th><td><input name="key_id" class="regular-text" value="' . esc_attr((string) ($m['key_id'] ?? '')) . '"></td></tr>'
                . '<tr><th>' . esc_html__('Secret', 'sfm4u-wholesale') . '</th><td><input name="secret" type="password" class="regular-text" autocomplete="off" placeholder="' . esc_attr(!empty($m['secret_enc']) ? __('(saved — paste again to change)', 'sfm4u-wholesale') : '') . '"></td></tr></table>'
                . '<p><button class="button button-primary">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
            if (!empty($m['key_id'])) {
                echo '<form method="post">';
                wp_nonce_field('sfm_wr_link');
                echo '<button class="button" name="sfm_wr_link_test" value="1">' . esc_html__('Test connection', 'sfm4u-wholesale') . '</button></form>';
            }
        }
        echo '</div>';
    }

    /* ---------------- Shops ---------------- */

    public static function shopsPage(): void {
        if (!current_user_can(Members::manageCap())) { wp_die(esc_html__('You cannot manage shops.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Shops', 'sfm4u-wholesale') . '</h1>';
        try {
            if (isset($_POST['sfm_wr_shop_add'])) {
                check_admin_referer('sfm_wr_shops');
                $code = sanitize_key(self::post('code'));
                $name = self::post('name');
                if (!Members::validStoreCode($code) || $name === '') { throw new DomainException(__('Enter a short code (a-z, 0-9, -) and a shop name.', 'sfm4u-wholesale')); }
                $list = get_option('sfm_wr_stores', []);
                $list = is_array($list) ? $list : [];
                $policy = esc_url_raw(self::post('policy_url'));
                $list[$code] = ['name' => mb_substr($name, 0, 120), 'phone' => mb_substr(self::post('phone'), 0, 40), 'address' => mb_substr(self::post('address'), 0, 200),
                    'licence_name' => mb_substr(self::post('licence_name'), 0, 120), 'licence_no' => mb_substr(self::post('licence_no'), 0, 60), 'policy_url' => $policy];
                update_option('sfm_wr_stores', $list, false);
                self::notice(__('Shop saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_staff'])) {
                check_admin_referer('sfm_wr_shops');
                $u = get_user_by('email', self::post('staff_email'));
                if (!$u) { throw new DomainException(__('Staff email or shop not found.', 'sfm4u-wholesale')); }
                Members::setStaff($u->ID, sanitize_key(self::post('staff_store')), self::post('staff_level'));
                self::notice(__('Staff shop saved.', 'sfm4u-wholesale'));
            }
        } catch (DomainException $e) { self::notice($e->getMessage(), 'error'); }
        echo '<p>' . esc_html__('One member card works in every shop. Each shop sees only its own khata for that member.', 'sfm4u-wholesale') . '</p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__('Code', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Shop', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Phone', 'sfm4u-wholesale')
            . '</th><th>' . esc_html__('Trade licence', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Address', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        foreach (Members::storeInfos() as $c => $i) {
            echo '<tr><td><code>' . esc_html($c) . '</code></td><td>' . esc_html($i['name']) . '</td><td>' . esc_html($i['phone']) . '</td><td>' . esc_html(trim($i['licence_name'] . ' ' . $i['licence_no'])) . '</td><td>' . esc_html($i['address']) . '</td></tr>';
        }
        echo '</tbody></table><h2>' . esc_html__('Add or update a shop', 'sfm4u-wholesale') . '</h2><p class="description">' . esc_html__('Use the same code to update a shop. The phone is printed on cards this shop issues; licence name, number, address and policy go on its receipts.', 'sfm4u-wholesale') . '</p><form method="post">';
        wp_nonce_field('sfm_wr_shops');
        echo '<table class="form-table"><tr><th>' . esc_html__('Code', 'sfm4u-wholesale') . '</th><td><input name="code" placeholder="maijdee-1" class="regular-text" style="max-width:12em"></td></tr>'
            . '<tr><th>' . esc_html__('Shop name', 'sfm4u-wholesale') . '</th><td><input name="name" class="regular-text"></td></tr>'
            . '<tr><th>' . esc_html__('Phone', 'sfm4u-wholesale') . '</th><td><input name="phone" class="regular-text" inputmode="tel"></td></tr>'
            . '<tr><th>' . esc_html__('Address', 'sfm4u-wholesale') . '</th><td><input name="address" class="large-text"></td></tr>'
            . '<tr><th>' . esc_html__('Trade licence name', 'sfm4u-wholesale') . '</th><td><input name="licence_name" class="regular-text"></td></tr>'
            . '<tr><th>' . esc_html__('Trade licence number', 'sfm4u-wholesale') . '</th><td><input name="licence_no" class="regular-text"></td></tr>'
            . '<tr><th>' . esc_html__('Policy page link', 'sfm4u-wholesale') . '</th><td><input name="policy_url" type="url" class="regular-text" placeholder="https://"></td></tr></table>'
            . '<p><button class="button button-primary" name="sfm_wr_shop_add" value="1">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        echo '<h2>' . esc_html__('Which shop a staff member works in', 'sfm4u-wholesale') . '</h2><form method="post">';
        wp_nonce_field('sfm_wr_shops');
        echo '<p><input name="staff_email" type="email" placeholder="staff@example.com" class="regular-text"> <select name="staff_store">';
        foreach (Members::stores() as $c => $n) { echo '<option value="' . esc_attr($c) . '">' . esc_html($n) . '</option>'; }
        echo '</select> <select name="staff_level"><option value="cashier">' . esc_html__('Cashier (no private details)', 'sfm4u-wholesale') . '</option><option value="manager">' . esc_html__('Manager (this shop)', 'sfm4u-wholesale') . '</option><option value="none">' . esc_html__('Remove from staff', 'sfm4u-wholesale') . '</option></select>'
            . ' <button class="button" name="sfm_wr_staff" value="1">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p>'
            . '<p class="description">' . esc_html__('Levels: CEO = site administrator; Owner = shop owner (all shops); Manager = one shop, sees private details and can correct khata; Cashier = one shop, cannot see NID, birth date or address.', 'sfm4u-wholesale') . '</p></form>';
        $staff = get_users(['role' => 'sfm_cashier', 'number' => 200]);
        if ($staff) {
            echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__('Staff', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Shop', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Level', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($staff as $st) {
                echo '<tr><td>' . esc_html($st->display_name . ' <' . $st->user_email . '>') . '</td><td>' . esc_html(Members::stores()[(string) get_user_meta($st->ID, 'sfm_wr_store', true)] ?? '—') . '</td><td>' . esc_html(Members::shopRole($st->ID)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /* ---------------- Product QR labels ---------------- */

    /** QR content: the SKU when set (scanner types it into POS search), otherwise "SFM4U-P:<id>". */
    public static function productCode(\WC_Product $p): string {
        $sku = (string) $p->get_sku();
        return ($sku !== '' && preg_match('/^[\x21-\x7E]{1,64}$/', $sku)) ? $sku : 'SFM4U-P:' . $p->get_id();
    }

    public static function labelsPage(): void {
        if (!current_user_can(Members::manageCap())) { wp_die(esc_html__('You cannot print labels.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Product QR labels', 'sfm4u-wholesale') . '</h1>';
        $labels = [];
        $size = 'small';
        if (isset($_POST['sfm_wr_labels'])) {
            check_admin_referer('sfm_wr_labels');
            $size = self::post('size') === 'a4' ? 'a4' : 'small';
            $total = 0;
            foreach ((array) ($_POST['qty'] ?? []) as $id => $qty) {
                $id = absint($id); $qty = min(500, absint($qty));
                if (!$id || !$qty) { continue; }
                $p = wc_get_product($id);
                if (!$p || !current_user_can('edit_post', $p->is_type('variation') ? $p->get_parent_id() : $id)) { continue; }
                $total += $qty;
                if ($total > 500) { self::notice(__('Up to 500 labels at a time.', 'sfm4u-wholesale'), 'error'); $labels = []; break; }
                $labels[] = [$p, $qty];
            }
        }
        if ($labels) {
            echo '<p class="sfm-wr-noprint"><button type="button" class="button button-primary" onclick="window.print()">' . esc_html__('Print labels', 'sfm4u-wholesale')
                . '</button> ' . esc_html__('Label printers: choose the label size in the print window.', 'sfm4u-wholesale') . '</p><div class="sfm-wr-labels sfm-wr-labels-' . esc_attr($size) . '">';
            foreach ($labels as [$p, $qty]) {
                $code = self::productCode($p);
                $price = wc_get_price_to_display($p);
                for ($i = 0; $i < $qty; $i++) {
                    echo '<div class="sfm-wr-label"><div class="sfm-wr-qr" data-qr="' . esc_attr($code) . '"></div><div><b>' . esc_html($p->get_name())
                        . '</b><span>' . esc_html(Members::money((int) round($price * (10 ** Members::decimals())))) . '</span><small>' . esc_html($code) . '</small></div></div>';
                }
            }
            echo '</div>';
        }
        $products = wc_get_products(['limit' => 200, 'status' => ['publish', 'private'], 'orderby' => 'title', 'order' => 'ASC', 'type' => ['simple', 'variable']]);
        echo '<form method="post" class="sfm-wr-noprint">';
        wp_nonce_field('sfm_wr_labels');
        echo '<input type="hidden" name="sfm_wr_labels" value="1"><p>' . esc_html__('Write how many stickers you need for each product.', 'sfm4u-wholesale') . '</p>'
            . '<table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'sfm4u-wholesale') . '</th><th>SKU / QR</th><th>' . esc_html__('Price', 'sfm4u-wholesale')
            . '</th><th>' . esc_html__('Stock', 'sfm4u-wholesale') . '</th><th>' . esc_html__('How many', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        foreach ($products as $p) {
            $items = $p->is_type('variable') ? array_filter(array_map('wc_get_product', $p->get_children())) : [$p];
            foreach ($items as $it) {
                echo '<tr><td>' . esc_html($it->get_name()) . '</td><td><code>' . esc_html(self::productCode($it)) . '</code></td><td>' . wp_kses_post(wc_price(wc_get_price_to_display($it)))
                    . '</td><td>' . esc_html($it->managing_stock() ? (string) $it->get_stock_quantity() : '—') . '</td><td><input type="number" min="0" max="500" name="qty[' . esc_attr($it->get_id()) . ']" value="0" style="width:5em"></td></tr>';
            }
        }
        echo '</tbody></table><p>' . esc_html__('Size', 'sfm4u-wholesale') . ': <label><input type="radio" name="size" value="small" checked> ' . esc_html__('Small sticker (50 × 30 mm)', 'sfm4u-wholesale')
            . '</label> <label><input type="radio" name="size" value="a4"> ' . esc_html__('A4 sheet', 'sfm4u-wholesale') . '</label></p>'
            . '<p><button class="button button-primary">' . esc_html__('Make labels', 'sfm4u-wholesale') . '</button></p></form></div>';
    }
}
