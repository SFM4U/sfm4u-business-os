<?php
use SFM4U\Wholesale\Market\VendorProfit;
use SFM4U\Wholesale\Ops\StockFeed;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
// pure
$ok(VendorProfit::averageCost(10, 100, 10, 120) == 110.0 && VendorProfit::averageCost(0, 0, 5, 90) == 90.0 && VendorProfit::averageCost(-3, 100, 5, 80) == 80.0, 'average cost');
$s = VendorProfit::summarise([[1,'A',2,30000,0,12000.0],[1,'A',1,15000,15000,12000.0],[2,'B',1,5000,0,null]]);
$ok($s['total']['sales'] === 50000 && $s['total']['refund'] === 15000 && $s['total']['cost'] === 36000 && $s['total']['unknown_sales'] === 5000 && $s['total']['profit_known'] === -6000, 'summary ' . json_encode($s['total']));
$ok($s['rows'][2]['profit'] === null && $s['rows'][1]['profit'] === 45000 - 15000 - 36000, 'row profit');
// real orders on site4
$v = get_user_by('email', 'blackvendor@example.com'); $vid = $v->ID;
$other = get_user_by('email','notvendor@example.com')->ID;
$mk = function($sku,$author,$price,$cost){ $id = wc_get_product_id_by_sku($sku); if ($id) wp_delete_post($id,true); $pr = new WC_Product_Simple(); $pr->set_name($sku); $pr->set_sku($sku); $pr->set_regular_price((string)$price); $pr->set_status('publish'); $id=$pr->save(); wp_update_post(['ID'=>$id,'post_author'=>$author]); if ($cost!==null) update_post_meta($id,'_sfm_cost',(string)$cost); return $id; };
$a = $mk('PL-A', $vid, 150, 120); $b = $mk('PL-B', $vid, 50, null); $c = $mk('PL-C', $other, 999, 1);
$o = wc_create_order(); $o->add_product(wc_get_product($a), 2); $o->add_product(wc_get_product($b), 1); $o->add_product(wc_get_product($c), 1); $o->calculate_totals(); $o->set_status('completed'); $o->save();
$o2 = wc_create_order(); $o2->add_product(wc_get_product($a), 5); $o2->calculate_totals(); $o2->set_status('pending'); $o2->save();
$lines = VendorProfit::lines($vid, current_time('Y-m'));
$mine = array_filter($lines, fn($l) => in_array($l[0], [$a,$b], true));
$ok(count($mine) === 2 && !array_filter($lines, fn($l) => $l[0] === $c), 'only own products, only paid orders');
$sum = VendorProfit::summarise(array_values($mine));
$ok($sum['total']['sales'] === 35000 && $sum['total']['cost'] === 24000 && $sum['total']['unknown_sales'] === 5000 && $sum['total']['profit_known'] === 6000, 'real totals ' . json_encode($sum['total']));
// restock average via StockFeed apply
$sid = wc_get_product_id_by_sku('RICE-25-V' . $vid);
if ($sid) { $pr = wc_get_product($sid); $before = (float) $pr->get_stock_quantity(); update_post_meta($sid,'_sfm_cost','1500'); StockFeed::apply($vid, 'avg#' . time(), [['sku'=>'RICE-25','qty'=>$before,'cost'=>1700]]); $ok((float) get_post_meta($sid,'_sfm_cost',true) == 1600.0, 'restock weighted cost ' . get_post_meta($sid,'_sfm_cost',true)); }
wp_set_current_user($vid); $_GET['month'] = current_time('Y-m'); ob_start(); VendorProfit::page(); $h = ob_get_clean();
$ok(strpos($h, '৳60.00') !== false && strpos($h, 'name="cost[' . $b . ']"') !== false && strpos($h, 'PL-C') === false, 'page');
wp_set_current_user($other); ob_start(); VendorProfit::page(); $h2 = ob_get_clean();
$ok(strpos($h2, 'for AlgiBazar sellers') !== false, 'non-seller blocked');
foreach ([$o,$o2] as $x) $x->delete(true); foreach([$a,$b,$c] as $x) wp_delete_post($x,true);
echo "pass $p fail $f\n";
