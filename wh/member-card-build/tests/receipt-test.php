<?php
use SFM4U\Wholesale\Members\{Members, Rewards, Receipt};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
update_option('sfm_wr_stores', ['main' => ['name' => 'SFM4U Test Shop', 'phone' => '01700-000000', 'address' => 'Maijdee, Noakhali', 'licence_name' => 'SFM4U Traders (TEST)', 'licence_no' => 'TRAD/123/2026', 'policy_url' => 'https://example.com/policy']]);
update_option('sfm_wr_rewards', ['points_on' => 'yes', 'rates' => ['main' => ['per' => 100, 'value' => 1]], 'company' => ['per' => 200, 'value' => 1], 'wallet_on' => 'no']);
$uid = wp_insert_user(['user_login' => 'rc' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => wp_rand(1, 99999) . 'rc@example.com', 'display_name' => 'Receipt Test']);
update_user_meta($uid, 'sfm_wr_name_bn', 'রসিদ পরীক্ষা');
wp_set_current_user(1);
$card = Members::cardsOf((int) $uid)[0];
$r = Rewards::purchase((int) $uid, 'main', 125000, '', 'চাল', 1);
$ok($r['id'] > 0, 'purchase row id');
$rc = Receipt::load('P', $r['id']);
$ok($rc && $rc['amount'] === 125000 && $rc['store'] === 'main' && $rc['note'] === 'চাল', 'purchase receipt data');
$h = Receipt::html($rc);
foreach (['SFM4U Traders (TEST)', 'TRAD/123/2026', 'Maijdee', '01700-000000', 'ক্রয়ের রসিদ', 'রসিদ পরীক্ষা', Members::memberNo((int) $uid), '+12', 'data-qr="https://example.com/policy"', 'P-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT)] as $needle) { $ok(strpos($h, $needle) !== false, 'receipt has ' . $needle); }
$k = Members::record((int) $uid, (int) $card['id'], 'charge', 50000, '', 'বাকি', 1, 'up', 'main');
$k2 = Members::record((int) $uid, (int) $card['id'], 'payment', 20000, 'mobile', '', 1, 'up', 'main');
$ok($k2['id'] === $k['id'] + 1, 'khata ids');
$rk = Receipt::load('K', $k2['id']);
$hk = Receipt::html($rk);
$ok(strpos($hk, 'খাতার টাকা জমার রসিদ') !== false && strpos($hk, 'বিকাশ / নগদ') !== false && $rk['khata_balance'] === 30000, 'payment receipt');
$ok(Receipt::parseRef('k-12') === ['K', 12] && Receipt::parseRef('X-1') === null && Receipt::parseRef('P-1;drop') === null, 'ref parsing');
$ok(Receipt::load('P', 999999) === null, 'missing receipt');
file_put_contents('/tmp/rc_ids.txt', $r['id'] . ' ' . $k2['id']);
echo "pass $p fail $f\n";
