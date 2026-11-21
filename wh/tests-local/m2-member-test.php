<?php
// M2 integration: member price at any quantity (local card, Business Black payment, linked member), order record.
use SFM4U\Wholesale\{Commerce, MemberPrice};
use SFM4U\Wholesale\Members\{Members, Tiers};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
update_option('sfmwh_enabled','yes'); Members::install(); update_option(MemberPrice::OPT_PRICE_MODE,'lowest'); update_option(MemberPrice::OPT_CARD_MODE,'code');
wp_set_current_user(1);
$s = new WC_Product_Simple(); $s->set_name('M2 Rice'); $s->set_regular_price('100'); $s->set_status('publish'); $s->save();
Commerce::saveRule($s, ['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'9000']]);
$a = new WC_Product_Simple(); $a->set_name('M2 Approved-only'); $a->set_regular_price('100'); $a->set_status('publish'); $a->save();
Commerce::saveRule($a, ['buyer'=>'approved','minor'=>'8000','min'=>'10','group_prices'=>['gold'=>7800]]);
$r = wp_rand(10000,99999);
$mk = function($n) use ($r) { return (int) wp_insert_user(['user_login'=>"m2$n$r",'user_pass'=>wp_generate_password(20),'user_email'=>"m2$n$r@example.com"]); };
$sub = function() { WC()->cart->calculate_totals(); return (float) WC()->cart->get_subtotal(); };
wc_load_cart();
// 1. Plain customer: retail below N
$plain = $mk('plain'); wp_set_current_user($plain); WC()->cart->empty_cart();
WC()->cart->add_to_cart($s->get_id(), 2); $ok(abs($sub()-200)<0.01, 'plain customer 2 pcs retail 200');
// 2. Local Business card: lowest price at any quantity (90 = lowest tier)
$biz = $mk('biz'); Members::issue($biz, 'business', 0, 1, 'main', null, 'Rahim Store'); MemberPrice::forget($biz);
wp_set_current_user($biz); WC()->cart->empty_cart();
WC()->cart->add_to_cart($s->get_id(), 2); $ok(abs($sub()-180)<0.01, 'business member 2 pcs = 180: '.WC()->cart->get_subtotal());
$k = WC()->cart->add_to_cart($a->get_id(), 1); $ok($k!==false && abs($sub()-260)<0.01, 'member gets approved-only product at 1 pc (80): '.WC()->cart->get_subtotal());
// Member who is also an approved gold buyer keeps the group price
update_user_meta($biz,'_sfmwh_buyer_status','approved'); update_user_meta($biz,'_sfmwh_price_group','gold'); MemberPrice::forget($biz);
$ok(abs($sub()-258)<0.01, 'approved gold member keeps group price 78: '.WC()->cart->get_subtotal());
update_user_meta($biz,'_sfmwh_buyer_status','suspended'); MemberPrice::forget($biz);
$ok(MemberPrice::memberFor($biz)===null, 'suspended buyer loses member price');
delete_user_meta($biz,'_sfmwh_buyer_status'); delete_user_meta($biz,'_sfmwh_price_group'); MemberPrice::forget($biz);
// order record
$order = wc_create_order(['customer_id'=>$biz]); MemberPrice::saveOrder($order, []);
$ok($order->get_meta('_sfmwh_member_price')==='yes' && preg_match('/^\d{3}\*{4}$/', $order->get_meta('_sfmwh_member_no_masked')) && $order->get_meta('_sfmwh_member_tier')==='business', 'order stores masked member no');
$order->delete(true);
// 3. Business Black: only when paid
$blk = $mk('blk'); Members::issue($blk, 'black', 0, 1, 'main', null, 'Karim Store'); MemberPrice::forget($blk);
wp_set_current_user($blk); WC()->cart->empty_cart(); WC()->cart->add_to_cart($s->get_id(), 2);
$ok(abs($sub()-200)<0.01, 'unpaid Business Black = retail');
Tiers::recordBlackPayment($blk, 1, 'cash', '', 1); MemberPrice::forget($blk);
$ok(abs($sub()-180)<0.01, 'paid Business Black = member price');
// 4. Premium card: no member price
$pre = $mk('pre'); Members::issue($pre, 'premium', 0, 1); MemberPrice::forget($pre);
$ok(MemberPrice::memberFor($pre)===null, 'premium card gets no wholesale');
// 5. Linked member (account site answer stored on the account)
$lk = $mk('lk'); update_user_meta($lk,'sfm_wr_member_no','7654321'); update_user_meta($lk,'sfm_wr_link_tier','business'); update_user_meta($lk,'sfm_wr_link_status','active');
update_user_meta($lk,'sfmwh_member_checked', time());
$ok(MemberPrice::memberFor($lk)['source']==='link', 'fresh linked business member qualifies');
update_user_meta($lk,'sfmwh_member_ok','no'); MemberPrice::forget($lk);
$ok(MemberPrice::memberFor($lk)===null, 'account site says not wholesale -> none');
delete_user_meta($lk,'sfmwh_member_ok'); update_user_meta($lk,'sfmwh_member_checked', time()-2*86400); MemberPrice::forget($lk);
$ok(MemberPrice::memberFor($lk)===null, 'stale link (> 24 h, no check possible) -> none');
// 6. Guest never
wp_set_current_user(0); $ok(MemberPrice::current()===null, 'guest has no member price');
ob_start(); MemberPrice::box(); $h = ob_get_clean(); $ok($h==='' || strpos($h,'Log in')!==false, 'guest box: login hint or nothing');
wp_set_current_user($biz); MemberPrice::forget($biz);
ob_start(); MemberPrice::box(); $h = ob_get_clean(); $ok(strpos($h,'wholesale price at any quantity is applied')!==false, 'member sees applied notice');
WC()->cart->empty_cart();
foreach ([$s,$a] as $x) { wc_get_product($x->get_id())->delete(true); }
// 0.9.5 settings: base price mode (default) and card-only mode
update_option(MemberPrice::OPT_PRICE_MODE,'base'); wp_set_current_user(1);
$s2 = new WC_Product_Simple(); $s2->set_name('M2b'); $s2->set_regular_price('100'); $s2->set_status('publish'); $s2->save();
Commerce::saveRule($s2, ['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'9000']]);
wp_set_current_user($biz); MemberPrice::forget($biz); WC()->cart->empty_cart();
$kk=WC()->cart->add_to_cart($s2->get_id(), 2); if(!$kk){ echo 'NOTICES:'.wp_json_encode(wc_get_notices())."\n"; } $ok(abs($sub()-190)<0.01, 'base mode: member 2 pcs = 95 each: '.WC()->cart->get_subtotal());
delete_option(MemberPrice::OPT_PRICE_MODE); $ok(MemberPrice::priceMode()==='base' && MemberPrice::cardMode()==='code', 'safe defaults: base + code');
$plain2=$mk('plain2'); wp_set_current_user($plain2); WC()->cart->empty_cart(); MemberPrice::forget($plain2);
update_option(MemberPrice::OPT_CARD_MODE,'card_only');
WC()->session->set('sfmwh_card_member', ['uid'=>$plain2,'raw'=>Members::memberNo($biz),'checked'=>time(),'member'=>MemberPrice::fromScan(Members::memberNo($biz))]);
$ok(MemberPrice::memberFor($plain2)['source']==='card', 'card-only mode: typed card gives member price');
update_option(MemberPrice::OPT_CARD_MODE,'code'); MemberPrice::forget($plain2);
$ok(MemberPrice::memberFor($plain2)===null, 'code mode: typed card alone gives nothing');
WC()->session->set('sfmwh_card_member', null); WC()->cart->empty_cart(); wc_get_product($s2->get_id())->delete(true);
echo "pass $p fail $f\n";
