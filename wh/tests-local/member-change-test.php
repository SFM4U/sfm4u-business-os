<?php
// Owner 2026-11-21: the administrator can change any member number; the change reaches shops and AlgiBazar through the
// stable member key; the old number is retired (never handed out again) and an old printed card still finds the member.
use SFM4U\Wholesale\Members\{Members, CardLink};
use SFM4U\Wholesale\Ops\StockFeed;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn,$code=null){try{$fn();return false;}catch(DomainException $e){return $code===null||$e->getCode()===$code;}};
wp_set_current_user(1); Members::install();
$oldRole = get_option(CardLink::OPT_ROLE); update_option(CardLink::OPT_ROLE, 'off');
$oldAuto = get_option(Members::AUTO_OPTION); update_option(Members::AUTO_OPTION, '0');
$r = wp_rand(10000, 99999);
$mk = fn($n, $role = 'subscriber') => (int) wp_insert_user(['user_login' => "mc$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "mc$n$r@example.com", 'role' => $role]);
$freeNo = function (int $from) { for ($i = $from; ; $i++) { if (!Members::isReservedNumber($i) && Members::userByMemberNo(Members::formatMemberNo($i)) === 0) { return $i; } } };
// --- account site (master role off here = local numbering) ---
$a = $mk('a'); Members::issue($a, 'regular', 0, 1);
$noA = Members::memberNo($a); $keyA = Members::memberKey($a);
$ok(preg_match('/^[a-f0-9]{32}$/', $keyA) && Members::memberKey($a) === $keyA, 'stable member key created once');
$n1 = $freeNo(500000 + wp_rand(1, 1000));
$ok(Members::assignNumber($a, $n1, 1) === Members::formatMemberNo($n1) && Members::memberNo($a) === Members::formatMemberNo($n1), 'admin changes the number');
$n2 = $freeNo($n1 + 1);
$ok(Members::assignNumber($a, $n2, 1) === Members::formatMemberNo($n2), 'admin can change it again');
$ok(Members::memberKey($a) === $keyA, 'key unchanged after number changes');
$ok(Members::userByMemberNo($noA) === $a && Members::userByMemberNo(Members::formatMemberNo($n1)) === $a, 'old numbers still find the member (old printed card)');
$b = $mk('b');
$ok($bad(fn() => Members::assignNumber($b, (int) $noA, 1), 409), 'a retired number cannot be given to someone else');
$ok($bad(fn() => Members::assignNumber($b, $n2, 1), 409), 'a number held now by another member refused');
$shopMgr = $mk('mgr', 'shop_manager');
$ok($bad(fn() => Members::assignNumber($b, $freeNo($n2 + 1), $shopMgr), 403), 'only an administrator can change numbers');
$card = Members::bestCardFor($a);
$pub = CardLink::publicMember($card);
$ok($pub['member_no'] === Members::formatMemberNo($n2) && $pub['member_key'] === $keyA, 'account site answers with the new number and the same key');
// --- shop side: a local copy with the OLD number follows the change on the next answer ---
update_option(CardLink::OPT_ROLE, 'shop');
$local = $mk('local'); update_user_meta($local, 'sfm_wr_member_no', '9' . substr(Members::formatMemberNo($n1), 1)); update_user_meta($local, 'sfm_wr_central', 1); update_user_meta($local, 'sfm_wr_member_key', $keyB = bin2hex(random_bytes(16)));
$answer = ['member_no' => Members::formatMemberNo($freeNo(800000 + wp_rand(1, 1000))), 'member_key' => $keyB, 'tier' => 'regular', 'status' => 'active', 'name' => 'Test'];
$ok(CardLink::localUserFor($answer) === $local && Members::memberNo($local) === $answer['member_no'], 'shop follows the new number by key (no duplicate customer)');
$ok(in_array('9' . substr(Members::formatMemberNo($n1), 1), get_user_meta($local, 'sfm_wr_member_no_old'), true), 'shop keeps the old number in history');
$ok($bad(fn() => Members::assignNumber($local, 123456, 1), 409), 'a linked shop cannot change numbers (account site does)');
// --- AlgiBazar receiver: finds the seller by key and follows the number ---
$seller = $mk('seller', 'seller'); update_user_meta($seller, 'sfm_wr_member_no', '7000001'); update_user_meta($seller, 'sfm_wr_member_key', $keyS = bin2hex(random_bytes(16)));
if (!get_role('seller')) { add_role('seller', 'Seller', ['read' => true]); (new WP_User($seller))->set_role('seller'); }
$found = StockFeed::findVendor('7000099', '', $keyS);
$ok($found === $seller && Members::memberNo($seller) === '7000099', 'receiver finds the seller by key and follows the new number');
$oldAuto !== false ? update_option(Members::AUTO_OPTION, $oldAuto) : delete_option(Members::AUTO_OPTION);
$oldRole !== false ? update_option(CardLink::OPT_ROLE, $oldRole) : delete_option(CardLink::OPT_ROLE);
echo "pass $p fail $f\n";
