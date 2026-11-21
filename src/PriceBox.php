<?php
namespace SFM4U\Wholesale;

/**
 * Shows an approved wholesale buyer their own price on the product page and in product lists:
 * wholesale price, minimum quantity, "buy more, pay less" steps, the retail price,
 * and how much they make per unit if they resell at retail. A PUBLIC rule (0.9.0) shows everyone,
 * guests too, a two-price table: 1 piece = retail, N or more = wholesale. Variable products use the parent rule.
 * Pages showing this box are never cached (Commerce::preventPrivatePageCache).
 */
final class PriceBox {
    public static function register(): void {
        add_action('woocommerce_single_product_summary', [self::class, 'single'], 11);
        add_action('woocommerce_after_shop_loop_item_title', [self::class, 'loop'], 11);
    }

    private static function rule(\WC_Product $product): ?array {
        if (!$product->is_type('simple') && !$product->is_type('variable')) { return null; }
        try {
            $rule = Commerce::ruleForBuyer($product->get_id(), wp_get_current_user());
        } catch (\DomainException $e) {
            return null;
        }
        // Guests only ever see a PUBLIC table (same for every guest, so cache-safe).
        if ($rule && !is_user_logged_in() && empty($rule['public'])) { return null; }
        return $rule;
    }

    /**
     * Pure: rows of the public two-price table. First row is retail for 1 piece (null price = use
     * the product's own retail price), then "N or more" rows in ascending quantity.
     */
    public static function publicRows(array $rule): array {
        $rows = [['from' => 1, 'minor' => null]];
        $steps = [(int) $rule['min'] => (int) $rule['minor']] + array_map('intval', $rule['tiers']);
        ksort($steps, SORT_NUMERIC);
        foreach ($steps as $from => $minor) {
            if ($from > 1) { $rows[] = ['from' => (int) $from, 'minor' => $minor]; }
        }
        return $rows;
    }

    private static function publicTable(\WC_Product $product, array $rule): void {
        $s = (int) $rule['scale'];
        $retail = $product->is_type('variable') ? null : (int) round((float) wc_get_price_to_display($product) * (10 ** $s));
        echo '<div class="sfmwh-pricebox sfmwh-public" style="border:1px solid rgba(221,193,123,.45);border-radius:14px;padding:12px 16px;margin:10px 0 16px">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__('Retail and wholesale price', 'sfm4u-wholesale') . '</strong></p>';
        echo '<table class="sfmwh-tiers" style="width:100%;margin:0 0 6px"><tbody>';
        foreach (self::publicRows($rule) as $row) {
            if ($row['minor'] === null) {
                $label = esc_html__('1 piece (retail)', 'sfm4u-wholesale');
                $price = $retail !== null ? wp_kses_post(self::money($retail, $s)) : esc_html__('as shown above', 'sfm4u-wholesale');
            } else {
                /* translators: %d: quantity */
                $label = esc_html(sprintf(__('%d pieces or more', 'sfm4u-wholesale'), $row['from']));
                $price = wp_kses_post(self::money($row['minor'], $s)) . ' / ' . esc_html__('piece', 'sfm4u-wholesale');
            }
            echo '<tr><td>' . $label . '</td><td style="text-align:right"><strong>' . $price . '</strong></td></tr>';
        }
        echo '</tbody></table>';
        if ((int) $rule['step'] > 1) {
            echo '<p style="margin:0 0 6px">' . esc_html(sprintf(__('Wholesale in steps of %d', 'sfm4u-wholesale'), (int) $rule['step'])) . '</p>';
        }
        echo '<p style="margin:0;font-size:.9em">' . esc_html__('The wholesale price is applied in the cart automatically when you reach the quantity.', 'sfm4u-wholesale') . '</p></div>';
    }

    private static function money(int $minor, int $scale): string {
        return wc_price($minor / (10 ** $scale));
    }

    /** Pure: per-unit margin if the buyer resells at the retail price. Null when retail is not higher. */
    public static function margin(int $retailMinor, int $wholesaleMinor): ?int {
        return $retailMinor > $wholesaleMinor ? $retailMinor - $wholesaleMinor : null;
    }

    public static function single(): void {
        global $product;
        if (!$product instanceof \WC_Product) { return; }
        $rule = self::rule($product);
        if (!$rule) { return; }
        if (!empty($rule['public']) || $product->is_type('variable')) {
            self::publicTable($product, $rule);
            return;
        }
        $s = (int) $rule['scale'];
        $retail = (int) round((float) wc_get_price_to_display($product) * (10 ** $s));
        echo '<div class="sfmwh-pricebox" style="border:1px solid rgba(221,193,123,.45);border-radius:14px;padding:12px 16px;margin:10px 0 16px">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__('Your wholesale price', 'sfm4u-wholesale') . ':</strong> '
            . wp_kses_post(self::money((int) $rule['minor'], $s)) . ' / ' . esc_html__('unit', 'sfm4u-wholesale') . '</p>';
        /* translators: %d: minimum quantity */
        echo '<p style="margin:0 0 6px">' . esc_html(sprintf(__('Minimum order: %d', 'sfm4u-wholesale'), (int) $rule['min']))
            . ((int) $rule['step'] > 1 ? ' · ' . esc_html(sprintf(__('in steps of %d', 'sfm4u-wholesale'), (int) $rule['step'])) : '') . '</p>';
        if (!empty($rule['tiers'])) {
            echo '<ul style="margin:0 0 6px 1.2em">';
            foreach ($rule['tiers'] as $from => $minor) {
                /* translators: 1: quantity, 2: price */
                echo '<li>' . wp_kses_post(sprintf(__('%1$d or more: %2$s / unit', 'sfm4u-wholesale'), (int) $from, self::money((int) $minor, $s))) . '</li>';
            }
            echo '</ul>';
        }
        $m = self::margin($retail, (int) $rule['minor']);
        echo '<p style="margin:0">' . esc_html__('Retail price', 'sfm4u-wholesale') . ': ' . wp_kses_post(self::money($retail, $s));
        if ($m !== null) {
            /* translators: %s: profit per unit */
            echo ' · <strong>' . wp_kses_post(sprintf(__('You make %s per unit at retail', 'sfm4u-wholesale'), self::money($m, $s))) . '</strong>';
        }
        echo '</p></div>';
    }

    public static function loop(): void {
        global $product;
        if (!$product instanceof \WC_Product) { return; }
        $rule = self::rule($product);
        if (!$rule) { return; }
        if (!empty($rule['public'])) {
            /* translators: 1: quantity, 2: price */
            echo '<div class="sfmwh-loop-price" style="font-size:.9em">' . wp_kses_post(sprintf(__('%1$d+ pcs: %2$s each', 'sfm4u-wholesale'),
                (int) $rule['min'], self::money((int) $rule['minor'], (int) $rule['scale']))) . '</div>';
            return;
        }
        /* translators: 1: price, 2: minimum quantity */
        echo '<div class="sfmwh-loop-price" style="font-size:.9em">' . wp_kses_post(sprintf(__('Wholesale %1$s (min %2$d)', 'sfm4u-wholesale'),
            self::money((int) $rule['minor'], (int) $rule['scale']), (int) $rule['min'])) . '</div>';
    }
}
