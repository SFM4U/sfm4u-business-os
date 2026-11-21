<?php
// L-05 (0.9.9): POS sale -> ERP invoice AFTER commit; refund -> ERPNext return invoice (credit note) once; mock ERPNext on :8897.
use SFM4U\Wholesale\Erp\{ErpSync, ErpNextClient};
use SFM4U\Wholesale\Members\Members;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1); update_option('woocommerce_currency','BDT');
// pure return doc
$d=ErpSync::returnDoc(['id'=>9,'number'=>'7','date'=>'2026-11-21','currency'=>'BDT','scale'=>2,'source'=>'pos','lines'=>[['sku'=>'A','qty'=>-1,'total_minor'=>-9000]]],'ACC-SINV-0001','SFM4U BD','Stores - SFM');
$ok($d['is_return']===1 && $d['return_against']==='ACC-SINV-0001' && $d['items'][0]['qty']===-1 && $d['items'][0]['rate']==90.0 && $d['update_stock']===1, 'return doc: is_return, against original, qty -1, rate 90, stock back');
$ok($bad(fn()=>ErpSync::returnDoc(['id'=>1,'number'=>'1','date'=>'x','currency'=>'BDT','lines'=>[]],'ACC-SINV-0001','C','W')), 'amount-only refund refused (manual credit note)');
$ok($bad(fn()=>ErpSync::returnDoc(['id'=>1,'number'=>'1','date'=>'x','currency'=>'BDT','lines'=>[['sku'=>'A','qty'=>1,'total_minor'=>100]]],'','C','W')), 'return without original invoice refused');
// settings -> mock
$oldS=get_option(ErpSync::OPT); $oldOn=get_option('sfmwh_erp_orders'); $oldMap=get_option('sfmwh_erp_branch_map');
update_option(ErpSync::OPT,['enabled'=>'1','url'=>'http://127.0.0.1:8897','key'=>'testkey123','secret_enc'=>Members::encrypt('testsecret456'),'warehouse'=>'Other - SFM','direction'=>'pull','auto'=>'0']);
update_option('sfmwh_erp_orders','yes'); update_option('sfmwh_erp_branch_map', wp_json_encode(['pos:MAIN'=>'Stores - SFM','web'=>'Online - LLC']));
$c=new ErpNextClient('http://127.0.0.1:8897','testkey123','testsecret456');
$x=new WC_Product_Simple(); $x->set_name('ER Soap'); $x->set_sku('ER-'.wp_rand(10000,99999)); $x->set_regular_price('100'); $x->set_manage_stock(true); $x->set_stock_quantity(20); $x->save();
$binBefore=$c->binQuantities('Stores - SFM')[$x->get_sku()] ?? 0.0;
// POS sale: status processing must NOT export inline; the job exports once
$pos=wc_create_order(['created_via'=>'sfm4u-pos-fixture']); $pos->add_product($x,3); $pos->set_currency('BDT'); $pos->update_meta_data('_sfm4u_pos_register','MAIN'); $pos->calculate_totals(); $pos->save();
$pos->update_status('processing'); $pid=$pos->get_id();
$ok((string) wc_get_order($pid)->get_meta('_sfmwh_erp_invoice')==='' && (bool) wp_next_scheduled(ErpSync::JOB_ORDER,[$pid]), 'POS sale not exported inside the sale; one-off job queued');
ErpSync::runOrderJob($pid); $inv=(string) wc_get_order($pid)->get_meta('_sfmwh_erp_invoice');
$ok($inv!=='' && wc_get_order($pid)->get_meta('_sfmwh_erp_warehouse')==='Stores - SFM', 'job exported POS sale to Stores - SFM: '.$inv);
$ok(abs(($c->binQuantities('Stores - SFM')[$x->get_sku()] ?? 0.0) - ($binBefore-3)) < 0.001, 'ERP stock down by 3');
ErpSync::runOrderJob($pid); $ok((string) wc_get_order($pid)->get_meta('_sfmwh_erp_invoice')===$inv, 'job again: same invoice, no duplicate');
// cancelled before its job -> nothing exported
$p2=wc_create_order(['created_via'=>'sfm4u-pos-fixture']); $p2->add_product($x,1); $p2->set_currency('BDT'); $p2->update_meta_data('_sfm4u_pos_register','MAIN'); $p2->calculate_totals(); $p2->save();
$p2->update_status('processing'); $p2->update_status('cancelled'); ErpSync::runOrderJob($p2->get_id());
$ok((string) wc_get_order($p2->get_id())->get_meta('_sfmwh_erp_invoice')==='', 'sale cancelled before its job: not exported');
// refund 1 of 3 -> queued, then credit note once, ERP stock back by 1
$item=array_values(wc_get_order($pid)->get_items())[0];
$refund=wc_create_refund(['order_id'=>$pid,'amount'=>'100.00','reason'=>'synthetic return','line_items'=>[$item->get_id()=>['qty'=>1,'refund_total'=>'100.00']],'restock_items'=>true]);
$ok(!is_wp_error($refund) && (bool) wp_next_scheduled(ErpSync::JOB_REFUND,[$refund->get_id()]), 'refund queued for ERP after commit');
ErpSync::runRefundJob($refund->get_id()); $cn=(string) wc_get_order($refund->get_id())->get_meta('_sfmwh_erp_return');
$ok($cn!=='' && $cn!==$inv, 'credit note created: '.$cn);
$ok(abs(($c->binQuantities('Stores - SFM')[$x->get_sku()] ?? 0.0) - ($binBefore-2)) < 0.001, 'ERP stock back by 1 (net -2) = shop sold 3, returned 1');
ErpSync::runRefundJob($refund->get_id()); $ok((string) wc_get_order($refund->get_id())->get_meta('_sfmwh_erp_return')===$cn, 'refund job again: no second credit note');
// refund of an order not yet in ERP -> retries later, no credit note
$p3=wc_create_order(); $p3->add_product($x,2); $p3->set_currency('BDT'); $p3->calculate_totals(); $p3->save();
$it3=array_values($p3->get_items())[0];
$r3=wc_create_refund(['order_id'=>$p3->get_id(),'amount'=>'100.00','line_items'=>[$it3->get_id()=>['qty'=>1,'refund_total'=>'100.00']]]);
ErpSync::runRefundJob($r3->get_id());
$ok((string) wc_get_order($r3->get_id())->get_meta('_sfmwh_erp_return')==='' && (int) wc_get_order($r3->get_id())->get_meta('_sfmwh_erp_return_tries')===1 && (bool) wp_next_scheduled(ErpSync::JOB_REFUND,[$r3->get_id()]), 'original not in ERP: credit note waits and retries');
// ERP export off -> nothing queued
update_option('sfmwh_erp_orders','no');
$p4=wc_create_order(['created_via'=>'sfm4u-pos-fixture']); $p4->add_product($x,1); $p4->set_currency('BDT'); $p4->calculate_totals(); $p4->save(); $p4->update_status('processing');
$ok(!wp_next_scheduled(ErpSync::JOB_ORDER,[$p4->get_id()]), 'ERP orders off (default): nothing queued');
$oldS!==false ? update_option(ErpSync::OPT,$oldS) : delete_option(ErpSync::OPT); $oldOn!==false ? update_option('sfmwh_erp_orders',$oldOn) : delete_option('sfmwh_erp_orders'); $oldMap!==false ? update_option('sfmwh_erp_branch_map',$oldMap) : delete_option('sfmwh_erp_branch_map');
echo "pass $p fail $f\n";
