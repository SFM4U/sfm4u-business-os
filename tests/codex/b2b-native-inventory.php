<?php
use SFM4U\Inventory\NativeOrderInventory;

wp_set_current_user(1);
$nativeBefore = $daily->report($today, $merchant, 'R1');
$nativeProduct = new WC_Product_Simple();
$nativeProduct->set_name('Synthetic native checkout stock');
$nativeProduct->set_regular_price('30.00');
$nativeProduct->set_manage_stock(true);
$nativeProduct->set_stock_quantity(0);
$nativeProduct->save();
$nativePid = $nativeProduct->get_id();
$nativePo = $purchase->issue(wp_generate_uuid4(), 'Synthetic native supplier', [['product' => $nativePid, 'quantity' => 10, 'unit_minor' => 2000]]);
$purchase->receive(wp_generate_uuid4(), $nativePo['id'], [['product' => $nativePid, 'quantity' => 10, 'landed_minor' => 0]]);
$nativeOrder = wc_create_order(['created_via' => 'checkout']);
$nativeOrder->set_currency('BDT');
$nativeLineId = $nativeOrder->add_product(wc_get_product($nativePid), 4);
$nativeOrder->calculate_totals(false);
$nativeOrder->update_status('processing');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 6 && $valuation->totals($nativePid)['stock_micros'] === 120000000,
    'native checkout reduces stock and moving-average value in one journal event');
wc_reduce_stock_levels($nativeOrder);
check((int) wc_get_product($nativePid)->get_stock_quantity() === 6, 'native status retries never reduce stock twice');
$nativeRefund = wc_create_refund(['order_id' => $nativeOrder->get_id(), 'amount' => '30.00', 'restock_items' => true,
    'refund_payment' => false, 'line_items' => [$nativeLineId => ['qty' => 1, 'refund_total' => '30.00', 'refund_tax' => []]]]);
check($nativeRefund instanceof WC_Order_Refund && (int) wc_get_product($nativePid)->get_stock_quantity() === 7
    && $valuation->totals($nativePid)['stock_micros'] === 140000000, 'native partial refund restores original cost and one stock unit');
$nativeDamaged = wc_create_refund(['order_id' => $nativeOrder->get_id(), 'amount' => '30.00', 'restock_items' => false,
    'refund_payment' => false, 'line_items' => [$nativeLineId => ['qty' => 1, 'refund_total' => '30.00', 'refund_tax' => []]]]);
check($nativeDamaged instanceof WC_Order_Refund && (int) wc_get_product($nativePid)->get_stock_quantity() === 7
    && $valuation->totals($nativePid)['stock_micros'] === 140000000, 'native damaged refund records a writeoff without restock');
$nativeOrder = wc_get_order($nativeOrder->get_id());
$nativeOrder->update_status('cancelled');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9 && $valuation->totals($nativePid)['stock_micros'] === 180000000,
    'native cancellation restores only remaining unreturned quantity at original cost');
wc_increase_stock_levels($nativeOrder);
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9, 'native cancellation replay has no duplicate restoration');

$nativeRetry = wc_create_order(['created_via' => 'store-api']);
$nativeRetry->set_currency('BDT');
$nativeRetry->add_product(wc_get_product($nativePid), 1);
$nativeRetry->calculate_totals(false);
$failNative = static function (string $action): void { if ($action === 'sale') throw new RuntimeException('synthetic native rollback'); };
add_action('sfm4u_native_inventory_before_commit', $failNative);
$nativeRetry->update_status('processing');
remove_action('sfm4u_native_inventory_before_commit', $failNative);
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9 && $valuation->totals($nativePid)['stock_micros'] === 180000000,
    'failed native stock event rolls back quantity and cost');
$pendingKeys = $wpdb->get_col($wpdb->prepare('SELECT event_key FROM %i', $wpdb->prefix . 'sfm4u_native_inventory_pending'));
check(count($pendingKeys) === 1, 'native inventory failure persists one review and retry record');
$nativeInventory = new NativeOrderInventory($wpdb);
$nativeInventory->retry($pendingKeys[0]);
$nativeInventory->retry($pendingKeys[0]);
check((int) wc_get_product($nativePid)->get_stock_quantity() === 8 && $valuation->totals($nativePid)['stock_micros'] === 160000000,
    'native inventory recovery posts once with the original request key');

$nativeAfter = $daily->report($today, $merchant, 'R1');
check($nativeAfter['sales_minor'] - $nativeBefore['sales_minor'] === 15000
    && $nativeAfter['returns_minor'] - $nativeBefore['returns_minor'] === 12000
    && $nativeAfter['sale_cogs_micros'] - $nativeBefore['sale_cogs_micros'] === 100000000
    && $nativeAfter['reversed_cogs_micros'] - $nativeBefore['reversed_cogs_micros'] === 80000000
    && $nativeAfter['writeoff_micros'] - $nativeBefore['writeoff_micros'] === 20000000
    && $nativeAfter['cash_sales_minor'] === $nativeBefore['cash_sales_minor'],
    'Daily Close includes native net sales, cancellations, original costs and writeoffs without changing drawer cash');
$failRefund = static function (string $action): void { if ($action === 'refund') throw new RuntimeException('synthetic refund rollback'); };
add_action('sfm4u_native_inventory_before_commit', $failRefund);
$nativeRetryLine = array_key_first($nativeRetry->get_items());
$retryRefund = wc_create_refund(['order_id' => $nativeRetry->get_id(), 'amount' => '30.00', 'restock_items' => true,
    'refund_payment' => false, 'line_items' => [$nativeRetryLine => ['qty' => 1, 'refund_total' => '30.00', 'refund_tax' => []]]]);
remove_action('sfm4u_native_inventory_before_commit', $failRefund);
check($retryRefund instanceof WC_Order_Refund && (int) wc_get_product($nativePid)->get_stock_quantity() === 8
    && $valuation->totals($nativePid)['stock_micros'] === 160000000,
    'saved native refund retains one recovery event after atomic inventory rollback');
ob_start(); $nativeInventory->render(); $reviewHtml = ob_get_clean();
check(str_contains($reviewHtml, '_wpnonce') && str_contains($reviewHtml, 'event_key'), 'manager inventory review offers nonce-protected retry');
$nativeInventory->sweep(); $nativeInventory->sweep();
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9 && $valuation->totals($nativePid)['stock_micros'] === 180000000
    && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'sfm4u_native_inventory_pending')) === 0,
    'native refund sweep recovers exact original cost and stock once');
wp_set_current_user($buyer);
ob_start(); $nativeInventory->render(); $deniedReview = ob_get_clean();
check($deniedReview === '', 'buyer cannot view or retry native inventory recovery');
wp_set_current_user(1);
