<?php
declare(strict_types=1);

namespace SFM4U\Modules\VendorPanel;

use SFM4U\Modules\Stores\Stores;
use SFM4U\Wholesale\{ModuleContract, Modules};

final class VendorPanel implements ModuleContract
{
    public const PAGE = 'sfm-vendor-panel';

    public static function register(): void
    {
        add_filter('sfm4u_bos_modules', static fn(array $modules): array => $modules + [
            'vendorpanel' => ['Vendor Panel', 'বিক্রেতার প্যানেল', 'Store workspace and shop management.', self::PAGE, self::class],
        ]);
        if (Modules::on('vendorpanel')) {
            add_action('admin_menu', [self::class, 'menu']);
            add_action('rest_api_init', [self::class, 'routes']);
        }
    }

    public static function dashboard(): array
    {
        return ['numbers' => [], 'pages' => [[self::label('Open vendor panel', 'বিক্রেতার প্যানেল খুলুন'), self::PAGE]],
            'settings' => [], 'help' => [
                'Owners work in their assigned SFM4U stores. A grant to Vendor Panel and Stores is needed for seller accounts.',
                'মালিক নিজের নির্ধারিত দোকানে কাজ করবেন। বিক্রেতার অ্যাকাউন্টে Vendor Panel ও Stores-এর অনুমতি দরকার।',
            ]];
    }

    private static function label(string $en, string $bn): string
    {
        return str_starts_with(determine_locale(), 'bn') ? $bn : $en;
    }

    public static function allowed(): bool
    {
        return Modules::on('stores') && Modules::on('vendorpanel') && get_current_user_id() > 0
            && (self::manager() || (current_user_can('sfm_bos_use_vendorpanel') && current_user_can('sfm_bos_use_stores')));
    }

    private static function manager(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public static function menu(): void
    {
        if (self::allowed()) {
            $title = self::label('Vendor Panel', 'বিক্রেতার প্যানেল');
            add_menu_page($title, $title, 'read', self::PAGE, [self::class, 'page'], 'dashicons-store');
        }
    }

    public static function routes(): void
    {
        register_rest_route('sfm4u/v1', '/vendorpanel', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'allowed'],
            'callback' => static function (\WP_REST_Request $request): \WP_REST_Response {
                return new \WP_REST_Response(self::stores(max(1, (int) $request->get_param('page'))), 200,
                    ['Cache-Control' => 'private, no-store']);
            },
        ]);
    }

    public static function stores(int $page): array
    {
        if (!self::allowed()) {
            return ['stores' => [], 'pages' => 0];
        }
        $args = ['post_type' => Stores::TYPE, 'post_status' => ['pending', 'publish'],
            'posts_per_page' => 25, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC'];
        if (!self::manager()) {
            $args['author'] = get_current_user_id();
        }
        $query = new \WP_Query($args);
        return [
            'stores' => array_map(static fn(\WP_Post $post): array => [
                'id' => $post->ID,
                'name' => $post->post_title,
                'status' => $post->post_status,
            ], $query->posts),
            'pages' => (int) $query->max_num_pages,
        ];
    }

    public static function page(): void
    {
        if (!self::allowed()) {
            wp_die(esc_html(self::label('Access denied.', 'অনুমতি নেই।')), '', ['response' => 403]);
        }
        $page = max(1, absint($_GET['store_page'] ?? 1));
        $result = self::stores($page);
        echo '<div class="wrap"><h1>' . esc_html(self::label('Vendor Panel', 'বিক্রেতার প্যানেল')) . '</h1><ul>';
        foreach ($result['stores'] as $store) {
            $url = add_query_arg(['page' => Stores::PAGE, 'store' => $store['id']], admin_url('admin.php'));
            echo '<li>' . esc_html($store['name']) . ' · ' . esc_html($store['status'] === 'publish'
                ? self::label('Approved', 'অনুমোদিত') : self::label('Pending', 'অপেক্ষায়'))
                . ' <a href="' . esc_url($url) . '">' . esc_html(self::label('Store settings', 'দোকানের সেটিংস')) . '</a></li>';
        }
        echo '</ul>';
        foreach ([$page - 1 => self::label('Previous', 'আগের'), $page + 1 => self::label('Next', 'পরের')] as $number => $label) {
            if ($number >= 1 && $number <= $result['pages']) {
                echo '<a href="' . esc_url(add_query_arg(['page' => self::PAGE, 'store_page' => $number], admin_url('admin.php')))
                    . '">' . esc_html($label) . '</a> ';
            }
        }
        if (current_user_can('edit_products') && Modules::on('retail')) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sfm-product-sheet')) . '">'
                . esc_html(self::label('SFM4U Product Sheet', 'SFM4U পণ্যের ফর্ম')) . '</a></p>';
        }
        echo '</div>';
    }
}
