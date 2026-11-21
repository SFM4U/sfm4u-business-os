<?php
use SFM4U\Inventory\PurchaseOrders;
use SFM4U\Wholesale\Commerce;

global $wpdb;
$purchaseOrders = new PurchaseOrders($wpdb);
$purchaseOrders->install();
$receiptProduct = new WC_Product_Simple();
$receiptProduct->set_name('Synthetic supplier stock');
$receiptProduct->set_manage_stock(true);
$receiptProduct->set_stock_quantity(0);
$receiptProduct->set_regular_price('20.00');
$receiptProduct->save();
$receiptProductId = $receiptProduct->get_id();
$purchaseLine = ['product' => $receiptProductId, 'quantity' => 10, 'unit_minor' => 1000];
$issueKey = wp_generate_uuid4();
try {
    wp_set_current_user($buyer);
    $purchaseOrders->issue($issueKey, 'Synthetic Supplier', [$purchaseLine]);
    check(false, 'buyer denied supplier issue');
} catch (DomainException $error) {
    check($error->getCode() === 403, 'buyer denied supplier issue');
}
wp_set_current_user(1);
$po = $purchaseOrders->issue($issueKey, 'Synthetic Supplier', [$purchaseLine]);
check($purchaseOrders->issue($issueKey, 'Synthetic Supplier', [$purchaseLine]) === $po, 'purchase issue exact retry');
$poId = $po['id'];
$initial = ['product' => $receiptProductId, 'quantity' => 6, 'landed_minor' => 600];
$receiptKey = wp_generate_uuid4();
$readStock = static fn(): int => (int) $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_stock'", $wpdb->postmeta, $receiptProductId
));
$first = $purchaseOrders->receive($receiptKey, $poId, [$initial]);
check($first['status'] === 'open' && $first['lines'][$receiptProductId]['received'] === 6 && $readStock() === 6
    && $first['receipt'][$receiptProductId]['average_micros'] === 11000000, 'partial receipt holds balance and adds landed cost');
check($purchaseOrders->receive($receiptKey, $poId, [$initial]) === $first && $readStock() === 6, 'receipt replay keeps one stock increase');
try {
    $purchaseOrders->receive($receiptKey, $poId, [array_replace($initial, ['quantity' => 1])]);
    check(false, 'receipt key conflict');
} catch (DomainException $error) {
    check($error->getCode() === 409 && $readStock() === 6, 'changed receipt payload on same key rejected');
}
try {
    $purchaseOrders->receive(wp_generate_uuid4(), $poId, [$initial]);
    check(false, 'over-receipt');
} catch (DomainException $error) {
    check($error->getCode() === 422 && $readStock() === 6, 'over-receipt blocked with unchanged stock');
}
$second = ['product' => $receiptProductId, 'quantity' => 3, 'landed_minor' => 900];
$retry = wp_generate_uuid4();
$fail = static function (): void { throw new RuntimeException('synthetic receipt rollback'); };
add_action('sfm4u_synthetic_receipt_before_commit', $fail);
try {
    $purchaseOrders->receive($retry, $poId, [$second], 'Supplier confirmed one unavailable.');
    check(false, 'rollback injected');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'synthetic receipt rollback' && $readStock() === 6
        && $purchaseOrders->find($poId)['status'] === 'open'
        && (int) wc_get_product($receiptProductId)->get_stock_quantity() === 6,
        'receipt failure rolls back stock and order status and clears the product cache');
} finally {
    remove_action('sfm4u_synthetic_receipt_before_commit', $fail);
}
$closed = $purchaseOrders->receive($retry, $poId, [$second], 'Supplier confirmed one unavailable.');
check($closed['status'] === 'closed_short' && $closed['lines'][$receiptProductId]['received'] === 9
    && $closed['lines'][$receiptProductId]['landed_minor_received'] === 1500
    && $closed['receipt'][$receiptProductId]['value_micros'] === 105000000
    && $closed['receipt'][$receiptProductId]['average_micros'] === 11666667, 'retry closes short and moving average conserves exact value');
check((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM %i WHERE product_id=%d AND movement_type=%s',
    $wpdb->prefix . 'sfm4u_inventory_movements', $receiptProductId, 'receipt'
)) === 2 && $readStock() === 9, 'two committed receipts record exactly two stock movements');
check((int) wc_get_product($receiptProductId)->get_stock_quantity() === 9
    && wc_get_product($receiptProductId)->is_in_stock(), 'WooCommerce sees committed stock as available');
$fullPo = $purchaseOrders->issue(wp_generate_uuid4(), 'Synthetic Supplier', [
    ['product' => $receiptProductId, 'quantity' => 1, 'unit_minor' => 1000],
]);
check($purchaseOrders->receive(wp_generate_uuid4(), $fullPo['id'], [
    ['product' => $receiptProductId, 'quantity' => 1, 'landed_minor' => 0],
])['status'] === 'received', 'fully filled order closes as received');

$unvalued = $purchaseOrders->issue(wp_generate_uuid4(), 'Synthetic Supplier', [
    ['product' => $product->get_id(), 'quantity' => 1, 'unit_minor' => 1000],
]);
try {
    $purchaseOrders->receive(wp_generate_uuid4(), $unvalued['id'], [
        ['product' => $product->get_id(), 'quantity' => 1, 'landed_minor' => 0],
    ]);
    check(false, 'missing opening valuation');
} catch (DomainException $error) {
    check($error->getCode() === 409 && $purchaseOrders->find($unvalued['id'])['lines'][$product->get_id()]['received'] === 0,
        'unvalued existing stock must be reconciled before receiving');
}

$supplierPage = new \SFM4U\Inventory\SupplierOrderPage($purchaseOrders);
$renderPo = static function () use ($supplierPage): string {
    ob_start();
    $supplierPage->render();
    return ob_get_clean();
};
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['po_action'=>'issue', 'po_nonce'=>'invalid', 'po_key'=>wp_generate_uuid4(),
    'supplier'=>'Synthetic UI Supplier', 'po_lines'=>"$receiptProductId,1,1000"];
check(str_contains($renderPo(), 'Refresh the page before submitting.'), 'supplier order admin rejects bad nonce');
$_POST['po_nonce'] = wp_create_nonce('sfm4u_supplier_order');
$form = $renderPo();
check(str_contains($form, 'Purchase order saved.') && preg_match('/<h2>Purchase order #(\d+)/', $form, $match) === 1,
    'supplier order issued through Woo admin form');
$_GET['po'] = $match[1];
$_POST = ['po_action'=>'receive', 'po_nonce'=>wp_create_nonce('sfm4u_supplier_order'),
    'po_key'=>wp_generate_uuid4(), 'po_lines'=>"$receiptProductId,1,100"];
check(str_contains($renderPo(), 'Synthetic UI Supplier · Received') && $readStock() === 11, 'Woo admin receives against issued supplier order');
$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

if (in_array('dokan', $args ?? [], true)) {
    global $wp;
    $otherVendor = wp_create_user("b2b-other-vendor-$tag", wp_generate_password());
    (new WP_User($otherVendor))->set_role('seller');
    (new WP_User($otherVendor))->add_cap('sfm4u_b2b_offer');
    update_user_meta($otherVendor, 'dokan_enable_selling', 'yes');
    $pendingRfq = call_api($buyer, 'submit', ['lines' => $lines], 'vendor_page_rfq_' . $tag)->get_data()['id'];
    $vendorPage = static function (string $page) use ($wp): string {
        $wp->query_vars = ['sfm4u-wholesale' => $page];
        return do_shortcode('[dokan-dashboard]');
    };
    wp_set_current_user($vendor);
    check(str_contains($vendorPage('rfqs'), $pendingRfq) && str_contains($vendorPage('rfqs'), 'Send offer'), 'Dokan shortcode shows incoming wholesale RFQ');
    check(isset(apply_filters('dokan_get_dashboard_nav', [])['sfm4u-wholesale']), 'Dokan menu includes Wholesale');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['sfm4u_vendor_submit' => '1', 'nonce' => 'invalid', 'quote' => $pendingRfq, 'key' => wp_generate_uuid4(),
        'revision' => '0', 'offer' => "$sku,5,1150", 'valid_until' => wp_date('Y-m-d\TH:i', time() + 3600)];
    check(str_contains($vendorPage('rfqs'), 'Refresh this page'), 'Dokan offer rejects invalid nonce');
    wp_set_current_user($otherVendor);
    $_POST['nonce'] = wp_create_nonce('sfm4u_vendor_rfqs');
    check(!str_contains($vendorPage('rfqs'), 'Offer sent.'), 'Dokan cannot answer another vendor quote');
    wp_set_current_user($vendor);
    $_POST['nonce'] = wp_create_nonce('sfm4u_vendor_rfqs');
    check(str_contains($vendorPage('rfqs'), 'Offer sent.'), 'Dokan vendor sends offer via dashboard');
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    wp_set_current_user($buyer);
    check(str_contains($render('b2b-quotes'), 'BDT 11.50'), 'buyer sees the Dokan dashboard offer');
    wp_set_current_user($vendor);
    $_POST = ['sfm4u_vendor_submit' => '1', 'nonce' => wp_create_nonce('sfm4u_vendor_products'),
        'product' => (string) $product->get_id(), 'minor' => '1250', 'minimum' => '5', 'maximum' => '40',
        'step' => '5', 'tiers' => '10,1100', 'packs' => 'carton,5,5000'];
    $_GET['sfm4u_product'] = (string) $product->get_id();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    check(str_contains($vendorPage('products'), 'Wholesale rules saved.'), 'Dokan vendor saves owned product price and pack rules');
    $rule = wc_get_product($product->get_id())->get_meta(Commerce::META);
    check($rule['min'] === 5 && $rule['tiers'][10] === 1100 && $rule['packs']['carton'] === 5
        && $rule['pack_minor']['carton'] === 5000 && $rule['buyer'] === 'tier:silver', 'vendor rules use actual Woo metadata and retain buyer policy');
    wp_set_current_user($otherVendor);
    $_POST['nonce'] = wp_create_nonce('sfm4u_vendor_products');
    check(str_contains($vendorPage('products'), 'You cannot edit this product.'), 'unrelated vendor cannot save product rules');
    $_POST = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    wp_set_current_user($vendor);
    $buyerLabel = get_userdata($buyer)->display_name;
    check(str_contains($vendorPage('credit'), $buyerLabel) && str_contains($vendorPage('credit'), 'NET 30'),
        'Dokan credit page lists own converted customers');
    wp_set_current_user($otherVendor);
    check(!str_contains($vendorPage('credit'), $buyerLabel), 'Dokan credit customers isolated across vendors');
    update_user_meta($otherVendor, 'dokan_enable_selling', 'no');
    check(str_contains($vendorPage('credit'), 'Wholesale vendor access is required.'), 'disabled vendor denied wholesale dashboard');
    $wp->query_vars = [];
}
