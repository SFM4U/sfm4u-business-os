<?php
// Unsupported synthetic table engine must not lock the administrator out of repair.
wp_set_current_user(1);
$setupCountsTable = $wpdb->prefix . 'sfm4u_stock_counts';
$setupCountRows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $setupCountsTable));
try {
    if ($wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=MyISAM', $setupCountsTable)) === false) throw new RuntimeException('Synthetic engine change failed.');
    \SFM4U\POS\Runtime::install($wpdb);
    check(get_option('sfm4u_operations_schema') !== '3', 'T15 incompatible table setup fails closed without an uncaught admin exception');
    ob_start(); \SFM4U\POS\Runtime::setupNotice(); $setupNotice = ob_get_clean();
    check(str_contains($setupNotice, 'Operations setup is incomplete') && !str_contains($setupNotice, DB_NAME), 'T15 setup repair notice is actionable without exposing database details');
    switch_to_locale('bn_BD');
    load_textdomain('sfm4u-wholesale', '/source/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo', 'bn_BD');
    ob_start(); \SFM4U\POS\Runtime::setupNotice(); $setupBangla = ob_get_clean();
    check(str_contains($setupBangla, 'সেটআপ অসম্পূর্ণ'), 'T15 setup repair notice renders in Bangla');
    restore_previous_locale(); unload_textdomain('sfm4u-wholesale');
    wp_set_current_user($buyer);
    ob_start(); \SFM4U\POS\Runtime::setupNotice(); $buyerSetupNotice = ob_get_clean();
    \SFM4U\POS\Runtime::install($wpdb);
    check($buyerSetupNotice === '' && get_option('sfm4u_operations_schema') !== '3', 'customer cannot view or initialize incomplete warehouse setup');
} finally {
    wp_set_current_user(1);
    if ($wpdb->query($wpdb->prepare('ALTER TABLE %i ENGINE=InnoDB', $setupCountsTable)) === false) throw new RuntimeException('Synthetic engine restore failed.');
    \SFM4U\POS\Runtime::install($wpdb);
}
check(get_option('sfm4u_operations_schema') === '3' && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $setupCountsTable)) === $setupCountRows,
    'administrator recovers T15 schema readiness without losing stock count records');
ob_start(); \SFM4U\POS\Runtime::setupNotice(); $recoveredNotice = ob_get_clean();
check($recoveredNotice === '', 'successful T15 setup recovery clears the repair notice');
