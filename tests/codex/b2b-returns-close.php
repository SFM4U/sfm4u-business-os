<?php
use SFM4U\Inventory\DailyClose;
use SFM4U\Wholesale\Pro\Settlement;

wp_set_current_user(1);
$close = new DailyClose($wpdb, new Settlement($wpdb), $ap);
$opening = 10000;
$baseline = $close->report($today, $merchant, 'R1', $opening, 26000);
check($baseline['expected_cash_minor'] === 26000, 'daily cash opening plus ledger POS sales');
(new WP_User($cashier))->add_cap('sfm4u_pos_credit');
wp_set_current_user($cashier);
$posReturnKey = wp_generate_uuid4();
$beforeFailure = $valuation->totals($pid);
$forceFailure = static function (): void { throw new RuntimeException('synthetic credit rollback'); };
add_action('sfm4u_synthetic_credit_before_commit', $forceFailure);
try {
    $pos->credit($merchant, 'R1', $posReturnKey, $posReceipt['order_id'], 1, true);
    check(false, 'forced POS credit failure');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'synthetic credit rollback' && $valuation->totals($pid) === $beforeFailure,
        'failed POS credit rolls back stock and valuation');
} finally {
    remove_action('sfm4u_synthetic_credit_before_commit', $forceFailure);
}
$posCredit = $pos->credit($merchant, 'R1', $posReturnKey, $posReceipt['order_id'], 1, true);
check($posCredit['number'] === 'R1-000003' && $posCredit['sale']['refund_minor'] === 2000
    && $valuation->totals($pid)['stock_micros'] === 10000000, 'POS resellable credit reuses next number and original cost');
check($pos->credit($merchant, 'R1', $posReturnKey, $posReceipt['order_id'], 1, true) === $posCredit, 'POS credit exact replay');
check($pos->reprint($merchant, 'R1', $posCredit['order_id']) === ['kind'=>'COPY','receipt'=>$posCredit], 'POS credit copy preserves document');
$damaged = $pos->credit($merchant, 'R1', wp_generate_uuid4(), $posReceipt['order_id'], 1, false);
check($damaged['number'] === 'R1-000004' && $damaged['sale']['resellable'] === false
    && $valuation->totals($pid)['quantity'] === 1, 'POS damaged return issues refund without restocking');

$wcLine = array_key_first(wc_get_order($wholesaleId)->get_items());
$creditArgs = ['buyer'=>$financeBuyer, 'order'=>$wholesaleId, 'line'=>$wcLine, 'quantity'=>1, 'resellable'=>true];
check(error_code(call_api($financeBuyer, 'credit', $creditArgs, 'finance_credit_denied_' . $tag), 'forbidden'), 'NET30 credit requires staff capability');
$firstNetKey = 'finance_credit_paid_' . $tag;
$priorNetSerial = (int) $wpdb->get_var($wpdb->prepare('SELECT last_number FROM %i WHERE merchant=%d AND register_code=%s',
    $wpdb->prefix . 'sfm4u_pos_receipt_counters', get_current_blog_id(), 'NETCN'));
$forceFailure = static function (array $document): void {
    if ($document['register'] === 'NETCN') {
        throw new RuntimeException('synthetic NET30 credit rollback');
    }
};
add_action('sfm4u_synthetic_credit_before_commit', $forceFailure);
$failed = call_api($manager, 'credit', $creditArgs, $firstNetKey);
remove_action('sfm4u_synthetic_credit_before_commit', $forceFailure);
check(error_code($failed, 'database_unavailable') && $valuation->totals($pid)['quantity'] === 1
    && $quotes->statement($financeBuyer)['balance_minor'] === 0, 'failed NET30 credit rolls back stock and AR');
$paidCredit = call_api($manager, 'credit', $creditArgs, $firstNetKey)->get_data();
check($paidCredit['sale']['refund_minor'] === 1500 && $paidCredit['sale']['ar_minor'] === 0
    && $valuation->totals($pid)['stock_micros'] === 20000000, 'paid NET30 credit records refund and original cost restock');
check(call_api($manager, 'credit', $creditArgs, $firstNetKey)->get_data() === $paidCredit, 'NET30 credit exact replay');
$sequence = (int) substr($paidCredit['number'], strlen('NETCN-'));
check($sequence === $priorNetSerial + 1, 'failed NET30 credit leaves no serial gap');

$line = ['sku'=>$sku, 'qty'=>2];
$rfq = call_api($financeBuyer, 'submit', ['lines'=>[$line]], 'close_submit_' . $tag)->get_data();
$quoteId = ['id'=>$rfq['id']];
call_api($vendor, 'offer', $quoteId + ['lines'=>[$line + ['unit_price_minor'=>1500]], 'valid_until'=>time()+3600], 'close_offer_' . $tag);
call_api($financeBuyer, 'accept', $quoteId, 'close_accept_' . $tag);
$returnOrder = call_api($financeBuyer, 'convert', $quoteId, 'close_convert_' . $tag)->get_data();
$returnOrderId = (int) $returnOrder['order_id'];
call_api($manager, 'fulfill', ['buyer'=>$financeBuyer, 'order'=>$returnOrderId], 'close_fulfill_' . $tag);
call_api($manager, 'settle', ['buyer'=>$financeBuyer, 'amount_minor'=>2000, 'reference'=>'CLOSE-PAY-' . $tag], 'close_settle_' . $tag);
check($quotes->statement($financeBuyer)['balance_minor'] === 1000, 'second NET30 invoice has BDT 10 unpaid');
$openCreditArgs = ['buyer'=>$financeBuyer, 'order'=>$returnOrderId, 'line'=>array_key_first(wc_get_order($returnOrderId)->get_items()),
    'quantity'=>1, 'resellable'=>false];
$secondCredit = call_api($manager, 'credit', $openCreditArgs, 'close_open_credit_' . $tag)->get_data();
check((int) substr($secondCredit['number'], strlen('NETCN-')) === $sequence + 1 && $secondCredit['sale']['ar_minor'] === 1000
    && $secondCredit['sale']['refund_minor'] === 500 && $quotes->statement($financeBuyer)['balance_minor'] === 0
    && $valuation->totals($pid)['stock_micros'] === 0, 'damaged NET30 credit clears BDT 10 AR and records BDT 5 refund without restock');
check(error_code(call_api($manager, 'credit', array_replace($openCreditArgs, ['quantity'=>2]),
    'close_over_credit_' . $tag), 'credit_unavailable'), 'NET30 over-return is rejected');
wp_set_current_user(1);
check((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM %i WHERE merchant=%d AND register_code=%s AND sequence_number IN (%d,%d)',
    $wpdb->prefix . 'sfm4u_pos_receipts', get_current_blog_id(), 'NETCN', $sequence, $sequence+1
)) === 2, 'NET30 credit notes have consecutive committed serials');

$report = $close->report($today, $merchant, 'R1', $opening, 22000);
$deltas = ['sales_minor'=>3000, 'returns_minor'=>7000, 'sale_cogs_micros'=>20000000, 'reversed_cogs_micros'=>40000000,
    'writeoff_micros'=>20000000, 'net_cogs_micros'=>0, 'gross_profit_micros'=>-40000000,
    'ar_credit_minor'=>1000, 'net30_refund_minor'=>2000, 'coverage_gaps'=>0];
foreach ($deltas as $field => $expectedDelta) {
    check($report[$field] - $baseline[$field] === $expectedDelta, 'daily close reconciles ' . $field);
}
check($report['cash_returns_minor'] === 4000 && $report['expected_cash_minor'] === 22000 && $report['variance_minor'] === 0,
    'cash drawer reconciles opening BDT 100 plus cash sales BDT 160 minus refunds BDT 40');
check($report['ar_aging'] === $baseline['ar_aging'] && $report['ap_aging'] === $baseline['ap_aging'],
    'daily close AR/AP aging agrees after settlement and credit');
$_GET = ['merchant'=>(string) $merchant, 'register'=>'R1', 'opening'=>'10000', 'counted'=>'22000', 'day'=>$today];
ob_start();
$close->render();
$closeHtml = ob_get_clean();
$_GET = [];
check(str_contains($closeHtml, 'Ledger totals') && str_contains($closeHtml, '220.00'), 'daily close admin displays reconciled cash');
