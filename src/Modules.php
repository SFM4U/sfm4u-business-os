<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;

defined('ABSPATH') || exit;

/**
 * SFM4U Business OS: one plugin, separate systems ("modules").
 *
 * - The owner turns each system on or off for this site (SFM4U Business OS → Systems).
 * - Each system has its own admin entry, so it can be opened and managed on its own.
 * - Access list: a system can be given to a shop owner or member (with an end date for subscriptions).
 *   This records who may use which system (for their own store, the app, or a staff login here) and gives the
 *   capability "sfm_bos_use_<system>". It never opens another business's data by itself.
 * - POS needs a safer database connection; the POS card installs or removes it with one button.
 */
final class Modules {
    public const OPT = 'sfm_bos_modules';
    public const GRANTS = 'sfm_bos_grants';
    public const SLUG = 'sfm-bos';
    public const MU_FILE = 'sfm4u-business-os-pos.php';

    /** slug => [English name, Bangla name, what it does, admin page (admin.php?page=…)] */
    public static function all(): array {
        return [
            'wholesale' => ['SFM4U Wholesale System', 'SFM4U হোলসেল সিস্টেম', 'Wholesale prices and pack sizes, approved buyers, bulk orders, B2B credit and quotes.', 'sfmwh-rules'],
            'retail' => ['SFM4U Retail Prices (B2C)', 'SFM4U খুচরা দাম (B2C)', 'Price sets per audience: who can see (public, customers, Premium, Business, shops, only me) and who can buy; quantity savings, "buy 7, 1 off" offers, private cost price.', ''],
            'membership' => ['SFM4U Membership System', 'SFM4U মেম্বারশিপ সিস্টেম', 'Member cards (Regular, Premium, Business, Business Black), khata, points, link to account.sfm4u.com.', 'sfm-wr-scan'],
            'pos' => ['SFM4U POS System', 'SFM4U POS সিস্টেম', 'Counter sale screen, registers and cashiers, stock that stays correct with online orders.', 'sfm4u-counter'],
            'receipt' => ['SFM4U Receipt System', 'SFM4U রিসিট সিস্টেম', '80 mm order slips, automatic printing of new orders, invoice profile.', 'sfm-wr-autoprint'],
            'erp' => ['SFM4U ERP Link', 'SFM4U ERP লিংক', 'Stock and products shared with ERPNext (the main ledger).', 'sfm-wr-erp'],
            'delivery' => ['SFM4U Delivery & Messages', 'SFM4U ডেলিভারি ও মেসেজ', 'Courier booking (Steadfast) and WhatsApp order messages.', 'sfm-wr-ops'],
            'stocklink' => ['SFM4U AlgiBazar Stock Link', 'SFM4U AlgiBazar স্টক লিংক', 'Business Black purchases go to the member\'s own AlgiBazar store stock.', 'sfm-wr-stockfeed'],
        ];
    }

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 9);
        add_action('admin_init', [self::class, 'actions']);
        add_filter('user_has_cap', [self::class, 'caps'], 10, 4);
    }

    /* ---------------- on / off ---------------- */

    public static function on(string $slug): bool {
        $o = get_option(self::OPT, []);
        if (!is_array($o) || !array_key_exists($slug, $o)) { return true; } // existing sites keep everything they had
        return (bool) $o[$slug];
    }

    public static function set(string $slug, bool $on): void {
        if (!isset(self::all()[$slug])) { throw new DomainException('Unknown system.'); }
        $o = get_option(self::OPT, []);
        $o = is_array($o) ? $o : [];
        $o[$slug] = $on;
        update_option(self::OPT, $o, true);
    }

    /* ---------------- access list ---------------- */

    /** Pure: is a grant still valid on $today (Y-m-d)? Empty end date = no end. */
    public static function grantValid(string $until, string $today): bool {
        return $until === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 && $until >= $today);
    }

    public static function grants(int $uid): array {
        $g = get_user_meta($uid, self::GRANTS, true);
        return is_array($g) ? $g : [];
    }

    public static function grant(int $uid, string $slug, string $until = ''): void {
        if (!isset(self::all()[$slug])) { throw new DomainException(__('Choose a system.', 'sfm4u-wholesale')); }
        if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) { throw new DomainException(__('End date must look like 2026-12-31.', 'sfm4u-wholesale')); }
        if (!get_userdata($uid)) { throw new DomainException(__('User not found.', 'sfm4u-wholesale')); }
        $g = self::grants($uid);
        $g[$slug] = ['until' => $until, 'by' => get_current_user_id(), 'at' => current_time('mysql')];
        update_user_meta($uid, self::GRANTS, $g);
    }

    public static function revoke(int $uid, string $slug): void {
        $g = self::grants($uid);
        unset($g[$slug]);
        $g ? update_user_meta($uid, self::GRANTS, $g) : delete_user_meta($uid, self::GRANTS);
    }

    public static function userHas(int $uid, string $slug): bool {
        if (!self::on($slug)) { return false; }
        if (user_can($uid, 'manage_options')) { return true; }
        $g = self::grants($uid)[$slug] ?? null;
        return is_array($g) && self::grantValid((string) ($g['until'] ?? ''), current_time('Y-m-d'));
    }

    public static function caps(array $caps, array $required, array $args, $user): array {
        foreach ($required as $cap) {
            if (is_string($cap) && strpos($cap, 'sfm_bos_use_') === 0 && $user instanceof \WP_User) {
                $slug = substr($cap, 12);
                if (isset(self::all()[$slug])) {
                    $g = self::grants((int) $user->ID)[$slug] ?? null;
                    $caps[$cap] = self::on($slug) && (!empty($caps['manage_options']) || (is_array($g) && self::grantValid((string) ($g['until'] ?? ''), current_time('Y-m-d'))));
                }
            }
        }
        return $caps;
    }

    /** Everyone who was given a system: [uid, slug, until]. */
    public static function grantList(): array {
        $out = [];
        foreach (get_users(['meta_key' => self::GRANTS, 'fields' => ['ID'], 'number' => 500]) as $u) {
            foreach (self::grants((int) $u->ID) as $slug => $g) { $out[] = [(int) $u->ID, (string) $slug, (string) ($g['until'] ?? '')]; }
        }
        return $out;
    }

    /* ---------------- POS database setup ---------------- */

    public static function posStatus(): array {
        global $wpdb;
        $db = WP_CONTENT_DIR . '/db.php';
        $ours = is_file($db) && strpos((string) file_get_contents($db), 'SFM4U\\Inventory\\LossAwareWpdb') !== false;
        return [
            'dropin' => $ours,
            'other_dropin' => is_file($db) && !$ours,
            'connection' => is_object($wpdb) && get_class($wpdb) === 'SFM4U\\Inventory\\LossAwareWpdb',
            'constants' => defined('SFM4U_B2B_ENABLED') && SFM4U_B2B_ENABLED === true && defined('SFM4U_OPERATIONS_ENABLED') && SFM4U_OPERATIONS_ENABLED === true,
            'schema' => get_option('sfm4u_operations_schema') === '3',
            'mu' => is_file(WPMU_PLUGIN_DIR . '/' . self::MU_FILE),
        ];
    }

    public static function posReady(): bool {
        $s = self::posStatus();
        return $s['dropin'] && $s['connection'] && $s['constants'];
    }

    public static function posInstall(): void {
        global $wpdb;
        $s = self::posStatus();
        if ($s['other_dropin']) { throw new DomainException(__('Another plugin already uses wp-content/db.php (often a cache plugin). POS was not set up. Ask SFM4U support.', 'sfm4u-wholesale')); }
        foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'woocommerce_order_items'] as $t) {
            $e = (string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t));
            if ($e !== '' && strtolower($e) !== 'innodb') { throw new DomainException(sprintf(__('Table %s is not InnoDB. POS needs InnoDB tables.', 'sfm4u-wholesale'), $t)); }
        }
        if (!wp_is_writable(WP_CONTENT_DIR)) { throw new DomainException(__('wp-content is not writable.', 'sfm4u-wholesale')); }
        $src = dirname(__DIR__) . '/modules/inventory-bridge/src/LossAwareWpdb.php';
        if (!wp_mkdir_p(WP_CONTENT_DIR . '/sfm4u-db') || !copy($src, WP_CONTENT_DIR . '/sfm4u-db/LossAwareWpdb.php')) { throw new DomainException(__('Could not copy the database file.', 'sfm4u-wholesale')); }
        if (!wp_mkdir_p(WPMU_PLUGIN_DIR)) { throw new DomainException(__('Could not create mu-plugins folder.', 'sfm4u-wholesale')); }
        $mu = "<?php\n/**\n * Plugin Name: SFM4U Business OS — POS switch\n * Description: Turns on POS and B2B for SFM4U Business OS. Remove it from SFM4U Business OS → Systems → POS.\n */\n"
            . "defined('ABSPATH') || exit;\nif (!defined('SFM4U_B2B_ENABLED')) { define('SFM4U_B2B_ENABLED', true); }\nif (!defined('SFM4U_OPERATIONS_ENABLED')) { define('SFM4U_OPERATIONS_ENABLED', true); }\n";
        if (file_put_contents(WPMU_PLUGIN_DIR . '/' . self::MU_FILE, $mu) === false) { throw new DomainException(__('Could not write the POS switch.', 'sfm4u-wholesale')); }
        $drop = "<?php\n// SFM4U Business OS — POS database connection. Remove from SFM4U Business OS → Systems → POS.\n"
            . "if (is_file(WP_CONTENT_DIR . '/sfm4u-db/LossAwareWpdb.php')) {\n    require_once WP_CONTENT_DIR . '/sfm4u-db/LossAwareWpdb.php';\n    \$wpdb = new \\SFM4U\\Inventory\\LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);\n}\n";
        if (file_put_contents(WP_CONTENT_DIR . '/db.php', $drop) === false) {
            @unlink(WPMU_PLUGIN_DIR . '/' . self::MU_FILE);
            throw new DomainException(__('Could not write wp-content/db.php.', 'sfm4u-wholesale'));
        }
    }

    public static function posRemove(): void {
        $s = self::posStatus();
        if ($s['dropin']) { @unlink(WP_CONTENT_DIR . '/db.php'); }
        @unlink(WPMU_PLUGIN_DIR . '/' . self::MU_FILE);
    }

    /* ---------------- admin ---------------- */

    public static function menu(): void {
        add_menu_page(__('SFM4U Business Software', 'sfm4u-wholesale'), __('SFM4U Business Software', 'sfm4u-wholesale'), 'manage_options', self::SLUG, [self::class, 'page'], 'dashicons-store', 55);
        add_submenu_page(self::SLUG, __('Systems', 'sfm4u-wholesale'), __('Systems', 'sfm4u-wholesale'), 'manage_options', self::SLUG, [self::class, 'page']);
    }

    private static function p(string $k): string {
        return isset($_POST[$k]) && is_string($_POST[$k]) ? trim(sanitize_text_field(wp_unslash($_POST[$k]))) : '';
    }

    public static function actions(): void {
        if (($_GET['page'] ?? '') !== self::SLUG || empty($_POST['sfm_bos']) || !current_user_can('manage_options')) { return; }
        check_admin_referer('sfm_bos_modules');
        $k = 'sfm_bos_mod_msg_' . get_current_user_id();
        try {
            $act = self::p('sfm_bos');
            if ($act === 'toggle') {
                $slug = self::p('slug');
                self::set($slug, self::p('to') === 'on');
                $msg = sprintf(__('%s is now %s.', 'sfm4u-wholesale'), self::all()[$slug][0], self::p('to') === 'on' ? __('ON', 'sfm4u-wholesale') : __('OFF', 'sfm4u-wholesale'));
            } elseif ($act === 'grant') {
                $who = self::p('who');
                $u = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
                if (!$u) { throw new DomainException(__('User not found. Type the email or username.', 'sfm4u-wholesale')); }
                self::grant((int) $u->ID, self::p('slug'), self::p('until'));
                $msg = sprintf(__('%1$s can now use %2$s.', 'sfm4u-wholesale'), $u->user_email ?: $u->user_login, self::all()[self::p('slug')][0]);
            } elseif ($act === 'revoke') {
                self::revoke((int) self::p('uid'), self::p('slug'));
                $msg = __('Access removed.', 'sfm4u-wholesale');
            } elseif ($act === 'pos_install') {
                self::posInstall();
                $msg = __('POS database connection installed. Open this page again to finish the POS setup.', 'sfm4u-wholesale');
            } elseif ($act === 'pos_remove') {
                self::posRemove();
                $msg = __('POS database connection removed. Shop runs as before; POS screens are off.', 'sfm4u-wholesale');
            } else { return; }
            set_transient($k, ['success', $msg], 300);
        } catch (DomainException $e) {
            set_transient($k, ['error', $e->getMessage()], 300);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function form(string $act, array $hidden, string $label, string $class = 'button'): string {
        $h = '<form method="post" style="display:inline">' . wp_nonce_field('sfm_bos_modules', '_wpnonce', true, false) . '<input type="hidden" name="sfm_bos" value="' . esc_attr($act) . '">';
        foreach ($hidden as $n => $v) { $h .= '<input type="hidden" name="' . esc_attr($n) . '" value="' . esc_attr((string) $v) . '">'; }
        return $h . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $k = 'sfm_bos_mod_msg_' . get_current_user_id();
        echo '<div class="wrap"><h1>' . esc_html__('SFM4U Business Software — Systems', 'sfm4u-wholesale') . '</h1>'
            . '<p>' . esc_html__('One software made of separate systems. Each system can be turned on or off and improved on its own without breaking the others. / একটি সফটওয়্যার, আলাদা আলাদা সিস্টেম — প্রতিটি আলাদাভাবে চালু/বন্ধ ও উন্নত করা যায়।', 'sfm4u-wholesale') . '</p>';
        if (is_array($m = get_transient($k))) { delete_transient($k); echo '<div class="notice notice-' . esc_attr((string) $m[0]) . '"><p>' . esc_html((string) $m[1]) . '</p></div>'; }
        echo '<p>' . esc_html__('Each system works on its own. Turn a system on or off for this site, open its own admin, or give it to a shop owner or member below.', 'sfm4u-wholesale') . '</p>'
            . '<style>.sfm-bos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin:16px 0}.sfm-bos-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px}.sfm-bos-card h2{margin:0 0 2px;font-size:16px}.sfm-bos-card .bn{color:#555;margin:0 0 8px}.sfm-bos-on{color:#0a7d3b;font-weight:600}.sfm-bos-off{color:#b32d2e;font-weight:600}.sfm-bos-card .actions{margin-top:10px;display:flex;gap:6px;flex-wrap:wrap}</style><div class="sfm-bos-grid">';
        $pos = self::posStatus();
        foreach (self::all() as $slug => [$en, $bn, $what, $pageSlug]) {
            $on = self::on($slug);
            echo '<div class="sfm-bos-card"><h2>' . esc_html($en) . '</h2><p class="bn">' . esc_html($bn) . '</p><p>' . esc_html($what) . '</p><p>'
                . ($on ? '<span class="sfm-bos-on">● ' . esc_html__('ON', 'sfm4u-wholesale') . '</span>' : '<span class="sfm-bos-off">● ' . esc_html__('OFF', 'sfm4u-wholesale') . '</span>') . '</p>';
            if ($slug === 'pos') {
                $ready = self::posReady();
                echo '<p>' . ($ready ? ($pos['schema'] ? '✅ ' . esc_html__('POS database ready.', 'sfm4u-wholesale') : '⏳ ' . esc_html__('Connection ready; tables are being created on the next admin page load.', 'sfm4u-wholesale'))
                    : '⚠️ ' . esc_html__('POS needs its safe database connection first.', 'sfm4u-wholesale')) . '</p>';
                if ($pos['other_dropin']) { echo '<p class="sfm-bos-off">' . esc_html__('Another db.php is present. POS cannot be installed automatically.', 'sfm4u-wholesale') . '</p>'; }
            }
            echo '<div class="actions">' . self::form('toggle', ['slug' => $slug, 'to' => $on ? 'off' : 'on'], $on ? __('Turn off', 'sfm4u-wholesale') : __('Turn on', 'sfm4u-wholesale'), $on ? 'button' : 'button button-primary');
            if ($on && $pageSlug !== '' && ($slug !== 'pos' || ($pos['schema'] && self::posReady()))) {
                echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=' . $pageSlug)) . '">' . esc_html__('Open', 'sfm4u-wholesale') . '</a>';
            }
            if ($slug === 'pos' && $on) {
                if (!$pos['dropin'] && !$pos['other_dropin']) { echo ' ' . self::form('pos_install', [], __('Set up POS database', 'sfm4u-wholesale'), 'button button-primary'); }
                if ($pos['dropin'] || $pos['mu']) { echo ' ' . self::form('pos_remove', [], __('Remove POS database (rollback)', 'sfm4u-wholesale')); }
            }
            echo '</div></div>';
        }
        echo '</div><h2>' . esc_html__('Give a system to a shop owner or member', 'sfm4u-wholesale') . '</h2>'
            . '<p>' . esc_html__('For subscriptions or special access. It records who may use which system and until when. It does not show this shop\'s private data to them.', 'sfm4u-wholesale') . '</p><form method="post">'
            . wp_nonce_field('sfm_bos_modules', '_wpnonce', true, false) . '<input type="hidden" name="sfm_bos" value="grant">'
            . '<input type="text" name="who" placeholder="' . esc_attr__('Email or username', 'sfm4u-wholesale') . '" required> <select name="slug">';
        foreach (self::all() as $slug => $d) { echo '<option value="' . esc_attr($slug) . '">' . esc_html($d[0]) . '</option>'; }
        echo '</select> <input type="date" name="until" aria-label="' . esc_attr__('End date (optional)', 'sfm4u-wholesale') . '"> <button class="button button-primary">' . esc_html__('Give access', 'sfm4u-wholesale') . '</button></form>';
        $list = self::grantList();
        if ($list) {
            echo '<table class="widefat striped" style="margin-top:12px;max-width:900px"><thead><tr><th>' . esc_html__('User', 'sfm4u-wholesale') . '</th><th>' . esc_html__('System', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Until', 'sfm4u-wholesale') . '</th><th></th></tr></thead><tbody>';
            $today = current_time('Y-m-d');
            foreach ($list as [$uid, $slug, $until]) {
                $u = get_userdata($uid);
                echo '<tr><td>' . esc_html($u ? ($u->user_email ?: $u->user_login) : '#' . $uid) . '</td><td>' . esc_html(self::all()[$slug][0] ?? $slug) . '</td><td>'
                    . esc_html($until === '' ? __('No end', 'sfm4u-wholesale') : $until) . (self::grantValid($until, $today) ? '' : ' (' . esc_html__('ended', 'sfm4u-wholesale') . ')') . '</td><td>'
                    . self::form('revoke', ['uid' => $uid, 'slug' => $slug], __('Remove', 'sfm4u-wholesale')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
