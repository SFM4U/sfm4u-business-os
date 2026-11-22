<?php
// CL-2 update client (src/UpdateClient.php + ModuleManager core rules). Synthetic data, HTTP mocked with pre_http_request.
// Run: wp eval-file wh/sfm4u-wholesale/tests/update-client-test.php   (sfm4u-wholesale active, WooCommerce active)
use SFM4U\Wholesale\UpdateClient as UC;
use SFM4U\Wholesale\ModuleManager as MM;

$p = 0; $f = 0;
$ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL $m\n"; } };
$err = static function (callable $fn): string { try { $fn(); return ''; } catch (DomainException $e) { return $e->getMessage(); } };

global $wpdb;
$autoload = static fn(string $o) => (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $o));
$saved = [UC::OPT_URL => get_option(UC::OPT_URL, null), UC::OPT_KEY => get_option(UC::OPT_KEY, null), UC::OPT_STATE => get_option(UC::OPT_STATE, null)];
foreach (array_keys($saved) as $o) { delete_option($o); }
wp_set_current_user(1);

$tmpDir = sys_get_temp_dir() . '/sfm-uc-test-' . wp_rand(1000, 9999);
wp_mkdir_p($tmpDir);
$zip = static function (string $folder, string $pluginName, string $ver, string $name = '') use ($tmpDir): string {
    $path = $tmpDir . '/' . ($name !== '' ? $name : $folder . '-' . $ver) . '.zip';
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString($folder . '/' . $folder . '.php', "<?php\n/**\n * Plugin Name: $pluginName\n * Version: $ver\n */\ndefined('ABSPATH') || exit;\n");
    $z->close();
    return $path;
};
$base = 'https://marketplace.sfm4u.com';
$pkg = static fn(string $slug, string $ver) => $base . '/wp-json/sfm4u-releases/v1/download?slug=' . $slug . '&version=' . $ver . '&site=example.org&expires=' . (time() + 600) . '&sig=' . str_repeat('a', 64);

// ---------- mock marketplace ----------
$mock = ['answer' => static fn(array $items) => ['updates' => [], 'new' => []], 'status' => 200, 'zips' => [], 'calls' => []];
$http = static function ($pre, $args, $url) use (&$mock, $base) {
    if (strpos($url, $base . '/wp-json/sfm4u-releases/v1/') !== 0) { return $pre; }
    $mock['calls'][] = ['url' => $url, 'args' => $args];
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
    if (strpos($url, '/check') !== false) {
        $items = [];
        foreach (array_filter(explode(',', (string) ($q['items'] ?? ''))) as $it) { [$s, $v] = explode(':', $it, 2); $items[$s] = $v; }
        $body = $mock['status'] === 200 ? ($mock['answer'])($items) : ['code' => 'x', 'message' => 'Unknown or revoked site key.'];
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $mock['status'], 'message' => 'X'], 'cookies' => [], 'filename' => null];
    }
    $file = $mock['zips'][($q['slug'] ?? '') . '-' . ($q['version'] ?? '')] ?? '';
    if ($file === '' || empty($args['filename'])) { return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => [], 'filename' => null]; }
    copy($file, $args['filename']);
    return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $args['filename']];
};
add_filter('pre_http_request', $http, 10, 3);

// ---------- 1. settings ----------
$ok(UC::url() === $base, 'default marketplace URL');
$ok($err(fn() => UC::saveSettings('http://marketplace.sfm4u.com', null)) !== '' && UC::cleanUrl('https://u:p@x.com') === '' && UC::cleanUrl('https://Market.example.com/') === 'https://market.example.com', 'only clean https marketplace URLs');
$ok($err(fn() => UC::saveSettings('', 'bad key!')) !== '', 'malformed key refused');
$key = 'sfm4u_' . str_repeat('K', 34) . 'Zq9x';
UC::saveSettings($base, $key);
$ok(UC::key() === $key && !in_array($autoload(UC::OPT_KEY), ['yes', 'on', 'auto-on'], true), 'key saved with autoload off');
UC::saveSettings($base, '');
$ok(UC::key() === $key, 'empty key field keeps the saved key');
ob_start(); UC::render(); $html = (string) ob_get_clean();
$ok(strpos($html, $key) === false && strpos($html, 'Zq9x') === false && strpos($html, 'saved, hidden') !== false, 'key never printed back (masked)');
$ok(strpos($html, 'Core update') !== false && strpos($html, 'Module updates') !== false && strpos($html, 'New modules') !== false && strpos($html, 'Check now') !== false, 'page shows three lists + Check now');

// ---------- 2. three lists (pure) ----------
$inst = ['sfm4u-wholesale' => '0.9.24-dev', 'sfm4u-module-about' => '0.1.0', 'sfm4u-module-roles' => '0.1.0', 'sfm4u-module-forms' => '0.1.0-dev'];
$sha = str_repeat('ab', 32);
$answer = ['updates' => [
    ['slug' => 'sfm4u-wholesale', 'version' => '0.9.25', 'sha256' => $sha, 'size' => 10, 'requires_core' => null, 'package_url' => $pkg('sfm4u-wholesale', '0.9.25')],
    ['slug' => 'sfm4u-module-about', 'version' => '0.2.0', 'sha256' => $sha, 'size' => 10, 'requires_core' => '0.9.23', 'package_url' => $pkg('sfm4u-module-about', '0.2.0')],
    ['slug' => 'sfm4u-module-roles', 'version' => '0.0.9', 'sha256' => $sha, 'package_url' => $pkg('sfm4u-module-roles', '0.0.9')],                      // older: dropped
    ['slug' => 'sfm4u-module-notinstalled', 'version' => '1.0', 'sha256' => $sha, 'package_url' => $pkg('sfm4u-module-notinstalled', '1.0')],        // not installed: dropped
    ['slug' => 'sfm4u-module-forms', 'version' => '0.2.0', 'sha256' => $sha, 'package_url' => 'https://evil.example.com/x.zip'],                       // other host: dropped
    ['slug' => 'sfm4u-module-forms', 'version' => '0.2.0', 'sha256' => 'nothex', 'package_url' => $pkg('sfm4u-module-forms', '0.2.0')],             // bad sha: dropped
    ['slug' => 'sfm4u-module-roles', 'version' => '0.5.0', 'sha256' => $sha, 'requires_core' => '9.0.0', 'package_url' => $pkg('sfm4u-module-roles', '0.5.0')], // needs newer core
], 'new' => [
    ['slug' => 'sfm4u-module-newthing', 'code' => 'Z99', 'name' => 'New thing', 'version' => '1.0.0'],
    ['slug' => 'sfm4u-module-about', 'name' => 'already here', 'version' => '9'],                                                                         // installed: dropped
    ['slug' => 'sfm4u-wholesale', 'name' => 'core', 'version' => '9'],                                                                                    // core is never "new"
]];
$l = UC::lists($answer, $inst, $base);
$ok(count($l['core']) === 1 && $l['core'][0]['version'] === '0.9.25' && $l['core'][0]['installed'] === '0.9.24-dev', 'Core update list');
$ok(array_column($l['modules'], 'slug') === ['sfm4u-module-about', 'sfm4u-module-roles'] && $l['modules'][0]['blocked'] === '', 'Module updates list: only newer, installed, same host, valid sha');
$ok($l['modules'][1]['blocked'] !== '' && strpos($l['modules'][1]['blocked'], '9.0.0') !== false, 'module needing a newer core is blocked');
$ok(array_column($l['new'], 'slug') === ['sfm4u-module-newthing'] && $l['new'][0]['code'] === 'Z99', 'New modules list');

// ---------- 3. check over (mocked) HTTP ----------
$ins = UC::installed();
$ok(isset($ins['sfm4u-wholesale'], $ins['sfm4u-module-about']) && $ins['sfm4u-wholesale'] === SFM4U_WH_VERSION && !array_filter(array_keys($ins), fn($s) => $s !== 'sfm4u-wholesale' && !str_starts_with($s, 'sfm4u-module-')), 'installed(): core + sfm4u-module-* only (no WooCommerce etc.)');
$mock['answer'] = static fn(array $items) => $answer;
$mock['calls'] = [];
$s = UC::check();
$c = $mock['calls'][0];
parse_str((string) wp_parse_url($c['url'], PHP_URL_QUERY), $cq);
$ok($c['args']['headers']['X-SFM4U-Site-Key'] === $key && (int) $c['args']['timeout'] === 15 && $c['args']['sslverify'] === true, 'check sends key header, timeout 15, sslverify true');
$ok(strpos($c['url'], $key) === false && strpos($cq['items'], 'sfm4u-wholesale:' . SFM4U_WH_VERSION) !== false && strpos($cq['items'], 'sfm4u-module-about:') !== false && $cq['site'] !== '', 'items = core + modules; key not in URL');
$ok($s['error'] === '' && count($s['core']) === 1 && count($s['new']) === 1 && UC::state()['checked'] > 0, 'state saved from the check');
$ok(!in_array($autoload(UC::OPT_STATE), ['yes', 'on', 'auto-on'], true), 'state option autoload off');
$mock['status'] = 401;
$s = UC::check();
$ok(strpos($s['error'], 'revoked') !== false && count($s['core']) === 1, 'server refusal: clear message, last lists kept');
$mock['status'] = 200;
delete_option(UC::OPT_KEY);
$ok(strpos(UC::check()['error'], 'key') !== false, 'no key: clear message, no call');
UC::saveSettings($base, $key);

// ---------- 4. inspectZip rules (core allowed only for sfm4u-wholesale) ----------
$coreZip = $zip('sfm4u-wholesale', 'SFM4U Business Software — Wholesale, Retail, POS & Membership', '0.9.99');
$ok($err(fn() => MM::inspectZip($coreZip)) !== '', 'core ZIP refused by the normal module upload');
$ok(MM::inspectZip($coreZip, true)[0] === 'sfm4u-wholesale', 'core ZIP accepted for a marketplace core update');
$ok($err(fn() => MM::inspectZip($zip('sfm4u-wholesale', 'Some Other Plugin', '1.0', 'fakecore'), true)) !== '', 'core folder with a foreign Plugin Name refused');
$ok($err(fn() => MM::inspectZip($zip('evil-plugin', 'SFM4U Module evil', '1.0'), true)) !== '', 'non-SFM4U folder refused even with allowCore');

// ---------- 5. install flow (real Plugin_Upgrader into wp-content/plugins) ----------
$slug = 'sfm4u-module-uctest';
$cleanup = static function () use ($slug) {
    foreach ([$slug, 'sfm4u-module-uctnew'] as $s) {
        if (is_plugin_active("$s/$s.php")) { deactivate_plugins("$s/$s.php", true); }
        if (is_dir(WP_PLUGIN_DIR . "/$s")) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_PLUGIN_DIR . "/$s", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $x) { $x->isDir() ? rmdir($x->getPathname()) : unlink($x->getPathname()); } rmdir(WP_PLUGIN_DIR . "/$s"); }
        foreach (MM::backups($s) as $b) { unlink($b); }
    }
};
$cleanup();
require_once ABSPATH . 'wp-admin/includes/plugin.php';
MM::install($zip($slug, 'SFM4U Module — UC test', '0.1.0'));
$ok(MM::currentVersion($slug) === '0.1.0', 'test module 0.1.0 installed');
$item = static function (string $ver, string $file, ?string $shaOverride = null) use ($slug, $pkg, &$mock) {
    $mock['zips'][$slug . '-' . $ver] = $file;
    return ['slug' => $slug, 'installed' => '0.1.0', 'version' => $ver, 'sha256' => $shaOverride ?? hash_file('sha256', $file), 'package_url' => $pkg($slug, $ver), 'blocked' => ''];
};
$z2 = $zip($slug, 'SFM4U Module — UC test', '0.2.0');
$tmpBefore = count(glob(get_temp_dir() . '*.tmp') ?: []);
$e = $err(fn() => UC::installItem($item('0.2.0', $z2, str_repeat('0', 64))));
$ok(strpos($e, 'checksum') !== false && MM::currentVersion($slug) === '0.1.0', 'sha256 mismatch: refused, nothing installed');
$ok(count(glob(get_temp_dir() . '*.tmp') ?: []) <= $tmpBefore, 'refused download is deleted');
$e = $err(fn() => UC::installItem($item('0.2.0', $zip('evil-plugin', 'SFM4U Module x', '0.2.0', 'evil'))));
$ok($e !== '' && MM::currentVersion($slug) === '0.1.0' && !is_dir(WP_PLUGIN_DIR . '/evil-plugin'), 'inspectZip rejection (foreign folder) even with the right sha');
$e = $err(fn() => UC::installItem($item('0.2.0', $zip('sfm4u-module-other', 'SFM4U Module other', '0.2.0', 'other'))));
$ok(strpos($e, 'not "' . $slug . '"') !== false && !is_dir(WP_PLUGIN_DIR . '/sfm4u-module-other'), 'package for another module refused');
$e = $err(fn() => UC::installItem($item('0.2.0', $coreZip)));
$ok($e !== '' && MM::currentVersion($slug) === '0.1.0', 'core ZIP under a module slug refused');
$e = $err(fn() => UC::installItem($item('0.2.0', $zip($slug, 'SFM4U Module — UC test', '0.2.5', 'wrongver'))));
$ok(strpos($e, 'expected 0.2.0') !== false, 'package with the wrong version refused');
$msg = UC::installItem($item('0.2.0', $z2));
$ok(MM::currentVersion($slug) === '0.2.0' && strpos($msg, 'updated') !== false && count(MM::backups($slug)) === 1, 'good package: installed 0.2.0, old version saved');
// failing install → automatic roll back
$fail = static fn() => new WP_Error('boom', 'disk full (test)');
add_filter('upgrader_pre_install', $fail);
$e = $err(fn() => UC::installItem($item('0.2.1', $zip($slug, 'SFM4U Module — UC test', '0.2.1'))));
remove_filter('upgrader_pre_install', $fail);
$ok(strpos($e, 'put back') !== false && MM::currentVersion($slug) === '0.2.0', 'failed install: old version put back (roll back)');
// full click flow: Install button → fresh check → download → install
$z3 = $zip($slug, 'SFM4U Module — UC test', '0.3.0');
$mock['zips'][$slug . '-0.3.0'] = $z3;
$mock['answer'] = static function (array $items) use ($slug, $z3, $pkg, $zip, &$mock) {
    $u = [];
    if (isset($items[$slug]) && version_compare('0.3.0', $items[$slug], '>')) { $u[] = ['slug' => $slug, 'version' => '0.3.0', 'sha256' => hash_file('sha256', $z3), 'size' => filesize($z3), 'requires_core' => null, 'package_url' => $pkg($slug, '0.3.0')]; }
    if (isset($items['sfm4u-module-uctnew'])) { $n = $mock['zips']['sfm4u-module-uctnew-1.0.0']; $u[] = ['slug' => 'sfm4u-module-uctnew', 'version' => '1.0.0', 'sha256' => hash_file('sha256', $n), 'size' => 1, 'requires_core' => null, 'package_url' => $pkg('sfm4u-module-uctnew', '1.0.0')]; }
    return ['updates' => $u, 'new' => isset($items['sfm4u-module-uctnew']) ? [] : [['slug' => 'sfm4u-module-uctnew', 'code' => 'T01', 'name' => 'UC new', 'version' => '1.0.0']]];
};
$mock['zips']['sfm4u-module-uctnew-1.0.0'] = $zip('sfm4u-module-uctnew', 'SFM4U Module — UC new', '1.0.0');
$s = UC::check();
$ok(array_column($s['modules'], 'slug') === [$slug] && array_column($s['new'], 'slug') === ['sfm4u-module-uctnew'], 'check: module update + new module listed separately');
UC::installSlug($slug);
$ok(MM::currentVersion($slug) === '0.3.0', 'Install button flow: 0.3.0 installed');
UC::installSlug('sfm4u-module-uctnew');
$ok(MM::currentVersion('sfm4u-module-uctnew') === '1.0.0' && is_plugin_active('sfm4u-module-uctnew/sfm4u-module-uctnew.php'), 'New module installed and switched on');
$ok(UC::check()['modules'] === [] && UC::state()['new'] === [], 'after installing, lists are empty');
$ok($err(fn() => UC::installSlug('../../evil')) === 'Unknown item.', 'bad slug refused');

// ---------- 6. WordPress native updates ----------
if (!function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$coreMain = dirname(__DIR__) . '/sfm4u-wholesale.php';
$hd = get_plugin_data($coreMain, false, false);
$ok(($hd['UpdateURI'] ?? '') === 'https://marketplace.sfm4u.com' && $hd['Version'] === '0.9.24-dev' && SFM4U_WH_VERSION === '0.9.24-dev', 'core header: Update URI + version 0.9.24-dev');
update_option(UC::OPT_STATE, ['core' => [['slug' => 'sfm4u-wholesale', 'installed' => '0.9.24-dev', 'version' => '0.9.25', 'sha256' => $sha, 'package_url' => $pkg('sfm4u-wholesale', '0.9.25'), 'blocked' => '']], 'modules' => [], 'new' => [], 'checked' => time(), 'error' => ''], false);
$coreFile = UC::coreFolder() . '/sfm4u-wholesale.php';
$n = apply_filters('update_plugins_marketplace.sfm4u.com', false, $hd, $coreFile, []);
$ok(UC::coreFolder() === 'sfm4u-wholesale' ? (is_array($n) && $n['version'] === '0.9.25' && strpos($n['package'], '/download') !== false) : $n === false, 'native filter gives Dashboard → Updates the core update');
$ok(apply_filters('update_plugins_marketplace.sfm4u.com', false, [], 'sfm4u-module-none/sfm4u-module-none.php', []) === false, 'native filter: nothing for unknown plugins');
$ok(UC::preDownload(false, 'https://downloads.wordpress.org/x.zip', null, []) === false, 'pre-download leaves other packages alone');
// native upgrade of the test module: fresh link + sha256 + rules, old version saved first
$z4 = $zip($slug, 'SFM4U Module — UC test', '0.4.0');
$mock['zips'][$slug . '-0.4.0'] = $z4;
$mock['answer'] = static fn(array $items) => ['updates' => [['slug' => $slug, 'version' => '0.4.0', 'sha256' => hash_file('sha256', $z4), 'size' => 1, 'requires_core' => null, 'package_url' => $pkg($slug, '0.4.0')]], 'new' => []];
$before = count(MM::backups($slug));
$t = UC::preDownload(false, $pkg($slug, '0.4.0'), null, ['plugin' => "$slug/$slug.php"]);
$ok(is_string($t) && is_file($t) && hash_file('sha256', $t) === hash_file('sha256', $z4) && count(MM::backups($slug)) === $before + 1, 'pre-download: verified file handed to WordPress, old version saved');
if (is_string($t)) { @unlink($t); }
$mock['answer'] = static fn(array $items) => ['updates' => [['slug' => $slug, 'version' => '0.4.0', 'sha256' => str_repeat('0', 64), 'size' => 1, 'requires_core' => null, 'package_url' => $pkg($slug, '0.4.0')]], 'new' => []];
$t = UC::preDownload(false, $pkg($slug, '0.4.0'), null, []);
$ok(is_wp_error($t) && strpos($t->get_error_message(), 'checksum') !== false, 'pre-download: sha256 mismatch stops the native update');

// ---------- 7. cron + permissions ----------
$ok((bool) wp_next_scheduled(UC::CRON) && wp_get_schedule(UC::CRON) === 'twicedaily', 'check scheduled every 12 h');
wp_set_current_user(0);
$_GET['page'] = MM::SLUG; $_POST['sfm_uc'] = 'check'; $mock['calls'] = [];
UC::actions();
$ok($mock['calls'] === [], 'no action for users without manage_options');
unset($_GET['page'], $_POST['sfm_uc']);
ob_start(); UC::render(); $ok(ob_get_clean() === '', 'update box hidden from users without manage_options');

// cleanup
wp_set_current_user(1);
$cleanup();
remove_filter('pre_http_request', $http, 10);
foreach (glob("$tmpDir/*") as $x) { unlink($x); } rmdir($tmpDir);
foreach ($saved as $o => $v) { delete_option($o); if ($v !== null) { add_option($o, $v, '', false); } }
echo "pass $p fail $f\n";
