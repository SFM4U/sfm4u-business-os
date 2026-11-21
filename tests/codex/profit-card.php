<?php
use SFM4U\Wholesale\{Commerce, ProfitCard};
use SFM4U\POS\{AuthorizedRegister, ReceiptRegister, RegisterPage, SyntheticSale};
use SFM4U\Inventory\RequestJournal;

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback fixture required.');
}
require_once '/modules/b2b-pro/bootstrap.php';
function profitCheck(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_price_num_decimals', 2);
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_feature_cost_of_goods_sold_enabled', 'yes');
$make = static function (string $name, string $price, ?float $buy): WC_Product_Simple {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_regular_price($price);
    $product->set_manage_stock(true);
    $product->set_stock_quantity(50);
    $product->set_cogs_value($buy);
    $product->update_meta_data('_sfm4u_pos_merchant_id', 42);
    $product->save();
    Commerce::saveRule($product, ['minor' => 12500, 'buyer' => 'approved', 'min' => 2, 'tiers' => [4 => 10000]]);
    return $product;
};
wp_set_current_user(1);
$first = $make('Synthetic profit one', '150.00', 75.0);
$second = $make('Synthetic profit two', '200.00', null);
$buyer = wp_create_user('profit-buyer-' . wp_generate_uuid4(), wp_generate_password());
$outsider = wp_create_user('profit-other-' . wp_generate_uuid4(), wp_generate_password());
update_user_meta($buyer, '_sfmwh_buyer_status', 'approved');
update_user_meta($outsider, '_sfmwh_buyer_status', 'approved');
wp_set_current_user($buyer);
wc_load_cart();
WC()->cart->add_to_cart($first->get_id(), 4);
WC()->cart->add_to_cart($second->get_id(), 2);
WC()->cart->calculate_totals();
$rows = ProfitCard::cartRows();
profitCheck(count($rows) === 2 && $rows[0]['cost'] === 40000 && $rows[0]['discount'] === 20000
    && $rows[0]['profit'] === 20000, 'cart uses current wholesale tier buy cost and catalogue saving');
profitCheck(ProfitCard::totals($rows) === ['cost' => 65000, 'suggestion' => 100000,
    'used' => 100000, 'discount' => 35000, 'profit' => 35000], 'cart totals reconcile integer Taka values');
ProfitCard::saveOverride($first->get_id(), '170.00');
profitCheck(ProfitCard::cartRows()[0]['profit'] === 28000 && (float) WC()->cart->get_subtotal() === 650.0,
    'private override changes estimated profit without changing checkout price');
ob_start();
ProfitCard::renderCart();
$html = ob_get_clean();
profitCheck(str_contains($html, '৳280.00') && str_contains($html, 'sfmwh_retail[' . $first->get_id()), 'cart renders the line and override form');
wp_set_current_user($outsider);
profitCheck(ProfitCard::override($first->get_id()) === null, 'overrides are scoped to the current account');
wp_set_current_user(0);
ob_start();
ProfitCard::renderCart();
profitCheck(ProfitCard::cartRows() === [] && ob_get_clean() === '', 'guest receives no profit markup');
wp_set_current_user(1);
(new WP_User(1))->add_cap('sfm4u_pos_cashier');
wp_set_current_user(0);
wp_set_current_user(1);
update_user_meta(1, '_sfm4u_pos_registers', [42 => ['R1' => 'C1']]);
$journal = new RequestJournal($GLOBALS['wpdb']);
$archive = new ReceiptRegister($GLOBALS['wpdb']);
$service = new AuthorizedRegister($journal, $archive, new SyntheticSale($journal, $archive));
$page = new RegisterPage($service);
$first->set_sale_price('140.00');
$first->save();
$_POST = ['_wpnonce' => wp_create_nonce('sfm4u_pos_register'), 'register_action' => 'profit',
    'merchant' => '42', 'register' => 'R1', 'product' => (string) $first->get_id(), 'quantity' => '2'];
$row = $page->submit()['rows'][0];
profitCheck($row['cost'] === 15000 && $row['suggestion'] === 30000 && $row['discount'] === 2000
    && $row['profit'] === 13000, 'POS preview reads stored buy cost and retail discount');
$_POST['save_profit_override'] = 'yes';
$_POST['profit_override'] = '180.00';
profitCheck($page->submit()['rows'][0]['profit'] === 21000 && (float) wc_get_product($first->get_id())->get_price() === 140.0,
    'POS override changes the estimate without changing charged retail price');
unset($_POST['save_profit_override']);
require_once ABSPATH . 'wp-admin/includes/template.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
ob_start();
$page->render();
$registerHtml = ob_get_clean();
profitCheck(str_contains($registerHtml, '৳210.00') && str_contains($registerHtml, 'name="profit_override"'),
    'POS renders the estimate and keeps the override form available');
$_POST['product'] = (string) $second->get_id();
$unknown = $page->submit()['rows'][0];
profitCheck($unknown['profit'] === null && ProfitCard::totals([$row, $unknown])['profit'] === null,
    'missing cost stays unavailable in the line and combined total');
$_POST['merchant'] = '43';
try {
    $page->submit();
    throw new RuntimeException('Unassigned register exposed profit.');
} catch (DomainException $error) {
    profitCheck($error->getCode() === 403, 'unassigned register cannot retrieve price and cost');
}
