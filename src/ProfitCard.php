<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

use DomainException;

final class ProfitCard {
    private const NONCE = 'sfmwh_profit_card';
    private const MAX_OVERRIDES = 200;

    public static function register(): void {
        add_action('woocommerce_after_cart', [self::class, 'renderCart']);
        add_filter('render_block_woocommerce/cart', static function (string $html): string {
            ob_start();
            self::renderCart();
            return $html . ob_get_clean();
        });
        add_action('template_redirect', [self::class, 'handleCart']);
        add_action('wp_enqueue_scripts', static function (): void {
            if (is_cart() && self::buyer()) {
                wp_enqueue_script('sfmwh-profit-card', plugins_url('assets/profit-card.js', dirname(__DIR__) . '/sfm4u-wholesale.php'),
                    ['wp-data'], '0.1.5-dev', true);
            }
        });
    }

    public static function supportedCurrency(): bool {
        return get_woocommerce_currency() === 'BDT' && wc_get_price_decimals() === 2;
    }

    private static function buyer(): bool {
        return self::supportedCurrency() && get_current_user_id() > 0
            && get_user_meta(get_current_user_id(), '_sfmwh_buyer_status', true) === 'approved';
    }

    public static function minor(string $price): int {
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $price, $matches)) {
            throw new DomainException('Invalid retail estimate.');
        }
        return Rule::number((int) ($matches[1] . str_pad($matches[2] ?? '', 2, '0')), 0, 1000000000);
    }

    public static function override(int $product): ?int {
        $value = get_user_meta(get_current_user_id(), '_sfmwh_retail_' . $product, true);
        return is_numeric($value) ? (int) $value : null;
    }

    /** Callers verify the actor, nonce and product scope before saving. */
    public static function saveOverride(int $product, string $price): void {
        $key = '_sfmwh_retail_' . $product;
        if ($price === '') {
            delete_user_meta(get_current_user_id(), $key);
            return;
        }
        update_user_meta(get_current_user_id(), $key, self::minor($price));
    }

    public static function line(\WC_Product $product, int $quantity, ?int $cost, int $suggestion, int $default, int $discount): array {
        $override = self::override($product->get_id());
        $used = ($override ?? $default) * $quantity;
        return ['product' => $product->get_id(), 'name' => $product->get_name(), 'quantity' => $quantity,
            'cost' => $cost, 'suggestion' => $suggestion * $quantity, 'used' => $used, 'discount' => $discount,
            'profit' => $cost === null ? null : $used - $cost, 'override' => $override];
    }

    public static function totals(array $rows): array {
        $totals = array_fill_keys(['cost', 'suggestion', 'used', 'discount', 'profit'], 0);
        foreach ($rows as $row) {
            foreach ($totals as $field => $total) {
                $totals[$field] = $total === null || $row[$field] === null ? null : $total + $row[$field];
            }
        }
        return $totals;
    }

    public static function cartRows(): array {
        if (!self::buyer() || !WC()->cart) {
            return [];
        }
        $rows = [];
        foreach (WC()->cart->get_cart() as $item) {
            try {
                $quote = Commerce::itemQuote($item);
                if (!$quote) {
                    continue;
                }
                $product = $item['data'];
                $quantity = $quote['stock_quantity'];
                $regular = $product->get_regular_price();
                if ($regular === '') {
                    continue;
                }
                $retail = self::minor(wc_format_decimal(wc_get_price_excluding_tax($product, ['price' => $regular]), 2));
                $cost = $quote['line_minor'];
                $rows[] = self::line($product, $quantity, $cost, $retail, $retail, max(0, $retail * $quantity - $cost));
            } catch (DomainException) {
                // Invalid wholesale lines are handled by checkout validation.
            }
        }
        return $rows;
    }

    public static function handleCart(): void {
        if (!is_cart()) {
            return;
        }
        if (!self::buyer()) {
            if (isset($_POST[self::NONCE]) || isset($_GET['sfmwh_profit_refresh'])) {
                wp_die(esc_html__('Access denied.', 'sfm4u-wholesale'), '', ['response' => 403]);
            }
            return;
        }
        nocache_headers();
        header('Cache-Control: private, no-store', true);
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (isset($_GET['sfmwh_profit_refresh'])) {
            $nonce = is_string($_GET['sfmwh_profit_refresh']) ? wp_unslash($_GET['sfmwh_profit_refresh']) : '';
            if (!wp_verify_nonce($nonce, self::NONCE)) {
                wp_send_json_error(null, 403);
            }
            ob_start();
            self::renderCart();
            wp_send_json_success(ob_get_clean());
        }
        if (!isset($_POST[self::NONCE])) {
            return;
        }
        $nonce = is_string($_POST[self::NONCE]) ? wp_unslash($_POST[self::NONCE]) : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            wp_die(esc_html__('Refresh the page before submitting.', 'sfm4u-wholesale'), '', ['response' => 403]);
        }
        $input = $_POST['sfmwh_retail'] ?? [];
        if (!is_array($input) || count($input) > self::MAX_OVERRIDES) {
            wp_die(esc_html__('Enter a valid retail price in Taka.', 'sfm4u-wholesale'), '', ['response' => 422]);
        }
        $input = wp_unslash($input);
        $allowed = array_column(self::cartRows(), 'product');
        try {
            foreach ($input as $id => $price) {
                if (!in_array($id, $allowed, true) || !is_string($price)) {
                    throw new DomainException('Invalid retail estimate.');
                }
                if ($price !== '') {
                    self::minor($price);
                }
            }
            foreach ($input as $id => $price) {
                self::saveOverride($id, $price);
            }
        } catch (DomainException) {
            wc_add_notice(__('Enter a valid retail price in Taka.', 'sfm4u-wholesale'), 'error');
        }
        wp_safe_redirect(wc_get_cart_url(), 303);
        exit;
    }

    public static function renderCart(): void {
        if (!self::buyer()) {
            return;
        }
        $url = add_query_arg('sfmwh_profit_refresh', wp_create_nonce(self::NONCE), wc_get_cart_url());
        echo '<div data-sfmwh-profit="' . esc_url($url) . '">';
        $rows = self::cartRows();
        if ($rows) {
            self::render($rows, true);
            echo '<form method="post" action="' . esc_url(wc_get_cart_url()) . '">';
            wp_nonce_field(self::NONCE, self::NONCE);
            $products = [];
            foreach ($rows as $row) {
                if (isset($products[$row['product']])) {
                    continue;
                }
                $products[$row['product']] = true;
                echo '<p><label>' . esc_html($row['name'] . ' · ' . __('Your retail price per unit (BDT)', 'sfm4u-wholesale'))
                    . ' <input type="number" min="0" max="10000000" step="0.01" name="sfmwh_retail[' . esc_attr((string) $row['product'])
                    . ']" value="' . esc_attr($row['override'] === null ? '' : Rule::decimal($row['override'], 2)) . '"></label></p>';
            }
            echo '<button type="submit">' . esc_html__('Save retail estimate', 'sfm4u-wholesale') . '</button></form>';
        }
        echo '</div>';
    }

    public static function render(array $rows, bool $wholesale): void {
        $labels = [
            'cost' => __('Buy cost', 'sfm4u-wholesale'), 'suggestion' => __('Suggested customer price', 'sfm4u-wholesale'),
            'used' => __('Customer price used', 'sfm4u-wholesale'),
            'discount' => $wholesale ? __('Wholesale saving', 'sfm4u-wholesale') : __('Catalogue discount', 'sfm4u-wholesale'),
            'profit' => __('Estimated profit', 'sfm4u-wholesale'),
        ];
        $money = static fn(?int $value): string => $value === null ? __('Unavailable', 'sfm4u-wholesale')
            : '৳' . ($value < 0 ? '-' : '') . Rule::decimal(abs($value), 2);
        echo '<section><h2>' . esc_html__('Shopkeeper profit estimate', 'sfm4u-wholesale') . '</h2><p>'
            . esc_html__('Before tax, shipping and fees. Retail estimates do not change checkout or POS charges.', 'sfm4u-wholesale') . '</p>';
        foreach ([...$rows, self::totals($rows) + ['name' => __('Total', 'sfm4u-wholesale')]] as $row) {
            echo '<h3>' . esc_html($row['name'] . (isset($row['quantity']) ? ' × ' . $row['quantity'] : '')) . '</h3><dl>';
            foreach ($labels as $key => $label) {
                echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($money($row[$key])) . '</dd>';
            }
            echo '</dl>';
        }
        echo '</section>';
    }
}
