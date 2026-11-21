<?php
wp_set_current_user(1);
$invoiceSettings=new \SFM4U\POS\InvoiceProfilePage();
$invoiceProfilePost=['merchant'=>(string)$merchant,'name'=>'সিন্থেটিক দোকান / Synthetic Store','address'=>'Synthetic local issuing address','bin'=>'SYNTHETIC-NOT-A-BIN','note'=>'Synthetic invoice preview only'];
$_POST=$invoiceProfilePost+['_wpnonce'=>'invalid'];
try{$invoiceSettings->save();check(false,'invoice nonce must fail');}catch(DomainException $error){check($error->getCode()===403,'T19 merchant invoice settings require administrator and nonce');}
$_POST=$invoiceProfilePost+['_wpnonce'=>wp_create_nonce('sfm4u_invoice_profile')];$invoiceSettings->save();$_POST=[];
$invoiceRounding=get_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION,'100');update_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION,'100');
wp_set_current_user($securityCashier);
$invoiceSession=$cashPos->drawer($merchant,'R2','open',wp_generate_uuid4(),0,0)['session_id'];
switch_to_locale('bn_BD');load_textdomain('sfm4u-wholesale','/source/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo','bn_BD');
$invoiceSale=$cashPos->sale($merchant,'R2',wp_generate_uuid4(),$t15Pid,1,1300,$invoiceSession);
$invoiceOrder=wc_get_order($invoiceSale['order_id']);$invoiceFee=array_values($invoiceOrder->get_items('fee'))[0];
check($invoiceFee->get_name()===__('Cash rounding','sfm4u-wholesale') && (string)$invoiceFee->get_meta('_sfm4u_cash_rounding')==='1',
    'T19 Bangla cash sale persists a locale-independent rounding fee marker');
restore_previous_locale();unload_textdomain('sfm4u-wholesale');
$invoiceBefore=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$wpdb->prefix.'sfm4u_inventory_requests'));
$invoiceHtml=$cashPos->invoice($merchant,'R2',$invoiceSale['order_id'])['html'];
check(str_contains($invoiceHtml,$invoiceSale['number']) && str_contains($invoiceHtml,'SYNTHETIC-NOT-A-BIN') && str_contains($invoiceHtml,'COPY')
    && str_contains($invoiceHtml,'VAT rate') && str_contains($invoiceHtml,'0.00%'), 'T19 installed invoice template renders recorded serial, merchant BIN, VAT and copy mark');
wp_set_current_user(1);$_POST=array_replace($invoiceProfilePost,['name'=>'Changed synthetic profile','bin'=>'CHANGED-SYNTHETIC-BIN','_wpnonce'=>wp_create_nonce('sfm4u_invoice_profile')]);$invoiceSettings->save();$_POST=[];
wp_set_current_user($securityCashier);
check($cashPos->invoice($merchant,'R2',$invoiceSale['order_id'])['html']===$invoiceHtml
    && $invoiceBefore===(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$wpdb->prefix.'sfm4u_inventory_requests')),
    'T19 invoice reprint retains the issued merchant profile and creates no new financial event');
$invoiceCredit=$cashPos->credit($merchant,'R2',wp_generate_uuid4(),$invoiceSale['order_id'],1,true,$invoiceSession);
check($invoiceCredit['sale']['refund_minor']===1300 && (int)wc_get_product($t15Pid)->get_stock_quantity()===6,
    'Bangla sale refunded in English restores original cash rounding and stock exactly once');
$creditInvoice=$cashPos->invoice($merchant,'R2',$invoiceCredit['order_id'])['html'];
check(str_contains($creditInvoice,'Credit note') && str_contains($creditInvoice,$invoiceSale['number']), 'T19 credit note prints its own committed serial and original receipt reference');
$cashPos->drawer($merchant,'R2','close',wp_generate_uuid4(),$invoiceSession,0);
$tampered=$invoiceSale;$tampered['sale']['packs']=1;$tampered['sale']['unit_minor']=1000;$tampered['sale']['vat_minor']=150;$tampered['sale']['rounding_minor']=0;
$tampered['sale']['total_minor']=1150;$tampered['sale']['tender_minor']=1150;$tampered['sale']['name']='<img src=x onerror=alert(1)>';
$vatInvoice=\SFM4U\POS\InvoiceTemplate::render($tampered);
check(str_contains($vatInvoice,'15.00%') && str_contains($vatInvoice,'1.50') && !str_contains($vatInvoice,'<img'), 'T19 nonzero VAT fields use exact minor units and escape untrusted product text');
$tampered['sale']['total_minor']=1149;
try{\SFM4U\POS\InvoiceTemplate::render($tampered);check(false,'invoice mismatch must fail');}catch(DomainException){check(true,'T19 inconsistent snapshot totals cannot be printed as an invoice');}
wp_set_current_user($buyer);
try{$cashPos->invoice($merchant,'R2',$invoiceSale['order_id']);check(false,'buyer invoice must fail');}catch(DomainException $error){check($error->getCode()===403,'T19 customer cannot enumerate another register invoice');}
wp_set_current_user($securityCashier);
file_put_contents('/fixture-out/t19-invoice-en.html','<!doctype html><html lang="en"><meta charset="utf-8">'.\SFM4U\POS\InvoiceTemplate::styles().'<body>'.$invoiceHtml.'</body></html>');
switch_to_locale('bn_BD');load_textdomain('sfm4u-wholesale','/source/sfm4u-wholesale/languages/sfm4u-wholesale-bn_BD.mo','bn_BD');
$invoiceBangla=$cashPos->invoice($merchant,'R2',$invoiceSale['order_id'])['html'];
check(str_contains($invoiceBangla,'চালান') && str_contains($invoiceBangla,'অনুলিপি'), 'T19 invoice template renders Bengali headings and copy marker');
file_put_contents('/fixture-out/t19-invoice-bn.html','<!doctype html><html lang="bn"><meta charset="utf-8">'.\SFM4U\POS\InvoiceTemplate::styles().'<body>'.$invoiceBangla.'</body></html>');
restore_previous_locale();unload_textdomain('sfm4u-wholesale');update_option(\SFM4U\POS\SyntheticSale::CASH_ROUNDING_OPTION,$invoiceRounding);wp_set_current_user(1);
