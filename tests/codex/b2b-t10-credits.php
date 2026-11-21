<?php
wp_set_current_user(1);
$apPaymentTable=$wpdb->prefix.'sfm4u_supplier_payments';
$oldApRows=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$apPaymentTable));
if($wpdb->query($wpdb->prepare('ALTER TABLE %i DROP COLUMN kind',$apPaymentTable))===false)throw new RuntimeException('Synthetic old AP schema setup failed.');
update_option('sfm4u_operations_schema','2');\SFM4U\POS\Runtime::install($wpdb);
check(get_option('sfm4u_operations_schema')==='3' && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE kind=%s',$apPaymentTable,'payment'))===$oldApRows,
    'T10 schema upgrade preserves historical supplier payments as payments');
$creditBill=$ap->bill(wp_generate_uuid4(),'Synthetic credit supplier','CREDIT-BILL-'.$tag,10000,$yesterday=(new DateTimeImmutable($today,new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d'),$today);
$ap->recordPayment(wp_generate_uuid4(),$creditBill['bill_id'],'CREDIT-PAID-'.$tag,2000,$yesterday);
$creditKey=wp_generate_uuid4();$creditFailure=static function(string $kind):void{if($kind==='credit')throw new RuntimeException('Synthetic AP credit rollback');};
add_action('sfm4u_supplier_settlement_before_commit',$creditFailure);
try{$ap->recordCredit($creditKey,$creditBill['bill_id'],'CREDIT-NOTE-'.$tag,3000,$today);check(false,'credit failure must roll back');}
catch(RuntimeException $error){$rows=array_column($ap->aging($today)['bills'],null,'id');check($error->getMessage()==='Synthetic AP credit rollback' && $rows[$creditBill['bill_id']]['open_minor']===8000,
    'T10 failed supplier credit rolls back the credit and original bill balance');}
finally{remove_action('sfm4u_supplier_settlement_before_commit',$creditFailure);}
$creditPosted=$ap->recordCredit($creditKey,$creditBill['bill_id'],'CREDIT-NOTE-'.$tag,3000,$today);
check($creditPosted['open_minor']===5000 && $creditPosted===$ap->recordCredit($creditKey,$creditBill['bill_id'],'CREDIT-NOTE-'.$tag,3000,$today),
    'T10 supplier credit retries once and reduces AP without recording a payment');
$creditNow=array_column($ap->aging($today)['bills'],null,'id');$creditPast=array_column($ap->aging($yesterday)['bills'],null,'id');
check((int)$creditNow[$creditBill['bill_id']]['paid_minor']===2000 && (int)$creditNow[$creditBill['bill_id']]['credit_minor']===3000
    && $creditPast[$creditBill['bill_id']]['open_minor']===8000,'T10 AP aging separates cash payments from credits and honors the as-of date');
try{$ap->recordCredit(wp_generate_uuid4(),$creditBill['bill_id'],'EXCESS-CREDIT-'.$tag,5001,$today);check(false,'excess credit must fail');}
catch(DomainException){check(true,'T10 credit above outstanding supplier balance is rejected');}
$finalCreditPayment=$ap->recordPayment(wp_generate_uuid4(),$creditBill['bill_id'],'CREDIT-FINAL-'.$tag,5000,$today);
check($finalCreditPayment['status']==='paid' && $finalCreditPayment['open_minor']===0,'T10 final payment settles the balance net of supplier credit');
wp_set_current_user($buyer);
try{$ap->recordCredit(wp_generate_uuid4(),$creditBill['bill_id'],'UNAUTHORIZED',1,$today);check(false,'unauthorized supplier credit must fail');}
catch(DomainException $error){check($error->getCode()===403,'T10 customer cannot record supplier credits');}
wp_set_current_user(1);
$_SERVER['REQUEST_METHOD']='POST';$retryBillKey=wp_generate_uuid4();
$_POST=['ap_action'=>'payment','ap_nonce'=>wp_create_nonce('sfm4u_supplier_ap'),'ap_key'=>$retryBillKey,'bill_id'=>'999999999','reference'=>'SYNTHETIC-RETRY-REFERENCE','amount'=>'1','paid'=>$today];
ob_start();(new \SFM4U\Inventory\SupplierPayablesPage($ap,$purchase))->render();$retryBillHtml=ob_get_clean();
check(str_contains($retryBillHtml,'value="'.$retryBillKey.'"') && str_contains($retryBillHtml,'value="SYNTHETIC-RETRY-REFERENCE"') && str_contains($retryBillHtml,'<details open>'),'T10 failed settlement form preserves original key and submitted fields');
$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
