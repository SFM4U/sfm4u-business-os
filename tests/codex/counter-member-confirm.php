<?php

use SFM4U\Wholesale\{Commerce, MemberPrice};
use SFM4U\Wholesale\Members\{Members, Tiers};

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Disposable loopback fixture required.');
}

function check_member_pos($passed, string $name): void {
    if (!$passed) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}

wp_set_current_user(1);
Members::install();
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_calc_taxes', 'no');
update_option('sfmwh_enabled', 'yes');
update_option(MemberPrice::OPT_PRICE_MODE, 'lowest');

$merchant = 763122;
$register = 'MEM';

$product = new WC_Product_Simple();
$product->set_name('Synthetic member counter item');
$product->set_regular_price('100.00');
$product->set_status('publish');
$product->set_manage_stock(true);
$product->set_stock_quantity(0);
$product->update_meta_data('_sfm4u_pos_merchant_id', $merchant);
$product->save();
$pid = $product->get_id();
\SFM4U\Wholesale\B2BRuntime::upgrade();
$purchase = new \SFM4U\Inventory\PurchaseOrders($GLOBALS['wpdb']);
$po = $purchase->issue(wp_generate_uuid4(), 'Synthetic supplier', [['product' => $pid, 'quantity' => 12, 'unit_minor' => 3000]]);
$purchase->receive(wp_generate_uuid4(), $po['id'], [['product' => $pid, 'quantity' => 12, 'landed_minor' => 0]]);
Commerce::saveRule(wc_get_product($pid), ['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'9000']]);

$make = static function (string $name, string $tier): int {
    $id = wp_create_user("member-probe-$name", wp_generate_password(), "$name@example.invalid");
    if (is_wp_error($id)) { throw new RuntimeException('Could not create synthetic member'); }
    Members::issue($id, $tier, 0, 1, 'main', null, "Synthetic $name");
    return $id;
};
$business = $make('business', 'business');
$paid = $make('paid-black', 'black');
$unpaid = $make('unpaid-black', 'black');
$expired = $make('expired-black', 'black');
$suspended = $make('suspended', 'business');
$changed = $make('changed', 'business');
Tiers::recordBlackPayment($paid, 1, 'cash', '', 1);
update_user_meta($expired, Tiers::BLACK_UNTIL, '2000-01-01');
update_user_meta($suspended, '_sfmwh_buyer_status', 'suspended');

wp_set_current_user(1);
$cashier = wp_create_user('member-probe-cashier', wp_generate_password());
$_POST = ['sfm4u_pos_assignments' => "$merchant,$register,C1", 'sfm4u_pos_credit' => '1', 'sfm4u_pos_assign_nonce' => wp_create_nonce('sfm4u_pos_assign_' . $cashier)];
(new \SFM4U\POS\RegisterAssignments())->save($cashier);
$_POST = [];

$call = static function (array $params, ?string $key = null, ?int $as = null) use ($cashier, $merchant, $register): WP_REST_Response {
    wp_set_current_user($as ?? $cashier);
    $request = new WP_REST_Request('POST', '/sfm4u-pos/v1/register');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_header('Idempotency-Key', $key ?? wp_generate_uuid4());
    $request->set_body(wp_json_encode($params + compact('merchant', 'register')));
    return rest_do_request($request);
};
$open = $call(['action' => 'drawer', 'drawer_action' => 'open', 'session' => 0, 'amount' => 0]);
check_member_pos($open->get_status() === 200, 'assigned cashier opens: ' . $open->get_status() . ' ' . ($open->get_data()['code'] ?? ''));
$session = $open->get_data()['session_id'];
$intent = static fn(string $member): array => [
    'lines' => [['product' => $pid, 'quantity' => 1]], 'tier' => 'member',
    'method' => 'cash', 'discount' => 0, 'member' => $member,
];
$stock = static fn(): int => (int) wc_get_product($pid)->get_stock_quantity();
$receiptCount = static function () use ($merchant, $register): int {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE merchant=%d AND register_code=%s',
        $wpdb->prefix . 'sfm4u_pos_receipts', $merchant, $register
    ));
};
$saleData = static fn(string $member, string $digest): array => $intent($member) +
    ['action' => 'counter_sale', 'session' => $session, 'digest' => $digest, 'tender' => 9000];
foreach (['Business' => $business, 'paid Black' => $paid] as $label => $id) {
    $no = Members::memberNo($id);
    $price = $call($intent($no) + ['action' => 'counter_quote']);
    check_member_pos($price->get_status() === 200 && $price->get_data()['total_minor'] === 9000, "$label member quote");
    $before = $stock();
    $key = wp_generate_uuid4();
    $data = $saleData($no, $price->get_data()['digest']);
    $sale = $call($data, $key);
    check_member_pos($sale->get_status() === 200 && $stock() === $before - 1, "$label commits and removes one stock");
    check_member_pos($call($data, $key)->get_data() === $sale->get_data() && $stock() === $before - 1, "$label same key returns same receipt");
}
$before = $stock();
$validQuote = $call($intent(Members::memberNo($business)) + ['action' => 'counter_quote']);
foreach (['unpaid Black' => $unpaid, 'expired Black' => $expired, 'suspended' => $suspended, 'unknown' => 0] as $label => $id) {
    $no = $id ? Members::memberNo($id) : '0000000';
    $entry = $call($intent($no) + ['action' => 'counter_quote']);
    check_member_pos($entry->get_status() === ($id ? 422 : 404), "$label denied at quote");
    $invalidSale = $call($saleData($no, $validQuote->get_data()['digest']));
    check_member_pos($invalidSale->get_status() === 409 && $stock() === $before && $receiptCount() === 2,
        "$label denied at confirm without stock or receipt change");
}
$no = Members::memberNo($changed);
$quote = $call($intent($no) + ['action' => 'counter_quote']);
check_member_pos($quote->get_status() === 200, 'changed card starts qualified');
wp_set_current_user(1);
update_user_meta($changed, '_sfmwh_buyer_status', 'suspended');
$denied = $call($saleData($no, $quote->get_data()['digest']));
check_member_pos($stock() === $before && $receiptCount() === 2, 'stale member confirm preserves stock and receipts');
check_member_pos($denied->get_status() === 409, 'member suspension after quote must return 409; observed ' . $denied->get_status());

check_member_pos(($denied->get_data()['code'] ?? null) === 'counter_rejected', 'cashier gets a rescan message');
$no = Members::memberNo($business);
$quote = $call($intent($no) + ['action' => 'counter_quote']);
wp_set_current_user(1);
Commerce::saveRule(wc_get_product($pid), ['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'8700']]);
$stale = $call($saleData($no, $quote->get_data()['digest']));
check_member_pos($stale->get_status() === 409 && $stock() === $before && $receiptCount() === 2, 'changed price still rejects stale confirmation');
$unassigned = $call($saleData($no, $quote->get_data()['digest']), null, $unpaid);
check_member_pos($unassigned->get_status() === 403 && $receiptCount() === 2, 'unassigned actor cannot confirm');
