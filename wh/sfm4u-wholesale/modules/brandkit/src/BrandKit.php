<?php
declare(strict_types=1);

namespace SFM4U\Modules\BrandKit;

use SFM4U\Wholesale\Modules;

defined('ABSPATH') || exit;

final class BrandKit
{
    public const PAGE = 'sfm-bos-about';
    private const STYLE = 'sfm4u-ui';
    private const VERSION = '1.0.0';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function menu(): void
    {
        add_submenu_page(Modules::SLUG, self::label('About SFM4U', 'SFM4U সম্পর্কে'),
            self::label('About SFM4U', 'SFM4U সম্পর্কে'), 'manage_options', self::PAGE, [self::class, 'page']);
    }

    public static function enqueue(string $hook): void
    {
        $page = $_GET['page'] ?? '';
        if (!is_string($page) || !self::usesStyle($page)) {
            return;
        }
        wp_enqueue_style(self::STYLE, self::asset('sfm4u-ui.css'), [], self::VERSION);
    }

    public static function usesStyle(string $page): bool
    {
        return $page === self::PAGE || $page === Modules::SLUG || $page === Modules::SYSTEMS
            || $page === 'sfm-bos-module' || in_array($page, array_column(Modules::all(), 3), true) && $page !== '';
    }

    private static function asset(string $name): string
    {
        return plugins_url('assets/' . $name, dirname(__DIR__, 3) . '/sfm4u-wholesale.php');
    }

    private static function label(string $english, string $bangla): string
    {
        return str_starts_with(determine_locale(), 'bn') ? $bangla : $english;
    }

    public static function header(string $title, string $description): string
    {
        return '<header class="sfm-ui-header"><img width="64" height="64" src="'
            . esc_url(self::asset('brand/sfm4u-logo-128.png'))
            . '" alt="SFM4U"><div><h1>' . esc_html($title) . '</h1><p>'
            . esc_html($description) . '</p></div></header>';
    }

    public static function card(string $title, string $body): string
    {
        return '<section class="sfm-ui-card"><h2>' . esc_html($title) . '</h2><p>' . esc_html($body) . '</p></section>';
    }

    public static function tile(string $label, string $value): string
    {
        return '<dl class="sfm-ui-tile"><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></dl>';
    }

    public static function button(string $label, string $url): string
    {
        return '<a class="sfm-ui-button" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    public static function table(array $headers, array $rows): string
    {
        $html = '<div class="sfm-ui-table-wrap"><table class="sfm-ui-table"><thead><tr>';
        foreach ($headers as $label) {
            $html .= '<th scope="col">' . esc_html((string) $label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . esc_html((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(self::label('Access denied.', 'অনুমতি নেই।')), '', ['response' => 403]);
        }
        echo '<div class="wrap sfm-ui">';
        echo self::header(self::label('About SFM4U', 'SFM4U সম্পর্কে'),
            self::label('SFM4U Business Software', 'SFM4U Business Software'));
        echo self::card(self::label('Software', 'সফটওয়্যার'),
            self::label('Tools for stores, wholesale, membership, POS, and marketplace operations.',
                'দোকান, পাইকারি ব্যবসা, মেম্বারশিপ, POS ও মার্কেটপ্লেস পরিচালনার সফটওয়্যার।'));
        echo self::card(self::label('5% policy', '৫% নীতি'),
            self::label('Policy text pending Owner confirmation.', 'নীতির লেখা মালিকের নিশ্চিতকরণের অপেক্ষায়।'));
        echo self::card(self::label('Support', 'সহায়তা'),
            self::label('Visit the SFM4U company website for contact information.', 'যোগাযোগের জন্য SFM4U-এর ওয়েবসাইট দেখুন।'));
        echo self::button(self::label('SFM4U website', 'SFM4U ওয়েবসাইট'), 'https://sfm4u.com/');
        echo '</div>';
    }
}
