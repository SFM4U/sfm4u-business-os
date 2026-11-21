<?php
use SFM4U\Wholesale\Members\{Members, MembersAdmin};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$st = 'due' . wp_rand(1000, 99999);
update_option('sfm_wr_stores', ['main' => ['name' => 'Main'], $st => ['name' => 'Due Test Shop']]);
$mk = function ($n) { return (int) wp_insert_user(['user_login' => $n . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => wp_rand(1, 999999) . $n . '@example.com']); };
$a = $mk('duea'); $b = $mk('dueb'); $c = $mk('duec');
update_user_meta($a, 'billing_phone', '01711223344'); update_user_meta($a, 'sfm_wr_name_bn', 'বাকি এক');
foreach ([[$a, 'charge', 500], [$a, 'payment', 100], [$b, 'charge', 900], [$c, 'charge', 50], [$c, 'payment', 50]] as [$u, $k, $amt]) {
    Members::record($u, (int) Members::cardsOf($u)[0]['id'], $k, $amt, $k === 'payment' ? 'cash' : '', '', 1, 'up', $st);
}
$d = Members::dueList($st);
$ok(count($d) === 2 && (int) $d[0]['user_id'] === $b && (int) $d[0]['balance_minor'] === 900 && (int) $d[1]['balance_minor'] === 400, 'due list sorted, paid-up member excluded');
$ok(Members::waNumber('01711-223344') === '8801711223344' && Members::waNumber('+8801711223344') === '8801711223344' && Members::waNumber('12345') === '', 'wa numbers');
wp_set_current_user(1); $_REQUEST['store'] = $st;
ob_start(); MembersAdmin::duePage(); $o = ob_get_clean();
$ok(strpos($o, 'wa.me/8801711223344') !== false && strpos($o, 'বাকি এক') !== false && strpos($o, 'no mobile number') !== false, 'manager page with WhatsApp link');
$cash = $mk('duecash'); Members::setStaff($cash, $st, 'cashier'); wp_set_current_user($cash);
ob_start(); MembersAdmin::duePage(); $o = ob_get_clean();
// 900 minor units at 2 store decimals = ৳ 9.00 (money display changed before 0.9.5; was raw "900").
$ok(strpos($o, 'wa.me') === false && strpos($o, '9.00') !== false, 'cashier sees list without phone links');
unset($_REQUEST['store']);
echo "pass $p fail $f\n";
