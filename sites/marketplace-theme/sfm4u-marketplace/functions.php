<?php
defined('ABSPATH') || exit;

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => 'Primary menu']);
});

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('sfm4u-marketplace', get_stylesheet_uri(), [], wp_get_theme()->get('Version'));
});

add_action('wp_head', static function (): void {
    echo '<link rel="icon" href="' . esc_url(get_template_directory_uri() . '/assets/sfm4u-logo-128.png') . '">' . "\n";
    echo '<meta name="theme-color" content="#020202">' . "\n";
});

/** Logo URL (theme file; can be replaced by the Site Icon later). */
function sfm4u_mp_logo(string $size = '128'): string {
    return get_template_directory_uri() . '/assets/sfm4u-logo-' . ($size === '512' ? '512' : '128') . '.png';
}

/** Main links (header + drawer). External links are SFM4U's own sites. */
function sfm4u_mp_links(): array {
    return [
        ['Business Software', home_url('/#software')],
        ['Modules', home_url('/modules/')],
        ['Plans', home_url('/#plans')],
        ['AlgiBazar', 'https://algibazar.com/'],
        ['SFM4U', 'https://sfm4u.com/'],
    ];
}

/* ===== 0.3.0: Owner edits without code (Owner 2026-11-22) =====
 * Texts: Appearance → Customize → "SFM4U texts". Menu: Appearance → Menus ("Main menu" in "Primary menu"). */
const SFM4U_MP_TEXTS = [
    'sfm_mp_home_badge' => ['Home: small label', 'SFM4U Business Software'],
    'sfm_mp_home_title' => ['Home: big title', 'One software for every business'],
    'sfm_mp_home_lead' => ['Home: text (English)', "From buying stock to the customer's door: shop, products, wholesale and retail prices, POS counter, member cards, khata, stock, delivery, reports and ERP link — in Bangla and English. Take the full software, or only the modules you need."],
    'sfm_mp_home_lead_bn' => ['Home: text (Bangla)', 'মাল কেনা থেকে কাস্টমারের বাসা পর্যন্ত — পুরো সফটওয়্যার, অথবা দরকারি মডিউল আলাদা করে।'],
    'sfm_mp_home_btn1' => ['Home: first button', 'Complete modules'],
    'sfm_mp_home_btn2' => ['Home: second button', 'All modules'],
    'sfm_mp_modules_title' => ['Modules page: title', 'SFM4U Business Software'],
    'sfm_mp_modules_lead' => ['Modules page: text (English)', 'One software, many modules — built in Noakhali. Open any item to see what it does; to get it, send a request.'],
    'sfm_mp_modules_lead_bn' => ['Modules page: text (Bangla)', 'একটি সফটওয়্যার, অনেক মডিউল — প্রতিটির বিস্তারিত দেখুন, নিতে চাইলে অনুরোধ পাঠান।'],
    'sfm_mp_footer_desc' => ['Footer: description', 'A connected platform for business, technology and community.'],
];

function sfm4u_mp_text(string $key): string {
    return (string) get_theme_mod($key, SFM4U_MP_TEXTS[$key][1] ?? '');
}

add_action('customize_register', static function (WP_Customize_Manager $c): void {
    $c->add_section('sfm_mp_texts', ['title' => 'SFM4U texts / লেখা', 'priority' => 20, 'description' => 'Change any text here. No code needed. / এখানে লেখা বদলান, কোড লাগবে না।']);
    foreach (SFM4U_MP_TEXTS as $k => [$label, $default]) {
        $c->add_setting($k, ['default' => $default, 'sanitize_callback' => 'sanitize_textarea_field', 'transport' => 'refresh']);
        $c->add_control($k, ['label' => $label, 'section' => 'sfm_mp_texts', 'type' => strlen($default) > 80 ? 'textarea' : 'text']);
    }
});

/** Default header menu (same items as sfm4u.com), created once so the Owner can edit it in Appearance → Menus. */
function sfm4u_mp_default_nav(): array {
    $sfm = 'https://sfm4u.com';
    return [['Home', $sfm . '/'], ['What We Do', $sfm . '/what-we-do/'], ['Jobs', $sfm . '/jobs/'], ['Business', 'https://business.sfm4u.com/'], ['Projects', $sfm . '/projects/'],
        ['Services', $sfm . '/services/'], ['Marketplace', home_url('/')], ['Real Estate', 'https://realestate.sfm4u.com/'], ['Partnership', 'https://partnership.sfm4u.com/'], ['About', $sfm . '/about/']];
}

add_action('init', static function (): void {
    if (get_option('sfm_mp_menu_made') || !function_exists('wp_create_nav_menu')) { return; }
    $locations = get_theme_mod('nav_menu_locations', []);
    if (!empty($locations['primary'])) { update_option('sfm_mp_menu_made', 1); return; }
    $menu = wp_get_nav_menu_object('Main menu');
    $id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu('Main menu');
    if ($id <= 0 || is_wp_error($id)) { return; }
    if (!$menu) {
        foreach (sfm4u_mp_default_nav() as $i => [$t, $u]) {
            wp_update_nav_menu_item($id, 0, ['menu-item-title' => $t, 'menu-item-url' => $u, 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1]);
        }
    }
    $locations['primary'] = $id;
    set_theme_mod('nav_menu_locations', $locations);
    update_option('sfm_mp_menu_made', 1);
}, 20);

/** Header links: from the editable menu; falls back to the default list. */
function sfm4u_mp_nav_items(): array {
    $loc = get_nav_menu_locations();
    if (!empty($loc['primary'])) {
        $items = wp_get_nav_menu_items((int) $loc['primary']);
        if ($items) { return array_map(static fn($i) => [(string) $i->title, (string) $i->url], array_values(array_filter($items, static fn($i) => (int) $i->menu_item_parent === 0))); }
    }
    return sfm4u_mp_default_nav();
}
