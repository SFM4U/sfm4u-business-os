<?php
use SFM4U\Wholesale\Members\CardLink;
wp_set_current_user(1);
$q = json_decode(file_get_contents('/tmp/pair.json'), true);
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
try { CardLink::approvePair($q['shop'], 'https://evil.example/wp-admin/admin.php?page=sfm-wr-link', $q['state']); $ok(false,'back to other host accepted'); } catch (DomainException $e) { $ok(true,''); }
try { CardLink::approvePair($q['shop'], $q['back'], 'xyz'); $ok(false,'bad state accepted'); } catch (DomainException $e) { $ok(true,''); }
$before = count(CardLink::clients());
$back = CardLink::approvePair($q['shop'], $q['back'], $q['state']);
parse_str((string) parse_url($back, PHP_URL_QUERY), $b);
$ok(strpos($back, $q['back']) === 0 && strlen($b['sfm_pair_code']) === 64 && $b['sfm_pair_state'] === $q['state'], 'redirect back with code');
$ok(strpos($back, 'secret') === false && count(CardLink::clients()) === $before + 1, 'key made, secret not in URL');
// second code for a host-mismatch redeem test
$back2 = CardLink::approvePair($q['shop'], $q['back'], $q['state']);
parse_str((string) parse_url($back2, PHP_URL_QUERY), $b2);
file_put_contents('/tmp/pair2.json', json_encode(['code' => $b['sfm_pair_code'], 'state' => $b['sfm_pair_state'], 'code2' => $b2['sfm_pair_code']]));
echo "pass $p fail $f\n";
