<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') exit(1);
require '/modules/inventory-bridge/src/RequestJournal.php';
require '/modules/pos/src/ReceiptRegister.php';
require '/modules/pos/src/SyntheticSale.php';

global $wpdb;
add_filter('pre_wp_mail', '__return_true');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_price_num_decimals', 2);
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_hold_stock_minutes', 15);
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$receipts = new \SFM4U\POS\ReceiptRegister($wpdb);
$journal->install();
$receipts->install();
$sales = new \SFM4U\POS\SyntheticSale($journal, $receipts);
$merchant = random_int(10000000, 999999999);

$product = new WC_Product_Simple();
$product->set_name('Drinking water, 500 mL sealed bottles — wholesale countertop stock');
$product->set_sku('SFM-POS-' . wp_generate_uuid4());
$product->set_regular_price('30.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(5);
$product->save();
$sku = $product->get_id();
$key = wp_generate_uuid4();
$check = static function (bool $pass, string $label) {
    if (!$pass) throw new RuntimeException($label);
    fwrite(STDERR, "PASS $label\n");
};
$stock = static fn() => (int) wc_get_product($sku)->get_stock_quantity();
$count = static fn() => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE product_id=%d',
    $wpdb->prefix . 'sfm4u_inventory_movements', $sku));
$record = $sales->commit($merchant,'R1',$key,$sku,3,10000,'C1');
$check($record['number'] === 'R1-000001' && $record['sale']['total_minor'] === 9000 && $record['sale']['change_minor'] === 1000
    && $stock() === 2 && $count() === 1 && wc_get_order($record['order_id'])->get_payment_method() === '',
    'sale commits Woo order, single stock movement and exact receipt without gateway');
$check($sales->commit($merchant,'R1',$key,$sku,3,10000,'C1') === $record,
    'replay after response loss returns same receipt');
$copy = $receipts->reprint($merchant,'R1',$record['order_id']);
for ($i=0; $i<3; $i++) {
    $check($receipts->reprint($merchant,'R1',$record['order_id']) === $copy && $copy['kind'] === 'COPY'
        && $copy['receipt'] === $record && $stock() === 2 && $count() === 1,
        'COPY reprint reads the immutable snapshot without stock or sale effects');
}
try {
    $receipts->reprint($merchant,'R2',$record['order_id']);
    throw new RuntimeException('Register isolation failed.');
} catch (DomainException $e) {
    $check($e->getCode() === 404, 'other register cannot reprint this receipt');
}
try {
    $journal->execute($merchant, 'pos', wp_generate_uuid4(), ['test'=>'rollback-after-issuing'], function () use ($receipts,$merchant) {
        $draft = wc_create_order(['created_via'=>'sfm4u-pos-fixture']);
        $receipts->issue($merchant,'R1',$draft->get_id(),['test'=>'rollback']);
        throw new DomainException('Synthetic failure after sequence write.', 422);
    });
    throw new RuntimeException('Expected rollback.');
} catch (DomainException $e) {
    $check($e->getCode() === 422, 'forced rollback occurs after receipt insert');
}
$second = $sales->commit($merchant,'R1',wp_generate_uuid4(),$sku,1,3000,'C1');
$check($second['number'] === 'R1-000002' && $stock() === 1 && $count() === 2,
    'failed receipt allocation leaves no numbering gap');
$archive = $wpdb->prefix . 'sfm4u_pos_receipts';
$sequence = $wpdb->get_col($wpdb->prepare('SELECT sequence_number FROM %i WHERE merchant=%d AND register_code=%s ORDER BY sequence_number',
    $archive,$merchant,'R1'));
$check(array_map('intval',$sequence) === [1,2], 'receipt archive contains exactly the two committed serials');
echo wp_json_encode($copy, JSON_THROW_ON_ERROR) . "\n";
