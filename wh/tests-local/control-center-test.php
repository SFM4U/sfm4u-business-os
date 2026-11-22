<?php
// Control Center (Owner 2026-11-22): Business Software opens one dashboard with every marketplace page grouped; Dokan ads hidden.
use SFM4U\Wholesale\{ControlCenter as CC, Modules};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
wp_set_current_user(1);
// titles and groups
$ok(CC::cleanTitle('Vendors <span class="awaiting-mod">5</span>') === ['Vendors', '5'], 'badge number taken out of the title');
$ok(CC::isPromo('Black Friday 60% OFF') && CC::isPromo('Pro Features') && CC::isPromo('Chat with us') && !CC::isPromo('Withdraw'), 'Dokan ads recognised');
$exp = ['Withdraw' => 'money', 'Reverse Withdrawal' => 'money', 'Vendors' => 'market', 'Modules' => 'market', 'Store location' => 'market',
    'POS counter' => 'sales', 'POS register' => 'sales', 'Daily Close' => 'sales', 'Receipt System (auto print)' => 'sales',
    'Wholesale rules' => 'wholesale', 'B2B Hold Review' => 'wholesale', 'SFM4U member price' => 'wholesale', 'Supplier Payables' => 'buying', 'Purchases & cost' => 'buying',
    'Barcode labels' => 'products', 'Stock counts' => 'products', 'Add product / পণ্য' => 'products', 'Card scan & khata' => 'members', 'Due list' => 'members',
    'Customers' => 'members', 'Coupons' => 'money', 'Reports' => 'money', 'ERP Link (ERPNext)' => 'links', 'Settings' => 'settings', 'Help' => 'settings'];
$bad = [];
foreach ($exp as $t => $g) { if (CC::classify($t, $t === 'Help' ? 'dokan' : '') !== $g && !($t === 'Help' && CC::classify($t, 'dokan') === 'market')) { $bad[] = "$t=" . CC::classify($t); } }
$ok(!$bad, 'each page goes to the right group: ' . implode(', ', $bad));
$ok(CC::classify('Help', 'dokan') === 'market' && CC::classify('Tools', 'woocommerce') === 'settings', 'unknown Dokan page stays with marketplace, other unknown pages go to settings');
// collect from a fake admin menu
$menu = [[ 'Dokan', 'manage_woocommerce', 'dokan' ], ['WooCommerce', 'edit_others_shop_orders', 'woocommerce'], ['POS counter', 'manage_woocommerce', 'sfm4u-counter'],
    ['Store location', 'manage_options', 'algibazar-store-location'], ['Posts', 'edit_posts', 'edit.php'], ['SFM4U Store', 'read', 'sfm-wr-scan']];
$sub = [
    'dokan' => [['Dashboard', 'manage_woocommerce', 'dokan#/'], ['Withdraw', 'manage_woocommerce', 'dokan#/withdraw'], ['Vendors <span>5</span>', 'manage_woocommerce', 'dokan#/vendors'],
        ['Pro Features', 'manage_options', 'dokan#/premium'], ['Black Friday <span>60% OFF</span>', 'manage_options', 'dokan-bf'], ['Secret', 'do_not_have', 'dokan#/secret']],
    'woocommerce' => [['Orders', 'edit_shop_orders', 'wc-orders'], ['Supplier Payables', 'manage_woocommerce', 'sfm4u-supplier-payables']],
    'sfm-wr-scan' => [['Card scan & khata', 'read', 'sfm-wr-scan'], ['Due list', 'read', 'sfm-wr-due']],
    Modules::SLUG => [['Dashboard / ড্যাশবোর্ড', 'manage_options', Modules::SLUG], ['Systems', 'manage_options', Modules::SYSTEMS], ['Purchases & cost', 'edit_products', 'sfm-purchases']],
];
$g = CC::collect($menu, $sub, fn($cap) => $cap !== 'do_not_have');
$all = array_merge(...array_values($g)); $titles = array_column($all, 'title'); $urls = array_column($all, 'url');
$ok(in_array('Withdraw', array_column($g['money'], 'title'), true) && in_array('Vendors', array_column($g['market'], 'title'), true), 'Dokan withdraw (money) and vendors (marketplace) shown');
$v = array_values(array_filter($g['market'], fn($l) => $l['title'] === 'Vendors'))[0];
$ok($v['badge'] === '5' && $v['url'] === admin_url('admin.php?page=dokan#/vendors'), 'vendors keeps its "5 waiting" badge and the Dokan link');
$ok(!in_array('Pro Features', $titles, true) && !in_array('Black Friday', $titles, true), 'Dokan ads not shown');
$ok(!in_array('Secret', $titles, true), 'a page the user may not open is not shown');
$ok(in_array('POS counter', array_column($g['sales'], 'title'), true) && in_array('Store location', array_column($g['market'], 'title'), true), 'top-level POS counter and AlgiBazar store location included');
$ok(!in_array('Posts', $titles, true) && !in_array(admin_url('admin.php?page=' . Modules::SLUG), $urls, true), 'unrelated WordPress menus and the dashboard itself not listed');
$ok(in_array('Systems', array_column($g['settings'], 'title'), true) && in_array('Purchases & cost', array_column($g['buying'], 'title'), true) && in_array('Due list', array_column($g['members'], 'title'), true), 'SFM4U systems, purchases and members included');
$ok(count($urls) === count(array_unique($urls)), 'no link twice');
$ok(CC::url('edit.php?post_type=product') === admin_url('edit.php?post_type=product') && CC::url('x', 'edit.php?post_type=product') === admin_url('edit.php?post_type=product&page=x'), 'links built correctly');
// hiding Dokan ads in the real left menu
global $submenu; $keep = $submenu; $submenu = $sub;
delete_option(CC::HIDE_PROMO); CC::hidePromos();
$left = array_map(fn($i) => CC::cleanTitle($i[0])[0], $submenu['dokan']);
$ok(!in_array('Pro Features', $left, true) && !in_array('Black Friday', $left, true) && in_array('Withdraw', $left, true), 'left menu: ads hidden, Withdraw kept');
$submenu = $sub; update_option(CC::HIDE_PROMO, 'no'); CC::hidePromos();
$ok(count($submenu['dokan']) === 6, 'owner can show the ads again');
delete_option(CC::HIDE_PROMO); $submenu = $keep;
// numbers + page
$s = CC::stats(true);
$ok(isset($s['orders_today'], $s['vendors'], $s['withdraw_pending'], $s['low_stock'], $s['products_pending']), 'numbers calculated');
$ok(is_array(get_transient(CC::CACHE)), 'numbers cached');
$fam = array_column(CC::familyPlugins(), 'name');
$ok((bool) array_filter($fam, fn($n) => stripos($n, 'SFM4U Business Software') !== false), 'plugin list shows SFM4U Business Software');
global $menu; $menu = $menu ?: []; $GLOBALS['menu'] = $menu; $GLOBALS['submenu'] = $sub;
update_option(CC::SHOW_OLD, 'yes'); ob_start(); CC::page(); $h = ob_get_clean(); delete_option(CC::SHOW_OLD);
ob_start(); CC::page(); $h2 = ob_get_clean();
$ok(strpos($h2, 'Marketplace &amp; shops') === false && strpos($h2, 'each with its own dashboard') !== false, 'default: only our modules, old Dokan pages hidden');
$ok(strpos($h, 'Orders today') !== false && strpos($h, 'Marketplace &amp; shops') !== false && strpos($h, 'sfm-cc-find') !== false && strpos($h, 'Plugins of this software') !== false, 'dashboard page renders (numbers, groups, search, plugins)');
$ok(strpos($h, 'page=' . Modules::SYSTEMS) !== false, 'dashboard links to Systems on/off');
ob_start(); Modules::page(); $sys = ob_get_clean();
$ok(strpos($sys, 'Systems') !== false && strpos($sys, 'Turn off') !== false, 'Systems page still works');
echo "pass $p fail $f\n";
