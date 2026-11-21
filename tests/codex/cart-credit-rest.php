<?php
require __DIR__ . '/pos-role-matrix.php';

wp_set_current_user(1);
$product2 = new WC_Product_Simple();
$product2->set_name('Synthetic second credit item');
$product2->set_regular_price('12.48');
$product2->set_manage_stock(true);
$product2->set_stock_quantity(0);
$product2->update_meta_data('_sfm4u_pos_merchant_id', $merchant);
$product2->save();
$pid2 = $product2->get_id();
$po2 = $purchase->issue(wp_generate_uuid4(), 'Synthetic credit supplier', [['product'=>$pid2, 'quantity'=>10, 'unit_minor'=>400]]);
$purchase->receive(wp_generate_uuid4(), $po2['id'], [['product'=>$pid2, 'quantity'=>10, 'landed_minor'=>0]]);
$open2 = $call(['action'=>'drawer','drawer_action'=>'open','session'=>0,'amount'=>0], null, $manager);
check_member_pos($open2->get_status() === 200, 'manager opens cart credit fixture drawer');
$session2 = $open2->get_data()['session_id'];
$cart = ['lines'=>[['product'=>$pid,'quantity'=>2],['product'=>$pid2,'quantity'=>2]],
    'method'=>'cash', 'tier'=>'retail', 'discount'=>11];
$quoted = $call($cart + ['action'=>'counter_quote'], null, $manager);
check_member_pos($quoted->get_status() === 200 && $quoted->get_data()['rounding_minor'] !== 0, 'discounted two-line quote has cash rounding');
$commit = $call($cart + ['action'=>'counter_sale','session'=>$session2,
    'digest'=>$quoted->get_data()['digest'],'tender'=>$quoted->get_data()['total_minor']], null, $manager);
check_member_pos($commit->get_status() === 200, 'discounted cart commits');
$cartOrder = wc_get_order($commit->get_data()['order_id']);
[$item1,$item2] = array_keys($cartOrder->get_items());
$originalStock = [$stock(), (int) wc_get_product($pid2)->get_stock_quantity()];
$payload = ['action'=>'cart_credit','session'=>$session2,'order'=>$cartOrder->get_id(), 'resellable'=>true,
    'items'=>[['item'=>$item1,'quantity'=>1],['item'=>$item2,'quantity'=>1]]];
check_member_pos($call($payload)->get_status() === 403, 'cashier denied cart credit');
$key2 = wp_generate_uuid4();
$interrupt = static function (): void { throw new RuntimeException('Synthetic interruption before cart credit commit'); };
add_action('sfm4u_synthetic_credit_before_commit', $interrupt);
$failed = $call($payload, $key2, $manager);
remove_action('sfm4u_synthetic_credit_before_commit', $interrupt);
check_member_pos($failed->get_status() === 503 && $stock() === $originalStock[0]
    && (int) wc_get_product($pid2)->get_stock_quantity() === $originalStock[1]
    && count(wc_get_order($cartOrder->get_id())->get_refunds()) === 0,
    'interrupted cart credit rolls back refund and both stock changes');
$first = $call($payload, $key2, $manager);
check_member_pos($first->get_status() === 200, 'partial two-line cart credit succeeds: ' . $first->get_status() . ' ' . ($first->get_data()['code'] ?? ''));
check_member_pos($stock() === $originalStock[0] + 1 && (int) wc_get_product($pid2)->get_stock_quantity() === $originalStock[1] + 1,
    'partial credit restores stock on both lines');
check_member_pos($call($payload, $key2, $manager)->get_data() === $first->get_data()
    && $stock() === $originalStock[0] + 1, 'cart credit replay returns same document without restocking');
$payload['resellable'] = false;
check_member_pos($call($payload, $key2, $manager)->get_status() === 409, 'changed credit intent conflicts under same key');
wp_set_current_user($manager);
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$receipts = new \SFM4U\POS\ReceiptRegister($wpdb);
$registerService = new \SFM4U\POS\AuthorizedRegister($journal, $receipts,
    new \SFM4U\POS\SyntheticSale($journal, $receipts), new \SFM4U\POS\RegisterSessions($wpdb));
$page = new \SFM4U\POS\RegisterPage($registerService);
$_POST = ['merchant'=>(string)$merchant, 'register'=>$register, 'session'=>(string)$session2,
    'order'=>(string)$cartOrder->get_id(), '_wpnonce'=>wp_create_nonce('sfm4u_pos_register'),
    'request_key'=>wp_generate_uuid4(), 'register_action'=>'cart_credit_lines'];
$listed = $page->submit();
check_member_pos($listed['kind'] === 'CART_CREDIT_LINES' && array_column($listed['lines'], 'quantity') === [1, 1],
    'manager form lists remaining credit quantities after partial return');
$_POST['register_action'] = 'cart_credit';
$_POST['credit_lines'] = "$item1,1\n$item2,1";
$second = rest_ensure_response($page->submit());
$_POST = [];
check_member_pos($second->get_status() === 200 && $stock() === $originalStock[0] + 1, 'damaged return credits without restocking');
check_member_pos($first->get_data()['sale']['refund_minor'] + $second->get_data()['sale']['refund_minor']
    === $quoted->get_data()['total_minor'], 'all cart credits reconcile with original rounded total');
check_member_pos($call($payload, null, $manager)->get_status() === 422, 'over-refund rejected');
$report2 = $call(['action'=>'drawer','drawer_action'=>'xreport','session'=>$session2,'amount'=>0], null, $manager);
check_member_pos($report2->get_data()['cash_returns_minor'] === $quoted->get_data()['total_minor'], 'X report includes both cart credits');
