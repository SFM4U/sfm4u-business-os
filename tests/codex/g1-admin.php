<?php
use SFM4U\Wholesale\Admin;
use SFM4U\Wholesale\Commerce;
use SFM4U\Wholesale\RuleCsv;

function check(bool $test, string $description): void {
    if (!$test) {
        throw new RuntimeException($description);
    }
    echo "PASS $description\n";
}

function denied(callable $test, string $description): void {
    try { $test(); } catch (DomainException $error) { check(true, $description); return; }
    check(false, $description);
}

wp_set_current_user(1);
$product = new WC_Product_Simple();
$product->set_status('publish');
$product->set_status('publish');
$product->set_name('Synthetic CSV rule');
$product->set_sku('SFM-CSV-' . wp_generate_uuid4());
$product->set_regular_price('48.00');
$product->save();
$csv = fopen('php://temp', 'w+');
$header = ['sku', 'currency', 'scale', 'stock_unit', 'buyer', 'minor', 'min', 'max', 'step', 'tiers', 'packs', 'pack_minor'];
$row = [$product->get_sku(), get_woocommerce_currency(), wc_get_price_decimals(), 'piece', 'role:customer', '3210', '2', '40', '2',
    '{"10":2500}', '{"case":2}', '{}'];
fputcsv($csv, $header, ',', '"', '');
fputcsv($csv, $row, ',', '"', '');
rewind($csv);
$contents = stream_get_contents($csv);
$preview = RuleCsv::preview($contents);
check(count($preview) === 1 && $product->get_meta(Commerce::META) === '', 'CSV dry run validates without writing');
RuleCsv::apply($preview);
$rule = wc_get_product($product->get_id())->get_meta(Commerce::META);
check($rule['minor'] === 3210 && $rule['packs']['case'] === 2, 'CSV applies normalized values');
check(wc_get_product($product->get_id())->get_regular_price() === '48.00', 'CSV import keeps retail price');
denied(fn() => RuleCsv::apply($preview), 'stale preview cannot overwrite a rule');
denied(fn() => RuleCsv::preview($contents . explode("\n", $contents)[1] . "\n"), 'duplicate SKU rejected before writing');

$export = fopen('php://temp', 'w+');
check(RuleCsv::export($export, $product->get_id()) === 1, 'CSV exports the selected rule');
rewind($export);
$decoded = RuleCsv::preview(stream_get_contents($export));
check($decoded[$product->get_id()]['rule']['packs']['case'] === 2, 'exported rule round-trips into preview');
fclose($export);

$anonymous = wp_insert_user(['user_login' => 'synthetic-subscriber-' . wp_generate_uuid4(),
    'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($anonymous);
denied(fn() => RuleCsv::preview($contents), 'subscriber cannot preview target product');
denied(fn() => RuleCsv::export($csv), 'subscriber cannot export private rule prices');
denied(fn() => Admin::product($product->get_id()), 'subscriber cannot open product wholesale editor');

wp_set_current_user(1);
$policyRule = $rule;
$policyRule['buyer'] = 'approved:customer';
Commerce::saveRule(wc_get_product($product->get_id()), $policyRule);
$buyer = wp_insert_user(['user_login' => 'synthetic-approved-' . wp_generate_uuid4(),
    'user_pass' => wp_generate_password(), 'role' => 'customer']);
update_option('sfmwh_enabled', 'yes');
wp_set_current_user($buyer);
wc_load_cart();
WC()->cart->empty_cart();
$key = WC()->cart->add_to_cart($product->get_id(), 2);
WC()->cart->calculate_totals();
check(wc_format_decimal(WC()->cart->get_cart_item($key)['data']->get_price(), 2) === '48.00', 'pending buyer sees retail');
wp_set_current_user(1);
$_POST['sfmwh_buyer_status'] = 'approved';
$_POST['sfmwh_buyer_nonce'] = wp_create_nonce('sfmwh_buyer_' . $buyer);
Admin::saveBuyerProfile($buyer);
unset($_POST['sfmwh_buyer_status'], $_POST['sfmwh_buyer_nonce']);
wp_set_current_user($buyer);
WC()->cart->calculate_totals();
check(wc_format_decimal(WC()->cart->get_cart_item($key)['data']->get_price(), 2) === '32.10', 'authorized buyer receives private price');
$_POST['sfmwh_buyer_status'] = 'suspended';
$_POST['sfmwh_buyer_nonce'] = wp_create_nonce('sfmwh_buyer_' . $buyer);
Admin::saveBuyerProfile($buyer);
check(get_user_meta($buyer, '_sfmwh_buyer_status', true) === 'approved', 'buyer cannot change own approval');
wp_set_current_user(1);
$_POST['sfmwh_buyer_nonce'] = wp_create_nonce('sfmwh_buyer_' . $buyer);
Admin::saveBuyerProfile($buyer);
unset($_POST['sfmwh_buyer_status'], $_POST['sfmwh_buyer_nonce']);
wp_set_current_user($buyer);
WC()->cart->calculate_totals();
check(wc_format_decimal(WC()->cart->get_cart_item($key)['data']->get_price(), 2) === '48.00', 'suspended buyer immediately returns to retail');
wp_set_current_user(0);
$request = new WP_REST_Request('GET', '/wc/store/v1/products/' . $product->get_id());
$response = rest_do_request($request);
$wire = wp_json_encode($response->get_data());
check($response->get_status() === 200 && !str_contains($wire, '3210') && !str_contains($wire, Commerce::META), 'guest catalogue REST omits private rule and wholesale price');

wp_set_current_user(1);
update_user_meta($buyer, '_sfmwh_buyer_status', 'approved');
wp_set_current_user($buyer);
update_option('woocommerce_enable_coupons', 'yes');
$coupon = new WC_Coupon();
$coupon->set_code('synthetic-' . wp_generate_uuid4());
$coupon->set_discount_type('fixed_cart');
$coupon->set_amount('5.00');
$coupon->save();
check(WC()->cart->apply_coupon($coupon->get_code()) === false, 'fixed cart coupon cannot stack on wholesale');
WC()->cart->calculate_totals();
check(wc_format_decimal(WC()->cart->get_cart_item($key)['line_subtotal'], 2) === '64.20', 'rejected coupon leaves exact quote subtotal');

update_option('sfmwh_min_order_minor', 6500);
$errors = new WP_Error();
Commerce::storeErrors($errors, WC()->cart);
check($errors->has_errors(), 'wholesale subtotal below the merchant minimum blocks checkout');
update_option('sfmwh_min_order_minor', 6420);
$errors = new WP_Error();
Commerce::storeErrors($errors, WC()->cart);
check(!$errors->has_errors(), 'exact wholesale minimum passes checkout validation');
update_option('sfmwh_min_order_minor', 0);

wp_set_current_user(1);
switch_to_locale('bn_BD');
load_textdomain('sfm4u-wholesale', '/source/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo', 'bn_BD');
$row[9] = '{broken';
$badCsv = fopen('php://temp', 'w+');
fputcsv($badCsv, $header, ',', '"', ''); fputcsv($badCsv, $row, ',', '"', ''); rewind($badCsv);
try { RuleCsv::preview(stream_get_contents($badCsv)); check(false, 'invalid CSV JSON must reject'); }
catch (DomainException $error) {
    check(str_contains($error->getMessage(), 'সঠিক JSON দিন') && !str_contains($error->getMessage(), 'Syntax error'), 'CSV parse errors use localized row and validation messages');
}

$sku = $product->get_sku();
$new = new WC_Product_Simple();
$new->set_sku('SFM-PRICE-' . wp_generate_uuid4());
$new->set_regular_price('90.00');
$new->save();
$previous = wc_get_product($product->get_id())->get_meta(Commerce::META);
$priceCsv = "\xEF\xBB\xBFsku,price\r\n{$sku},27.45\r\n{$new->get_sku()},25\r\n";
$prices = RuleCsv::preview($priceCsv);
check(count($prices) === 2 && $prices[$product->get_id()]['rule']['minor'] === 2745
    && $prices[$new->get_id()]['rule']['minor'] === 2500, 'simple CSV accepts BOM, CRLF and exact decimal prices');
check(wc_get_product($product->get_id())->get_meta(Commerce::META) === $previous
    && wc_get_product($new->get_id())->get_meta(Commerce::META) === '', 'simple CSV preview leaves both products unchanged');
$malformed = [
    "sku,cost\n{$sku},25\n" => 'শিরোনাম হিসেবে sku,price',
    "sku,price\n{$sku}\n" => 'সারি 2: সারিতে CSV-এর',
    "sku,price\n{$sku},25\n{$sku},30\n" => 'সারি 3:',
    "sku,price\nSFM-MISSING,25\n" => 'সারি 2:',
    "sku,price\n{$sku},25.001\n" => 'দোকানের মুদ্রায় ধনাত্মক দাম',
    "sku,price\n{$sku},1e3\n" => 'দোকানের মুদ্রায় ধনাত্মক দাম',
    "sku,price\n{$sku},0.00\n" => 'পাইকারি দাম শূন্য',
    "sku,price\n{$sku},10000000.01\n" => 'পাইকারি দাম শূন্য',
];
foreach ($malformed as $input => $message) {
    try {
        RuleCsv::preview($input);
        check(false, 'invalid simple price list must reject');
    } catch (DomainException $error) {
        check(str_contains($error->getMessage(), $message), 'simple CSV gives the expected Bangla validation error');
    }
}
RuleCsv::apply($prices);
$updated = wc_get_product($product->get_id());
$updatedRule = $updated->get_meta(Commerce::META);
check($updatedRule === array_replace($previous, ['minor' => 2745, 'revision' => $previous['revision'] + 1])
    && $updated->get_regular_price() === '48.00', 'simple CSV edits only the existing wholesale base price');
check(wc_get_product($new->get_id())->get_meta(Commerce::META)['buyer'] === 'approved'
    && wc_get_product($new->get_id())->get_regular_price() === '90.00', 'simple CSV defaults new rules to approved buyers');
denied(fn() => RuleCsv::apply($prices), 'simple CSV rejects a stale preview');
restore_previous_locale(); unload_textdomain('sfm4u-wholesale');
