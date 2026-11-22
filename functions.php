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
