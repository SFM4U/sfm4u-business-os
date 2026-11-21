<?php
use SFM4U\Wholesale\Members\CardLink;
wp_set_current_user(1);
$d = json_decode(file_get_contents('/tmp/pair2.json'), true);
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
// redeem code2 pretending to be another shop -> refused, and code2 is burnt
$r = wp_remote_post('http://127.0.0.1:8899/?rest_route=' . rawurlencode('/sfm4u-card/v1/pair'), ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['code' => $d['code2'], 'shop' => 'https://evil.example/'])]);
$ok(wp_remote_retrieve_response_code($r) === 400, 'other host cannot redeem');
$r = wp_remote_post('http://127.0.0.1:8899/?rest_route=' . rawurlencode('/sfm4u-card/v1/pair'), ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['code' => $d['code2'], 'shop' => home_url('/')])]);
$ok(wp_remote_retrieve_response_code($r) === 400, 'code burnt after failed try');
try { CardLink::finishPair($d['code'], str_repeat('a', 32)); $ok(false, 'wrong state accepted'); } catch (DomainException $e) { $ok(true, ''); }
// state transient was consumed by the failed try -> start again is needed; re-create it to test the good path
set_transient('sfm_wr_pair_state_1', ['state' => $d['state'], 'url' => 'http://127.0.0.1:8899'], 900);
$site = CardLink::finishPair($d['code'], $d['state']);
$ok($site !== '' && CardLink::role() === 'shop', 'connected: ' . $site);
$ok(CardLink::call('/ping')['ok'] === true, 'signed ping works');
set_transient('sfm_wr_pair_state_1', ['state' => $d['state'], 'url' => 'http://127.0.0.1:8899'], 900);
try { CardLink::finishPair($d['code'], $d['state']); $ok(false, 'code reused'); } catch (DomainException $e) { $ok(true, ''); }
echo "pass $p fail $f\n";
