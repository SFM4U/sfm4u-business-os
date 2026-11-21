<?php
namespace SFM4U\Wholesale;

use DomainException;

/**
 * Lets the shopkeeper who owns a product set its own wholesale terms:
 * wholesale price, minimum quantity, step, quantity tiers ("50=90, 100=85"), an optional floor price
 * and whether both prices are shown publicly. Simple and variable products.
 *
 * Shown on the WooCommerce product screen (wp-admin) and on the Dokan seller
 * dashboard product form. Only the product's owner (or a store manager) may save.
 * Packs, pack prices, group prices and stock unit set by a manager are kept as-is.
 */
final class SellerRules {
    private const NONCE = 'sfmwh_seller_rules';
    public const MAX_TIERS = 3;

    public static function register(): void {
        add_action('woocommerce_product_options_pricing', [self::class, 'renderAdmin'], 30);
        add_action('woocommerce_admin_process_product_object', [self::class, 'saveAdmin'], 30);
        add_action('dokan_product_edit_after_pricing', [self::class, 'renderDokan'], 30, 2);
        add_action('dokan_product_updated', [self::class, 'saveDokan'], 30, 1);
        add_action('dokan_new_product_added', [self::class, 'saveDokan'], 30, 1);
    }

    /** The product owner, or a store manager, who can also edit this product. */
    public static function canManage(int $productId, ?int $userId = null): bool {
        $userId = $userId ?? get_current_user_id();
        if (!$productId || !$userId || !user_can($userId, 'edit_post', $productId)) {
            return false;
        }
        if (user_can($userId, 'manage_woocommerce')) {
            return true;
        }
        $owner = (int) get_post_field('post_author', $productId);
        if ($owner !== $userId) {
            return false;
        }
        if (function_exists('dokan_is_user_seller')) {
            return dokan_is_user_seller($userId) && (!function_exists('dokan_is_seller_enabled') || dokan_is_seller_enabled($userId));
        }
        return user_can($userId, 'edit_products');
    }

    /**
     * Pure conversion of the form into rule data for Commerce::saveRule().
     * Returns null when the shopkeeper turned wholesale off.
     * Prices are typed in major units (e.g. taka) and stored in minor units.
     */
    public static function fromInput(array $in, ?array $prior, int $decimals): ?array {
        if (($in['sfmwh_s_enabled'] ?? '') !== 'yes') {
            return null;
        }
        $minor = self::money($in['sfmwh_s_price'] ?? '', $decimals);
        if ($minor === null) {
            throw new DomainException(__('Enter the wholesale price.', 'sfm4u-wholesale'));
        }
        $min = self::count($in['sfmwh_s_min'] ?? '1', __('Enter a valid minimum quantity.', 'sfm4u-wholesale'));
        $step = self::count(($in['sfmwh_s_step'] ?? '') === '' ? '1' : $in['sfmwh_s_step'],
            __('Enter a valid quantity step.', 'sfm4u-wholesale'));
        $tiers = [];
        for ($i = 1; $i <= self::MAX_TIERS; $i++) {
            $qty = trim((string) ($in['sfmwh_s_tq' . $i] ?? ''));
            $price = trim((string) ($in['sfmwh_s_tp' . $i] ?? ''));
            if ($qty === '' && $price === '') {
                continue;
            }
            $q = self::count($qty, __('Each discount row needs a quantity and a price.', 'sfm4u-wholesale'));
            $p = self::money($price, $decimals);
            if ($p === null) {
                throw new DomainException(__('Each discount row needs a quantity and a price.', 'sfm4u-wholesale'));
            }
            if ($q <= $min) {
                throw new DomainException(__('A discount quantity must be more than the minimum quantity.', 'sfm4u-wholesale'));
            }
            if ($p >= $minor) {
                throw new DomainException(__('A discount price must be lower than the wholesale price.', 'sfm4u-wholesale'));
            }
            $tiers[(string) $q] = (string) $p;
        }
        // Shared tier text (same as AlgiBazar Product Experience): "12=95, 50=90". Replaces the rows when given.
        $text = trim((string) ($in['sfmwh_s_tiers_text'] ?? ''));
        if ($text !== '') {
            $tiers = [];
            foreach (Rule::parseTierText($text, $decimals) as $q => $p) {
                if ($q <= $min) {
                    throw new DomainException(__('A discount quantity must be more than the minimum quantity.', 'sfm4u-wholesale'));
                }
                if ($p >= $minor) {
                    throw new DomainException(__('A discount price must be lower than the wholesale price.', 'sfm4u-wholesale'));
                }
                $tiers[(string) $q] = (string) $p;
            }
        }
        $floorText = trim((string) ($in['sfmwh_s_floor'] ?? ''));
        $floor = $floorText === '' ? null : self::money($floorText, $decimals);
        if ($floorText !== '' && $floor === null) {
            throw new DomainException(__('Enter a valid lowest price.', 'sfm4u-wholesale'));
        }
        $public = ($in['sfmwh_s_public'] ?? '') === 'yes';
        $prior = is_array($prior) ? $prior : [];
        $buyer = is_string($prior['buyer'] ?? null) ? $prior['buyer'] : 'approved';
        if ($public) {
            $buyer = 'everyone';
        } elseif (!empty($prior['public'])) {
            $buyer = 'approved';
        }
        return [
            'buyer' => $buyer, 'public' => $public,
            'floor_minor' => $floor === null ? '' : (string) $floor,
            'stock_unit' => $prior['stock_unit'] ?? 'piece',
            'minor' => (string) $minor, 'min' => (string) $min,
            'max' => (string) max($min, (int) ($prior['max'] ?? 1000000)),
            'step' => (string) $step, 'tiers' => $tiers,
            'packs' => is_array($prior['packs'] ?? null) ? $prior['packs'] : [],
            'pack_minor' => is_array($prior['pack_minor'] ?? null) ? $prior['pack_minor'] : [],
            'group_prices' => is_array($prior['group_prices'] ?? null) ? $prior['group_prices'] : [],
        ];
    }

    private static function money($value, int $decimals): ?int {
        $value = str_replace([',', ' '], '', trim((string) $value));
        $value = strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if ($value === '' || !preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,6})?$/D', $value)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($frac) > $decimals && rtrim(substr($frac, $decimals), '0') !== '') {
            return null;
        }
        $minor = (int) $whole * (10 ** $decimals) + (int) str_pad(substr($frac, 0, $decimals), $decimals, '0');
        return $minor >= 1 && $minor <= 1000000000 ? $minor : null;
    }

    private static function count($value, string $error): int {
        $value = strtr(trim((string) $value), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if (!preg_match('/^[0-9]{1,7}$/D', $value) || (int) $value < 1 || (int) $value > 1000000) {
            throw new DomainException($error);
        }
        return (int) $value;
    }

    private static function display(int $minor, int $decimals): string {
        return Rule::decimal($minor, $decimals);
    }

    /** Field values for the form, from the saved rule. */
    private static function values(\WC_Product $product): array {
        $rule = $product->get_meta(Commerce::META, true, 'edit');
        $d = wc_get_price_decimals();
        $v = ['enabled' => is_array($rule), 'price' => '', 'min' => '1', 'step' => '1', 'tiers' => [],
            'public' => is_array($rule) && !empty($rule['public']), 'text' => '', 'floor' => ''];
        if (is_array($rule)) {
            $sc = (int) ($rule['scale'] ?? $d);
            $v['text'] = Rule::tierText(array_map('intval', (array) ($rule['tiers'] ?? [])), $sc);
            $v['floor'] = isset($rule['floor_minor']) ? self::display((int) $rule['floor_minor'], $sc) : '';
            $v['price'] = self::display((int) $rule['minor'], (int) ($rule['scale'] ?? $d));
            $v['min'] = (string) $rule['min'];
            $v['step'] = (string) $rule['step'];
            foreach (($rule['tiers'] ?? []) as $q => $p) {
                $v['tiers'][] = [(string) $q, self::display((int) $p, (int) ($rule['scale'] ?? $d))];
            }
        }
        return $v;
    }

    private static function fields(\WC_Product $product, string $wrapClass): void {
        $v = self::values($product);
        $sym = get_woocommerce_currency_symbol();
        wp_nonce_field(self::NONCE . '_' . $product->get_id(), 'sfmwh_s_nonce');
        echo '<div class="' . esc_attr($wrapClass) . ' sfmwh-seller-rules">';
        echo '<p><strong>' . esc_html__('Wholesale (set by the shop)', 'sfm4u-wholesale') . '</strong><br><span class="description">'
            . esc_html__('You decide how many a buyer must take to get the wholesale price. Only approved wholesale buyers see it.', 'sfm4u-wholesale')
            . '</span></p>';
        echo '<p><label><input type="checkbox" name="sfmwh_s_enabled" value="yes" ' . checked($v['enabled'], true, false) . '> '
            . esc_html__('Sell this product at wholesale', 'sfm4u-wholesale') . '</label></p>';
        $row = static function (string $name, string $label, string $value, string $hint = ''): void {
            echo '<p class="form-field"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label> '
                . '<input type="text" inputmode="decimal" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="'
                . esc_attr($value) . '" style="max-width:10em">'
                . ($hint !== '' ? ' <span class="description">' . esc_html($hint) . '</span>' : '') . '</p>';
        };
        /* translators: %s: currency symbol */
        $row('sfmwh_s_price', sprintf(__('Wholesale price per unit (%s)', 'sfm4u-wholesale'), html_entity_decode($sym)), $v['price']);
        $row('sfmwh_s_min', __('Minimum quantity', 'sfm4u-wholesale'), $v['min'], __('e.g. 10', 'sfm4u-wholesale'));
        $row('sfmwh_s_step', __('Sell in steps of', 'sfm4u-wholesale'), $v['step'], __('1 = any amount above the minimum', 'sfm4u-wholesale'));
        $row('sfmwh_s_tiers_text', __('Bigger order, lower price (optional)', 'sfm4u-wholesale'), $v['text'],
            __('quantity=price, e.g. 50=90, 100=85', 'sfm4u-wholesale'));
        $row('sfmwh_s_floor', __('Lowest price you accept (optional)', 'sfm4u-wholesale'), $v['floor'],
            __('member and tier prices never go below this', 'sfm4u-wholesale'));
        echo '<p><label><input type="checkbox" name="sfmwh_s_public" value="yes" ' . checked($v['public'], true, false) . '> '
            . esc_html__('Show both prices to every customer (1 piece = retail, minimum or more = wholesale)', 'sfm4u-wholesale') . '</label></p>';
        echo '</div>';
    }

    /** Simple and variable products (variations use the parent's rule). */
    private static function supported(\WC_Product $product): bool {
        return $product->is_type('simple') || $product->is_type('variable');
    }

    public static function renderAdmin(): void {
        global $post;
        $product = $post ? wc_get_product($post->ID) : false;
        if (!$product || !self::supported($product) || !self::canManage($product->get_id())) {
            return;
        }
        self::fields($product, 'options_group');
    }

    public static function renderDokan($post, $postId = 0): void {
        $id = (int) ($postId ?: (is_object($post) ? $post->ID : 0));
        $product = $id ? wc_get_product($id) : false;
        if (!$product || !self::supported($product) || !self::canManage($id)) {
            return;
        }
        self::fields($product, 'dokan-form-group');
    }

    public static function saveAdmin(\WC_Product $product): void {
        self::save($product, true);
    }

    public static function saveDokan($productId): void {
        $product = wc_get_product((int) $productId);
        if ($product) {
            self::save($product, false);
        }
    }

    private static function save(\WC_Product $product, bool $admin): void {
        $id = $product->get_id();
        if (!isset($_POST['sfmwh_s_nonce']) || !is_string($_POST['sfmwh_s_nonce']) || !self::supported($product)
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sfmwh_s_nonce'])), self::NONCE . '_' . $id)
            || !self::canManage($id)) {
            return;
        }
        $in = [];
        foreach ($_POST as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'sfmwh_s_') && is_string($value)) {
                $in[$key] = sanitize_text_field(wp_unslash($value));
            }
        }
        $prior = $product->get_meta(Commerce::META, true, 'edit');
        try {
            $data = self::fromInput($in, is_array($prior) ? $prior : null, wc_get_price_decimals());
            if ($data === null) {
                if (is_array($prior)) {
                    $product->delete_meta_data(Commerce::META);
                    $product->save_meta_data();
                }
                return;
            }
            Commerce::saveRule($product, $data);
        } catch (DomainException $e) {
            if ($admin && class_exists('WC_Admin_Meta_Boxes')) {
                \WC_Admin_Meta_Boxes::add_error(__('Wholesale was not saved:', 'sfm4u-wholesale') . ' ' . $e->getMessage());
            } elseif (function_exists('wc_add_notice')) {
                wc_add_notice(__('Wholesale was not saved:', 'sfm4u-wholesale') . ' ' . $e->getMessage(), 'error');
            }
        }
    }
}
