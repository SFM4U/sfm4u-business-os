<?php
/**
 * Plugin Name: SFM4U Business Software — Marketplace (module store)
 * Description: SFM4U's own module showcase for marketplace.sfm4u.com: every module, business plan and skill with its own page. Visitors can look and send a request; they cannot download or edit. Private items are visible to admins only.
 * Version: 0.3.0
 * Author: SFM4U
 * Requires PHP: 8.1
 * Text Domain: sfm4u-module-store
 */
declare(strict_types=1);
namespace SFM4U\ModuleStore;

defined('ABSPATH') || exit;

const CPT = 'sfm_module';
const REQ = 'sfm_module_request';
const TAX_GROUP = 'sfm_module_group';
const TAX_TYPE = 'sfm_module_type';
const META = ['code' => 'Code (e.g. B01)', 'name_bn' => 'Name in Bangla', 'status' => 'Status (BUILT / READY / LATER)', 'priority' => 'Priority (P1 / P2 / P3)', 'version' => 'Version', 'price' => 'Price text (e.g. Coming soon)'];
const TYPES = ['module' => 'Module', 'plan' => 'Business plan', 'skill' => 'Skill', 'product' => 'SFM4U product', 'core' => 'Business Software', 'plugin' => 'Plugin', 'theme' => 'Theme'];

/* ---------------- data model ---------------- */

function register_types(): void {
    register_post_type(CPT, [
        'labels' => ['name' => 'Modules', 'singular_name' => 'Module', 'add_new_item' => 'Add module / plan / skill', 'edit_item' => 'Edit module', 'menu_name' => 'SFM4U Business Software'],
        'public' => true, 'has_archive' => 'modules', 'rewrite' => ['slug' => 'module', 'with_front' => false],
        'menu_icon' => 'dashicons-screenoptions', 'supports' => ['title', 'editor', 'excerpt', 'thumbnail'], 'show_in_rest' => false,
    ]);
    register_taxonomy(TAX_GROUP, CPT, ['labels' => ['name' => 'Groups', 'singular_name' => 'Group'], 'public' => true, 'hierarchical' => true, 'rewrite' => ['slug' => 'modules/group'], 'show_admin_column' => true]);
    register_taxonomy(TAX_TYPE, CPT, ['labels' => ['name' => 'Types', 'singular_name' => 'Type'], 'public' => true, 'hierarchical' => true, 'rewrite' => ['slug' => 'modules/type'], 'show_admin_column' => true]);
    register_post_type(REQ, ['labels' => ['name' => 'Module requests', 'singular_name' => 'Module request'], 'public' => false, 'show_ui' => true, 'show_in_menu' => 'edit.php?post_type=' . CPT, 'supports' => ['title', 'editor'], 'capabilities' => ['create_posts' => 'do_not_allow'], 'map_meta_cap' => true]);
}
add_action('init', __NAMESPACE__ . '\\register_types');

function meta(int $id, string $k): string { return (string) get_post_meta($id, '_sfm_' . $k, true); }

/** Pure: Ready / Soon chip from the status text. */
function chip(string $status): array {
    $s = strtoupper($status);
    if (str_starts_with($s, 'BUILT')) { return ['Ready', 'ready']; }
    if (str_starts_with($s, 'PARTLY')) { return ['Partly ready', 'part']; }
    if (str_starts_with($s, 'REVIEW')) { return ['In review', 'part']; }
    return ['Coming soon', 'soon'];
}

/* ---------------- seed from the SFM4U catalog (once) ---------------- */

function seed(): int {
    $file = __DIR__ . '/catalog.json';
    if (!is_file($file)) { return 0; }
    $rows = json_decode((string) file_get_contents($file), true);
    if (!is_array($rows)) { return 0; }
    $n = 0;
    foreach ($rows as $r) {
        $code = (string) ($r['code'] ?? '');
        if ($code === '' || get_posts(['post_type' => CPT, 'post_status' => 'any', 'meta_key' => '_sfm_code', 'meta_value' => $code, 'fields' => 'ids', 'numberposts' => 1])) { continue; }
        $id = wp_insert_post(['post_type' => CPT, 'post_status' => 'publish', 'post_title' => (string) $r['en'], 'post_excerpt' => (string) $r['what'],
            'post_content' => '<p>' . esc_html((string) $r['what']) . '</p>', 'menu_order' => $n], true);
        if (is_wp_error($id)) { continue; }
        foreach (['code' => $code, 'name_bn' => $r['bn'] ?? '', 'status' => $r['status'] ?? '', 'priority' => $r['p'] ?? '', 'price' => 'Coming soon'] as $k => $v) { update_post_meta($id, '_sfm_' . $k, (string) $v); }
        wp_set_object_terms($id, (string) ($r['group'] ?? 'Other'), TAX_GROUP);
        wp_set_object_terms($id, TYPES[$r['type'] ?? 'module'] ?? 'Module', TAX_TYPE);
        $n++;
    }
    return $n;
}

/** Keep status/version of seeded items in step with catalog.json (runs once each time the file changes). */
function sync(): int {
    $file = __DIR__ . '/catalog.json';
    if (!is_file($file)) { return 0; }
    $hash = md5_file($file);
    if (get_option('sfm_ms_catalog_hash') === $hash) { return 0; }
    $rows = json_decode((string) file_get_contents($file), true);
    $n = seed();
    foreach (is_array($rows) ? $rows : [] as $r) {
        $ids = get_posts(['post_type' => CPT, 'post_status' => 'any', 'meta_key' => '_sfm_code', 'meta_value' => (string) ($r['code'] ?? ''), 'fields' => 'ids', 'numberposts' => 1]);
        if (!$ids) { continue; }
        update_post_meta($ids[0], '_sfm_status', (string) ($r['status'] ?? ''));
        if (!empty($r['version'])) { update_post_meta($ids[0], '_sfm_version', (string) $r['version']); }
        if (!empty($r['type']) && isset(TYPES[$r['type']])) { wp_set_object_terms($ids[0], TYPES[$r['type']], TAX_TYPE); }
        $n++;
    }
    update_option('sfm_ms_catalog_hash', $hash, false);
    return $n;
}
add_action('init', __NAMESPACE__ . '\\sync', 20);

register_activation_hook(__FILE__, static function (): void {
    register_types();
    seed();
    // New marketplace site: pretty links so /modules/ works (only if the site still uses plain links).
    if ((string) get_option('permalink_structure') === '') { update_option('permalink_structure', '/%postname%/'); }
    flush_rewrite_rules();
});

/* ---------------- admin: fields ---------------- */

add_action('add_meta_boxes', static function (): void {
    add_meta_box('sfm_module_fields', 'Module details / মডিউলের তথ্য', static function (\WP_Post $p): void {
        wp_nonce_field('sfm_module_save', '_sfm_module_nonce');
        echo '<p>Tip: set the post to <strong>Private</strong> (Publish box → Visibility) to keep it for admins only. Visitors never get a download.</p><table class="form-table">';
        foreach (META as $k => $label) {
            echo '<tr><th><label for="sfm_' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="sfm_' . esc_attr($k) . '" name="sfm_meta[' . esc_attr($k) . ']" value="' . esc_attr(meta($p->ID, $k)) . '"></td></tr>';
        }
        echo '</table>';
    }, CPT, 'normal', 'high');
});

add_action('save_post_' . CPT, static function (int $id): void {
    if (!isset($_POST['_sfm_module_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_sfm_module_nonce'])), 'sfm_module_save') || !current_user_can('edit_post', $id) || wp_is_post_autosave($id)) { return; }
    $in = isset($_POST['sfm_meta']) && is_array($_POST['sfm_meta']) ? wp_unslash($_POST['sfm_meta']) : [];
    foreach (array_keys(META) as $k) { update_post_meta($id, '_sfm_' . $k, sanitize_text_field((string) ($in[$k] ?? ''))); }
});

add_filter('manage_' . CPT . '_posts_columns', static fn(array $c): array => array_slice($c, 0, 2) + ['sfm_code' => 'Code', 'sfm_status' => 'Status', 'sfm_p' => 'P'] + $c);
add_action('manage_' . CPT . '_posts_custom_column', static function (string $col, int $id): void {
    if ($col === 'sfm_code') { echo esc_html(meta($id, 'code')); }
    if ($col === 'sfm_status') { echo esc_html(meta($id, 'status')); }
    if ($col === 'sfm_p') { echo esc_html(meta($id, 'priority')); }
}, 10, 2);

/* ---------------- public: archive order + search ---------------- */

add_action('pre_get_posts', static function (\WP_Query $q): void {
    if (is_admin() || !$q->is_main_query() || !($q->is_post_type_archive(CPT) || $q->is_tax([TAX_GROUP, TAX_TYPE]))) { return; }
    $q->set('posts_per_page', 200);
    $q->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
    $s = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
    if ($s !== '') { $q->set('s', $s); }
    $st = isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '';
    if ($st === 'ready') { $q->set('meta_query', [['key' => '_sfm_status', 'value' => 'BUILT', 'compare' => 'LIKE']]); }
    if ($st === 'soon') { $q->set('meta_query', [['key' => '_sfm_status', 'value' => 'BUILT', 'compare' => 'NOT LIKE']]); }
});

add_filter('template_include', static function (string $t): string {
    if (is_singular(CPT)) { return __DIR__ . '/templates/single.php'; }
    if (is_post_type_archive(CPT) || is_tax([TAX_GROUP, TAX_TYPE])) { return __DIR__ . '/templates/archive.php'; }
    return $t;
});

add_action('wp_enqueue_scripts', static function (): void {
    if (is_singular(CPT) || is_post_type_archive(CPT) || is_tax([TAX_GROUP, TAX_TYPE])) {
        wp_enqueue_style('sfm4u-module-store', plugins_url('assets/store.css', __FILE__), [], '0.3.0');
    }
});

/* ---------------- request form ---------------- */

/** Pure-ish validation; returns clean data or throws. */
function clean_request(array $p): array {
    $name = sanitize_text_field((string) ($p['name'] ?? ''));
    $contact = sanitize_text_field((string) ($p['contact'] ?? ''));
    $shop = sanitize_text_field((string) ($p['shop'] ?? ''));
    $msg = sanitize_textarea_field((string) ($p['message'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) { throw new \DomainException('Please write your name.'); }
    if ($contact === '' || mb_strlen($contact) > 100 || (!is_email($contact) && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $contact))) { throw new \DomainException('Please give a phone number or email.'); }
    if (mb_strlen($msg) > 2000 || mb_strlen($shop) > 120) { throw new \DomainException('Message is too long.'); }
    return compact('name', 'contact', 'shop', 'msg');
}

add_action('template_redirect', static function (): void {
    if (!is_singular(CPT) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['sfm_req'])) { return; }
    $id = (int) get_queried_object_id();
    $back = get_permalink($id);
    try {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_POST['_sfm_req_nonce'] ?? ''))), 'sfm_req_' . $id)) { throw new \DomainException('Please reload the page and try again.'); }
        if (!empty($_POST['website'])) { throw new \DomainException('Request not sent.'); } // bot trap
        $ip = substr(md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt()), 0, 16);
        $k = 'sfm_req_rate_' . $ip;
        if ((int) get_transient($k) >= 5) { throw new \DomainException('Too many requests. Please try again later.'); }
        $d = clean_request(wp_unslash($_POST));
        $rid = wp_insert_post(['post_type' => REQ, 'post_status' => 'private', 'post_title' => sprintf('%s — %s', meta($id, 'code') ?: get_the_title($id), $d['name']),
            'post_content' => sprintf("Module: %s (%s)\nName: %s\nContact: %s\nShop/company: %s\n\n%s", get_the_title($id), meta($id, 'code'), $d['name'], $d['contact'], $d['shop'], $d['msg'])], true);
        if (is_wp_error($rid)) { throw new \DomainException('Could not save the request. Please try again.'); }
        update_post_meta($rid, '_sfm_module_id', $id);
        set_transient($k, (int) get_transient($k) + 1, HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg('sent', '1', $back) . '#request');
    } catch (\DomainException $e) {
        wp_safe_redirect(add_query_arg('err', rawurlencode($e->getMessage()), $back) . '#request');
    }
    exit;
});
