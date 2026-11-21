<?php
use SFM4U\Wholesale\Ops\StockFeed;
wp_set_current_user(1); delete_option(StockFeed::OPT_TARGET); update_option(StockFeed::OPT_ROLE, 'off');
$u = StockFeed::startPair('http://127.0.0.1:8894'); parse_str((string) parse_url($u, PHP_URL_QUERY), $q); file_put_contents('/tmp/stock_pair.json', json_encode($q)); echo strlen($q['state'])===32 ? "ok start\n" : "FAIL\n";
