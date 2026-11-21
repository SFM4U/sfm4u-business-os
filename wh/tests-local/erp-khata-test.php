<?php
// L-12 (Owner approved 2026-11-21): khata credit sales + due payments -> ERPNext (mock :8897); Black StockFeed for credit sales.
// POS sale = Sales Invoice (is_pos 0) + cash Payment Entry; khata sales billed to the member's own ERP customer; due-only payment = Payment Entry.
use SFM4U\Wholesale\Erp\{ErpSync, ErpNextClient};
use SFM4U\Wholesale\Members\{Members, Tiers};
use SFM4U\Wholesale\Ops\StockFeed;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1); update_option('woocommerce_currency','BDT'); Members::install();
$keep=[]; foreach ([ErpSync::OPT,'sfmwh_erp_orders','sfmwh_erp_branch_map',ErpSync::OPT_CASH,ErpSync::OPT_ROUND_OFF,StockFeed::OPT_ROLE,StockFeed::OPT_TARGET] as $k) { $keep[$k]=get_option($k); }
update_option(ErpSync::OPT,['enabled'=>'1','url'=>'http://127.0.0.1:8897','key'=>'testkey123','secret_enc'=>Members::encrypt('testsecret456'),'warehouse'=>'Stores - SFM','direction'=>'pull','auto'=>'0']);
update_option('sfmwh_erp_orders','yes'); update_option('sfmwh_erp_branch_map', wp_json_encode(['pos:MAIN'=>'Stores - SFM']));
update_option(ErpSync::OPT_CASH, 'Cash - SFM'); delete_option(ErpSync::OPT_ROUND_OFF);
$state=fn()=>json_decode(wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:8897/_state')),true);
$inv=function($name)use($state){ $s=$state(); return $s['invoices'][(int) substr($name,-4)-1] ?? null; };
$pay=function($name)use($state){ foreach ($state()['payments'] as $x) { if ($x['_name']===$name) return $x; } return null; };
// pure: payment doc needs the cash account
$ok(ErpSync::paymentDoc('Receive','SFM4U BD','Walk-in Customer',9000,2,'R1','2026-11-21')['paid_to']==='Cash - SFM', 'payment doc: cash account on paid_to for Receive');
update_option(ErpSync::OPT_CASH, ''); $ok($bad(fn()=>ErpSync::paymentDoc('Receive','SFM4U BD','Walk-in Customer',9000,2,'R1','2026-11-21')), 'no cash account: payment refused (waits)'); update_option(ErpSync::OPT_CASH, 'Cash - SFM');
// fixtures: Black member with card, product
$r=wp_rand(10000,99999);
$m=(int) wp_insert_user(['user_login'=>"ek$r",'user_pass'=>wp_generate_password(20),'user_email'=>"ek$r@example.com"]);
Members::issue($m,'black',0,1,'main',null,'Khata Shop'); Tiers::recordBlackPayment($m,1,'cash','',1);
$x=new WC_Product_Simple(); $x->set_name('EK Oil'); $x->set_sku('EK-'.$r); $x->set_regular_price('100'); $x->set_manage_stock(true); $x->set_stock_quantity(50); $x->set_status('publish'); $x->save();
// StockFeed sender with mocked AlgiBazar
update_option(StockFeed::OPT_ROLE,'sender'); update_option(StockFeed::OPT_TARGET,['url'=>'https://algibazar.example.test','key_id'=>'k_test','secret_enc'=>Members::encrypt(str_repeat('b',64))]);
$calls=0; add_filter('pre_http_request', function($pre,$args,$url)use(&$calls){ if (strpos($url,'algibazar.example.test')===false) return $pre; $calls++;
    return ['headers'=>[],'body'=>wp_json_encode(['ok'=>true,'created'=>1,'updated'=>0]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]]; },10,3);
// a counter sale like C-09 makes it: total 300.00, 100.00 on khata, 20.00 old due paid -> cash 220.00, order on-hold
$mk=function(array $khata, string $status) use ($x,$m) {
    $o=wc_create_order(['created_via'=>'sfm4u-pos-fixture']); $o->add_product(wc_get_product($x->get_id()),3); $o->set_currency('BDT');
    $o->set_payment_method('sfm4u_counter_cash'); $o->update_meta_data('_sfm4u_pos_register','MAIN'); $o->update_meta_data('_sfmwh_member_source','pos');
    $o->update_meta_data('_sfmwh_member_user',$m); if ($khata) { $o->update_meta_data('_sfm4u_pos_khata',$khata); }
    $o->calculate_totals(); $o->save(); $o->update_status($status); return $o->get_id();
};
$id=$mk(['previous_due_minor'=>2000,'due_payment_minor'=>2000,'credit_minor'=>10000,'due_minor'=>10000,'advance_minor'=>0],'on-hold');
$ok((bool) wp_next_scheduled(ErpSync::JOB_ORDER,[$id]) && (bool) wp_next_scheduled(StockFeed::CRON_POS,[$id]), 'on-hold khata credit sale: ERP job and Black stock job queued');
ErpSync::runOrderJob($id); $o=wc_get_order($id);
$in=$inv((string) $o->get_meta('_sfmwh_erp_invoice')); $pe=$pay((string) $o->get_meta('_sfmwh_erp_payment'));
$cust=(string) get_user_meta($m, ErpSync::META_CUSTOMER, true);
$ok($in && $in['customer']===$cust && str_starts_with($cust,'SFM4U ') && !str_contains($cust, Members::memberNo($m)), 'invoice billed to the member ERP customer (masked, no full number): '.$cust);
$ok($in && $in['is_pos']===0 && abs($in['_grand_total']-300.0)<0.001, 'POS invoice is a normal invoice for the full 300.00');
$ok($pe && abs($pe['paid_amount']-220.0)<0.001 && abs($pe['references'][0]['allocated_amount']-200.0)<0.001 && $pe['party']===$cust, 'payment 220.00: 200.00 to this invoice, 20.00 toward old due');
$ok(abs($inv((string) $o->get_meta('_sfmwh_erp_invoice'))['_outstanding']-100.0)<0.001, 'ERP outstanding on the invoice = khata credit 100.00');
$n=count($state()['payments']); ErpSync::runOrderJob($id); $ok(count($state()['payments'])===$n, 'job again: no second invoice/payment');
$ok(StockFeed::sendPos($id)==='sent' && $calls===1, 'Black member credit sale: stock sent to AlgiBazar once');
// card/MFS sale left on-hold (unverified tender, no khata) is NOT a done sale
$id2=$mk([],'on-hold'); $ok(!wp_next_scheduled(ErpSync::JOB_ORDER,[$id2]) && StockFeed::sendPos($id2)==='skip', 'on-hold sale without khata credit: not exported, no stock sent');
// plain cash POS sale -> walk-in invoice + full payment allocated
$id3=$mk([],'processing'); ErpSync::runOrderJob($id3); $o3=wc_get_order($id3);
$pe3=$pay((string) $o3->get_meta('_sfmwh_erp_payment')); $in3=$inv((string) $o3->get_meta('_sfmwh_erp_invoice'));
$ok($in3['customer']==='Walk-in Customer' && $pe3 && abs($pe3['paid_amount']-300.0)<0.001 && abs($in3['_outstanding'])<0.001, 'cash POS sale: walk-in invoice fully paid');
// due-only payment by card scan (khata row with ref pos-due:...) -> Payment Entry once
$row=Members::record($m,0,'payment',5000,'cash','POS due payment',1,'up','test-main','pos-due:'.substr(hash('sha256',"k$r"),0,40));
$ok((bool) wp_next_scheduled(ErpSync::JOB_DUE,[$row['id']]), 'due payment queued for ERP after commit');
ErpSync::runDueJob($row['id']); $due=null; foreach ($state()['payments'] as $q) { if (($q['reference_no']??'')==='KHATA-'.$row['id']) $due=$q; }
$ok($due && $due['party']===$cust && abs($due['paid_amount']-50.0)<0.001 && empty($due['references']), 'due payment 50.00 -> Payment Entry to the member customer');
$n=count($state()['payments']); ErpSync::runDueJob($row['id']); $ok(count($state()['payments'])===$n, 'due job again: no duplicate');
$row2=Members::record($m,0,'payment',700,'cash','manager entry',1,'up','test-main');
$n=count($state()['payments']); ErpSync::runDueJob($row2['id']); $ok(count($state()['payments'])===$n, 'khata payment not from the POS counter: not sent (manual)');
// refund of the plain cash sale: credit note to walk-in + cash Pay entry
$it=array_values($o3->get_items())[0];
$rf=wc_create_refund(['order_id'=>$id3,'amount'=>'100.00','restock_items'=>true,'line_items'=>[$it->get_id()=>['qty'=>1,'refund_total'=>'100.00']]]);
ErpSync::runRefundJob($rf->get_id()); $rf=wc_get_order($rf->get_id()); $rp=$pay((string) $rf->get_meta('_sfmwh_erp_refund_payment'));
$cn=$inv((string) $rf->get_meta('_sfmwh_erp_return'));
$ok($cn && $cn['is_pos']===0 && $cn['customer']==='Walk-in Customer' && $rp && $rp['payment_type']==='Pay' && abs($rp['paid_amount']-100.0)<0.001, 'POS cash refund: credit note + cash Pay entry 100.00');
foreach ($keep as $k=>$v) { $v!==false ? update_option($k,$v) : delete_option($k); }
echo "pass $p fail $f\n";
