<?php
// Run on the SHOP site (site2) after link-master-setup.php ran on the master (site1).
use SFM4U\Wholesale\Members\{Members, CardLink};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$k = json_decode(file_get_contents('/tmp/link.json'), true);
update_option(CardLink::OPT_ROLE, 'shop');
CardLink::setMaster('http://127.0.0.1:8899', $k['id'], $k['secret']);
wp_set_current_user(1);
$r = wp_rand(10000, 99999);
// 1. new local customer (registers on the shop) -> auto card with a CENTRAL number
$u1 = (int) wp_insert_user(['user_login' => "shopcust$r", 'user_pass' => wp_generate_password(20), 'user_email' => "shopcust$r@example.com", 'display_name' => "Shop Cust $r"]);
$c1 = Members::cardsOf($u1);
$no1 = (string) get_user_meta($u1, 'sfm_wr_member_no', true);
$ok(count($c1) === 1 && preg_match('/^\d{7}$/', $no1), 'shop registration got card with number ' . $no1);
$m = CardLink::remoteLookup($no1);
$ok($m && $m['name'] === "Shop Cust $r", 'number exists on the account site');
// 2. same person registered at a second shop (same email) -> same number
$u2 = (int) wp_insert_user(['user_login' => "shopcustB$r", 'user_pass' => wp_generate_password(20), 'user_email' => "x$r@example.com"]);
wp_update_user(['ID' => $u2, 'user_email' => "shopcust$r@example.com"]);
// simulate second shop: ask master directly for that email
try { CardLink::post('/members', ['email' => "shopcust$r@example.com", 'name' => 'ignored']); $ok(false, 'self sign-up took over existing member'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), 'already belongs') !== false, 'self sign-up with existing email refused'); }
$again = CardLink::post('/members', ['email' => "shopcust$r@example.com", 'name' => 'ignored', 'match' => true]);
$ok($again['member_no'] === $no1, 'staff-checked same email -> same central number');
// 3. phone match
$byPhone = CardLink::post('/members', ['phone' => '০১৮' . substr((string) (10000000 + $r), 0, 8), 'name' => "Phone Person $r"]);
$byPhone2 = CardLink::post('/members', ['phone' => '018' . substr((string) (10000000 + $r), 0, 8), 'name' => 'x', 'match' => true]);
$ok($byPhone['member_no'] === $byPhone2['member_no'], 'phone (Bangla digits) matches same member');
try { CardLink::post('/members', ['name' => 'nobody']); $ok(false, 'no email/phone accepted'); } catch (DomainException $e) { $ok(true, ''); }
// 4. adopt a central-only member (made on master by setup script)
$remote = CardLink::remoteLookup($k['no']);
$ok($remote !== null, 'central member visible');
$lu = CardLink::adoptRemote($remote);
$ok(Members::memberNo($lu) === $k['no'] && count(Members::cardsOf($lu)) === 1, 'adopted with central number + local card');
$ok(CardLink::adoptRemote($remote) === $lu, 'adopt twice -> same local customer');
Members::record($lu, (int) Members::cardsOf($lu)[0]['id'], 'charge', 300, '', 'TEST', 1, 'up', 'main');
$ok(Members::balance($lu, 'main') === 300, 'khata works for adopted member');
// 5. master down -> no card row, error
CardLink::setMaster('http://127.0.0.1:8890', $k['id'], $k['secret']);
$u3 = (int) wp_insert_user(['user_login' => "down$r", 'user_pass' => wp_generate_password(20), 'user_email' => "down$r@example.com"]);
$ok(count(Members::cardsOf($u3)) === 0 && get_user_meta($u3, 'sfm_wr_member_no', true) === '', 'master unreachable: no card, no number');
try { Members::issue($u3, 'regular', 0, 1); $ok(false, 'issued while master down'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), 'reach') !== false, 'clear error when master down'); }
CardLink::setMaster('http://127.0.0.1:8899', $k['id'], $k['secret']);
update_option(CardLink::OPT_ROLE, 'off');
echo "pass $p fail $f\n";
