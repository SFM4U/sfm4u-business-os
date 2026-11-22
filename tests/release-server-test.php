<?php
// CL-2 release server (sites/sfm4u-module-store/src/ReleaseServer.php). Synthetic data only, no network.
// Run: wp eval-file tests/release-server-test.php   (marketplace store plugin active)
use SFM4U\ModuleStore\ReleaseServer as RS;

$p = 0; $f = 0;
$ok = function ($c, $m) use (&$p, &$f) { if ($c) { $p++; } else { $f++; echo "FAIL $m\n"; } };

// isolated release store in a temp folder
$dir = sys_get_temp_dir() . '/sfm-rs-test-' . wp_rand(1000, 9999);
wp_mkdir_p($dir);
$dirFilter = static fn() => $dir;
add_filter('sfm4u_release_store_dir', $dirFilter);
$oldSites = get_option(RS::OPT_SITES, null);
delete_option(RS::OPT_SITES);

$mkzip = static function (string $path, string $folder, string $body) {
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString($folder . '/' . $folder . '.php', $body); $z->close();
    return hash_file('sha256', $path);
};
$rel = [];
foreach ([['sfm4u-wholesale', 'A00', '0.9.23', null], ['sfm4u-wholesale', 'A00', '0.9.24', null], ['sfm4u-module-about', 'A11', '0.1.0', null], ['sfm4u-module-about', 'A11', '0.2.0', '0.9.24'], ['sfm4u-module-newthing', 'Z99', '1.0.0', null]] as [$slug, $code, $ver, $req]) {
    $sha = $mkzip("$dir/$slug-$ver.zip", $slug, "<?php\n/**\n * Plugin Name: test $slug\n * Version: $ver\n */\n");
    $rel[] = ['slug' => $slug, 'code' => $code, 'version' => $ver, 'sha256' => $sha, 'size' => filesize("$dir/$slug-$ver.zip"), 'requires_core' => $req, 'name' => "Test $slug"];
}
$rel[] = ['slug' => '../evil', 'version' => '1', 'sha256' => str_repeat('a', 64)];          // bad slug: dropped
$rel[] = ['slug' => 'sfm4u-module-bad', 'version' => '1.0', 'sha256' => 'nothex'];        // bad sha: dropped
file_put_contents("$dir/releases.json", wp_json_encode($rel));
RS::protectStore();

// 1. secret + store
$secret = RS::secret();
global $wpdb;
$ok(strlen($secret) === 64 && RS::secret() === $secret, 'secret is 64 chars and stable');
$ok($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", RS::OPT_SECRET)) !== 'yes' && $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", RS::OPT_SECRET)) !== 'on', 'secret option not autoloaded');
$ok(strpos((string) file_get_contents(dirname(__DIR__) . '/sites/sfm4u-module-store/src/ReleaseServer.php'), $secret) === false, 'secret not in code');
$ok(is_file("$dir/.htaccess") && strpos((string) file_get_contents("$dir/.htaccess"), 'Require all denied') !== false && is_file("$dir/index.php"), 'store folder denied by .htaccess + index.php');
$ok(count(RS::releases()) === 5, 'releases.json: bad entries dropped (5 of 7 kept)');
$latest = RS::latest(RS::releases());
$ok($latest['sfm4u-wholesale']['version'] === '0.9.24' && $latest['sfm4u-module-about']['version'] === '0.2.0', 'latest version per slug');

// 2. site keys
[$id, $key] = RS::addSite('https://AlgiBazar.com/');
$sites = RS::sites();
$ok(str_starts_with($key, 'sfm4u_') && strlen($key) === 46, 'key generated (sfm4u_ + 40)');
$ok($sites[$id]['host'] === 'algibazar.com' && $sites[$id]['hash'] === hash_hmac('sha256', $key, $secret) && strpos(serialize($sites), substr($key, 6, 30)) === false, 'only HMAC of key stored, host normalised');
$ok(RS::siteForKey($key)['host'] === 'algibazar.com' && RS::siteForKey($key . 'x') === null && RS::siteForKey('short') === null, 'key lookup: right key only');
$e = ''; try { RS::addSite('algibazar.com'); } catch (DomainException $x) { $e = $x->getMessage(); } $ok($e !== '', 'second active key for same host refused');
$e = ''; try { RS::addSite('not a host!'); } catch (DomainException $x) { $e = $x->getMessage(); } $ok($e !== '', 'bad host refused');

// 3. /check over REST
$call = static function (string $route, array $q, string $k = '') {
    $r = new WP_REST_Request('GET', '/' . RS::NS . $route);
    $r->set_query_params($q);
    if ($k !== '') { $r->set_header('X-SFM4U-Site-Key', $k); }
    return rest_do_request($r);
};
delete_transient('sfm_rs_rate_' . $id);
$res = $call('/check', ['site' => 'algibazar.com', 'items' => 'sfm4u-wholesale:0.9.23,sfm4u-module-about:0.2.0,sfm4u-module-gone:1.0,../x:1'], $key);
$d = $res->get_data();
$ok($res->get_status() === 200 && count($d['updates']) === 1 && $d['updates'][0]['slug'] === 'sfm4u-wholesale' && $d['updates'][0]['version'] === '0.9.24', 'check: only the newer core (about 0.2.0 is current)');
$ok(array_column($d['new'], 'slug') === ['sfm4u-module-newthing'] && $d['new'][0]['version'] === '1.0.0' && $d['new'][0]['name'] !== '', 'check: new list = modules the site lacks (core never "new")');
$u = $d['updates'][0];
$ok($u['sha256'] === $latest['sfm4u-wholesale']['sha256'] && $u['size'] > 0 && array_key_exists('requires_core', $u), 'update carries sha256, size, requires_core');
$json = wp_json_encode($d);
$ok(strpos($json, $dir) === false && strpos($json, WP_CONTENT_DIR) === false && strpos($json, 'release-store') === false, 'no raw path in the answer');
parse_str((string) wp_parse_url($u['package_url'], PHP_URL_QUERY), $q);
$ok($q['site'] === 'algibazar.com' && (int) $q['expires'] - time() <= 600 && (int) $q['expires'] - time() >= 590 && strpos($u['package_url'], '/download') !== false, 'package_url signed for this site, 10 minutes');
$res = $call('/check', ['items' => 'sfm4u-module-about:0.1.0'], $key);
$ok($res->get_data()['updates'][0]['requires_core'] === '0.9.24', 'module update passes requires_core');
$ok($call('/check', ['items' => 'sfm4u-wholesale:0.9.23'])->get_status() === 401, 'no key → 401');
$ok($call('/check', ['items' => 'sfm4u-wholesale:0.9.23'], 'sfm4u_' . str_repeat('x', 40))->get_status() === 401, 'wrong key → 401');
$ok($call('/check', ['site' => 'other.com', 'items' => 'sfm4u-wholesale:0.9.23'], $key)->get_status() === 403, 'key of another site → 403');
$ok(RS::parseItems('sfm4u-wholesale:1.0, sfm4u-module-a-b:2.0-dev ,bad,evil:1,sfm4u-module-x:../1') === ['sfm4u-wholesale' => '1.0', 'sfm4u-module-a-b' => '2.0-dev'], 'items parser drops bad items');

// 4. signed links
$v = RS::verifyDownload($q);
$ok(is_array($v) && $v['slug'] === 'sfm4u-wholesale' && $v['version'] === '0.9.24' && is_file($v['path']), 'valid signature → release');
$ok(is_wp_error(RS::verifyDownload($q, (int) $q['expires'] + 1)) && RS::verifyDownload($q, (int) $q['expires'] + 1)->get_error_code() === 'sfm4u_link_expired', 'expired link refused');
$t = $q; $t['version'] = '0.9.23';
$ok(is_wp_error(RS::verifyDownload($t)) && RS::verifyDownload($t)->get_error_code() === 'sfm4u_bad_signature', 'tampered version refused');
$t = $q; $t['site'] = 'evil.com';
$ok(RS::verifyDownload($t)->get_error_code() === 'sfm4u_bad_signature', 'tampered site refused');
$t = $q; $t['sig'] = str_repeat('0', 64);
$ok(RS::verifyDownload($t)->get_error_code() === 'sfm4u_bad_signature', 'forged signature refused');
$t = $q; $t['expires'] = (string) (time() + 86400); $t['sig'] = RS::sign($t['slug'], $t['version'], $t['site'], (int) $t['expires']);
$ok(RS::verifyDownload($t)->get_error_code() === 'sfm4u_link_expired', 'far-future expiry refused');
$t = $q; $t['slug'] = '../../wp-config'; $ok(RS::verifyDownload($t)->get_error_code() === 'sfm4u_bad_link', 'path in slug refused');
// download route (streaming stopped by the test filter)
$stop = static fn($pre, $r) => ['would_stream' => $r['slug'] . '-' . $r['version']];
add_filter('sfm4u_releases_pre_stream', $stop, 10, 2);
$res = $call('/download', $q);
$ok($res->get_status() === 200 && $res->get_data()['would_stream'] === 'sfm4u-wholesale-0.9.24', 'download route accepts a valid link');
$t = $q; $t['sig'] = strrev($q['sig']);
$ok($call('/download', $t)->get_status() === 403, 'download route refuses a tampered link (403)');
remove_filter('sfm4u_releases_pre_stream', $stop, 10);
// file changed on disk → refused
$good = file_get_contents($v['path']); file_put_contents($v['path'], 'tampered');
$ok(RS::verifyDownload($q)->get_error_code() === 'sfm4u_release_missing', 'ZIP whose sha256 no longer matches is not served');
file_put_contents($v['path'], $good);

// 5. rate limit: 60 per hour per site
delete_transient('sfm_rs_rate_' . $id);
$codes = [];
for ($i = 0; $i < 61; $i++) { $codes[] = $call('/check', ['items' => 'sfm4u-wholesale:0.9.24'], $key)->get_status(); }
$ok(count(array_filter(array_slice($codes, 0, 60), fn($c) => $c === 200)) === 60 && $codes[60] === 429, 'rate limit: 60 ok, 61st → 429');
set_transient('sfm_rs_rate_' . $id, [60, time() - HOUR_IN_SECONDS - 1], 60);
$ok($call('/check', ['items' => 'sfm4u-wholesale:0.9.24'], $key)->get_status() === 200, 'rate limit window resets after an hour');

// 6. revoke
RS::revokeSite($id);
$ok(RS::siteForKey($key) === null && $call('/check', ['items' => 'sfm4u-wholesale:0.9.23'], $key)->get_status() === 401, 'revoked key → 401');
$ok(RS::verifyDownload($q)->get_error_code() === 'sfm4u_site_revoked', 'revoked site cannot use an old link');
[$id2, $key2] = RS::addSite('algibazar.com');
$ok($key2 !== $key && RS::siteForKey($key2)['id'] === $id2, 'new key after revoke works');
$e = ''; try { RS::revokeSite('nope'); } catch (DomainException $x) { $e = 'x'; } $ok($e === 'x', 'revoke unknown site refused');

// 7. admin page needs manage_options
wp_set_current_user(0);
$died = false; add_filter('wp_die_handler', $dh = static fn() => static function () { throw new RuntimeException('die'); }, 999); // WP-CLI has its own handler
try { ob_start(); RS::page(); } catch (RuntimeException $x) { $died = $x->getMessage() === 'die'; } finally { ob_end_clean(); }
$ok($died, 'Update sites page refuses users without manage_options');
remove_filter('wp_die_handler', $dh, 999);
$ok(has_action('rest_api_init', [RS::class, 'routes']) !== false && isset(rest_get_server()->get_routes()['/' . RS::NS . '/check']), 'routes registered');

// cleanup
foreach (glob("$dir/{,.}*", GLOB_BRACE) as $x) { if (is_file($x)) { unlink($x); } } @rmdir($dir);
delete_transient('sfm_rs_rate_' . $id); delete_transient('sfm_rs_rate_' . $id2);
if ($oldSites === null) { delete_option(RS::OPT_SITES); } else { update_option(RS::OPT_SITES, $oldSites, false); }
remove_filter('sfm4u_release_store_dir', $dirFilter);
echo "pass $p fail $f\n";
