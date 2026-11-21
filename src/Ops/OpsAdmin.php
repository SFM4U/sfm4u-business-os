<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Ops;

use DomainException;

defined('ABSPATH') || exit;

/** Admin screens for courier, WhatsApp and printing, plus the "Delivery & messages" box on each order. */
final class OpsAdmin {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 21);
        add_action('add_meta_boxes', [self::class, 'box']);
        add_action('admin_post_sfm_wr_order_op', [self::class, 'orderOp']);
        foreach (['edit-shop_order', 'woocommerce_page_wc-orders'] as $screen) {
            add_filter('bulk_actions-' . $screen, [self::class, 'bulkActions']);
            add_filter('handle_bulk_actions-' . $screen, [self::class, 'handleBulk'], 10, 3);
        }
        add_action('admin_notices', [self::class, 'bulkNotice']);
    }

    public static function menu(): void {
        $on = static fn (string $m): bool => !class_exists(\SFM4U\Wholesale\Modules::class) || \SFM4U\Wholesale\Modules::on($m);
        if ($on('delivery')) { add_submenu_page('sfm-bos', __('Courier & WhatsApp', 'sfm4u-wholesale'), __('Delivery & Messages', 'sfm4u-wholesale'), 'manage_woocommerce', 'sfm-wr-ops', [self::class, 'page']); }
        if ($on('receipt')) { add_submenu_page('sfm-bos', __('Auto print orders', 'sfm4u-wholesale'), __('Receipt System (auto print)', 'sfm4u-wholesale'), 'edit_shop_orders', 'sfm-wr-autoprint', [OrderPrint::class, 'autoPage']); }
    }

    private static function p(string $k): string {
        return isset($_POST[$k]) && is_string($_POST[$k]) ? trim(sanitize_text_field(wp_unslash($_POST[$k]))) : '';
    }

    private static function msg(string $t, string $type = 'success'): void { echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($t) . '</p></div>'; }

    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('Courier & WhatsApp', 'sfm4u-wholesale') . '</h1>';
        try {
            if (isset($_POST['sfm_wr_courier_save'])) {
                check_admin_referer('sfm_wr_ops');
                Courier::save(['enabled' => self::p('enabled'), 'base' => self::p('base'), 'key' => self::p('key'), 'secret' => self::p('secret'), 'auto_status' => self::p('auto_status'), 'note' => self::p('note')]);
                self::msg(__('Courier settings saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_courier_test'])) {
                check_admin_referer('sfm_wr_ops');
                self::msg(sprintf(__('Connected to Steadfast. Balance: ৳%s', 'sfm4u-wholesale'), Courier::balance()));
            } elseif (isset($_POST['sfm_wr_wa_save'])) {
                check_admin_referer('sfm_wr_ops');
                WhatsApp::save(['enabled' => self::p('enabled'), 'base' => self::p('base'), 'version' => self::p('version'), 'phone_id' => self::p('phone_id'),
                    'token' => self::p('token'), 'template' => self::p('template'), 'lang' => self::p('lang'), 'checkout_box' => self::p('checkout_box')]);
                self::msg(__('WhatsApp settings saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_wa_test'])) {
                check_admin_referer('sfm_wr_ops');
                $id = WhatsApp::sendTemplate(\SFM4U\Wholesale\Members\Members::waNumber(self::p('test_phone')), ['Test', '0000', '৳0', 'পরীক্ষা']);
                self::msg(sprintf(__('Test message sent (%s).', 'sfm4u-wholesale'), $id));
            }
        } catch (DomainException $e) {
            self::msg($e->getMessage(), 'error');
        }
        $c = Courier::settings();
        echo '<h2>Steadfast courier</h2><p>' . esc_html__('Off until you turn it on. Get the API key and secret from the Steadfast merchant panel (or Steadfast support) and type them here yourself. Then each order gets a "Send to Steadfast" button, and the order list gets a bulk action.', 'sfm4u-wholesale') . '</p><form method="post">';
        wp_nonce_field('sfm_wr_ops');
        echo '<table class="form-table">'
            . '<tr><th>' . esc_html__('Turn on', 'sfm4u-wholesale') . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($c['enabled'], '1', false) . '> ' . esc_html__('Courier link is on', 'sfm4u-wholesale') . '</label></td></tr>'
            . '<tr><th>' . esc_html__('API key', 'sfm4u-wholesale') . '</th><td><input name="key" class="regular-text" autocomplete="off" value="' . esc_attr($c['key']) . '"></td></tr>'
            . '<tr><th>' . esc_html__('Secret key', 'sfm4u-wholesale') . '</th><td><input name="secret" type="password" class="regular-text" autocomplete="off" placeholder="' . esc_attr($c['secret_enc'] !== '' ? __('(saved — type again to change)', 'sfm4u-wholesale') : '') . '"></td></tr>'
            . '<tr><th>' . esc_html__('Book automatically', 'sfm4u-wholesale') . '</th><td><select name="auto_status"><option value="">' . esc_html__('No — staff press the button', 'sfm4u-wholesale') . '</option><option value="processing"' . selected($c['auto_status'], 'processing', false) . '>' . esc_html__('When the order becomes Processing', 'sfm4u-wholesale') . '</option><option value="completed"' . selected($c['auto_status'], 'completed', false) . '>' . esc_html__('When the order becomes Completed', 'sfm4u-wholesale') . '</option></select></td></tr>'
            . '<tr><th>' . esc_html__('Note for the rider', 'sfm4u-wholesale') . '</th><td><input name="note" class="regular-text" value="' . esc_attr($c['note']) . '" placeholder="Handle with care"></td></tr>'
            . '<tr><th>' . esc_html__('API address', 'sfm4u-wholesale') . '</th><td><input name="base" class="regular-text" value="' . esc_attr($c['base']) . '"><p class="description">' . esc_html__('Leave as it is unless Steadfast gives a new address.', 'sfm4u-wholesale') . '</p></td></tr>'
            . '</table><p><button class="button button-primary" name="sfm_wr_courier_save" value="1">' . esc_html__('Save courier settings', 'sfm4u-wholesale') . '</button> <button class="button" name="sfm_wr_courier_test" value="1">' . esc_html__('Test (show balance)', 'sfm4u-wholesale') . '</button></p></form>';

        $w = WhatsApp::settings();
        echo '<hr><h2>WhatsApp</h2><p><strong>' . esc_html__('Free, already on:', 'sfm4u-wholesale') . '</strong> ' . esc_html__('every order has a WhatsApp button that opens WhatsApp with the order message ready; staff press Send. The Due list has the same for due reminders.', 'sfm4u-wholesale') . '</p>'
            . '<p><strong>' . esc_html__('Automatic (optional, paid by Meta per message):', 'sfm4u-wholesale') . '</strong> ' . esc_html__('needs a WhatsApp Business account on Meta, a business phone number, and an approved "Utility" template whose body uses {{1}} name, {{2}} order number, {{3}} total, {{4}} status. Messages go only to customers who ticked "Send me order updates on WhatsApp" at checkout (or that staff ticked on the order). Meta charges per delivered template message; check the current Bangladesh rate on Meta\'s pricing page before turning it on.', 'sfm4u-wholesale') . '</p><form method="post">';
        wp_nonce_field('sfm_wr_ops');
        echo '<table class="form-table">'
            . '<tr><th>' . esc_html__('Checkout box', 'sfm4u-wholesale') . '</th><td><label><input type="checkbox" name="checkout_box" value="1"' . checked($w['checkout_box'], '1', false) . '> ' . esc_html__('Show "Send me order updates on WhatsApp" at checkout', 'sfm4u-wholesale') . '</label></td></tr>'
            . '<tr><th>' . esc_html__('Turn on automatic messages', 'sfm4u-wholesale') . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($w['enabled'], '1', false) . '> ' . esc_html__('Send when an order becomes Processing or Completed', 'sfm4u-wholesale') . '</label></td></tr>'
            . '<tr><th>' . esc_html__('Phone number ID', 'sfm4u-wholesale') . '</th><td><input name="phone_id" class="regular-text" value="' . esc_attr($w['phone_id']) . '"><p class="description">' . esc_html__('From Meta: WhatsApp → API Setup (not the phone number itself).', 'sfm4u-wholesale') . '</p></td></tr>'
            . '<tr><th>' . esc_html__('Access token', 'sfm4u-wholesale') . '</th><td><input name="token" type="password" class="regular-text" autocomplete="off" placeholder="' . esc_attr($w['token_enc'] !== '' ? __('(saved — type again to change)', 'sfm4u-wholesale') : '') . '"><p class="description">' . esc_html__('Use a permanent System User token. Type it here yourself; it is stored encrypted.', 'sfm4u-wholesale') . '</p></td></tr>'
            . '<tr><th>' . esc_html__('Template name', 'sfm4u-wholesale') . '</th><td><input name="template" class="regular-text" value="' . esc_attr($w['template']) . '" placeholder="order_update"></td></tr>'
            . '<tr><th>' . esc_html__('Template language', 'sfm4u-wholesale') . '</th><td><input name="lang" class="small-text" value="' . esc_attr($w['lang']) . '"> <span class="description">bn / en / en_US</span></td></tr>'
            . '<tr><th>' . esc_html__('API version', 'sfm4u-wholesale') . '</th><td><input name="version" class="small-text" value="' . esc_attr($w['version']) . '"> <input name="base" type="hidden" value="' . esc_attr($w['base']) . '"></td></tr>'
            . '</table><p><button class="button button-primary" name="sfm_wr_wa_save" value="1">' . esc_html__('Save WhatsApp settings', 'sfm4u-wholesale') . '</button></p>'
            . '<p><input name="test_phone" placeholder="01XXXXXXXXX"> <button class="button" name="sfm_wr_wa_test" value="1">' . esc_html__('Send a test message', 'sfm4u-wholesale') . '</button> <span class="description">' . esc_html__('Only to a number that agreed to receive it.', 'sfm4u-wholesale') . '</span></p></form></div>';
    }

    /* ---------------- order box ---------------- */

    public static function box(): void {
        foreach (['shop_order', 'woocommerce_page_wc-orders'] as $screen) {
            add_meta_box('sfm-wr-ops', __('Delivery & messages', 'sfm4u-wholesale'), [self::class, 'boxHtml'], $screen, 'side', 'high');
        }
    }

    public static function boxHtml($postOrOrder): void {
        $o = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID ?? 0);
        if (!$o) { return; }
        $url = static fn(string $op): string => wp_nonce_url(admin_url('admin-post.php?action=sfm_wr_order_op&op=' . $op . '&order=' . $o->get_id()), 'sfm_wr_order_op_' . $o->get_id());
        $c = Courier::info($o);
        echo '<p><strong>Steadfast</strong><br>';
        if (!empty($c['consignment_id'])) {
            echo esc_html(sprintf(__('Consignment %1$s · status: %2$s', 'sfm4u-wholesale'), $c['consignment_id'], $c['status'] ?? '?')) . '<br>';
            if (Courier::trackingUrl($c) !== '') { echo '<a href="' . esc_url(Courier::trackingUrl($c)) . '" target="_blank" rel="noopener">' . esc_html__('Track parcel', 'sfm4u-wholesale') . '</a> · '; }
            echo '<a href="' . esc_url($url('courier_refresh')) . '">' . esc_html__('Refresh status', 'sfm4u-wholesale') . '</a>';
        } elseif (Courier::settings()['enabled'] === '1') {
            echo '<a class="button" href="' . esc_url($url('courier_book')) . '" onclick="return confirm(\'' . esc_js(__('Book this order with Steadfast?', 'sfm4u-wholesale')) . '\')">' . esc_html__('Send to Steadfast', 'sfm4u-wholesale') . '</a>';
        } else {
            echo '<span class="description">' . esc_html__('Courier link is off (SFM4U → Courier & WhatsApp).', 'sfm4u-wholesale') . '</span>';
        }
        echo '</p><p><strong>WhatsApp</strong><br>';
        $wa = WhatsApp::link($o);
        echo $wa !== '' ? '<a class="button" target="_blank" rel="noopener" href="' . esc_url($wa) . '">' . esc_html__('Open WhatsApp with order message', 'sfm4u-wholesale') . '</a>' : '<span class="description">' . esc_html__('No Bangladesh mobile number on this order.', 'sfm4u-wholesale') . '</span>';
        echo '<br><label><a href="' . esc_url($url(WhatsApp::optedIn($o) ? 'wa_optout' : 'wa_optin')) . '">' . (WhatsApp::optedIn($o) ? esc_html__('Customer agreed to WhatsApp updates ✓ (remove)', 'sfm4u-wholesale') : esc_html__('Mark: customer agreed to WhatsApp updates', 'sfm4u-wholesale')) . '</a></label></p>';
        echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url(OrderPrint::slipUrl($o->get_id())) . '">' . esc_html__('Print 80 mm slip', 'sfm4u-wholesale') . '</a></p>';
    }

    public static function orderOp(): void {
        $id = absint($_GET['order'] ?? 0);
        check_admin_referer('sfm_wr_order_op_' . $id);
        if (!current_user_can('edit_shop_orders')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $o = wc_get_order($id);
        if (!$o) { wp_die(esc_html__('Order not found.', 'sfm4u-wholesale')); }
        $op = sanitize_key((string) ($_GET['op'] ?? ''));
        try {
            if ($op === 'courier_book') { $i = Courier::book($o); $note = sprintf(__('Booked with Steadfast: %s', 'sfm4u-wholesale'), $i['consignment_id']); }
            elseif ($op === 'courier_refresh') { $i = Courier::refresh($o); $note = sprintf(__('Courier status: %s', 'sfm4u-wholesale'), $i['status']); }
            elseif ($op === 'wa_optin') { $o->update_meta_data(WhatsApp::OPTIN, 'yes'); $o->add_order_note('WhatsApp updates: customer agreed (marked by staff).'); $o->save(); $note = __('Saved.', 'sfm4u-wholesale'); }
            elseif ($op === 'wa_optout') { $o->delete_meta_data(WhatsApp::OPTIN); $o->add_order_note('WhatsApp updates: removed by staff.'); $o->save(); $note = __('Saved.', 'sfm4u-wholesale'); }
            else { throw new DomainException('Unknown action.'); }
            set_transient('sfm_wr_ops_msg_' . get_current_user_id(), ['success', $note], 120);
        } catch (DomainException $e) {
            set_transient('sfm_wr_ops_msg_' . get_current_user_id(), ['error', $e->getMessage()], 120);
        }
        wp_safe_redirect($o->get_edit_order_url());
        exit;
    }

    public static function bulkActions(array $a): array {
        if (Courier::settings()['enabled'] === '1') { $a['sfm_wr_courier_book'] = __('Send to Steadfast', 'sfm4u-wholesale'); }
        return $a;
    }

    public static function handleBulk(string $redirect, string $action, array $ids): string {
        if ($action !== 'sfm_wr_courier_book' || !current_user_can('edit_shop_orders')) { return $redirect; }
        $ok = 0; $fail = [];
        foreach (array_slice($ids, 0, 100) as $id) {
            $o = wc_get_order((int) $id);
            if (!$o) { continue; }
            try { Courier::book($o); $ok++; } catch (DomainException $e) { $fail[] = '#' . $o->get_order_number() . ': ' . $e->getMessage(); }
        }
        set_transient('sfm_wr_ops_msg_' . get_current_user_id(), [$fail ? 'warning' : 'success', sprintf(__('Steadfast: %d booked.', 'sfm4u-wholesale'), $ok) . ($fail ? ' ' . implode(' | ', array_slice($fail, 0, 10)) : '')], 120);
        return $redirect;
    }

    public static function bulkNotice(): void {
        $k = 'sfm_wr_ops_msg_' . get_current_user_id();
        $m = get_transient($k);
        if (is_array($m)) { delete_transient($k); self::msg((string) $m[1], (string) $m[0]); }
    }
}
