<?php
wp_set_current_user(1);
$t11OldMax=get_option('sfm4u_pos_max_difference_minor','0');$t11OldRound=get_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION,'100');
$t11Policy=new \SFM4U\POS\RegisterSettingsPage();
$_POST=['_wpnonce'=>wp_create_nonce('sfm4u_pos_settings'),'rounding'=>'100','maximum'=>'0.05'];$t11Policy->save();$_POST=[];
$t11Assignments=get_user_meta($securityCashier,'_sfm4u_pos_registers',true);$t11ManagerAssignments=$t11Assignments;$t11ManagerAssignments[$merchant]['R1']='M1';update_user_meta($securityCashier,'_sfm4u_pos_registers',$t11ManagerAssignments);
wp_set_current_user($staff);$midSession=$cashPos->drawer($merchant,'R1','open',wp_generate_uuid4(),0,1000)['session_id'];$midKey=wp_generate_uuid4();
$midCount=$cashPos->drawer($merchant,'R1','count',$midKey,$midSession,996);
check($midCount===$cashPos->drawer($merchant,'R1','count',$midKey,$midSession,996) && $midCount['variance_minor']===-4 && $midCount['approval_by']===null,
    'T11 mid-shift cash count within configured tolerance replays as one audited count');
try{$cashPos->drawer($merchant,'R1','count',wp_generate_uuid4(),$midSession,990);check(false,'large count variance must require approval');}
catch(DomainException $error){check($error->getCode()===403,'T11 cashier cannot approve a mid-shift variance above policy');}
wp_set_current_user($securityCashier);$managerCount=$cashPos->drawer($merchant,'R1','count',wp_generate_uuid4(),$midSession,990,'','Synthetic manager count review');
check($managerCount['approval_by']===$securityCashier && $cashPos->drawer($merchant,'R1','xreport')['expected_cash_minor']===1000,
    'T11 manager variance approval records the difference without inventing cash movement');
wp_set_current_user($staff);$cashPos->drawer($merchant,'R1','close',wp_generate_uuid4(),$midSession,1000);
wp_set_current_user(1);$t11Before=$daily->report($today,$merchant,'R2');
wp_set_current_user($securityCashier);$manualSession=$cashPos->drawer($merchant,'R2','open',wp_generate_uuid4(),0,1000)['session_id'];
$manualSales=[];
foreach(['manual_card','manual_mfs'] as $tenderType){
    $manualKey=wp_generate_uuid4();$manualSales[$tenderType]=$cashPos->sale($merchant,'R2',$manualKey,$t15Pid,1,1250,$manualSession,$tenderType,'SYNTHETIC-'.$tenderType);
    $manualOrder=wc_get_order($manualSales[$tenderType]['order_id']);
    check($manualSales[$tenderType]===$cashPos->sale($merchant,'R2',$manualKey,$t15Pid,1,1250,$manualSession,$tenderType,'SYNTHETIC-'.$tenderType)
        && $manualSales[$tenderType]['sale']['cash_received_minor']===0 && $manualSales[$tenderType]['sale']['rounding_minor']===0
        && $manualOrder->has_status('on-hold') && !$manualOrder->is_paid() && $manualOrder->get_date_paid()===null,
        'T11 '.$tenderType.' records tender and stock once without cash rounding or verified-paid status');
}
check($cashPos->drawer($merchant,'R2','xreport')['expected_cash_minor']===1000,'T11 noncash tender records do not change expected drawer cash');
$manualInvoice=$cashPos->invoice($merchant,'R2',$manualSales['manual_card']['order_id'])['html'];
check(str_contains($manualInvoice,'Unverified manual record') && str_contains($manualInvoice,'Recorded tender amount'), 'T11 invoice labels a manual tender as unverified rather than cash');
$manualCardCredit=$cashPos->credit($merchant,'R2',wp_generate_uuid4(),$manualSales['manual_card']['order_id'],1,true,$manualSession);
$manualMfsCredit=$cashPos->credit($merchant,'R2',wp_generate_uuid4(),$manualSales['manual_mfs']['order_id'],1,false,$manualSession);
check($manualCardCredit['sale']['cash_refund_minor']===0 && $manualMfsCredit['sale']['cash_refund_minor']===0
    && $cashPos->drawer($merchant,'R2','xreport')['expected_cash_minor']===1000,
    'T11 manual tender credits restore eligible stock without deducting cash or initiating a refund transfer');
$manualZ=$cashPos->drawer($merchant,'R2','close',wp_generate_uuid4(),$manualSession,1000);
check($manualZ['variance_minor']===0,'T11 manual card/mobile sale and credit records reconcile to unchanged opening cash');
wp_set_current_user(1);$t11After=$daily->report($today,$merchant,'R2');
check($t11After['manual_card_sales_minor']-$t11Before['manual_card_sales_minor']===1250 && $t11After['manual_mfs_sales_minor']-$t11Before['manual_mfs_sales_minor']===1250
    && $t11After['manual_card_returns_minor']-$t11Before['manual_card_returns_minor']===1250 && $t11After['manual_mfs_returns_minor']-$t11Before['manual_mfs_returns_minor']===1250
    && $t11After['cash_sales_minor']===$t11Before['cash_sales_minor'] && $t11After['cash_returns_minor']===$t11Before['cash_returns_minor'] && $t11After['session_ledger_mismatches']===0,
    'T11 Daily Close separates recorded card/mobile tenders and credits from real drawer cash');
update_user_meta($securityCashier,'_sfm4u_pos_registers',$t11Assignments);update_option('sfm4u_pos_max_difference_minor',$t11OldMax);update_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION,$t11OldRound);
