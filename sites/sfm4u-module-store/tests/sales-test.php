<?php
// CL-3 sales (sites/sfm4u-module-store/src/Sales.php + ReleaseServer licence keys). Synthetic data only, no network.
// Run: wp eval-file sites/sfm4u-module-store/tests/sales-test.php   (store plugin 0.6.0 + WooCommerce active)
use SFM4U\ModuleStore\ReleaseServer as RS;
use SFM4U\ModuleStore\Sales;
use const SFM4U\ModuleStore\CPT;

$p = 0; $f = 0;
$ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL $m\n"; } };
$throws = static function (callable $fn): string { try { $fn(); } catch (\Throwable $e) { return $e->getMessage() ?: get_class($e); } return ''; };
global $wpdb;

// ---------- isolated release store ----------
$dir = sys_get_temp_dir() . '/sfm-sales-test-' . wp_rand(1000, 9999);
wp_mkdir_p($dir);
$dirFilter = static fn() => $dir;
add_filter('sfm4u_release_store_dir', $dirFilter);
$oldSites = get_option(RS::OPT_SITES, null);
delete_option(RS::OPT_SITES);
$mkzip = static function (string $path, string $folder, string $ver) {
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString("$folder/$folder.php", "<?php\n/**\n * Plugin Name: test $folder\n * Version: $ver\n */\n"); $z->close();
    return hash_file('sha256', $path);
};
$rel = [];
foreach ([['sfm4u-module-alpha', 'T01', '1.0.0'], ['sfm4u-module-alpha', 'T01', '1.1.0'], ['sfm4u-module-beta', 'T02', '2.0.0'], ['sfm4u-wholesale', 'A00', '0.9.30']] as [$slug, $code, $ver]) {
    $sha = $mkzip("$dir/$slug-$ver.zip", $slug, $ver);
    $rel[] = ['slug' => $slug, 'code' => $code, 'version' => $ver, 'sha256' => $sha, 'size' => filesize("$dir/$slug-$ver.zip"), 'name' => "Test $slug"];
}
file_put_contents("$dir/releases.json", wp_json_encode($rel));
RS::protectStore();

// ---------- users + modules ----------
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$admin = (int) $admins[0];
$buyer = wp_insert_user(['user_login' => 'sfm_buyer_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'buyer' . wp_rand(1000, 9999) . '@example.test', 'role' => 'customer']);
$other = wp_insert_user(['user_login' => 'sfm_other_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'other' . wp_rand(1000, 9999) . '@example.test', 'role' => 'customer']);
wp_set_current_user($admin);
$modA = wp_insert_post(['post_type' => CPT, 'post_status' => 'publish', 'post_title' => 'Test Alpha module']);
$modB = wp_insert_post(['post_type' => CPT, 'post_status' => 'publish', 'post_title' => 'Test Beta module']);
update_post_meta($modA, '_sfm_code', 'T01');
update_post_meta($modB, '_sfm_code', 'T02');
$modX = wp_insert_post(['post_type' => CPT, 'post_status' => 'publish', 'post_title' => 'Test no release']);
update_post_meta($modX, '_sfm_code', 'T99');

// 1. module → slug
$ok(Sales::slugForModule($modA) === 'sfm4u-module-alpha' && Sales::slugForModule($modB) === 'sfm4u-module-beta', 'slug found by module code');
$ok(Sales::slugForModule($modX) === '', 'no release → no slug');
update_post_meta($modX, '_sfm_slug', 'sfm4u-module-beta'); $ok(Sales::slugForModule($modX) === 'sfm4u-module-beta', 'Release slug field overrides code');
update_post_meta($modX, '_sfm_slug', '../wp-config'); $ok(Sales::slugForModule($modX) === '', 'bad Release slug ignored');
delete_post_meta($modX, '_sfm_slug');

// 2. admin download
$died = static function (callable $fn): string {
    $h = static fn() => static function ($m = '') { throw new RuntimeException('die:' . (is_string($m) ? wp_strip_all_tags($m) : 'x')); };
    add_filter('wp_die_handler', $h, 999); add_filter('wp_die_ajax_handler', $h, 999);
    try { ob_start(); $fn(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } finally { ob_end_clean(); remove_filter('wp_die_handler', $h, 999); remove_filter('wp_die_ajax_handler', $h, 999); }
};
$streamed = [];
$stop = static function ($pre, $r, $ctx) use (&$streamed) { $streamed[] = [$ctx, $r['slug'], $r['version'], $r['path']]; return 'stopped'; };
add_filter('sfm4u_store_pre_stream', $stop, 10, 3);

wp_set_current_user($buyer);
$r = Sales::adminRelease($modA);
$ok(is_wp_error($r) && $r->get_error_code() === 'sfm4u_forbidden', 'admin download: non-admin refused');
$_GET = ['action' => 'sfm_store_admin_dl', 'post' => (string) $modA]; $_REQUEST = $_GET + ['_wpnonce' => wp_create_nonce('sfm_store_dl_' . $modA)];
$ok(str_starts_with($died([Sales::class, 'handleAdminDownload']), 'die:') && !$streamed, 'admin download handler: non-admin gets wp_die, nothing streamed');
$ok(!isset(Sales::rowActions([], get_post($modA))['sfm_dl']), 'module list: no Download ZIP link for non-admin');
wp_set_current_user($admin);
$r = Sales::adminRelease($modA);
$ok(is_array($r) && $r['version'] === '1.1.0' && is_file($r['path']), 'admin download: newest release (1.1.0)');
$ok(is_wp_error(Sales::adminRelease($modX)) && Sales::adminRelease($modX)->get_error_code() === 'sfm4u_no_release', 'admin download: module without release refused');
$_REQUEST['_wpnonce'] = 'bad';
$ok(str_starts_with($died([Sales::class, 'handleAdminDownload']), 'die:') && !$streamed, 'admin download handler: bad nonce refused');
$_REQUEST['_wpnonce'] = wp_create_nonce('sfm_store_dl_' . $modA);
$ok($died([Sales::class, 'handleAdminDownload']) === '' && ($streamed[0] ?? [])[0] === 'admin' && $streamed[0][2] === '1.1.0', 'admin download handler: admin + nonce streams the ZIP');
$good = file_get_contents("$dir/sfm4u-module-alpha-1.1.0.zip"); file_put_contents("$dir/sfm4u-module-alpha-1.1.0.zip", 'tampered');
$streamed = [];
$ok(Sales::adminRelease($modA) instanceof WP_Error && Sales::adminRelease($modA)->get_error_code() === 'sfm4u_release_missing', 'admin download: sha256 mismatch refused');
$ok(str_starts_with($died([Sales::class, 'handleAdminDownload']), 'die:') && !$streamed, 'admin download handler: sha256 mismatch → nothing streamed');
file_put_contents("$dir/sfm4u-module-alpha-1.1.0.zip", $good);
$ra = Sales::rowActions([], get_post($modA));
$ok(isset($ra['sfm_dl']) && strpos($ra['sfm_dl'], '_wpnonce=') !== false && strpos($ra['sfm_dl'], 'admin-post.php') !== false && strpos($ra['sfm_dl'], $dir) === false, 'module list: Download ZIP link with nonce for admin, no raw path');
ob_start(); Sales::releaseBox(get_post($modA)); $box = ob_get_clean();
$ok(strpos($box, 'Download ZIP') !== false && strpos($box, '1.1.0') !== false && strpos($box, $dir) === false, 'edit screen: release box with button, no raw path');
$_GET = []; $_REQUEST = [];

// 3. product link
$ok(Sales::wooActive(), 'WooCommerce active in test site');
$pid = Sales::linkProduct($modA, true, '');
$prod = wc_get_product($pid);
$dls = $prod->get_downloads(); $dl = reset($dls);
$ok($prod->is_virtual() && $prod->is_downloadable() && $prod->get_type() === 'simple' && (int) get_post_meta($modA, Sales::META_PRODUCT, true) === $pid, 'product created: simple, virtual, downloadable, linked');
$ok($prod->get_regular_price() === '' && $prod->get_status() === 'draft' && Sales::buyBox($modA) === null, 'no price → draft, no Buy box (price never invented)');
$ok($prod->get_download_limit() === 5 && $prod->get_download_expiry() === 365, 'download limit 5, expiry 365 days');
$ok($dl && $dl->get_file() === Sales::buyUrl('sfm4u-module-alpha') && strpos($dl->get_file(), '/wp-json/sfm4u-releases/v1/buy/') !== false && strpos($dl->get_file(), 'release-store') === false, 'file URL = our protected /buy endpoint, not the raw file');
$reg = wc_get_container()->get(Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class);
$ok($reg->is_valid_path($dl->get_file()) && !$reg->is_valid_path(content_url('sfm4u-release-store/x.zip')), 'approved download directory = only our /buy/ folder');
$ok($throws(static fn() => Sales::linkProduct($modA, true, 'abc')) !== '' && $throws(static fn() => Sales::linkProduct($modA, true, '-5')) !== '', 'bad price refused');
$ok($throws(static fn() => Sales::linkProduct($modX, true, '10')) !== '', 'module without release cannot be sold');
// save through the meta box (nonce + capability)
$_POST = ['_sfm_sell_nonce' => wp_create_nonce('sfm_store_sell_' . $modA), 'sfm_sell' => ['on' => '1', 'price' => '25', 'product' => '']];
Sales::saveSell($modA);
$prod = wc_get_product($pid);
$ok($prod->get_regular_price() === '25' && $prod->get_status() === 'publish' && (int) get_post_meta($modA, Sales::META_PRODUCT, true) === $pid, 'meta box save: price 25 → same product published');
$_POST['_sfm_sell_nonce'] = 'bad'; $_POST['sfm_sell']['price'] = '1';
Sales::saveSell($modA);
$ok(wc_get_product($pid)->get_regular_price() === '25', 'meta box save: bad nonce ignored');
wp_set_current_user($buyer); $_POST['_sfm_sell_nonce'] = wp_create_nonce('sfm_store_sell_' . $modA);
Sales::saveSell($modA);
$ok(wc_get_product($pid)->get_regular_price() === '25', 'meta box save: customer cannot change price');
wp_set_current_user($admin); $_POST = [];
$bb = Sales::buyBox($modA);
$ok(is_array($bb) && strpos($bb['url'], 'add-to-cart=' . $pid) !== false && strpos(wp_strip_all_tags($bb['price_html']), '25') !== false, 'Buy box: price + add-to-cart link');
$pidB = Sales::linkProduct($modB, true, '40');
$ok($throws(static fn() => Sales::linkProduct($modX, true, '10', $pidB)) !== '', 'product already linked to another module refused');
ob_start(); Sales::sellBox(get_post($modA)); $sb = ob_get_clean();
$ok(strpos($sb, 'name="sfm_sell[price]"') !== false && strpos($sb, 'value="25"') !== false && strpos($sb, '_sfm_sell_nonce') !== false, 'Sell this box shows price field + nonce');

// single template (theme header/footer included)
$render = static function (int $id): string {
    global $wp_query, $post;
    $wp_query = new WP_Query(['p' => $id, 'post_type' => CPT]); $GLOBALS['wp_the_query'] = $wp_query;
    ob_start(); include WP_PLUGIN_DIR . '/sfm4u-module-store/templates/single.php'; $h = ob_get_clean();
    wp_reset_postdata(); return $h;
};
$h = $render($modA);
$ok(strpos($h, 'Buy / কিনুন') !== false && strpos($h, 'add-to-cart=' . $pid) !== false && strpos($h, 'Online buying opens soon') === false, 'single page: priced product → price + Buy in payment box');
$h = $render($modX);
$ok(strpos($h, 'Buy / কিনুন') === false && strpos($h, 'Online buying opens soon') !== false && strpos($h, 'name="sfm_req"') !== false, 'single page: no product → request form as before');

// 4. order → licence
$mkOrder = static function (int $user, int $productId, string $status) {
    $o = wc_create_order(['customer_id' => $user]);
    $o->add_product(wc_get_product($productId), 1);
    $o->set_billing_email(get_userdata($user)->user_email);
    $o->calculate_totals();
    $o->save();
    $o->update_status($status);
    return wc_get_order($o->get_id());
};
$lt = Sales::table();
$pending = $mkOrder($buyer, $pid, 'pending');
$ok((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lt WHERE order_id = %d", $pending->get_id())) === 0, 'pending order → no licence');
$order = $mkOrder($buyer, $pid, 'completed');
$lic = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE order_id = %d", $order->get_id()), ARRAY_A);
$ok($lic && (int) $lic['user_id'] === $buyer && $lic['slug'] === 'sfm4u-module-alpha' && $lic['status'] === 'active' && $lic['plan'] === 'single' && $lic['site'] === '', 'completed order → licence (user, slug, active, plan)');
$ok($lic && abs(strtotime($lic['expires'] . ' UTC') - (time() + 365 * DAY_IN_SECONDS)) < 120, 'licence expires in 365 days');
$ok(Sales::orderCompleted($order->get_id()) === 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lt WHERE order_id = %d", $order->get_id())) === 1, 'licence creation is idempotent');
$perm = WC_Data_Store::load('customer-download')->get_downloads(['order_id' => $order->get_id(), 'return' => 'objects']);
$perm = reset($perm);
$ok($perm && (int) $perm->get_downloads_remaining() === 5 && $perm->get_access_expires() !== null, 'WooCommerce download permission: 5 remaining, expiry set');

// 5. buyer download through WooCommerce (real WC_Download_Handler::download_product)
$streamed = [];
$_GET = ['download_file' => (string) $pid, 'order' => $order->get_order_key(), 'email' => $order->get_billing_email(), 'key' => $perm->get_download_id()];
$e = $died([WC_Download_Handler::class, 'download_product']);
$perm2 = new WC_Customer_Download($perm->get_id());
$ok($e === '' && ($streamed[0] ?? [])[0] === 'woocommerce' && $streamed[0][1] === 'sfm4u-module-alpha' && $streamed[0][2] === '1.1.0', 'WooCommerce download → our method streams the verified newest ZIP' . ($e ? " ($e)" : ''));
$ok((int) $perm2->get_downloads_remaining() === 4, 'download counted by WooCommerce (5 → 4)');
$_GET['order'] = $pending->get_order_key(); $streamed = [];
$ok(str_starts_with($died([WC_Download_Handler::class, 'download_product']), 'die:') && !$streamed, 'unpaid order cannot download');
$_GET = [];
// signed /buy endpoint
$call = static function (string $slug, array $q) { $r = new WP_REST_Request('GET', '/' . RS::NS . '/buy/' . $slug); $r->set_query_params($q); return rest_do_request($r); };
$signed = Sales::signFilePath(Sales::buyUrl('sfm4u-module-alpha'), '', $order, wc_get_product($pid), $perm);
parse_str((string) wp_parse_url($signed, PHP_URL_QUERY), $q);
$item = array_key_first($order->get_items());
$ok((int) $q['order'] === $order->get_id() && (int) $q['item'] === $item && (int) $q['expires'] - time() <= 600 && Sales::downloadMethod('force', $pid, $signed) === 'sfm4u' && Sales::downloadMethod('force', $pid, 'https://x.test/a.zip') === 'force', 'signed per order item, 10 minutes; our method only for our URLs');
$streamed = [];
$ok($call('sfm4u-module-alpha', $q)->get_status() === 200 && ($streamed[0] ?? [])[0] === 'rest', '/buy with valid signature streams');
$ok($call('sfm4u-module-alpha', [])->get_status() === 403, '/buy without signature → 403');
$t = $q; $t['sig'] = strrev($q['sig']); $ok($call('sfm4u-module-alpha', $t)->get_status() === 403, '/buy tampered signature → 403');
$t = $q; $t['item'] = (string) ($item + 1); $ok($call('sfm4u-module-alpha', $t)->get_status() === 403, '/buy other order item → 403');
$ok(Sales::verifyBuy('sfm4u-module-alpha', $q, (int) $q['expires'] + 1)->get_error_code() === 'sfm4u_link_expired', '/buy expired link refused');
$t = ['order' => (string) $order->get_id(), 'item' => (string) $item, 'expires' => (string) (time() + 300)]; $t['sig'] = Sales::buySig('sfm4u-module-beta', $order->get_id(), $item, (int) $t['expires']);
$ok($call('sfm4u-module-beta', $t)->get_status() === 403, '/buy: order item of Alpha cannot fetch Beta');
$t = ['order' => (string) $pending->get_id(), 'item' => (string) array_key_first($pending->get_items()), 'expires' => (string) (time() + 300)]; $t['sig'] = Sales::buySig('sfm4u-module-alpha', $pending->get_id(), (int) $t['item'], (int) $t['expires']);
$ok(Sales::verifyBuy('sfm4u-module-alpha', $t)->get_error_code() === 'sfm4u_not_paid', '/buy: unpaid order refused even with a valid signature');

// 6. site key from a licence
delete_transient('sfm_store_keyrate_' . $buyer);
$key = Sales::generateKey($buyer, (int) $lic['id'], 'https://Buyer-Shop.test/');
$sites = RS::sites();
$bs = RS::siteForKey($key);
$ok(str_starts_with($key, 'sfm4u_') && $bs && $bs['host'] === 'buyer-shop.test' && (int) $bs['user_id'] === $buyer, 'site key made for buyer domain');
$ok(strpos(serialize($sites), substr($key, 6, 30)) === false && $sites[$bs['id']]['hash'] === RS::keyHash($key), 'only the HMAC of the key is stored');
$ok($wpdb->get_var($wpdb->prepare("SELECT site FROM $lt WHERE id = %d", $lic['id'])) === 'buyer-shop.test', 'licence bound to the domain');
$ok($throws(static fn() => Sales::generateKey($buyer, (int) $lic['id'], 'another.test')) !== '', 'licence already used on another domain refused');
$ok($throws(static fn() => Sales::generateKey($other, (int) $lic['id'], 'other.test')) === 'Unknown licence.', 'another user cannot use this licence');
// /check with licence key vs admin key
$check = static function (string $k, string $items, string $site = '') { $r = new WP_REST_Request('GET', '/' . RS::NS . '/check'); $r->set_query_params(['items' => $items] + ($site ? ['site' => $site] : [])); $r->set_header('X-SFM4U-Site-Key', $k); return rest_do_request($r); };
$items = 'sfm4u-module-alpha:1.0.0,sfm4u-module-beta:1.0.0,sfm4u-wholesale:0.9.0';
$res = $check($key, $items, 'buyer-shop.test'); $d = $res->get_data();
$ok($res->get_status() === 200 && array_column($d['updates'], 'slug') === ['sfm4u-module-alpha'] && $d['new'] === [], 'licence key /check: only the owned slug');
$ok(array_column($check($key, 'sfm4u-wholesale:0.9.0')->get_data()['new'], 'slug') === ['sfm4u-module-alpha'], 'licence key /check: "new" lists only owned modules');
[$aid, $akey] = RS::addSite('admin-site.test');
$d = $check($akey, $items)->get_data();
$ok(array_column($d['updates'], 'slug') === ['sfm4u-module-alpha', 'sfm4u-module-beta', 'sfm4u-wholesale'], 'Owner key /check: sees everything');
[$aid2, $akey2] = RS::addSite('buyer-shop.test'); RS::revokeSite($aid2);
$ok(true, 'Owner key may exist for the same host as a buyer key');
$okLink = RS::packageUrl('sfm4u-module-alpha', '1.1.0', 'buyer-shop.test'); parse_str((string) wp_parse_url($okLink, PHP_URL_QUERY), $lq);
$badLink = RS::packageUrl('sfm4u-module-beta', '2.0.0', 'buyer-shop.test'); parse_str((string) wp_parse_url($badLink, PHP_URL_QUERY), $bq);
$ok(is_array(RS::verifyDownload($lq)) && RS::verifyDownload($bq)->get_error_code() === 'sfm4u_site_revoked', 'signed update link: owned slug ok, not-owned slug refused for buyer host');
// rotation + rate limit
$k2 = Sales::generateKey($buyer, (int) $lic['id'], 'buyer-shop.test');
$ok(RS::siteForKey($key) === null && RS::siteForKey($k2) !== null, 'new key for same domain revokes the old key');
for ($i = 0; $i < 3; $i++) { Sales::generateKey($buyer, (int) $lic['id'], 'buyer-shop.test'); }
$e = $throws(static fn() => Sales::generateKey($buyer, (int) $lic['id'], 'buyer-shop.test'));
$ok(strpos($e, 'Too many') !== false, 'rate limit: 5 keys per hour, 6th refused');
set_transient('sfm_store_keyrate_' . $buyer, [5, time() - HOUR_IN_SECONDS - 1], 60);
$k3 = Sales::generateKey($buyer, (int) $lic['id'], 'buyer-shop.test');
$ok(RS::siteForKey($k3) !== null, 'rate limit window resets after an hour');
// My Account form: nonce, login, key shown once
delete_transient('sfm_store_keyrate_' . $buyer);
$form = static function (array $post) { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; Sales::handleKeyForm(); ob_start(); Sales::accountPage(); $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; return ob_get_clean(); };
$rp = new ReflectionProperty(Sales::class, 'keyResult');
wp_set_current_user($buyer);
$h = $form(['sfm_lic_key' => '1', 'licence' => (string) $lic['id'], 'site' => 'buyer-shop.test', '_sfm_lic_nonce' => 'bad']);
$ok(strpos($h, 'reload the page') !== false && strpos($h, 'shown only once') === false, 'My Account: bad nonce → no key');
$h = $form(['sfm_lic_key' => '1', 'licence' => (string) $lic['id'], 'site' => 'buyer-shop.test', '_sfm_lic_nonce' => wp_create_nonce('sfm_lic_key_' . $buyer)]);
preg_match('/<code[^>]*>(sfm4u_[A-Za-z0-9]{40})<\/code>/', $h, $m);
$ok(!empty($m[1]) && RS::siteForKey($m[1]) !== null && strpos($h, 'sfm4u-module-alpha') !== false, 'My Account: key shown once in the page and works');
$k3 = $m[1] ?? $k3; // the form made a new key for the same domain (the older one is revoked)
$ok(RS::siteForKey($k3) !== null, 'My Account key replaces the older key of that domain');
$rp->setValue(null, null);
ob_start(); Sales::accountPage(); $h2 = ob_get_clean();
$ok(!empty($m[1]) && strpos($h2, $m[1]) === false, 'My Account: key not shown again on the next view');
$ok(!empty($m[1]) && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like(substr($m[1], 6)) . '%')) === 0, 'plain key is not stored in any option/transient');
wp_set_current_user(0);
$h = $form(['sfm_lic_key' => '1', 'licence' => (string) $lic['id'], 'site' => 'buyer-shop.test', '_sfm_lic_nonce' => 'x']);
$ok(strpos($h, 'Please log in') !== false, 'My Account: logged out → refused');
$rp->setValue(null, null);
$ok(isset(Sales::accountMenu(['dashboard' => 'D', 'customer-logout' => 'L'])[Sales::ENDPOINT]) && array_keys(Sales::accountMenu(['dashboard' => 'D', 'customer-logout' => 'L']))[1] === Sales::ENDPOINT, 'My Account menu has "SFM4U licences" before Logout');
$ok(isset(WC()->query->get_query_vars()[Sales::ENDPOINT]), 'My Account endpoint registered with WooCommerce');
wp_set_current_user($admin);
// expiry + refund
$wpdb->update($lt, ['expires' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => (int) $lic['id']]);
$ok(Sales::ownedSlugs($buyer, 'buyer-shop.test') === [] && $check($k3, $items)->get_data()['updates'] === [], 'expired licence: key sees nothing');
$wpdb->update($lt, ['expires' => gmdate('Y-m-d H:i:s', time() + 3600)], ['id' => (int) $lic['id']]);
$order->update_status('refunded');
$ok($wpdb->get_var($wpdb->prepare("SELECT status FROM $lt WHERE id = %d", $lic['id'])) === 'revoked' && $check($k3, $items)->get_data()['updates'] === [], 'refunded order: licence revoked, key sees nothing');
$ok($throws(static fn() => Sales::generateKey($buyer, (int) $lic['id'], 'buyer-shop.test')) === 'This licence is not active.', 'revoked licence cannot make keys');
$ok(count(array_column($check($akey, $items)->get_data()['updates'], 'slug')) === 3, 'Owner key unaffected by buyer licences');

// 7. degrade without WooCommerce
$off = static fn() => false;
add_filter('sfm4u_store_woo_active', $off);
$ok(!Sales::wooActive() && Sales::linkedProduct($modA) === null && Sales::buyBox($modA) === null, 'WooCommerce off: no product, no Buy box');
$ok($throws(static fn() => Sales::linkProduct($modA, true, '5')) !== '' && Sales::orderCompleted($order->get_id()) === 0, 'WooCommerce off: no product link, no licence creation');
$ok($call('sfm4u-module-alpha', $q)->get_status() === 503, 'WooCommerce off: /buy closed (503)');
ob_start(); Sales::sellBox(get_post($modA)); $sb = ob_get_clean();
$ok(strpos($sb, 'not active') !== false && strpos($sb, 'sfm_sell') === false, 'WooCommerce off: Sell box explains, no form');
$h = $render($modA);
$ok(strpos($h, 'Online buying opens soon') !== false && strpos($h, 'name="sfm_req"') !== false && strpos($h, 'Buy / কিনুন') === false, 'WooCommerce off: single page keeps the request form');
$ok(is_array(Sales::adminRelease($modA)), 'WooCommerce off: admin download still works');
$_POST = ['_sfm_sell_nonce' => wp_create_nonce('sfm_store_sell_' . $modA), 'sfm_sell' => ['on' => '1', 'price' => '99']]; Sales::saveSell($modA); $_POST = [];
remove_filter('sfm4u_store_woo_active', $off);
$ok(wc_get_product($pid)->get_regular_price() === '25', 'WooCommerce off: meta box save does nothing');
remove_filter('sfm4u_releases_site_slugs', [Sales::class, 'siteSlugs'], 10);
$wpdb->update($lt, ['status' => 'active'], ['id' => (int) $lic['id']]);
$ok($check($k3, $items)->get_data()['updates'] === [] && count($check($akey, $items)->get_data()['updates']) === 3, 'without the Sales filter a buyer key sees nothing; Owner key still sees all');
add_filter('sfm4u_releases_site_slugs', [Sales::class, 'siteSlugs'], 10, 2);

// ---------- cleanup ----------
remove_filter('sfm4u_store_pre_stream', $stop, 10);
foreach ([$order, $pending] as $o) { $o->delete(true); }
$wpdb->query($wpdb->prepare("DELETE FROM $lt WHERE user_id IN (%d, %d)", $buyer, $other));
foreach ([$pid, $pidB] as $x) { wc_get_product($x)->delete(true); }
foreach ([$modA, $modB, $modX] as $x) { wp_delete_post($x, true); }
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($buyer); wp_delete_user($other);
delete_transient('sfm_store_keyrate_' . $buyer);
foreach (array_keys(RS::sites()) as $sid) { delete_transient('sfm_rs_rate_' . $sid); }
foreach (glob("$dir/{,.}*", GLOB_BRACE) as $x) { if (is_file($x)) { unlink($x); } } @rmdir($dir);
if ($oldSites === null) { delete_option(RS::OPT_SITES); } else { update_option(RS::OPT_SITES, $oldSites, false); }
remove_filter('sfm4u_release_store_dir', $dirFilter);
echo "pass $p fail $f\n";
