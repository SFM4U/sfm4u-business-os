<?php
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
add_filter('pre_wp_mail', '__return_true');
[$productId, $winnerId] = array_map('intval', $args);
$winner = wc_get_order($winnerId);
$product = wc_get_product($productId);
if ((int) wc_get_held_stock_quantity($product) !== 1 || (int) $product->get_stock_quantity() !== 1) {
    throw new RuntimeException('Expected one held unit and one on hand.');
}
$winner->update_status('processing');
wc_maybe_reduce_stock_levels($winnerId);
wc_release_stock_for_order($winner);
if ((int) wc_get_product($productId)->get_stock_quantity() !== 0) {
    throw new RuntimeException('Expected exactly one committed deduction.');
}
echo "PASS last unit commits once after both channels contend\n";
