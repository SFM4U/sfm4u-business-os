<?php
// site4 = AlgiBazar (receiver)
use SFM4U\Wholesale\Ops\StockFeed;
wp_set_current_user(1);
update_option(StockFeed::OPT_ROLE, 'receiver'); delete_option(StockFeed::OPT_SEEN); delete_option(StockFeed::OPT_LOG);
if (!get_role('seller')) { add_role('seller', 'Vendor', ['read' => true, 'seller' => true, 'edit_products' => true]); }
$r = $argv ?? [];
$v = get_user_by('email', 'blackvendor@example.com');
$vid = $v ? $v->ID : (int) wp_insert_user(['user_login' => 'blackvendor', 'user_pass' => wp_generate_password(20), 'user_email' => 'blackvendor@example.com', 'role' => 'seller']);
$c = get_user_by('email', 'notvendor@example.com');
if (!$c) { wp_insert_user(['user_login' => 'notvendor', 'user_pass' => wp_generate_password(20), 'user_email' => 'notvendor@example.com', 'role' => 'customer']); }
foreach (['RICE-25', 'WH-DAL-1'] as $s0) { $old = wc_get_product_id_by_sku($s0 . '-V' . $vid); if ($old) { wp_delete_post($old, true); } }
// pure apply test
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$ok(StockFeed::findVendor('', 'blackvendor@example.com') === $vid, 'vendor by email');
$ok(StockFeed::findVendor('', 'notvendor@example.com') === 0, 'non-vendor refused');
$a = StockFeed::apply($vid, 'test#1', [['sku' => 'RICE-25', 'name' => 'Rice 25kg', 'qty' => 3, 'cost' => 1500, 'retail' => 1700], ['sku' => '', 'qty' => 1], ['sku' => 'OIL', 'qty' => 0]]);
$pid = wc_get_product_id_by_sku('RICE-25-V' . $vid); $pr = wc_get_product($pid);
$ok($a['created'] === 1 && $pr && $pr->get_status() === 'draft' && (int) $pr->get_stock_quantity() === 3 && (int) get_post_field('post_author', $pid) === $vid && $pr->get_regular_price() === '1700', 'new product = draft of vendor with stock');
$a2 = StockFeed::apply($vid, 'test#1', [['sku' => 'RICE-25', 'qty' => 3]]);
$ok($a2['duplicate'] === true && (int) wc_get_product($pid)->get_stock_quantity() === 3, 'same order twice ignored');
$a3 = StockFeed::apply($vid, 'test#2', [['sku' => 'RICE-25', 'qty' => 2]]);
$ok($a3['updated'] === 1 && (int) wc_get_product($pid)->get_stock_quantity() === 5, 'next order adds stock');
file_put_contents('/tmp/stock_vendor.txt', (string) $vid);
echo "pass $p fail $f\n";
