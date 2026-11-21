<?php
use SFM4U\Inventory\{PurchaseOrders, RequestJournal, StockValuation, SupplierPayables};
use SFM4U\POS\{AuthorizedRegister, ReceiptRegister, SyntheticSale};
use SFM4U\Wholesale\Commerce;

foreach (['ReceiptRegister', 'SyntheticSale', 'AuthorizedRegister'] as $module) {
    require_once '/modules/pos/src/' . $module . '.php';
}

global $wpdb;
wp_set_current_user(1);
$posJournal = new RequestJournal($wpdb);
$register = new ReceiptRegister($wpdb);
$register->install();
$outsideTransaction = false;
try {
    $register->issue(1, 'PROBE', 1, []);
} catch (RuntimeException) {
    $outsideTransaction = true;
}
check($outsideTransaction && !$wpdb->get_var($wpdb->prepare(
    "SELECT 1 FROM %i WHERE merchant=1 AND register_code='PROBE'",
    $wpdb->prefix . 'sfm4u_pos_receipt_counters'
)), 'POS receipt issuance outside a transaction leaves no counter');
$pos = new AuthorizedRegister($posJournal, $register, new SyntheticSale($posJournal, $register));
$purchaseOrders = new PurchaseOrders($wpdb);
$valuation = new StockValuation($wpdb);
$ap = new SupplierPayables($wpdb, $valuation);
$merchant = random_int(1000000, 99999999);
$supplier = 'Synthetic AP ' . $tag;
$sku = 'FIN-' . strtoupper($tag);
$financeProduct = new WC_Product_Simple();
$financeProduct->set_name('Synthetic financial scenario');
$financeProduct->set_sku($sku);
$financeProduct->set_manage_stock(true);
$financeProduct->set_stock_quantity(0);
$financeProduct->set_regular_price('20.00');
$financeProduct->update_meta_data('_sfm4u_pos_merchant_id', $merchant);
$financeProduct->save();
$pid = $financeProduct->get_id();
wp_update_post(['ID' => $pid, 'post_author' => $vendor]);
Commerce::saveRule($financeProduct, ['minor' => 1500, 'buyer' => 'approved:subscriber']);
$po = $purchaseOrders->issue(wp_generate_uuid4(), $supplier, [['product' => $pid, 'quantity' => 12, 'unit_minor' => 1000]]);
$receipt = $purchaseOrders->receive(wp_generate_uuid4(), $po['id'], [['product' => $pid, 'quantity' => 10, 'landed_minor' => 0]]);
check($receipt['status'] === 'open' && $valuation->totals($pid)['stock_micros'] === 100000000, 'finance PO receives 10 of 12 at BDT 100 inventory');

$cashier = wp_create_user('finance-pos-' . $tag, wp_generate_password());
(new WP_User($cashier))->add_cap('sfm4u_pos_cashier');
update_user_meta($cashier, '_sfm4u_pos_registers', [$merchant => ['R1' => 'C1']]);
wp_set_current_user($cashier);
$saleKey = wp_generate_uuid4();
$posReceipt = $pos->sale($merchant, 'R1', $saleKey, $pid, 2, 4000);
check($posReceipt['sale']['total_minor'] === 4000 && $valuation->totals($pid)['cogs_micros'] === 20000000, 'authorized POS sale records BDT 40 revenue and BDT 20 COGS');
check($pos->sale($merchant, 'R1', $saleKey, $pid, 2, 4000) === $posReceipt, 'POS retry preserves one cost snapshot');

$financeBuyer = wp_create_user('finance-buyer-' . $tag, wp_generate_password());
(new WP_User($financeBuyer))->add_cap('sfm4u_b2b_buy');
update_user_meta($financeBuyer, '_sfmwh_buyer_status', 'approved');
$wpdb->insert($wpdb->prefix . 'sfm4u_b2b_accounts', ['buyer'=>$financeBuyer, 'credit_limit'=>20000,
    'net_days'=>30, 'grace_days'=>0, 'currency'=>'BDT']);
$financeLine = ['sku'=>$sku, 'qty'=>3];
$rfq = call_api($financeBuyer, 'submit', ['lines'=>[$financeLine]], 'finance_submit_' . $tag)->get_data();
$id = ['id'=>$rfq['id']];
check(call_api($vendor, 'offer', $id + ['lines'=>[$financeLine + ['unit_price_minor'=>1500]], 'valid_until'=>time()+3600],
    'finance_offer_' . $tag)->get_data()['state'] === 'offered', 'finance vendor issues RFQ offer');
check(call_api($financeBuyer, 'accept', $id, 'finance_accept_' . $tag)->get_data()['state'] === 'accepted', 'finance buyer accepts NET30 offer');
$order = call_api($financeBuyer, 'convert', $id, 'finance_convert_' . $tag)->get_data();
check($order['amount_minor'] === 4500 && $order['due_at'] >= time() + 30 * DAY_IN_SECONDS - 30
    && $valuation->totals($pid)['quantity'] === 8 && $quotes->statement($financeBuyer)['balance_minor'] === 4500,
    'NET30 charge records BDT 45 AR and reserves without premature COGS');
$wholesaleId = (int) $order['order_id'];
$fulfill = ['buyer'=>$financeBuyer, 'order'=>$wholesaleId];
check(error_code(call_api($financeBuyer, 'fulfill', $fulfill, 'finance_fulfill_' . $tag), 'forbidden'), 'buyer cannot fulfill credit order');
$delivery = call_api($manager, 'fulfill', $fulfill, 'finance_fulfill_' . $tag);
check($delivery->get_status() === 200 && $delivery->get_data()['status'] === 'processing'
    && $valuation->totals($pid)['cogs_micros'] === 50000000
    && (int) wc_get_product($pid)->get_stock_quantity() === 5, 'NET30 fulfillment records BDT 30 COGS and reduces stock once');
check(call_api($manager, 'fulfill', $fulfill, 'finance_fulfill_' . $tag)->get_data() === $delivery->get_data()
    && $valuation->totals($pid)['quantity'] === 5, 'NET30 fulfillment retries without duplicate stock or COGS');

wp_set_current_user(1);
$costKey = wp_generate_uuid4();
try {
    $purchaseOrders->postLateCost(wp_generate_uuid4(), $po['id'], $receipt['receipt_id'], $pid, -2000);
    check(false, 'negative landed adjustment');
} catch (DomainException $error) {
    check($valuation->totals($pid)['stock_micros'] === 50000000, 'negative late landed cost cannot reduce stock value');
}
$cost = $purchaseOrders->postLateCost($costKey, $po['id'], $receipt['receipt_id'], $pid, 2000);
check($cost['stock_micros'] === 10000000 && $cost['cogs_adjustment_micros'] === 10000000
    && $cost['value_micros'] === 60000000, 'late BDT 20 split between remaining stock and sold units');
check($purchaseOrders->postLateCost($costKey, $po['id'], $receipt['receipt_id'], $pid, 2000) === $cost, 'late cost retry is idempotent');
try {
    $purchaseOrders->postLateCost(wp_generate_uuid4(), $po['id'], $receipt['receipt_id'], $pid, 2000);
    check(false, 'duplicate late posting');
} catch (DomainException $error) {
    check($valuation->totals($pid)['cogs_adjustment_micros'] === 10000000, 'receipt line cannot be charged twice');
}

$today = gmdate('Y-m-d');
$due = gmdate('Y-m-d', time() + 30 * DAY_IN_SECONDS);
$billKey = wp_generate_uuid4();
$billRef = 'BILL-' . $tag;
$bill = $ap->billReceipt($billKey, $po['id'], $receipt['receipt_id'], $billRef, $today, $due);
check($bill['open_minor'] === 12000 && $bill['status'] === 'open', 'receipt-based supplier bill records BDT 120 AP');
check($ap->billReceipt($billKey, $po['id'], $receipt['receipt_id'], $billRef, $today, $due) === $bill,
    'supplier bill retry returns original bill');
try {
    $ap->billReceipt(wp_generate_uuid4(), $po['id'], $receipt['receipt_id'], 'DUP-' . $tag, $today, $due);
    check(false, 'duplicate billing');
} catch (DomainException $error) {
    check(true, 'same receipt cannot be billed twice');
}
$customerPayment = call_api($manager, 'settle', ['buyer'=>$financeBuyer, 'amount_minor'=>4500,
    'reference'=>'CUSTOMER-' . $tag], 'finance_ar_payment_' . $tag);
check($customerPayment->get_status() === 200 && $quotes->statement($financeBuyer)['balance_minor'] === 0
    && $customerPayment->get_data()['allocations'][0]['order_id'] === $wholesaleId, 'customer payment applies BDT 45 and clears AR');

wp_set_current_user(1);
$payKey = wp_generate_uuid4();
$paid = $ap->recordPayment($payKey, $bill['bill_id'], 'SUP-PART-' . $tag, 5000, $today);
check($paid['status'] === 'partially_paid' && $paid['open_minor'] === 7000, 'supplier partial payment leaves BDT 70 AP');
check($ap->recordPayment($payKey, $bill['bill_id'], 'SUP-PART-' . $tag, 5000, $today) === $paid, 'supplier payment retry is safe');
try {
    $ap->recordPayment(wp_generate_uuid4(), $bill['bill_id'], 'EXCESS-' . $tag, 7001, $today);
    check(false, 'supplier overpayment');
} catch (DomainException $error) {
    check(true, 'supplier overpayment is blocked');
}
$finalPayment = $ap->recordPayment(wp_generate_uuid4(), $bill['bill_id'], 'SUP-FINAL-' . $tag, 7000, $today);
check($finalPayment['status'] === 'paid' && $finalPayment['open_minor'] === 0
    && !in_array($bill['bill_id'], array_map('intval', array_column($ap->aging($today)['bills'], 'id')), true), 'supplier bill fully paid and removed from open AP');
$totals = $valuation->totals($pid);
check($totals === ['quantity'=>5, 'stock_micros'=>60000000, 'cogs_micros'=>50000000, 'cogs_adjustment_micros'=>10000000]
    && $totals['stock_micros'] + $totals['cogs_micros'] + $totals['cogs_adjustment_micros'] === $bill['open_minor'] * 10000
    && $quotes->statement($financeBuyer)['balance_minor'] === 0 && $finalPayment['open_minor'] === 0,
    'E2E reconciled stock BDT 60 plus COGS BDT 60 equals supplier cost BDT 120; AR and AP zero');

$expected = [0=>'0-30', 30=>'0-30', 31=>'31-60', 60=>'31-60', 61=>'61-90', 90=>'61-90', 91=>'90+'];
$agingIds = [];
$issue = gmdate('Y-m-d', time() - 100 * DAY_IN_SECONDS);
foreach ($expected as $days => $bucket) {
    $agingBill = $ap->bill(wp_generate_uuid4(), $supplier, "AGE-$days-$tag", 100, $issue,
        gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS));
    $agingIds[$agingBill['bill_id']] = $bucket;
}
$actual = [];
foreach ($ap->aging($today)['bills'] as $row) {
    if (isset($agingIds[(int) $row['id']])) {
        $actual[(int) $row['id']] = $row['bucket'];
    }
}
check(count($actual) === count($agingIds) && $actual == $agingIds, 'AP ageing boundaries 0/30/31/60/61/90/91 days');

$historical = $ap->aging(gmdate('Y-m-d', time() - DAY_IN_SECONDS));
check(!in_array($bill['bill_id'], array_map('intval', array_column($historical['bills'], 'id')), true),
    'AP as-of excludes bills not issued by the report date');
$pastPayment = $ap->recordPayment(wp_generate_uuid4(), array_key_first($agingIds), 'AGE-PAID-' . $tag, 50, $today);
$previousBalance = array_column($historical['bills'], 'open_minor', 'id')[array_key_first($agingIds)] ?? 0;
check($previousBalance === 100 && $pastPayment['open_minor'] === 50, 'AP as-of excludes later payments');

$extraReceipt = $purchaseOrders->receive(wp_generate_uuid4(), $po['id'], [['product'=>$pid, 'quantity'=>1, 'landed_minor'=>0]]);
$goodsOnlyBill = $ap->billReceipt(wp_generate_uuid4(), $po['id'], $extraReceipt['receipt_id'], 'GOODS-' . $tag, $today, $due);
wp_set_current_user($cashier);
$pos->sale($merchant, 'R1', wp_generate_uuid4(), $pid, 6, 12000);
wp_set_current_user(1);
$allSold = $purchaseOrders->postLateCost(wp_generate_uuid4(), $po['id'], $extraReceipt['receipt_id'], $pid, 300);
check($allSold['quantity'] === 0 && $allSold['value_micros'] === 0 && $allSold['stock_micros'] === 0
    && $allSold['cogs_adjustment_micros'] === 3000000, 'fully sold receipt late cost goes entirely to COGS and stock remains zero');
$freight = $ap->bill(wp_generate_uuid4(), $supplier, 'FREIGHT-' . $tag, 300, $today, $due);
$openById = array_column($ap->aging($today)['bills'], 'open_minor', 'id');
check($openById[$goodsOnlyBill['bill_id']] === 1000 && $openById[$freight['bill_id']] === 300,
    'late freight can be billed separately without rewriting the original bill');

$apPage = new \SFM4U\Inventory\SupplierPayablesPage($ap, $purchaseOrders);
ob_start();
$apPage->render();
$apHtml = ob_get_clean();
check(str_contains($apHtml, 'AP ageing') && str_contains($apHtml, '31-60 days')
    && str_contains($apHtml, 'FREIGHT-' . $tag) && str_contains($apHtml, 'Record a supplier payment'),
    'Woo admin AP page shows ageing, bill and payment controls');
