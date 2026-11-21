<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Ops;

use DomainException;
use SFM4U\Wholesale\Members\CardLink;

defined('ABSPATH') || exit;

/** Settings screen for Business Black auto stock (wholesale shop → AlgiBazar store). */
final class StockFeedAdmin {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 22);
        add_action('admin_init', [self::class, 'actions']);
    }

    public static function menu(): void {
        add_submenu_page(defined('SFM4U_STOCK_ONLY') ? 'woocommerce' : 'sfm-bos', __('AlgiBazar stock link', 'sfm4u-wholesale'), __('AlgiBazar stock link', 'sfm4u-wholesale'), 'manage_options', 'sfm-wr-stockfeed', [self::class, 'page']);
    }

    private static function p(string $k): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim(sanitize_text_field(wp_unslash($_POST[$k]))) : ''; }

    public static function actions(): void {
        if (($_GET['page'] ?? '') !== 'sfm-wr-stockfeed' || !current_user_can('manage_options')) { return; }
        $mk = 'sfm_wr_stock_msg_' . get_current_user_id();
        try {
            if (isset($_POST['sfm_wr_stock_start'])) { check_admin_referer('sfm_wr_stock'); wp_redirect(StockFeed::startPair(self::p('url'))); exit; }
            if (isset($_POST['sfm_wr_stock_approve'])) { check_admin_referer('sfm_wr_stock_pair'); wp_redirect(StockFeed::approvePair(self::p('site'), self::p('back'), self::p('state'))); exit; }
            if (isset($_GET['sfm_stock_code'])) {
                $site = StockFeed::finishPair(sanitize_text_field(wp_unslash((string) $_GET['sfm_stock_code'])), sanitize_text_field(wp_unslash((string) ($_GET['sfm_stock_state'] ?? ''))));
                set_transient($mk, ['success', sprintf(__('Connected to %s.', 'sfm4u-wholesale'), $site)], 300);
                wp_safe_redirect(admin_url('admin.php?page=sfm-wr-stockfeed'));
                exit;
            }
        } catch (DomainException $e) {
            set_transient($mk, ['error', $e->getMessage()], 300);
            wp_safe_redirect(admin_url('admin.php?page=sfm-wr-stockfeed'));
            exit;
        }
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $msg = static function (string $t, string $type = 'success'): void { echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($t) . '</p></div>'; };
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('AlgiBazar stock link (Business Black)', 'sfm4u-wholesale') . '</h1>';
        $mk = 'sfm_wr_stock_msg_' . get_current_user_id();
        if (is_array($m = get_transient($mk))) { delete_transient($mk); $msg((string) $m[1], (string) $m[0]); }
        try {
            if (isset($_POST['sfm_wr_stock_role'])) {
                check_admin_referer('sfm_wr_stock');
                $r = self::p('sfm_wr_stock_role');
                update_option(StockFeed::OPT_ROLE, in_array($r, ['sender', 'receiver'], true) ? $r : 'off', false);
                $msg(__('Saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_stock_remove'])) {
                check_admin_referer('sfm_wr_stock');
                $k = StockFeed::keys(); unset($k[self::p('sfm_wr_stock_remove')]); update_option(StockFeed::OPT_KEYS, $k, false);
                $msg(__('Removed.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_stock_test'])) {
                check_admin_referer('sfm_wr_stock');
                $d = StockFeed::call('GET', '/ping');
                $msg(sprintf(__('Connected to %s.', 'sfm4u-wholesale'), (string) ($d['site'] ?? '')));
            }
        } catch (DomainException $e) { $msg($e->getMessage(), 'error'); }
        $role = StockFeed::role();
        if ($role === 'receiver' && !empty($_GET['sfm_stock_pair'])) {
            $site = esc_url_raw(wp_unslash((string) ($_GET['site'] ?? '')));
            echo '<div class="notice notice-warning" style="padding:12px"><p><strong>' . esc_html(sprintf(__('Let %s add stock to Business Black members\' stores here?', 'sfm4u-wholesale'), CardLink::hostOf($site))) . '</strong></p>'
                . '<p>' . esc_html__('It can only add products (as drafts) and quantities to stores of members who bought from it. It cannot see orders, customers or money here.', 'sfm4u-wholesale') . '</p><form method="post">';
            wp_nonce_field('sfm_wr_stock_pair');
            echo '<input type="hidden" name="site" value="' . esc_attr($site) . '"><input type="hidden" name="back" value="' . esc_attr(esc_url_raw(wp_unslash((string) ($_GET['back'] ?? '')))) . '"><input type="hidden" name="state" value="' . esc_attr(sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')))) . '">'
                . '<button class="button button-primary" name="sfm_wr_stock_approve" value="1">' . esc_html__('Approve and connect', 'sfm4u-wholesale') . '</button></form></div>';
        }
        echo '<p>' . esc_html__('When a Business Black member (paid) buys from the wholesale shop and the order is Completed, the goods are added to their own store on AlgiBazar.com: more stock for products they already have, new products as drafts for them to check and publish.', 'sfm4u-wholesale') . '</p><form method="post">';
        wp_nonce_field('sfm_wr_stock');
        echo '<p><strong>' . esc_html__('This site is', 'sfm4u-wholesale') . '</strong><br>';
        foreach (['off' => __('Off', 'sfm4u-wholesale'), 'sender' => __('The wholesale shop (sends stock)', 'sfm4u-wholesale'), 'receiver' => __('AlgiBazar marketplace (receives stock into vendor stores)', 'sfm4u-wholesale')] as $k => $l) {
            echo '<label style="display:block;margin:4px 0"><input type="radio" name="sfm_wr_stock_role" value="' . esc_attr($k) . '"' . checked($role, $k, false) . '> ' . esc_html($l) . '</label>';
        }
        echo '</p><p><button class="button">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        if ($role !== 'receiver') {
            $t = get_option(StockFeed::OPT_TARGET, []);
            echo '<h2>' . esc_html__('Connect to AlgiBazar', 'sfm4u-wholesale') . '</h2><form method="post">';
            wp_nonce_field('sfm_wr_stock');
            echo '<input name="url" class="regular-text" value="' . esc_attr(is_array($t) && !empty($t['url']) ? $t['url'] : 'https://algibazar.com') . '"> <button class="button button-primary" name="sfm_wr_stock_start" value="1">' . esc_html__('Connect automatically', 'sfm4u-wholesale') . '</button>';
            if (is_array($t) && !empty($t['key_id'])) { echo ' <button class="button" name="sfm_wr_stock_test" value="1">' . esc_html__('Test connection', 'sfm4u-wholesale') . '</button>'; }
            echo '</form>';
        }
        if ($role === 'receiver') {
            echo '<h2>' . esc_html__('Connected wholesale shops', 'sfm4u-wholesale') . '</h2><ul>';
            foreach (StockFeed::keys() as $id => $k) {
                echo '<li>' . esc_html($k['name'] . ' (' . $id . ', ' . substr((string) $k['created'], 0, 10) . ')') . ' <form method="post" style="display:inline">';
                wp_nonce_field('sfm_wr_stock');
                echo '<button class="button-link-delete" name="sfm_wr_stock_remove" value="' . esc_attr($id) . '">' . esc_html__('Remove', 'sfm4u-wholesale') . '</button></form></li>';
            }
            echo '</ul>';
        }
        $log = array_reverse((array) get_option(StockFeed::OPT_LOG, []));
        if ($log) {
            echo '<h2>' . esc_html__('Log', 'sfm4u-wholesale') . '</h2><table class="widefat striped" style="max-width:900px">';
            foreach (array_slice($log, 0, 30) as $l) { echo '<tr><td>' . esc_html(get_date_from_gmt((string) $l[0], 'd M H:i')) . '</td><td>' . esc_html((string) $l[1]) . '</td></tr>'; }
            echo '</table>';
        }
        echo '</div>';
    }
}
