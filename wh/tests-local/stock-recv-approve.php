<?php
use SFM4U\Wholesale\Ops\StockFeed;
wp_set_current_user(1); $q = json_decode(file_get_contents('/tmp/stock_pair.json'), true);
try { StockFeed::approvePair($q['site'], 'https://evil.example/x', $q['state']); echo "FAIL other host\n"; } catch (DomainException $e) { echo "ok refuse\n"; }
$b = StockFeed::approvePair($q['site'], $q['back'], $q['state']); parse_str((string) parse_url($b, PHP_URL_QUERY), $r); file_put_contents('/tmp/stock_pair2.json', json_encode($r)); echo strpos($b,'secret')===false ? "ok approve\n" : "FAIL\n";
