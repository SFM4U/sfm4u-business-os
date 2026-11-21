<?php
use SFM4U\Wholesale\{Admin, BulkOrder, BuyerApplication, Commerce, Rule};

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback fixture required.');
}
function phaseCheck(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_price_num_decimals', 2);
wp_set_current_user(1);
$products = [];
foreach (['One', 'Two'] as $name) {
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic Bulk ' . $name);
    $product->set_sku('PHASE-' . $name);
    $product->set_status('publish');
    $product->set_regular_price('20.00');
    $product->save();
    Commerce::saveRule($product, ['minor' => 1200, 'buyer' => 'approved', 'min' => 2, 'tiers' => [5 => 900],
        'group_prices' => ['bronze' => 1100, 'silver' => 1000, 'gold' => 800]]);
    $products[] = $product->get_id();
}
[$first, $second] = $products;
$buyer = wp_insert_user(['user_login' => 'phase-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'customer']);
wp_set_current_user($buyer);
$apply = wp_create_nonce('sfmwh_buyer_application_' . $buyer);
phaseCheck(BuyerApplication::submit($apply) && BuyerApplication::status($buyer) === 'Pending review'
    && BulkOrder::search('Synthetic Bulk') === [], 'new wholesale applicant remains pending without catalog access');
$_POST = ['sfmwh_buyer_status' => 'approved', 'sfmwh_price_group' => 'gold',
    'sfmwh_buyer_nonce' => wp_create_nonce('sfmwh_buyer_' . $buyer)];
Admin::saveBuyerProfile($buyer);
phaseCheck(Commerce::ruleForBuyer($first, wp_get_current_user()) === null, 'buyer cannot approve their own application');
foreach (['bronze' => 1100, 'silver' => 1000, 'gold' => 800] as $group => $expected) {
    wp_set_current_user(1);
    $_POST['sfmwh_price_group'] = $group;
    $_POST['sfmwh_buyer_nonce'] = wp_create_nonce('sfmwh_buyer_' . $buyer);
    Admin::saveBuyerProfile($buyer);
    wp_set_current_user($buyer);
    phaseCheck(Commerce::ruleForBuyer($first, wp_get_current_user())['minor'] === $expected, "$group resolves its approved unit price");
}
$rule = Commerce::ruleForBuyer($first, wp_get_current_user());
phaseCheck(Rule::quote($rule, 5, 'piece')['line_minor'] === 4000, 'quantity tier cannot raise the better Gold price');
update_user_meta($buyer, '_sfmwh_price_group', 'silver');
phaseCheck(Rule::quote(Commerce::ruleForBuyer($first, wp_get_current_user()), 5, 'piece')['line_minor'] === 4500,
    'quantity tier lowers the Silver price at the configured threshold');
$_POST = [];
wc_load_cart();
phaseCheck(count(BulkOrder::search('Synthetic Bulk')) === 2
    && BulkOrder::search('PHASE-One')[0]->get_id() === $first, 'bulk form searches by title and exact SKU');
ob_start();
BulkOrder::render();
$form = ob_get_clean();
phaseCheck(str_contains($form, 'sfmwh_bulk_qty[' . $first . ']') && str_contains($form, 'sfmwh_bulk_qty[' . $second . ']'),
    'bulk form renders multiple product quantity controls on one page');
$nonce = wp_create_nonce('sfmwh_bulk_order');
foreach ([['invalid', 5], [$nonce, 1]] as [$submitted, $quantity]) {
    try {
        BulkOrder::add([$first => '5', $second => (string) $quantity], $submitted);
        throw new RuntimeException('Invalid bulk order unexpectedly added.');
    } catch (DomainException) {
        phaseCheck(WC()->cart->is_empty(), 'bulk nonce or invalid quantity rejects entire batch');
    }
}
wc_clear_notices();
BulkOrder::add([$first => '5', $second => '2'], $nonce);
phaseCheck(count(WC()->cart->get_cart()) === 2, 'valid bulk order adds all selected lines');
$key = array_key_first(WC()->cart->get_cart());
WC()->cart->set_quantity($key, 1, false);
do_action('woocommerce_check_cart_items');
$errors = new WP_Error();
do_action('woocommerce_store_api_cart_errors', $errors, WC()->cart);
phaseCheck(wc_notice_count('error') > 0 && in_array('sfmwh_invalid_cart', $errors->get_error_codes(), true),
    'Classic and Store API checkout reject a cart line below MOQ');
