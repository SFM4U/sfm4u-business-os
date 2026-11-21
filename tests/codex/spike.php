<?php
use SFM4U\Wholesale\Commerce;
function same($expected, $actual, $name) {
    if ($expected !== $actual) {
        throw new RuntimeException($name . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "PASS $name\n";
}
add_filter('pre_wp_mail', '__return_true');
echo 'WordPress ' . get_bloginfo('version') . ', WooCommerce ' . WC_VERSION . ', PHP ' . PHP_VERSION . "\n";
update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_price_num_decimals', 2);
wp_set_current_user(0);
wc_load_cart();
WC()->cart->empty_cart();
$product = new WC_Product_Simple();
$product->set_name('Synthetic water bottle');
$product->set_regular_price('15.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(100);
$product->save();
$parent = new WC_Product_Variable();
$parent->set_name('Synthetic variation parent');
$parent->save();
$variant = new WC_Product_Variation();
$variant->set_parent_id($parent->get_id());
$variant->set_regular_price('20.00');
$variant->set_manage_stock(true);
$variant->set_stock_quantity(50);
$variant->save();
$data = ['minor' => 1001, 'min' => 12, 'step' => 12, 'packs' => ['case' => 12], 'buyer' => 'role:customer',
    'tiers' => [24 => 801]];
Commerce::saveRule($product, $data);
Commerce::saveRule($variant, array_replace($data, ['minor' => 1103]));
same(1001, wc_get_product($product->get_id())->get_meta(Commerce::META)['minor'], 'simple rule persisted');
same(1103, wc_get_product($variant->get_id())->get_meta(Commerce::META)['minor'], 'variation override persisted');
$guestKey = WC()->cart->add_to_cart($product->get_id(), 1);
WC()->cart->calculate_totals();
same('15', (string) (0 + WC()->cart->get_cart_item($guestKey)['data']->get_price()), 'guest retail price');
$otherRetailExtension = static function (WC_Cart $cart) use ($guestKey) {
    $cart->cart_contents[$guestKey]['data']->set_price('13.25');
};
add_action('woocommerce_before_calculate_totals', $otherRetailExtension, 50);
WC()->cart->calculate_totals();
same('13.25', (string) WC()->cart->get_cart_item($guestKey)['data']->get_price(), 'wholesale leaves third-party retail adjustments alone');
remove_action('woocommerce_before_calculate_totals', $otherRetailExtension, 50);
WC()->cart->empty_cart();
$user = wp_insert_user(['user_login' => 'synthetic-buyer-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'customer']);
wp_set_current_user($user);
$_POST['sfmwh_pack'] = 'case';
same(false, apply_filters('woocommerce_add_to_cart_validation', true, $product->get_id(), 13), 'server rejects fractional pack');
wc_clear_notices();
same(true, apply_filters('woocommerce_add_to_cart_validation', true, $product->get_id(), 24), 'server accepts two cases');
$key = WC()->cart->add_to_cart($product->get_id(), 24);
unset($_POST['sfmwh_pack']);
$variationKey = WC()->cart->add_to_cart($parent->get_id(), 12, $variant->get_id());
WC()->cart->calculate_totals();
same(24, WC()->cart->get_cart_item($key)['quantity'], 'cart uses canonical stock units');
same('192.24', wc_format_decimal(WC()->cart->get_cart_item($key)['line_subtotal'], 2), 'exact tier line subtotal');
same('132.36', wc_format_decimal(WC()->cart->get_cart_item($variationKey)['line_subtotal'], 2), 'exact variation line subtotal');
same('15.00', wc_get_product($product->get_id())->get_regular_price(), 'retail product metadata unchanged');
$order = wc_create_order();
$order->set_currency('BDT');
WC()->checkout()->create_order_line_items($order, WC()->cart);
$order->save();
$lines = array_values(wc_get_order($order->get_id())->get_items());
same(19224, $lines[0]->get_meta('_sfmwh_snapshot_v1')['line_minor'], 'immutable monetary basis saved on line');
same(2, $lines[0]->get_meta('_sfmwh_snapshot_v1')['pack_count'], 'pack count saved separately');
$order->set_payment_method('');
$order->update_status('processing');
wc_maybe_reduce_stock_levels($order->get_id());
same(76, (int) wc_get_product($product->get_id())->get_stock_quantity(), 'native stock deduction once');
same(38, (int) wc_get_product($variant->get_id())->get_stock_quantity(), 'native variation stock deduction once');
$order->update_status('cancelled');
wc_maybe_increase_stock_levels($order->get_id());
same(100, (int) wc_get_product($product->get_id())->get_stock_quantity(), 'native cancellation restocks once');
Commerce::saveRule($product, array_replace($data, ['minor' => 1200]));
same(19224, array_values(wc_get_order($order->get_id())->get_items())[0]->get_meta('_sfmwh_snapshot_v1')['line_minor'], 'rule edit preserves saved order');

WC()->cart->empty_cart();
Commerce::saveRule($product, array_replace($data, ['pack_minor' => ['case' => 10000]]));
$_POST['sfmwh_pack'] = 'case';
$key = WC()->cart->add_to_cart($product->get_id(), 12);
unset($_POST['sfmwh_pack']);
WC()->cart->calculate_totals();
same('100.00', wc_format_decimal(WC()->cart->get_cart_item($key)['line_subtotal'], 2), '100 per 12 pieces survives Woo rounding');
$order = wc_create_order(['created_via' => 'sfm4u-g0-receipt']);
$order->set_currency('BDT');
WC()->checkout()->create_order_line_items($order, WC()->cart);
$order->calculate_totals();
$line = array_values($order->get_items())[0];
same(10000, $line->get_meta('_sfmwh_snapshot_v1')['line_minor'], '100 per case snapshot stores exact minor units');
same('100.00', wc_format_decimal($line->get_subtotal(), 2), 'persisted Woo order has exact fixed pack subtotal');

WC()->cart->empty_cart();
$request = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
$request->set_header('Nonce', wp_create_nonce('wc_store_api'));
$request->set_body_params(['id' => $product->get_id(), 'quantity' => 12,
    'extensions' => ['sfm4u-wholesale' => ['pack' => 'case', 'price' => '0.01']]]);
$response = rest_do_request($request);
if ($response->is_error()) { throw new RuntimeException(wp_json_encode($response->get_data())); }
same(201, $response->get_status(), 'Store API accepts valid case with server-owned price');
$cartResponse = json_decode(wp_json_encode($response->get_data()), true, 16, JSON_THROW_ON_ERROR);
$item = $cartResponse['items'][0];
same('10000', (string) $item['totals']['line_subtotal'], 'Store API returns exact case amount and ignores spoof price');
$update = new WP_REST_Request('POST', '/wc/store/v1/cart/update-item');
$update->set_header('Nonce', wp_create_nonce('wc_store_api'));
$update->set_body_params(['key' => $item['key'], 'quantity' => 13]);
same(400, rest_do_request($update)->get_status(), 'Store API rejects off-step pack update');

$orderController = new \Automattic\WooCommerce\StoreApi\Utilities\OrderController();
$seedDraft = $orderController->create_order_from_cart();
$seedDraft->save();
WC()->session->set('store_api_draft_order', $seedDraft->get_id());
$draft = new WP_REST_Request('GET', '/wc/store/v1/checkout');
$draft->set_header('Nonce', wp_create_nonce('wc_store_api'));
$draftResponse = rest_do_request($draft);
same(200, $draftResponse->get_status(), 'Store API checkout returns the persisted draft without payment');
$draftOrder = wc_get_order($draftResponse->get_data()['order_id']);
same('', $draftOrder->get_payment_method(), 'Store API draft has no payment method');
same(10000, array_values($draftOrder->get_items())[0]->get_meta('_sfmwh_snapshot_v1')['line_minor'],
    'Blocks checkout draft retains exact wholesale snapshot');
