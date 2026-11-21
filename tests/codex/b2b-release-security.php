<?php
use SFM4U\POS\{RegisterAssignments, RegisterPage};

$assignments = new RegisterAssignments();
$securityCashier = wp_create_user('synthetic-security-' . $tag, wp_generate_password());
wp_set_current_user(1);
$assignmentPost = ['sfm4u_pos_assignments' => $merchant . ',R2,C9', 'sfm4u_pos_credit' => '1'];
$_POST = $assignmentPost + ['sfm4u_pos_assign_nonce' => 'invalid'];
$assignments->save($securityCashier);
check(!get_user_meta($securityCashier, '_sfm4u_pos_registers', true), 'cashier assignment rejects missing or invalid nonce');
$_POST['sfm4u_pos_assign_nonce'] = wp_create_nonce('sfm4u_pos_assign_' . $securityCashier);
$assignments->save($securityCashier);
check((new WP_User($securityCashier))->has_cap('sfm4u_pos_cashier') && (new WP_User($securityCashier))->has_cap('sfm4u_pos_credit')
    && get_user_meta($securityCashier, '_sfm4u_pos_registers', true) === [$merchant => ['R2' => 'C9']],
    'owner assigns scoped cashier and credit permissions through the user profile');
wp_set_current_user($securityCashier);
$_POST = $assignmentPost + ['sfm4u_pos_assign_nonce' => wp_create_nonce('sfm4u_pos_assign_' . $buyer)];
$assignments->save($buyer);
check(!(new WP_User($buyer))->has_cap('sfm4u_pos_cashier'), 'cashier cannot grant themselves or buyers register permissions');
wp_set_current_user(1);
$_POST = ['sfm4u_pos_assignments' => $merchant . ',R2,C1' . "\n" . $merchant . ',R2,C2',
    'sfm4u_pos_assign_nonce' => wp_create_nonce('sfm4u_pos_assign_' . $securityCashier)];
try { $assignments->save($securityCashier); check(false, 'duplicate register must fail'); }
catch (DomainException) { check(get_user_meta($securityCashier, '_sfm4u_pos_registers', true) === [$merchant => ['R2' => 'C9']], 'invalid assignment batch changes no scope or capability'); }
$_POST['sfm4u_pos_assignments'] = '';
$assignments->save($securityCashier);
check(!(new WP_User($securityCashier))->has_cap('sfm4u_pos_cashier') && !(new WP_User($securityCashier))->has_cap('sfm4u_pos_credit'),
    'blank assignment revokes cashier and credit permissions');

$securityProduct = wc_get_product($countProductId);
$_POST = ['_sfm4u_pos_merchant_id' => '9123', 'sfm4u_pos_product_nonce' => 'invalid'];
$assignments->saveProduct($securityProduct);
check((int) $securityProduct->get_meta('_sfm4u_pos_merchant_id') === $merchant, 'product merchant scope rejects invalid nonce');
$_POST['sfm4u_pos_product_nonce'] = wp_create_nonce('sfm4u_pos_product_' . $countProductId);
$assignments->saveProduct($securityProduct); $securityProduct->save();
check((int) wc_get_product($countProductId)->get_meta('_sfm4u_pos_merchant_id') === 9123, 'owner assigns merchant product scope with product-bound nonce');
$_POST['_sfm4u_pos_merchant_id'] = (string) $merchant;
$assignments->saveProduct($securityProduct); $securityProduct->save();

foreach (['quick-order', 'submit', 'offer', 'revise', 'accept', 'reject', 'cancel', 'convert', 'settle', 'reconcile', 'fulfill', 'credit'] as $route) {
    check(call_api(0, $route, [])->get_status() === 401 && call_api($buyer, $route, [], '', false)->get_status() === 403,
        'REST ' . $route . ' rejects anonymous and missing nonce');
}
check($posRequest($buyer, ['merchant' => $merchant, 'register' => 'R1', 'action' => 'reprint', 'order' => $apiSale->get_data()['order_id']], wp_generate_uuid4())->get_status() === 403,
    'POS receipt endpoint denies buyer even with valid nonce');
check($posRequest($staff, ['merchant' => $merchant + 999, 'register' => 'R1', 'action' => 'reprint', 'order' => $apiSale->get_data()['order_id']], wp_generate_uuid4())->get_status() === 403,
    'POS receipt endpoint denies cross-merchant access');
check($posRequest($staff, ['merchant' => $merchant, 'register' => 'R1', 'action' => 'sale', 'product' => ['1 OR 1=1'], 'quantity' => 1, 'tender' => 1, 'session' => 1], wp_generate_uuid4())->get_status() === 422,
    'POS numeric array and SQL payload is rejected before SQL');
$registerPage = new RegisterPage($cashPos);
wp_set_current_user($staff);
$_POST = ['merchant' => (string) $merchant, 'register' => 'R1', 'register_action' => 'status'];
try { $registerPage->submit(); check(false, 'register missing nonce must fail'); }
catch (DomainException $error) { check($error->getCode() === 403, 'server register handler requires nonce as well as cashier capability'); }
$_POST['_wpnonce'] = wp_create_nonce('sfm4u_pos_register');
check(is_array($registerPage->submit()), 'server register handler reads assigned drawer with nonce');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = ['expense_memo' => '"><script>alert(1)</script>'];
ob_start(); $registerPage->render(); $escapedRegister = ob_get_clean();
check(!str_contains($escapedRegister, '<script>alert(1)</script>') && str_contains($escapedRegister, '&lt;script&gt;'), 'register reflected input is escaped in attributes');
wp_set_current_user($buyer);
ob_start(); $registerPage->render(); $deniedRegister = ob_get_clean();
check($deniedRegister === '', 'buyer cannot render cashier register page');
wp_set_current_user(1); $_POST = []; $_GET = [];

// Failed refund must be recovered before a later cancellation, including manual out-of-order retry.
$queueOrder = wc_create_order(['created_via' => 'checkout']);
$queueOrder->set_currency('BDT');
$queueLine = $queueOrder->add_product(wc_get_product($nativePid), 2);
$queueOrder->calculate_totals(false); $queueOrder->update_status('processing');
add_action('sfm4u_native_inventory_before_commit', $failRefund);
$queueRefund = wc_create_refund(['order_id' => $queueOrder->get_id(), 'amount' => '30.00', 'restock_items' => true,
    'refund_payment' => false, 'line_items' => [$queueLine => ['qty' => 1, 'refund_total' => '30.00', 'refund_tax' => []]]]);
remove_action('sfm4u_native_inventory_before_commit', $failRefund);
wc_get_order($queueOrder->get_id())->update_status('cancelled');
$queued = $wpdb->get_col($wpdb->prepare('SELECT event_key FROM %i WHERE order_id=%d ORDER BY sequence',
    $wpdb->prefix . 'sfm4u_native_inventory_pending', $queueOrder->get_id()));
check(count($queued) === 2 && (int) wc_get_product($nativePid)->get_stock_quantity() === 7, 'failed native refund queues later cancellation without restoring twice');
try { $nativeInventory->retry($queued[1]); check(false, 'later cancellation must wait'); }
catch (DomainException) { check(true, 'out-of-order inventory retry cannot overtake the pending refund'); }
$nativeInventory->sweep();
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9 && $valuation->totals($nativePid)['stock_micros'] === 180000000,
    'ordered recovery sweep posts refund then remaining cancellation exactly once');
$nativeOrder = wc_get_order($nativeOrder->get_id()); $nativeOrder->update_status('processing');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 7, 'reopened native order reduces only unrefunded units');
wc_get_order($nativeOrder->get_id())->update_status('cancelled');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9, 'reopened native cancellation restores remaining units once');

// The owner-facing form uses the same committed cash receipt and refund services.
wp_set_current_user(1);
$_POST = ['sfm4u_pos_assignments' => $merchant . ',R2,C9', 'sfm4u_pos_credit' => '1',
    'sfm4u_pos_assign_nonce' => wp_create_nonce('sfm4u_pos_assign_' . $securityCashier)];
$assignments->save($securityCashier);
wp_set_current_user($securityCashier);
$pageCall = static function (array $values) use ($registerPage, $merchant): array {
    $_POST = $values + ['merchant' => (string) $merchant, 'register' => 'R2', 'request_key' => wp_generate_uuid4(),
        '_wpnonce' => wp_create_nonce('sfm4u_pos_register')];
    return $registerPage->submit();
};
$pageDrawer = $pageCall(['register_action' => 'open', 'amount' => '0.00']);
$pageSaleValues = ['register_action' => 'sale', 'session' => (string) $pageDrawer['session_id'], 'product' => (string) $countProductId,
    'quantity' => '1', 'tender' => '30.00', 'request_key' => wp_generate_uuid4()];
$pageSale = $pageCall($pageSaleValues);
check($pageSale === $pageCall($pageSaleValues), 'owner-facing cash sale form replays the committed receipt without another sale');
$pageCopy = $pageCall(['register_action' => 'reprint', 'order' => (string) $pageSale['order_id']]);
check($pageCopy['kind'] === 'COPY' && $pageCopy['receipt'] === $pageSale, 'owner-facing register reprints an immutable receipt copy');
$pageCredit = $pageCall(['register_action' => 'credit', 'order' => (string) $pageSale['order_id'], 'quantity' => '1',
    'session' => (string) $pageDrawer['session_id'], 'resellable' => '1']);
$pageClose = $pageCall(['register_action' => 'close', 'session' => (string) $pageDrawer['session_id'], 'amount' => '0.00']);
check($pageCredit['sale']['total_minor'] === 3000 && $pageClose['expected_cash_minor'] === 0 && $pageClose['variance_minor'] === 0,
    'owner-facing register completes sale, return and daily drawer close');
wp_set_current_user(1); $_POST = []; $_GET = [];

wp_set_current_user($staff); $_GET = ['page' => 'sfm4u-pos'];
check(apply_filters('woocommerce_prevent_admin_access', true) === false, 'assigned cashier can reach only the registered Woo admin POS screen');
wp_set_current_user($buyer);
check(apply_filters('woocommerce_prevent_admin_access', true) === true, 'buyer does not bypass Woo admin access restriction');
wp_set_current_user(1); $_GET = [];
$currencyOrder = wc_create_order(['created_via' => 'checkout']);
$currencyOrder->set_currency('USD'); $currencyOrder->add_product(wc_get_product($nativePid), 1);
$currencyOrder->calculate_totals(false); $currencyOrder->update_status('processing');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9, 'foreign currency native stock capture fails closed for warehouse cost');
$currencyOrder->set_currency('BDT'); $currencyOrder->save(); $nativeInventory->sweep();
check((int) wc_get_product($nativePid)->get_stock_quantity() === 8, 'reviewed native currency correction recovers the original queued stock event');
wc_get_order($currencyOrder->get_id())->update_status('cancelled');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9, 'recovered native currency order cancels consistently');

// A real status transition must survive while an earlier stock event is still queued.
$rapidOrder = wc_create_order(['created_via' => 'checkout']); $rapidOrder->set_currency('BDT');
$rapidOrder->add_product(wc_get_product($nativePid), 1); $rapidOrder->calculate_totals(false);
$rapidOrder->update_status('processing');
$failCancel = static function (string $action): void { if ($action === 'cancel') throw new RuntimeException('synthetic queued cancel'); };
add_action('sfm4u_native_inventory_before_commit', $failCancel);
wc_get_order($rapidOrder->get_id())->update_status('cancelled');
remove_action('sfm4u_native_inventory_before_commit', $failCancel);
wc_get_order($rapidOrder->get_id())->update_status('processing');
$rapidPending = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d', $wpdb->prefix . 'sfm4u_native_inventory_pending', $rapidOrder->get_id()));
check($rapidPending === 2, 'reopened status is durably queued behind failed native cancellation');
$nativeInventory->sweep(); $nativeInventory->sweep();
check((int) wc_get_product($nativePid)->get_stock_quantity() === 8 && $valuation->totals($nativePid)['stock_micros'] === 160000000,
    'queued cancellation then reopening leaves processing order stock and value reduced exactly once');
wc_get_order($rapidOrder->get_id())->update_status('cancelled');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9, 'post-recovery cancellation is a new event and restores stock once');

$rapidSaleOrder = wc_create_order(['created_via' => 'checkout']); $rapidSaleOrder->set_currency('BDT');
$rapidSaleOrder->add_product(wc_get_product($nativePid), 1); $rapidSaleOrder->calculate_totals(false);
add_action('sfm4u_native_inventory_before_commit', $failNative);
$rapidSaleOrder->update_status('processing');
remove_action('sfm4u_native_inventory_before_commit', $failNative);
wc_get_order($rapidSaleOrder->get_id())->update_status('cancelled');
wc_get_order($rapidSaleOrder->get_id())->update_status('processing');
$nativeInventory->sweep(); $nativeInventory->sweep();
check((int) wc_get_product($nativePid)->get_stock_quantity() === 8 && $valuation->totals($nativePid)['stock_micros'] === 160000000,
    'failed sale followed by cancel and reopen retains every distinct recovery transition');
wc_get_order($rapidSaleOrder->get_id())->update_status('cancelled');
check((int) wc_get_product($nativePid)->get_stock_quantity() === 9, 'rapid sale transition recovery returns to original stock after final cancel');
