<?php
// M1 (0.9.0-dev): public two-price table, shared tier text, vendor floor, member quote (used by M2/M3).
namespace { function __($s,$d=null){return $s;} }
namespace {
require '/home/claude/wh/sfm4u-wholesale/src/Units.php';
require '/home/claude/wh/sfm4u-wholesale/src/Rule.php';
require '/home/claude/wh/sfm4u-wholesale/src/SellerRules.php';
require '/home/claude/wh/sfm4u-wholesale/src/PriceBox.php';
use SFM4U\Wholesale\SellerRules as S; use SFM4U\Wholesale\Rule; use SFM4U\Wholesale\PriceBox;
$pass=0;$fail=0;
function ok($c,$m){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "FAIL $m\n";}}
function bad(callable $f){try{$f();return false;}catch(\DomainException $e){return true;}}
$base=['currency'=>'BDT','scale'=>2,'revision'=>1];

// --- Tier text (same format as AlgiBazar Product Experience) ---
ok(Rule::parseTierText('12=95, 50=90',2)===[12=>9500,50=>9000],'tier text basic');
ok(Rule::parseTierText("৫০=৯০\n১২=৯৫",2)===[12=>9500,50=>9000],'bangla digits, newline, sorted');
ok(Rule::parseTierText('12 = ৳95.50; 24:tk 90',2)===[12=>9550,24=>9000],'taka sign, colon, semicolon, decimals');
ok(Rule::parseTierText('',2)===[],'empty text');
ok(bad(fn()=>Rule::parseTierText('12=95, 50=96',2)),'bigger qty must be cheaper');
ok(bad(fn()=>Rule::parseTierText('abc',2)),'garbage text');
ok(bad(fn()=>Rule::parseTierText('1=95',2)),'qty 1 rejected');
ok(bad(fn()=>Rule::parseTierText('12=0',2)),'price 0 rejected');
ok(bad(fn()=>Rule::parseTierText('12=9.5',0)),'too many decimals');
ok(Rule::tierText([12=>9500,50=>9050],2)==='12=95, 50=90.5','tier text round trip');

// --- Public rule: section 4 steps 2 and 3 ---
$pub=Rule::validate(['buyer'=>'everyone','public'=>true,'minor'=>9500,'min'=>12,'tiers'=>[50=>9000]]+$base);
ok(!empty($pub['public']),'public kept');
ok(Rule::quote($pub,1,'piece')===null,'1 piece -> retail (null)');
ok(Rule::quote($pub,11,'piece')===null,'below N -> retail');
ok(Rule::quote($pub,12,'piece')['line_minor']===12*9500,'at N -> wholesale');
ok(Rule::quote($pub,50,'piece')['line_minor']===50*9000,'tier N -> tier price');
ok(bad(fn()=>Rule::validate(['buyer'=>'approved','public'=>true,'minor'=>9500,'min'=>12]+$base)),'public needs everyone');
ok(bad(fn()=>Rule::validate(['buyer'=>'everyone','public'=>true,'minor'=>9500,'min'=>1]+$base)),'public needs min >= 2');
$priv=Rule::validate(['buyer'=>'approved','minor'=>9500,'min'=>12]+$base);
ok(!isset($priv['public']) && bad(fn()=>Rule::quote($priv,11,'piece')),'non-public keeps strict minimum');

// --- Public table rows ---
ok(PriceBox::publicRows($pub)===[['from'=>1,'minor'=>null],['from'=>12,'minor'=>9500],['from'=>50,'minor'=>9000]],'public rows');

// --- Vendor floor (section 4 step 4) ---
$fl=Rule::validate(['buyer'=>'everyone','public'=>true,'minor'=>9500,'min'=>12,'tiers'=>[50=>9000],'floor_minor'=>8800]+$base);
ok($fl['floor_minor']===8800,'floor kept');
ok(bad(fn()=>Rule::validate(['minor'=>9500,'min'=>12,'tiers'=>[50=>8000],'floor_minor'=>8800]+$base)),'tier below floor rejected');
ok(bad(fn()=>Rule::validate(['minor'=>9500,'min'=>12,'group_prices'=>['gold'=>8000],'floor_minor'=>8800]+$base)),'group price below floor rejected');
ok(bad(fn()=>Rule::validate(['minor'=>9500,'min'=>12,'packs'=>['box'=>10],'pack_minor'=>['box'=>80000],'floor_minor'=>8800]+$base)),'pack price below floor rejected');
ok(bad(fn()=>Rule::validate(['minor'=>8000,'min'=>12,'floor_minor'=>8800]+$base)),'base below floor rejected');

// --- Member quote (section 4 step 1): any quantity ---
ok(Rule::memberQuote($pub,1,'piece')['line_minor']===9000,'member, no group price: lowest price at 1 piece');
ok(Rule::memberQuote($pub,3,'piece',9200)['line_minor']===3*9200,'member group price at 3');
ok(Rule::memberQuote($pub,60,'piece',9200)['line_minor']===60*9000,'tier lower than group applies');
ok(Rule::memberQuote($priv,1,'piece')['member']===true,'member ignores min');
ok(bad(fn()=>Rule::memberQuote($pub,0,'piece')),'member qty 0 rejected');
$bx=Rule::validate(['minor'=>9500,'min'=>12,'packs'=>['box'=>10]]+$base);
ok(Rule::memberQuote($bx,20,'box')['pack_count']===2,'member pack count');
ok(bad(fn()=>Rule::memberQuote($bx,15,'box')),'member pack size enforced');

// --- Vendor form ---
$f=['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'95','sfmwh_s_min'=>'12','sfmwh_s_tiers_text'=>'50=90, 100=85','sfmwh_s_public'=>'yes','sfmwh_s_floor'=>'80'];
$r=S::fromInput($f,null,2);
ok($r['buyer']==='everyone' && $r['public']===true,'form public -> everyone');
ok($r['tiers']===['50'=>'9000','100'=>'8500'],'form tier text');
ok($r['floor_minor']==='8000','form floor');
$v=Rule::validate($r+$base); ok($v['public']===true && $v['floor_minor']===8000 && $v['tiers']===[50=>9000,100=>8500],'form validates');
ok(bad(fn()=>S::fromInput(['sfmwh_s_tiers_text'=>'10=90']+$f,null,2)),'form tier qty <= min rejected');
ok(bad(fn()=>S::fromInput(['sfmwh_s_tiers_text'=>'50=96']+$f,null,2)),'form tier price not lower rejected');
ok(bad(fn()=>S::fromInput(['sfmwh_s_floor'=>'x']+$f,null,2)),'form bad floor');
ok(bad(fn()=>Rule::validate(S::fromInput(['sfmwh_s_floor'=>'86']+$f,null,2)+$base)),'form floor above tier rejected on save');
$off=S::fromInput(['sfmwh_s_public'=>'']+$f,['buyer'=>'everyone','public'=>true],2);
ok($off['buyer']==='approved' && $off['public']===false,'turning public off returns to approved buyers');
$keep=S::fromInput(['sfmwh_s_public'=>'']+$f,['buyer'=>'tier:gold'],2);
ok($keep['buyer']==='tier:gold','non-public keeps manager buyer');
$rows=S::fromInput(['sfmwh_s_tiers_text'=>'','sfmwh_s_tq1'=>'24','sfmwh_s_tp1'=>'92']+$f,null,2);
ok($rows['tiers']===['24'=>'9200'],'old row fields still work');
ok(!isset(Rule::validate(S::fromInput(['sfmwh_s_floor'=>'']+$f,null,2)+$base)['floor_minor']),'empty floor allowed');

echo "pass $pass fail $fail\n";
}
