<?php
wp_set_current_user(1);
$matrixOptions = [];
foreach (['woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_based_on' => 'base',
    'woocommerce_default_country' => 'BD', 'woocommerce_price_num_decimals' => 2] as $option => $value) {
    $matrixOptions[$option] = get_option($option, false); update_option($option, $value);
}
try {
    $matrixClass = WC_Tax::create_tax_class('Synthetic native tax ' . $tag);
    $matrixRate = WC_Tax::_insert_tax_rate(['tax_rate_country' => 'BD', 'tax_rate_state' => '', 'tax_rate' => '15.0000',
        'tax_rate_name' => 'Synthetic native VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
        'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => $matrixClass['slug']]);
    $matrixProduct = wc_get_product($nativePid); $matrixProduct->set_tax_class($matrixClass['slug']); $matrixProduct->save();
    $virtual = new WC_Product_Simple(); $virtual->set_name('Synthetic virtual bundle service');
    $virtual->set_regular_price('10.00'); $virtual->set_virtual(true); $virtual->set_tax_class($matrixClass['slug']); $virtual->save();
    $matrixBefore = $daily->report($today, $merchant, 'R1');
    $matrixOrder = wc_create_order(['created_via' => 'store-api']); $matrixOrder->set_currency('BDT');
    $matrixOrder->set_billing_country('BD'); $matrixOrder->set_shipping_country('BD');
    $matrixLine = $matrixOrder->add_product(wc_get_product($nativePid), 2, ['subtotal' => 60, 'total' => 50]);
    $matrixOrder->add_product($virtual, 1);
    $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_title('Synthetic shipping'); $shipping->set_total('7.00');
    $matrixOrder->add_item($shipping); $matrixOrder->calculate_totals(); $matrixOrder->save();
    check(wc_format_decimal($matrixOrder->get_total(), 2) === '76.00' && wc_format_decimal($matrixOrder->get_total_tax(), 2) === '9.00',
        'native mixed virtual and discounted stock order uses Woo tax and shipping totals');
    $matrixOrder->update_status('processing');
    check((int) wc_get_product($nativePid)->get_stock_quantity() === 7 && $valuation->totals($nativePid)['stock_micros'] === 140000000,
        'native mixed order captures only physical units and original warehouse cost');
    $goodwill = wc_create_refund(['order_id' => $matrixOrder->get_id(), 'amount' => '5.75', 'restock_items' => false,
        'refund_payment' => false, 'line_items' => [$matrixLine => ['qty' => 0, 'refund_total' => '5.00', 'refund_tax' => [$matrixRate => '0.75']]]]);
    check($goodwill instanceof WC_Order_Refund && (int) wc_get_product($nativePid)->get_stock_quantity() === 7,
        'native goodwill refund reduces net sales without stock or cost movement');
    $taxReturn = wc_create_refund(['order_id' => $matrixOrder->get_id(), 'amount' => '23.00', 'restock_items' => true,
        'refund_payment' => false, 'line_items' => [$matrixLine => ['qty' => 1, 'refund_total' => '20.00', 'refund_tax' => [$matrixRate => '3.00']]]]);
    check($taxReturn instanceof WC_Order_Refund && (int) wc_get_product($nativePid)->get_stock_quantity() === 8,
        'native taxed discounted return restores quantity and original cost independently of refund price');
    wc_get_order($matrixOrder->get_id())->update_status('cancelled');
    $matrixAfter = $daily->report($today, $merchant, 'R1');
    check((int) wc_get_product($nativePid)->get_stock_quantity() === 9 && $valuation->totals($nativePid)['stock_micros'] === 180000000
        && $matrixAfter['sales_minor'] - $matrixBefore['sales_minor'] === 6000
        && $matrixAfter['returns_minor'] - $matrixBefore['returns_minor'] === 6000
        && $matrixAfter['sale_cogs_micros'] - $matrixBefore['sale_cogs_micros'] === 40000000
        && $matrixAfter['reversed_cogs_micros'] - $matrixBefore['reversed_cogs_micros'] === 40000000,
        'native cancellation reconciles remaining net line value after goodwill, tax and partial discounted return');
    wp_set_current_user($staff);
    try { $cashPos->sale($merchant, 'R1', wp_generate_uuid4(), $countProductId, 1, 3000); check(false, 'taxed POS must reject'); }
    catch (DomainException $error) { check($error->getCode() === 422, 'cash-only POS rejects taxable configuration before any order or stock write'); }
} finally {
    foreach ($matrixOptions as $option => $value) { if ($value === false) delete_option($option); else update_option($option, $value); }
    wp_set_current_user(1);
}
$variable = new WC_Product_Variable(); $variable->set_name('Synthetic shared parent stock'); $variable->set_manage_stock(true);
$variable->set_stock_quantity(0); $variable->save();
$variant = new WC_Product_Variation(); $variant->set_parent_id($variable->get_id()); $variant->set_regular_price('13.00'); $variant->save();
$variantPo = $purchase->issue(wp_generate_uuid4(), 'Synthetic variable supplier', [['product' => $variable->get_id(), 'quantity' => 5, 'unit_minor' => 400]]);
$purchase->receive(wp_generate_uuid4(), $variantPo['id'], [['product' => $variable->get_id(), 'quantity' => 5, 'landed_minor' => 0]]);
$variantOrder = wc_create_order(['created_via' => 'checkout']); $variantOrder->set_currency('BDT');
$variantLine = $variantOrder->add_product(wc_get_product($variant->get_id()), 2); $variantOrder->calculate_totals(false);
$variantOrder->update_status('processing');
check((int) wc_get_product($variable->get_id())->get_stock_quantity() === 3 && $valuation->totals($variable->get_id())['stock_micros'] === 12000000,
    'native variation uses the stock-managed parent warehouse pool');
$variantRefund = wc_create_refund(['order_id' => $variantOrder->get_id(), 'amount' => '13.00', 'restock_items' => true,
    'refund_payment' => false, 'line_items' => [$variantLine => ['qty' => 1, 'refund_total' => '13.00', 'refund_tax' => []]]]);
wc_get_order($variantOrder->get_id())->update_status('cancelled');
check($variantRefund instanceof WC_Order_Refund && (int) wc_get_product($variable->get_id())->get_stock_quantity() === 5
    && $valuation->totals($variable->get_id())['stock_micros'] === 20000000, 'variation partial return and cancellation restore original parent quantity and cost');
