<?php
wp_set_current_user(1);$scanProduct=wc_get_product($t15Pid);$scanProduct->set_sku('SYNTHETIC-SCAN-'.$t15Pid);$scanProduct->save();
wp_set_current_user($securityCashier);
$lookup=$cashPos->search($merchant,'R2',wc_get_product($t15Pid)->get_sku());
check(count($lookup['products'])===1 && $lookup['products'][0]['product']===$t15Pid,'T11 exact SKU lookup remains merchant scoped');
$scan=$cashPos->search($merchant,'R2',$cartonEan);
check(count($scan['products'])===1 && $scan['products'][0]['unit']==='carton' && $scan['products'][0]['quantity']===12,'T11 EAN scan resolves the configured carton stock quantity');
$basketKey=wp_generate_uuid4();$basketStock=(int)wc_get_product($t15Pid)->get_stock_quantity();
$basket=$cashPos->park($merchant,'R2',$basketKey,$t15Pid,1);
check($basket===$cashPos->park($merchant,'R2',$basketKey,$t15Pid,1) && (int)wc_get_product($t15Pid)->get_stock_quantity()===$basketStock,'T11 parking and replay reserve no stock and create one draft');
check($cashPos->resume($merchant,'R2',$basketKey)['quantity']===1,'T11 scoped basket resumes with recorded product and quantity');
try{$cashPos->sale($merchant,'R2',$basketKey,$t15Pid,2,2600);check(false,'basket mutation must fail');}catch(DomainException $e){check($e->getCode()===409,'T11 parked basket sale rejects changed quantity');}
$basketSession=$cashPos->drawer($merchant,'R2','open',wp_generate_uuid4(),0,0)['session_id'];
$basketSale=$cashPos->sale($merchant,'R2',$basketKey,$t15Pid,1,1300,$basketSession);
check($basketSale===$cashPos->sale($merchant,'R2',$basketKey,$t15Pid,1,1300,$basketSession) && $cashPos->resume($merchant,'R2',$basketKey)['receipt']===$basketSale
    && (int)wc_get_product($t15Pid)->get_stock_quantity()===$basketStock-1,'T11 resumed basket uses its original key and cannot create another sale on retry');
$thermal=$cashPos->invoice($merchant,'R2',$basketSale['order_id'],'thermal');
check(str_contains($thermal['html'],'COPY') && $thermal['paper']==='thermal' && $cashPos->reprint($merchant,'R2',$basketSale['order_id'])['receipt']['number']===$basketSale['number'],'T06 thermal print reuses original immutable number and COPY');
file_put_contents('/fixture-out/thermal.html','<!doctype html><html lang="bn"><meta charset="utf-8">'.\SFM4U\POS\InvoiceTemplate::styles('thermal').'<body>'.$thermal['html'].'</body></html>');
$cashPos->credit($merchant,'R2',wp_generate_uuid4(),$basketSale['order_id'],1,true,$basketSession);
$cashPos->drawer($merchant,'R2','close',wp_generate_uuid4(),$basketSession,0);
wp_set_current_user($staff);
try{$cashPos->resume($merchant,'R1',$basketKey);check(false,'cross-register draft must fail');}catch(DomainException $e){check($e->getCode()===404,'T11 cashier cannot recover another actor or register basket');}
wp_set_current_user($buyer);
try{$cashPos->search($merchant,'R2',$cartonEan);check(false,'buyer search must fail');}catch(DomainException $e){check($e->getCode()===403,'T11 product lookup requires assigned cashier capability');}
wp_set_current_user(1);
wp_set_current_user($securityCashier);
try{$cashPos->park($merchant,'R2',$basketKey,$t15Pid,2);check(false,'used key mutation must fail');}catch(DomainException $e){check($e->getCode()===409,'security parked key cannot be rebound to another quantity');}
$oldSaleKey=wp_generate_uuid4();$oldSaleSession=$cashPos->drawer($merchant,'R2','open',wp_generate_uuid4(),0,0)['session_id'];
$oldKeySale=$cashPos->sale($merchant,'R2',$oldSaleKey,$t15Pid,1,1300,$oldSaleSession);
try{$cashPos->park($merchant,'R2',$oldSaleKey,$t15Pid,1);check(false,'old sale key must not become draft');}catch(DomainException $e){check($e->getCode()===409,'security existing sale key cannot create a new parked basket');}
$cashPos->credit($merchant,'R2',wp_generate_uuid4(),$oldKeySale['order_id'],1,true,$oldSaleSession);$cashPos->drawer($merchant,'R2','close',wp_generate_uuid4(),$oldSaleSession,0);
wp_set_current_user(1);
