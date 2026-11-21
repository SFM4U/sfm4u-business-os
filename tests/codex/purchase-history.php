<?php
use SFM4U\Wholesale\{Commerce, ProfitCard, PurchaseHistory};

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback fixture required.');
}
function historyCheck(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
add_filter('pre_wp_mail', '__return_true');
update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_price_num_decimals', 2);
update_option('woocommerce_calc_taxes', 'no');
update_option('timezone_string', 'Asia/Dhaka');
$buyer = wp_create_user('history-buyer-' . wp_generate_uuid4(), wp_generate_password());
$other = wp_create_user('history-other-' . wp_generate_uuid4(), wp_generate_password());
update_user_meta($buyer, '_sfmwh_buyer_status', 'approved');
$product = new WC_Product_Simple();
$product->set_name('Synthetic history');
$product->set_regular_price('150');
$product->save();
Commerce::saveRule($product, ['minor' => 10000, 'buyer' => 'approved']);
wp_set_current_user($buyer);
ProfitCard::saveOverride($product->get_id(), '180');
wc_load_cart();
WC()->cart->add_to_cart($product->get_id(), 3);
WC()->cart->calculate_totals();
$createOrder = static function (int $owner, string $date, bool $historical = false) use ($product): WC_Order {
    $order = new WC_Order();
    $order->set_customer_id($owner);
    $order->set_currency('BDT');
    $order->set_date_created(strtotime($date));
    $order->set_status('on-hold');
    if ($historical) {
        $line = new WC_Order_Item_Product();
        $line->set_product($product);
        $line->set_quantity(1);
        $line->set_total('100');
        $line->add_meta_data('_sfmwh_snapshot_v1', ['currency' => 'BDT', 'scale' => 2]);
        $order->add_item($line);
    } else {
        WC()->checkout()->create_order_line_items($order, WC()->cart);
    }
    $order->calculate_totals(false);
    $order->save();
    return $order;
};

$current = $createOrder($buyer, '2026-10-31T18:05:00Z');
$foreign = $createOrder($other, '2026-11-02T00:00:00Z', true);
$oldMonth = $createOrder($buyer, '2026-10-31T17:55:00Z', true);
$child = $createOrder($buyer, '2026-11-02T00:00:00Z', true);
$child->set_parent_id($current->get_id());
$child->save();
$currentLineId = array_key_first($current->get_items());
$report = PurchaseHistory::report('2026-11');
historyCheck(count($report['rows']) === 1 && $report['rows'][0]['order'] === $current->get_id(), 'site-time month and account scope exclude adjacent, foreign and mirrored orders');
historyCheck($report['totals'] === ['cost' => 30000, 'suggested' => 45000, 'retail' => 54000, 'profit' => 24000],
    'checkout snapshots purchase cost, stored suggestion and buyer override');
$product->set_regular_price('999');
$product->save();
ProfitCard::saveOverride($product->get_id(), '999');
historyCheck(PurchaseHistory::report('2026-11') === $report, 'later catalogue and profile changes do not change history');
$refund = wc_create_refund(['order_id' => $current->get_id(), 'amount' => '100.00', 'refund_payment' => false,
    'line_items' => [$currentLineId => ['qty' => 1, 'refund_total' => 100, 'refund_tax' => []]]]);
historyCheck(!is_wp_error($refund) && PurchaseHistory::report('2026-11')['totals']
    === ['cost' => 20000, 'suggested' => 30000, 'retail' => 36000, 'profit' => 16000], 'item refund adjusts cost, retained units and expected profit');
$stream = fopen('php://temp', 'w+');
PurchaseHistory::writeCsv($stream, '2026-11');
rewind($stream);
$csv = iterator_to_array((static function ($file): Generator {
    while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
        yield $row;
    }
})($stream));
fclose($stream);
historyCheck(count($csv) === 3 && $csv[1][0] === (string) $current->get_id()
    && $csv[2] === ['TOTAL', '2026-11', 'BDT', '200.00', '300.00', '360.00', '160.00'], 'CSV matches private report and monthly totals');
$profile = PurchaseHistory::report('2026-10');
historyCheck($profile['rows'][0]['order'] === $oldMonth->get_id() && $profile['totals']['cost'] === 10000
    && $profile['totals']['profit'] === null, 'old orders retain known cost with unavailable retail estimate');
$_GET['purchase_month'] = '2026-11';
ob_start();
PurchaseHistory::render();
$html = ob_get_clean();
historyCheck(str_contains($html, 'Download CSV') && str_contains($html, '160.00')
    && str_contains($html, '_wpnonce='), 'account page renders monthly data and a nonce-protected export link');
wp_set_current_user($other);
historyCheck(PurchaseHistory::report('2026-11')['rows'][0]['order'] === $foreign->get_id(), 'second buyer sees only their own order');
wp_set_current_user(0);
try {
    PurchaseHistory::report('2026-11');
    throw new RuntimeException('Guest unexpectedly read history.');
} catch (DomainException $error) {
    historyCheck($error->getCode() === 403, 'guest cannot read or export purchase history');
}
