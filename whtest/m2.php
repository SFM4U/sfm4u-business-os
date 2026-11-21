<?php
// M2: member price rules (pure parts). WordPress parts are in wh/tests-local/m2-member-test.php.
namespace { function __($s,$d=null){return $s;} }
namespace {
require '/home/claude/wh/sfm4u-wholesale/src/Units.php';
require '/home/claude/wh/sfm4u-wholesale/src/Rule.php';
require '/home/claude/wh/sfm4u-wholesale/src/MemberPrice.php';
use SFM4U\Wholesale\MemberPrice as M;
$pass=0;$fail=0;
function ok($c,$m){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "FAIL $m\n";}}
ok(M::qualifies(['tier'=>'business','status'=>'active']),'business active qualifies');
ok(M::qualifies(['tier'=>'black','status'=>'active','wholesale_ok'=>true]),'black paid qualifies');
ok(!M::qualifies(['tier'=>'black','status'=>'active','wholesale_ok'=>false]),'black unpaid does not');
ok(!M::qualifies(['tier'=>'business','status'=>'suspended']),'suspended does not');
ok(!M::qualifies(['tier'=>'premium','status'=>'active']),'premium does not');
ok(!M::qualifies(['tier'=>'regular','status'=>'active','wholesale_ok'=>true]),'regular never, even if flag set');
ok(!M::qualifies(null) && !M::qualifies([]),'empty does not');
ok(M::mask('1234567')==='123****','mask member no');
ok(M::mask('4123 4567 8901')==='412*********','mask card no');
ok(M::mask('12')==='12','mask short');
ok(M::freshness(1000,1000+599)===[false,true],'fresh: no recheck');
ok(M::freshness(1000,1000+600)===[true,true],'recheck after 10 min');
ok(M::freshness(1000,1000+86400)===[true,false],'stale after 24 h');
ok(M::rateOk([],100) && M::rateOk([1,2,3,4],4000) && !M::rateOk([3601,3602,3603,3604,3605],4000),'code rate limit 5/hour');
// 0.9.5: price mode and renewal list
$r=\SFM4U\Wholesale\Rule::validate(['buyer'=>'everyone','public'=>true,'minor'=>9500,'min'=>12,'tiers'=>[50=>9000],'currency'=>'BDT','scale'=>2,'revision'=>1]);
ok(\SFM4U\Wholesale\Rule::memberQuote($r,1,'piece',null,'base')['line_minor']===9500,'base mode: 1 pc at base 95');
ok(\SFM4U\Wholesale\Rule::memberQuote($r,60,'piece',null,'base')['line_minor']===60*9000,'base mode: tier by quantity');
ok(\SFM4U\Wholesale\Rule::memberQuote($r,1,'piece',null,'lowest')['line_minor']===9000,'lowest mode: 1 pc at lowest 90');
$now=strtotime('2026-11-21 12:00:00 UTC');
$rows=[['user_id'=>1,'name'=>'A','until'=>'2026-11-20'],['user_id'=>2,'name'=>'B','until'=>'2026-11-25'],['user_id'=>3,'name'=>'C','until'=>'2026-12-30'],['user_id'=>4,'name'=>'D','until'=>'bad']];
$l=M::renewalList($rows,$now,7);
ok(count($l)===2 && $l[0]['state']==='expired' && $l[1]['state']==='due' && $l[1]['name']==='B','renewal list: expired + due, sorted, others out');
ok(M::renewalList($rows,$now,60)[2]['name']==='C','renewal window 60 days includes C');
echo "pass $pass fail $fail\n";
}
