<?php
// Module dashboards (Owner 2026-11-22): each system opens its own home page (numbers, pages, settings, access, help); new modules plug in with ModuleContract.
use SFM4U\Wholesale\{ModuleHome as MH, ModuleContract, Modules, ControlCenter as CC};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}};
wp_set_current_user(1);
// a sample module written the way Codex will write one
if (!class_exists('SfmDemoModule')) {
    eval('final class SfmDemoModule implements \SFM4U\Wholesale\ModuleContract { public static function dashboard(): array { return [
        "numbers" => [["Open tickets", "3", admin_url("admin.php?page=x")]],
        "pages" => [["Tickets", "sfm-demo-tickets"]],
        "settings" => [["enabled_bn", "Bangla on", "checkbox", true], ["max_days", "Days to answer", "number", 3], ["mode", "Mode", "select", "auto", ["auto" => "Auto", "manual" => "Manual"]], ["note", "Note", "text", ""]],
        "help" => ["Demo help", "ডেমো সাহায্য"]]; } }');
}
$filter = function ($l) { $l['demo'] = ['SFM4U Demo', 'SFM4U ডেমো', 'A demo module.', 'sfm-demo-tickets', 'SfmDemoModule']; $l['wholesale'] = ['HACK', 'x', 'y', 'z']; $l['Bad Slug!'] = ['a', 'b', 'c', 'd']; return $l; };
add_filter('sfm4u_bos_modules', $filter);
$all = Modules::all();
$ok(isset($all['demo']) && $all['demo'][4] === 'SfmDemoModule', 'new module joins the systems list through the filter');
$ok($all['wholesale'][0] === 'SFM4U Wholesale System' && !isset($all['Bad Slug!']), 'a module cannot replace an existing system or use a bad name');
$d = MH::dashboard('demo');
$ok($d['numbers'][0][1] === '3' && $d['pages'][0][1] === 'sfm-demo-tickets' && $d['help'][1] === 'ডেমো সাহায্য', 'module dashboard read from the module');
$ok(MH::setting('demo', 'max_days') === 3 && MH::setting('demo', 'mode') === 'auto', 'settings default from the module');
$c = MH::clean($d['settings'], ['max_days' => '5', 'mode' => 'manual', 'note' => '<b>hi</b>']);
$ok($c === ['enabled_bn' => false, 'max_days' => 5, 'mode' => 'manual', 'note' => 'hi'], 'settings cleaned (checkbox off, number, select, text without HTML)');
$ok($bad(fn() => MH::clean($d['settings'], ['max_days' => 'abc', 'mode' => 'auto'])) && $bad(fn() => MH::clean($d['settings'], ['mode' => 'evil'])), 'wrong number or select value refused');
update_option('sfm_mod_demo', $c); $ok(MH::setting('demo', 'max_days') === 5, 'saved setting read back');
delete_option('sfm_mod_demo');
// built-in dashboards for existing systems
foreach (['wholesale', 'retail', 'purchase', 'membership', 'pos', 'receipt', 'erp', 'delivery', 'stocklink'] as $s) {
    $b = MH::dashboard($s);
    $ok(!empty($b['pages']) && !empty($b['help'][0]) && !empty($b['help'][1]), "$s has its own pages and help (EN+BN)");
}
$r = MH::dashboard('retail');
$ok(is_numeric($r['numbers'][0][1]) && $r['pages'][0][1] === 'sfm-product-sheet', 'retail shows numbers and the product sheet');
$ok(MH::dashboard('nope') === [], 'unknown module has no dashboard');
// a broken module does not break the page
eval('final class SfmBrokenModule implements \SFM4U\Wholesale\ModuleContract { public static function dashboard(): array { throw new \RuntimeException("db down"); } }');
add_filter('sfm4u_bos_modules', fn($l) => $l + ['broken' => ['Broken', 'ভাঙা', 'x', '', 'SfmBrokenModule']]);
$ok((MH::dashboard('broken')['error'] ?? '') === 'db down', 'a broken module shows an error instead of crashing');
// pages render
foreach (['overview', 'settings', 'access', 'help'] as $tab) {
    $_GET = ['page' => MH::SLUG, 'm' => 'demo', 'tab' => $tab]; ob_start(); MH::page(); $h = ob_get_clean();
    $want = ['overview' => 'Open tickets', 'settings' => 'Days to answer', 'access' => 'Nobody else', 'help' => 'ডেমো সাহায্য'][$tab];
    $ok(strpos($h, 'SFM4U Demo') !== false && strpos($h, $want) !== false && strpos($h, 'nav-tab-active') !== false, "demo module page, tab $tab");
}
$_GET = ['page' => MH::SLUG, 'm' => 'pos']; ob_start(); MH::page(); $h = ob_get_clean();
$ok(strpos($h, 'POS counter') !== false && strpos($h, 'POS database') !== false, 'POS module home shows its pages and database status');
$_GET = ['page' => MH::SLUG, 'm' => 'broken']; ob_start(); MH::page(); $h = ob_get_clean();
$ok(strpos($h, 'could not load') !== false, 'broken module page shows the error');
$_GET = ['page' => MH::SLUG, 'm' => '<script>']; ob_start(); MH::page(); $h = ob_get_clean();
$ok(strpos($h, 'Module not found') !== false && strpos($h, '<script>') === false, 'unknown module: safe message');
$_GET = [];
// Systems and Dashboard link to module dashboards
ob_start(); Modules::page(); $sys = ob_get_clean();
$ok(strpos($sys, 'Open dashboard') !== false && strpos($sys, 'm=demo') !== false, 'Systems cards open each module dashboard');
$GLOBALS['menu'] = []; $GLOBALS['submenu'] = [];
ob_start(); CC::page(); $cc = ob_get_clean();
$ok(strpos($cc, 'each with its own dashboard') !== false && strpos($cc, 'm=purchase') !== false, 'main Dashboard lists every system with a link to its dashboard');
// hidden from the left menu
$GLOBALS['submenu'] = [Modules::SLUG => [['Systems', 'manage_options', Modules::SYSTEMS], ['Module', 'manage_options', MH::SLUG]]];
MH::hideFromMenu();
$ok(count($GLOBALS['submenu'][Modules::SLUG]) === 1, 'module page not shown as its own left-menu item');
remove_all_filters('sfm4u_bos_modules');
echo "pass $p fail $f\n";
