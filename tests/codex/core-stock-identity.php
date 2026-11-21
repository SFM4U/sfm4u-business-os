<?php
/** Standalone side of the Core M1 stock identity contract. */
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback synthetic fixture required.');
}
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_hold_stock_minutes', 15);
$parent = new WC_Product_Variable();
$parent->set_manage_stock(true);
$parent->set_stock_quantity(10);
$parent->save();
$variation = static function (bool $separate) use ($parent): WC_Product_Variation {
    $product = new WC_Product_Variation();
    $product->set_parent_id($parent->get_id());
    $product->set_manage_stock($separate);
    $product->set_regular_price('20.00');
    if ($separate) {
        $product->set_stock_quantity(7);
    }
    $product->save();
    return wc_get_product($product->get_id());
};
$first = $variation(false);
$second = $variation(false);
$separate = $variation(true);
if ($first->get_stock_managed_by_id() !== $parent->get_id()
    || $second->get_stock_managed_by_id() !== $parent->get_id()
    || $separate->get_stock_managed_by_id() !== $separate->get_id()) {
    throw new RuntimeException('Woo stock-owner identity mismatch.');
}
echo "PASS inherited variants share the parent stock identity and separately managed variants keep their own\n";
$order = wc_create_order();
$order->add_product($first, 3);
$order->save();
wc_reserve_stock_for_order($order);
try {
    if ((int) wc_get_held_stock_quantity(wc_get_product($second->get_stock_managed_by_id())) !== 3
        || (int) wc_get_held_stock_quantity($separate) !== 0
        || (int) wc_get_product($parent->get_id())->get_stock_quantity() !== 10) {
        throw new RuntimeException('Variation holds did not respect the shared stock identity.');
    }
    echo "PASS a hold on one variant reduces shared availability without changing on-hand stock or separate stock\n";
} finally {
    wc_release_stock_for_order($order);
}
