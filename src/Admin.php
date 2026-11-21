<?php
namespace SFM4U\Wholesale;

use DomainException;

final class Admin {
    public static function register(): void {
        add_action('admin_post_sfmwh_csv_export', [self::class, 'download']);
        add_action('edit_user_profile', [self::class, 'buyerProfile']);
        add_action('edit_user_profile_update', [self::class, 'saveBuyerProfile']);
        add_filter('manage_users_columns', static function (array $columns): array {
            if (current_user_can('manage_woocommerce') && current_user_can('edit_users')) {
                $columns['sfmwh_buyer'] = __('Wholesale buying', 'sfm4u-wholesale');
            }
            return $columns;
        });
        add_filter('manage_users_custom_column', static function (string $value, string $column, int $userId): string {
            return $column === 'sfmwh_buyer' && current_user_can('manage_woocommerce') && current_user_can('edit_user', $userId)
                ? esc_html(BuyerApplication::status($userId)) : $value;
        }, 10, 3);
        add_action('admin_menu', static function () {
            add_submenu_page('woocommerce', __('Wholesale rules', 'sfm4u-wholesale'),
                __('Wholesale rules', 'sfm4u-wholesale'), 'manage_woocommerce', 'sfmwh-rules', [self::class, 'page']);
        });
        add_action('woocommerce_product_options_pricing', static function () {
            global $post;
            if ($post && current_user_can('manage_woocommerce') && current_user_can('edit_post', $post->ID)) {
                $url = add_query_arg(['page' => 'sfmwh-rules', 'product_id' => $post->ID], admin_url('admin.php'));
                echo '<p class="form-field"><a href="' . esc_url($url) . '">' .
                    esc_html__('Edit wholesale rules', 'sfm4u-wholesale') . '</a></p>';
            }
        });
    }

    public static function buyerProfile(\WP_User $user): void {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_user', $user->ID)) {
            return;
        }
        $status = get_user_meta($user->ID, '_sfmwh_buyer_status', true);
        wp_nonce_field('sfmwh_buyer_' . $user->ID, 'sfmwh_buyer_nonce');
        echo '<h2>' . esc_html__('Wholesale buyer approval', 'sfm4u-wholesale') . '</h2>';
        echo '<label for="sfmwh-buyer-status">' . esc_html__('Status', 'sfm4u-wholesale') . '</label> ';
        echo '<select id="sfmwh-buyer-status" name="sfmwh_buyer_status">';
        foreach (['' => __('Unapproved', 'sfm4u-wholesale'), 'approved' => __('Approved', 'sfm4u-wholesale'),
            'suspended' => __('Suspended', 'sfm4u-wholesale')] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($key, $status, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p><label>' . esc_html__('Wholesale price group', 'sfm4u-wholesale') . ' <select name="sfmwh_price_group">';
        $assigned = get_user_meta($user->ID, '_sfmwh_price_group', true);
        foreach (['' => __('Automatic (Bronze by default)', 'sfm4u-wholesale'), 'bronze' => __('Bronze', 'sfm4u-wholesale'),
            'silver' => __('Silver', 'sfm4u-wholesale'), 'gold' => __('Gold', 'sfm4u-wholesale')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($value, $assigned, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
    }

    public static function saveBuyerProfile(int $userId): void {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_user', $userId)
            || !isset($_POST['sfmwh_buyer_status'])
            || !wp_verify_nonce(self::input('sfmwh_buyer_nonce'), 'sfmwh_buyer_' . $userId)) {
            return;
        }
        $status = self::input('sfmwh_buyer_status');
        if (in_array($status, ['', 'approved', 'suspended'], true)) {
            update_user_meta($userId, '_sfmwh_buyer_status', $status);
        }
        if (isset($_POST['sfmwh_price_group']) && is_string($_POST['sfmwh_price_group'])) {
            $group = self::input('sfmwh_price_group');
            if ($group === '') {
                delete_user_meta($userId, '_sfmwh_price_group');
            } elseif (in_array($group, Rule::GROUPS, true)) {
                update_user_meta($userId, '_sfmwh_price_group', $group);
            }
        }
    }

    public static function product(int $id): \WC_Product {
        $product = $id ? wc_get_product($id) : false;
        if (!$product || !$product->is_type(['simple', 'variation'])
            || !current_user_can('manage_woocommerce') || !current_user_can('edit_post', $id)) {
            throw new DomainException(__('Select a simple product or variation you can edit.', 'sfm4u-wholesale'));
        }
        return $product;
    }

    private static function input(string $name): string {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? trim(wp_unslash($_POST[$name])) : '';
    }

    private static function jsonMap(string $source): array {
        if ($source === '') {
            return [];
        }
        $data = json_decode($source, true, 4);
        if (!is_array($data) || !str_starts_with($source, '{')) {
            throw new DomainException(__('Enter a JSON object for tiers and packs.', 'sfm4u-wholesale'));
        }
        return $data;
    }

    private static function ruleFromForm(): array {
        return [
            'buyer' => self::input('buyer'), 'stock_unit' => self::input('stock_unit'), 'minor' => self::input('minor'),
            'min' => self::input('min'), 'max' => self::input('max'), 'step' => self::input('step'),
            'tiers' => self::jsonMap(self::input('tiers')), 'packs' => self::jsonMap(self::input('packs')),
            'pack_minor' => self::jsonMap(self::input('pack_minor')),
            'group_prices' => self::jsonMap(self::input('group_prices')),
        ];
    }

    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot manage wholesale rules.', 'sfm4u-wholesale'));
        }
        $product = null;
        $preview = [];
        $token = '';
        $id = is_string($_GET['product_id'] ?? null) ? absint(wp_unslash($_GET['product_id'])) : 0;
        echo '<div class="wrap"><h1>' . esc_html__('Wholesale rules', 'sfm4u-wholesale') . '</h1>';
        try {
            if (isset($_POST['sfmwh_action'])) {
                check_admin_referer('sfmwh_rules_' . $id);
                $action = self::input('sfmwh_action');
                if ($action === 'settings') {
                    $minimum = Rule::number(self::input('minimum_order_minor'), 0, 1000000000);
                    update_option('sfmwh_min_order_minor', $minimum);
                    update_option('sfmwh_enabled', self::input('enabled') === 'yes' ? 'yes' : 'no');
                } elseif ($action === 'work_engine') {
                    WorkEngineEvents::saveSettings(self::input('work_engine_enabled') === 'yes', self::input('work_engine_url'),
                        self::input('work_engine_wholesale_low_stock') === 'yes');
                } elseif ($action === 'save') {
                    Commerce::saveRule(self::product($id), self::ruleFromForm());
                } elseif ($action === 'preview') {
                    $file = $_FILES['rules_csv'] ?? [];
                    $temporary = $file['tmp_name'] ?? '';
                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($temporary)
                        || !is_uploaded_file($temporary) || filesize($temporary) > RuleCsv::MAX_BYTES) {
                        throw new DomainException(__('Upload a valid CSV within the size limit.', 'sfm4u-wholesale'));
                    }
                    $preview = RuleCsv::preview((string) file_get_contents($temporary));
                    $token = wp_generate_uuid4();
                    set_transient('sfmwh_' . get_current_user_id() . '_' . $token, $preview, 600);
                } elseif ($action === 'apply') {
                    $token = self::input('preview_token');
                    if (!wp_is_uuid($token, 4)) {
                        throw new DomainException(__('Preview the file before importing.', 'sfm4u-wholesale'));
                    }
                    $key = 'sfmwh_' . get_current_user_id() . '_' . $token;
                    $pending = get_transient($key);
                    if (!is_array($pending) || !delete_transient($key)) {
                        throw new DomainException(__('The preview expired. Preview it again.', 'sfm4u-wholesale'));
                    }
                    RuleCsv::apply($pending);
                    $token = '';
                } else {
                    throw new DomainException(__('Unknown wholesale action.', 'sfm4u-wholesale'));
                }
                if ($action !== 'preview') {
                    echo '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'sfm4u-wholesale') . '</p></div>';
                }
                // Page rendering reads the updated rule through WooCommerce CRUD.
            }
            $product = $id ? self::product($id) : null;
        } catch (DomainException $error) {
            echo '<div class="notice notice-error"><p>' . esc_html(__($error->getMessage(), 'sfm4u-wholesale')) . '</p></div>';
        }
        echo '<form method="post">';
        wp_nonce_field('sfmwh_rules_' . $id);
        echo '<label><input type="checkbox" name="enabled" value="yes" ' .
            checked('yes', get_option('sfmwh_enabled', 'no'), false) . '> ' .
            esc_html__('Enable wholesale checkout', 'sfm4u-wholesale') . '</label> ';
        echo '<p><label>' . esc_html__('Minimum wholesale subtotal in minor currency units (0 disables)', 'sfm4u-wholesale') .
            ' <input type="number" min="0" max="1000000000" name="minimum_order_minor" value="' .
            esc_attr(get_option('sfmwh_min_order_minor', 0)) . '"></label></p>';
        submit_button(__('Save settings', 'sfm4u-wholesale'), 'secondary', 'submit', false);
        echo '<input type="hidden" name="sfmwh_action" value="settings"></form>';
        echo '<form method="get"><input type="hidden" name="page" value="sfmwh-rules"><p><label>' .
            esc_html__('Simple product or variation ID', 'sfm4u-wholesale') .
            ' <input type="number" min="1" name="product_id" value="' . esc_attr($id ?: '') . '"></label> ';
        submit_button(__('Open rule', 'sfm4u-wholesale'), 'secondary', 'submit', false);
        echo '</p></form>';
        if ($product) {
            self::renderRule($product);
        }
        self::renderCsv($id, $preview, $token);
        if (current_user_can('manage_options')) {
            $config = WorkEngineEvents::settings();
            echo '<h2>' . esc_html__('Work Engine events', 'sfm4u-wholesale') . '</h2><form method="post">';
            wp_nonce_field('sfmwh_rules_' . $id);
            echo '<input type="hidden" name="sfmwh_action" value="work_engine"><p><label><input type="checkbox" name="work_engine_enabled" value="yes" ' .
                checked(true, $config['enabled'], false) . '> ' . esc_html__('Enable Work Engine events', 'sfm4u-wholesale') . '</label></p>';
            echo '<p><label>' . esc_html__('n8n webhook URL', 'sfm4u-wholesale') . ' <input class="regular-text" type="url" name="work_engine_url" value="' .
                esc_attr($config['url']) . '"></label></p><p>' .
                esc_html__('Set SFM4U_WORK_ENGINE_KEY in wp-config.php before enabling delivery.', 'sfm4u-wholesale') . '</p>';
            echo '<p><label><input type="checkbox" name="work_engine_wholesale_low_stock" value="yes" ' .
                checked(true, $config['wholesale_low_stock'], false) . '> ' .
                esc_html__('Send wholesale.low_stock for explicit product thresholds', 'sfm4u-wholesale') . '</label></p>';
            submit_button(__('Save Work Engine settings', 'sfm4u-wholesale'), 'secondary');
            echo '</form>';
        }
        echo '</div>';
    }

    public static function download(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot export wholesale rules.', 'sfm4u-wholesale'));
        }
        $product = is_string($_GET['product_id'] ?? null) ? absint(wp_unslash($_GET['product_id'])) : 0;
        check_admin_referer('sfmwh_csv_export_' . $product);
        $stream = fopen('php://temp', 'w+');
        try {
            RuleCsv::export($stream, $product);
        } catch (DomainException $error) {
            fclose($stream);
            wp_die(esc_html(__($error->getMessage(), 'sfm4u-wholesale')));
        }
        rewind($stream);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sfm4u-wholesale-rules.csv"');
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    private static function renderCsv(int $id, array $preview, string $token): void {
        $route = add_query_arg(['action' => 'sfmwh_csv_export', 'product_id' => $id], admin_url('admin-post.php'));
        $url = wp_nonce_url($route, 'sfmwh_csv_export_' . $id);
        echo '<h2>' . esc_html__('CSV rules', 'sfm4u-wholesale') . '</h2><p><a href="' . esc_url($url) . '">' .
            esc_html__('Download CSV', 'sfm4u-wholesale') . '</a></p>';
        echo '<p>' . esc_html__('For a simple price list, use the header sku,price and one row per product. Example: CASE12,125.50. Enter the pre-tax price per stock unit in store currency.', 'sfm4u-wholesale') . '</p>';
        echo '<p>' . esc_html__('New rules are for approved buyers. Existing buyer groups, quantity limits, tiers and pack prices are kept.', 'sfm4u-wholesale') . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('sfmwh_rules_' . $id);
        echo '<input type="hidden" name="sfmwh_action" value="preview"><label>' .
            esc_html__('Rules CSV', 'sfm4u-wholesale') . ' <input required type="file" name="rules_csv" accept=".csv,text/csv"></label>';
        submit_button(__('Preview CSV', 'sfm4u-wholesale'), 'secondary');
        echo '</form>';
        if (!$preview) {
            return;
        }
        echo '<form method="post">';
        wp_nonce_field('sfmwh_rules_' . $id);
        echo '<input type="hidden" name="sfmwh_action" value="apply"><input type="hidden" name="preview_token" value="' .
            esc_attr($token) . '"><ul>';
        foreach ($preview as $row) {
            $rule = $row['rule'];
            $price = Rule::decimal($rule['minor'], $rule['scale']);
            echo '<li>' . esc_html($row['sku'] . ' · ' . $rule['currency'] . ' ' . $price . ' · ' . $rule['buyer']) . '</li>';
        }
        echo '</ul>';
        submit_button(__('Apply previewed rules', 'sfm4u-wholesale'));
        echo '</form>';
    }

    private static function renderRule(\WC_Product $product): void {
        $data = $product->get_meta(Commerce::META, true, 'edit');
        $data = is_array($data) ? $data : [];
        echo '<h2>' . esc_html($product->get_name()) . '</h2><form method="post">';
        wp_nonce_field('sfmwh_rules_' . $product->get_id());
        echo '<input type="hidden" name="sfmwh_action" value="save">';
        $fields = [
            'buyer' => [__('Buyer group (everyone, logged_in, approved, role:customer, approved:customer or tier:gold)', 'sfm4u-wholesale'), 'logged_in'],
            'minor' => [__('Price per stock unit in minor currency units', 'sfm4u-wholesale'), ''],
            'stock_unit' => [__('WooCommerce stock unit (piece, g, ml, cm)', 'sfm4u-wholesale'), 'piece'],
            'min' => [__('Minimum stock quantity', 'sfm4u-wholesale'), 1],
            'max' => [__('Maximum stock quantity', 'sfm4u-wholesale'), 1000000],
            'step' => [__('Quantity step', 'sfm4u-wholesale'), 1],
            'group_prices' => [__('Bronze, Silver and Gold prices in minor units, e.g. {"bronze":1000,"silver":900,"gold":800}', 'sfm4u-wholesale'), '{}'],
            'tiers' => [__('Tier prices by stock threshold, e.g. {"24":900}', 'sfm4u-wholesale'), '{}'],
            'packs' => [__('Stock units per pack, e.g. {"case":12}', 'sfm4u-wholesale'), '{}'],
            'pack_minor' => [__('Fixed pack prices, e.g. {"case":10000}', 'sfm4u-wholesale'), '{}'],
        ];
        foreach ($fields as $field => [$label, $default]) {
            $value = $data[$field] ?? $default;
            $value = is_array($value) ? wp_json_encode((object) $value) : $value;
            echo '<p><label for="sfmwh-' . esc_attr($field) . '">' . esc_html($label) . '</label><br>' .
                '<input class="regular-text" id="sfmwh-' . esc_attr($field) . '" name="' . esc_attr($field) .
                '" value="' . esc_attr($value) . '"></p>';
        }
        submit_button(__('Save wholesale rule', 'sfm4u-wholesale'));
        echo '</form>';
    }
}
