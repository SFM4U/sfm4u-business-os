<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    exit(1);
}
use SFM4U\Wholesale\Commerce;

update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_price_num_decimals', 2);
update_option('woocommerce_cart_redirect_after_add', 'no');
$product = new WC_Product_Simple();
$product->set_name('SFM4U synthetic browser pack');
$product->set_status('publish');
$product->set_regular_price('149.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(100);
$product->save();
Commerce::saveRule($product, ['min' => 12, 'step' => 12, 'buyer' => 'approved:customer',
    'minor' => 141, 'packs' => ['case' => 12], 'pack_minor' => ['case' => 10000]]);
$user = wp_insert_user(['user_login' => 'synthetic-browser-' . wp_generate_uuid4(),
    'user_pass' => wp_generate_password(), 'role' => 'customer']);
update_user_meta($user, '_sfmwh_buyer_status', 'approved');
add_filter('pre_wp_mail', '__return_true');
$purchase = wc_create_order(['customer_id' => $user]);
$purchase->set_currency('BDT');
$item = new WC_Order_Item_Product();
$item->set_product($product);
$item->set_quantity(12);
$item->set_total('100.00');
$item->add_meta_data('_sfmwh_snapshot_v1', ['currency' => 'BDT', 'scale' => 2]);
\SFM4U\Wholesale\PurchaseHistory::capture($item, $product, $user);
$purchase->add_item($item);
$purchase->calculate_totals(false);
$purchase->update_status('on-hold');
$classic = wp_insert_post(['post_title' => 'Synthetic Classic basket', 'post_status' => 'publish',
    'post_type' => 'page', 'post_content' => '[woocommerce_cart]'], true);
$cartPattern = (new ReflectionMethod(WC_Install::class, 'get_cart_block_content'))->invoke(null);
$blocks = wp_insert_post(['post_title' => 'Synthetic Blocks basket', 'post_status' => 'publish',
    'post_type' => 'page', 'post_content' => $cartPattern], true);
update_option('woocommerce_cart_page_id', $blocks);
$classicCheckout = wp_insert_post(['post_title' => 'Synthetic Classic checkout', 'post_status' => 'publish',
    'post_type' => 'page', 'post_content' => '[woocommerce_checkout]'], true);
$checkoutPattern = (new ReflectionMethod(WC_Install::class, 'get_checkout_block_content'))->invoke(null);
$blocksCheckout = wp_insert_post(['post_title' => 'Synthetic Blocks checkout', 'post_status' => 'publish',
    'post_type' => 'page', 'post_content' => $checkoutPattern], true);
update_option('woocommerce_checkout_page_id', $blocksCheckout);
echo wp_json_encode([
    'home' => home_url('/'), 'product' => add_query_arg(['post_type' => 'product', 'p' => $product->get_id()], home_url('/')),
    'productId' => $product->get_id(), 'classic' => add_query_arg('page_id', $classic, home_url('/')),
    'blocks' => add_query_arg('page_id', $blocks, home_url('/')),
    'classicCheckout' => add_query_arg('page_id', $classicCheckout, home_url('/')),
    'blocksCheckout' => add_query_arg('page_id', $blocksCheckout, home_url('/')),
    'purchases' => add_query_arg('sfmwh-purchases', '1', wc_get_page_permalink('myaccount')),
    'purchaseId' => $purchase->get_id(),
    'cookie' => ['name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie($user, time() + 900, 'logged_in')],
]);
