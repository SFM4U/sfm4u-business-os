<?php
// L-01: Business Black auto stock for a COUNTER (POS) member sale. Synthetic; AlgiBazar receiver is mocked via pre_http_request.
// The send must happen AFTER the sale committed (scheduled event), once, only for a paid Black member, never for unpaid/non-member/online-processing.
use SFM4U\Wholesale\Ops\StockFeed;
use SFM4U\Wholesale\Members\{Members, Tiers};
wp_set_current_user(1);
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
update_option('woocommerce_currency','BDT'); Members::install();
$oldRole = get_option(StockFeed::OPT_ROLE); $oldTarget = get_option(StockFeed::OPT_TARGET);
update_option(StockFeed::OPT_ROLE, 'sender');
update_option(StockFeed::OPT_TARGET, ['url' => 'https://algibazar.example.test', 'key_id' => 'k_test', 'secret_enc' => Members::encrypt(str_repeat('b', 64))]);
$calls = []; $down = false;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, &$down) {
    if (strpos($url, 'algibazar.example.test') === false) return $pre;
    if ($down) return new WP_Error('down', 'unreachable');
    $calls[] = json_decode((string) ($args['body'] ?? ''), true);
    return ['headers' => [], 'body' => wp_json_encode(['ok' => true, 'created' => 1, 'updated' => 0]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
}, 10, 3);
$r = wp_rand(10000, 99999);
$mkUser = function ($n) use ($r) { return (int) wp_insert_user(['user_login' => "sf$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "sf$n$r@example.com"]); };
$blk = $mkUser('blk'); Members::issue($blk, 'black', 0, 1, 'main', null, 'Shop Black'); Tiers::recordBlackPayment($blk, 1, 'cash', '', 1);
$unpaid = $mkUser('unp'); Members::issue($unpaid, 'black', 0, 1, 'main', null, 'Shop Unpaid');
$prod = new WC_Product_Simple(); $prod->set_name('SF Rice'); $prod->set_sku('SF-RICE-' . $r); $prod->set_regular_price('100'); $prod->set_manage_stock(true); $prod->set_stock_quantity(50); $prod->set_status('publish'); $prod->save();
$mkOrder = function (int $memberUser, string $source = 'pos') use ($prod) {
    $o = wc_create_order(['created_via' => 'sfm4u-pos-fixture']); $o->add_product(wc_get_product($prod->get_id()), 3);
    if ($source !== '') { $o->update_meta_data('_sfmwh_member_source', $source); }
    if ($memberUser) { $o->update_meta_data(StockFeed::META_MEMBER_USER, $memberUser); }
    $o->calculate_totals(); $o->save(); return $o;
};
$ok(Tiers::blackActive($blk) && !Tiers::blackActive($unpaid), 'fixture: one paid Black, one unpaid Black');

// 1. paid Black member at the counter: processing -> event queued, NOT sent inside the status change
$o = $mkOrder($blk); $o->update_status('processing'); $id = $o->get_id();
$ok(count($calls) === 0, 'nothing sent during the POS status change (inside the sale transaction)');
$ok((bool) wp_next_scheduled(StockFeed::CRON_POS, [$id]), 'one-off send queued after commit');
StockFeed::onPosPaid($id); $ok(count(array_filter(_get_cron_array(), fn($e) => isset($e[StockFeed::CRON_POS]))) >= 1, 'second trigger does not add a duplicate event');
$res = StockFeed::sendPos($id);
$ok($res === 'sent' && count($calls) === 1, 'queued job sends once: ' . $res);
$sent = $calls[0] ?? [];
$ok(($sent['member_no'] ?? '') === Members::memberNo($blk) && ($sent['items'][0]['qty'] ?? 0) == 3 && ($sent['items'][0]['sku'] ?? '') === $prod->get_sku(), 'payload: member number, SKU and quantity of the counter sale');
$ok(StockFeed::sendPos($id) === 'sent' && count($calls) === 1, 'running the job again does not send twice');

// 2. unpaid Black -> skip
$o2 = $mkOrder($unpaid); $o2->update_status('processing');
$ok(StockFeed::sendPos($o2->get_id()) === 'skip' && count($calls) === 1, 'unpaid Business Black: no stock sent');

// 3. online (non-POS) processing order of the Black member -> not queued (online waits for "completed")
$o3 = $mkOrder(0, ''); $o3->set_customer_id($blk); $o3->save(); $o3->update_status('processing');
$ok(!wp_next_scheduled(StockFeed::CRON_POS, [$o3->get_id()]) && count($calls) === 1, 'online order at processing: not sent');
$o3->update_status('completed'); $ok(count($calls) === 2, 'online order sent at completed (existing behaviour kept)');

// 4. sale undone before the job ran (cancelled) -> job skips
$o4 = $mkOrder($blk); $o4->update_status('processing'); $o4->update_status('cancelled');
$ok(StockFeed::sendPos($o4->get_id()) === 'skip' && count($calls) === 2, 'cancelled before the job ran: nothing sent');

// 5. AlgiBazar down -> failed, retry later sends once
$down = true; $o5 = $mkOrder($blk); $o5->update_status('processing');
$ok(StockFeed::sendPos($o5->get_id()) === 'failed', 'receiver down: marked failed');
$down = false; StockFeed::retry();
$m5 = wc_get_order($o5->get_id())->get_meta(StockFeed::META);
$ok(($m5['status'] ?? '') === 'sent' && count($calls) === 3, 'hourly retry sends the failed counter sale once');
StockFeed::retry(); $ok(count($calls) === 3, 'retry again: no second send');

// 6. member user meta without POS source is ignored
$o6 = $mkOrder($blk, ''); $o6->update_status('processing');
$ok(StockFeed::memberUser($o6) === 0 && StockFeed::sendPos($o6->get_id()) === 'skip', 'member meta without POS source is not trusted');

update_option(StockFeed::OPT_ROLE, $oldRole ?: 'off'); $oldTarget ? update_option(StockFeed::OPT_TARGET, $oldTarget) : delete_option(StockFeed::OPT_TARGET);
echo "pass $p fail $f\n";
