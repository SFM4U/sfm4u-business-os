<?php
// E04 COD control: amount limit, cancelled-orders guard, block list, Bangla digits, checkout validation, dashboard.
use SFM4U\Modules\CodGuard\CodGuard as CG;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$o = ['max_amount' => 5000, 'max_cancelled' => 2, 'blocked' => "01811111111\n+8801922222222"];
$ok(CG::phone('+880 1712-345678') === '01712345678' && CG::phone('০১৭১২৩৪৫৬৭৮') === '01712345678', 'phone normalised (+880, Bangla digits)');
$ok(CG::decide(4999, 0, '01712345678', $o) === '' && CG::decide(5001, 0, '01712345678', $o) === 'amount', 'amount limit');
$ok(CG::decide(100, 0, '8801811111111', $o) === 'blocked' && CG::decide(100, 0, '01922222222', $o) === 'blocked', 'block list (any phone format)');
$ok(CG::decide(100, 2, '01712345678', $o) === 'cancelled' && CG::decide(100, 1, '01712345678', $o) === '', 'cancelled-orders guard at 2');
$ok(CG::decide(99999, 9, '01811111111', ['max_amount' => 0, 'max_cancelled' => 0, 'blocked' => '']) === '', 'all limits off = allowed');
// real cancelled orders by phone
$ph = '0171' . wp_rand(1000000, 9999999); $ids = [];
foreach (['cancelled', 'failed', 'completed'] as $st) { $x = wc_create_order(); $x->set_billing_phone($ph); $x->set_status($st); $x->save(); $ids[] = $x->get_id(); }
$ok(CG::cancelledCount($ph) === 2 && CG::cancelledCount('+88' . $ph) === 2, 'counts cancelled + failed orders of this phone only');
// checkout validation
update_option('sfm_mod_codguard', ['max_amount' => 5000, 'max_cancelled' => 2, 'days' => 90, 'blocked' => '', 'message' => 'No COD here']);
$e = new WP_Error(); CG::validate(['payment_method' => 'cod', 'billing_phone' => $ph], $e);
$ok($e->has_errors() && $e->get_error_message() === 'No COD here', 'checkout refuses COD for a phone with 2 cancelled orders');
$e = new WP_Error(); CG::validate(['payment_method' => 'bkash', 'billing_phone' => $ph], $e); $ok(!$e->has_errors(), 'other payment methods not touched');
$e = new WP_Error(); CG::validate(['payment_method' => 'cod', 'billing_phone' => '01799' . wp_rand(100000, 999999)], $e); $ok(!$e->has_errors(), 'clean phone can use COD');
$d = CG::dashboard(); $ok((int) $d['numbers'][0][1] >= 1 && count($d['settings']) === 5 && $d['help'][1] !== '', 'dashboard counts refusals; 5 settings; Bangla help');
$ok(isset(\SFM4U\Wholesale\Modules::all()['codguard']), 'module joined SFM4U Business Software');
foreach ($ids as $i) { wc_get_order($i)->delete(true); } delete_option('sfm_mod_codguard');
echo "pass $p fail $f\n";
