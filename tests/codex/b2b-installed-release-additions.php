<?php
// Invoked inside the installed operations workflow, before deactivation.
wp_set_current_user(1);
op_check(is_file(WP_PLUGIN_DIR.'/sfm4u-wholesale/COPYING') && is_file(WP_PLUGIN_DIR.'/sfm4u-wholesale/readme.txt')
    && is_file(WP_PLUGIN_DIR.'/sfm4u-wholesale/languages/sfm4u-wholesale.pot'),'installed candidate includes license, setup guide and translation template');
$product=wc_get_product($pid);$product->set_sku('ZIP-SCAN-'.$pid);$product->update_meta_data('_sfm4u_label_barcodes',['piece'=>'2000000000015']);$product->save();
$label=(new \SFM4U\Inventory\BarcodeLabelsPage())->labels($pid,'piece');
op_check(str_contains($label,'2000000000015'),'installed candidate renders validated barcode label');
$_POST=['_wpnonce'=>wp_create_nonce('sfm4u_invoice_profile'),'merchant'=>'654321','name'=>'Synthetic ZIP merchant','address'=>'Synthetic address','bin'=>'NOT-A-REAL-BIN','note'=>'Synthetic only'];
(new \SFM4U\POS\InvoiceProfilePage())->save();$_POST=[];
$look=$call(['action'=>'search','search'=>'ZIP-SCAN-'.$pid]);
op_check($look->get_status()===200 && $look->get_data()['products'][0]['product']===$pid,'installed candidate exposes scoped product search');
$draftKey=wp_generate_uuid4();$draft=$call(['action'=>'park','product'=>$pid,'quantity'=>1],$draftKey);
$resumed=$call(['action'=>'resume','basket'=>$draftKey]);
op_check($draft->get_status()===200 && $resumed->get_data()['key']===$draftKey,'installed candidate parks and resumes the same basket key');
$opened=$call(['action'=>'drawer','drawer_action'=>'open','session'=>0,'amount'=>0]);$newSid=$opened->get_data()['session_id'];
$mid=$call(['action'=>'drawer','drawer_action'=>'count','session'=>$newSid,'amount'=>0]);
op_check($mid->get_status()===200 && $mid->get_data()['variance_minor']===0,'installed candidate records non-mutating mid-shift count');
$manualIntent=['action'=>'sale','session'=>$newSid,'product'=>$pid,'quantity'=>1,'tender'=>3000,'tender_type'=>'manual_mfs','tender_reference'=>'SYNTHETIC-REFERENCE'];
$manual=$call($manualIntent,$draftKey);$manualRecord=$manual->get_data();
op_check($manual->get_status()===200 && $manualRecord===$call($manualIntent,$draftKey)->get_data()
    && $manualRecord['sale']['cash_received_minor']===0 && wc_get_order($manualRecord['order_id'])->has_status('on-hold'),'installed manual mobile tender remains unverified and replays once');
$invoice=$call(['action'=>'invoice','order'=>$manualRecord['order_id'],'paper'=>'thermal']);
op_check($invoice->get_status()===200 && str_contains($invoice->get_data()['html'],'NOT-A-REAL-BIN') && str_contains($invoice->get_data()['html'],'COPY')
    && str_contains($invoice->get_data()['html'],'Unverified manual record'),'installed invoice preserves frozen BIN, COPY and unverified tender warning');
$manualCredit=$call(['action'=>'credit','session'=>$newSid,'order'=>$manualRecord['order_id'],'quantity'=>1,'resellable'=>true]);
$manualClose=$call(['action'=>'drawer','drawer_action'=>'close','session'=>$newSid,'amount'=>0]);
op_check($manualCredit->get_status()===200 && $manualCredit->get_data()['sale']['cash_refund_minor']===0
    && $manualClose->get_data()['expected_cash_minor']===0,'installed manual tender credit leaves drawer cash unchanged');
wp_set_current_user(1);$supplier=new \SFM4U\Inventory\SupplierPayables($wpdb,$value);$day=gmdate('Y-m-d');
$bill=$supplier->bill(wp_generate_uuid4(),'Synthetic audit supplier',wp_generate_uuid4(),100,$day,$day);
$creditKey=wp_generate_uuid4();$supplierCredit=$supplier->recordCredit($creditKey,$bill['bill_id'],'SYNTHETIC-CREDIT',40,$day);
op_check($supplierCredit['open_minor']===60 && $supplierCredit===$supplier->recordCredit($creditKey,$bill['bill_id'],'SYNTHETIC-CREDIT',40,$day),'installed supplier credit replays separately from payments');
$reportRows=(new \SFM4U\Inventory\InventoryReportsPage($wpdb))->report();
op_check(is_array($reportRows) && $value->totals($pid)['stock_micros']===60000000 && (int)wc_get_product($pid)->get_stock_quantity()===3,'installed release additions preserve count-approved stock and valuation');

require_once '/tests/product-meta-security.php';
sfm4u_product_meta_security('op_check');

wp_set_current_user(1);
$releaseLabels = new \SFM4U\Inventory\BarcodeLabelsPage();
$releaseProduct = new WC_Product_Simple();
$releaseProduct->set_name('Synthetic unopened ZIP stock');
$releaseProduct->set_sku('ZIP-LABEL-01');
$releaseProduct->set_regular_price('42.25');
$releaseProduct->set_price('42.25');
$releaseProduct->set_manage_stock(true);
$releaseProduct->set_stock_quantity(0);
$releaseProduct->update_meta_data('_sfm4u_reorder_target', 5);
$releaseProductId = $releaseProduct->save();
$releaseReport = new \SFM4U\Inventory\InventoryReportsPage($wpdb);
op_check($releaseReport->draftLines([$releaseProductId], 0) === "$releaseProductId,5,", 'installed candidate opens a zero-stock reorder draft');
$_GET = ['sku_products' => (string) $releaseProductId, 'sku_format' => 'thermal', 'sku_copies' => '2'];
ob_start(); $releaseLabels->render(); $releaseSheet = ob_get_clean();
$_GET = [];
op_check(str_contains($releaseSheet, 'size:58mm 36mm') && substr_count($releaseSheet, '<article') === 2
    && str_contains($releaseSheet, '42.25'), 'installed candidate renders print-ready thermal SKU labels through the admin page');
