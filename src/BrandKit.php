<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

defined('ABSPATH') || exit;

/**
 * SFM4U design kit (A01): logo, colours and components on every SFM4U Business Software admin page — and only there,
 * so WordPress and WooCommerce screens stay as they are.
 */
final class BrandKit {
    public static function register(): void {
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('in_admin_header', [self::class, 'bar']);
    }

    /** Pure: is this admin page one of ours? */
    public static function isOurs(string $page): bool {
        return $page !== '' && preg_match('/^(sfm-|sfmwh-|sfm4u-|sfm_)/', $page) === 1;
    }

    private static function page(): string {
        return isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    }

    public static function bodyClass(string $c): string {
        return self::isOurs(self::page()) ? $c . ' sfm4u-ui' : $c;
    }

    public static function url(string $file): string {
        return plugins_url('assets/' . $file, dirname(__DIR__) . '/sfm4u-wholesale.php');
    }

    public static function enqueue(): void {
        if (!self::isOurs(self::page())) { return; }
        wp_enqueue_style('sfm4u-ui', self::url('sfm4u-ui.css'), [], defined('SFM4U_WH_VERSION') ? (string) SFM4U_WH_VERSION : '1');
    }

    public static function bar(): void {
        if (!self::isOurs(self::page())) { return; }
        // Owner 2026-11-22: the wordmark cut from the real logo (brackets at the logo's own size); no separate round logo.
        echo '<div class="sfm4u-brandbar"><img class="wm" src="' . esc_url(self::url('brand/sfm4u-wordmark.png')) . '" alt="SFM4U" height="44">'
            . '<div><div class="w">Business Software</div><div class="t">One software · separate modules · বাংলা / English</div></div>'
            . '<div class="r"><a href="' . esc_url(admin_url('admin.php?page=' . Modules::SLUG)) . '">Dashboard</a>'
            . '<a href="' . esc_url(admin_url('admin.php?page=' . Modules::SYSTEMS)) . '">Systems</a>'
            . '<a href="' . esc_url(admin_url('admin.php?page=' . ModuleManager::SLUG)) . '">Module Manager</a></div></div>';
    }
}
