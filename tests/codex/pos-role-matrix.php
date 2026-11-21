<?php
require __DIR__ . '/counter-member-confirm.php';

$inventory = wp_create_user('matrix-inventory', wp_generate_password());
(new WP_User($inventory))->add_cap('manage_woocommerce');
$vendor = wp_create_user('matrix-vendor', wp_generate_password());
(new WP_User($vendor))->set_role('seller');
(new WP_User($cashier))->remove_cap('sfm4u_pos_credit');
$orderId = $retailSale->get_data()['order_id'];
$drawer = static fn(string $action, int $amount = 0, string $reason = ''): array =>
    ['action'=>'drawer', 'drawer_action'=>$action, 'session'=>$session, 'amount'=>$amount, 'reason'=>$reason];
$credit = ['action'=>'credit', 'order'=>$orderId, 'quantity'=>1, 'session'=>$session, 'resellable'=>true];
$reprint = ['action'=>'reprint', 'order'=>$orderId];
$counter = $retail + ['action'=>'counter_sale', 'session'=>$session,
    'digest'=>$retailQuote->get_data()['digest'], 'tender'=>$retailQuote->get_data()['total_minor']];
$actions = ['sale'=>$counter, 'credit'=>$credit, 'reprint'=>$reprint, 'X report'=>$drawer('xreport'),
    'drawer in'=>$drawer('in', 100, 'change_float'), 'drawer out'=>$drawer('out', 100, 'bank_drop'),
    'drawer close'=>$drawer('close')];

foreach (['inventory-only'=>$inventory, 'vendor'=>$vendor, 'guest'=>0] as $role => $id) {
    foreach ($actions as $label => $payload) {
        check_member_pos($call($payload, null, $id)->get_status() === 403, "$role denied $label");
    }
}
check_member_pos($call($credit)->get_status() === 403, 'cashier without credit permission denied credit');
foreach (['in'=>$actions['drawer in'], 'out'=>$actions['drawer out'], 'X report'=>$actions['X report'], 'reprint'=>$reprint] as $label => $payload) {
    check_member_pos($call($payload)->get_status() === 200, "assigned cashier allowed $label");
}

wp_set_current_user(1);
$manager = wp_insert_user(['user_login'=>'matrix-manager', 'user_pass'=>wp_generate_password(), 'role'=>'shop_manager']);
$_POST = ['sfm4u_pos_assignments'=>"$merchant,$register,M1",'sfm4u_pos_credit'=>'1',
    'sfm4u_pos_assign_nonce'=>wp_create_nonce('sfm4u_pos_assign_' . $manager)];
(new \SFM4U\POS\RegisterAssignments())->save($manager);
$_POST = [];
$legacy = $call(['action'=>'sale', 'product'=>$pid, 'quantity'=>1, 'session'=>$session, 'tender'=>10000], null, $manager);
check_member_pos($legacy->get_status() === 200, 'assigned manager allowed single-SKU sale');
$credit['order'] = $legacy->get_data()['order_id'];
$result = $call($credit, null, $manager);
check_member_pos($result->get_status() === 200, 'assigned manager allowed credit for supported sale: ' . $result->get_status());

global $wpdb;
$counts = new \SFM4U\Inventory\StockCounts($wpdb);
wp_set_current_user(1);
$count = $counts->submit(wp_generate_uuid4(), $pid, $stock(), $stock() - 1, 'Synthetic difference');
foreach (['cashier'=>$cashier, 'vendor'=>$vendor, 'guest'=>0] as $role => $id) {
    wp_set_current_user($id);
    $denied = false;
    try {
        $counts->approve(wp_generate_uuid4(), $count['id']);
    } catch (DomainException $error) {
        $denied = $error->getCode() === 403;
    }
    check_member_pos($denied, "$role denied count approval");
}
wp_set_current_user($inventory);
$approved = $counts->approve(wp_generate_uuid4(), $count['id']);
check_member_pos($approved['status'] === 'approved', 'inventory operator approved another actor count');

$report = $call($drawer('xreport'), null, $manager);
check_member_pos($report->get_status() === 200, 'assigned manager allowed X report');
$close = $drawer('close', $report->get_data()['expected_cash_minor']);
check_member_pos($call($close, null, $manager)->get_status() === 200, 'assigned manager allowed balanced drawer close');
