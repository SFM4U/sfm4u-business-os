<?php
declare(strict_types=1);

// Keep WordPress header operations independent of assertion output in this CLI process.
ob_start();
$phase = getenv('ROLE_PHASE');
if (in_array($phase, ['fresh', 'baseline'], true)) {
    define('WP_INSTALLING', true);
}

// Isolated WordPress and WooCommerce lifecycle using a disposable SQLite site.
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function role_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}

if (in_array($phase, ['fresh', 'baseline'], true)) {
    $site = wp_install('Synthetic role lifecycle', 'role_fixture_owner', 'owner@example.invalid', false, '', wp_generate_password(24));
    wp_installing(false);
    wp_set_current_user((int) $site['user_id']);
    role_check(!is_wp_error(activate_plugin('woocommerce/woocommerce.php')) && get_role('shop_manager') !== null, 'Fresh WooCommerce activation creates shop_manager');
    role_check(!is_wp_error(activate_plugin('sfm4u-wholesale/sfm4u-wholesale.php')), 'Wholesale ZIP activation succeeds');
    // Browser onboarding is separate from this isolated role migration test.
    update_option(WC_Install::NEWLY_INSTALLED_OPTION, 'no');
    delete_transient('_wc_activation_redirect');
} else {
    wp_set_current_user(1);
}

$roleName = 'sfm4u_pos_cashier';
if ($phase === 'baseline') {
    role_check(!get_role('administrator')->has_cap($roleName) && get_role($roleName) === null,
        'Confirmed 0.1.7-dev baseline has no cashier grant or role');
    return;
}
if ($phase === 'upgrade') {
    update_option(WC_Install::NEWLY_INSTALLED_OPTION, 'no');
    delete_transient('_wc_activation_redirect');
    role_check(!get_role('administrator')->has_cap($roleName), 'File replacement alone reproduces missing grant before upgrade');
    do_action('admin_init');
}
if ($phase === 'uninstall') {
    deactivate_plugins('sfm4u-wholesale/sfm4u-wholesale.php');
    role_check(get_role('administrator')->has_cap($roleName), 'Deactivation preserves permission assignments');
    uninstall_plugin('sfm4u-wholesale/sfm4u-wholesale.php');
    role_check(!get_role('administrator')->has_cap($roleName) && !get_role('shop_manager')->has_cap($roleName)
        && !get_role($roleName) && !get_option('sfm4u_pos_role_version'), 'Uninstall removes grants, role and migration marker');
    $assigned = get_user_by('login', 'role_fixture_staff');
    role_check(!$assigned->has_cap($roleName) && get_user_meta($assigned->ID, '_sfm4u_pos_registers', true) === [7 => ['R1' => 'X']],
        'Uninstall clears the assigned-user direct grant and retains register data');
    return;
}

role_check(get_role('administrator')->has_cap($roleName) && get_role('shop_manager')->has_cap($roleName),
    'Administrator and shop manager receive POS cashier permission');
$cashier = get_role($roleName);
role_check($cashier && $cashier->has_cap('read') && $cashier->has_cap($roleName)
    && !$cashier->has_cap('manage_woocommerce') && !$cashier->has_cap('sfm4u_pos_credit'), 'POS cashier cannot manage WooCommerce or issue POS credits');
role_check(!get_role('subscriber')->has_cap($roleName), 'Unrelated roles receive no POS access');
do_action('admin_init');
role_check(get_option('sfm4u_pos_role_version') === '1', 'Repeat admin upgrade preserves the installed migration');
$staff = wp_insert_user(['user_login' => 'role_fixture_staff', 'user_pass' => wp_generate_password(24), 'role' => $roleName]);
role_check(is_int($staff) && user_can($staff, $roleName) && !user_can($staff, 'manage_woocommerce'), 'A synthetic user inherits only the limited POS grants');
update_user_meta($staff, '_sfm4u_pos_registers', [7 => ['R1' => 'X']]);
(new WP_User($staff))->add_cap($roleName);
