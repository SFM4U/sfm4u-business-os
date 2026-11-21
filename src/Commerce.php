<?php
namespace SFM4U\Wholesale;

use DomainException;

final class Commerce {
    public const META = '_sfmwh_rules_v1';
    public const PACK = 'sfmwh_pack';

    public static function register(): void {
        add_filter('woocommerce_rest_pre_insert_product_object', [self::class, 'protectOperationsMeta'], 99, 2);
        add_filter('woocommerce_rest_pre_insert_product_variation_object', [self::class, 'protectOperationsMeta'], 99, 2);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'cartData'], 10, 4);
        add_filter('woocommerce_store_api_add_to_cart_data', [self::class, 'storeData'], 10, 2);
        add_filter('woocommerce_available_variation', [self::class, 'variationPacks'], 10, 3);
        add_action('template_redirect', [self::class, 'preventPrivatePageCache']);
        add_action('woocommerce_before_add_to_cart_button', [self::class, 'packField']);
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validateAdd'], 20, 6);
        add_filter('woocommerce_update_cart_validation', [self::class, 'validateUpdate'], 20, 4);
        add_filter('woocommerce_store_api_cart_item_quantity_validation', [self::class, 'storeQuantity'], 20, 4);
        add_action('woocommerce_before_calculate_totals', [self::class, 'priceCart'], 100);
        add_action('woocommerce_check_cart_items', [self::class, 'checkCart']);
        add_action('woocommerce_store_api_cart_errors', [self::class, 'storeErrors'], 10, 2);
        add_filter('woocommerce_get_item_data', [self::class, 'displayPack'], 10, 2);
        add_filter('woocommerce_coupon_is_valid', [self::class, 'coupon'], 20);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'snapshot'], 20, 4);
    }

    /** Woo and Dokan share these filters, including product batch/variation writes. */
    public static function protectOperationsMeta($product, \WP_REST_Request $request) {
        if (is_wp_error($product) || current_user_can('manage_woocommerce')) return $product;
        $entries=$request->get_param('meta_data');
        if (!is_array($entries)) return $product;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $key=is_string($entry['key']??null)?$entry['key']:'';
            $oldKey='';$id=$entry['id']??0;
            if ((is_int($id) || is_string($id)) && ctype_digit((string)$id) && (int)$id>0) {
                // Resolve the persisted key: an attacker can rename it in the in-memory object.
                $stored=get_metadata_by_mid('post',(int)$id);
                $oldKey=$stored?(string)$stored->meta_key:'';
            }
            if (str_starts_with(strtolower($key),'_sfm4u_') || str_starts_with(strtolower($oldKey),'_sfm4u_')) {
                return new \WP_Error('sfmwh_protected_operations_meta',
                    __('Only an inventory manager may change operations metadata.','sfm4u-wholesale'),['status'=>403]);
            }
        }
        return $product;
    }

    public static function saveRule(\WC_Product $product, array $data): array {
        $prior = $product->get_meta(self::META, true, 'edit');
        $data['revision'] = is_array($prior) ? $prior['revision'] + 1 : 1;
        $data['currency'] = get_woocommerce_currency();
        $data['scale'] = wc_get_price_decimals();
        $rule = Rule::validate($data);
        $product->update_meta_data(self::META, $rule);
        $product->save_meta_data();
        return $rule;
    }

    private static function eligibleRule(int $id): ?array {
        return self::ruleForBuyer($id, wp_get_current_user());
    }

    /**
     * The saved rule for a product. A variation without its own rule uses its parent's rule,
     * so one table covers all sizes/colours (plan G3).
     */
    public static function storedRule(int $id): ?array {
        $product = wc_get_product($id);
        if (!$product) {
            return null;
        }
        $data = $product->get_meta(self::META, true, 'edit');
        if (!is_array($data) && $product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            $data = $parent ? $parent->get_meta(self::META, true, 'edit') : null;
        }
        return is_array($data) ? $data : null;
    }

    public static function ruleForBuyer(int $id, \WP_User $user): ?array {
        if (get_option('sfmwh_enabled', 'no') !== 'yes') {
            return null;
        }
        $data = self::storedRule($id);
        if (!is_array($data)) {
            return null;
        }
        $rule = Rule::validate($data);
        $buyer = $rule['buyer'];
        $status = $user->exists() ? get_user_meta($user->ID, '_sfmwh_buyer_status', true) : '';
        $assigned = $user->exists() ? get_user_meta($user->ID, '_sfmwh_price_group', true) : '';
        $tier = in_array($assigned, Rule::GROUPS, true) ? $assigned
            : ($user->exists() ? get_user_meta($user->ID, '_sfmwh_spend_tier', true) : '');
        if ($status === 'suspended') {
            return null;
        }
        if ($buyer !== 'everyone') {
            if (!$user->exists()) {
                return null;
            }
            if ($buyer === 'approved' && $status !== 'approved') {
                return null;
            }
            if (!in_array($buyer, ['logged_in', 'approved'], true)) {
                [$policy, $group] = explode(':', $buyer, 2);
                $eligible = match ($policy) {
                    'tier' => $status === 'approved' && ($tier ?: 'bronze') === $group,
                    'approved' => $status === 'approved' && in_array($group, $user->roles, true),
                    'role' => in_array($group, $user->roles, true),
                };
                if (!$eligible) {
                    return null;
                }
            }
        }
        if ($rule['currency'] === get_woocommerce_currency()) {
            $rule = Rule::rescale($rule, wc_get_price_decimals());
        }
        if ($rule['currency'] !== get_woocommerce_currency() || $rule['scale'] !== wc_get_price_decimals()) {
            throw new DomainException(__('The wholesale rule uses a different store currency or precision.', 'sfm4u-wholesale'));
        }
        $groupPrice = $status === 'approved' ? ($rule['group_prices'][$tier ?: 'bronze'] ?? null) : null;
        if ($groupPrice !== null) {
            $rule['minor'] = $groupPrice;
            $rule['tiers'] = array_map(static fn(int $minor): int => min($minor, $groupPrice), $rule['tiers']);
        }
        // Buyer-facing consumers need only the resolved rule.
        unset($rule['group_prices']);
        return $rule;
    }

    private static function pack($value): string {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $value)) {
            throw new DomainException(__('Invalid wholesale pack.', 'sfm4u-wholesale'));
        }
        return $value;
    }

    private static function requestPack(): string {
        return self::pack(isset($_POST[self::PACK]) ? wp_unslash($_POST[self::PACK]) : 'piece');
    }

    public static function preventPrivatePageCache(): void {
        if (is_product() && is_user_logged_in() && get_option('sfmwh_enabled', 'no') === 'yes') {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
        }
    }

    private static function packOptions(int $id): array {
        try {
            $rule = self::eligibleRule($id);
        } catch (DomainException $error) {
            return [];
        }
        if (!$rule) {
            return [];
        }
        $options = [];
        foreach (['piece' => 1] + $rule['packs'] as $name => $factor) {
            $minor = $rule['pack_minor'][$name] ?? $rule['minor'] * $factor;
            $price = $rule['currency'] . ' ' . Rule::decimal($minor, $rule['scale']);
            $label = sprintf(__('%1$s × %2$d · %3$s base price', 'sfm4u-wholesale'), $name, $factor, $price);
            $options[] = ['id' => $name, 'label' => $label];
        }
        return $options;
    }

    public static function variationPacks(array $data, \WC_Product $product, \WC_Product_Variation $variation): array {
        $data['sfmwh_pack_options'] = self::packOptions($variation->get_id());
        if ($data['sfmwh_pack_options'] && !headers_sent()) {
            nocache_headers();
        }
        return $data;
    }

    public static function packField(): void {
        global $product;
        if (!$product instanceof \WC_Product) {
            return;
        }
        $label = esc_html__('Pack (enter quantity in stock units)', 'sfm4u-wholesale');
        if ($product->is_type('variable') && get_option('sfmwh_enabled', 'no') === 'yes') {
            $entry = dirname(__DIR__) . '/sfm4u-wholesale.php';
            wp_enqueue_script('sfmwh-variation-packs', plugins_url('assets/variation-packs.js', $entry),
                ['jquery', 'wc-add-to-cart-variation'], '0.1.0-dev', true);
            echo '<label data-sfmwh-variation-packs hidden>' . $label . ' <select name="sfmwh_pack" disabled></select></label>';
            return;
        }
        if (!$product->is_type('simple')) {
            return;
        }
        $options = self::packOptions($product->get_id());
        if (!$options) {
            return;
        }
        echo '<label>' . $label . ' <select name="sfmwh_pack">';
        foreach ($options as $option) {
            echo '<option value="' . esc_attr($option['id']) . '">' . esc_html($option['label']) . '</option>';
        }
        echo '</select></label>';
    }

    public static function cartData(array $data, int $product, int $variation, $quantity): array {
        $pack = isset($data[self::PACK]) ? self::pack($data[self::PACK]) : self::requestPack();
        unset($data[self::PACK]);
        if ($pack !== 'piece') {
            $data[self::PACK] = $pack;
        }
        return $data;
    }

    public static function storeData(array $data, \WP_REST_Request $request): array {
        $extensions = $request->get_param('extensions');
        $pack = $extensions['sfm4u-wholesale']['pack'] ?? 'piece';
        try {
            if (self::pack($pack) !== 'piece') {
                $data['cart_item_data'][self::PACK] = $pack;
            }
        } catch (DomainException $e) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('sfmwh_invalid_pack', __($e->getMessage(), 'sfm4u-wholesale'), 400);
        }
        return $data;
    }

    private static function quote(int $id, $quantity, string $pack): ?array {
        // Plan section 4 step 1: an active SFM4U Business / Business Black member gets wholesale at any quantity.
        $uid = get_current_user_id();
        if ($uid && get_option('sfmwh_enabled', 'no') === 'yes' && MemberPrice::memberFor($uid)) {
            $q = is_float($quantity) && floor($quantity) === $quantity ? (int) $quantity : $quantity;
            $member = MemberPrice::quote($id, Rule::number($q, 1, 1000000), $pack, $uid);
            if ($member) {
                return $member;
            }
        }
        $rule = self::eligibleRule($id);
        if (!$rule) {
            if ($pack !== 'piece') {
                throw new DomainException(__('The selected wholesale pack is unavailable for this buyer.', 'sfm4u-wholesale'));
            }
            return null;
        }
        // The Store API uses floats for its numeric quantity schema.
        if (is_float($quantity) && $quantity >= 1 && $quantity <= 1000000 && floor($quantity) === $quantity) {
            $quantity = (int) $quantity;
        }
        return Rule::quote($rule, Rule::number($quantity, 1, 1000000), $pack);
    }

    public static function itemQuote(array $item): ?array {
        return self::quote((int) ($item['variation_id'] ?: $item['product_id']), $item['quantity'],
            self::pack($item[self::PACK] ?? 'piece'));
    }

    public static function validateAdd($passed, $product, $quantity, $variation = 0, $attributes = [], $data = []): bool {
        if (!$passed) {
            return false;
        }
        try {
            $pack = isset($data[self::PACK]) ? self::pack($data[self::PACK]) : self::requestPack();
            $id = (int) ($variation ?: $product);
            $line = $quantity;
            foreach (WC()->cart->get_cart() as $item) {
                if ((int) ($item['variation_id'] ?: $item['product_id']) === $id && ($item[self::PACK] ?? 'piece') === $pack) {
                    $line += $item['quantity'];
                }
            }
            self::quote($id, $line, $pack);
            return true;
        } catch (DomainException $e) {
            wc_add_notice(__($e->getMessage(), 'sfm4u-wholesale'), 'error');
            return false;
        }
    }

    public static function validateUpdate($passed, $key, array $item, $quantity): bool {
        if (!$passed || !$quantity) {
            return (bool) $passed;
        }
        $item['quantity'] = $quantity;
        try {
            self::itemQuote($item);
            return true;
        } catch (DomainException $e) {
            wc_add_notice(__($e->getMessage(), 'sfm4u-wholesale'), 'error');
            return false;
        }
    }

    public static function storeQuantity($valid, $quantity, $product, array $item) {
        $item['quantity'] = $quantity;
        try {
            self::itemQuote($item);
            return $valid;
        } catch (DomainException $e) {
            return new \WP_Error('sfmwh_invalid_quantity', __($e->getMessage(), 'sfm4u-wholesale'));
        }
    }

    private static function catalogueUnitPrice(\WC_Product $product, array $quote): string {
        $net = Rule::unitPrice($quote);
        if (!wc_tax_enabled() || !wc_prices_include_tax() || !$product->is_taxable()) {
            return $net;
        }
        // WooCommerce inclusive catalogue prices include store-base tax. Rules stay net.
        $rates = \WC_Tax::get_base_tax_rates($product->get_tax_class('unfiltered'));
        $tax = array_sum(\WC_Tax::calc_tax((float) $net, $rates, false));
        return wc_format_decimal((float) $net + $tax, false);
    }

    public static function priceCart(\WC_Cart $cart): void {
        foreach ($cart->get_cart() as $key => $item) {
            $quote = null;
            try {
                $quote = self::itemQuote($item);
            } catch (DomainException $e) {
                // Checkout validation rejects this line.
            }
            if ($quote) {
                $item['data'] = clone $item['data'];
                $item['data']->set_price(self::catalogueUnitPrice($item['data'], $quote));
                $item['sfmwh_price_applied'] = true;
            } elseif (!empty($item['sfmwh_price_applied'])) {
                $base = wc_get_product($item['data']->get_id());
                if ($base) {
                    $item['data'] = clone $base;
                }
                unset($item['sfmwh_price_applied']);
            } else {
                continue;
            }
            $cart->cart_contents[$key] = $item;
        }
    }

    private static function cartViolations(\WC_Cart $cart): array {
        $messages = [];
        $minimum = max(0, (int) get_option('sfmwh_min_order_minor', 0));
        $remaining = $minimum;
        $hasWholesale = false;
        foreach ($cart->get_cart() as $item) {
            try {
                $quote = self::itemQuote($item);
                if ($quote) {
                    $hasWholesale = true;
                    $remaining = max(0, $remaining - $quote['line_minor']);
                }
            } catch (DomainException $e) {
                $messages[] = __($e->getMessage(), 'sfm4u-wholesale');
            }
        }
        if ($hasWholesale && $remaining > 0) {
            $messages[] = sprintf(__('The wholesale subtotal must reach %1$s %2$s.', 'sfm4u-wholesale'),
                get_woocommerce_currency(), Rule::decimal($minimum, wc_get_price_decimals()));
        }
        return array_unique($messages);
    }

    public static function checkCart(): void {
        foreach (self::cartViolations(WC()->cart) as $message) {
            if (!wc_has_notice($message, 'error')) {
                wc_add_notice($message, 'error');
            }
        }
    }

    public static function storeErrors(\WP_Error $errors, \WC_Cart $cart): void {
        foreach (self::cartViolations($cart) as $message) {
            $errors->add('sfmwh_invalid_cart', $message);
        }
    }

    public static function coupon($valid): bool {
        if (!$valid || !WC()->cart) {
            return (bool) $valid;
        }
        foreach (WC()->cart->get_cart() as $item) {
            try {
                if (self::itemQuote($item)) {
                    return false;
                }
            } catch (DomainException $error) {
                return false;
            }
        }
        return true;
    }

    public static function displayPack(array $display, array $item): array {
        try {
            $quote = self::itemQuote($item);
            if ($quote && $quote['pack'] !== 'piece') {
                $display[] = ['key' => __('Wholesale pack', 'sfm4u-wholesale'),
                    'value' => $quote['pack_count'] . ' × ' . $quote['pack_factor'] . ' (' . $quote['pack'] . ')'];
            }
        } catch (DomainException $e) {
            // The cart validation reports the error.
        }
        return $display;
    }

    public static function snapshot(\WC_Order_Item_Product $line, $key, array $item, \WC_Order $order): void {
        $quote = self::itemQuote($item);
        if ($quote) {
            $expected = Rule::decimal($quote['line_minor'], $quote['scale']);
            $actual = wc_format_decimal($line->get_subtotal(), $quote['scale']);
            // Store API creates lines before setting currency on a new draft.
            $currency = $order->get_currency();
            if ($currency === '' && !$order->get_id()) {
                $currency = get_woocommerce_currency();
            }
            if ((string) $item['data']->get_price() !== self::catalogueUnitPrice($item['data'], $quote)
                || $currency !== $quote['currency'] || $actual !== $expected) {
                throw new DomainException(__('The wholesale quote changed. Refresh the cart and try again.', 'sfm4u-wholesale'));
            }
            $line->add_meta_data('_sfmwh_snapshot_v1', $quote, true);
            if ($quote['currency'] === 'BDT' && class_exists(PurchaseHistory::class, false)) {
                PurchaseHistory::capture($line, $item['data'], (int) ($order->get_customer_id() ?: get_current_user_id()));
            }
        }
    }
}
