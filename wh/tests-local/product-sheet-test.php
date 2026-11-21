<?php
// One-page product sheet (Owner 2026-11-22): name, photos, case, pieces, buying price, trip costs, retail, wholesale, B2C steps, offer, BN/EN.
use SFM4U\Wholesale\{ProductSheet as PS, PriceAudience as PA, Purchase};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1);
$dec = wc_get_price_decimals(); $M = fn($t) => (int) round($t * (10 ** $dec));
$r = wp_rand(10000, 99999);
$att = wp_insert_attachment(['post_title' => "photo $r", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false);
$post = ['name' => "Fizz 250ml $r", 'photos' => (string) $att, 'pack_name' => 'কেস', 'pieces' => '১২', 'packs' => '১০', 'pack_cost' => '৪০০', 'supplier' => 'Kabirhat depot',
    'exp_own_fare' => '100', 'payee_own_fare' => 'Owner', 'exp_truck' => '300', 'payee_truck' => 'Truck Karim', 'exp_labour' => '100', 'payee_labour' => 'Rafiq',
    'cost_see' => ['only_me'], 'retail' => '45', 'singles' => '1', 'w_piece' => '42', 'w_min' => '12', 'w_see' => ['business', 'vendor'],
    'step_qty' => ['3', '6', '5'], 'step_price' => ['44', '43', '41'], 'step_for' => ['everyone', 'everyone', 'premium'], 'step_see' => ['public', 'public', 'public'],
    'offer_buy' => '7', 'offer_off' => '1', 'offer_for' => 'premium', 'publish' => '1',
    'brand' => 'Fizz Bangladesh Ltd', 'unit' => 'piece', 'mfg' => '2026-11-01', 'exp' => '2027-05-01', 'batch' => 'B12', 'short' => 'Cold drink', 'desc' => 'Orange soft drink', 'delivery_mode' => 'both', 'weight' => '0.28', 'delivery_note' => 'Kabirhat area'];
$d = PS::fromPost($post);
$ok($d['pieces'] === 12 && $d['packs'] === 10 && $d['pack_cost'] === $M(400), 'Bangla digits: 12 pieces, 10 cases, 400 buying price');
$labels = array_column($d['sets'], 'label');
$ok(in_array('Retail', $labels, true) && in_array('Wholesale', $labels, true) && in_array('Wholesale qty', $labels, true) && in_array('Customer price', $labels, true) && in_array('Premium price', $labels, true), 'retail, wholesale, B2C steps per audience built');
$prem = array_values(array_filter($d['sets'], fn($s) => $s['label'] === 'Premium price'))[0];
$ok(($prem['promo'] ?? null) === ['buy' => 7, 'off' => 1], 'offer attached to the premium set');
$id = PS::save($d, 0, 1);
$prod = wc_get_product($id);
$ok($prod && $prod->get_status() === 'publish' && (float) $prod->get_regular_price() === 45.0, 'product created and published at retail 45');
$ok($prod->get_manage_stock() && (int) $prod->get_stock_quantity() === 120, 'stock = 10 cases × 12 = 120 pieces');
$ok((int) $prod->get_image_id() === $att, 'photo set as the product image');
$landed = json_decode((string) get_post_meta($id, Purchase::LANDED, true), true);
$ok($landed['piece_minor'] === $M(37.5) && $landed['pack_minor'] === $M(450), 'real cost 450 per case, 37.50 per piece');
$cost = json_decode((string) get_post_meta($id, PA::COST, true), true);
$ok($cost['minor'] === $M(37.5) && $cost['show_to'] === ['only_me'], 'buying cost private');
$ok(PA::best(PA::sets($id), 'business', 1)['unit'] === $M(42) && PA::best(PA::sets($id), 'public', 12)['unit'] === $M(42) && PA::best(PA::sets($id), 'public', 3)['unit'] === $M(44), 'business 1 pc = 42; anyone 12 pcs = 42; 3 pcs = 44');
$ok(PA::best(PA::sets($id), 'premium', 5)['unit'] === $M(41), 'premium 5 pcs = 41');
$pk = json_decode((string) get_post_meta($id, PS::PACK, true), true);
$ok($pk['name'] === 'কেস' && $pk['pieces'] === 12, 'pack remembered (কেস of 12)');
$ok(preg_match('/^AB0*' . $id . '$/', $prod->get_sku()) === 1 || preg_match('/^AB0*' . $id . '$/', wc_get_product($id)->get_sku()) === 1, 'product code made automatically (AB + id) for QR/barcode');
$info = json_decode((string) get_post_meta($id, PS::INFO, true), true); $del = json_decode((string) get_post_meta($id, PS::DELIVERY, true), true);
$ok($info['brand'] === 'Fizz Bangladesh Ltd' && $info['exp'] === '2027-05-01' && $del['mode'] === 'both' && (float) wc_get_product($id)->get_weight() === 0.28, 'company, expiry, delivery and weight saved');
$ok($bad(fn() => PS::fromPost(['name' => 'X', 'retail' => '10', 'mfg' => '2026-12-01', 'exp' => '2026-11-01'])), 'expiry before made-on refused');
// video: max 15 seconds
$v1 = wp_insert_attachment(['post_title' => "v1 $r", 'post_mime_type' => 'video/mp4', 'post_status' => 'inherit'], false); wp_update_attachment_metadata($v1, ['length' => 20, 'filesize' => 1000]);
$v2 = wp_insert_attachment(['post_title' => "v2 $r", 'post_mime_type' => 'video/mp4', 'post_status' => 'inherit'], false); wp_update_attachment_metadata($v2, ['length' => 9, 'filesize' => 1000]);
$ok($bad(fn() => PS::checkVideo($v1)) && !$bad(fn() => PS::checkVideo($v2)) && $bad(fn() => PS::checkVideo($att)), '20 s video refused, 9 s ok, a photo is not a video');
update_attached_file($v2, "2026/11/v2-$r.mp4");
$again = $post; unset($again['publish']); $again['packs'] = ''; $again['video'] = (string) $v2; $again['just_save'] = '1';
PS::save(PS::fromPost($again), $id, 1);
$pk0 = json_decode((string) get_post_meta($id, PS::PACK, true), true);
$ok($pk0['pieces'] === 12, 'saving again keeps the pack size (12)');
$keepPieces = PS::fromPost(['name' => "Fizz 250ml $r", 'retail' => '45']);
$ok($keepPieces['pieces'] === 0, 'pieces not given = keep saved');
$ok((int) get_post_meta($id, PS::VIDEO, true) === $v2 && wc_get_product($id)->get_status() === 'publish', 'video saved; "save only" keeps it published');
// stock: received / sold / now
$pr = wc_get_product($id); $pr->set_stock_quantity(90); $pr->save();
$sti = PS::stockInfo($id);
$ok($sti['received'] === 120 && $sti['sold'] === 30 && $sti['now'] === 90 && $sti['now_packs'] === 7 && $sti['now_loose'] === 6, 'stock: received 120, sold 30, now 90 = 7 cases + 6');
$pr->set_stock_quantity(120); $pr->save();
// product page (front): video, company, expiry, delivery
global $product; $product = wc_get_product($id); ob_start(); PS::front(); $fr = ob_get_clean();
$ok(strpos($fr, '<video') !== false && strpos($fr, 'Fizz Bangladesh Ltd') !== false && strpos($fr, '2027-05-01') !== false && strpos($fr, 'responsible') !== false || strpos($fr, 'দায়ী') !== false, 'product page shows video, company, expiry, seller responsibility');
// owner decides which parts shops use; a shop cannot change locked parts
PS::saveShopSections(['buy', 'steps']);
$shop = (int) wp_insert_user(['user_login' => "ps$r", 'user_pass' => wp_generate_password(20), 'user_email' => "ps$r@example.com", 'role' => 'shop_manager']);
$vendor = (int) wp_insert_user(['user_login' => "pv$r", 'user_pass' => wp_generate_password(20), 'user_email' => "pv$r@example.com", 'role' => 'author']);
$ok(!PS::allowed('wholesale', $vendor) && PS::allowed('steps', $vendor) && PS::allowed('wholesale', 1), 'owner setting: shop may use steps, not wholesale; admin all');
$try = PS::fromPost(['name' => "Fizz 250ml $r", 'retail' => '45', 'w_piece' => '30']);
$rd = PS::restrict($try, $id, $vendor);
$ws = array_values(array_filter($rd['sets'], fn($s) => $s['label'] === 'Wholesale'));
$ok($ws && reset($ws[0]['ladder']) === $M(42), 'shop tried wholesale 30: kept the saved 42');
PS::saveShopSections(PS::SECTIONS);
// edit: buy 5 more cases -> stock adds up, second purchase recorded
$d2 = PS::fromPost(['name' => "Fizz 250ml $r", 'pieces' => '12', 'packs' => '5', 'pack_cost' => '410', 'retail' => '46']);
PS::save($d2, $id, 1);
$prod = wc_get_product($id);
$ok((int) $prod->get_stock_quantity() === 180 && (float) $prod->get_regular_price() === 46.0, 'edit: +5 cases -> stock 180, new retail 46');
$ok(PS::stockInfo($id)['received'] === 180, 'received total 180');
// page renders in both languages
update_user_meta(1, PS::LANG, 'bn'); $_GET['product'] = (string) $id; ob_start(); PS::page(); $bn = ob_get_clean();
update_user_meta(1, PS::LANG, 'en'); ob_start(); PS::page(); $en = ob_get_clean(); unset($_GET['product']);
$ok(strpos($bn, 'এক প্যাকের কেনা দাম') !== false && strpos($bn, 'পণ্যের ছবি') !== false, 'Bangla page: buying price + photos');
$ok(strpos($en, 'Buying price of one pack') !== false && strpos($en, 'Product photos') !== false, 'English page');
$ok(strpos($en, 'value="46"') !== false || strpos($en, 'value="46.00"') !== false, 'edit page shows saved retail');
$ok(strpos($en, 'data-qr="AB') !== false && strpos($en, '<svg') !== false && strpos($en, 'Print label') !== false && strpos($en, 'Published') !== false, 'label with QR + barcode, print buttons, status shown');
file_put_contents('/tmp/claude-0/sheet-page.html', $en);
// errors
$ok($bad(fn() => PS::fromPost(['name' => '', 'retail' => '10'])) && $bad(fn() => PS::fromPost(['name' => 'X', 'retail' => ''])), 'name and selling price required');
$ok($bad(fn() => PS::fromPost(['name' => 'X', 'retail' => '10', 'step_qty' => ['3', '6'], 'step_price' => ['9', '9.5'], 'step_for' => ['everyone', 'everyone'], 'step_see' => ['public', 'public']])), 'price going up with quantity refused');
wp_delete_post($id, true); wp_delete_attachment($att, true); wp_delete_attachment($v1, true); wp_delete_attachment($v2, true);
update_user_meta(1, PS::LANG, 'bn');
echo "pass $p fail $f\n";
