<?php
require '/wordpress/wp-load.php';
require '/source/sfm4u-wholesale/src/Commerce.php';
require '/source/sfm4u-wholesale/src/Rule.php';
require '/modules/b2b-pro/bootstrap.php';

use SFM4U\Wholesale\Commerce;
use SFM4U\Wholesale\Pro\{CreditAccount, Money, PurchaseList, QuickOrderCsv, Quote, TierPolicy, WooCatalog, WooQuotes};

function expect(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
global $wpdb;
expect(defined('WC_VERSION') && function_exists('dokan_is_user_seller'), 'WooCommerce and Dokan Lite active');
echo 'Versions: WP ' . get_bloginfo('version') . ', Woo ' . WC_VERSION . ', Dokan ' . DOKAN_PLUGIN_VERSION . ", PHP " . PHP_VERSION . "\n";
update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_price_num_decimals', 2);
$buyer = wp_insert_user(['user_login' => 'synthetic_buyer', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$vendor = wp_insert_user(['user_login' => 'synthetic_vendor', 'user_pass' => wp_generate_password(), 'role' => 'seller']);
expect(is_int($buyer) && is_int($vendor) && dokan_is_user_seller($vendor), 'real Dokan seller role');
update_user_meta($buyer, '_sfmwh_buyer_status', 'approved');
update_user_meta($vendor, 'dokan_enable_selling', 'yes');
$product = new WC_Product_Simple();
$product->set_name('Synthetic carton');
$product->set_sku('B2B-FIXTURE');
$product->set_regular_price('50.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(20);
$product->save();
wp_update_post(['ID' => $product->get_id(), 'post_author' => $vendor]);
Commerce::saveRule($product, ['minor' => 1500, 'min' => 3, 'max' => 20, 'step' => 2,
    'tiers' => [5 => 1200], 'packs' => ['carton' => 5], 'pack_minor' => ['carton' => 5400], 'buyer' => 'approved:subscriber']);

$catalog = new WooCatalog();
$quick = new QuickOrderCsv($catalog);
expect($quick->parse("B2B-FIXTURE,2\nB2B-FIXTURE,3", (string) $buyer)['total_minor'] === 6000, 'quick order uses Woo tier');
expect($quick->parse('B2B-FIXTURE,1,carton', (string) $buyer)['total_minor'] === 5400, 'explicit carton price');
update_user_meta($vendor, 'dokan_enable_selling', 'no');
expect($catalog->findForBuyer('B2B-FIXTURE', (string) $buyer) === null, 'disabled Dokan vendor is hidden');
update_user_meta($vendor, 'dokan_enable_selling', 'yes');
$list = new PurchaseList('sample', (string) $buyer, 'Monthly order');
$list->set('B2B-FIXTURE', 5);
expect($list->reorder($quick)['total_minor'] === 6000, 'purchase list uses current tier');
Commerce::saveRule($product, ['minor' => 1500, 'min' => 3, 'max' => 20, 'step' => 2,
    'tiers' => [5 => 1100], 'buyer' => 'approved:subscriber']);
expect($list->reorder($quick)['total_minor'] === 5500, 'reorder reprices saved SKU');
$policy = new TierPolicy([['tier' => 'base', 'min_spend_minor' => 0], ['tier' => 'plus', 'min_spend_minor' => 5000]]);
expect($policy->evaluate('base', [['paid_at' => 8000, 'net_minor' => 5500]], 9000)['tier'] === 'plus', 'rolling spend upgrades tier policy');
$quote = new Quote('synthetic-quote', (string) $buyer, (string) $vendor);
$quote->setRequestLines([['sku' => 'B2B-FIXTURE', 'qty' => 5]]);
$quote->apply('submit', (string) $buyer, 'buyer', 'submit', 10000);
$quote->apply('offer', (string) $vendor, 'seller', 'offer', 10001, [
    'offer_lines' => [['sku' => 'B2B-FIXTURE', 'qty' => 5, 'unit_price_minor' => 1100]], 'valid_until' => 20000,
]);
$quote->apply('accept', (string) $buyer, 'buyer', 'accept', 10002);
expect($quote->offerTotalMinor() === 5500 && $quote->state === Quote::ACCEPTED, 'RFQ offer acceptance');
$credit = new CreditAccount((string) $buyer, 5500);
expect(!$credit->check(5501, 10002)['ok'], 'credit limit denies checkout policy');
$due = $credit->charge('charge', 5500, 10002, 'synthetic-order');
expect($due === 10002 + 30 * DAY_IN_SECONDS && $credit->check(1, $due + 1)['code'] === 'overdue_invoices', 'NET30 and overdue checkout policy');
// Verify the portable quote-to-Woo handoff separately from the InnoDB transaction gate.
$playgroundOrder = new WC_Order();
$playgroundOrder->set_customer_id($buyer);
$playgroundOrder->set_currency('BDT');
$playgroundOrder->set_status('pending');
$item = new WC_Order_Item_Product();
$item->set_product($product);
$item->set_quantity(5);
$item->set_subtotal('55.00');
$item->set_total('55.00');
$playgroundOrder->add_item($item);
$playgroundOrder->calculate_totals(false);
$quote->apply('convert', 'system', 'system', 'convert', 10003, ['order_id' => (string) $playgroundOrder->get_id()]);
expect($quote->state === Quote::CONVERTED && Money::parse((string) wc_get_order((int) $quote->orderId)->get_total())->minor === 5500, 'portable quote converts to real Woo synthetic order');
$guarded = false;
try {
    (new WooQuotes($wpdb, $catalog))->convert($buyer, wp_generate_uuid4(), str_repeat('a', 16));
} catch (RuntimeException $error) {
    $guarded = $error->getCode() === 503;
}
expect($guarded, 'Playground SQLite fails closed before transactional order writes');
