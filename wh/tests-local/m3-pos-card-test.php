<?php
// M3 integration: POS "SFM4U member card" tier (scan -> member price) and staff "apply card" on an order.
use SFM4U\Wholesale\{Commerce, MemberPrice};
use SFM4U\Wholesale\Members\{Members, Tiers};
use SFM4U\POS\CounterSale;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn,$code=null){try{$fn();return false;}catch(DomainException $e){return $code===null||$e->getCode()===$code;}};
if (!class_exists(CounterSale::class)) { foreach (['Runtime','RegisterProductSearch','SyntheticSale','CounterSale'] as $c) { require_once WP_PLUGIN_DIR.'/sfm4u-wholesale/modules/pos/src/'.$c.'.php'; } }
$cur=get_option('woocommerce_currency'); update_option('woocommerce_currency','BDT'); update_option('sfmwh_enabled','yes'); Members::install(); update_option(MemberPrice::OPT_PRICE_MODE,'lowest');
wp_set_current_user(1);
$merchant=7;
$mkp=function($name,$price) use ($merchant){ $x=new WC_Product_Simple(); $x->set_name($name); $x->set_regular_price($price); $x->set_status('publish'); $x->set_manage_stock(true); $x->set_stock_quantity(500); $x->update_meta_data('_sfm4u_pos_merchant_id',$merchant); $x->save(); return $x; };
$soap=$mkp('M3 Soap','100'); Commerce::saveRule($soap,['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'9000']]);
$pen=$mkp('M3 Pen','20'); // no wholesale rule
$r=wp_rand(10000,99999);
$mk=function($n,$tier) use ($r){ $u=(int)wp_insert_user(['user_login'=>"m3$n$r",'user_pass'=>wp_generate_password(20),'user_email'=>"m3$n$r@example.com"]); if($tier) Members::issue($u,$tier,0,1,'main',null,'Shop '.$n); return $u; };
$biz=$mk('biz','business'); $blk=$mk('blk','black'); $pre=$mk('pre','premium');
$items=[['product'=>$soap->get_id(),'quantity'=>2],['product'=>$pen->get_id(),'quantity'=>3]];
// intent rules
$ok($bad(fn()=>CounterSale::intent($items,'member',0,'card')), 'member tier needs a card');
$ok($bad(fn()=>CounterSale::intent($items,'retail',0,'card',Members::memberNo($biz))), 'card only with member tier');
$retail=CounterSale::intent($items,'retail',0,'card'); $ok(!isset($retail['member']), 'old intents unchanged (no member key)');
// quotes
$q=CounterSale::quote($merchant,$retail); $ok($q['total_minor']===2*10000+3*2000, 'retail total 260');
$whole=CounterSale::quote($merchant,CounterSale::intent([$items[0]],'wholesale',0,'card')); $ok($whole['total_minor']===20000, 'wholesale tier below N = retail (public rule)');
$ok(CounterSale::quote($merchant,CounterSale::intent([['product'=>$soap->get_id(),'quantity'=>12]],'wholesale',0,'card'))['total_minor']===114000, 'wholesale tier at N = 95 each');
$mi=CounterSale::intent($items,'member',0,'card',Members::memberNo($biz));
$mq=CounterSale::quote($merchant,$mi);
$ok($mq['total_minor']===2*9000+3*2000, 'business card: soap at member price 90, pen retail: '.$mq['total_minor']);
$ok($mq['member']['tier']==='business' && preg_match('/^\d{3}\*{4}$/',$mq['member']['member_no']) && $mq['member']['business_name']==='Shop biz', 'cashier sees member name, tier, masked no');
$ok($mq['digest']!==$q['digest'], 'member price changes the digest');
$ok($bad(fn()=>CounterSale::quote($merchant,CounterSale::intent($items,'member',0,'card',Members::memberNo($blk))),422), 'unpaid Business Black refused at counter');
Tiers::recordBlackPayment($blk,1,'cash','',1);
$ok(CounterSale::quote($merchant,CounterSale::intent($items,'member',0,'card',Members::memberNo($blk)))['total_minor']===24000, 'paid Business Black gets member price');
$ok($bad(fn()=>CounterSale::quote($merchant,CounterSale::intent($items,'member',0,'card',Members::memberNo($pre))),422), 'premium card refused');
$ok($bad(fn()=>CounterSale::quote($merchant,CounterSale::intent($items,'member',0,'card','0000000')),404), 'unknown card 404');
// card number (12 digits) also scans
$card=Members::cardsOf($biz)[0];
if (!empty($card['card_no'])) { $ok(CounterSale::quote($merchant,CounterSale::intent($items,'member',0,'card',(string)$card['card_no']))['total_minor']===24000, 'scan by 12-digit card number'); }
// Codex review fixes
$fl=$mkp('M3 Oil','100'); Commerce::saveRule($fl,['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','floor_minor'=>'9000']);
$ok(CounterSale::quote($merchant,CounterSale::intent([['product'=>$fl->get_id(),'quantity'=>12]],'wholesale',6000,'card'))['total_minor']===108000, 'discount down to floor allowed (12x90)');
$ok($bad(fn()=>CounterSale::quote($merchant,CounterSale::intent([['product'=>$fl->get_id(),'quantity'=>12]],'wholesale',6001,'card')),422), 'discount below floor refused');
update_user_meta($biz,'_sfmwh_buyer_status','approved'); update_user_meta($biz,'_sfmwh_price_group','gold');
$gp=$mkp('M3 Sugar','100'); Commerce::saveRule($gp,['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'8500'],'group_prices'=>['gold'=>'9100']]);
$ok(CounterSale::quote($merchant,CounterSale::intent([['product'=>$gp->get_id(),'quantity'=>2]],'member',0,'card',Members::memberNo($biz)))['total_minor']===18200, 'POS uses member gold group price 91 (same as online)');
update_user_meta($biz,'_sfmwh_buyer_status','suspended');
$ok($bad(fn()=>CounterSale::quote($merchant,CounterSale::intent($items,'member',0,'card',Members::memberNo($biz))),422), 'suspended buyer card refused at counter');
delete_user_meta($biz,'_sfmwh_buyer_status'); delete_user_meta($biz,'_sfmwh_price_group');
wc_get_product($fl->get_id())->delete(true); wc_get_product($gp->get_id())->delete(true);
// Order: staff applies card
$order=wc_create_order(); $order->set_currency('BDT'); $order->add_product(wc_get_product($soap->get_id()),2); $order->add_product(wc_get_product($pen->get_id()),1); $order->calculate_totals(); $order->set_status('on-hold'); $order->save();
$ok(abs((float)$order->get_total()-220)<0.01, 'order before: 220');
$saved=MemberPrice::applyToOrder($order, MemberPrice::fromScan(Members::memberNo($biz)), 1);
$order=wc_get_order($order->get_id());
$ok($saved===2000 && abs((float)$order->get_total()-200)<0.01, 'apply card: soap 2x90 + pen 20 = 200, saved 20');
$ok($order->get_meta('_sfmwh_member_source')==='order' && (int)$order->get_meta('_sfmwh_member_applied_by')===1, 'order records who applied');
$notes=wc_get_order_notes(['order_id'=>$order->get_id()]); $ok($notes && strpos($notes[0]->content,'member price applied')!==false, 'order note written');
$ok($bad(fn()=>MemberPrice::applyToOrder($order, MemberPrice::fromScan(Members::memberNo($biz)), 1)), 'cannot apply twice');
$paid=wc_create_order(); $paid->set_currency('BDT'); $paid->add_product(wc_get_product($soap->get_id()),2); $paid->calculate_totals(); $paid->set_status('processing'); $paid->save();
$ok($bad(fn()=>MemberPrice::applyToOrder($paid, MemberPrice::fromScan(Members::memberNo($biz)), 1)), 'cannot apply to a paid/processing order');
$o3=wc_create_order(); $o3->set_currency('BDT'); $o3->add_product(wc_get_product($soap->get_id()),2); $o3->calculate_totals(); $o3->set_status('pending'); $o3->save();
$ok($bad(fn()=>MemberPrice::applyToOrder($o3, MemberPrice::fromScan(Members::memberNo($pre)), 1)), 'premium card cannot reprice an order');
foreach ([$order,$paid,$o3] as $o) { $o->delete(true); }
foreach ([$soap,$pen] as $x) { wc_get_product($x->get_id())->delete(true); }
update_option('woocommerce_currency',$cur); delete_option(MemberPrice::OPT_PRICE_MODE);
echo "pass $p fail $f\n";
