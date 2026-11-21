<?php
namespace { function __($s,$d=null){return $s;} }
namespace {
require '/home/claude/wh/sfm4u-wholesale/src/Members/Members.php';
use SFM4U\Wholesale\Members\Members as M;
$p=0;$f=0;function ok($c,$m){global $p,$f;if($c)$p++;else{$f++;echo "FAIL $m\n";}}
function throws($fn){try{$fn();return false;}catch(\DomainException $e){return true;}}
// Luhn known: 7992739871 -> 3
ok(M::luhnDigit('7992739871')===3,'luhn known');
for($i=0;$i<200;$i++){foreach(M::TIERS as $k=>$t){$n=M::makeCardNo($t,'random_int');ok(strlen($n)===12&&M::validCardNo($n)&&$n[0]===(string)($k+1),"make $t $n");}}
$n=M::makeCardNo('business','random_int');$bad=substr($n,0,11).(((int)$n[11]+1)%10);ok(!M::validCardNo($bad),'bad check digit');
ok(M::parseScan($n)===['card_no',$n],'scan number');
ok(M::parseScan(chunk_split($n,4,' '))===['card_no',$n],'scan spaced');
ok(M::parseScan($bad)[0]==='invalid','scan bad');
$t=str_repeat('a1',16);
ok(M::parseScan('https://thenoakhali.com/?sfm_card='.$t)===['token',$t],'scan url');
ok(M::parseScan('SFM4U-CARD:'.$t)===['token',$t],'scan prefix');
ok(M::parseScan(strtoupper($t))===['token',$t],'scan bare upper');
ok(M::parseScan('https://evil.example/?x=1')[0]==='invalid','scan junk');
ok(M::parseScan("' OR 1=1 --")[0]==='invalid','scan sql');
$bn=strtr($n,['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);ok(M::parseScan($bn)===['card_no',$n],'scan bangla digits');
ok(M::parseMoney('1,250',0)===1250,'money comma');ok(M::parseMoney('৫০০',0)===500,'money bn');ok(M::parseMoney('৳ 20',0)===20,'money symbol');
ok(M::parseMoney('12.50',2)===1250,'money 2dp');ok(throws(fn()=>M::parseMoney('12.5',0)),'money frac 0dp');
ok(throws(fn()=>M::parseMoney('0',0)),'money zero');ok(throws(fn()=>M::parseMoney('-5',0)),'money neg');ok(throws(fn()=>M::parseMoney('abc',0)),'money text');
ok(M::delta('charge',100)===100,'charge');ok(M::delta('payment',100)===-100,'payment');ok(M::delta('adjust',50,'down')===-50,'adj down');ok(M::delta('adjust',50,'up')===50,'adj up');
ok(throws(fn()=>M::delta('gift',5)),'bad kind');
ok(M::parseNationalId('1234567890','nid')===['nid','1234567890'],'nid10');
ok(M::parseNationalId('১২৩৪৫৬৭৮৯০১২৩','nid')===['nid','1234567890123'],'nid13 bn');
ok(M::parseNationalId('19901234567890123','brn')===['brn','19901234567890123'],'brn17');
ok(M::parseNationalId('1234567890','brn')===null,'brn10 bad');ok(M::parseNationalId('12345','nid')===null,'short');ok(M::parseNationalId('12345678901','nid')===null,'11 digits');
$h=M::idHash('1234567890','k');ok(strlen($h)===64 && $h!==M::idHash('1234567891','k') && $h!==M::idHash('1234567890','k2'),'hash keyed');
$s1=M::parseScan('1234567890');ok($s1[0]==='id_hash' && strlen($s1[1])===64,'scan nid');
ok(M::parseScan('1234 5678 90')[0]==='id_hash','scan nid spaced');
ok(M::parseScan('19901234567890123')[0]==='id_hash','scan brn');
ok(!str_contains(M::parseScan('1234567890')[1],'1234567890'),'no plaintext');
echo "pass $p fail $f\n";
}
