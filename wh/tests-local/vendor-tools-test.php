<?php
// L-02 (0.9.9-dev): Business OS seller tools only for Business Black members' stores; regular sellers keep plain Dokan.
use SFM4U\Wholesale\Members\{Members, Tiers};
use SFM4U\Wholesale\Market\VendorProfit;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
wp_set_current_user(1); Members::install();
$old = get_option(Tiers::VENDOR_POLICY_OPT); delete_option(Tiers::VENDOR_POLICY_OPT);
$dokan = function_exists('dokan_is_user_seller');
$r = wp_rand(10000, 99999);
$mkSeller = function ($n) use ($r, $dokan) {
    $u = (int) wp_insert_user(['user_login' => "vt$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "vt$n$r@example.com"]);
    $w = new WP_User($u);
    if ($dokan) { $w->set_role('seller'); update_user_meta($u, 'dokan_enable_selling', 'yes'); } else { $w->add_cap('seller'); }
    return $u;
};
$regular = $mkSeller('reg');
$black = $mkSeller('blk'); Members::issue($black, 'black', 0, 1, 'main', null, 'Black Store'); Tiers::recordBlackPayment($black, 1, 'cash', '', 1);
$unpaid = $mkSeller('unp'); Members::issue($unpaid, 'black', 0, 1, 'main', null, 'Unpaid Store');
$expired = $mkSeller('exp'); Members::issue($expired, 'black', 0, 1, 'main', null, 'Expired Store'); update_user_meta($expired, Tiers::BLACK_UNTIL, gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS));
$granted = $mkSeller('grt'); update_user_meta($granted, Tiers::VENDOR_GRANT_META, 'yes');
$buyer = (int) wp_insert_user(['user_login' => "vtbuy$r", 'user_pass' => wp_generate_password(20), 'user_email' => "vtbuy$r@example.com"]);
Members::issue($buyer, 'black', 0, 1, 'main', null, 'Not a seller'); Tiers::recordBlackPayment($buyer, 1, 'cash', '', 1);

$ok(get_option(Tiers::VENDOR_POLICY_OPT, 'black') === 'black', 'default policy: Business Black stores only');
$ok(!Tiers::vendorToolsAllowed($regular) && !VendorProfit::isSeller($regular), 'regular seller: plain Dokan, no Business OS tools');
$ok(Tiers::vendorToolsAllowed($black) && VendorProfit::isSeller($black), 'paid Business Black store: Business OS tools');
$ok(!Tiers::vendorToolsAllowed($unpaid), 'unpaid Black: no tools');
$ok(!Tiers::vendorToolsAllowed($expired), 'expired Black: no tools');
$ok(Tiers::vendorToolsAllowed($granted) && VendorProfit::isSeller($granted), 'admin-granted store (trial): tools');
$ok(!VendorProfit::isSeller($buyer), 'Black member who is not a seller: not a store');
if ($dokan && has_filter('user_has_cap', [\SFM4U\Wholesale\B2BRuntime::class, 'capabilities'])) { // B2B runtime really active
    $ok(!user_can($regular, 'sfm4u_b2b_offer') && user_can($black, 'sfm4u_b2b_offer'), 'B2B wholesale offer capability: Black store yes, regular seller no');
}
wp_set_current_user($regular); ob_start(); VendorProfit::page(); $h = ob_get_clean();
$ok(strpos($h, 'This page is for AlgiBazar sellers.') !== false, 'regular seller sees no Business OS profit page');
wp_set_current_user(1);
update_option(Tiers::VENDOR_POLICY_OPT, 'all');
$ok(Tiers::vendorToolsAllowed($regular), "policy 'all' (owner switch) opens tools to every seller");
$old !== false ? update_option(Tiers::VENDOR_POLICY_OPT, $old) : delete_option(Tiers::VENDOR_POLICY_OPT);
echo "pass $p fail $f\n";
