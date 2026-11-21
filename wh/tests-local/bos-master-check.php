<?php
use SFM4U\Wholesale\Members\{MemberLink, Members};
$r = MemberLink::requests();
echo (count($r) === 1 && $r[0]['plan'] === 'black' && $r[0]['host'] === '127.0.0.1:8894' && $r[0]['status'] === 'new') ? "pass plan request stored\n" : "FAIL plan " . json_encode($r) . "\n";
$u = get_user_by('email', 'newseller@example.com');
echo ($u && Members::cardsOf($u->ID) && get_user_meta($u->ID, 'billing_phone', true) === '01811222333') ? "pass new member has card\n" : "FAIL new member\n";
wp_set_current_user(get_user_by('email', 'm1@example.com')->ID);
update_user_meta(get_current_user_id(), MemberLink::META_PENDING, ['k' => ['code' => '246810', 'host' => 'algibazar.com', 'exp' => time() + 100, 'tries' => 0], 'old' => ['code' => '1', 'host' => 'x', 'exp' => time() - 5, 'tries' => 0]]);
ob_start(); MemberLink::showCodes(get_current_user_id()); $h = ob_get_clean();
echo (strpos($h, '246810') !== false && strpos($h, 'algibazar.com') !== false && count(get_user_meta(get_current_user_id(), MemberLink::META_PENDING, true)) === 1) ? "pass card page shows live code, drops expired\n" : "FAIL showCodes\n";
