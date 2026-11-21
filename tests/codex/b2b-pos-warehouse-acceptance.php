<?php
// Reconciled stock through cash sale, two stock dispositions, immutable receipt and expense close.
wp_set_current_user(1);
$t15Product = wc_get_product($t15Pid); $t15Product->update_meta_data('_sfm4u_pos_merchant_id', $merchant); $t15Product->save();
$followupRounding = get_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION, '100');
update_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION, '1');
wp_set_current_user($securityCashier);
try {
    $followupOpen = $cashPos->drawer($merchant, 'R2', 'open', wp_generate_uuid4(), 0, 0);
    $followupSid = $followupOpen['session_id'];
    $followupSaleKey = wp_generate_uuid4();
    $followupSale = $cashPos->sale($merchant, 'R2', $followupSaleKey, $t15Pid, 2, 3000, $followupSid);
    check($followupSale === $cashPos->sale($merchant, 'R2', $followupSaleKey, $t15Pid, 2, 3000, $followupSid)
        && $followupSale['sale']['total_minor'] === 2500 && $followupSale['sale']['change_minor'] === 500
        && $valuation->totals($t15Pid)['stock_micros'] === 50000000,
        'reconciled warehouse stock cash sale and receipt replay preserve quantity and COGS');
    $followupCreditKey = wp_generate_uuid4();
    $followupCredit = $cashPos->credit($merchant, 'R2', $followupCreditKey, $followupSale['order_id'], 1, true, $followupSid);
    check($followupCredit === $cashPos->credit($merchant, 'R2', $followupCreditKey, $followupSale['order_id'], 1, true, $followupSid)
        && $followupCredit['sale']['refund_minor'] === 1250 && (int) wc_get_product($t15Pid)->get_stock_quantity() === 6
        && $valuation->totals($t15Pid)['stock_micros'] === 60000000,
        'resellable partial cash refund and replay restore original reconciled cost once');
    $followupDamaged = $cashPos->credit($merchant, 'R2', wp_generate_uuid4(), $followupSale['order_id'], 1, false, $followupSid);
    check($followupDamaged['sale']['refund_minor'] === 1250 && (int) wc_get_product($t15Pid)->get_stock_quantity() === 6
        && $valuation->totals($t15Pid)['stock_micros'] === 60000000,
        'damaged remaining cash return refunds tender without restoring saleable warehouse stock');
    $followupCopy = $cashPos->reprint($merchant, 'R2', $followupSale['order_id']);
    check($followupCopy['kind'] === 'COPY' && $followupCopy['receipt'] === $followupSale,
        'receipt copy remains the original sale snapshot after partial and damaged credits');
    wc_get_order($followupSale['order_id'])->update_status('cancelled');
    check((int) wc_get_product($t15Pid)->get_stock_quantity() === 6 && $valuation->totals($t15Pid)['quantity'] === 6,
        'native cancellation cannot double-restock an already credited POS order');
    $followupZ = $cashPos->drawer($merchant, 'R2', 'close', wp_generate_uuid4(), $followupSid, 0);
    check($followupZ['expected_cash_minor'] === 0 && $followupZ['variance_minor'] === 0,
        'sale and both return dispositions reconcile to a zero-cash drawer close');
    $expenseOpen = $cashPos->drawer($merchant, 'R2', 'open', wp_generate_uuid4(), 0, 1000);
    $expenseKey = wp_generate_uuid4();
    $expenseArgs = [$merchant, 'R2', 'out', $expenseKey, $expenseOpen['session_id'], 250, 'operating_expense', '', 'Synthetic warehouse supplies'];
    $followupExpense = $cashPos->drawer(...$expenseArgs);
    check($followupExpense === $cashPos->drawer(...$expenseArgs), 'warehouse operating expense retry preserves one memo-backed cash posting');
    $expenseZ = $cashPos->drawer($merchant, 'R2', 'close', wp_generate_uuid4(), $expenseOpen['session_id'], 750);
    check($expenseZ['expected_cash_minor'] === 750 && $expenseZ['variance_minor'] === 0, 'warehouse operating expense reconciles expected and counted cash');
    wp_set_current_user(1);
    $followupDay = $daily->report($today, $merchant, 'R2');
    check($followupDay['session_ledger_mismatches'] === 0
        && (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(remaining) FROM %i WHERE product_id=%d',
            $wpdb->prefix . 'sfm4u_inventory_receipt_lots', $t15Pid)) === 6,
        'Daily Close and FIFO receipt lots reconcile after T15, POS returns and warehouse expense');
} finally {
    update_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION, $followupRounding);
    wp_set_current_user(1);
}
