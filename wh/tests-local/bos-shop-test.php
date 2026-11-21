<?php
use SFM4U\Wholesale\Members\{CardLink, MemberLink, Members};
use SFM4U\Wholesale\Market\MarketConnect;
$p = 0; $f = 0; $ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL: $m\n"; } };
$k = json_decode(file_get_contents('/tmp/bos.json'), true);
$phase = $GLOBALS['argv'][count($GLOBALS['argv']) - 1] ?? '';
$phase = getenv('PHASE') ?: 'a';
$W = 'php /home/claude/wplocal/wpcli.phar --allow-root --path=/home/claude/wplocal/site';
$code = fn () => trim((string) shell_exec("$W eval-file /home/claude/wh/tests-local/bos-master-code.php 2>/dev/null"));
// pure
$e = MemberLink::newPending('x', 1000, fn ($a, $b) => 42);
$ok($e['code'] === '000042' && $e['exp'] === 1000 + MemberLink::TTL, 'pending code padded');
$ok(MemberLink::check($e, '000042', 1001)[0] === true, 'right code ok');
$ok(MemberLink::check($e, '000042', 1000 + MemberLink::TTL + 1)[2] === 'expired', 'expired');
$r = MemberLink::check($e, '111111', 1001); $ok(!$r[0] && $r[1]['tries'] === 1, 'wrong code counts try');
$e4 = $e; $e4['tries'] = 4; $r = MemberLink::check($e4, '111111', 1001); $ok($r[1] === null && $r[2] === 'wrong', '5th wrong kills code');
$ok(MemberLink::check(['tries' => 5] + $e, '000042', 1001)[2] === 'too_many', 'locked');
$ok(MarketConnect::rateOk([time() - 10, time() - 20], time()) && !MarketConnect::rateOk(array_fill(0, 5, time() - 5), time()) && MarketConnect::rateOk(array_fill(0, 5, time() - 4000), time()), 'rate limit');
// connect
update_option(CardLink::OPT_ROLE, 'off'); delete_option(CardLink::OPT_MASTER);
$ok(!MarketConnect::on(), 'off before pairing');
$ok(MarketConnect::accountMenu(['dashboard' => 'D', 'customer-logout' => 'L']) === ['dashboard' => 'D', 'customer-logout' => 'L'], 'no menu when off');
update_option(CardLink::OPT_ROLE, 'shop');
CardLink::setMaster('http://127.0.0.1:8899', $k['id'], $k['secret']);
$ok(MarketConnect::on(), 'on after pairing');
$ok(array_keys(MarketConnect::accountMenu(['dashboard' => 'D', 'customer-logout' => 'L'])) === ['dashboard', MarketConnect::ENDPOINT, 'customer-logout'], 'menu before logout');
if (!get_role('seller')) { add_role('seller', 'Vendor', ['read' => true, 'seller' => true]); }
$mk = function ($login, $email, $role) { $u = get_user_by('email', $email); if ($u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'user_email' => $email, 'role' => $role, 'first_name' => 'New', 'last_name' => 'Seller']); };
$s1 = $mk('mseller', 'mseller@example.com', 'seller');
// unknown person: same message, no code anywhere
$msg1 = MemberLink::shopStart('nobody-here@example.com');
$ok(strpos($msg1, 'If this is an SFM4U member') === 0, 'same answer for unknown');
$ok($code() === 'none', 'no code for unknown');
// real member by mobile
$msg2 = MemberLink::shopStart('01711000111');
$ok($msg1 === $msg2, 'same answer for member (no enumeration)');
$c = $code();
$ok(preg_match('/^\d{6}$/', $c) === 1, 'code waiting on master: ' . $c);
try { MemberLink::shopFinish('01711000111', $c === '123456' ? '654321' : '123456'); $ok(false, 'wrong code accepted'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), '422') !== false, 'wrong code refused'); }
$m = MemberLink::shopFinish('01711000111', $c);
$ok($m['member_no'] === $k['no'] && $m['tier'] === 'regular' && !isset($m['phone']) && strpos(json_encode($m), '01711') === false, 'linked member, no private data');
try { MemberLink::shopFinish('01711000111', $c); $ok(false, 'code reused'); } catch (DomainException $e) { $ok(true, ''); }
MemberLink::saveLink($s1, $m);
$ok(MemberLink::linked($s1)['member_no'] === $k['no'], 'link saved on seller');
$s2 = $mk('mseller2', 'mseller2@example.com', 'seller');
try { MemberLink::saveLink($s2, $m); $ok(false, 'same member on 2 users'); } catch (DomainException $e) { $ok(true, ''); }
// 5 wrong codes kill it
MemberLink::shopStart($k['no']);
$c2 = $code();
for ($i = 0; $i < 5; $i++) { try { MemberLink::shopFinish($k['no'], $c2 === '000000' ? '000001' : '000000'); } catch (DomainException $e) {} }
try { MemberLink::shopFinish($k['no'], $c2); $ok(false, 'code still works after 5 wrong'); } catch (DomainException $e) { $ok(true, ''); }
$ok($code() === 'none', 'dead code removed');
// plan request
$msg = MemberLink::shopPlanRequest($s1, 'black', 'Test Store', 'https://algibazar.com/store/test');
$ok(strpos($msg, 'Request received') === 0, 'plan request sent');
$ok(strpos(MemberLink::shopPlanRequest($s1, 'black', 'Test Store', ''), 'already') !== false, 'duplicate plan request');
try { MemberLink::shopPlanRequest($s2, 'black', '', ''); $ok(false, 'unlinked plan'); } catch (DomainException $e) { $ok(true, ''); }
// join: existing email refused, new one makes account
$s3 = $mk('twinlocal', 'twin1@example.com', 'customer');
try { MemberLink::shopJoin($s3); $ok(false, 'join took over existing member'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), 'already belongs') !== false, 'join cannot take existing member'); }
$s4 = $mk('newseller', 'newseller@example.com', 'seller');
update_user_meta($s4, 'billing_phone', '01811222333');
$j = MemberLink::shopJoin($s4);
$ok(preg_match('/^\d{7}$/', $j['member_no']) === 1 && $j['member_no'] !== $k['no'], 'join made new member ' . $j['member_no']);
MemberLink::saveLink($s4, $j);
// checkout lookup + page
$ok(CardLink::remoteLookup($k['no'])['member_no'] === $k['no'], 'checkout lookup');
wp_set_current_user($s1);
ob_start(); MarketConnect::accountPage(); $h = ob_get_clean();
$ok(strpos($h, $k['no']) !== false && strpos($h, 'Business Black') !== false && strpos($h, 'name="plan"') !== false, 'seller page shows member + plan form');
wp_set_current_user($s2);
ob_start(); MarketConnect::accountPage(); $h = ob_get_clean();
$ok(strpos($h, 'Send me a code') !== false && strpos($h, 'Make me an SFM4U member') !== false && strpos($h, 'What SFM4U members get') !== false, 'unlinked page shows link + join + benefits');
$ok(strpos($h, 'name="code"') === false, 'no code box before asking');
$nav = MarketConnect::dokanNav([]);
$ok(isset($nav['sfm4u-membership']['url']), 'dokan nav item');
MemberLink::unlink($s1);
$ok(MemberLink::linked($s1) === [], 'unlink');
echo "pass $p fail $f\n";
