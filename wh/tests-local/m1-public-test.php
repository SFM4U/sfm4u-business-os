<?php
// M1 integration: public two-price table, cart auto-switch at N, variable product via parent rule.
use SFM4U\Wholesale\{Commerce, PriceBox, Rule};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
update_option('sfmwh_enabled','yes');
wp_set_current_user(1);
$s = new WC_Product_Simple(); $s->set_name('M1 Soap'); $s->set_regular_price('100'); $s->set_status('publish'); $s->save();
Commerce::saveRule($s, ['buyer'=>'everyone','public'=>true,'minor'=>'9500','min'=>'12','tiers'=>['50'=>'9000'],'floor_minor'=>'8000']);
$vp = new WC_Product_Variable(); $vp->set_name('M1 Shirt'); $vp->set_status('publish'); $vp->save();
$va = new WC_Product_Variation(); $va->set_parent_id($vp->get_id()); $va->set_regular_price('300'); $va->set_status('publish'); $va->save();
Commerce::saveRule(wc_get_product($vp->get_id()), ['buyer'=>'everyone','public'=>true,'minor'=>'25000','min'=>'6']);
// Guest sees the public table
wp_set_current_user(0);
$GLOBALS['product'] = wc_get_product($s->get_id());
ob_start(); PriceBox::single(); $h = ob_get_clean();
$ok(strpos($h,'sfmwh-public')!==false && strpos($h,'12 pieces or more')!==false && strpos($h,'50 pieces or more')!==false, 'guest sees two-price table');
$ok(strpos($h,'1 piece (retail)')!==false, 'retail row shown');
ob_start(); PriceBox::loop(); $l = ob_get_clean(); $ok(strpos($l,'12+ pcs')!==false, 'loop line');
$GLOBALS['product'] = wc_get_product($vp->get_id());
ob_start(); PriceBox::single(); $h2 = ob_get_clean(); $ok(strpos($h2,'6 pieces or more')!==false, 'variable product table');
// Non-public rule stays hidden from guests
$hid = new WC_Product_Simple(); $hid->set_name('M1 Hidden'); $hid->set_regular_price('100'); $hid->save();
Commerce::saveRule($hid, ['buyer'=>'everyone','minor'=>'9000','min'=>'10']);
$GLOBALS['product'] = wc_get_product($hid->get_id());
ob_start(); PriceBox::single(); $ok(ob_get_clean()==='', 'non-public rule hidden from guests');
// Cart: automatic switch at N
wc_load_cart(); WC()->cart->empty_cart();
$k = WC()->cart->add_to_cart($s->get_id(), 11);
$ok($k !== false, 'guest can add 11 (below N)');
WC()->cart->calculate_totals();
$ok(abs((float) WC()->cart->get_subtotal() - 1100) < 0.01, '11 pcs = retail 1100: '.WC()->cart->get_subtotal());
WC()->cart->set_quantity($k, 12); WC()->cart->calculate_totals();
$ok(abs((float) WC()->cart->get_subtotal() - 1140) < 0.01, '12 pcs = wholesale 1140: '.WC()->cart->get_subtotal());
WC()->cart->set_quantity($k, 50); WC()->cart->calculate_totals();
$ok(abs((float) WC()->cart->get_subtotal() - 4500) < 0.01, '50 pcs = tier 4500: '.WC()->cart->get_subtotal());
WC()->cart->set_quantity($k, 3); WC()->cart->calculate_totals();
$ok(abs((float) WC()->cart->get_subtotal() - 300) < 0.01, 'back to 3 pcs = retail 300: '.WC()->cart->get_subtotal());
WC()->cart->empty_cart();
$kv = WC()->cart->add_to_cart($vp->get_id(), 6, $va->get_id());
WC()->cart->calculate_totals();
$ok($kv !== false && abs((float) WC()->cart->get_subtotal() - 1500) < 0.01, 'variation 6 pcs uses parent rule 1500: '.WC()->cart->get_subtotal());
WC()->cart->set_quantity($kv, 2); WC()->cart->calculate_totals();
$ok(abs((float) WC()->cart->get_subtotal() - 600) < 0.01, 'variation 2 pcs retail 600');
WC()->cart->empty_cart();
$ok(Commerce::storedRule($va->get_id())['minor'] === 25000, 'storedRule falls back to parent');
foreach ([$s,$vp,$hid] as $x) { wc_get_product($x->get_id())->delete(true); }
echo "pass $p fail $f\n";
