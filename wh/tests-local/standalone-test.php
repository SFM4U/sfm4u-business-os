<?php
// Run: wp eval-file standalone-test.php  (site WITHOUT WooCommerce, plugin sfm4u-member-card active)
use SFM4U\Wholesale\Members\{Members, CardView, MembersFront, CardGuide, BdMap};
$p = 0; $f = 0;
$ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL: $m\n"; } };
global $wpdb;
$ok(!class_exists('WooCommerce'), 'no WooCommerce on this site');
$ok(defined('SFM4U_MEMBERS_FROM') && SFM4U_MEMBERS_FROM === 'card', 'loaded by the card plugin');
Members::maybeInstall();
$ok((bool) $wpdb->get_var("SELECT COUNT(*) FROM " . Members::cards() . " WHERE 1=0") !== null, 'cards table queryable');
$ok(Members::manageCap() === 'manage_options', 'manager cap = manage_options without WC');
$ok(Members::autoOn(), 'auto-issue on by default');
// 1. new account -> card
$login = 'cardtest' . wp_rand(1000, 9999);
$uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'user_email' => $login . '@example.com', 'display_name' => 'Card Test']);
$ok(!is_wp_error($uid), 'user created');
$cards = Members::cardsOf((int) $uid);
$ok(count($cards) === 1 && $cards[0]['tier'] === 'regular', 'new account got one regular card');
$mno = Members::memberNo((int) $uid);
$ok(preg_match('/^\d{7}$/', $mno) === 1, 'member number 7 digits: ' . $mno);
// 2. auto off -> no card
update_option(Members::AUTO_OPTION, '0');
$u2 = wp_insert_user(['user_login' => $login . 'b', 'user_pass' => wp_generate_password(20), 'user_email' => $login . 'b@example.com']);
$ok(count(Members::cardsOf((int) $u2)) === 0, 'auto off: no card');
update_option(Members::AUTO_OPTION, '1');
// 3. backfill
wp_set_current_user(1);
$n = Members::backfill(50);
$ok($n >= 1 && count(Members::cardsOf((int) $u2)) === 1, 'backfill gave cards (' . $n . ')');
$ok(Members::backfill(50) === 0, 'backfill twice gives none');
// 4. grouping
$ok(CardView::groupDigits('2865023705') === '286 502 3705', '10-digit 3-3-4');
$ok(CardView::groupDigits('1234567890123') === '123 456 789 0123', '13-digit');
$ok(CardView::groupDigits('20017524708000044') === '2001 7524 7080 00044', '17-digit');
// 5. shortcodes
wp_set_current_user(0);
$ok(strpos(do_shortcode('[sfm4u_member_card]'), 'log in') !== false || strpos(do_shortcode('[sfm4u_member_card]'), 'Log in') !== false, 'logged out: login prompt');
$ok(strpos(do_shortcode('[sfm4u_card_guide]'), 'sfm-cv') === false, 'logged out: guide hidden');
wp_set_current_user((int) $uid);
$html = do_shortcode('[sfm4u_member_card]');
$ok(strpos($html, 'sfm-cv-front') !== false && strpos($html, 'No. ' . $mno) !== false, 'card page shows card with number');
$ok(strpos($html, 'sfm-wr-sigpad') !== false, 'signature pad on member page');
$ok(strpos($html, 'sfm-wr-nidscan') !== false, 'NID scan button on member page');
$ok(strpos($html, 'শর্তাবলি') !== false, 'guide inside card page');
$g = do_shortcode('[sfm4u_card_guide]');
$ok(strpos($g, 'SAMPLE MEMBER') !== false && strpos($g, '123 456 7890') !== false, 'guide shows sample card');
$ok(strpos($g, 'Admin only') === false, 'members do not see admin draft note');
// 6. self details: 2 saves then locked
$_POST = ['sfm_wr_my_details' => '1', 'sfm_wr_name_en' => 'CARD TEST', 'sfm_wr_mother' => 'মাতা', 'sfm_wr_address' => 'Maijdee, Noakhali', 'billing_phone' => '০১৭০০ ০০০০০০', '_wpnonce' => wp_create_nonce('sfm_wr_my_details')];
$_REQUEST = $_POST;
ob_start(); MembersFront::page(); $o = ob_get_clean();
$ok(strpos($o, 'woocommerce-message') !== false, 'first self save ok');
$ok(get_user_meta((int) $uid, 'billing_phone', true) === '01700000000', 'mobile normalised from Bangla digits');
$ok(BdMap::districtFor((int) $uid) === 'Noakhali', 'district detected from address');
$ok(Members::selfEditsLeft((int) $uid) === 1, 'one self edit left');
$_POST['sfm_wr_name_en'] = 'CARD TEST TWO'; $_REQUEST = $_POST;
ob_start(); MembersFront::page(); ob_get_clean();
$ok(Members::selfEditsLeft((int) $uid) === 0, 'no self edits left');
$_POST['sfm_wr_name_en'] = 'HACK'; $_REQUEST = $_POST;
ob_start(); MembersFront::page(); $o = ob_get_clean();
$ok(get_user_meta((int) $uid, 'sfm_wr_name_en', true) === 'CARD TEST TWO' && strpos($o, 'woocommerce-error') !== false, 'third save refused');
// 7. signature: unlimited
$png = 'data:image/png;base64,' . base64_encode(file_get_contents(WP_PLUGIN_DIR . '/sfm4u-member-card/assets/sample-signature.png'));
foreach ([1, 2] as $i) {
    $_POST = ['sfm_wr_my_sign' => '1', 'sfm_wr_signature' => $png, '_wpnonce' => wp_create_nonce('sfm_wr_my_sign')]; $_REQUEST = $_POST;
    ob_start(); MembersFront::page(); $o = ob_get_clean();
    $ok(strpos($o, 'signature is saved') !== false, "signature save #$i");
}
$ok(Members::signature((int) $uid) !== '', 'signature stored');
$_POST = ['sfm_wr_my_sign' => '1', 'sfm_wr_signature' => 'data:image/png;base64,AAAA', '_wpnonce' => wp_create_nonce('sfm_wr_my_sign')]; $_REQUEST = $_POST;
ob_start(); MembersFront::page(); $o = ob_get_clean();
$ok(strpos($o, 'woocommerce-error') !== false, 'bad signature refused');
// 8. BRN self link (new user)
$u3 = wp_insert_user(['user_login' => $login . 'c', 'user_pass' => wp_generate_password(20), 'user_email' => $login . 'c@example.com']);
wp_set_current_user((int) $u3);
$brn = '2001' . str_pad((string) wp_rand(0, 999999999), 9, '0', STR_PAD_LEFT) . '0044';
$_POST = ['sfm_wr_my_details' => '1', 'id_type' => 'brn', 'national_id' => $brn, 'id_consent' => 'yes', '_wpnonce' => wp_create_nonce('sfm_wr_my_details')]; $_REQUEST = $_POST;
ob_start(); MembersFront::page(); ob_get_clean();
$c3 = Members::cardsOf((int) $u3)[0];
$ok($c3['id_type'] === 'brn' && $c3['id_last4'] === '0044', 'birth registration linked');
$ok(Members::nationalId($c3) === $brn, 'BRN decrypts');
$ok(strpos((string) $wpdb->get_var($wpdb->prepare('SELECT id_enc FROM ' . Members::cards() . ' WHERE id=%d', $c3['id'])), substr($brn, 0, 10)) === false, 'BRN not stored in plain text');
$_POST = []; $_REQUEST = [];
// 9. log
$log = get_user_meta((int) $uid, 'sfm_wr_details_log', true);
$ok(is_array($log) && count($log) >= 3, 'change log kept');
echo "pass $p fail $f\n";
