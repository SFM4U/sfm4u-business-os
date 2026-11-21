<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

final class LowStock {
    private const PAGE = 'sfmwh-low-stock';
    private const EXPORT = 'sfmwh_low_stock_csv';
    private const PAGE_SIZE = 50;

    public static function register(): void {
        add_action('admin_post_' . self::EXPORT, static function (): void {
            ReportCsv::download(self::EXPORT, 'wholesale-low-stock.csv',
                ['product_id', 'product', 'sku', 'on_hand', 'threshold'], [self::class, 'csvRows']);
        });
        add_action('admin_menu', static function (): void {
            add_submenu_page('woocommerce', __('Wholesale low stock', 'sfm4u-wholesale'),
                __('Wholesale low stock', 'sfm4u-wholesale'), 'manage_woocommerce', self::PAGE, [self::class, 'render']);
        });
    }

    public static function threshold(\WC_Product $owner): ?int {
        if ($owner->get_status() !== 'publish' || !$owner->managing_stock()
            || $owner->get_stock_managed_by_id() !== $owner->get_id()) {
            return null;
        }
        // The edit value excludes the store-wide default and any inherited amount.
        $threshold = $owner->get_low_stock_amount('edit');
        return is_numeric($threshold) && $threshold > 0 && (float) $threshold === (float) (int) $threshold
            ? (int) $threshold : null;
    }

    public static function below(\WC_Product $owner): bool {
        $threshold = self::threshold($owner);
        $quantity = $owner->get_stock_quantity();
        return $threshold !== null && $quantity !== null && $quantity < $threshold;
    }

    public static function report(int $page = 1): array {
        if (!current_user_can('manage_woocommerce')) {
            throw new \DomainException('Inventory manager permission required.', 403);
        }
        $page = max(1, min(1000000, $page));
        $ids = get_posts([
            'post_type' => ['product', 'product_variation'], 'post_status' => 'publish',
            'fields' => 'ids', 'numberposts' => self::PAGE_SIZE + 1,
            'offset' => ($page - 1) * self::PAGE_SIZE, 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => [['key' => '_low_stock_amount', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC']],
        ]);
        $items = [];
        foreach (array_slice($ids, 0, self::PAGE_SIZE) as $id) {
            $product = wc_get_product($id);
            if ($product && self::below($product)) {
                $items[] = $product;
            }
        }
        return ['items' => $items, 'next' => count($ids) > self::PAGE_SIZE];
    }

    public static function csvRows(): iterable {
        $page = 1;
        do {
            $report = self::report($page);
            foreach ($report['items'] as $product) {
                yield [$product->get_id(), ReportCsv::text($product->get_name()), ReportCsv::text($product->get_sku()),
                    $product->get_stock_quantity(), self::threshold($product)];
            }
        } while ($report['next'] && ++$page <= 1000000);
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $page = is_string($_GET['stock_page'] ?? null) ? max(1, min(1000000, absint($_GET['stock_page']))) : 1;
        $report = self::report($page);
        echo '<div class="wrap"><h1>' . esc_html__('Wholesale low stock', 'sfm4u-wholesale') . '</h1><p>'
            . esc_html__('Set a positive Low stock threshold in the WooCommerce product or variation inventory settings. Shared variations use the stock-owning product. This list compares on-hand stock with that threshold.', 'sfm4u-wholesale')
            . '</p>';
        $export = wp_nonce_url(add_query_arg('action', self::EXPORT, admin_url('admin-post.php')), self::EXPORT);
        echo '<p><a class="button" href="' . esc_url($export) . '">' . esc_html__('Export CSV', 'sfm4u-wholesale') . '</a></p>';
        if ($report['items']) {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Product', 'sfm4u-wholesale'), __('SKU', 'sfm4u-wholesale'),
                __('On-hand stock', 'sfm4u-wholesale'), __('Low stock threshold', 'sfm4u-wholesale')] as $label) {
                echo '<th scope="col">' . esc_html($label) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($report['items'] as $product) {
                echo '<tr><td>' . esc_html($product->get_name()) . '</td><td>' . esc_html($product->get_sku())
                    . '</td><td>' . esc_html((string) $product->get_stock_quantity()) . '</td><td>'
                    . esc_html((string) self::threshold($product)) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No products below their configured threshold on this page.', 'sfm4u-wholesale') . '</p>';
        }
        $url = add_query_arg('page', self::PAGE, admin_url('admin.php'));
        echo '<p>';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg('stock_page', $page - 1, $url)) . '">'
                . esc_html__('Previous products', 'sfm4u-wholesale') . '</a> ';
        }
        if ($report['next']) {
            echo '<a class="button" href="' . esc_url(add_query_arg('stock_page', $page + 1, $url)) . '">'
                . esc_html__('More products', 'sfm4u-wholesale') . '</a>';
        }
        echo '</p></div>';
    }
}
