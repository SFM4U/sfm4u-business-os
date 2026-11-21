<?php
use SFM4U\Inventory\{PurchaseOrders, StockCounts, StockValuation};

wp_set_current_user(1);
$counts = new StockCounts($wpdb);
$counts->install();
$purchase = new PurchaseOrders($wpdb);
$valuation = new StockValuation($wpdb);
$countProduct = new WC_Product_Simple();
$countProduct->set_name('Synthetic counted stock');
$countProduct->set_regular_price('30.00');
$countProduct->set_manage_stock(true);
$countProduct->set_stock_quantity(0);
$countProduct->save();
$countProductId = $countProduct->get_id();
$countPo = $purchase->issue(wp_generate_uuid4(), 'Synthetic stock count', [
    ['product' => $countProductId, 'quantity' => 11, 'unit_minor' => 2000],
]);
$purchase->receive(wp_generate_uuid4(), $countPo['id'], [['product' => $countProductId, 'quantity' => 9, 'landed_minor' => 0]]);
wc_update_product_stock(wc_get_product($countProductId), 9, 'set');
$purchase->receive(wp_generate_uuid4(), $countPo['id'], [['product' => $countProductId, 'quantity' => 1, 'landed_minor' => 0]]);
check($valuation->totals($countProductId)['quantity'] === 10, 'receiving and counts accept WooCommerce formatted whole stock balances');
$reviewerId = wp_insert_user(['user_login' => "count-review-$tag", 'user_pass' => wp_generate_password(), 'role' => 'shop_manager']);
check(is_int($reviewerId), 'created separate stock count reviewer');

$expectCountReject = static function (callable $action, int $code, string $label): void {
    try {
        $action();
    } catch (DomainException $error) {
        check($error->getCode() === $code, $label);
        return;
    }
    check(false, $label);
};

$submitKey = wp_generate_uuid4();
$count = $counts->submit($submitKey, $countProductId, 10, 7, 'Cycle count shortage');
check($count['status'] === 'pending' && $counts->submit($submitKey, $countProductId, 10, 7, 'Cycle count shortage') === $count
    && $valuation->totals($countProductId)['stock_micros'] === 200000000, 'pending count is replayable and leaves valuation untouched');
$expectCountReject(fn() => $counts->approve(wp_generate_uuid4(), $count['id']), 403, 'counter cannot approve own count');
wp_set_current_user($outsider);
$expectCountReject(fn() => $counts->find($count['id']), 403, 'unauthorized customer cannot read a stock count');

wp_set_current_user($reviewerId);
$holdOrder = wc_create_order();
$holdOrder->add_product(wc_get_product($countProductId), 8);
$holdOrder->save();
wc_reserve_stock_for_order($holdOrder);
check((int) wc_get_held_stock_quantity(wc_get_product($countProductId)) === 8, 'fixture acquired real WooCommerce stock hold');
$approvalKey = wp_generate_uuid4();
$expectCountReject(fn() => $counts->approve($approvalKey, $count['id']), 409, 'count cannot reduce stock below active holds');
wc_release_stock_for_order($holdOrder);

$forcedFailure = static fn() => throw new RuntimeException('forced stock count rollback');
add_action('sfm4u_synthetic_stock_count_before_commit', $forcedFailure);
try {
    $counts->approve($approvalKey, $count['id']);
    check(false, 'forced stock count rollback reached approval hook');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'forced stock count rollback' && $counts->find($count['id'])['status'] === 'pending'
        && (int) wc_get_product($countProductId)->get_stock_quantity() === 10
        && $valuation->totals($countProductId)['stock_micros'] === 200000000, 'rollback restores stock, value and pending count');
} finally {
    remove_action('sfm4u_synthetic_stock_count_before_commit', $forcedFailure);
}
$approved = $counts->approve($approvalKey, $count['id']);
check($approved['approved_by'] === $reviewerId && $approved['value_delta_micros'] === -60000000
    && $valuation->totals($countProductId)['stock_micros'] === 140000000
    && (int) wc_get_product($countProductId)->get_stock_quantity() === 7
    && $counts->approve($approvalKey, $count['id']) === $approved, 'shortage approval adjusts stock and average value once');
$expectCountReject(fn() => $counts->approve(wp_generate_uuid4(), $count['id']), 409, 'new approval key cannot apply a closed count');

wp_set_current_user(1);
$surplus = $counts->submit(wp_generate_uuid4(), $countProductId, 7, 9, 'Cycle count surplus');
$stale = $counts->submit(wp_generate_uuid4(), $countProductId, 7, 6, 'Independent observed count');
wp_set_current_user($reviewerId);
$surplus = $counts->approve(wp_generate_uuid4(), $surplus['id']);
check($surplus['value_delta_micros'] === 40000000 && $valuation->totals($countProductId)['stock_micros'] === 180000000
    && (int) wc_get_product($countProductId)->get_stock_quantity() === 9, 'surplus is valued at the existing moving average');
$expectCountReject(fn() => $counts->approve(wp_generate_uuid4(), $stale['id']), 409, 'stale expected stock blocks a second count');
$movementDeltas = $wpdb->get_col($wpdb->prepare(
    "SELECT stock_delta FROM %i WHERE product_id=%d AND movement_type='adjustment' ORDER BY id",
    $wpdb->prefix . 'sfm4u_inventory_movements', $countProductId
));
check(array_map('intval', $movementDeltas) === [-3, 2], 'exactly two compensating adjustment movements were committed');

wp_set_current_user(1);
$secondProduct = new WC_Product_Simple();
$secondProduct->set_name('Synthetic second counted stock');
$secondProduct->set_manage_stock(true);
$secondProduct->set_stock_quantity(0);
$secondProduct->save();
$secondId = $secondProduct->get_id();
$secondPo = $purchase->issue(wp_generate_uuid4(), 'Synthetic count scope', [['product' => $secondId, 'quantity' => 5, 'unit_minor' => 1000]]);
$purchase->receive(wp_generate_uuid4(), $secondPo['id'], [['product' => $secondId, 'quantity' => 5, 'landed_minor' => 0]]);
$rows = [
    ['product' => $secondId, 'expected' => 5, 'counted' => 4],
    ['product' => $countProductId, 'expected' => 9, 'counted' => 8],
];
$scopeKey = wp_generate_uuid4();
$sessionCount = $counts->submitSession($scopeKey, 'full', 'Synthetic shelf A', $rows, 'Full shelf count');
check($counts->submitSession($scopeKey, 'full', 'Synthetic shelf A', array_reverse($rows), 'Full shelf count') === $sessionCount,
    'multi-SKU count canonicalizes product lock and replay order');
wp_set_current_user($reviewerId);
wc_update_product_stock($secondProduct, 1, 'increase');
$expectCountReject(fn() => $counts->approve(wp_generate_uuid4(), $sessionCount['id']), 409, 'stale second SKU aborts entire count session');
check((int) wc_get_product($countProductId)->get_stock_quantity() === 9
    && $valuation->totals($countProductId)['stock_micros'] === 180000000
    && $counts->find($sessionCount['id'])['status'] === 'pending', 'failed session restores first SKU stock and valuation');
wc_update_product_stock($secondProduct, 1, 'decrease');
$sessionApprovalKey = wp_generate_uuid4();
$sessionResult = $counts->approve($sessionApprovalKey, $sessionCount['id']);
check(array_column($sessionResult['lines'], 'delta') === [-1, -1]
    && array_column($sessionResult['lines'], 'value_delta_micros') === [-20000000, -10000000]
    && $counts->approve($sessionApprovalKey, $sessionCount['id']) === $sessionResult, 'full declared scope approves both SKU adjustments once');

wp_set_current_user(1);
$native = wc_create_order();
// Seed an out-of-band physical count mismatch in both standalone and installed fixtures.
// An installed runtime captures ordinary Woo order stock reductions in valuation.
wc_update_product_stock(wc_get_product($countProductId), 2, 'decrease');
check((int) wc_get_product($countProductId)->get_stock_quantity() === 6
    && $valuation->totals($countProductId)['quantity'] === 8, 'synthetic physical adjustment changes Woo stock without changing warehouse valuation');
$reconcileRows = [['product' => $countProductId, 'expected' => 8, 'counted' => 6]];
$reconciliation = $counts->submitSession(wp_generate_uuid4(), 'reconcile', 'Synthetic physical stock correction', $reconcileRows, 'Verified stock difference');
$duplicate = $counts->submitSession(wp_generate_uuid4(), 'reconcile', 'Synthetic physical stock correction', $reconcileRows, 'Duplicate observation');
wp_set_current_user($reviewerId);
$reconcileKey = wp_generate_uuid4();
$posted = $counts->approve($reconcileKey, $reconciliation['id']);
check($posted['lines'][0]['value_delta_micros'] === -40000000
    && $valuation->totals($countProductId)['stock_micros'] === 120000000
    && (int) wc_get_product($countProductId)->get_stock_quantity() === 6
    && $counts->approve($reconcileKey, $reconciliation['id']) === $posted,
    'reconciliation adjusts valuation and preserves the WooCommerce balance on retry');
$expectCountReject(fn() => $counts->approve(wp_generate_uuid4(), $duplicate['id']), 409, 'changed book baseline blocks duplicate reconciliation');

wp_set_current_user(1);
$countProduct = wc_get_product($countProductId);
$countProduct->update_meta_data('_sfm4u_pos_merchant_id', $merchant);
$countProduct->save();
wp_set_current_user($staff);
$workflowDrawer = $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 0)['session_id'];
$workflowKey = wp_generate_uuid4();
$workflowSale = $cashPos->sale($merchant, 'R1', $workflowKey, $countProductId, 1, 3000, $workflowDrawer);
check($workflowSale['sale']['total_minor'] === 3000 && $valuation->totals($countProductId)['cogs_micros'] === 20000000
    && $valuation->totals($countProductId)['stock_micros'] === 100000000
    && (int) wc_get_product($countProductId)->get_stock_quantity() === 5,
    'POS sale after warehouse reconciliation keeps Woo stock, COGS and receipt lots in agreement');
check($cashPos->sale($merchant, 'R1', $workflowKey, $countProductId, 1, 3000, $workflowDrawer) === $workflowSale,
    'reconciled stock POS sale replay does not duplicate movements');
$workflowZ = $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $workflowDrawer, 3000);
wp_set_current_user(1);
$workflowDay = $daily->report($today, $merchant, 'R1');
check($workflowZ['expected_cash_minor'] === 3000 && $workflowDay['session_ledger_mismatches'] === 0
    && $workflowDay['inventory_adjusted_units'] === -5 && $workflowDay['inventory_value_adjustment_micros'] === -90000000,
    'Daily Close reconciles the POS drawer and reports warehouse adjustments separately');

$guardedSale = wc_get_order($workflowSale['order_id']);
$guardedLine = array_values($guardedSale->get_items())[0];
wc_increase_stock_levels($guardedSale);
wc_restock_refunded_items($guardedSale, [$guardedLine->get_id() => ['qty' => 1]]);
check((int) wc_get_product($countProductId)->get_stock_quantity() === 5
    && $valuation->totals($countProductId)['quantity'] === 5,
    'managed POS order blocks native cancellation and refund restocks outside the journal');

check(__('Wholesale', 'sfm4u-wholesale') === 'Wholesale', 'default English wholesale label is preserved');
switch_to_locale('bn_BD');
$catalogFile = '/source/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo';
check(load_textdomain('sfm4u-wholesale', $catalogFile, 'bn_BD')
    && __('Wholesale', 'sfm4u-wholesale') === 'পাইকারি'
    && __('Supplier Payables', 'sfm4u-wholesale') === 'সরবরাহকারীর পাওনা', 'Bangla vendor and warehouse labels load via WordPress gettext');
restore_previous_locale();
unload_textdomain('sfm4u-wholesale');

check(!apply_filters('woocommerce_can_reduce_order_stock', true, $guardedSale)
    && apply_filters('woocommerce_can_reduce_order_stock', true, $native), 'ordinary Woo status handlers cannot directly reduce managed-order stock');

if (!defined('SFM4U_OPERATIONS_ENABLED')) define('SFM4U_OPERATIONS_ENABLED', true);
\SFM4U\POS\Runtime::install($wpdb);
\SFM4U\POS\Runtime::register($wpdb);
$posRequest = static function (int $actor, array $payload, string $key, bool $nonce = true): WP_REST_Response {
    wp_set_current_user($actor);
    $request = new WP_REST_Request('POST', '/sfm4u-pos/v1/register');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('Idempotency-Key', $key);
    if ($nonce) $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_body(wp_json_encode($payload));
    return rest_do_request($request);
};
$posScope = ['merchant' => $merchant, 'register' => 'R1'];
check($posRequest(0, $posScope + ['action' => 'drawer'], wp_generate_uuid4())->get_status() === 403
    && $posRequest($staff, $posScope + ['action' => 'drawer'], wp_generate_uuid4(), false)->get_status() === 403,
    'installed POS route requires cashier identity and nonce');
$open = $posRequest($staff, $posScope + ['action' => 'drawer', 'drawer_action' => 'open', 'session' => 0, 'amount' => 0], wp_generate_uuid4());
check($open->get_status() === 200, 'authenticated POS route opens assigned drawer');
$sessionId = $open->get_data()['session_id'];
$apiSaleKey = wp_generate_uuid4();
$apiSalePayload = $posScope + ['action' => 'sale', 'session' => $sessionId, 'product' => $countProductId, 'quantity' => 1, 'tender' => 3000];
$apiSale = $posRequest($staff, $apiSalePayload, $apiSaleKey);
check($apiSale->get_status() === 200 && $posRequest($staff, $apiSalePayload, $apiSaleKey)->get_data() === $apiSale->get_data(),
    'authenticated POS route records and replays assigned stock sale');
$apiClose = $posRequest($staff, $posScope + ['action' => 'drawer', 'drawer_action' => 'close', 'session' => $sessionId, 'amount' => 3000], wp_generate_uuid4());
check($apiClose->get_status() === 200 && $apiClose->get_data()['expected_cash_minor'] === 3000,
    'authenticated POS route closes the drawer with reconciled cash');
