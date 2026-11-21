<?php
// Purchase & Cost (Owner 2026-11-22): 10 cases x 400, 12 per case, own fare 100, truck 300, labour 100 -> 450/case, 37.50/piece.
use SFM4U\Wholesale\{Purchase as P, PriceAudience as PA};
use SFM4U\Wholesale\Members\Members;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1);
$dec = wc_get_price_decimals(); $M = fn($t) => (int) round($t * (10 ** $dec));
$r = wp_rand(10000, 99999);
$prod = new WC_Product_Simple(); $prod->set_name("Fizz $r"); $prod->set_regular_price('45'); $prod->set_status('publish'); $pid = $prod->save();
$items = [['product_id' => $pid, 'name' => 'Fizz', 'packs' => 10, 'pieces' => 12, 'pack_minor' => $M(400)]];
$exp = [['type' => 'own_fare', 'payee' => 'Owner', 'minor' => $M(100)], ['type' => 'truck', 'payee' => 'Truck Karim', 'minor' => $M(300)], ['type' => 'labour', 'payee' => 'Labour Rafiq', 'minor' => $M(100)]];
$c = P::calculate($items, $exp);
$l = $c['lines'][0];
$ok($c['goods_minor'] === $M(4000) && $c['expense_minor'] === $M(500) && $c['total_minor'] === $M(4500), 'goods 4000 + costs 500 = 4500');
$ok($l['landed_pack_minor'] === $M(450) && $l['landed_piece_minor'] === $M(37.5), 'real cost 450 per case, 37.50 per piece');
$ok(str_starts_with($l['landed_piece_exact'], '37.5'), 'exact piece cost shown');
// two products: costs spread by value, totals exact
$c2 = P::calculate([['name' => 'A', 'packs' => 3, 'pieces' => 10, 'pack_minor' => $M(100)], ['name' => 'B', 'packs' => 1, 'pieces' => 7, 'pack_minor' => $M(50)]],
    [['type' => 'truck', 'payee' => 'T', 'minor' => $M(10)]]);
$ok(array_sum(array_column($c2['lines'], 'expense_minor')) === $M(10) && array_sum(array_column($c2['lines'], 'landed_minor')) === $M(360), 'spread by value: no money lost to rounding');
$c3 = P::calculate([['name' => 'A', 'packs' => 1, 'pieces' => 30, 'pack_minor' => $M(100)], ['name' => 'B', 'packs' => 1, 'pieces' => 10, 'pack_minor' => $M(100)]],
    [['type' => 'labour', 'minor' => $M(40)]], 'quantity');
$ok($c3['lines'][0]['expense_minor'] === $M(30) && $c3['lines'][1]['expense_minor'] === $M(10), 'spread by pieces: 30/10 of 40');
$c4 = P::calculate($items, $exp, 'value', [0 => $M(38)]);
$ok($c4['lines'][0]['landed_piece_minor'] === $M(38) && !empty($c4['lines'][0]['manual']), 'shop can type the piece cost by hand');
$ok($bad(fn() => P::calculate([], $exp)) && $bad(fn() => P::calculate([['packs' => 0, 'pieces' => 12, 'pack_minor' => 1]], [])) && $bad(fn() => P::calculate($items, [['minor' => -5]])), 'bad input refused');
$ok(P::suggest($M(37.5), 10) === (int) ceil($M(37.5) * 1.1), 'suggested price = cost + 10 %');
// form parsing (Bangla digits ok)
[$fi, $fe, $fby] = P::fromPost(['item_product' => [$pid], 'item_name' => ['Fizz'], 'item_packs' => ['১০'], 'item_pieces' => ['12'], 'item_cost' => ['৪০০'], 'exp_type' => ['truck'], 'exp_payee' => ['T'], 'exp_amount' => ['300'], 'by' => 'value']);
$ok($fi[0]['packs'] === 10 && $fi[0]['pack_minor'] === $M(400) && $fe[0]['minor'] === $M(300), 'form: Bangla digits in packs and cost');
// save -> product gets real cost + private cost price; price sheet shows profit
PA::saveSets($pid, PA::fromLines("Wholesale | business,vendor | business | 1=42, 5=41 |\nRetail | public | everyone | 1=45 |"));
$id = P::save($c, 'Fizz depot', '2026-11-22', 1);
$landed = json_decode((string) get_post_meta($pid, P::LANDED, true), true);
$cost = json_decode((string) get_post_meta($pid, PA::COST, true), true);
$ok($id > 0 && $landed['piece_minor'] === $M(37.5) && $landed['pieces'] === 12, 'product remembers real cost per piece');
$ok($cost['minor'] === $M(37.5) && $cost['show_to'] === ['only_me'], 'real cost becomes the private cost price');
$sheet = P::sheetRows($pid);
$w = array_values(array_filter($sheet['rows'], fn($x) => $x['label'] === 'Wholesale' && $x['qty'] === 1))[0];
$ok($w['profit_minor'] === $M(4.5) && $w['margin_pct'] === round(4.5 * 100 / 42, 1), 'price sheet: wholesale 42 -> profit 4.50 per piece');
// ledger: who was paid what
$rows = array_values(array_filter(P::ledger(), fn($x) => $x['purchase'] === $id));
$tot = P::ledgerTotals($rows);
$ok(count($rows) === 3 && $tot['Truck Karim'] === $M(300) && $tot['Labour Rafiq'] === $M(100), 'ledger lists each payment and totals per person');
// who may see purchases: cashier no, manager yes
update_option('sfm_wr_stores', ['main' => ['name' => 'Main']]);
$cash = (int) wp_insert_user(['user_login' => "pc$r", 'user_pass' => wp_generate_password(20), 'user_email' => "pc$r@example.com"]);
$man = (int) wp_insert_user(['user_login' => "pm$r", 'user_pass' => wp_generate_password(20), 'user_email' => "pm$r@example.com"]);
Members::setStaff($cash, 'main', 'cashier'); Members::setStaff($man, 'main', 'manager');
$ok(!P::canManage($cash) && P::canManage($man) && P::canManage(1), 'cashier cannot see purchases/costs; manager and owner can');
wp_delete_post($id, true); wp_delete_post($pid, true);
echo "pass $p fail $f\n";
