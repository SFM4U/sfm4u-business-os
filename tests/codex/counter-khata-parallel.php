<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') exit(1);
$mode = $args[0];
$barrier = realpath($args[1] ?? '');
if (!$barrier || !is_dir($barrier) || dirname($barrier) !== '/fixture-out') {
    throw new RuntimeException('Use a fresh khata barrier directory under /fixture-out');
}
$path = $barrier . '/seed.json';
if ($mode === 'seed') {
    require __DIR__ . '/counter-khata-rest.php';
    wp_set_current_user(1);
    $otherCashier = wp_create_user('khata-parallel-cashier', wp_generate_password());
    $_POST = ['sfm4u_pos_assignments'=>"$merchant,MEM2,C2",
        'sfm4u_pos_assign_nonce'=>wp_create_nonce('sfm4u_pos_assign_' . $otherCashier)];
    (new \SFM4U\POS\RegisterAssignments())->save($otherCashier);
    $_POST = [];
    $otherOpen = $call(['action'=>'drawer','drawer_action'=>'open','session'=>0,'amount'=>0,'register'=>'MEM2'], null, $otherCashier);
    check_member_pos($otherOpen->get_status() === 200, 'second cashier opens distinct register');
    file_put_contents($path, wp_json_encode(['actors'=>[$cashier,$otherCashier], 'sessions'=>[$session,$otherOpen->get_data()['session_id']],
        'member'=>$card,'member_id'=>$business, 'merchant'=>$merchant]));
    return;
}

define('SFM4U_POS_KHATA_STORES', [763122 => 'test-main', 763123 => 'test-other']);
$fixture = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
if ($mode === 'worker') {
    $index = (int) $args[2];
    wp_set_current_user($fixture['actors'][$index]);
    $request = new WP_REST_Request('POST', '/sfm4u-pos/v1/register');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_header('Idempotency-Key', 'khata-parallel-payment-' . $index);
    $request->set_body(wp_json_encode(['action'=>'member_due_payment','merchant'=>$fixture['merchant'],
        'register'=>['MEM','MEM2'][$index],'session'=>$fixture['sessions'][$index], 'member'=>$fixture['member'],'amount'=>5000]));
    touch("$barrier/ready-$index");
    for ($tries=0; !is_file($barrier . '/go'); ++$tries) {
        if ($tries>=180) throw new RuntimeException('Khata barrier timeout');
        usleep(100000);
    }
    for ($tries=0; $tries<3; ++$tries) {
        $response = rest_do_request($request);
        if ($response->get_status() !== 503) break;
        usleep(250000);
    }
    file_put_contents("$barrier/result-$index", wp_json_encode([
        'status'=>$response->get_status(),'data'=>$response->get_data(),
    ]));
    return;
}
$results=[];
foreach ([0,1] as $index) {
    $result = json_decode(file_get_contents("$barrier/result-$index"), true, 16, JSON_THROW_ON_ERROR);
    if ($result['status'] !== 200) throw new RuntimeException('Parallel payment failed: ' . $result['status']);
    $results[] = $result['data'];
}
$before = array_column($results,'previous_due_minor');
sort($before);
$balance = \SFM4U\Wholesale\Members\Members::balance($fixture['member_id'],'test-main');
if ($before !== [1000,6000] || $results[0]['payment_id'] === $results[1]['payment_id'] || $balance !== -4000) {
    throw new RuntimeException('Parallel khata balances did not reconcile');
}
echo "PASS two cashiers at separate registers serialize khata and preserve both payments\n";
