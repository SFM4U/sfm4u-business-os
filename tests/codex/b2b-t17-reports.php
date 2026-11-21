<?php
wp_set_current_user(1);
$inventoryReport = new \SFM4U\Inventory\InventoryReportsPage($wpdb);
$t17Product = wc_get_product($t15Pid);
$_POST = ['sfm4u_reorder_nonce' => 'invalid', '_sfm4u_reorder_point' => '6', '_sfm4u_reorder_target' => '10'];
$inventoryReport->saveProduct($t17Product);
check($t17Product->get_meta('_sfm4u_reorder_target') === '', 'T17 reorder configuration rejects invalid nonce');
$_POST['sfm4u_reorder_nonce'] = wp_create_nonce('sfm4u_reorder_' . $t15Pid);
$inventoryReport->saveProduct($t17Product); $t17Product->save(); $_POST = [];
$t17Rows = array_column($inventoryReport->report()['rows'], null, 'product');
check($t17Rows[$t15Pid]['quantity'] === 6 && $t17Rows[$t15Pid]['stock_micros'] === 60000000
    && $t17Rows[$t15Pid]['suggested_quantity'] === 4 && !$t17Rows[$t15Pid]['needs_reconciliation'],
    'T17 valuation report reconciles T15/POS stock and configured reorder target');
$t17Hold = wc_create_order(); $t17Hold->add_product(wc_get_product($t15Pid), 2); $t17Hold->save(); wc_reserve_stock_for_order($t17Hold);
$t17Rows = array_column($inventoryReport->report()['rows'], null, 'product');
check($t17Rows[$t15Pid]['available'] === 4 && $t17Rows[$t15Pid]['suggested_quantity'] === 6, 'T17 reorder suggestions account for active stock reservations');
wc_release_stock_for_order($t17Hold);
wc_update_product_stock($t15Pid, 1, 'increase');
$t17Rows = array_column($inventoryReport->report()['rows'], null, 'product');
check($t17Rows[$t15Pid]['needs_reconciliation'] && $valuation->totals($t15Pid)['quantity'] === 6,
    'T17 discrepancy report flags Woo/book drift without overwriting either balance');
wc_update_product_stock($t15Pid, 1, 'decrease');
wp_set_current_user($buyer);
try { $inventoryReport->report(); check(false, 'buyer valuation must be denied'); }
catch (DomainException $error) { check($error->getCode() === 403, 'T17 customer cannot read warehouse costs or reorder reports'); }
wp_set_current_user($securityCashier);
$t17Drawer = $cashPos->drawer($merchant, 'R2', 'open', wp_generate_uuid4(), 0, 1000);
$t17CountBefore = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'sfm4u_inventory_requests'));
$t17X = $cashPos->drawer($merchant, 'R2', 'xreport');
check($t17X['kind'] === 'X' && $t17X['expected_cash_minor'] === 1000 && $t17X['session_id'] === $t17Drawer['session_id']
    && $t17CountBefore === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'sfm4u_inventory_requests')),
    'T17 live X-report reads expected cash without creating a document or movement');
$cashPos->drawer($merchant, 'R2', 'close', wp_generate_uuid4(), $t17Drawer['session_id'], 1000);
wp_set_current_user(1);
$t17High = new WC_Product_Simple(); $t17High->set_name('Synthetic high value report'); $t17High->set_manage_stock(true); $t17High->set_stock_quantity(0); $t17High->save();
$t17HighPo=$purchase->issue(wp_generate_uuid4(),'Synthetic high value supplier',[['product'=>$t17High->get_id(),'quantity'=>1,'unit_minor'=>1000000]]);
$purchase->receive(wp_generate_uuid4(),$t17HighPo['id'],[['product'=>$t17High->get_id(),'quantity'=>1,'landed_minor'=>0]]);
$t17HighRows=array_column($inventoryReport->report()['rows'],null,'product');
check($t17HighRows[$t17High->get_id()]['stock_micros']===10000000000, 'T17 aggregate preserves values above ten digits without float rounding');

$t17HighId = $t17High->get_id();
$t17High = wc_get_product($t17HighId);
$t17High->update_meta_data('_sfm4u_reorder_point', 1);
$t17High->update_meta_data('_sfm4u_reorder_target', 4);
$t17High->save();
$t17Page = new \SFM4U\Inventory\SupplierOrderPage($purchase);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['po_action' => 'draft', 'po_nonce' => 'invalid', 'po_after' => '0', 'po_products' => [(string) $t17HighId]];
ob_start(); $t17Page->render(); $invalidDraft = ob_get_clean();
check(str_contains($invalidDraft, 'Refresh the page') && !str_contains($invalidDraft, "$t17HighId,3,"), 'Reorder draft rejects an invalid nonce');
$_POST['po_nonce'] = wp_create_nonce('sfm4u_supplier_order');
ob_start(); $t17Page->render(); $draftHtml = ob_get_clean();
$_POST = []; unset($_SERVER['REQUEST_METHOD']);
check(str_contains($draftHtml, "$t17HighId,3,") && str_contains($draftHtml, 'Draft ready')
    && (int) wc_get_product($t17HighId)->get_stock_quantity() === 1, 'Selected suggestion prefills current quantity and leaves cost and stock alone');
try { $inventoryReport->draftLines([$t17HighId, $t17HighId], 0); check(false, 'duplicate reorder line must fail'); }
catch (DomainException) { check(true, 'Reorder draft validates duplicate selection'); }
wc_update_product_stock($t17HighId, 1, 'increase');
try { $inventoryReport->draftLines([$t17HighId], 0); check(false, 'stale reorder line must fail'); }
catch (DomainException) { check(true, 'Reorder draft rejects stale or unreconciled selection'); }
wc_update_product_stock($t17HighId, 1, 'decrease');
$t17DraftPo = $purchase->issue(wp_generate_uuid4(), 'Synthetic reorder supplier', [['product' => $t17HighId, 'quantity' => 3, 'unit_minor' => 500]]);
$t17ReceiptKey = wp_generate_uuid4();
$t17Delivery = [['product' => $t17HighId, 'quantity' => 1, 'landed_minor' => 0]];
$purchase->receive($t17ReceiptKey, $t17DraftPo['id'], $t17Delivery);
$purchase->receive($t17ReceiptKey, $t17DraftPo['id'], $t17Delivery);
check($purchase->find($t17DraftPo['id'])['lines'][$t17HighId]['received'] === 1
    && (int) wc_get_product($t17HighId)->get_stock_quantity() === 2, 'Partial receiving credits stock once on replay');
$purchase->receive(wp_generate_uuid4(), $t17DraftPo['id'], [['product' => $t17HighId, 'quantity' => 2, 'landed_minor' => 0]]);
check($purchase->find($t17DraftPo['id'])['lines'][$t17HighId]['received'] === 3
    && (int) wc_get_product($t17HighId)->get_stock_quantity() === 4, 'Final receiving completes the draft-to-PO stock flow');
$t17Empty = new WC_Product_Simple();
$t17Empty->set_name('Synthetic unopened warehouse SKU');
$t17Empty->set_manage_stock(true);
$t17Empty->set_stock_quantity(0);
$t17Empty->update_meta_data('_sfm4u_reorder_target', 5);
$t17EmptyId = $t17Empty->save();
check($inventoryReport->draftLines([$t17EmptyId], 0) === "$t17EmptyId,5,", 'Reorder includes zero-stock products before the first valuation entry');
