<?php
// 0.9.9-dev: khata (due) at the shop — idempotent request key, due summary for the cashier, day-by-day history. Synthetic.
use SFM4U\Wholesale\Members\Members;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn,$code=null){try{$fn();return false;}catch(DomainException $e){return $code===null||$e->getCode()===$code;}};
wp_set_current_user(1); update_option('woocommerce_currency','BDT');
global $wpdb;
// schema upgrade 5 -> 6 adds khata.ref on an existing table
update_option(Members::SCHEMA_OPTION, '5'); Members::install();
$ok(get_option(Members::SCHEMA_OPTION) === '6', 'schema upgraded to 6');
$ok((bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . Members::khata() . ' LIKE %s', 'ref')), 'khata.ref column exists after upgrade');
$r = wp_rand(10000, 99999);
$u = (int) wp_insert_user(['user_login' => "kd$r", 'user_pass' => wp_generate_password(20), 'user_email' => "kd$r@example.com"]);
$st = 'shop' . $r; $other = 'other' . $r;
$m = fn($taka) => $taka * (10 ** Members::decimals());
// sale on credit with a request key, replayed by a retry -> one row
$a = Members::record($u, 0, 'charge', $m(500), '', 'POS sale #1 on credit', 1, 'up', $st, 'pos-sale-1');
$b = Members::record($u, 0, 'charge', $m(500), '', 'POS sale #1 on credit', 1, 'up', $st, 'pos-sale-1');
$ok($a['id'] === $b['id'] && !empty($b['replayed']) && Members::balance($u, $st) === $m(500), 'retry with the same key: one row, balance 500');
$ok($bad(fn() => Members::record($u, 0, 'charge', $m(700), '', 'x', 1, 'up', $st, 'pos-sale-1'), 409), 'same key, different amount -> 409');
$ok($bad(fn() => Members::record($u, 0, 'payment', $m(500), 'cash', 'x', 1, 'up', $st, 'pos-sale-1'), 409), 'same key, different kind -> 409');
$ok((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Members::khata() . ' WHERE user_id=%d AND store=%s', $u, $st)) === 1, 'still exactly one row');
// keys are per store; no key = always a new row (old behaviour)
Members::record($u, 0, 'charge', $m(50), '', 'other store', 1, 'up', $other, 'pos-sale-1');
$ok(Members::balance($u, $other) === $m(50) && Members::balance($u, $st) === $m(500), 'same key in another store is a separate entry; balances separate');
Members::record($u, 0, 'charge', $m(100), '', 'no key 1', 1, 'up', $st); Members::record($u, 0, 'charge', $m(100), '', 'no key 2', 1, 'up', $st);
$ok(Members::balance($u, $st) === $m(700), 'entries without a key are never merged (balance 700)');
// partial payment toward the due
Members::record($u, 0, 'payment', $m(300), 'cash', 'paid part of due at the counter', 1, 'up', $st, 'pos-pay-1');
$s = Members::dueSummary($u, $st);
$ok($s['due_minor'] === $m(400) && $s['advance_minor'] === 0, 'due summary: 400 still owed');
$ok($s['due_since'] !== null && strtotime($s['due_since']) <= time(), 'due summary: owed since a date');
$ok(($s['last_payment']['amount_minor'] ?? 0) === $m(300) && ($s['last_payment']['method'] ?? '') === 'cash', 'due summary: last payment 300 cash');
$ok(count($s['recent']) === 4 && $s['recent'][0]['kind'] === 'payment', 'due summary: newest rows first');
// day-by-day history
$h = Members::dueHistory($u, $st);
$ok(count($h) === 1 && $h[0]['charged_minor'] === $m(700) && $h[0]['paid_minor'] === $m(300) && $h[0]['closing_minor'] === $m(400) && $h[0]['entries'] === 4, 'history: today charged 700, paid 300, closing 400');
// pay everything -> no due, "since" cleared; overpay -> advance
Members::record($u, 0, 'payment', $m(400), 'mobile', 'cleared', 1, 'up', $st, 'pos-pay-2');
$s2 = Members::dueSummary($u, $st);
$ok($s2['due_minor'] === 0 && $s2['due_since'] === null, 'all paid: no due, no date');
Members::record($u, 0, 'payment', $m(20), 'cash', 'advance', 1, 'up', $st);
$ok(Members::dueSummary($u, $st)['advance_minor'] === $m(20), 'overpayment shows as advance 20');
// due date resets: new credit after clearing starts a new "since"
$wpdb->query($wpdb->prepare('UPDATE ' . Members::khata() . ' SET created_at=%s WHERE user_id=%d AND store=%s', gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS), $u, $st));
Members::record($u, 0, 'charge', $m(90), '', 'new credit', 1, 'up', $st);
$s3 = Members::dueSummary($u, $st);
$ok($s3['due_minor'] === $m(70) && strtotime($s3['due_since']) > time() - DAY_IN_SECONDS, 'new due after an advance: owed since today, not 3 days ago');
$h3 = Members::dueHistory($u, $st);
$ok(count($h3) === 2 && $h3[0]['charged_minor'] === $m(90) && $h3[1]['paid_minor'] === $m(720), 'history: two days, newest first');
echo "pass $p fail $f\n";
