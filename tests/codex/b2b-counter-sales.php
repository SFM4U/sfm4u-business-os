<?php
declare(strict_types=1);

use SFM4U\POS\{AuthorizedRegister, ReceiptRegister, RegisterSessions, SyntheticSale};
use SFM4U\Inventory\RequestJournal;

wp_set_current_user(1);
$counterMerchant = 919001;
$counterRegister = 'NIGHT';
$counterUser = get_userdata($staff);
$priorCounterAssignments = (array) get_user_meta($staff, '_sfm4u_pos_registers', true);
update_user_meta($staff, '_sfm4u_pos_registers', [$counterMerchant => [$counterRegister => 'C1']] + $priorCounterAssignments);
$counterUser->add_cap('sfm4u_pos_cashier');
$cartLines = [];
foreach ([2500, 3000] as $amount) {
    $counterProduct = new WC_Product_Simple();
    $counterProduct->set_name('Synthetic POS product ' . $amount);
    $counterProduct->set_sku('SYN-POS-' . $amount);
    $counterProduct->set_status('publish');
    $counterProduct->set_manage_stock(true);
    $counterProduct->set_stock_quantity(0);
    $counterProduct->set_regular_price((string) ($amount / 100));
    $counterProduct->update_meta_data('_sfm4u_pos_merchant_id', $counterMerchant);
    $counterProductId = $counterProduct->save();
    $counterPo = $purchase->issue(wp_generate_uuid4(), 'Synthetic POS supplier', [['product' => $counterProductId, 'quantity' => 4, 'unit_minor' => 1000]]);
    $purchase->receive(wp_generate_uuid4(), $counterPo['id'], [['product' => $counterProductId, 'quantity' => 4, 'landed_minor' => 0]]);
    $cartLines[] = ['product' => $counterProductId, 'quantity' => 2];
}
$counterJournal = new RequestJournal($wpdb);
$counterReceipts = new ReceiptRegister($wpdb);
$counter = new AuthorizedRegister($counterJournal, $counterReceipts, new SyntheticSale($counterJournal, $counterReceipts), new RegisterSessions($wpdb));
wp_set_current_user($staff);
$counterSession = $counter->drawer($counterMerchant, $counterRegister, 'open', wp_generate_uuid4(), 0, 5000)['session_id'];
$counterCart = ['lines' => $cartLines, 'tier' => 'retail', 'method' => 'bkash', 'discount' => 1000];
$counterQuote = $counter->counter($counterMerchant, $counterRegister, '', $counterCart, false);
check($counterQuote['total_minor'] === 10000, 'Counter cart quotes two products with exact discount');
$counterKey = wp_generate_uuid4();
$counterSale = $counterCart + ['session' => $counterSession, 'digest' => $counterQuote['digest'], 'tender' => 10000];
try {
    $counter->counter($counterMerchant, $counterRegister, wp_generate_uuid4(), ['digest' => str_repeat('0', 64)] + $counterSale, true);
    check(false, 'stale offline cart must fail');
} catch (DomainException $error) {
    check($error->getCode() === 409, 'Changed quote is rejected without creating an order');
}
$counterReceipt = $counter->counter($counterMerchant, $counterRegister, $counterKey, $counterSale, true);
$counterOrder = wc_get_order($counterReceipt['order_id']);
check($counterReceipt === $counter->counter($counterMerchant, $counterRegister, $counterKey, $counterSale, true)
    && count($counterOrder->get_items()) === 2 && $counterOrder->get_payment_method() === 'sfm4u_counter_bkash'
    && $counterOrder->has_status('on-hold'), 'Counter creates one Woo order and replays the identical method-only receipt');
check(array_map(static fn(array $line): int => (int) wc_get_product($line['product'])->get_stock_quantity(), $cartLines) === [2, 2],
    'Two product stock quantities decrease through the Woo order exactly once');
check($counter->drawer($counterMerchant, $counterRegister, 'day')['cash_sales_minor'] === 0
    && $counter->drawer($counterMerchant, $counterRegister, 'close', wp_generate_uuid4(), $counterSession, 5000)['variance_minor'] === 0,
    'Non-cash method leaves daily cash and close-out cash unchanged');
check(str_contains($counter->invoice($counterMerchant, $counterRegister, $counterOrder->get_id(), 'thermal58')['styles'], '58mm'),
    'Counter receipt returns scoped 58 mm print layout');
update_user_meta($staff, '_sfm4u_pos_registers', $priorCounterAssignments);
wp_set_current_user(1);
