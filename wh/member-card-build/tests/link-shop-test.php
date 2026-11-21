<?php
use SFM4U\Wholesale\Members\{Members, CardLink};
$p = 0; $f = 0;
$ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL: $m\n"; } };
$k = json_decode(file_get_contents('/tmp/link.json'), true);
// pure signing tests
$sig = CardLink::signature('s', 'GET', '/x', 1700000000, '');
$ok(strlen($sig) === 64, 'signature hex');
try { CardLink::verify('s', 'GET', '/x', '1700000000', '', $sig, 1700000100); $ok(true, ''); } catch (Throwable $e) { $ok(false, 'verify good'); }
try { CardLink::verify('s', 'GET', '/x', '1700000000', '', $sig, 1700000400); $ok(false, 'expired accepted'); } catch (DomainException $e) { $ok($e->getMessage() === 'expired', 'expired refused'); }
try { CardLink::verify('t', 'GET', '/x', '1700000000', '', $sig, 1700000100); $ok(false, 'wrong secret accepted'); } catch (DomainException $e) { $ok(true, ''); }
try { CardLink::verify('s', 'GET', '/y', '1700000000', '', $sig, 1700000100); $ok(false, 'path change accepted'); } catch (DomainException $e) { $ok(true, ''); }
// shop config
update_option(CardLink::OPT_ROLE, 'shop');
try { CardLink::setMaster('ftp://x', $k['id'], $k['secret']); $ok(false, 'bad url accepted'); } catch (DomainException $e) { $ok(true, ''); }
try { CardLink::setMaster('http://127.0.0.1:8899', 'shop_bad', $k['secret']); $ok(false, 'bad key accepted'); } catch (DomainException $e) { $ok(true, ''); }
CardLink::setMaster('http://127.0.0.1:8899', $k['id'], $k['secret']);
$ok(strpos((string) get_option(CardLink::OPT_MASTER)['secret_enc'], $k['secret']) === false, 'secret stored encrypted');
$pong = CardLink::call('/ping');
$ok(!empty($pong['ok']), 'ping ok: ' . json_encode($pong));
$ok(CardLink::findLocal($k['no']) === null, 'member not on this shop');
$m = CardLink::remoteLookup($k['no']);
$ok($m && $m['name'] === 'CENTRAL ONLY' && $m['member_no'] === $k['no'] && $m['tier'] === 'regular' && $m['status'] === 'active', 'remote lookup by member no: ' . json_encode($m));
$ok($m && !isset($m['dob']) && strpos(json_encode($m), '1999') === false && strpos(json_encode($m), 'example.com') === false, 'no private data returned');
$ok($m && $m['district'] === 'Feni', 'district returned');
$ok(CardLink::remoteLookup('0999999') === null, 'unknown member: not found');
// wrong secret
CardLink::setMaster('http://127.0.0.1:8899', $k['id'], str_repeat('a', 64));
try { CardLink::call('/ping'); $ok(false, 'wrong secret worked'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), '401') !== false, 'wrong secret refused (401)'); }
CardLink::setMaster('http://127.0.0.1:8899', $k['id'], $k['secret']);
update_option(CardLink::OPT_ROLE, 'off');
try { CardLink::call('/ping'); $ok(false, 'off still calls'); } catch (DomainException $e) { $ok(true, ''); }
echo "pass $p fail $f\n";
