<?php
use SFM4U\Inventory\DailyClose;
use SFM4U\POS\{AuthorizedRegister, RegisterSessions, SyntheticSale};
use SFM4U\Wholesale\Pro\Settlement;

$sessions = new RegisterSessions($wpdb);
$cashPos = new AuthorizedRegister($posJournal, $register, new SyntheticSale($posJournal, $register), $sessions);
$daily = new DailyClose($wpdb, new Settlement($wpdb), $ap, $sessions);
$staff = wp_create_user('drawer-cashier-' . $tag, wp_generate_password());
(new WP_User($staff))->add_cap('sfm4u_pos_cashier');
update_user_meta($staff, '_sfm4u_pos_registers', [$merchant=>['R1'=>'C2']]);
$rejectDrawer = static function (int $status, callable $action, string $label): void {
    try {
        $action();
    } catch (DomainException $error) {
        check($error->getCode() === $status, $label);
        return;
    }
    check(false, $label);
};
wp_set_current_user($vendor);
$rejectDrawer(403, fn() => $cashPos->drawer($merchant, 'R1', 'status'), 'drawer is scoped to assigned POS staff');
wp_set_current_user($staff);
$openKey = wp_generate_uuid4();
$opened = $cashPos->drawer($merchant, 'R1', 'open', $openKey, 0, 10000);
$id = $opened['session_id'];
check($cashPos->drawer($merchant, 'R1', 'open', $openKey, 0, 10000) === $opened, 'opening float request replays exactly');
$rejectDrawer(409, fn() => $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 10000),
    'only one open session per register');
$rejectDrawer(422, fn() => $cashPos->drawer($merchant, 'R1', 'out', wp_generate_uuid4(), $id, 500, 'change_float'),
    'direction-specific reason codes are enforced');
$inKey = wp_generate_uuid4();
$cashIn = $cashPos->drawer($merchant, 'R1', 'in', $inKey, $id, 2000, 'change_float');
check($cashIn['actor_id'] === $staff && $cashIn['reason'] === 'change_float'
    && $cashPos->drawer($merchant, 'R1', 'in', $inKey, $id, 2000, 'change_float') === $cashIn, 'cash in audit and retry');
$rejectDrawer(409, fn() => $cashPos->drawer($merchant, 'R1', 'in', $inKey, $id, 2100, 'change_float'),
    'same drawer key rejects a changed amount');
$cashPos->drawer($merchant, 'R1', 'out', wp_generate_uuid4(), $id, 500, 'bank_drop');

wp_set_current_user(1);
$drawerPo = $purchaseOrders->issue(wp_generate_uuid4(), $supplier, [['product'=>$pid, 'quantity'=>2, 'unit_minor'=>1000]]);
$purchaseOrders->receive(wp_generate_uuid4(), $drawerPo['id'], [['product'=>$pid, 'quantity'=>2, 'landed_minor'=>0]]);
check($daily->report($today, $merchant, 'R1')['sessions_open_at_cutoff'] === 1, 'daily close identifies unclosed register session');
wp_set_current_user($staff);
$sessionSaleKey = wp_generate_uuid4();
$drawerSale = $cashPos->sale($merchant, 'R1', $sessionSaleKey, $pid, 2, 4000, $id);
check($drawerSale['sale']['session_id'] === $id, 'POS cash sale is linked to the active session');
wp_set_current_user($cashier);
$drawerCredit = $cashPos->credit($merchant, 'R1', wp_generate_uuid4(), $drawerSale['order_id'], 1, false, $id);
check($drawerCredit['sale']['session_id'] === $id && $drawerCredit['sale']['refund_minor'] === 2000
    && (int) wc_get_product($pid)->get_stock_quantity() === 0, 'POS cash credit is linked and damaged stock stays out');

wp_set_current_user($staff);
$rejectDrawer(403, fn() => $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $id, 13400, '', 'Count verified'),
    'cashier cannot approve a drawer difference');
wp_set_current_user($cashier);
$rejectDrawer(403, fn() => $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $id, 13400),
    'manager must document approval for a drawer difference');
$zKey = wp_generate_uuid4();
$interrupt = static function (string $action): void {
    if ($action === 'close') throw new RuntimeException('synthetic Z rollback');
};
add_action('sfm4u_pos_drawer_before_commit', $interrupt);
try {
    $cashPos->drawer($merchant, 'R1', 'close', $zKey, $id, 13400, '', 'Count verified');
    check(false, 'forced Z rollback');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'synthetic Z rollback' && $cashPos->drawer($merchant, 'R1', 'status')['last_z'] === null
        && $cashPos->drawer($merchant, 'R1', 'status')['session_id'] === $id, 'failed close leaves drawer open and no Z-report');
} finally {
    remove_action('sfm4u_pos_drawer_before_commit', $interrupt);
}
$z = $cashPos->drawer($merchant, 'R1', 'close', $zKey, $id, 13400, '', 'Count verified');
check($z['number'] === 'R1-Z-000001' && $z['cash_sales_minor'] === 4000 && $z['cash_returns_minor'] === 2000
    && $z['cash_in_minor'] === 2000 && $z['cash_out_minor'] === 500 && $z['expected_cash_minor'] === 13500
    && $z['counted_minor'] === 13400 && $z['variance_minor'] === -100 && $z['approval_by'] === $cashier,
    'Z reconciles BDT 100 + 40 - 20 + 20 - 5 = 135; BDT 1 variance approved');
check($cashPos->drawer($merchant, 'R1', 'close', $zKey, $id, 13400, '', 'Count verified') === $z
    && $cashPos->drawer($merchant, 'R1', 'status')['last_z'] === $z, 'Z snapshot survives retries and readback');
$rejectDrawer(409, fn() => $cashPos->credit($merchant, 'R1', wp_generate_uuid4(), $drawerSale['order_id'], 1),
    'tracked register blocks new cash credits while closed');

wp_set_current_user($staff);
check($cashPos->sale($merchant, 'R1', $sessionSaleKey, $pid, 2, 4000, $id) === $drawerSale,
    'closed drawer still allows committed sale retry');
$second = $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 5000)['session_id'];
$rejectDrawer(409, fn() => $cashPos->drawer($merchant, 'R1', 'out', wp_generate_uuid4(), $id, 100, 'bank_drop'),
    'delayed cash transfer cannot hit the next session');
$rejectDrawer(409, fn() => $cashPos->sale($merchant, 'R1', wp_generate_uuid4(), $pid, 1, 2000, $id),
    'delayed sale cannot hit the next session');
$z2 = $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $second, 5000);
check($z2['number'] === 'R1-Z-000002' && $z2['approval_by'] === null, 'zero variance cashier close gets next Z number');

wp_set_current_user(1);
$persistedClose = $daily->report($today, $merchant, 'R1', 1, 1);
check($persistedClose['z_reports'] === [$z, $z2] && $persistedClose['sessions_open_at_cutoff'] === 0
    && $persistedClose['session_ledger_mismatches'] === 0 && $persistedClose['expected_cash_minor'] === 18500
    && $persistedClose['counted_minor'] === 18400 && $persistedClose['variance_minor'] === -100,
    'daily close derives BDT 185 expected and BDT 184 counted from two reconciled Z sessions');
check($persistedClose['sales_minor'] - $report['sales_minor'] === 4000
    && $persistedClose['returns_minor'] - $report['returns_minor'] === 2000
    && $persistedClose['net_cogs_micros'] - $report['net_cogs_micros'] === 20000000
    && $persistedClose['gross_profit_micros'] === $report['gross_profit_micros']
    && $persistedClose['ar_aging'] === $report['ar_aging'] && $persistedClose['ap_aging'] === $report['ap_aging'],
    'daily sales, returns, COGS, GP and AR/AP reconcile after drawer movements');
$_GET = ['merchant'=>(string) $merchant, 'register'=>'R1', 'day'=>$today];
ob_start();
$daily->render();
$sessionHtml = ob_get_clean();
$_GET = [];
check(str_contains($sessionHtml, 'R1-Z-000002') && str_contains($sessionHtml, '185.00'),
    'Daily Close admin displays saved Z numbers and drawer totals');

$roundingPo = $purchaseOrders->issue(wp_generate_uuid4(), $supplier, [['product'=>$pid, 'quantity'=>2, 'unit_minor'=>1000]]);
$purchaseOrders->receive(wp_generate_uuid4(), $roundingPo['id'], [['product'=>$pid, 'quantity'=>2, 'landed_minor'=>0]]);
$roundingProduct = wc_get_product($pid);
$roundingProduct->set_regular_price('89.60');
$roundingProduct->save();
$roundingOption = \SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION;
delete_option($roundingOption);
wp_set_current_user($staff);
$roundingSession = $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 0)['session_id'];
$roundedKey = wp_generate_uuid4();
$rounded = $cashPos->sale($merchant, 'R1', $roundedKey, $pid, 1, 10000, $roundingSession);
check($rounded['sale']['total_minor'] === 9000 && $rounded['sale']['rounding_minor'] === 40
    && $rounded['sale']['change_minor'] === 1000, 'BDT 89.60 cash sale rounds to BDT 90 with BDT 0.40 adjustment');
update_option($roundingOption, '1');
check($cashPos->sale($merchant, 'R1', $roundedKey, $pid, 1, 10000, $roundingSession) === $rounded,
    'cash rounding settings never change a committed receipt on retry');
$exact = $cashPos->sale($merchant, 'R1', wp_generate_uuid4(), $pid, 1, 10000, $roundingSession);
check($exact['sale']['total_minor'] === 8960 && $exact['sale']['rounding_minor'] === 0,
    'exact-cash denomination disables rounding for the next sale');
delete_option($roundingOption);
$roundedZ = $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $roundingSession, 17960);
wp_set_current_user(1);
$roundedClose = $daily->report($today, $merchant, 'R1');
check($roundedZ['expected_cash_minor'] === 17960 && $roundedClose['cash_sale_rounding_minor'] === 40
    && $roundedClose['session_ledger_mismatches'] === 0, 'daily close reports rounding separately without adding it to cash twice');
wp_set_current_user($cashier);
$refundSession = $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 10000)['session_id'];
update_option($roundingOption, '1');
$roundedRefund = $cashPos->credit($merchant, 'R1', wp_generate_uuid4(), $rounded['order_id'], 1, true, $refundSession);
delete_option($roundingOption);
check($roundedRefund['sale']['refund_minor'] === 9000 && $roundedRefund['sale']['rounding_minor'] === 40
    && $roundedRefund['sale']['remaining_minor'] === 0 && (int) wc_get_product($pid)->get_stock_quantity() === 1,
    'full cash return reverses the original rounding and restocks exactly once');
$refundZ = $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $refundSession, 1000);
wp_set_current_user(1);
$refundClose = $daily->report($today, $merchant, 'R1');
check($refundZ['expected_cash_minor'] === 1000 && $refundClose['cash_return_rounding_minor'] === 40,
    'daily close shows original refund rounding and reconciles returned cash');

$partialPo = $purchaseOrders->issue(wp_generate_uuid4(), $supplier, [['product'=>$pid, 'quantity'=>1, 'unit_minor'=>1000]]);
$purchaseOrders->receive(wp_generate_uuid4(), $partialPo['id'], [['product'=>$pid, 'quantity'=>1, 'landed_minor'=>0]]);
$partialProduct = wc_get_product($pid);
$partialProduct->set_regular_price('10.20');
$partialProduct->save();
wp_set_current_user($cashier);
$partialSession = $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 0)['session_id'];
$roundedDown = $cashPos->sale($merchant, 'R1', wp_generate_uuid4(), $pid, 2, 2000, $partialSession);
$firstKey = wp_generate_uuid4();
$firstReturn = $cashPos->credit($merchant, 'R1', $firstKey, $roundedDown['order_id'], 1, true, $partialSession);
$secondReturn = $cashPos->credit($merchant, 'R1', wp_generate_uuid4(), $roundedDown['order_id'], 1, true, $partialSession);
check($roundedDown['sale']['rounding_minor'] === -40 && $firstReturn['sale']['rounding_minor'] === -20
    && $secondReturn['sale']['rounding_minor'] === -20 && $secondReturn['sale']['remaining_minor'] === 0
    && $firstReturn['sale']['refund_minor'] + $secondReturn['sale']['refund_minor'] === 2000
    && $cashPos->credit($merchant, 'R1', $firstKey, $roundedDown['order_id'], 1, true, $partialSession) === $firstReturn
    && (int) wc_get_product($pid)->get_stock_quantity() === 2,
    'rounding down is prorated across partial credits without duplicate cash or restock');
$partialZ = $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $partialSession, 0);
check($partialZ['expected_cash_minor'] === 0, 'rounded sale and partial returns reconcile the cash drawer');

$expenseSession = $cashPos->drawer($merchant, 'R1', 'open', wp_generate_uuid4(), 0, 10000)['session_id'];
$expenseKey = wp_generate_uuid4();
$rejectDrawer(422, fn() => $cashPos->drawer($merchant, 'R1', 'out', $expenseKey, $expenseSession, 1200, 'operating_expense'),
    'drawer expense requires an audit memo');
$expense = $cashPos->drawer($merchant, 'R1', 'out', $expenseKey, $expenseSession, 1200, 'operating_expense', '', 'Synthetic packaging supplies');
check($expense['expense_memo'] === 'Synthetic packaging supplies' && $expense['session_id'] === $expenseSession
    && $cashPos->drawer($merchant, 'R1', 'out', $expenseKey, $expenseSession, 1200, 'operating_expense', '', 'Synthetic packaging supplies') === $expense,
    'drawer expense preserves session and memo on retry');
$rejectDrawer(409, fn() => $cashPos->drawer($merchant, 'R1', 'out', $expenseKey, $expenseSession, 1200, 'operating_expense', '', 'Changed memo'),
    'drawer expense key rejects a changed memo');
$expenseZ = $cashPos->drawer($merchant, 'R1', 'close', wp_generate_uuid4(), $expenseSession, 8800);
check($expenseZ['cash_out_minor'] === 1200 && $expenseZ['cash_expense_minor'] === 1200 && $expenseZ['expected_cash_minor'] === 8800,
    'BDT 12 expense reduces the BDT 100 drawer by exactly BDT 12');
wp_set_current_user(1);
$expenseClose = $daily->report($today, $merchant, 'R1');
check($expenseClose['cash_expense_minor'] === 1200 && $expenseClose['session_cash_expense_minor'] === 1200
    && $expenseClose['session_ledger_mismatches'] === 0, 'Daily Close shows operating expense separately and reconciles all Z reports');
