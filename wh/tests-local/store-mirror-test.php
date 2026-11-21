<?php
// SFM4U Store Mirror: products + stock from a (mocked) AlgiBazar Dokan store; buy link goes to AlgiBazar; stale fallback.
use SFM4U\StoreMirror\Mirror;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
require_once '/home/user/sfm4u-business-os/wh/sfm4u-store-mirror/sfm4u-store-mirror.php';
$mode = 'ok'; $calls = 0;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$mode, &$calls) {
    if (strpos($url, '/wp-json/dokan/v1/stores/8/products') === false) return $pre;
    $calls++;
    if ($mode === 'down') return new WP_Error('down', 'source down');
    $body = [
        ['id' => 501, 'name' => 'Khata 200 pages <b>x</b>', 'price' => '120', 'regular_price' => '150', 'permalink' => 'https://algibazar.com/product/khata/', 'images' => [['src' => 'https://algibazar.com/k.jpg']], 'stock_status' => 'instock', 'stock_quantity' => 37, 'status' => 'publish', 'purchasable' => true],
        ['id' => 502, 'name' => 'Pen box', 'price' => '60', 'permalink' => 'https://algibazar.com/product/pen/', 'images' => [], 'stock_status' => 'outofstock', 'stock_quantity' => 0, 'status' => 'publish'],
        ['id' => 503, 'name' => 'Draft item', 'price' => '1', 'status' => 'draft'],
    ];
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
delete_option(Mirror::OPT);
$ok(Mirror::products()['error'] === 'not_configured' && Mirror::shortcode() === '', 'not configured: nothing shown to visitors');
update_option(Mirror::OPT, ['source' => 'https://algibazar.com', 'store' => 8, 'cache' => 60]);
global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%sfm4u_store_mirror_products%'");
$r = Mirror::products();
$ok(count($r['items']) === 2 && $r['error'] === '', 'published products only (draft skipped)');
$ok($r['items'][0]['name'] === 'Khata 200 pages x' && $r['items'][0]['qty'] === 37, 'name cleaned, stock quantity kept');
$h = Mirror::shortcode();
$ok(strpos($h, 'https://algibazar.com/cart/?add-to-cart=501&#038;quantity=1') !== false || strpos($h, 'https://algibazar.com/cart/?add-to-cart=501&amp;quantity=1') !== false, 'buy link adds to the AlgiBazar cart');
$ok(strpos($h, 'add-to-cart=502') === false && strpos($h, 'Out of stock') !== false, 'out of stock: no buy button');
$ok(strpos($h, '37 in stock') !== false && strpos($h, '<b>') === false, 'stock shown, HTML escaped');
$before = $calls; Mirror::products(); $ok($calls === $before, 'second read uses the cache (no new request)');
delete_transient('sfm4u_store_mirror_products_' . md5('https://algibazar.com|8'));
$mode = 'down'; $r2 = Mirror::products();
$ok($r2['error'] === 'source_unavailable' && count($r2['items']) === 2, 'AlgiBazar down: last good list shown');
$ok(strpos(Mirror::shortcode(), 'may be a few minutes old') !== false, 'visitor sees the stale note');
delete_option(Mirror::OPT);
echo "pass $p fail $f\n";
