<?php
/**
 * Plugin Name: SFM4U Business OS — Wholesale, Retail & POS
 * Description: SFM4U main store software: wholesale and retail prices, member cards (regular, business, premium), khata (credit ledger), product QR labels, POS and inventory for WooCommerce.
 * Version: 0.9.8-dev
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: SFM for you
 * License: GPL-2.0-or-later
 * Text Domain: sfm4u-wholesale
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/src/Rule.php';
require_once __DIR__ . '/src/Commerce.php';
require_once __DIR__ . '/src/Admin.php';
require_once __DIR__ . '/src/RuleCsv.php';
require_once __DIR__ . '/src/BuyerApplication.php';
require_once __DIR__ . '/src/B2BRuntime.php';
require_once __DIR__ . '/src/PosRoles.php';
require_once __DIR__ . '/src/WorkEngineEvents.php';
require_once __DIR__ . '/src/LowStock.php';
require_once __DIR__ . '/src/ReportCsv.php';
require_once __DIR__ . '/src/BulkOrder.php';
require_once __DIR__ . '/src/ProfitCard.php';
require_once __DIR__ . '/src/PurchaseHistory.php';
require_once __DIR__ . '/src/SellerRules.php';
require_once __DIR__ . '/src/PriceBox.php';
require_once __DIR__ . '/src/MemberPrice.php';
require_once __DIR__ . '/src/Erp/StockStore.php';
require_once __DIR__ . '/src/Erp/ErpNextClient.php';
require_once __DIR__ . '/src/Modules.php';
require_once __DIR__ . '/src/Market/VendorProfit.php';
require_once __DIR__ . '/src/Erp/ErpSync.php';
require_once __DIR__ . '/src/Erp/ErpAdmin.php';
foreach (['Courier', 'WhatsApp', 'OrderPrint', 'OpsAdmin', 'StockFeed', 'StockFeedAdmin'] as $sfm4uOps) { require_once __DIR__ . '/src/Ops/' . $sfm4uOps . '.php'; }
unset($sfm4uOps);
// Member cards. The same code also ships alone as "SFM4U Membership Card" (for account.sfm4u.com, no WooCommerce).
// If that plugin is already active here, use its copy and do not load ours twice.
if (!class_exists('SFM4U\\Wholesale\\Members\\Members')) {
    define('SFM4U_MEMBERS_FROM', 'wholesale');
    foreach (['Members', 'Barcode', 'BdMapData', 'BdMap', 'CardView', 'CardGuide', 'CardLink', 'Receipt', 'MembersAdmin', 'MembersFront', 'Rewards', 'Tiers', 'MemberLink'] as $sfm4uMembersFile) {
        require_once __DIR__ . '/src/Members/' . $sfm4uMembersFile . '.php';
    }
    unset($sfm4uMembersFile);
}

register_activation_hook(__FILE__, [\SFM4U\Wholesale\PosRoles::class, 'activate']);
register_activation_hook(__FILE__, [\SFM4U\Wholesale\B2BRuntime::class, 'install']);
add_action('admin_init', [\SFM4U\Wholesale\PosRoles::class, 'upgrade']);
register_deactivation_hook(__FILE__, [\SFM4U\Wholesale\B2BRuntime::class, 'deactivate']);
register_deactivation_hook(__FILE__, static function () { wp_clear_scheduled_hook(\SFM4U\Wholesale\Erp\ErpSync::CRON); wp_clear_scheduled_hook(\SFM4U\Wholesale\Ops\Courier::CRON); wp_clear_scheduled_hook(\SFM4U\Wholesale\Ops\StockFeed::CRON); });

add_action('init', static function () {
    load_plugin_textdomain('sfm4u-wholesale', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
add_action('plugins_loaded', static function () {
    if (class_exists('WooCommerce')) {
        // SFM4U Business OS: each system can be turned on or off (SFM4U Business OS → Systems).
        $on = [\SFM4U\Wholesale\Modules::class, 'on'];
        \SFM4U\Wholesale\Modules::register();
        \SFM4U\Wholesale\WorkEngineEvents::register();
        if ($on('wholesale')) {
            \SFM4U\Wholesale\Commerce::register();
            \SFM4U\Wholesale\Admin::register();
            \SFM4U\Wholesale\BuyerApplication::register();
            \SFM4U\Wholesale\LowStock::register();
            \SFM4U\Wholesale\BulkOrder::register();
            \SFM4U\Wholesale\ProfitCard::register();
            \SFM4U\Wholesale\PurchaseHistory::register();
            \SFM4U\Wholesale\SellerRules::register();
            \SFM4U\Wholesale\PriceBox::register();
            \SFM4U\Wholesale\MemberPrice::register();
        }
        if ($on('membership')) { \SFM4U\Wholesale\Members\Members::boot(); }
        if ($on('erp')) { \SFM4U\Wholesale\Erp\ErpSync::register(); \SFM4U\Wholesale\Erp\ErpAdmin::register(); }
        if ($on('delivery')) { \SFM4U\Wholesale\Ops\Courier::register(); \SFM4U\Wholesale\Ops\WhatsApp::register(); }
        if ($on('receipt')) { \SFM4U\Wholesale\Ops\OrderPrint::register(); }
        if ($on('delivery') || $on('receipt')) { \SFM4U\Wholesale\Ops\OpsAdmin::register(); }
        if ($on('stocklink')) { \SFM4U\Wholesale\Ops\StockFeed::register(); \SFM4U\Wholesale\Ops\StockFeedAdmin::register(); }
        \SFM4U\Wholesale\B2BRuntime::register();
        return;
    }
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('SFM4U Wholesale requires WooCommerce.', 'sfm4u-wholesale') . '</p></div>';
    });
});
