<?php
use SFM4U\Wholesale\Members\{Members, MembersAdmin};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
Members::maybeInstall();
update_option('sfm_wr_stores', ['main' => ['name' => 'Main'], 'shop2' => ['name' => 'Shop 2']]);
$mk = function ($n) { return (int) wp_insert_user(['user_login' => $n . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => wp_rand(1, 999999) . $n . '@example.com']); };
$cash = $mk('cash'); $man = $mk('man'); $cust = $mk('cust');
Members::setStaff($cash, 'shop2', 'cashier');
Members::setStaff($man, 'shop2', 'manager');
$ok(Members::shopRole(1) === 'ceo', 'admin = ceo');
$ok(Members::shopRole($cash) === 'cashier' && Members::shopRole($man) === 'manager' && Members::shopRole($cust) === 'none', 'levels');
wp_set_current_user($cash);
$ok(!Members::canSeeDetails() && !Members::canAdjust(), 'cashier: no details/adjust');
$_REQUEST['store'] = 'main';
$ok(Members::currentStore() === 'shop2', 'cashier locked to own shop');
wp_set_current_user($man);
$ok(Members::canSeeDetails() && Members::canAdjust() && Members::currentStore() === 'shop2', 'manager: details, adjust, locked shop');
wp_set_current_user(1);
$ok(Members::currentStore() === 'main', 'ceo can pick shop');
unset($_REQUEST['store']);
// server-side: cashier cannot post an adjustment
wp_set_current_user($cash);
$card = Members::cardsOf($cust)[0];
$_POST = ['sfm_wr_action' => 'adjust', 'card_id' => (string) $card['id'], 'amount' => '100', 'note' => 'try', 'direction' => 'down', '_wpnonce' => wp_create_nonce('sfm_wr_khata')]; $_REQUEST = $_POST;
ob_start(); MembersAdmin::scanPage(); $o = ob_get_clean();
$ok(strpos($o, 'Only a manager can correct the khata') !== false, 'cashier adjust refused server-side');
$ok(Members::balance($cust, 'shop2') === 0, 'no khata change');
wp_set_current_user($man); $_POST['_wpnonce'] = wp_create_nonce('sfm_wr_khata'); $_REQUEST = $_POST;
ob_start(); MembersAdmin::scanPage(); $o = ob_get_clean();
$ok(Members::balance($cust, 'shop2') === -100, 'manager adjust works (' . Members::balance($cust, 'shop2') . ')');
$_POST = []; $_REQUEST = [];
Members::setStaff($cash, 'main', 'none');
$ok(Members::shopRole($cash) === 'none', 'removed from staff');
try { Members::setStaff(1, 'main', 'cashier'); $ok(false, 'admin demoted'); } catch (DomainException $e) { $ok(true, ''); }
echo "pass $p fail $f\n";
