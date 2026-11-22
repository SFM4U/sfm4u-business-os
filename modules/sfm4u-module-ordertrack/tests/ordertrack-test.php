<?php
// E07 Order tracking: order number + phone → status; wrong phone = same "not found"; Bangla digits; rate limit; dashboard.
use SFM4U\Modules\OrderTrack\OrderTrack as OT;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$ok(OT::digits('০১৭১২-৩৪৫৬৭৮') === '01712345678', 'Bangla digits and dashes cleaned');
$ok(OT::phoneMatches('01712345678', '5678', 'last4') && !OT::phoneMatches('01712345678', '5679', 'last4') && !OT::phoneMatches('01712345678', '678', 'last4'), 'last-4 match');
$ok(OT::phoneMatches('+8801712345678', '01712345678', 'full') && !OT::phoneMatches('01712345678', '01712345670', 'full'), 'full phone match with +880');
$prod = new WC_Product_Simple(); $prod->set_name('OT test item'); $prod->set_regular_price('100'); $prod->save();
$o = wc_create_order(); $o->add_product($prod, 2); $o->set_billing_phone('01712345678'); $o->calculate_totals(); $o->set_status('processing'); $o->update_meta_data('_sfm_courier', ['tracking_code' => 'SF123', 'status' => 'in_review']); $o->save();
$r = OT::lookup((string) $o->get_id(), '5678');
$ok($r && $r['status'] === 'processing' && $r['status_bn'] === 'প্রস্তুত হচ্ছে' && $r['tracking'] === 'SF123' && $r['items'] === ['OT test item × 2'], 'order found with status, Bangla status, items and courier code');
$ok(OT::lookup((string) $o->get_id(), '1111') === null && OT::lookup('99999999', '5678') === null && OT::lookup('abc', '5678') === null, 'wrong phone, unknown order, bad number → not found');
$ok(OT::lookup('#' . $o->get_id(), '৫৬৭৮') !== null, 'order number with # and Bangla phone digits works');
// shortcode flow
$_POST = ['sfm_ot' => '1', '_sfm_ot' => wp_create_nonce('sfm_ot'), 'sfm_ot_number' => (string) $o->get_id(), 'sfm_ot_phone' => '5678'];
$_SERVER['REMOTE_ADDR'] = '10.9.8.' . wp_rand(1, 250);
$h = OT::shortcode();
$ok(strpos($h, 'Order #' . $o->get_order_number()) !== false && strpos($h, 'SF123') !== false, 'shortcode shows the order');
$_POST['sfm_ot_phone'] = '0000'; $h = OT::shortcode();
$ok(strpos($h, 'No order found') !== false && strpos($h, 'SF123') === false, 'wrong phone shows not found, no data');
for ($i = 0; $i < 12; $i++) { $h = OT::shortcode(); }
$ok(strpos($h, 'Too many tries') !== false, 'rate limit after 10 tries');
$_POST = []; $h = OT::shortcode(); $ok(strpos($h, 'name="sfm_ot_number"') !== false, 'empty form renders');
$d = OT::dashboard();
$ok(count($d['settings']) === 3 && $d['help'][1] !== '' && (int) $d['numbers'][0][1] >= 1, 'module dashboard: settings, Bangla help, lookups counted');
$ok(isset(\SFM4U\Wholesale\Modules::all()['ordertrack']), 'module joined SFM4U Business Software');
$o->delete(true); $prod->delete(true);
echo "pass $p fail $f\n";
