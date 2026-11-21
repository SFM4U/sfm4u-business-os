<?php
// Member numbers: the local counter skips numbers someone already holds (0.9.9), automatic numbers start at 101,
// and 1-100 are family numbers that only an administrator gives (Owner 2026-11-21). Runs with the link role off.
use SFM4U\Wholesale\Members\{Members, CardLink};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn,$code=null){try{$fn();return false;}catch(DomainException $e){return $code===null||$e->getCode()===$code;}};
wp_set_current_user(1); Members::install();
$oldRole = get_option(CardLink::OPT_ROLE); update_option(CardLink::OPT_ROLE, 'off');
// auto card on sign-up would hand out numbers while the fixture users are created; keep it off for this test
$oldAuto = get_option(Members::AUTO_OPTION); update_option(Members::AUTO_OPTION, '0');
$r = wp_rand(10000, 99999);
$mk = fn($n) => (int) wp_insert_user(['user_login' => "mn$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "mn$n$r@example.com"]);
$seq = max((int) get_option('sfm_wr_member_seq', 0), Members::RESERVED_MAX);
// two numbers ahead of the counter are already taken by other customers
$a = $mk('a'); update_user_meta($a, 'sfm_wr_member_no', Members::formatMemberNo($seq + 1)); update_user_meta($a, 'sfm_wr_central', 1);
$b = $mk('b'); update_user_meta($b, 'sfm_wr_member_no', Members::formatMemberNo($seq + 2));
$free = function (int $from) { for ($i = $from; ; $i++) { if (Members::userByMemberNo(Members::formatMemberNo($i)) === 0) { return $i; } } };
$want = $free($seq + 3);
$c = $mk('c'); Members::issue($c, 'business', 0, 1);
$no = Members::memberNo($c);
$ok($no === Members::formatMemberNo($want) && (int) $no > Members::RESERVED_MAX, 'counter skips taken numbers and stays above 100: got ' . $no);
$ok(Members::userByMemberNo($no) === $c, 'lookup of the new number finds the new customer');
global $wpdb;
$ok((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='sfm_wr_member_no' AND meta_value=%s", $no)) === 1, 'exactly one holder of the new number');
$want2 = $free($want + 1);
$d = $mk('d'); Members::issue($d, 'business', 0, 1);
$ok(Members::memberNo($d) === Members::formatMemberNo($want2), 'next customer gets the next free number');
// a fresh counter below 100 jumps to 101 (family numbers are never handed out automatically)
$keepSeq = get_option('sfm_wr_member_seq'); update_option('sfm_wr_member_seq', '5', false);
$e = $mk('e'); $eno = Members::memberNo($e);
$ok((int) $eno >= 101, 'automatic number after a low counter starts at 101+: ' . $eno);
if ((int) $keepSeq > (int) get_option('sfm_wr_member_seq')) { update_option('sfm_wr_member_seq', $keepSeq, false); }
// family numbers: admin only, 1-100, not twice
$famFree = null; for ($i = 100; $i >= 1; $i--) { if (Members::userByMemberNo(Members::formatMemberNo($i)) === 0) { $famFree = $i; break; } }
$g = $mk('g');
$ok($famFree !== null && Members::assignReservedNo($g, $famFree, 1) === Members::formatMemberNo($famFree) && Members::memberNo($g) === Members::formatMemberNo($famFree), 'admin gives family number ' . $famFree);
$h = $mk('h');
$ok($bad(fn() => Members::assignReservedNo($h, $famFree, 1), 409), 'same family number for another person refused');
$ok($bad(fn() => Members::assignReservedNo($h, 101, 1), 422) && $bad(fn() => Members::assignReservedNo($h, 0, 1), 422), 'numbers outside 1-100 refused');
$shop = (int) wp_insert_user(['user_login' => "mnshop$r", 'user_pass' => wp_generate_password(20), 'user_email' => "mnshop$r@example.com", 'role' => 'shop_manager']);
$ok($bad(fn() => Members::assignReservedNo($h, 99, $shop), 403), 'a shop manager (not administrator) cannot give family numbers');
$ok((string) get_user_meta($e, 'sfm_wr_member_no_prev', true) === '' && Members::assignReservedNo($e, $famFree - 1 >= 1 ? $famFree - 1 : $famFree, 1) !== '' && get_user_meta($e, 'sfm_wr_member_no_prev', true) === $eno, 'changing to a family number keeps the old number in sfm_wr_member_no_prev');
update_option(CardLink::OPT_ROLE, 'shop');
$ok($bad(fn() => Members::assignReservedNo($h, 98, 1), 409), 'linked shop cannot give family numbers (account site does)');
$oldAuto !== false ? update_option(Members::AUTO_OPTION, $oldAuto) : delete_option(Members::AUTO_OPTION);
$oldRole !== false ? update_option(CardLink::OPT_ROLE, $oldRole) : delete_option(CardLink::OPT_ROLE);
echo "pass $p fail $f\n";
