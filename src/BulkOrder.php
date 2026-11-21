<?php
namespace SFM4U\Wholesale;

use DomainException;

final class BulkOrder {
    private const NONCE = 'sfmwh_bulk_order';
    private const LIMIT = 20;

    public static function register(): void {
        add_action('woocommerce_account_dashboard', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'handle']);
    }

    private static function approved(): bool {
        return get_option('sfmwh_enabled', 'no') === 'yes' && get_current_user_id()
            && get_user_meta(get_current_user_id(), '_sfmwh_buyer_status', true) === 'approved';
    }

    private static function product(int $id): ?\WC_Product {
        $product = wc_get_product($id);
        if (!$product || !$product->is_type(['simple', 'variation']) || !$product->is_purchasable()
            || $product->get_status() !== 'publish') {
            return null;
        }
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || $parent->get_status() !== 'publish' || $parent->get_catalog_visibility() === 'hidden') {
            return null;
        }
        return Commerce::ruleForBuyer($id, wp_get_current_user()) ? $product : null;
    }

    public static function search(string $term): array {
        if (!self::approved() || strlen($term) > 100) {
            return [];
        }
        $args = ['post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => self::LIMIT * 3,
            'meta_query' => [['key' => Commerce::META, 'compare' => 'EXISTS']]];
        if ($term !== '') {
            $args['s'] = $term;
        }
        $ids = array_unique(array_filter([(int) ($term === '' ? 0 : wc_get_product_id_by_sku($term)), ...get_posts($args)]));
        $products = [];
        foreach ($ids as $id) {
            if ($product = self::product((int) $id)) {
                $products[] = $product;
            }
            if (count($products) === self::LIMIT) {
                break;
            }
        }
        return $products;
    }

    public static function add(array $quantities, string $nonce): void {
        if (!self::approved() || !wp_verify_nonce($nonce, self::NONCE) || !WC()->cart || count($quantities) > self::LIMIT) {
            throw new DomainException('Bulk order rejected.');
        }
        $cart = WC()->cart;
        $original = $cart->get_cart();
        $added = false;
        try {
            foreach ($quantities as $id => $quantity) {
                if ($quantity === '' || $quantity === '0') {
                    continue;
                }
                $quantity = Rule::number($quantity, 1, 1000000);
                $product = self::product(Rule::number($id, 1, PHP_INT_MAX));
                if (!$product) {
                    throw new DomainException('Bulk order rejected.');
                }
                $variant = $product->is_type('variation') ? $product->get_id() : 0;
                $parent = $variant ? $product->get_parent_id() : $product->get_id();
                $attributes = $variant ? $product->get_variation_attributes() : [];
                $data = [Commerce::PACK => 'piece'];
                if (!apply_filters('woocommerce_add_to_cart_validation', true, $parent, $quantity, $variant, $attributes, $data)
                    || !$cart->add_to_cart($parent, $quantity, $variant, $attributes, $data)) {
                    throw new DomainException('Bulk order rejected.');
                }
                $added = true;
            }
            if (!$added) {
                throw new DomainException('Bulk order rejected.');
            }
        } catch (\Throwable $error) {
            $cart->set_cart_contents($original);
            $cart->calculate_totals();
            throw $error;
        }
    }

    public static function handle(): void {
        if (!is_account_page() || !isset($_POST[self::NONCE])) {
            return;
        }
        try {
            $nonce = is_string($_POST[self::NONCE]) ? wp_unslash($_POST[self::NONCE]) : '';
            $quantities = is_array($_POST['sfmwh_bulk_qty'] ?? null) ? wp_unslash($_POST['sfmwh_bulk_qty']) : [];
            self::add($quantities, $nonce);
        } catch (DomainException) {
            wc_add_notice(__('Check the selected products and quantities. The bulk order was not added.', 'sfm4u-wholesale'), 'error');
            return;
        }
        wp_safe_redirect(wc_get_cart_url(), 303);
        exit;
    }

    public static function render(): void {
        if (!self::approved()) {
            return;
        }
        $term = is_string($_GET['sfmwh_find'] ?? null) ? sanitize_text_field(wp_unslash($_GET['sfmwh_find'])) : '';
        echo '<section><h2>' . esc_html__('Bulk wholesale order', 'sfm4u-wholesale') . '</h2><form method="get"><label>'
            . esc_html__('Search by product name or exact SKU', 'sfm4u-wholesale')
            . ' <input name="sfmwh_find" maxlength="100" value="' . esc_attr($term) . '"></label> <button>'
            . esc_html__('Search', 'sfm4u-wholesale') . '</button></form>';
        try {
            $products = self::search($term);
        } catch (DomainException) {
            $products = [];
        }
        echo '<form method="post">';
        wp_nonce_field(self::NONCE, self::NONCE);
        foreach ($products as $product) {
            echo '<p><label>' . esc_html($product->get_name() . ' · ' . $product->get_sku()) . ' '
                . esc_html__('Quantity in stock units', 'sfm4u-wholesale') . ' <input type="number" min="0" max="1000000" name="sfmwh_bulk_qty['
                . esc_attr((string) $product->get_id()) . ']"></label></p>';
        }
        if ($products) {
            echo '<button>' . esc_html__('Add selected items to cart', 'sfm4u-wholesale') . '</button>';
        } else {
            echo '<p>' . esc_html__('No eligible wholesale products found.', 'sfm4u-wholesale') . '</p>';
        }
        echo '</form></section>';
    }
}
