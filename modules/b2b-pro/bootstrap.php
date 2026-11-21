<?php
declare(strict_types=1);

use SFM4U\Wholesale\Pro\{Dashboard, HoldReviewPage, QuickOrderCsv, RestApi, TierSettingsPage, VendorDashboard, WooCatalog, WooQuotes, WooSpendTiers};

if (!class_exists(\SFM4U\Inventory\LossAwareWpdb::class, false)) {
    require_once dirname(__DIR__) . '/inventory-bridge/src/LossAwareWpdb.php';
}
require_once dirname(__DIR__) . '/inventory-bridge/src/RequestJournal.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/ManagedOrderStock.php';

require_once dirname(__DIR__) . '/inventory-bridge/src/MovingAverage.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/PurchaseOrders.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/SupplierOrderPage.php';

require_once dirname(__DIR__) . '/inventory-bridge/src/StockValuation.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/StockCounts.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/StockCountsPage.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/SupplierPayables.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/SupplierPayablesPage.php';

require_once dirname(__DIR__) . '/pos/src/ReceiptRegister.php';
require_once dirname(__DIR__) . '/pos/src/RegisterSessions.php';
require_once dirname(__DIR__) . '/pos/src/Runtime.php';

require_once dirname(__DIR__) . '/inventory-bridge/src/DailyClose.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/InventoryReportsPage.php';
require_once dirname(__DIR__) . '/inventory-bridge/src/BarcodeLabelsPage.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'SFM4U\\Wholesale\\Pro\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/D', $name) && is_file(__DIR__ . "/src/$name.php")) {
            require_once __DIR__ . "/src/$name.php";
        }
    }
});

function sfm4u_b2b_register(\wpdb $db, bool $fixtureFinance = true): WooQuotes
{
    \SFM4U\Inventory\ManagedOrderStock::register();
    \SFM4U\POS\Runtime::register($db);
    $catalog = new WooCatalog();
    $quotes = new WooQuotes($db, $catalog);
    $quick = new QuickOrderCsv($catalog);
    $api = new RestApi($quick, $quotes);
    (new Dashboard($quotes, $quick))->register();
    add_filter('woocommerce_valid_order_statuses_for_payment', static function (array $statuses, \WC_Order $order): array {
        return $order->get_created_via() === 'sfm4u-b2b-credit' ? [] : $statuses;
    }, 10, 2);
    if (did_action('rest_api_init')) {
        $api->register();
    } else {
        add_action('rest_api_init', [$api, 'register']);
    }
    (new WooSpendTiers($db))->register();
    (new HoldReviewPage($quotes))->register();
    (new TierSettingsPage())->register();
    if ($fixtureFinance && (\SFM4U\POS\Runtime::enabled() || in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true))) {
        $valuation = new \SFM4U\Inventory\StockValuation($db);
        add_action('sfm4u_inventory_capture_sale', static function (\WC_Order $order) use ($valuation): void {
            if (get_option('sfm4u_cost_lots_ready') === 'yes') {
                $valuation->capture($order);
            }
        });
        add_action('sfm4u_inventory_capture_return', static function (\WC_Order_Item_Product $item, int $refund, int $returned,
            int $quantity, bool $resellable) use ($valuation): void {
            if (get_option('sfm4u_cost_lots_ready') === 'yes') {
                $valuation->captureReturn($item, $refund, $returned, $quantity, $resellable);
            }
        }, 10, 5);
        (new \SFM4U\Inventory\DailyClose($db, new \SFM4U\Wholesale\Pro\Settlement($db),
            new \SFM4U\Inventory\SupplierPayables($db, $valuation), new \SFM4U\POS\RegisterSessions($db)))->register();
        (new \SFM4U\Inventory\SupplierPayablesPage(
            new \SFM4U\Inventory\SupplierPayables($db, $valuation), new \SFM4U\Inventory\PurchaseOrders($db)
        ))->register();
        (new \SFM4U\Inventory\SupplierOrderPage(new \SFM4U\Inventory\PurchaseOrders($db)))->register();
        (new \SFM4U\Inventory\InventoryReportsPage($db))->register();
        (new \SFM4U\Inventory\BarcodeLabelsPage())->register();
        (new \SFM4U\Inventory\StockCountsPage(new \SFM4U\Inventory\StockCounts($db)))->register();
    }
    if (function_exists('dokan_is_seller_enabled')) {
        (new VendorDashboard($quotes))->register();
    }
    $hook = 'sfm4u_b2b_reconcile_stock_holds';
    add_action($hook, [$quotes, 'reconcileExpiredHolds']);
    $ensure = static function () use ($hook): void {
        if (!as_has_scheduled_action($hook, [], 'sfm4u-b2b')) {
            as_schedule_recurring_action(time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, $hook, [], 'sfm4u-b2b', true);
        }
    };
    add_action('action_scheduler_init', $ensure);
    add_action('action_scheduler_ensure_recurring_actions', $ensure);
    if (did_action('action_scheduler_init')) {
        $ensure();
    }
    return $quotes;
}
