<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    exit(1);
}
require '/modules/inventory-bridge/src/MovingAverage.php';

use SFM4U\Inventory\MovingAverage;
use SFM4U\Wholesale\Commerce;

function exact($expected, $actual, string $name): void {
    if ($expected !== $actual) {
        throw new RuntimeException($name . ': ' . var_export($actual, true));
    }
    echo "PASS $name\n";
}
function minor($value): int {
    return (int) str_replace('.', '', wc_format_decimal($value, 2));
}

add_filter('pre_wp_mail', '__return_true');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_price_num_decimals', 2);
update_option('sfmwh_enabled', 'yes');
wc_load_cart();
WC()->cart->empty_cart();

$pool = new MovingAverage();
$movements = [];
$sku = new WC_Product_Simple();
$sku->set_name('WATER-500 Synthetic');
$sku->set_sku('WATER-500-' . wp_generate_uuid4());
$sku->set_regular_price('30.00');
$sku->set_manage_stock(true);
$sku->set_stock_quantity(0);
$sku->save();
Commerce::saveRule($sku, ['minor' => 2750, 'min' => 12, 'step' => 12,
    'buyer' => 'role:customer', 'packs' => ['case12' => 12], 'pack_minor' => ['case12' => 30000]]);

// The synthetic PO/shift setup records only the fixture inputs. Production ledgers are a later gate.
$purchaseMinor = 10 * 24000;
$freightMinor = 12000;
$cashEvents = [100000, -$freightMinor];
$pool->receive(120, $purchaseMinor * 10000, $freightMinor * 10000);
wc_update_product_stock($sku, 120, 'increase');
$movements[] = 120;
exact(21000000, $pool->state()['average_micros'], 'landed receipt sets BDT 21.000000 average');

$buyer = wp_insert_user(['user_login' => 'retailer-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'customer']);
wp_set_current_user($buyer);
$_POST['sfmwh_pack'] = 'case12';
$key = WC()->cart->add_to_cart($sku->get_id(), 24);
unset($_POST['sfmwh_pack']);
if (!$key) {
    throw new RuntimeException('Wholesale cart did not add the 24 stock units.');
}
WC()->cart->calculate_totals();
$web = wc_create_order(['customer_id' => $buyer, 'created_via' => 'checkout']);
$web->set_currency('BDT');
WC()->checkout()->create_order_line_items($web, WC()->cart);
$web->calculate_totals();
$webLine = array_values($web->get_items())[0];
$sale = $pool->sell((int) $webLine->get_quantity());
$webLine->add_meta_data('_sfmwh_g0_cogs', $sale, true);
$webLine->save();
wc_reserve_stock_for_order($web);
$web->update_status('processing');
wc_release_stock_for_order($web);
$movements[] = -24;
exact(504000000, $sale['cogs_micros'], 'web sale snapshots BDT 504.00 COGS');

$posFixture = new class($pool, $sku, $movements, $cashEvents) {
    private array $requests = [];
    private array $printed = [];

    public function __construct(
        private MovingAverage $pool, private WC_Product $sku, private array &$movements, private array &$cashEvents
    ) {}

    public function sale(string $id, int $quantity, int $tender): array {
        $intent = [$quantity, $tender];
        if (isset($this->requests[$id])) {
            if ($this->requests[$id]['intent'] !== $intent) {
                throw new DomainException('Replay conflict', 409);
            }
            return $this->requests[$id]['result'];
        }
        if ($quantity > (int) wc_get_product($this->sku->get_id())->get_stock_quantity()) {
            throw new DomainException('Stock conflict', 409);
        }
        $pos = wc_create_order(['created_via' => 'sfm4u-pos-spike']);
        $pos->set_currency('BDT');
        $line = $pos->add_product($this->sku, $quantity);
        $pos->calculate_totals();
        $amount = minor($pos->get_total());
        wc_reserve_stock_for_order($pos);
        $cost = $this->pool->sell($quantity);
        $item = $pos->get_item($line);
        $item->add_meta_data('_sfmwh_g0_cogs', $cost, true);
        $item->save();
        $pos->update_status('processing');
        wc_release_stock_for_order($pos);
        $this->movements[] = -$quantity;
        $this->cashEvents[] = $amount;
        $receipt = sprintf('R1-%06d', count($this->requests) + 1);
        $result = ['id' => $pos->get_id(), 'receipt' => $receipt, 'change' => $tender - $amount];
        $this->requests[$id] = compact('intent', 'result');
        return $result;
    }

    public function printLabel(array $result): string {
        $number = $result['receipt'];
        $copy = isset($this->printed[$number]);
        $this->printed[$number] = true;
        return $copy ? 'COPY ' . $number : $number;
    }
};

$counter = $posFixture->sale('synthetic-request', 3, 10000);
exact(1000, $counter['change'], 'counter gives BDT 10 change');
exact('R1-000001', $posFixture->printLabel($counter), 'original receipt identifier');
exact('COPY R1-000001', $posFixture->printLabel($counter), 'reprint preserves receipt identifier');
exact($counter, $posFixture->sale('synthetic-request', 3, 10000), 'same-process retry returns same order and receipt');
try {
    $posFixture->sale('synthetic-request', 4, 10000);
    throw new RuntimeException('Replay with a different payload was accepted.');
} catch (DomainException $error) {
    exact(409, $error->getCode(), 'changed same-process replay conflicts');
}
$beforeRejected = count(wc_get_orders(['limit' => -1, 'return' => 'ids']));
try {
    $posFixture->sale('synthetic-out-of-stock', 94, 999999);
    throw new RuntimeException('Oversell accepted.');
} catch (DomainException $error) {
    exact(409, $error->getCode(), 'insufficient stock rejected before counter order');
}
exact($beforeRejected, count(wc_get_orders(['limit' => -1, 'return' => 'ids'])), 'stock conflict creates no order');

$refund = wc_create_refund(['order_id' => $web->get_id(), 'amount' => '300.00', 'refund_payment' => false,
    'restock_items' => true, 'line_items' => [$webLine->get_id() => ['qty' => 12, 'refund_total' => '300.00', 'refund_tax' => []]]]);
if (is_wp_error($refund)) {
    throw new RuntimeException($refund->get_error_message());
}
$movements[] = 12;
$reversed = $pool->returnCost($webLine->get_meta('_sfmwh_g0_cogs'), 0, 12, true);
$pos = wc_get_order($counter['id']);
$posLine = array_values($pos->get_items())[0];
$netSales = minor($web->get_total()) + minor($pos->get_total()) - minor($refund->get_amount());
$cogs = intdiv($webLine->get_meta('_sfmwh_g0_cogs')['cogs_micros'] + $posLine->get_meta('_sfmwh_g0_cogs')['cogs_micros'] - $reversed, 10000);
exact(39000, $netSales, 'net sales BDT 390.00');
exact(31500, $cogs, 'net COGS BDT 315.00');
exact(7500, $netSales - $cogs, 'gross profit BDT 75.00');
exact(2205000000, $pool->state()['value_micros'], 'inventory BDT 2,205.00');
exact(105, $pool->state()['quantity'], 'cost pool 105 bottles');
exact(105, (int) wc_get_product($sku->get_id())->get_stock_quantity(), 'native stock 105 bottles');
exact([120, -24, -3, 12], $movements, 'four fixture movements reconcile with Woo stock');
exact(97000, array_sum($cashEvents), 'drawer BDT 970.00');
exact(30000, minor($web->get_total()) - minor($refund->get_amount()), 'fixture receivable BDT 300.00');
exact(240000, $purchaseMinor, 'fixture supplier bill BDT 2,400.00');
