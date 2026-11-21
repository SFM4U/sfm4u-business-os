<?php
// wp eval-file (local WP; Members module loaded by the card plugin). Uses the mock ERPNext on :8897.
$base = '/home/claude/wh/sfm4u-wholesale/src/Erp/';
foreach (['StockStore', 'ErpNextClient', 'ErpSync'] as $f) { require_once $base . $f . '.php'; }
use SFM4U\Wholesale\Erp\{ErpSync, ErpNextClient, ArrayStockStore};
$p = 0; $f = 0;
$ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL: $m\n"; } };
// pure plan
$pl = ErpSync::plan('pull', ['A' => 5, 'B' => 2, 'D' => 1], ['A' => 7, 'B' => 2, 'C' => 9]);
$by = array_column($pl, null, 'sku');
$ok($by['A']['action'] === 'set_site' && $by['A']['qty'] == 7, 'pull: A site ← 7');
$ok($by['B']['action'] === 'same', 'pull: B same');
$ok($by['C']['action'] === 'not_on_site', 'pull: C only in ERP');
$ok($by['D']['action'] === 'not_in_erp', 'pull: D only on site');
$pp = array_column(ErpSync::plan('push', ['A' => 5, 'D' => 1], ['A' => 7]), null, 'sku');
$ok($pp['A']['action'] === 'set_erp' && $pp['A']['qty'] == 5 && $pp['D']['action'] === 'set_erp', 'push plan');
// settings validation + encryption
try { ErpSync::save(['url' => 'http://evil.example.com']); $ok(false, 'http url accepted'); } catch (DomainException $e) { $ok(true, ''); }
ErpSync::save(['url' => 'http://127.0.0.1:8897', 'key' => 'testkey123', 'secret' => 'testsecret456', 'warehouse' => 'Stores - SFM', 'direction' => 'pull', 'enabled' => '0']);
$ok(strpos(wp_json_encode(get_option(ErpSync::OPT)), 'testsecret456') === false, 'secret stored encrypted');
$ok(ErpSync::client()->whoAmI() === 'website-api@sfm4u.com', 'test connection');
$bad = new ErpNextClient('http://127.0.0.1:8897', 'testkey123', 'wrong');
try { $bad->whoAmI(); $ok(false, 'wrong secret ok'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), 'refused') !== false, 'wrong secret refused'); }
$ok(ErpSync::client()->binQuantities('Stores - SFM') === ['A-100' => 10.0, 'B-200' => 0.0, 'C-300' => 7.0], 'bins for warehouse only');
// off: real sync refused, preview allowed
$store = new ArrayStockStore(['A-100' => 4.0, 'B-200' => 0.0, 'Z-1' => 2.0]);
try { ErpSync::run(false, $store); $ok(false, 'sync while off'); } catch (DomainException $e) { $ok(true, ''); }
$rows = ErpSync::run(true, $store);
$ok($store->q['A-100'] === 4.0 && count($rows) === 4, 'preview changes nothing');
// pull
ErpSync::save(['url' => 'http://127.0.0.1:8897', 'key' => 'testkey123', 'secret' => '', 'warehouse' => 'Stores - SFM', 'direction' => 'pull', 'enabled' => '1']);
ErpSync::run(false, $store);
$ok($store->q['A-100'] === 10.0 && $store->q['Z-1'] === 2.0, 'pull set website stock from ERPNext, kept unmatched');
// push
ErpSync::save(['url' => 'http://127.0.0.1:8897', 'key' => 'testkey123', 'secret' => '', 'warehouse' => 'Stores - SFM', 'direction' => 'push', 'enabled' => '1']);
$store->q['A-100'] = 3.0;
ErpSync::run(false, $store);
$st = json_decode(wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:8897/_state')), true);
$ok($st['bins']['A-100|Stores - SFM'] == 3 && $st['bins']['Z-1|Stores - SFM'] == 2 && count($st['recos']) === 1, 'push sent one Stock Reconciliation');
$ok($st['bins']['X-999|Other - SFM'] == 3, 'other warehouse untouched');
$log = get_option(ErpSync::LOG);
$ok(is_array($log) && count($log) >= 2, 'sync log');
delete_option(ErpSync::OPT); delete_option(ErpSync::LOG);
echo "pass $p fail $f\n";
