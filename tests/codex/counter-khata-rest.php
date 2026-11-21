<?php
define('SFM4U_POS_KHATA_STORES', [763122 => 'test-main', 763123 => 'test-other']);
require __DIR__ . '/counter-member-confirm.php';

$memberClass = \SFM4U\Wholesale\Members\Members::class;
$memberClass::record($business, 0, 'charge', 5000, '', 'Synthetic note excluded from cashier view', 1, 'up', 'test-main');
$memberClass::record($business, 0, 'charge', 9900, '', '', 1, 'up', 'test-other');
$card = $memberClass::memberNo($business);
$summary = $call(['action'=>'member_due','member'=>$card]);
check_member_pos($summary->get_status() === 200 && $summary->get_data()['due_minor'] === 5000
    && !str_contains(wp_json_encode($summary->get_data()), 'Synthetic note'), 'khata summary is scoped and hides notes');
$history = $call(['action'=>'member_due_history','member'=>$card]);
check_member_pos($history->get_status() === 200 && $history->get_data()['days'][0]['closing_minor'] === 5000,
    'khata history uses the same store scope');
$payment = ['action'=>'member_due_payment','member'=>$card,'session'=>$session,'amount'=>6000];
check_member_pos($call($payment, null, $unpaid)->get_status() === 403, 'unassigned member cannot record payment');
$beforeReceipt = $receiptCount();
$beforeStock = $stock();
$key = wp_generate_uuid4();
$interrupt = static function (): void { throw new RuntimeException('Synthetic khata interruption'); };
add_action('sfm4u_pos_due_payment_before_commit', $interrupt);
$failed = $call($payment, $key);
remove_action('sfm4u_pos_due_payment_before_commit', $interrupt);
check_member_pos($failed->get_status() === 503 && $memberClass::balance($business,'test-main') === 5000,
    'interrupted due payment rolls back');
$notifications = 0;
$hook = static function () use (&$notifications): void { ++$notifications; };
add_action('sfm_wr_khata_recorded', $hook);
$paid = $call($payment, $key);
check_member_pos($paid->get_status() === 200 && $paid->get_data()['advance_minor'] === 1000
    && $memberClass::balance($business,'test-main') === -1000, 'due overpayment recorded as shop advance');
check_member_pos($call($payment,$key)->get_data() === $paid->get_data() && $notifications === 1,
    'due payment replay does not notify or append twice');
$payment['amount'] = 7000;
check_member_pos($call($payment,$key)->get_status() === 409, 'changed due payment reusing key conflicts');
check_member_pos($memberClass::balance($business,'test-other') === 9900
    && $receiptCount() === $beforeReceipt && $stock() === $beforeStock, 'due-only payment leaves other shop, stock and sale receipts unchanged');
$report = $call(['action'=>'drawer','drawer_action'=>'xreport','session'=>$session,'amount'=>0]);
check_member_pos($report->get_status() === 200 && $report->get_data()['cash_in_minor'] === 6000,
    'due-only cash is included in X report once');

$memberClass::record($business, 0, 'charge', 6000, '', '', 1, 'up', 'test-main');
$quote = $call($intent($card) + ['action'=>'counter_quote']);
$combined = $saleData($card, $quote->get_data()['digest']);
$combined['due_payment_minor'] = 2000;
$combined['credit_minor'] = 3000;
$combined['tender'] = $quote->get_data()['total_minor'] - 3000 + 2000;
$combinedKey = wp_generate_uuid4();
$before = $stock();
$beforeCount = $receiptCount();
add_action('sfm4u_pos_synthetic_before_commit', $interrupt);
$failedSale = $call($combined, $combinedKey);
remove_action('sfm4u_pos_synthetic_before_commit', $interrupt);
check_member_pos($failedSale->get_status() === 503 && $stock() === $before && $receiptCount() === $beforeCount
    && $memberClass::balance($business, 'test-main') === 5000, 'combined khata sale rolls back stock, receipt and khata');
$committed = $call($combined, $combinedKey);
check_member_pos($committed->get_status() === 200, 'combined sale commits: ' . $committed->get_status());
$receipt = $committed->get_data()['sale'];
check_member_pos($receipt['previous_due_minor'] === 5000 && $receipt['due_payment_minor'] === 2000
    && $receipt['credit_minor'] === 3000 && $receipt['due_minor'] === 6000
    && $memberClass::balance($business, 'test-main') === 6000, 'combined sale receipt and khata agree');
check_member_pos(wc_get_order($committed->get_data()['order_id'])->get_status() === 'on-hold',
    'part-credit sale is not marked fully paid');
check_member_pos($call($combined,$combinedKey)->get_data() === $committed->get_data()
    && $stock() === $before - 1 && $receiptCount() === $beforeCount + 1
    && $memberClass::balance($business,'test-main') === 6000, 'combined replay creates neither duplicate khata nor sale');
$report2 = $call(['action'=>'drawer','drawer_action'=>'xreport','session'=>$session,'amount'=>0]);
check_member_pos($report2->get_data()['cash_sales_minor'] === $report->get_data()['cash_sales_minor'] + $combined['tender'],
    'drawer records only cash collected on combined sale');

wp_set_current_user($cashier);
unload_textdomain('sfm4u-wholesale', true);
check_member_pos(load_textdomain('sfm4u-wholesale', WP_PLUGIN_DIR . '/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo', 'bn_BD'),
    'khata Bangla catalog loads');
ob_start();
\SFM4U\POS\CounterPage::render();
$markup = ob_get_clean();
check_member_pos(str_contains($markup, 'id="sfc-due-history"') && str_contains($markup, 'শুধু বাকি পরিশোধ করুন')
    && str_contains($markup, 'বাকিতে বিক্রি'), 'Bangla counter form renders khata controls');
$html = \SFM4U\POS\InvoiceTemplate::render($committed->get_data(), true);
check_member_pos(str_contains($html, 'আগের বাকি') && str_contains($html, 'আগের বাকি পরিশোধ')
    && str_contains($html, 'বাকিতে বিক্রি') && str_contains($html, 'নতুন বাকি'), 'Bangla sale COPY prints khata summary');
file_put_contents('/fixture-out/c09-bn-counter.html', '<!doctype html><html lang="bn"><meta charset="utf-8">' . $markup);
file_put_contents('/fixture-out/c09-bn-receipt.html', '<!doctype html><html lang="bn"><meta charset="utf-8">' . $html);
