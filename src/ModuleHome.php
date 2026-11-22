<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;

defined('ABSPATH') || exit;

/**
 * Module contract (plan r6 §2). A module class implements this and is added to Modules::all() through the
 * filter "sfm4u_bos_modules" with a 5th item: the class name.
 *
 * dashboard() returns:
 *  - numbers:  list of [label, value, url?]                     (up to 6 tiles)
 *  - pages:    list of [title, admin page slug or admin URL]
 *  - settings: list of [key, label, type(text|number|checkbox|select|textarea), default, options?(for select)]
 *              ModuleHome draws and saves them in option "sfm_mod_<slug>"; read with ModuleHome::setting().
 *  - help:     [english text, bangla text]
 */
interface ModuleContract {
    public static function dashboard(): array;
}

/**
 * Each module's own home page (Owner 2026-11-22): click a module → its numbers, its pages, its settings,
 * who may use it, and help in English and Bangla. One page for all modules: admin.php?page=sfm-bos-module&m=<slug>.
 */
final class ModuleHome {
    public const SLUG = 'sfm-bos-module';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_menu', [self::class, 'hideFromMenu'], 10000);
        add_action('admin_init', [self::class, 'save']);
    }

    public static function url(string $slug, string $tab = ''): string {
        return admin_url('admin.php?page=' . self::SLUG . '&m=' . rawurlencode($slug) . ($tab !== '' ? '&tab=' . rawurlencode($tab) : ''));
    }

    public static function menu(): void {
        add_submenu_page(Modules::SLUG, __('Module', 'sfm4u-wholesale'), __('Module', 'sfm4u-wholesale'), 'manage_options', self::SLUG, [self::class, 'page']);
    }

    /** The page is reached from the Dashboard and Systems cards, not from the left menu. */
    public static function hideFromMenu(): void {
        global $submenu;
        foreach (($submenu[Modules::SLUG] ?? []) as $i => $item) {
            if (($item[2] ?? '') === self::SLUG) { unset($submenu[Modules::SLUG][$i]); }
        }
    }

    /* ---------------- settings storage ---------------- */

    public static function settings(string $slug): array {
        $o = get_option('sfm_mod_' . $slug, []);
        return is_array($o) ? $o : [];
    }

    public static function setting(string $slug, string $key, $default = null) {
        static $busy = [];
        $o = self::settings($slug);
        if (array_key_exists($key, $o)) { return $o[$key]; }
        // Guard: a module whose dashboard() reads its own settings must not loop forever (found with E04, 2026-11-22).
        if (!empty($busy[$slug])) { return $default; }
        $busy[$slug] = true;
        try { $fields = self::dashboard($slug)['settings'] ?? []; } finally { unset($busy[$slug]); }
        foreach ($fields as $f) {
            if (($f[0] ?? '') === $key) { return $f[3] ?? $default; }
        }
        return $default;
    }

    /** Pure: clean submitted values against the field list. */
    public static function clean(array $fields, array $post): array {
        $out = [];
        foreach ($fields as $f) {
            [$key, $label, $type] = [(string) $f[0], (string) $f[1], (string) ($f[2] ?? 'text')];
            $raw = $post[$key] ?? null;
            switch ($type) {
                case 'checkbox': $out[$key] = !empty($raw); break;
                case 'number':
                    $v = is_scalar($raw) ? trim((string) $raw) : '';
                    if ($v !== '' && !is_numeric($v)) { throw new DomainException(sprintf(__('%s must be a number.', 'sfm4u-wholesale'), $label)); }
                    $out[$key] = $v === '' ? ($f[3] ?? 0) : $v + 0; break;
                case 'select':
                    $opts = array_map('strval', array_keys((array) ($f[4] ?? [])));
                    $v = is_scalar($raw) ? (string) $raw : '';
                    if (!in_array($v, $opts, true)) { throw new DomainException(sprintf(__('Choose a value for %s.', 'sfm4u-wholesale'), $label)); }
                    $out[$key] = $v; break;
                case 'textarea': $out[$key] = is_scalar($raw) ? sanitize_textarea_field((string) $raw) : ''; break;
                default: $out[$key] = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';
            }
        }
        return $out;
    }

    /* ---------------- dashboards ---------------- */

    /** The module's dashboard: from its class (contract) or the built-in description of an existing system. */
    public static function dashboard(string $slug): array {
        $d = Modules::all()[$slug] ?? null;
        if (!$d) { return []; }
        $base = ['numbers' => [], 'pages' => [], 'settings' => [], 'help' => [(string) $d[2], (string) $d[1]]];
        $class = $d[4] ?? '';
        if (is_string($class) && $class !== '' && class_exists($class) && is_subclass_of($class, ModuleContract::class)) {
            try { return array_merge($base, (array) $class::dashboard()); } catch (\Throwable $e) { $base['error'] = $e->getMessage(); return $base; }
        }
        return array_merge($base, self::builtin($slug, (string) $d[3]));
    }

    private static function countMeta(string $key): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $key));
    }

    /** Built-in dashboards for the systems that exist today. */
    private static function builtin(string $slug, string $main): array {
        $p = static fn(string $t, string $s): array => [$t, $s];
        switch ($slug) {
            case 'wholesale':
                return ['pages' => [$p('Wholesale rules', 'sfmwh-rules'), $p('Wholesale low stock', 'sfmwh-low-stock'), $p('SFM4U member price', 'sfmwh-member-price'), $p('B2B Hold Review', 'sfm4u-b2b-holds'), $p('B2B Spend Tiers', 'sfm4u-b2b-tiers'), $p('B2B Credit Accounts', 'sfm4u-b2b-accounts'), $p('Wholesale margin', 'sfm4u-margin')],
                    'help' => ['Wholesale prices and pack sizes for approved buyers and Business members; bulk orders, B2B credit, holds and spend tiers.', 'অনুমোদিত ক্রেতা ও Business মেম্বারদের জন্য হোলসেল দাম, প্যাক সাইজ, বড় অর্ডার, B2B বাকি ও লেভেল।']];
            case 'retail':
                return ['numbers' => [['Products with price sets / দাম সেট আছে', (string) self::countMeta(PriceAudience::META)], ['Products with private cost / কেনা দাম', (string) self::countMeta(PriceAudience::COST)]],
                    'pages' => [$p('Add product / পণ্য', 'sfm-product-sheet'), $p('All products', 'edit.php?post_type=product')],
                    'help' => ['Customer prices per audience (who can see, who can buy), quantity steps, Premium offers like "buy 7, 1 off", private cost price. Products are added on the one-page product sheet.', 'কে দেখবে আর কে কিনবে অনুযায়ী দাম, বেশি নিলে কম দাম, Premium অফার (৭ নিলে ১ ফ্রি), গোপন কেনা দাম। পণ্য যোগ হয় এক পেজের প্রোডাক্ট শিটে।']];
            case 'purchase':
                return ['numbers' => [['Buying trips saved / কেনার রেকর্ড', (string) (int) (wp_count_posts(Purchase::CPT)->publish ?? 0)], ['Products with real cost / আসল খরচ', (string) self::countMeta(Purchase::LANDED)]],
                    'pages' => [$p('Purchases & cost', 'sfm-purchases'), $p('Supplier Purchase Orders', 'sfm4u-supplier-orders'), $p('Supplier Payables', 'sfm4u-supplier-ap')],
                    'help' => ['Record each buying trip: goods, packs, fares, truck, labour and who was paid. The real cost per pack and per piece goes to the product as a private cost.', 'প্রতিটি কেনা রেকর্ড করুন: মাল, প্যাক, ভাড়া, ট্রাক, লেবার আর কাকে কত দিলেন। প্যাক ও পিস প্রতি আসল খরচ পণ্যে গোপন কেনা দাম হিসেবে যায়।']];
            case 'membership':
                return ['pages' => [$p('Card scan & khata', 'sfm-wr-scan'), $p('Due list', 'sfm-wr-due'), $p('Member cards', 'sfm-wr-cards'), $p('Points & wallet', 'sfm-wr-rewards'), $p('Shops', 'sfm-wr-shops'), $p('Card guide text', 'sfm-wr-guide'), $p('Account link', 'sfm-wr-link'), $p('SFM4U accounts', 'sfm-wr-accounts'), $p('Plan requests', 'sfm-wr-plans')],
                    'help' => ['Member cards (Regular, Premium, Business, Business Black), khata (due), points and wallet, link to accounts.sfm4u.com.', 'মেম্বার কার্ড (Regular, Premium, Business, Business Black), খাতা (বাকি), পয়েন্ট ও ওয়ালেট, accounts.sfm4u.com লিংক।']];
            case 'pos':
                return ['numbers' => [['POS database / POS ডাটাবেস', Modules::posReady() ? 'Ready ✅' : 'Not set up']],
                    'pages' => [$p('POS counter', 'sfm4u-counter'), $p('POS register', 'sfm4u-pos'), $p('POS settings', 'sfm4u-pos-settings'), $p('Daily Close', 'sfm4u-daily-close'), $p('Invoice settings', 'sfm4u-invoice-settings'), $p('Stock counts', 'sfm4u-stock-counts'), $p('Barcode labels', 'sfm4u-barcode-labels'), $p('Stock valuation and reorder', 'sfm4u-inventory-report'), $p('Inventory recovery', 'sfm4u-inventory-recovery')],
                    'help' => ['Counter sale screen with registers and cashiers, cash drawer, daily close, receipts; stock stays correct with online orders.', 'কাউন্টারে বিক্রির স্ক্রিন, রেজিস্টার ও ক্যাশিয়ার, ক্যাশ ড্রয়ার, দিনের হিসাব, রিসিট; অনলাইন অর্ডারের সাথে স্টক ঠিক থাকে।']];
            case 'receipt':
                return ['pages' => [$p('Receipt System (auto print)', 'sfm-wr-autoprint'), $p('Invoice settings', 'sfm4u-invoice-settings')],
                    'help' => ['80 mm order slips, automatic printing of new orders, invoice profile.', '৮০ মিমি অর্ডার স্লিপ, নতুন অর্ডার নিজে থেকে প্রিন্ট, ইনভয়েস প্রোফাইল।']];
            case 'erp':
                return ['pages' => [$p('ERP Link (ERPNext)', 'sfm-wr-erp')], 'help' => ['Stock and products shared with ERPNext, the main ledger.', 'ERPNext (মূল হিসাবের খাতা)-এর সাথে স্টক ও পণ্য শেয়ার।']];
            case 'delivery':
                return ['pages' => [$p('Delivery & Messages', 'sfm-wr-ops')], 'help' => ['Courier booking (Steadfast) and WhatsApp order messages.', 'কুরিয়ার বুকিং (Steadfast) ও WhatsApp-এ অর্ডারের মেসেজ।']];
            case 'stocklink':
                return ['pages' => [$p('AlgiBazar stock link', 'sfm-wr-stockfeed')], 'help' => ['Business Black purchases go to the member\'s own AlgiBazar store stock.', 'Business Black মেম্বারের কেনা মাল তার নিজের AlgiBazar দোকানের স্টকে যায়।']];
        }
        return $main !== '' ? ['pages' => [$p(__('Open', 'sfm4u-wholesale'), $main)]] : [];
    }

    /** Is an admin page registered right now? (When menus are not built — e.g. tests — every page counts as present.) */
    public static function pageExists(string $slug): bool {
        global $menu, $submenu;
        if (strpos($slug, '.php') !== false) { return true; }
        if (empty($menu) && empty($submenu)) { return true; }
        foreach ((array) $menu as $m) { if (($m[2] ?? '') === $slug) { return true; } }
        foreach ((array) $submenu as $items) { foreach ((array) $items as $i) { if (($i[2] ?? '') === $slug) { return true; } } }
        return false;
    }

    /* ---------------- save ---------------- */

    public static function save(): void {
        if (($_GET['page'] ?? '') !== self::SLUG || empty($_POST['sfm_mod_save']) || !current_user_can('manage_options')) { return; }
        check_admin_referer('sfm_mod_save');
        $slug = sanitize_key(wp_unslash((string) ($_GET['m'] ?? '')));
        $k = 'sfm_mod_msg_' . get_current_user_id();
        try {
            if (!isset(Modules::all()[$slug])) { throw new DomainException(__('Unknown system.', 'sfm4u-wholesale')); }
            $fields = self::dashboard($slug)['settings'] ?? [];
            update_option('sfm_mod_' . $slug, self::clean($fields, wp_unslash($_POST)), false);
            set_transient($k, ['success', __('Settings saved. / সেটিংস সেভ হয়েছে।', 'sfm4u-wholesale')], 300);
        } catch (DomainException $e) {
            set_transient($k, ['error', $e->getMessage()], 300);
        }
        wp_safe_redirect(self::url($slug, 'settings'));
        exit;
    }

    /* ---------------- page ---------------- */

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $slug = sanitize_key(wp_unslash((string) ($_GET['m'] ?? '')));
        $all = Modules::all();
        if (!isset($all[$slug])) {
            echo '<div class="wrap"><h1>' . esc_html__('Module not found', 'sfm4u-wholesale') . '</h1><p><a href="' . esc_url(admin_url('admin.php?page=' . Modules::SYSTEMS)) . '">' . esc_html__('Back to Systems', 'sfm4u-wholesale') . '</a></p></div>';
            return;
        }
        [$en, $bn] = $all[$slug];
        $tab = sanitize_key(wp_unslash((string) ($_GET['tab'] ?? 'overview')));
        $tabs = ['overview' => 'Overview / সারাংশ', 'settings' => 'Settings / সেটিংস', 'access' => 'Who can use / কে ব্যবহার করবে', 'help' => 'Help / সাহায্য'];
        $tab = isset($tabs[$tab]) ? $tab : 'overview';
        $d = self::dashboard($slug);
        $on = Modules::on($slug);
        echo '<div class="wrap sfm-mh"><p><a href="' . esc_url(admin_url('admin.php?page=' . Modules::SLUG)) . '">← Dashboard</a> · <a href="' . esc_url(admin_url('admin.php?page=' . Modules::SYSTEMS)) . '">Systems</a></p>'
            . '<h1>' . esc_html($en) . ' <small style="font-weight:400;color:#666">/ ' . esc_html($bn) . '</small> '
            . ($on ? '<span style="color:#0a7d3b;font-size:14px">● ON</span>' : '<span style="color:#b32d2e;font-size:14px">● OFF</span>') . '</h1>'
            . '<style>.sfm-mh .tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:14px 0}.sfm-mh .tile{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px;color:#1d2327;text-decoration:none;display:block}.sfm-mh .tile b{display:block;font-size:20px;margin-top:4px}'
            . '.sfm-mh .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}.sfm-mh .lnk{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;text-decoration:none;color:#1d2327}.sfm-mh .lnk:hover{border-color:#2271b1;color:#2271b1}.sfm-mh .box{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px;max-width:900px}</style>';
        if (is_array($m = get_transient('sfm_mod_msg_' . get_current_user_id()))) { delete_transient('sfm_mod_msg_' . get_current_user_id()); echo '<div class="notice notice-' . esc_attr((string) $m[0]) . '"><p>' . esc_html((string) $m[1]) . '</p></div>'; }
        if (!empty($d['error'])) { echo '<div class="notice notice-error"><p>' . esc_html__('This module could not load its dashboard:', 'sfm4u-wholesale') . ' ' . esc_html((string) $d['error']) . '</p></div>'; }
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) { echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($slug, $k)) . '">' . esc_html($label) . '</a>'; }
        echo '</h2>';
        if ($tab === 'overview') {
            echo '<div class="tiles"><div class="tile">Status / অবস্থা<b>' . ($on ? 'ON' : 'OFF') . '</b></div>';
            foreach (array_slice((array) ($d['numbers'] ?? []), 0, 6) as $n) {
                $u = (string) ($n[2] ?? '');
                echo $u !== '' ? '<a class="tile" href="' . esc_url($u) . '">' : '<div class="tile">';
                echo esc_html((string) ($n[0] ?? '')) . '<b>' . esc_html((string) ($n[1] ?? '')) . '</b>' . ($u !== '' ? '</a>' : '</div>');
            }
            echo '</div><h2>Pages / পেজ</h2>';
            $pages = array_values(array_filter((array) ($d['pages'] ?? []), fn($pg) => self::pageExists((string) ($pg[1] ?? ''))));
            if (!$on) { echo '<p>' . esc_html__('This system is OFF. Turn it on from Systems to use its pages.', 'sfm4u-wholesale') . '</p>'; }
            elseif (!$pages) { echo '<p>' . esc_html__('No pages yet.', 'sfm4u-wholesale') . '</p>'; }
            else {
                echo '<div class="grid">';
                foreach ($pages as [$t, $s]) { echo '<a class="lnk" href="' . esc_url(ControlCenter::url((string) $s)) . '">' . esc_html((string) $t) . '</a>'; }
                echo '</div>';
            }
        } elseif ($tab === 'settings') {
            $fields = (array) ($d['settings'] ?? []);
            echo '<div class="box">';
            if (!$fields) {
                echo '<p>' . esc_html__('This system keeps its settings on its own pages:', 'sfm4u-wholesale') . '</p><ul>';
                foreach ((array) ($d['pages'] ?? []) as [$t, $s]) { if (self::pageExists((string) $s)) { echo '<li><a href="' . esc_url(ControlCenter::url((string) $s)) . '">' . esc_html((string) $t) . '</a></li>'; } }
                echo '</ul>';
            } else {
                $vals = self::settings($slug);
                echo '<form method="post">' . wp_nonce_field('sfm_mod_save', '_wpnonce', true, false) . '<input type="hidden" name="sfm_mod_save" value="1"><table class="form-table">';
                foreach ($fields as $f) {
                    [$key, $label, $type] = [(string) $f[0], (string) $f[1], (string) ($f[2] ?? 'text')];
                    $v = array_key_exists($key, $vals) ? $vals[$key] : ($f[3] ?? '');
                    echo '<tr><th><label for="sfm-f-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
                    if ($type === 'checkbox') { echo '<input type="checkbox" id="sfm-f-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1"' . checked((bool) $v, true, false) . '>'; }
                    elseif ($type === 'select') {
                        echo '<select id="sfm-f-' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
                        foreach ((array) ($f[4] ?? []) as $ov => $ol) { echo '<option value="' . esc_attr((string) $ov) . '"' . selected((string) $v, (string) $ov, false) . '>' . esc_html((string) $ol) . '</option>'; }
                        echo '</select>';
                    } elseif ($type === 'textarea') { echo '<textarea id="sfm-f-' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="4" class="large-text">' . esc_textarea((string) $v) . '</textarea>'; }
                    else { echo '<input type="' . ($type === 'number' ? 'number" step="any' : 'text') . '" id="sfm-f-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr((string) $v) . '" class="regular-text">'; }
                    echo '</td></tr>';
                }
                echo '</table><p><button class="button button-primary">' . esc_html__('Save settings / সেভ', 'sfm4u-wholesale') . '</button></p></form>';
            }
            echo '</div>';
        } elseif ($tab === 'access') {
            $rows = array_values(array_filter(Modules::grantList(), fn($g) => $g[1] === $slug));
            echo '<div class="box"><p>' . esc_html__('Admins can always use every system. Here you see who else was given this system (shop owners, members) and until when. Give or remove access on the Systems page.', 'sfm4u-wholesale') . '</p>';
            if (!$rows) { echo '<p><em>' . esc_html__('Nobody else has this system yet.', 'sfm4u-wholesale') . '</em></p>'; }
            else {
                echo '<table class="widefat striped"><thead><tr><th>User</th><th>Until</th></tr></thead><tbody>';
                foreach ($rows as [$uid, , $until]) { $u = get_userdata($uid); echo '<tr><td>' . esc_html($u ? ($u->user_email ?: $u->user_login) : '#' . $uid) . '</td><td>' . esc_html($until === '' ? 'No end' : $until) . '</td></tr>'; }
                echo '</tbody></table>';
            }
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . Modules::SYSTEMS)) . '">' . esc_html__('Give access on Systems', 'sfm4u-wholesale') . '</a></p></div>';
        } else {
            [$hen, $hbn] = array_pad((array) ($d['help'] ?? []), 2, '');
            echo '<div class="box"><h3>English</h3><p>' . esc_html((string) $hen) . '</p><h3>বাংলা</h3><p>' . esc_html((string) $hbn) . '</p></div>';
        }
        echo '</div>';
    }
}
