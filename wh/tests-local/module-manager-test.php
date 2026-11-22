<?php
// Module Manager + design kit (Owner 2026-11-22): list modules, add/update only SFM4U modules by ZIP, save old version, roll back.
use SFM4U\Wholesale\{ModuleManager as MM, BrandKit as BK, Modules};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1);
$tmp = sys_get_temp_dir() . '/mmtest-' . wp_rand(1000, 9999); wp_mkdir_p($tmp);
$mk = function (string $name, array $files) use ($tmp): string { $z = new ZipArchive(); $path = "$tmp/$name.zip"; $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE); foreach ($files as $n => $c) { $z->addFromString($n, $c); } $z->close(); return $path; };
$main = fn(string $ver, string $title = 'SFM4U Module — Demo') => "<?php\n/**\n * Plugin Name: $title\n * Version: $ver\n * Requires Plugins: sfm4u-wholesale\n */\ndefined('ABSPATH') || exit;\nadd_filter('sfm4u_bos_modules', static function (array \$l): array { require_once __DIR__ . '/src/Demo.php'; \$l['mmdemo'] = ['SFM4U Demo Module', 'ডেমো', 'Demo.', '', \\SfmMm\\Demo::class]; return \$l; });\n";
$cls = "<?php\nnamespace SfmMm; final class Demo implements \\SFM4U\\Wholesale\\ModuleContract { public static function dashboard(): array { return ['help' => ['demo', 'ডেমো']]; } }\n";
// ZIP checks
$good = $mk('good', ['sfm4u-module-mmdemo/sfm4u-module-mmdemo.php' => $main('1.0.0'), 'sfm4u-module-mmdemo/src/Demo.php' => $cls]);
$ok(MM::inspectZip($good) === ['sfm4u-module-mmdemo', 'SFM4U Module — Demo', '1.0.0'], 'SFM4U module ZIP accepted (folder, name, version)');
$ok($bad(fn() => MM::inspectZip($mk('other', ['hello-dolly/hello-dolly.php' => "<?php\n/* Plugin Name: Hello Dolly */"]))), 'outside plugin refused');
$ok($bad(fn() => MM::inspectZip($mk('fake', ['sfm4u-module-x1/sfm4u-module-x1.php' => "<?php\n/* Plugin Name: Evil Plugin */"]))), 'right folder but not an SFM4U Module header refused');
$ok($bad(fn() => MM::inspectZip($mk('two', ['sfm4u-module-a1/sfm4u-module-a1.php' => $main('1'), 'other/x.php' => '<?php']))), 'two folders refused');
$ok($bad(fn() => MM::inspectZip($mk('trav', ['sfm4u-module-t1/../../evil.php' => '<?php']))), 'unsafe path refused');
file_put_contents("$tmp/notzip.zip", 'hello'); $ok($bad(fn() => MM::inspectZip("$tmp/notzip.zip")), 'not a ZIP refused');
// install, update (backup), roll back
$dir = WP_PLUGIN_DIR . '/sfm4u-module-mmdemo';
if (is_dir($dir)) { deactivate_plugins('sfm4u-module-mmdemo/sfm4u-module-mmdemo.php'); exec('rm -rf ' . escapeshellarg($dir)); }
foreach (MM::backups('sfm4u-module-mmdemo') as $b) { @unlink($b); }
$msg = MM::install($good);
$ok(is_file("$dir/src/Demo.php") && strpos($msg, 'added') !== false && is_plugin_active('sfm4u-module-mmdemo/sfm4u-module-mmdemo.php'), 'module added and switched on');
$ok(MM::currentVersion('sfm4u-module-mmdemo') === '1.0.0', 'version 1.0.0 installed');
$v2 = $mk('good2', ['sfm4u-module-mmdemo/sfm4u-module-mmdemo.php' => $main('1.1.0'), 'sfm4u-module-mmdemo/src/Demo.php' => $cls]);
$msg = MM::install($v2);
$ok(MM::currentVersion('sfm4u-module-mmdemo') === '1.1.0' && strpos($msg, 'updated') !== false, 'updated to 1.1.0');
$ok(count(MM::backups('sfm4u-module-mmdemo')) === 1, 'old version saved before update');
$ok(is_file(MM::backupDir() . '/.htaccess') && is_file(MM::backupDir() . '/index.php'), 'backup folder closed to the web');
$ver = MM::rollback('sfm4u-module-mmdemo');
$ok($ver === '1.0.0' && MM::currentVersion('sfm4u-module-mmdemo') === '1.0.0', 'rolled back to 1.0.0');
$ok(count(MM::backups('sfm4u-module-mmdemo')) === 1, 'the replaced 1.1.0 was saved too (nothing lost)');
$ok($bad(fn() => MM::rollback('../etc')) && $bad(fn() => MM::rollback('sfm4u-module-none')), 'roll back refuses unknown names / no saved version');
// list shows built-in systems and the add-on (load it like WordPress would on the next request)
include_once "$dir/sfm4u-module-mmdemo.php";
$rows = array_column(MM::list(), null, 'slug');
$ok(($rows['wholesale']['source'] ?? '') === 'Built in' && ($rows['pos']['version'] ?? '') === SFM4U_WH_VERSION, 'built-in systems listed with the software version');
$ok(($rows['mmdemo']['source'] ?? '') === 'Add-on' && ($rows['mmdemo']['version'] ?? '') === '1.0.0', 'add-on module listed with its own version');
$_GET = ['page' => MM::SLUG]; ob_start(); MM::page(); $h = ob_get_clean(); $_GET = [];
$ok(strpos($h, 'Module Manager') !== false && strpos($h, 'Upload module ZIP') !== false && strpos($h, 'Roll back') !== false && strpos($h, 'SFM4U Demo Module') !== false, 'Module Manager page: list, upload, roll back');
// design kit only on our pages
$ok(BK::isOurs('sfm-bos') && BK::isOurs('sfmwh-rules') && BK::isOurs('sfm4u-counter') && !BK::isOurs('wc-orders') && !BK::isOurs('dokan') && !BK::isOurs(''), 'design kit only on SFM4U pages');
$_GET = ['page' => 'sfm-bos']; $ok(strpos(BK::bodyClass('x'), 'sfm4u-ui') !== false, 'our page gets the SFM4U look'); ob_start(); BK::bar(); $bar = ob_get_clean();
$ok(strpos($bar, 'sfm4u-wordmark.png') !== false && strpos($bar, 'sfm4u-logo-128.png') === false && strpos($bar, 'Module Manager') !== false, 'brand bar with logo and links');
$_GET = ['page' => 'wc-orders']; $ok(BK::bodyClass('x') === 'x', 'WooCommerce page unchanged'); $_GET = [];
$ok(is_file(WP_PLUGIN_DIR . '/sfm4u-wholesale/assets/sfm4u-ui.css') && is_file(WP_PLUGIN_DIR . '/sfm4u-wholesale/assets/brand/sfm4u-logo-128.png'), 'design kit files shipped');
// clean up
deactivate_plugins('sfm4u-module-mmdemo/sfm4u-module-mmdemo.php'); exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($tmp)); foreach (MM::backups('sfm4u-module-mmdemo') as $b) { @unlink($b); }
echo "pass $p fail $f\n";
