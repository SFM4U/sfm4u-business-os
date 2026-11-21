<?php
// Additional T15 boundary acceptance; synthetic state stays in disposable Playground.
wp_set_current_user(1);
$t15Product = new WC_Product_Simple(); $t15Product->set_name('Synthetic T15 acceptance');
$t15Product->set_regular_price('12.50'); $t15Product->set_manage_stock(true); $t15Product->set_stock_quantity(0); $t15Product->save();
$t15Pid = $t15Product->get_id();
$t15Po = $purchase->issue(wp_generate_uuid4(), 'Synthetic T15 supplier', [['product' => $t15Pid, 'quantity' => 5, 'unit_minor' => 1000]]);
$purchase->receive(wp_generate_uuid4(), $t15Po['id'], [['product' => $t15Pid, 'quantity' => 5, 'landed_minor' => 0]]);
$t15NoChange = $counts->submit(wp_generate_uuid4(), $t15Pid, 5, 5, 'Verified matching physical count');
wp_set_current_user($reviewerId);
$t15NoChange = $counts->approve(wp_generate_uuid4(), $t15NoChange['id']);
check($t15NoChange['status'] === 'approved' && $t15NoChange['value_delta_micros'] === 0
    && $valuation->totals($t15Pid)['stock_micros'] === 50000000, 'T15 zero-variance approval preserves warehouse value');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE product_id=%d',
    $wpdb->prefix . 'sfm4u_inventory_movements', $t15Pid)) === 1, 'T15 zero-variance approval creates no adjustment movement');
wp_set_current_user(1);
wc_update_product_stock($t15Pid, 2, 'increase');
$t15Reconcile = $counts->submitSession(wp_generate_uuid4(), 'reconcile', 'Synthetic T15 shelf',
    [['product' => $t15Pid, 'expected' => 5, 'counted' => 7]], 'Verified pre-existing Woo surplus');
wp_set_current_user($reviewerId);
$t15ApprovalKey = wp_generate_uuid4();
add_action('sfm4u_synthetic_stock_count_before_commit', $forcedFailure);
try { $counts->approve($t15ApprovalKey, $t15Reconcile['id']); check(false, 'T15 reconciliation failure must roll back'); }
catch (RuntimeException $error) {
    check($error->getMessage() === 'forced stock count rollback' && (int) wc_get_product($t15Pid)->get_stock_quantity() === 7
        && $valuation->totals($t15Pid)['quantity'] === 5 && $valuation->totals($t15Pid)['stock_micros'] === 50000000
        && $counts->find($t15Reconcile['id'])['status'] === 'pending',
        'T15 failed reconciliation preserves pre-existing Woo stock and rolls back book value and approval');
} finally { remove_action('sfm4u_synthetic_stock_count_before_commit', $forcedFailure); }
$t15Approved = $counts->approve($t15ApprovalKey, $t15Reconcile['id']);
check($t15Approved === $counts->approve($t15ApprovalKey, $t15Reconcile['id'])
    && $t15Approved['lines'][0]['value_delta_micros'] === 20000000
    && (int) wc_get_product($t15Pid)->get_stock_quantity() === 7
    && $valuation->totals($t15Pid)['quantity'] === 7 && $valuation->totals($t15Pid)['stock_micros'] === 70000000,
    'T15 surplus reconciliation retry posts original average value once without changing Woo stock again');
check((int) $wpdb->get_var($wpdb->prepare('SELECT SUM(remaining) FROM %i WHERE product_id=%d',
    $wpdb->prefix . 'sfm4u_inventory_receipt_lots', $t15Pid)) === 7, 'T15 reconciliation receipt lots equal the approved warehouse quantity');
wp_set_current_user(1);
