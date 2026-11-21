<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    exit(1);
}

use SFM4U\Wholesale\Commerce;

function expect($expected, $actual, string $description): void {
    if ($expected !== $actual) {
        throw new RuntimeException($description . ': ' . var_export($actual, true));
    }
    echo "PASS $description\n";
}

$previous = [];
foreach (['woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no',
    'woocommerce_tax_based_on' => 'base', 'woocommerce_default_country' => 'BD',
    'woocommerce_currency' => 'BDT', 'woocommerce_price_num_decimals' => 2, 'sfmwh_enabled' => 'yes'] as $option => $value) {
    $previous[$option] = get_option($option, false);
    update_option($option, $value);
}

try {
    $taxClass = WC_Tax::create_tax_class('Synthetic ' . wp_generate_uuid4());
    if (is_wp_error($taxClass)) throw new RuntimeException($taxClass->get_error_message());
    WC_Tax::_insert_tax_rate(['tax_rate_country' => 'BD', 'tax_rate_state' => '', 'tax_rate' => '15.0000',
        'tax_rate_name' => 'Synthetic tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
        'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => $taxClass['slug']]);
    wp_set_current_user(0);
    wc_load_cart();
    WC()->cart->empty_cart();
    $product = new WC_Product_Simple();
    $product->set_status('publish');
    $product->set_name('Synthetic taxable bottles');
    $product->set_regular_price('15.00');
    $product->set_tax_status('taxable');
    $product->set_tax_class($taxClass['slug']);
    $product->save();
    // A guest-created author-0 fixture is treated as the guest's own product by Dokan.
    wp_update_post(['ID' => $product->get_id(), 'post_author' => 1]);
    Commerce::saveRule($product, ['buyer' => 'everyone', 'minor' => 1000, 'min' => 12, 'step' => 12,
        'packs' => ['case' => 12], 'pack_minor' => ['case' => 10000]]);
    $_POST['sfmwh_pack'] = 'case';
    $key = WC()->cart->add_to_cart($product->get_id(), 12);
    unset($_POST['sfmwh_pack']);
    WC()->cart->calculate_totals();
    $item = WC()->cart->get_cart_item($key);
    expect('100.00', wc_format_decimal($item['line_subtotal'], 2), 'pre-tax case subtotal exact');
    expect('15.00', wc_format_decimal($item['line_subtotal_tax'], 2), 'WooCommerce calculates synthetic 15 percent tax on full case');
    $order = wc_create_order();
    $order->set_currency('BDT');
    WC()->checkout()->set_data_from_cart($order);
    $order->save();
    $line = array_values($order->get_items())[0];
    expect(10000, $line->get_meta('_sfmwh_snapshot_v1')['line_minor'], 'saved snapshot retains pre-tax basis');
    expect('115.00', wc_format_decimal($order->get_total(), 2), 'order gross total preserves tax and exact case amount');

    update_option('woocommerce_prices_include_tax', 'yes');
    WC()->cart->calculate_totals();
    $item = WC()->cart->get_cart_item($key);
    expect('100.00', wc_format_decimal($item['line_subtotal'], 2), 'inclusive catalogue preserves pre-tax case subtotal');
    expect('15.00', wc_format_decimal($item['line_subtotal_tax'], 2), 'inclusive catalogue applies Woo base tax exactly once');
    $inclusive = wc_create_order();
    $inclusive->set_currency('BDT');
    WC()->checkout()->set_data_from_cart($inclusive);
    $inclusive->save();
    expect('115.00', wc_format_decimal($inclusive->get_total(), 2), 'inclusive checkout persists exact gross total');
    expect(10000, array_values($inclusive->get_items())[0]->get_meta('_sfmwh_snapshot_v1')['line_minor'],
        'inclusive order records unchanged pre-tax snapshot');

    WC()->customer->set_is_vat_exempt(true);
    WC()->cart->calculate_totals();
    $exempt = WC()->cart->get_cart_item($key);
    expect('100.00', wc_format_decimal($exempt['line_subtotal'], 2), 'exempt inclusive checkout removes base tax');
    expect('0.00', wc_format_decimal($exempt['line_subtotal_tax'], 2), 'exempt inclusive checkout charges no tax');
    WC()->customer->set_is_vat_exempt(false);
} finally {
    foreach ($previous as $option => $value) {
        if ($value === false) {
            delete_option($option);
        } else {
            update_option($option, $value);
        }
    }
}
