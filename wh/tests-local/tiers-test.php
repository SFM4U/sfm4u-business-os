<?php
use SFM4U\Wholesale\Members\{Members, Tiers, CardView};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
wp_set_current_user(1); delete_option(Tiers::OPT);
$r = wp_rand(10000,99999);
$u = (int) wp_insert_user(['user_login'=>"tier$r",'user_pass'=>wp_generate_password(20),'user_email'=>"tier$r@example.com",'display_name'=>"Tier $r"]);
$ok(in_array('black', Members::TIERS, true) && Members::tierLabel('black')==='Business Black member', 'black tier exists');
$no = Members::makeCardNo('black', fn($a,$b)=>5); $ok($no[0]==='4' && Members::validCardNo($no), 'black card number starts with 4');
try { Tiers::recordBlackPayment($u, 1, 'cash', '', 1); $ok(false,'payment without black card'); } catch (DomainException $e) { $ok(true,''); }
$c = Members::issue($u, 'black', 0, 1, 'main', null, 'Rahim Store');
$ok($c['tier']==='black' && $c['business_name']==='Rahim Store', 'black card issued with business name');
$ok(!Tiers::blackActive($u), 'not active before payment');
try { Tiers::recordBlackPayment($u, 1, 'bkash', '', 1); $ok(false,'bkash without ref'); } catch (DomainException $e) { $ok(true,''); }
$now = strtotime('2026-11-20 12:00:00 UTC');
$until = Tiers::recordBlackPayment($u, 1, 'cash', '', 1, $now);
$ok($until==='2026-12-20', 'one month = 30 days: '.$until);
$until2 = Tiers::recordBlackPayment($u, 2, 'bkash', 'TX123', 1, $now);
$ok($until2==='2027-02-18', 'extends from current end: '.$until2);
$ok(Tiers::blackActive($u, $now) && !Tiers::blackActive($u, strtotime('2027-02-19 12:00:00 UTC')), 'active window');
$ok(count(Tiers::blackLog($u))===2 && Tiers::blackLog($u)[1]['fee']===2000, 'payment log');
$html = CardView::render(Members::find('id',(int)$c['id']), 'en', true);
$ok(strpos($html,'BUSINESS BLACK')!==false && strpos($html,'#d4af37')!==false && strpos($html,'Rahim Store')!==false, 'card shows BUSINESS BLACK in gold');
// premium gift
$u2 = (int) wp_insert_user(['user_login'=>"prem$r",'user_pass'=>wp_generate_password(20),'user_email'=>"prem$r@example.com"]);
$ok(Tiers::checkGift($u2,'main',200000,'P-1')==='', 'no premium card -> no gift');
Members::issue($u2, 'premium', 0, 1);
$d = 10 ** Members::decimals();
$ok(Tiers::checkGift($u2,'main',999*$d,'P-2')==='', 'below threshold -> no gift');
$ok(Tiers::checkGift($u2,'main',1000*$d,'P-3')==='বিশেষ উপহার', 'at threshold -> gift');
Tiers::saveRules(['gift_min'=>500,'gift_text'=>'Shirt','black_fee'=>1000,'black_days'=>30]);
$ok(Tiers::checkGift($u2,'main',600*$d,'P-4')==='Shirt', 'rules change applies');
$g = Tiers::gifts($u2); $ok(count($g)===2 && $g[0]['given']==='', 'gift log');
Tiers::markGiftGiven($u2, 0, 1); $ok(Tiers::gifts($u2)[0]['given']!=='', 'mark given');
$ok(Tiers::isOnlineOnly('regular') && !Tiers::isOnlineOnly('premium'), 'regular is online only');
delete_option(Tiers::OPT);
echo "pass $p fail $f\n";
