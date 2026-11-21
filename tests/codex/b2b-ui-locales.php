<?php
require_once '/source/sfm4u-wholesale/src/Admin.php';
require_once '/source/sfm4u-wholesale/src/WorkEngineEvents.php';
require_once '/source/sfm4u-wholesale/src/BuyerApplication.php';
require_once '/source/sfm4u-wholesale/src/RuleCsv.php';
require_once '/modules/b2b-pro/src/CreditAccountsPage.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$screenCases = [
    'pos-settings' => [1, fn() => (new \SFM4U\POS\RegisterSettingsPage())->render(), []],
    'invoice-settings' => [1, fn() => (new \SFM4U\POS\InvoiceProfilePage())->render(), []],
    'barcode-labels' => [1, fn() => (new \SFM4U\Inventory\BarcodeLabelsPage())->render(), []],
    'inventory-report' => [1, fn() => (new \SFM4U\Inventory\InventoryReportsPage($wpdb))->render(), []],
    'register-assignments' => [1, fn() => $assignments->render(new WP_User($staff)), []],
    'cash-register' => [$staff, fn() => $registerPage->render(), []],
    'wholesale-rules' => [1, fn() => \SFM4U\Wholesale\Admin::page(), ['product_id' => (string) $product->get_id()]],
    'buyer-approval' => [1, fn() => \SFM4U\Wholesale\Admin::buyerProfile(new WP_User($buyer)), []],
    'tiers' => [1, fn() => (new \SFM4U\Wholesale\Pro\TierSettingsPage())->render(), []],
    'credit-accounts' => [$manager, fn() => (new \SFM4U\Wholesale\Pro\CreditAccountsPage($wpdb))->render(), ['buyer' => (string) $buyer]],
    'hold-review' => [$manager, fn() => (new \SFM4U\Wholesale\Pro\HoldReviewPage($quotes))->render(), []],
    'purchase-orders' => [1, fn() => (new \SFM4U\Inventory\SupplierOrderPage($purchase))->render(), ['po' => (string) $nativePo['id']]],
    'supplier-payables' => [1, fn() => (new \SFM4U\Inventory\SupplierPayablesPage($ap, $purchase))->render(), []],
    'stock-counts' => [1, fn() => (new \SFM4U\Inventory\StockCountsPage($counts))->render(), ['count' => $posted['id']]],
    'daily-close' => [1, fn() => $daily->render(), ['merchant' => (string) $merchant, 'register' => 'R1']],
    'inventory-recovery' => [1, fn() => $nativeInventory->render(), []],
    'buyer-quotes' => [$buyer, fn() => do_action('woocommerce_account_b2b-quotes_endpoint'), []],
    'buyer-lists' => [$buyer, fn() => do_action('woocommerce_account_b2b-lists_endpoint'), []],
    'buyer-credit' => [$buyer, fn() => do_action('woocommerce_account_b2b-credit_endpoint'), []],
    'buyer-application' => [$outsider, fn() => \SFM4U\Wholesale\BuyerApplication::render(), []],
    'vendor-rfqs' => [$vendor, fn() => (new \SFM4U\Wholesale\Pro\VendorDashboard($quotes))->render('rfqs'), []],
    'vendor-products' => [$vendor, fn() => (new \SFM4U\Wholesale\Pro\VendorDashboard($quotes))->render('products'), ['sfm4u_product' => (string) $product->get_id()]],
    'vendor-credit' => [$vendor, fn() => (new \SFM4U\Wholesale\Pro\VendorDashboard($quotes))->render('credit'), []],
];
update_user_meta($vendor, 'dokan_enable_selling', 'yes');
$localeMissing = [];
$localeSeen = [];
$auditTranslations = static function (string $translated, string $text, string $domain) use (&$localeMissing, &$localeSeen): string {
    if ($domain === 'sfm4u-wholesale') {
        $localeSeen[$text] = true;
        if ($translated === $text && preg_match('/[A-Za-z]{3}/', $text)) $localeMissing[$text] = true;
    }
    return $translated;
};
$screenSummaries = [];
foreach (['en_US', 'bn_BD'] as $locale) {
    switch_to_locale($locale);
    unload_textdomain('sfm4u-wholesale');
    if ($locale === 'bn_BD') {
        load_textdomain('sfm4u-wholesale', '/source/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo', $locale);
        add_filter('gettext', $auditTranslations, 999, 3);
    }
    foreach ($screenCases as $name => [$actor, $callback, $query]) {
        wp_set_current_user($actor);
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = $query;
        ob_start(); $callback(); $html = ob_get_clean();
        check(strlen($html) > 15 && ($locale !== 'bn_BD' || preg_match('/[\x{0980}-\x{09FF}]/u', $html)), "$locale rendered $name screen");
        $screenSummaries[] = ['locale' => $locale, 'screen' => $name, 'bytes' => strlen($html)];
        // Generated synthetic HTML stays in scratch, never in Git or the release ZIP.
        file_put_contents('/fixture-out/ui-' . $locale . '-' . $name . '.html', $html);
    }
    if ($locale === 'bn_BD') remove_filter('gettext', $auditTranslations, 999);
    restore_previous_locale(); unload_textdomain('sfm4u-wholesale');
}
check(!$localeMissing, 'all rendered plugin labels have Bangla translations: ' . implode(' | ', array_keys($localeMissing)));
file_put_contents('/fixture-out/ui-screens.json', wp_json_encode(['screens' => $screenSummaries, 'translated_keys' => count($localeSeen), 'missing' => array_keys($localeMissing)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
wp_set_current_user(1); $_GET = []; $_POST = [];
