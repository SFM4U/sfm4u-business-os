<?php
// M4 (0.9.6-dev): Rule::rescale — store decimals vs rule decimals (the live "price box missing" bug).
// Rewritten 2026-11-21 by Claude from the 0.9.6 description in Skill 209 v4 (the original m4.php
// exists only on the Owner's Mac). Going up is exact; going down only if no paisa is lost.
namespace { function __($s,$d=null){return $s;} }
namespace {
require '/home/claude/wh/sfm4u-wholesale/src/Units.php';
require '/home/claude/wh/sfm4u-wholesale/src/Rule.php';
use SFM4U\Wholesale\Rule;
$p=0;$f=0;function ok($c,$m){global $p,$f;if($c)$p++;else{$f++;echo "FAIL $m\n";}}
function throws($fn){try{$fn();return false;}catch(\DomainException $e){return true;}}

$r0=['scale'=>0,'minor'=>1750,'min'=>10,'tiers'=>[50=>1650,100=>1600],'floor_minor'=>1500,'group_prices'=>['business'=>1600]];
$up=Rule::rescale($r0,2);
ok($up['scale']===2,'0->2 scale set');
ok($up['minor']===175000,'0->2 base 1750 -> 175000 paisa');
ok($up['tiers']===[50=>165000,100=>160000],'0->2 tiers exact, keys kept');
ok($up['floor_minor']===150000,'0->2 floor');
ok($up['group_prices']===['business'=>160000],'0->2 member group price');
ok($up['min']===10,'quantity minimum unchanged');
ok(Rule::rescale($up,0)===$r0,'2->0 round trip exact when no paisa');
ok(Rule::rescale($r0,0)===$r0,'same scale returns rule unchanged');
$paisa=['scale'=>2,'minor'=>175050,'tiers'=>[]];
ok(throws(fn()=>Rule::rescale($paisa,0)),'2->0 refused when paisa would be lost');
ok(throws(fn()=>Rule::rescale(['scale'=>2,'minor'=>100,'tiers'=>[50=>99]],0)),'tier with paisa refused');
ok(throws(fn()=>Rule::rescale($r0,7)),'precision > 6 refused');
ok(throws(fn()=>Rule::rescale($r0,-1)),'negative precision refused');
ok(Rule::rescale(['minor'=>1750],2)===['minor'=>1750],'missing scale treated as 2 (no change at 2)');
echo "pass $p fail $f\n";
}
