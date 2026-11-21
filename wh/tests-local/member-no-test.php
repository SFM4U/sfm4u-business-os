<?php
// 0.9.9: the local member-number counter skips numbers someone already holds (e.g. central numbers kept from when
// this site was a linked shop), so two people never share a number. Runs with the link role off (local numbering).
use SFM4U\Wholesale\Members\{Members, CardLink};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
wp_set_current_user(1); Members::install();
$oldRole = get_option(CardLink::OPT_ROLE); update_option(CardLink::OPT_ROLE, 'off');
$r = wp_rand(10000, 99999);
$mk = fn($n) => (int) wp_insert_user(['user_login' => "mn$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "mn$n$r@example.com"]);
$seq = (int) get_option('sfm_wr_member_seq', 0);
// two numbers ahead of the counter are already taken by other customers
$a = $mk('a'); update_user_meta($a, 'sfm_wr_member_no', Members::formatMemberNo($seq + 1)); update_user_meta($a, 'sfm_wr_central', 1);
$b = $mk('b'); update_user_meta($b, 'sfm_wr_member_no', Members::formatMemberNo($seq + 2));
$free = function (int $from) { for ($i = $from; ; $i++) { if (Members::userByMemberNo(Members::formatMemberNo($i)) === 0) { return $i; } } };
$want = $free($seq + 3);   // first number nobody holds (a used test DB can hold more taken numbers)
$c = $mk('c'); Members::issue($c, 'business', 0, 1);
$no = Members::memberNo($c);
$ok($no === Members::formatMemberNo($want) && $no !== Members::formatMemberNo($seq + 1) && $no !== Members::formatMemberNo($seq + 2), 'counter skipped taken numbers: got ' . $no);
$ok(Members::userByMemberNo($no) === $c, 'lookup of the new number finds the new customer');
global $wpdb;
$ok((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='sfm_wr_member_no' AND meta_value=%s", $no)) === 1, 'exactly one holder of the new number');
$want2 = $free($want + 1);
$d = $mk('d'); Members::issue($d, 'business', 0, 1);
$ok(Members::memberNo($d) === Members::formatMemberNo($want2), 'next customer gets the next free number');
$oldRole !== false ? update_option(CardLink::OPT_ROLE, $oldRole) : delete_option(CardLink::OPT_ROLE);
echo "pass $p fail $f\n";
