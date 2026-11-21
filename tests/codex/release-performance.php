<?php
declare(strict_types=1);
require '/wordpress/wp-load.php';
if (wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Local synthetic Playground only.');
}
require_once '/modules/b2b-pro/bootstrap.php';
global $wpdb;
$old = $wpdb;
$wpdb = new \SFM4U\Inventory\LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb->set_prefix($old->base_prefix);
$wpdb->set_blog_id(get_current_blog_id());
foreach (get_object_vars($old) as $alias => $value) {
    if (is_string($value) && preg_match('/^[a-z][a-z0-9_]*$/D', $alias)
        && str_starts_with($value, $old->prefix) && preg_match('/^[a-zA-Z0-9_]+$/D', $value)) {
        $wpdb->$alias = $value;
    }
}
add_filter('pre_wp_mail', '__return_true');
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$receipts = new \SFM4U\POS\ReceiptRegister($wpdb);
$journal->install();
$receipts->install();
$start = microtime(true);
$queries = $wpdb->num_queries;
$productIds = [];
for ($i = 1; $i <= 1000; $i++) {
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic performance ' . $i);
    $product->set_sku('PERF-' . $i);
    $product->set_regular_price('30.00');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $productIds[] = $product->save();
}
$productTime = microtime(true) - $start;
$receiptStart = microtime(true);
$merchant = 879511;
for ($i = 1; $i <= 1000; $i++) {
    $journal->execute($merchant, 'receipt-perf', sprintf('receipt_perf_%06d', $i), ['order' => $i],
        static fn() => ['response' => $receipts->issue($merchant, 'PERF', $i, ['currency' => 'BDT', 'total_minor' => 3000]), 'movements' => []]);
}
$receiptTime = microtime(true) - $receiptStart;
$lookupStart = microtime(true);
foreach ([1, 250, 500, 750, 1000] as $index) {
    if (wc_get_product_id_by_sku('PERF-' . $index) !== $productIds[$index - 1]
        || $receipts->read($merchant, 'PERF', $index)['number'] !== sprintf('PERF-%06d', $index)) {
        throw new RuntimeException('Performance dataset lookup mismatch.');
    }
}
$lookupTime = microtime(true) - $lookupStart;
$count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE merchant=%d',
    $wpdb->prefix . 'sfm4u_pos_receipts', $merchant));
if (count($productIds) !== 1000 || $count !== 1000) {
    throw new RuntimeException('Incomplete synthetic dataset.');
}
file_put_contents('/fixture-out/performance.json', wp_json_encode([
    'products' => 1000, 'receipts' => $count, 'product_write_seconds' => $productTime,
    'receipt_write_seconds' => $receiptTime, 'five_product_and_receipt_lookups_seconds' => $lookupTime,
    'queries' => $wpdb->num_queries - $queries, 'peak_memory_bytes' => memory_get_peak_usage(true),
    'php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION,
    'scope' => 'serial synthetic data creation and lookups; not 1000 full checkout transactions',
], JSON_PRETTY_PRINT));
