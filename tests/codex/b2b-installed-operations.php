<?php
declare(strict_types=1);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') throw new LogicException('Local synthetic Playground only.');
function op_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    file_put_contents('/fixture-out/operations-workflow.txt', 'PASS ' . $label . "\n", FILE_APPEND);
}
global $wpdb;
wp_set_current_user(1);
$entry = 'sfm4u-wholesale/sfm4u-wholesale.php';
$phase = getenv('SFM4U_OPERATIONS_PHASE');
if ($phase === 'reactivate') {
    op_check(!is_plugin_active($entry), 'operations deactivation survives next request');
    op_check(activate_plugin($entry) === null, 'exact candidate reactivates without error');
    return;
}
if ($phase === 'resumed') {
    op_check(is_plugin_active($entry) && get_option('sfm4u_operations_schema') === '3', 'reactivation preserves operations schema');
    $data = get_option('sfm4u_zip_operations');
    op_check((int) wc_get_product($data['product'])->get_stock_quantity() === 3, 'reactivation preserves reconciled physical stock');
    op_check((new \SFM4U\Inventory\StockValuation($wpdb))->totals($data['product'])['stock_micros'] === 60000000, 'reactivation preserves warehouse value');
    return;
}
\SFM4U\Wholesale\B2BRuntime::upgrade();
if ($phase === 'init') {
    op_check(get_option('sfm4u_operations_schema') === '3', 'exact ZIP installs operations schema 3');
    op_check((bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $wpdb->prefix . 'sfm4u_native_inventory_pending', 'sequence')), 'exact ZIP creates ordered native recovery queue');
    return;
}
op_check($wpdb instanceof \SFM4U\Inventory\LossAwareWpdb, 'installed operations use shared transaction adapter');
op_check(str_starts_with((new ReflectionClass(\SFM4U\Inventory\NativeOrderInventory::class))->getFileName(), WP_PLUGIN_DIR . '/sfm4u-wholesale/'), 'native workflow loads only installed ZIP bytes');
update_option('woocommerce_calc_taxes', 'no');
$purchase = new \SFM4U\Inventory\PurchaseOrders($wpdb);
$value = new \SFM4U\Inventory\StockValuation($wpdb);
$product = new WC_Product_Simple(); $product->set_name('Synthetic installed operations'); $product->set_status('publish');
$product->set_regular_price('30.00'); $product->set_manage_stock(true); $product->set_stock_quantity(0); $product->save();
$pid = $product->get_id();
$po = $purchase->issue(wp_generate_uuid4(), 'Synthetic ZIP supplier', [['product' => $pid, 'quantity' => 4, 'unit_minor' => 2000]]);
$purchase->receive(wp_generate_uuid4(), $po['id'], [['product' => $pid, 'quantity' => 4, 'landed_minor' => 0]]);
op_check($value->totals($pid)['stock_micros'] === 80000000, 'installed ZIP receives and values four units');
$order = wc_create_order(['created_via' => 'checkout']); $order->set_currency('BDT');
$line = $order->add_product(wc_get_product($pid), 2); $order->calculate_totals(false); $order->update_status('processing');
op_check((int) wc_get_product($pid)->get_stock_quantity() === 2 && $value->totals($pid)['stock_micros'] === 40000000, 'installed native checkout captures exact cost and stock');
$refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '30.00', 'restock_items' => true, 'refund_payment' => false,
    'line_items' => [$line => ['qty' => 1, 'refund_total' => '30.00', 'refund_tax' => []]]]);
op_check($refund instanceof WC_Order_Refund && (int) wc_get_product($pid)->get_stock_quantity() === 3, 'installed native partial refund restores one unit');
wc_get_order($order->get_id())->update_status('cancelled');
op_check((int) wc_get_product($pid)->get_stock_quantity() === 4 && $value->totals($pid)['stock_micros'] === 80000000, 'installed native cancellation restores only remaining cost and quantity');
$cashier = wp_create_user('zip-cashier-' . wp_generate_uuid4(), wp_generate_password());
$_POST = ['sfm4u_pos_assignments' => '654321,ZIP,C1', 'sfm4u_pos_credit' => '1', 'sfm4u_pos_assign_nonce' => wp_create_nonce('sfm4u_pos_assign_' . $cashier)];
(new \SFM4U\POS\RegisterAssignments())->save($cashier); $_POST = [];
$product = wc_get_product($pid); $product->update_meta_data('_sfm4u_pos_merchant_id', 654321); $product->save();
$call = static function (array $data, ?string $key = null) use ($cashier): WP_REST_Response {
    wp_set_current_user($cashier); $req = new WP_REST_Request('POST', '/sfm4u-pos/v1/register');
    $req->set_header('Content-Type', 'application/json'); $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $req->set_header('Idempotency-Key', $key ?? wp_generate_uuid4());
    $req->set_body(wp_json_encode($data + ['merchant' => 654321, 'register' => 'ZIP'])); return rest_do_request($req);
};
$open = $call(['action' => 'drawer', 'drawer_action' => 'open', 'session' => 0, 'amount' => 0]);
op_check($open->get_status() === 200, 'installed assigned cashier opens drawer');
$sid = $open->get_data()['session_id']; $key = wp_generate_uuid4();
$intent = ['action' => 'sale', 'session' => $sid, 'product' => $pid, 'quantity' => 1, 'tender' => 3000];
$sale = $call($intent, $key);
op_check($sale->get_status() === 200 && $call($intent, $key)->get_data() === $sale->get_data(), 'installed POS cash sale replays same receipt');
$credit = $call(['action' => 'credit', 'session' => $sid, 'order' => $sale->get_data()['order_id'], 'quantity' => 1, 'resellable' => true]);
op_check($credit->get_status() === 200 && (int) wc_get_product($pid)->get_stock_quantity() === 4, 'installed manager credit restores sale stock once');
$copy = $call(['action' => 'reprint', 'order' => $sale->get_data()['order_id']]);
op_check($copy->get_status() === 200 && $copy->get_data()['kind'] === 'COPY' && $copy->get_data()['receipt'] === $sale->get_data(), 'installed receipt COPY preserves original snapshot');
$closed = $call(['action' => 'drawer', 'drawer_action' => 'close', 'session' => $sid, 'amount' => 0]);
op_check($closed->get_status() === 200 && $closed->get_data()['expected_cash_minor'] === 0, 'installed cash drawer closes after refund with zero variance');
wp_set_current_user(1);
$rapid = wc_create_order(['created_via' => 'checkout']); $rapid->set_currency('BDT');
$rapid->add_product(wc_get_product($pid), 1); $rapid->calculate_totals(false); $rapid->update_status('processing');
$failCancel = static function (string $action): void { if ($action === 'cancel') throw new RuntimeException('Synthetic installed cancel rollback'); };
add_action('sfm4u_native_inventory_before_commit', $failCancel);
wc_get_order($rapid->get_id())->update_status('cancelled');
remove_action('sfm4u_native_inventory_before_commit', $failCancel);
wc_get_order($rapid->get_id())->update_status('processing');
(new \SFM4U\Inventory\NativeOrderInventory($wpdb))->sweep();
op_check((int) wc_get_product($pid)->get_stock_quantity() === 3 && $value->totals($pid)['stock_micros'] === 60000000,
    'installed queued cancellation and reopening retains processing stock deduction');
wc_get_order($rapid->get_id())->update_status('cancelled');
op_check((int) wc_get_product($pid)->get_stock_quantity() === 4, 'installed recovered order cancellation restores only one unit');
$counts = new \SFM4U\Inventory\StockCounts($wpdb);
$count = $counts->submit(wp_generate_uuid4(), $pid, 4, 3, 'Synthetic installed count');
$reviewer = wp_create_user('zip-count-reviewer-' . wp_generate_uuid4(), wp_generate_password()); (new WP_User($reviewer))->add_cap('manage_woocommerce');
wp_set_current_user($reviewer); $approved = $counts->approve(wp_generate_uuid4(), $count['id']);
op_check($approved['status'] === 'approved' && $value->totals($pid)['stock_micros'] === 60000000, 'installed two-person count reconciles warehouse value');
wp_set_current_user(1);
$daily = new \SFM4U\Inventory\DailyClose($wpdb, new \SFM4U\Wholesale\Pro\Settlement($wpdb),
    new \SFM4U\Inventory\SupplierPayables($wpdb, $value), new \SFM4U\POS\RegisterSessions($wpdb));
$report = $daily->report(gmdate('Y-m-d'), 654321, 'ZIP');
op_check($report['session_ledger_mismatches'] === 0 && $report['inventory_value_adjustment_micros'] === -20000000, 'installed Daily Close reconciles drawer and warehouse adjustment');
require '/tests/b2b-installed-release-additions.php';
update_option('sfm4u_zip_operations', ['product' => $pid]);
$active = as_has_scheduled_action('sfm4u_native_inventory_sweep', [], 'sfm4u-inventory');
op_check((bool) $active, 'installed native recovery sweep is scheduled');
deactivate_plugins($entry);
op_check(!as_has_scheduled_action('sfm4u_native_inventory_sweep', [], 'sfm4u-inventory'), 'deactivation removes native recovery schedule');
op_check($value->totals($pid)['stock_micros'] === 60000000, 'deactivation retains stock and value records');
