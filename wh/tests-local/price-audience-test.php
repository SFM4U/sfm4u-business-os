<?php
// J2 price audiences: who sees which price set, who buys at which price, offers, private cost price, cart.
use SFM4U\Wholesale\PriceAudience as PA;
use SFM4U\Wholesale\MemberPrice;
use SFM4U\Wholesale\Members\{Members, CardLink};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1); Members::install();
$oldRole = get_option(CardLink::OPT_ROLE); update_option(CardLink::OPT_ROLE, 'off');
$dec = wc_get_price_decimals(); $M = fn($t) => (int) round($t * (10 ** $dec));
$r = wp_rand(10000, 99999);
$mk = fn($n) => (int) wp_insert_user(['user_login' => "pa$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "pa$n$r@example.com"]);
$reg = $mk('reg'); $pre = $mk('pre'); $biz = $mk('biz');
Members::issue($pre, 'premium', 0, 1); Members::issue($biz, 'business', 0, 1);
foreach ([$reg, $pre, $biz] as $u) { MemberPrice::forget($u); }
$ok(PA::buyerType(0) === 'public' && PA::buyerType($reg) === 'regular', 'guest = public, logged in = regular');
$ok(PA::buyerType($pre) === 'premium', 'Premium card = premium');
$ok(PA::buyerType($biz) === 'business', 'Business card = business');
// product with three sets + private cost
$prod = new WC_Product_Simple(); $prod->set_name("PA pen $r"); $prod->set_regular_price((string) (120)); $prod->set_status('publish'); $pid = $prod->save();
$sets = PA::fromLines("Retail | public | everyone | 1=120, 3=114 |\nPremium | public | premium | 1=115, 5=105 | 7+1\nWholesale | business,vendor | business | 1=100, 12=95 |");
PA::saveSets($pid, $sets, ['minor' => $M(80), 'show_to' => ['only_me']]);
$s = PA::sets($pid);
$ok(count($s) === 3 && $s[1]['promo'] === ['buy' => 7, 'off' => 1], 'three sets saved, offer 7+1 kept');
$ok(PA::fromLines(PA::toLines($s)) === $s, 'edit box text round-trips');
$ok(PA::best($s, 'public', 1)['unit'] === $M(120) && PA::best($s, 'public', 3)['unit'] === $M(114), 'guest: retail ladder 1=120, 3=114');
$ok(PA::best($s, 'premium', 1)['unit'] === $M(114 > 115 ? 115 : 115) || PA::best($s, 'premium', 1)['unit'] === $M(115), 'premium 1 piece = 115');
$ok(PA::best($s, 'premium', 5)['unit'] === $M(105), 'premium 5 pieces = 105 (lowest of the sets it may buy)');
$ok(PA::best($s, 'business', 1)['unit'] === $M(100) && PA::best($s, 'business', 12)['unit'] === $M(95), 'business: wholesale at any quantity, 12 = 95');
$ok(PA::best($s, 'regular', 12)['unit'] === $M(114), 'regular cannot buy at premium or wholesale prices');
$ok(PA::promoDiscount($s[1]['promo'], 7, $M(105)) === $M(105) && PA::promoDiscount($s[1]['promo'], 15, $M(105)) === $M(210), 'offer: every 7 pieces, 1 piece off');
// who sees what
$ok(PA::canSee($s[0]['show_to'], 'public', false, false) && !PA::canSee($s[2]['show_to'], 'public', false, false), 'guest sees retail, not wholesale');
$ok(PA::canSee($s[2]['show_to'], 'regular', true, false), 'a shop (vendor) sees the wholesale set');
$ok(PA::canSee($s[2]['show_to'], 'business', false, false) && !PA::canSee($s[2]['show_to'], 'premium', false, false), 'business sees wholesale, premium does not');
$g = PA::boxHtml($pid, 0);
$ok(strpos($g, 'Become a Premium member') !== false && strpos($g, 'Wholesale') === false && strpos($g, 'Cost price') === false, 'guest box: premium invite, no wholesale, no cost');
$ok(strpos(PA::boxHtml($pid, $biz), 'Wholesale') !== false && strpos(PA::boxHtml($pid, $biz), 'Cost price') === false, 'business box: wholesale shown, cost hidden');
$ok(strpos(PA::boxHtml($pid, 1), 'Cost price') !== false && strpos(PA::boxHtml($pid, 1), 'only you see this') !== false, 'owner/admin sees the private cost');
PA::saveSets($pid, $s, ['minor' => $M(80), 'show_to' => ['business']]);
$ok(strpos(PA::boxHtml($pid, $biz), 'Cost price') !== false && strpos(PA::boxHtml($pid, $pre), 'Cost price') === false, 'shop may publish cost to business members only');
// validation
$ok($bad(fn() => PA::fromLines("X | public | everyone | 1=100, 3=110 |")), 'price rising with quantity refused');
$ok($bad(fn() => PA::fromLines("X | friends | everyone | 1=100 |")), 'unknown "who can see" refused');
$ok($bad(fn() => PA::fromLines("X | public | vip | 1=100 |")), 'unknown "who can buy" refused');
$ok($bad(fn() => PA::fromLines("X | public | premium | 1=100 | 3+3")), 'bad offer refused');
// cart (WooCommerce): premium buys 7 -> unit 105 and offer discount 105
if (function_exists('wc_load_cart')) {
    wp_set_current_user($pre); MemberPrice::forget($pre);
    wc_load_cart(); WC()->cart->empty_cart();
    WC()->cart->add_to_cart($pid, 7); WC()->cart->calculate_totals();
    $line = array_values(WC()->cart->get_cart())[0] ?? null;
    $fees = WC()->cart->get_fees();
    $fee = $fees ? array_sum(array_map(fn($x) => (float) $x->amount, $fees)) : 0.0;
    $ok($line && abs((float) $line['data']->get_price() - 105) < 0.001, 'cart: premium unit price 105 (got ' . ($line ? $line['data']->get_price() : '-') . ')');
    $ok(abs($fee + 105) < 0.001, 'cart: offer discount -105 (got ' . $fee . ')');
    WC()->cart->empty_cart(); wp_set_current_user(1);
} else { $ok(false, 'wc_load_cart missing'); }
wp_delete_post($pid, true);
$oldRole !== false ? update_option(CardLink::OPT_ROLE, $oldRole) : delete_option(CardLink::OPT_ROLE);
echo "pass $p fail $f\n";
