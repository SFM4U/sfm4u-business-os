<?php
// 0.9.5: orders + member sales go to the right ERPNext company/branch (mock ERPNext on :8897).
use SFM4U\Wholesale\Erp\{ErpSync, ErpNextClient};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
// pure
$ok(ErpSync::branchFor(['pos:MAIN'=>'Stores - SFM','web'=>'Online - LLC'],'pos','main','Def')==='Stores - SFM','POS register MAIN -> Stores');
$ok(ErpSync::branchFor(['pos:MAIN'=>'Stores - SFM','web'=>'Online - LLC'],'web','','Def')==='Online - LLC','web -> Online');
$ok(ErpSync::branchFor([],'pos','R2','Def')==='Def','unknown -> default warehouse');
$doc=ErpSync::invoiceDoc(['id'=>5,'number'=>'5','date'=>'2026-11-21','currency'=>'BDT','scale'=>2,'source'=>'pos','lines'=>[['sku'=>'A-100','qty'=>2,'total_minor'=>18000]],'member_no_masked'=>'123****','member_tier'=>'business'],'SFM4U BD','Stores - SFM');
$ok($doc['company']==='SFM4U BD' && $doc['items'][0]['rate']==90.0 && $doc['is_pos']===1 && $doc['update_stock']===1,'invoice doc: company, rate 90, POS, stock');
$ok(strpos($doc['remarks'],'member 123**** (business)')!==false,'member sale marked in remarks');
$ok($bad(fn()=>ErpSync::invoiceDoc(['id'=>1,'number'=>'1','date'=>'x','currency'=>'BDT','lines'=>[['sku'=>'','qty'=>1,'total_minor'=>1]]],'C','W')),'line without SKU refused');
$ok($bad(fn()=>ErpSync::invoiceDoc(['id'=>1,'number'=>'1','date'=>'x','currency'=>'BDT','lines'=>[]],'','W')),'missing company refused');
// with mock ERPNext
$c=new ErpNextClient('http://127.0.0.1:8897','testkey123','testsecret456');
$map=['pos:MAIN'=>'Stores - SFM','web'=>'Online - LLC'];
$mk=function($sku){ $x=new WC_Product_Simple(); $x->set_name($sku); $x->set_sku($sku.'-'.wp_rand(1000,9999)); $x->set_regular_price('100'); $x->save(); return $x; };
$a=$mk('A');
$web=wc_create_order(); $web->add_product($a,3); $web->set_currency('BDT'); $web->calculate_totals(); $web->save();
$n1=ErpSync::exportOrder($web,$c,$map);
$w2=wc_get_order($web->get_id());
$ok($n1!=='' && $w2->get_meta('_sfmwh_erp_company')==='SFM4U LLC' && $w2->get_meta('_sfmwh_erp_warehouse')==='Online - LLC','web order -> SFM4U LLC / Online - LLC');
$ok(ErpSync::exportOrder($w2,$c,$map)===$n1,'second export returns same invoice (no duplicate)');
$pos=wc_create_order(['created_via'=>'sfm4u-pos-fixture']); $pos->add_product($a,2); $pos->set_currency('BDT'); $pos->update_meta_data('_sfm4u_pos_register','MAIN');
$pos->update_meta_data('_sfmwh_member_no_masked','765****'); $pos->update_meta_data('_sfmwh_member_tier','black'); $pos->calculate_totals(); $pos->save();
$n2=ErpSync::exportOrder($pos,$c,$map);
$p2=wc_get_order($pos->get_id());
$ok($n2!==$n1 && $p2->get_meta('_sfmwh_erp_company')==='SFM4U BD','POS member sale -> SFM4U BD / Stores');
$st=json_decode(wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:8897/_state')),true);
$inv=end($st['invoices']);
$ok($inv['is_pos']===1 && strpos($inv['remarks'],'765**** (black)')!==false && $inv['items'][0]['warehouse']==='Stores - SFM','mock got POS member invoice in right branch');
$ok(count($st['invoices'])>=2,'two invoices stored');
foreach([$web,$pos] as $o){ $o->delete(true);} $a->delete(true);
echo "pass $p fail $f\n";
