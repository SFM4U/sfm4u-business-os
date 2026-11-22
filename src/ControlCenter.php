<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

defined('ABSPATH') || exit;

/**
 * SFM4U Business Software — Control Center (Owner 2026-11-22).
 *
 * One admin page that shows every part of the marketplace in one place: Dokan (dashboard, withdraw, reverse
 * withdrawal, vendors, modules, tools, status, settings, help), WooCommerce, POS counter/register, SFM4U Store
 * (members), AlgiBazar plugins (shop profiles, store location & pickup, customer profile) and every SFM4U system.
 *
 * - Links are collected from the real WordPress admin menu, so a page that exists is never missing and a page
 *   that does not exist is never shown. Each link keeps its own capability check.
 * - Dokan advertising entries (Pro Features, Black Friday, Chat with us …) are hidden; the owner can show them again.
 * - Numbers at the top (orders today, sales today, vendors, products waiting, withdraw requests, low stock) are
 *   cached for 5 minutes.
 */
final class ControlCenter {
    public const HIDE_PROMO = 'sfm_cc_hide_promos';
    public const CACHE = 'sfm_cc_stats';
    public const SHOW_OLD = 'sfm_cc_show_old';
    public const PROMO = '/pro\s*features|black\s*friday|chat\s*with\s*us|upgrade|go\s*pro|get\s*pro|cyber\s*monday|% ?off/i';

    /** group => [English, Bangla] (display order). */
    public static function groups(): array {
        return [
            'market' => ['Marketplace & shops (Dokan)', 'মার্কেটপ্লেস ও দোকান'],
            'products' => ['Products & stock', 'পণ্য ও স্টক'],
            'sales' => ['Selling, POS & receipts', 'বিক্রি, POS ও রিসিট'],
            'wholesale' => ['Wholesale & B2B', 'হোলসেল ও B2B'],
            'buying' => ['Buying & suppliers', 'কেনা ও সাপ্লায়ার'],
            'members' => ['Members & customers', 'মেম্বার ও কাস্টমার'],
            'money' => ['Money, reports & marketing', 'টাকা, রিপোর্ট ও মার্কেটিং'],
            'links' => ['Delivery, ERP & links', 'ডেলিভারি, ERP ও লিংক'],
            'settings' => ['Settings, tools & help', 'সেটিংস, টুলস ও সাহায্য'],
        ];
    }

    /** Ordered rules: the first matching title decides the group. */
    private const RULES = [
        ['links', '/delivery|courier|whatsapp|\berp\b|stock link/i'],
        ['buying', '/purchase|supplier|payable/i'],
        ['wholesale', '/wholesale|\bb2b\b|member price|spend tier|credit account/i'],
        ['sales', '/\bpos\b|counter|register|daily close|receipt|invoice|auto ?print|^orders$/i'],
        ['products', '/product|stock|barcode|label|inventor|categor|^tags$|attribute|review|brands?$/i'],
        ['members', '/customer|member|card|khata|^due|points|wallet|account|plan request|^shops$/i'],
        ['money', '/report|analytic|payment|withdraw|coupon|marketing|margin|earning|refund/i'],
        ['market', '/vendor|seller|store|pickup|location|profile|dokan|^modules$|^dashboard$|announcement/i'],
    ];

    /** Menus whose pages belong in the Control Center. */
    private const PARENTS = ['dokan', 'woocommerce', 'sfm-wr-scan', Modules::SLUG, 'edit.php?post_type=product', 'wc-admin&path=/analytics/overview', 'woocommerce-marketing'];

    public static function register(): void {
        add_action('admin_menu', [self::class, 'hidePromos'], 9999);
        add_action('admin_init', [self::class, 'actions']);
    }

    public static function hidePromo(): bool {
        return get_option(self::HIDE_PROMO, 'yes') !== 'no';
    }

    /** Clean a menu title: no HTML, no badge numbers. Returns [title, badge]. */
    public static function cleanTitle(string $raw): array {
        $badge = '';
        if (preg_match('/<span[^>]*>\s*(\d+)\s*<\/span>/i', $raw, $m)) { $badge = $m[1]; }
        $t = preg_replace('/<span[^>]*>.*?<\/span>/is', '', $raw) ?? $raw;
        $t = trim(html_entity_decode(wp_strip_all_tags($t), ENT_QUOTES, 'UTF-8'));
        return [preg_replace('/\s+/', ' ', $t) ?? $t, $badge];
    }

    public static function isPromo(string $title): bool {
        return preg_match(self::PROMO, $title) === 1;
    }

    public static function classify(string $title, string $parent = ''): string {
        foreach (self::RULES as [$g, $re]) {
            if (preg_match($re, $title) === 1) { return $g; }
        }
        return $parent === 'dokan' ? 'market' : 'settings';
    }

    /** admin URL for a menu entry. */
    public static function url(string $slug, string $parent = ''): string {
        if (preg_match('#^https?://#', $slug)) { return $slug; }
        if (strpos($slug, '.php') !== false) { return admin_url($slug); }
        if ($parent !== '' && strpos($parent, '.php') !== false && strpos($parent, 'admin.php') === false) {
            return admin_url($parent . (strpos($parent, '?') === false ? '?' : '&') . 'page=' . $slug);
        }
        return admin_url('admin.php?page=' . $slug);
    }

    /** Remove Dokan advertising entries from the left menu. */
    public static function hidePromos(): void {
        global $submenu;
        if (!self::hidePromo() || !is_array($submenu) || empty($submenu['dokan'])) { return; }
        foreach ($submenu['dokan'] as $i => $item) {
            if (self::isPromo(self::cleanTitle((string) ($item[0] ?? ''))[0])) { unset($submenu['dokan'][$i]); }
        }
    }

    /**
     * Pure: build the grouped link list from WordPress menu arrays.
     * @return array<string, list<array{title:string,badge:string,url:string,from:string}>>
     */
    public static function collect(array $menu, array $submenu, ?callable $can = null): array {
        $can = $can ?? static fn(string $cap): bool => current_user_can($cap);
        $out = array_fill_keys(array_keys(self::groups()), []);
        $seen = [];
        $add = static function (string $rawTitle, string $cap, string $slug, string $parent, string $from) use (&$out, &$seen, $can): void {
            [$title, $badge] = self::cleanTitle($rawTitle);
            if ($title === '' || self::isPromo($title) || ($cap !== '' && !$can($cap))) { return; }
            $url = self::url($slug, $parent);
            if (isset($seen[$url]) || $slug === Modules::SLUG) { return; } // not a link to this page itself
            $seen[$url] = true;
            $out[self::classify($title, $parent)][] = ['title' => $title, 'badge' => $badge, 'url' => $url, 'from' => $from];
        };
        $topTitle = [];
        foreach ($menu as $m) {
            if (!empty($m[2])) { $topTitle[(string) $m[2]] = self::cleanTitle((string) ($m[0] ?? ''))[0]; }
        }
        foreach (self::PARENTS as $p) {
            foreach (($submenu[$p] ?? []) as $item) {
                $add((string) ($item[0] ?? ''), (string) ($item[1] ?? ''), (string) ($item[2] ?? ''), $p, $topTitle[$p] ?? $p);
            }
        }
        // Top-level pages from SFM4U and AlgiBazar (POS counter, POS register, Store location …) and their sub pages.
        foreach ($menu as $m) {
            $slug = (string) ($m[2] ?? '');
            [$title] = self::cleanTitle((string) ($m[0] ?? ''));
            if ($slug === '' || in_array($slug, self::PARENTS, true) || preg_match('/sfm|algibazar|store|pickup|\bpos\b|location|vendor|dokan/i', $slug . ' ' . $title) !== 1) { continue; }
            $kids = $submenu[$slug] ?? [];
            if (!$kids) { $add((string) $m[0], (string) ($m[1] ?? ''), $slug, '', $title); continue; }
            foreach ($kids as $item) { $add((string) ($item[0] ?? ''), (string) ($item[1] ?? ''), (string) ($item[2] ?? ''), $slug, $title); }
        }
        return $out;
    }

    /* ---------------- numbers ---------------- */

    public static function stats(bool $fresh = false): array {
        $c = $fresh ? false : get_transient(self::CACHE);
        if (is_array($c)) { return $c; }
        global $wpdb;
        $s = ['orders_today' => 0, 'sales_today' => 0.0, 'processing' => 0, 'vendors' => 0, 'vendors_pending' => 0, 'products_pending' => 0, 'withdraw_pending' => 0, 'low_stock' => 0, 'at' => current_time('mysql')];
        if (function_exists('wc_get_orders')) {
            $ids = wc_get_orders(['limit' => 500, 'return' => 'ids', 'status' => ['wc-processing', 'wc-completed', 'wc-on-hold'], 'date_created' => '>=' . strtotime(current_time('Y-m-d') . ' 00:00:00 ' . wp_timezone_string())]);
            $s['orders_today'] = count($ids);
            foreach ($ids as $id) { $o = wc_get_order($id); if ($o) { $s['sales_today'] += (float) $o->get_total(); } }
            $s['processing'] = function_exists('wc_orders_count') ? (int) wc_orders_count('processing') : 0;
        }
        $roles = count_users()['avail_roles'] ?? [];
        $s['vendors'] = (int) ($roles['seller'] ?? 0);
        if ($s['vendors'] > 0) {
            $s['vendors_pending'] = count(get_users(['role' => 'seller', 'meta_key' => 'dokan_enable_selling', 'meta_value' => 'no', 'fields' => 'ID', 'number' => 1000]));
        }
        $s['products_pending'] = (int) (wp_count_posts('product')->pending ?? 0);
        $wt = $wpdb->prefix . 'dokan_withdraw';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wt)) === $wt) {
            $s['withdraw_pending'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wt} WHERE status = 0"); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        $low = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        $s['low_stock'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} ms ON ms.post_id=p.ID AND ms.meta_key='_manage_stock' AND ms.meta_value='yes'
             JOIN {$wpdb->postmeta} st ON st.post_id=p.ID AND st.meta_key='_stock' WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND CAST(st.meta_value AS DECIMAL(20,4)) <= %d", $low));
        set_transient(self::CACHE, $s, 300);
        return $s;
    }

    /** Plugins that are part of (or will move into) SFM4U Business Software. */
    public static function familyPlugins(): array {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $out = [];
        foreach (get_plugins() as $file => $d) {
            $name = (string) ($d['Name'] ?? '');
            if (preg_match('/algibazar|sfm4u|dokan/i', $name) !== 1) { continue; }
            $out[] = ['file' => $file, 'name' => $name, 'version' => (string) ($d['Version'] ?? ''), 'active' => is_plugin_active($file)];
        }
        return $out;
    }

    /* ---------------- page ---------------- */

    public static function actions(): void {
        if (($_GET['page'] ?? '') !== Modules::SLUG || empty($_POST['sfm_cc']) || !current_user_can('manage_options')) { return; }
        check_admin_referer('sfm_cc');
        $act = sanitize_key(wp_unslash((string) $_POST['sfm_cc']));
        if ($act === 'promos') { update_option(self::HIDE_PROMO, self::hidePromo() ? 'no' : 'yes', true); }
        if ($act === 'refresh') { delete_transient(self::CACHE); }
        if ($act === 'old') { update_option(self::SHOW_OLD, get_option(self::SHOW_OLD) === 'yes' ? 'no' : 'yes', false); }
        wp_safe_redirect(admin_url('admin.php?page=' . Modules::SLUG));
        exit;
    }

    private static function money(float $v): string {
        return function_exists('wc_price') ? wp_strip_all_tags(wc_price($v)) : number_format($v, 2);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        global $menu, $submenu;
        $links = self::collect(is_array($menu) ? $menu : [], is_array($submenu) ? $submenu : []);
        $s = self::stats();
        $nonce = wp_nonce_field('sfm_cc', '_wpnonce', true, false);
        echo '<div class="wrap sfm-cc"><h1>SFM4U Business Software — Dashboard / ড্যাশবোর্ড</h1>'
            . '<p>' . esc_html__('Everything in one place: marketplace, shops, products, stock, POS, wholesale, members, money and settings. Click a box to open it.', 'sfm4u-wholesale')
            . ' / সব কিছু এক জায়গায় — বক্সে ক্লিক করলে খুলবে।</p>'
            . '<style>.sfm-cc .tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin:14px 0}.sfm-cc .tile{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px;text-decoration:none;color:#1d2327;display:block}'
            . '.sfm-cc .tile b{display:block;font-size:22px;margin-top:4px}.sfm-cc .tile.warn b{color:#b32d2e}.sfm-cc h2{margin:22px 0 4px}.sfm-cc h2 small{color:#666;font-weight:400}'
            . '.sfm-cc .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px}.sfm-cc .lnk{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;text-decoration:none;color:#1d2327;display:flex;justify-content:space-between;gap:8px;align-items:center}'
            . '.sfm-cc .lnk:hover{border-color:#2271b1;color:#2271b1}.sfm-cc .lnk small{display:block;color:#777;font-size:11px}.sfm-cc .badge{background:#d63638;color:#fff;border-radius:10px;padding:1px 8px;font-size:12px}'
            . '.sfm-cc .find{width:100%;max-width:420px;padding:8px 10px;font-size:15px}.sfm-cc .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.sfm-cc table.plg td,.sfm-cc table.plg th{padding:6px 10px}</style>';
        $tile = static function (string $label, string $value, string $url = '', bool $warn = false): string {
            $tag = $url !== '' ? 'a href="' . esc_url($url) . '"' : 'div';
            return '<' . $tag . ' class="tile' . ($warn ? ' warn' : '') . '">' . esc_html($label) . '<b>' . esc_html($value) . '</b></' . ($url !== '' ? 'a' : 'div') . '>';
        };
        echo '<div class="tiles">'
            . $tile('Orders today / আজকের অর্ডার', (string) $s['orders_today'], admin_url('admin.php?page=wc-orders'))
            . $tile('Sales today / আজকের বিক্রি', self::money((float) $s['sales_today']), admin_url('admin.php?page=wc-orders'))
            . $tile('To pack & send / প্রসেসিং', (string) $s['processing'], admin_url('admin.php?page=wc-orders&status=wc-processing'), $s['processing'] > 0)
            . $tile('Shops (vendors) / দোকান', (string) $s['vendors'], admin_url('admin.php?page=dokan#/vendors'))
            . $tile('Shops waiting / অনুমোদন বাকি', (string) $s['vendors_pending'], admin_url('admin.php?page=dokan#/vendors?status=pending'), $s['vendors_pending'] > 0)
            . $tile('Products waiting / পণ্য রিভিউ', (string) $s['products_pending'], admin_url('edit.php?post_status=pending&post_type=product'), $s['products_pending'] > 0)
            . $tile('Withdraw requests / উইথড্র', (string) $s['withdraw_pending'], admin_url('admin.php?page=dokan#/withdraw?status=pending'), $s['withdraw_pending'] > 0)
            . $tile('Low stock / স্টক কম', (string) $s['low_stock'], admin_url('admin.php?page=sfmwh-low-stock'), $s['low_stock'] > 0)
            . $tile('POS', Modules::posReady() ? 'Ready ✅' : 'Not set up', admin_url('admin.php?page=' . Modules::SYSTEMS))
            . '</div><div class="row"><input type="search" class="find" id="sfm-cc-find" placeholder="Search: stock, withdraw, vendors, receipt … / খুঁজুন">'
            . '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="sfm_cc" value="refresh"><button class="button">' . esc_html__('Refresh numbers', 'sfm4u-wholesale') . '</button></form>'
            . ' <a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=sfm-product-sheet')) . '">+ Add product / পণ্য যোগ</a>'
            . ' <a class="button" href="' . esc_url(admin_url('admin.php?page=' . Modules::SYSTEMS)) . '">Systems on/off</a>'
            . ' <span style="color:#777">' . esc_html(sprintf(__('Numbers from %s (refresh every 5 minutes).', 'sfm4u-wholesale'), (string) $s['at'])) . '</span></div>';
        echo '<section class="grp"><h2>Systems — each with its own dashboard <small>/ সিস্টেম — প্রতিটির আলাদা ড্যাশবোর্ড</small></h2><div class="grid">';
        foreach (Modules::all() as $slug => $sys) {
            $on = Modules::on($slug);
            echo '<a class="lnk" href="' . esc_url(ModuleHome::url((string) $slug)) . '"><span>' . esc_html((string) $sys[0]) . '<small>' . esc_html((string) $sys[1]) . '</small></span>'
                . '<span style="color:' . ($on ? '#0a7d3b' : '#b32d2e') . '">●</span></a>';
        }
        echo '</div></section>';
        // Owner r7: the Dashboard shows our own modules. The old Dokan/WooCommerce pages stay reachable only behind this button until our module replaces each one.
        $showOld = get_option(self::SHOW_OLD) === 'yes';
        echo '<p><form method="post">' . $nonce . '<input type="hidden" name="sfm_cc" value="old"><button class="button">'
            . esc_html($showOld ? 'Hide old Dokan / WooCommerce pages' : 'Show old Dokan / WooCommerce pages (temporary, until our modules replace them)') . '</button></form></p>';
        foreach (self::groups() as $g => [$en, $bn]) {
            if (!$showOld || empty($links[$g])) { continue; }
            echo '<section class="grp"><h2>' . esc_html($en) . ' <small>/ ' . esc_html($bn) . '</small></h2><div class="grid">';
            foreach ($links[$g] as $l) {
                echo '<a class="lnk" href="' . esc_url($l['url']) . '"><span>' . esc_html($l['title']) . '<small>' . esc_html($l['from']) . '</small></span>'
                    . ($l['badge'] !== '' ? '<span class="badge">' . esc_html($l['badge']) . '</span>' : '') . '</a>';
            }
            echo '</div></section>';
        }
        echo '<h2>Plugins of this software <small>/ এই সফটওয়্যারের প্লাগইন</small></h2>'
            . '<p>' . esc_html__('AlgiBazar shop profiles, store location & pickup, customer profile and product experience will move inside SFM4U Business Software one by one, each with its own test. Until then they stay as they are.', 'sfm4u-wholesale') . '</p>'
            . '<table class="widefat striped plg" style="max-width:900px"><thead><tr><th>Plugin</th><th>Version</th><th>Status</th></tr></thead><tbody>';
        foreach (self::familyPlugins() as $p) {
            echo '<tr><td>' . esc_html($p['name']) . '</td><td>' . esc_html($p['version']) . '</td><td>' . ($p['active'] ? '<span style="color:#0a7d3b">● active</span>' : '<span style="color:#777">○ off (kept)</span>') . '</td></tr>';
        }
        echo '</tbody></table><p><form method="post">' . $nonce . '<input type="hidden" name="sfm_cc" value="promos"><button class="button">'
            . esc_html(self::hidePromo() ? __('Show Dokan advertising menus again', 'sfm4u-wholesale') : __('Hide Dokan advertising menus (Pro Features, Black Friday, Chat with us)', 'sfm4u-wholesale')) . '</button></form></p>'
            . '<script>(function(){var f=document.getElementById("sfm-cc-find");if(!f)return;f.addEventListener("input",function(){var q=f.value.toLowerCase().trim();document.querySelectorAll(".sfm-cc .grp").forEach(function(s){var any=false;s.querySelectorAll(".lnk").forEach(function(a){var hit=!q||a.textContent.toLowerCase().indexOf(q)>-1;a.style.display=hit?"":"none";any=any||hit;});s.style.display=any?"":"none";});});})();</script></div>';
    }
}
