<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Erp;

use DomainException;

defined('ABSPATH') || exit;

final class ErpAdmin {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 20);
    }

    public static function menu(): void {
        add_submenu_page('sfm-bos', __('ERP link (ERPNext)', 'sfm4u-wholesale'), __('ERP Link (ERPNext)', 'sfm4u-wholesale'), 'manage_woocommerce', 'sfm-wr-erp', [self::class, 'page']);
    }

    private static function p(string $k): string {
        return isset($_POST[$k]) && is_string($_POST[$k]) ? trim(sanitize_text_field(wp_unslash($_POST[$k]))) : '';
    }

    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('You cannot manage the ERP link.', 'sfm4u-wholesale')); }
        echo '<div class="wrap sfm-wr"><h1>' . esc_html__('ERP link (ERPNext)', 'sfm4u-wholesale') . '</h1>';
        $rows = null;
        $msg = static function (string $t, string $type = 'success'): void { echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($t) . '</p></div>'; };
        try {
            if (isset($_POST['sfm_wr_erp_save'])) {
                check_admin_referer('sfm_wr_erp');
                ErpSync::save(['url' => self::p('url'), 'key' => self::p('key'), 'secret' => self::p('secret'), 'warehouse' => self::p('warehouse'),
                    'direction' => self::p('direction'), 'enabled' => self::p('enabled'), 'auto' => self::p('auto')]);
                $msg(__('Saved.', 'sfm4u-wholesale'));
            } elseif (isset($_POST['sfm_wr_erp_test'])) {
                check_admin_referer('sfm_wr_erp');
                $msg(sprintf(__('Connected to ERPNext as %s.', 'sfm4u-wholesale'), ErpSync::client()->whoAmI()));
            } elseif (isset($_POST['sfm_wr_erp_preview'])) {
                check_admin_referer('sfm_wr_erp');
                $rows = ErpSync::run(true);
                $msg(__('Preview only — nothing was changed.', 'sfm4u-wholesale'), 'info');
            } elseif (isset($_POST['sfm_wr_erp_items_preview'])) {
                check_admin_referer('sfm_wr_erp');
                $todo = ErpSync::exportItems(true);
                $msg(sprintf(__('%d website products are not in ERPNext yet.', 'sfm4u-wholesale'), count($todo)) . ($todo ? ' ' . implode(', ', array_slice($todo, 0, 30)) . (count($todo) > 30 ? ' …' : '') : ''), 'info');
            } elseif (isset($_POST['sfm_wr_erp_items_copy'])) {
                check_admin_referer('sfm_wr_erp');
                $made = ErpSync::exportItems(false);
                $msg(sprintf(__('%d products copied to ERPNext as Items (group "Products", unit "Nos").', 'sfm4u-wholesale'), count($made)));
            } elseif (isset($_POST['sfm_wr_erp_sync'])) {
                check_admin_referer('sfm_wr_erp');
                $rows = ErpSync::run(false);
                $msg(__('Stock synced.', 'sfm4u-wholesale'));
            }
        } catch (DomainException $e) {
            $msg($e->getMessage(), 'error');
        }
        $s = ErpSync::settings();
        echo '<p>' . esc_html__('Match: WooCommerce SKU = ERPNext Item Code, in one ERPNext warehouse. Choose one stock master. Always press Preview first.', 'sfm4u-wholesale') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('sfm_wr_erp');
        echo '<input type="hidden" name="sfm_wr_erp_save" value="1"><table class="form-table">'
            . '<tr><th>' . esc_html__('Turn on', 'sfm4u-wholesale') . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($s['enabled'], '1', false) . '> ' . esc_html__('ERP link is on', 'sfm4u-wholesale') . '</label></td></tr>'
            . '<tr><th>' . esc_html__('ERPNext address', 'sfm4u-wholesale') . '</th><td><input name="url" class="regular-text" placeholder="https://erp.sfm4u.com" value="' . esc_attr($s['url']) . '"></td></tr>'
            . '<tr><th>' . esc_html__('API key', 'sfm4u-wholesale') . '</th><td><input name="key" class="regular-text" autocomplete="off" value="' . esc_attr($s['key']) . '"></td></tr>'
            . '<tr><th>' . esc_html__('API secret', 'sfm4u-wholesale') . '</th><td><input name="secret" type="password" class="regular-text" autocomplete="off" placeholder="' . esc_attr($s['secret_enc'] !== '' ? __('(saved — type again to change)', 'sfm4u-wholesale') : '') . '"><p class="description">' . esc_html__('Make an ERPNext user only for the website, with stock permissions only. Type the secret here yourself; it is stored encrypted.', 'sfm4u-wholesale') . '</p></td></tr>'
            . '<tr><th>' . esc_html__('Warehouse', 'sfm4u-wholesale') . '</th><td><input name="warehouse" class="regular-text" placeholder="Stores - SFM" value="' . esc_attr($s['warehouse']) . '"></td></tr>'
            . '<tr><th>' . esc_html__('Stock master', 'sfm4u-wholesale') . '</th><td>'
            . '<label style="display:block"><input type="radio" name="direction" value="pull"' . checked($s['direction'], 'pull', false) . '> ' . esc_html__('ERPNext is the master: set website stock from ERPNext', 'sfm4u-wholesale') . '</label>'
            . '<label style="display:block"><input type="radio" name="direction" value="push"' . checked($s['direction'], 'push', false) . '> ' . esc_html__('Website is the master: send website stock to ERPNext (Stock Reconciliation)', 'sfm4u-wholesale') . '</label></td></tr>'
            . '<tr><th>' . esc_html__('Automatic', 'sfm4u-wholesale') . '</th><td><label><input type="checkbox" name="auto" value="1"' . checked($s['auto'], '1', false) . '> ' . esc_html__('Sync every hour', 'sfm4u-wholesale') . '</label></td></tr>'
            . '</table><p><button class="button button-primary">' . esc_html__('Save', 'sfm4u-wholesale') . '</button></p></form>';
        echo '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap">';
        wp_nonce_field('sfm_wr_erp');
        echo '<button class="button" name="sfm_wr_erp_test" value="1">' . esc_html__('Test connection', 'sfm4u-wholesale') . '</button>'
            . '<button class="button" name="sfm_wr_erp_preview" value="1">' . esc_html__('Preview changes', 'sfm4u-wholesale') . '</button>'
            . '<button class="button button-primary" name="sfm_wr_erp_sync" value="1" onclick="return confirm(this.dataset.q)" data-q="' . esc_attr__('Change stock now? Run Preview first.', 'sfm4u-wholesale') . '">' . esc_html__('Sync now', 'sfm4u-wholesale') . '</button>'
            . '<br><br><strong>' . esc_html__('First time:', 'sfm4u-wholesale') . '</strong> ' . esc_html__('products must exist in ERPNext with the same code as the website SKU.', 'sfm4u-wholesale') . ' '
            . '<button class="button" name="sfm_wr_erp_items_preview" value="1">' . esc_html__('Which products are missing in ERPNext?', 'sfm4u-wholesale') . '</button> '
            . '<button class="button" name="sfm_wr_erp_items_copy" value="1" onclick="return confirm(this.dataset.q)" data-q="' . esc_attr__('Create the missing products in ERPNext?', 'sfm4u-wholesale') . '">' . esc_html__('Copy missing products to ERPNext', 'sfm4u-wholesale') . '</button></form>';
        if (is_array($rows)) {
            $label = ['same' => __('same', 'sfm4u-wholesale'), 'set_site' => __('website will change', 'sfm4u-wholesale'), 'set_erp' => __('ERPNext will change', 'sfm4u-wholesale'),
                'not_on_site' => __('only in ERPNext', 'sfm4u-wholesale'), 'not_in_erp' => __('only on website', 'sfm4u-wholesale')];
            echo '<table class="widefat striped" style="max-width:900px;margin-top:12px"><thead><tr><th>SKU</th><th>' . esc_html__('Website', 'sfm4u-wholesale') . '</th><th>ERPNext</th><th>' . esc_html__('Result', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td><code>' . esc_html($r['sku']) . '</code></td><td>' . esc_html($r['site'] === null ? '—' : (string) $r['site']) . '</td><td>' . esc_html($r['erp'] === null ? '—' : (string) $r['erp']) . '</td><td>' . esc_html($label[$r['action']] ?? $r['action']) . ($r['qty'] !== null ? ' → ' . esc_html((string) $r['qty']) : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $log = get_option(ErpSync::LOG, []);
        if (is_array($log) && $log) {
            echo '<h2>' . esc_html__('Recent syncs', 'sfm4u-wholesale') . '</h2><ul>';
            foreach (array_reverse($log) as $l) { echo '<li>' . esc_html(get_date_from_gmt($l[0], 'd M Y H:i') . ' — ' . $l[1]) . '</li>'; }
            echo '</ul>';
        }
        echo '</div>';
    }
}
