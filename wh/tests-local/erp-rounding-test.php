<?php
// C-11 ERP blocker (Codex): POS cash-rounding fee + unit-rate rounding reach ERPNext as one "Actual" round-off row,
// so ERP totals equal WooCommerce to the minor unit; a damaged return (no restock) does not raise ERP stock. Mock :8897.
use SFM4U\Wholesale\Erp\{ErpSync, ErpNextClient};
use SFM4U\Wholesale\Members\Members;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1); update_option('woocommerce_currency','BDT');
// pure: rounding row maths
$items=[['rate'=>33.34,'qty'=>2],['rate'=>10.01,'qty'=>1]];
$row=ErpSync::roundingRow($items, 6667+1001+32, 2, 1, 'Round Off - SFM');
$ok(count($row)===1 && $row[0]['charge_type']==='Actual' && abs($row[0]['tax_amount']-0.31)<0.0001, 'invoice row: +0.32 fee -0.01 rate residue = 0.31');
$ok(ErpSync::roundingRow([['rate'=>90.0,'qty'=>1]], 9000, 2, 1, '')===[], 'no difference: no row, no account needed');
$ok($bad(fn()=>ErpSync::roundingRow($items, 7700, 2, 1, '')), 'difference without a round-off account is refused (export waits)');
$r=ErpSync::roundingRow([['rate'=>33.34,'qty'=>-1]], 3350, 2, -1, 'Round Off - SFM');
$ok(abs($r[0]['tax_amount']-(-0.16))<0.0001, 'return row is negative: -(33.50-33.34)');
// settings -> mock
$oldS=get_option(ErpSync::OPT); $oldOn=get_option('sfmwh_erp_orders'); $oldMap=get_option('sfmwh_erp_branch_map'); $oldR=get_option(ErpSync::OPT_ROUND_OFF);
update_option(ErpSync::OPT,['enabled'=>'1','url'=>'http://127.0.0.1:8897','key'=>'testkey123','secret_enc'=>Members::encrypt('testsecret456'),'warehouse'=>'Other - SFM','direction'=>'pull','auto'=>'0']);
update_option('sfmwh_erp_orders','yes'); update_option('sfmwh_erp_branch_map', wp_json_encode(['pos:MAIN'=>'Stores - SFM']));
update_option(ErpSync::OPT_ROUND_OFF,'Round Off - SFM');
$c=new ErpNextClient('http://127.0.0.1:8897','testkey123','testsecret456');
$state=fn()=>json_decode(wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:8897/_state')),true);
$mk=function($name,$price){ $x=new WC_Product_Simple(); $x->set_name($name); $x->set_sku('RN-'.wp_rand(100000,999999)); $x->set_regular_price($price); $x->set_manage_stock(true); $x->set_stock_quantity(20); $x->save(); return $x; };
$a=$mk('RN Rice','33.34'); $b=$mk('RN Salt','10.01');
$bin=fn($x)=>$c->binQuantities('Stores - SFM')[$x->get_sku()] ?? 0.0;
$a0=$bin($a); $b0=$bin($b);
// discounted two-line POS sale: line A 2 pcs = 66.67 (odd: rate 33.335), line B 10.01, cash rounding fee +0.32 -> 77.00
$o=wc_create_order(['created_via'=>'sfm4u-pos-fixture']); $o->set_currency('BDT'); $o->update_meta_data('_sfm4u_pos_register','MAIN');
$ia=$o->add_product($a,2,['subtotal'=>'66.67','total'=>'66.67']); $ib=$o->add_product($b,1);
$fee=new WC_Order_Item_Fee(); $fee->set_name('Cash rounding'); $fee->set_total('0.32'); $fee->add_meta_data('_sfm4u_cash_rounding','1'); $o->add_item($fee);
$o->calculate_totals(false); $o->save();
$ok(wc_format_decimal($o->get_total(),2)==='77.00', 'fixture total 77.00: '.$o->get_total());
$o->update_status('processing'); ErpSync::runOrderJob($o->get_id());
$inv=(string) wc_get_order($o->get_id())->get_meta('_sfmwh_erp_invoice'); $st=$state(); $doc=end($st['invoices']);
$ok($inv!=='' && abs($doc['_grand_total']-77.00)<0.001 && ($doc['disable_rounded_total']??0)===1, 'ERP invoice total = Woo total 77.00 (got '.$doc['_grand_total'].')');
// refund 1: one Rice, resellable, with its share of the rounding fee (0.16) -> 33.50
$feeId=array_key_first(wc_get_order($o->get_id())->get_items('fee'));
$r1=wc_create_refund(['order_id'=>$o->get_id(),'amount'=>'33.50','reason'=>'resellable','restock_items'=>true,
    'line_items'=>[$ia=>['qty'=>1,'refund_total'=>'33.34'],$feeId=>['qty'=>0,'refund_total'=>'0.16']]]);
ErpSync::runRefundJob($r1->get_id()); $st=$state(); $d1=end($st['invoices']);
$ok((string) wc_get_order($r1->get_id())->get_meta(ErpSync::META_RESTOCK)==='1' && $d1['update_stock']===1, 'resellable return: restock remembered, ERP update_stock 1');
$ok(abs($d1['_grand_total']-(-33.50))<0.001, 'ERP return 1 total = -33.50 exactly (got '.$d1['_grand_total'].')');
$ok(abs($bin($a)-($a0-1))<0.001, 'ERP Rice stock: sold 2, back 1');
// refund 2: Salt damaged (no restock), share of fee 0.16 -> 10.17
$r2=wc_create_refund(['order_id'=>$o->get_id(),'amount'=>'10.17','reason'=>'damaged','restock_items'=>false,
    'line_items'=>[$ib=>['qty'=>1,'refund_total'=>'10.01'],$feeId=>['qty'=>0,'refund_total'=>'0.16']]]);
ErpSync::runRefundJob($r2->get_id()); $st=$state(); $d2=end($st['invoices']);
$ok($d2['update_stock']===0 && str_contains($d2['remarks'],'damaged'), 'damaged return: ERP update_stock 0');
$ok(abs($bin($b)-($b0-1))<0.001, 'ERP Salt stock stays down (damaged not back in stock)');
$ok(abs($d2['_grand_total']-(-10.17))<0.001, 'ERP return 2 total = -10.17 exactly');
$ok(abs($doc['_grand_total']+$d1['_grand_total']+$d2['_grand_total']-(77.00-33.50-10.17))<0.001, 'ERP net equals Woo net to the poisha');
// refund older than this version (no restock meta) keeps old behaviour: stock back
$ok(ErpSync::returnDoc(['id'=>1,'number'=>'1','date'=>'2026-11-21','currency'=>'BDT','lines'=>[['sku'=>'A','qty'=>-1,'total_minor'=>-100]]],'ACC-SINV-0001','SFM4U BD','Stores - SFM')['update_stock']===1, 'no restock info: update_stock 1 (unchanged behaviour)');
foreach ([[ErpSync::OPT,$oldS],['sfmwh_erp_orders',$oldOn],['sfmwh_erp_branch_map',$oldMap],[ErpSync::OPT_ROUND_OFF,$oldR]] as [$k,$v]) { $v!==false ? update_option($k,$v) : delete_option($k); }
echo "pass $p fail $f\n";
