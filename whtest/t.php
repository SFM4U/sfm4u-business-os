<?php
namespace { function __($s,$d=null){return $s;} }
namespace {
require '/home/claude/wh/sfm4u-wholesale/src/Units.php';
require '/home/claude/wh/sfm4u-wholesale/src/Rule.php';
require '/home/claude/wh/sfm4u-wholesale/src/SellerRules.php';
use SFM4U\Wholesale\SellerRules as S; use SFM4U\Wholesale\Rule;
$pass=0;$fail=0;
function ok($c,$m){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "FAIL $m\n";}}
function err($in,$prior=null,$d=0){try{S::fromInput($in,$prior,$d);return false;}catch(\DomainException $e){return true;}}
ok(S::fromInput([],null,0)===null,'off returns null');
$r=S::fromInput(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'1,750','sfmwh_s_min'=>'১০','sfmwh_s_tq1'=>'50','sfmwh_s_tp1'=>'1700'],null,0);
ok($r['minor']==='1750','price comma'); ok($r['min']==='10','bangla digits'); ok($r['tiers']===['50'=>'1700'],'tier'); ok($r['buyer']==='approved','default approved'); ok($r['step']==='1','default step');
$v=Rule::validate($r+['currency'=>'BDT','scale'=>0,'revision'=>1]); ok($v['min']===10 && $v['tiers']===[50=>1700],'validates');
$q=Rule::quote($v,60,'piece'); ok($q['line_minor']===60*1700,'tier price applied');
$q=Rule::quote($v,10,'piece'); ok($q['line_minor']===17500,'base price at min');
try{Rule::quote($v,9,'piece');ok(false,'below min should fail');}catch(\DomainException $e){ok(true,'');}
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'']),'missing price');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'100','sfmwh_s_min'=>'0']),'min 0');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'100','sfmwh_s_min'=>'10','sfmwh_s_tq1'=>'5','sfmwh_s_tp1'=>'90']),'tier qty <= min');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'100','sfmwh_s_min'=>'10','sfmwh_s_tq1'=>'20','sfmwh_s_tp1'=>'100']),'tier price not lower');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'100','sfmwh_s_tq1'=>'20']),'tier half filled');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'12.5']),'decimals 0 rejects .5');
$r=S::fromInput(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'12.50'],null,2); ok($r['minor']==='1250','2 decimals');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'-5']),'negative');
ok(err(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'abc']),'text');
$prior=['buyer'=>'tier:gold','packs'=>['box'=>10],'pack_minor'=>['box'=>16000],'stock_unit'=>'piece','max'=>500,'group_prices'=>['gold'=>1500]];
$r=S::fromInput(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'1750','sfmwh_s_min'=>'10'],$prior,0);
ok($r['buyer']==='tier:gold' && $r['packs']===['box'=>10] && $r['pack_minor']===['box'=>16000] && $r['max']==='500' && $r['group_prices']===['gold'=>1500],'keeps manager fields');
$v=Rule::validate($r+['currency'=>'BDT','scale'=>0,'revision'=>2]); ok($v['packs']===['box'=>10],'prior validates');
$r=S::fromInput(['sfmwh_s_enabled'=>'yes','sfmwh_s_price'=>'100','sfmwh_s_min'=>'20','sfmwh_s_tq1'=>'30','sfmwh_s_tp1'=>'90','sfmwh_s_tq2'=>'60','sfmwh_s_tp2'=>'80'],null,0); ok(count($r['tiers'])===2,'two tiers');
echo "pass $pass fail $fail\n";
}
