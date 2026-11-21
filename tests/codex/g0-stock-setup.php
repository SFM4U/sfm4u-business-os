<?php
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_hold_stock_minutes', 15);
$product = new WC_Product_Simple();
$product->set_name('Synthetic last bottle');
$product->set_regular_price('15.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(1);
$product->save();
$ids = [];
$channels = ($args[0] ?? '') === 'three' ? ['checkout', 'checkout', 'sfm4u-pos-spike'] : ['checkout', 'sfm4u-pos-spike'];
foreach ($channels as $channel) {
    $order = wc_create_order(['created_via' => $channel]);
    $order->add_product($product, 1);
    $ids[] = $order->get_id();
}
echo implode(' ', [$product->get_id(), ...$ids]);
