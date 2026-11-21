<?php
declare(strict_types=1);
namespace SFM4U\POS;

use DomainException;

/** Owner-managed cashier permissions and product scope; never writable by a cashier. */
final class RegisterAssignments
{
    private function canEdit(int $user): bool
    {
        return current_user_can('manage_woocommerce') && current_user_can('promote_users') && current_user_can('edit_user', $user);
    }

    public function register(): void
    {
        // Owners can also give a register to themselves (Profile page), so a one-person shop needs no extra login.
        foreach (['edit_user_profile', 'show_user_profile'] as $hook) { add_action($hook, [$this, 'render']); }
        foreach (['edit_user_profile_update', 'personal_options_update'] as $hook) {
            add_action($hook, function (int $user): void {
                try { $this->save($user); } catch (DomainException $error) {
                    wp_die(esc_html($error->getMessage()), '', ['response' => 422]);
                }
            });
        }
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'productField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProduct']);
    }

    public function render(\WP_User $user): void
    {
        if (!$this->canEdit($user->ID)) return;
        $lines = [];
        $assignments = get_user_meta($user->ID, '_sfm4u_pos_registers', true);
        foreach (is_array($assignments) ? $assignments : [] as $merchant => $registers) {
            foreach ($registers as $register => $cashier) $lines[] = $merchant . ',' . $register . ',' . $cashier;
        }
        echo '<h2>' . esc_html__('POS register assignments', 'sfm4u-wholesale') . '</h2>';
        wp_nonce_field('sfm4u_pos_assign_' . $user->ID, 'sfm4u_pos_assign_nonce', false);
        echo '<p><label for="sfm4u-pos-assignments">' . esc_html__('Merchant ID, register code, cashier code (one per line; blank revokes access)', 'sfm4u-wholesale')
            . '</label><br><textarea id="sfm4u-pos-assignments" name="sfm4u_pos_assignments" rows="5" maxlength="2000">'
            . esc_textarea(implode("\n", $lines)) . '</textarea></p>';
        echo '<p><label><input type="checkbox" name="sfm4u_pos_credit" value="1" '
            . checked($user->has_cap('sfm4u_pos_credit'), true, false) . '> '
            . esc_html__('Allow credit notes and cash refunds', 'sfm4u-wholesale') . '</label></p>';
    }

    public function save(int $user): void
    {
        if (!$this->canEdit($user) || !isset($_POST['sfm4u_pos_assignments'])
            || !wp_verify_nonce(self::input('sfm4u_pos_assign_nonce'), 'sfm4u_pos_assign_' . $user)) return;
        $text = trim(self::input('sfm4u_pos_assignments'));
        $lines = $text === '' ? [] : preg_split('/\R/', $text);
        $assignments = [];
        if (strlen($text) > 2000 || count($lines) > 30) throw new DomainException(__('Enter at most 30 register assignments.', 'sfm4u-wholesale'));
        foreach ($lines as $line) {
            $fields = array_map('trim', explode(',', $line));
            if (count($fields) !== 3 || !preg_match('/^[1-9][0-9]{0,9}$/D', $fields[0])
                || !preg_match('/^[A-Z0-9]{1,20}$/D', $fields[1]) || !preg_match('/^[A-Z0-9]{1,20}$/D', $fields[2])
                || isset($assignments[(int) $fields[0]][$fields[1]])) {
                throw new DomainException(__('Check the merchant and unique uppercase register/cashier codes.', 'sfm4u-wholesale'));
            }
            $assignments[(int) $fields[0]][$fields[1]] = $fields[2];
        }
        update_user_meta($user, '_sfm4u_pos_registers', $assignments);
        $account = new \WP_User($user);
        if ($assignments) $account->add_cap('sfm4u_pos_cashier'); else $account->remove_cap('sfm4u_pos_cashier');
        if ($assignments && self::input('sfm4u_pos_credit') === '1') $account->add_cap('sfm4u_pos_credit'); else $account->remove_cap('sfm4u_pos_credit');
    }

    public function productField(): void
    {
        global $product_object;
        if (!$product_object || !current_user_can('manage_woocommerce') || !current_user_can('edit_post', $product_object->get_id())) return;
        wp_nonce_field('sfm4u_pos_product_' . $product_object->get_id(), 'sfm4u_pos_product_nonce', false);
        woocommerce_wp_text_input(['id' => '_sfm4u_pos_merchant_id', 'type' => 'number', 'custom_attributes' => ['min' => '1', 'step' => '1'],
            'label' => __('POS merchant ID', 'sfm4u-wholesale'), 'description' => __('Leave blank to exclude this product from POS.', 'sfm4u-wholesale')]);
    }

    public function saveProduct(\WC_Product $product): void
    {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post', $product->get_id())
            || !wp_verify_nonce(self::input('sfm4u_pos_product_nonce'), 'sfm4u_pos_product_' . $product->get_id())) return;
        $merchant = self::input('_sfm4u_pos_merchant_id');
        if ($merchant === '') $product->delete_meta_data('_sfm4u_pos_merchant_id');
        elseif (preg_match('/^[1-9][0-9]{0,9}$/D', $merchant)) $product->update_meta_data('_sfm4u_pos_merchant_id', (int) $merchant);
    }

    private static function input(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? wp_unslash($_POST[$name]) : '';
    }
}
