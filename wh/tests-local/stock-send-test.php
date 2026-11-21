<?php
use SFM4U\Wholesale\Ops\StockFeed;
use SFM4U\Wholesale\Members\{Members, Tiers};
wp_set_current_user(1);
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$r = json_decode(file_get_contents('/tmp/stock_pair2.json'), true);
$site = StockFeed::finishPair($r['sfm_stock_code'], $r['sfm_stock_state']);
$ok($site !== '' && StockFeed::role() === 'sender', 'paired: ' . $site);
$ok(StockFeed::call('GET', '/ping')['ok'] === true, 'signed ping');
$cust = get_user_by('email', 'blackvendor@example.com');
$cid = $cust ? $cust->ID : (int) wp_insert_user(['user_login' => 'blackbuyer', 'user_pass' => wp_generate_password(20), 'user_email' => 'blackvendor@example.com']);
delete_user_meta($cid, Tiers::BLACK_UNTIL);
$mk = function() use ($cid) {
    $pr = wc_get_product_id_by_sku('WH-DAL-1') ? wc_get_product(wc_get_product_id_by_sku('WH-DAL-1')) : null;
    if (!$pr) { $pr = new WC_Product_Simple(); $pr->set_name('Dal 1kg'); $pr->set_sku('WH-DAL-1'); $pr->set_regular_price('140'); $pr->save(); }
    $o = wc_create_order(['customer_id' => $cid]); $o->add_product($pr, 10); $o->set_billing_email('blackvendor@example.com'); $o->calculate_totals(); $o->save(); return $o;
};
$o1 = $mk(); $o1->update_status('completed'); $o1 = wc_get_order($o1->get_id());
$ok(empty($o1->get_meta(StockFeed::META)), 'not Business Black -> nothing sent');
if (!Members::cardFor($cid, 'black')) { Members::issue($cid, 'black', 0, 1, 'main', null, 'Black Store'); }
Tiers::recordBlackPayment($cid, 1, 'cash', '', 1);
$o2 = $mk(); $o2->update_status('completed'); $o2 = wc_get_order($o2->get_id());
$m = $o2->get_meta(StockFeed::META);
$ok(is_array($m) && $m['status'] === 'sent' && $m['created'] === 1, 'Business Black completed order sent, new draft product: ' . wp_json_encode($m));
$o3 = $mk(); $o3->update_status('completed'); $o3 = wc_get_order($o3->get_id());
$m3 = $o3->get_meta(StockFeed::META);
$ok(is_array($m3) && $m3['updated'] === 1, 'second order adds stock');
$ok(StockFeed::send($o3) === 'sent', 'resend does nothing');
echo "pass $p fail $f\n";
