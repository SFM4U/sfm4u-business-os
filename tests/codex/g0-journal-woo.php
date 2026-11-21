<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    exit(1);
}
require '/modules/inventory-bridge/src/RequestJournal.php';

global $wpdb;
add_filter('pre_wp_mail', '__return_true');
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$journal->install();
$product = new WC_Product_Simple();
$product->set_name('Synthetic inventory rollback pack');
$product->set_regular_price('15.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(1);
$product->save();
$id = $product->get_id();
$key = wp_generate_uuid4();
$intent = ['product_id' => $id, 'quantity' => 1];
$perform = static function (bool $forceRollback) use ($id): array {
    $product = wc_get_product($id);
    $order = wc_create_order(['created_via' => 'sfm4u-journal-spike']);
    $order->add_product($product, 1);
    wc_reserve_stock_for_order($order);
    $order->update_status('processing');
    wc_release_stock_for_order($order);
    if ((int) wc_get_product($id)->get_stock_quantity() !== 0) {
        throw new RuntimeException('Native deduction not visible within transaction.');
    }
    return [
        'response' => ['order_id' => $order->get_id()],
        'movements' => [['product_id' => $id, 'delta' => $forceRollback ? 1 : -1,
            'type' => 'sale', 'order_id' => $order->get_id()]],
    ];
};
$before = count(wc_get_orders(['limit' => -1, 'return' => 'ids']));
try {
    $journal->execute(1, 'pos', $key, $intent, fn() => $perform(true));
    throw new RuntimeException('Invalid ledger row did not roll back.');
} catch (DomainException $error) {
    if ($error->getCode() !== 422) throw $error;
}
clean_post_cache($id);
WC_Cache_Helper::invalidate_cache_group('product_' . $id);
if ((int) wc_get_product($id)->get_stock_quantity() !== 1 || count(wc_get_orders(['limit' => -1, 'return' => 'ids'])) !== $before) {
    throw new RuntimeException('Rollback leaked a Woo order or stock deduction.');
}
echo "PASS invalid ledger result rolls back new Woo order and native stock in isolated single-database run\n";

$result = $journal->execute(1, 'pos', $key, $intent, fn() => $perform(false));
$replayed = $journal->execute(1, 'pos', $key, $intent, static function () {
    throw new RuntimeException('Retry entered order creation.');
});
$ledger = $wpdb->prefix . 'sfm4u_inventory_movements';
$delta = (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(stock_delta) FROM %i WHERE product_id=%d', $ledger, $id));
if ($result !== $replayed || $delta !== -1 || !wc_get_order($result['order_id'])
    || count(wc_get_orders(['limit' => -1, 'return' => 'ids'])) !== $before + 1
    || (int) wc_get_product($id)->get_stock_quantity() !== 0) {
    throw new RuntimeException('Native order did not reconcile to one committed ledger movement.');
}
echo "PASS native Woo order, stock reduction, one ledger movement and exact response reconcile\n";
