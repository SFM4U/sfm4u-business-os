<?php
namespace { function __($s,$d=null){return $s;} function esc_attr($s){return htmlspecialchars($s);} }
namespace {
require '/home/claude/wh/sfm4u-wholesale/src/Members/Members.php';
require '/home/claude/wh/sfm4u-wholesale/src/Members/Barcode.php';
use SFM4U\Wholesale\Members\Members as M; use SFM4U\Wholesale\Members\Barcode as B;
$p=0;$f=0;function ok($c,$m){global $p,$f;if($c)$p++;else{$f++;echo "FAIL $m\n";}}
ok(M::formatMemberNo(1)==='0000001','fmt1');ok(M::formatMemberNo(100)==='0000100','fmt100');ok(M::formatMemberNo(9999999)==='9999999','max');
try{M::formatMemberNo(10000000);ok(false,'over');}catch(\DomainException $e){ok(true,'');}
ok(M::parseScan('0000123')===['member_no','0000123'],'scan member');ok(M::parseScan('০০০০১২৩')===['member_no','0000123'],'scan member bn');
ok(M::parseScan('1234567890')[0]==='id_hash','nid still');
$m=B::modules('0000123');ok(count($m)===(7+3)*6+1,'module count '.count($m));ok(array_sum($m)===(7+3)*11+2,'width sum '.array_sum($m));
// checksum: start104 + sum(16*i for 0s, '1'=17,'2'=18,'3'=19)
$codes=[104,16,16,16,16,17,18,19];$s=104;foreach(array_slice($codes,1) as $i=>$c)$s+=$c*($i+1);ok($s%103===(104+16*10+17*5+18*6+19*7)%103,'chk');
$e=M::encrypt('1990123456789');ok($e!=='' && !str_contains($e,'1990123456789'),'enc hides');ok(M::decrypt($e)==='1990123456789','roundtrip');ok(M::decrypt('garbage')==='','bad blob');
$e2=M::encrypt('1990123456789');ok($e!==$e2,'random nonce');
echo "pass $p fail $f\n";}
