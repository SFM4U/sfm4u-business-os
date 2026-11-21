<?php

if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Loopback synthetic WooCommerce only.');
}

require_once '/modules/b2b-pro/bootstrap.php';
if (!class_exists('SFM4U\\Wholesale\\Commerce')) {
    require_once WP_PLUGIN_DIR . '/sfm4u-wholesale/src/Commerce.php';
}

use SFM4U\Inventory\LossAwareWpdb;
use SFM4U\Wholesale\Commerce;

global $wpdb;
if (!$wpdb instanceof LossAwareWpdb) {
    $original = $wpdb;
    $wpdb = new LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $wpdb->set_prefix($original->base_prefix);
    $wpdb->set_blog_id(get_current_blog_id());
    foreach (get_object_vars($original) as $alias => $value) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) && is_string($value) && str_starts_with($value, $original->prefix)
            && preg_match('/^[a-zA-Z0-9_]+$/D', $value)) {
            $wpdb->$alias = $value;
        }
    }
}
add_filter('pre_wp_mail', '__return_true');
update_option('sfmwh_enabled', 'yes');
update_option('woocommerce_currency', 'BDT');
update_option('woocommerce_hold_stock_minutes', '10');
update_option('woocommerce_calc_taxes', 'no');

$tag = bin2hex(random_bytes(4));
$buyer = wp_create_user("b2b-buyer-$tag", wp_generate_password());
$vendor = wp_create_user("b2b-vendor-$tag", wp_generate_password());
if (in_array('dokan', $args ?? [], true)) {
    check(function_exists('dokan_is_user_seller'), 'Dokan Lite active');
    echo 'WordPress ' . get_bloginfo('version') . ', WooCommerce ' . WC_VERSION . ', Dokan ' . DOKAN_PLUGIN_VERSION . ', PHP ' . PHP_VERSION . PHP_EOL;
    (new WP_User($vendor))->set_role('seller');
    update_user_meta($vendor, 'dokan_enable_selling', 'yes');
    check(dokan_is_user_seller($vendor), 'real Dokan seller');
}
$outsider = wp_create_user("b2b-outsider-$tag", wp_generate_password());
(new WP_User($buyer))->add_cap('sfm4u_b2b_buy');
(new WP_User($outsider))->add_cap('sfm4u_b2b_buy');
(new WP_User($vendor))->add_cap('sfm4u_b2b_offer');
update_user_meta($buyer, '_sfmwh_buyer_status', 'approved');

$product = new WC_Product_Simple();
$product->set_name("Synthetic B2B $tag");
$product->set_sku("B2B-$tag");
$product->set_regular_price('50.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(20);
$product->save();
wp_update_post(['ID' => $product->get_id(), 'post_author' => $vendor]);
Commerce::saveRule($product, ['minor' => 1500, 'min' => 3, 'max' => 20, 'step' => 2,
    'tiers' => [5 => 1200], 'packs' => ['carton' => 5], 'pack_minor' => ['carton' => 5400], 'buyer' => 'approved:subscriber']);

// The standalone fixture registers the B2B routes without the installed runtime bootstrap.
$capabilityFilter = [\SFM4U\Wholesale\B2BRuntime::class, 'capabilities'];
if (has_filter('user_has_cap', $capabilityFilter)) {
    $quotes = new \SFM4U\Wholesale\Pro\WooQuotes($wpdb, new \SFM4U\Wholesale\Pro\WooCatalog());
} else {
    add_filter('user_has_cap', $capabilityFilter, 10, 4);
    $quotes = sfm4u_b2b_register($wpdb);
}
$quotes->install();
$ledger = $wpdb->prefix . 'sfm4u_b2b_ledger';
$wpdb->insert($wpdb->prefix . 'sfm4u_b2b_accounts', ['buyer' => $buyer, 'credit_limit' => 6500,
    'net_days' => 30, 'grace_days' => 1, 'currency' => 'BDT']);

function check(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException($name);
    }
    echo "PASS $name\n";
}

function call_api(int $actor, string $endpoint, array $payload, string $key = '', bool $nonce = true): WP_REST_Response
{
    wp_set_current_user($actor);
    $request = new WP_REST_Request('POST', '/sfm4u-b2b/v1/' . $endpoint);
    $request->set_header('Content-Type', 'application/json');
    if ($nonce) {
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    }
    $request->set_header('Idempotency-Key', $key);
    $request->set_body(wp_json_encode($payload));
    $response = rest_do_request($request);
    if ($response->get_status() >= 400) { echo $endpoint . " " . wp_json_encode($response->get_data()) . PHP_EOL; }
    return $response;
}

function error_code(WP_REST_Response $response, string $code): bool
{
    $error = $response->get_data();
    return ($error['code'] ?? '') === $code && !empty($error['data']['messages']['en']) && !empty($error['data']['messages']['bn']);
}

$sku = $product->get_sku();
check(error_code(call_api(0, 'quick-order', ['csv' => "$sku,5"]), 'authentication_required'), 'anonymous denied bilingually');
check(error_code(call_api($buyer, 'quick-order', ['csv' => "$sku,5"], '', false), 'nonce_invalid'), 'missing nonce denied bilingually');
check(error_code(call_api($vendor, 'quick-order', ['csv' => "$sku,5"]), 'forbidden'), 'wrong capability denied bilingually');
$hidden = call_api($outsider, 'quick-order', ['csv' => "$sku,5"]);
check(error_code($hidden, 'forbidden') || ($hidden->get_data()['errors'][0]['code'] ?? '') === 'sku_not_found',
    'unapproved buyer cannot see the wholesale SKU');
check(call_api($buyer, 'quick-order', ['csv' => "$sku,2\n$sku,3"])->get_data()['total_minor'] === 6000, 'Woo aggregate tier');
check(call_api($buyer, 'quick-order', ['csv' => "$sku,1,carton"])->get_data()['total_minor'] === 5400, 'Woo carton pricing');

$lines = [['sku' => $sku, 'qty' => 5]];
$key = 'quote_submit_' . $tag;
$submit = call_api($buyer, 'submit', ['lines' => $lines], $key);
check($submit->get_status() === 200, 'submit RFQ ' . wp_json_encode($submit->get_data()));
$id = ['id' => $submit->get_data()['id']];
check(call_api($buyer, 'submit', ['lines' => $lines], $key)->get_data() === $submit->get_data(), 'submit exact replay');
check(error_code(call_api($buyer, 'submit', ['lines' => [['sku' => $sku, 'qty' => 7]]], $key), 'idempotency_conflict'), 'changed submission rejected');
$offer = $id + ['lines' => [$lines[0] + ['unit_price_minor' => 1200]], 'valid_until' => time() + 3600];
if (function_exists('dokan_is_user_seller') && dokan_is_user_seller($vendor)) {
    check(call_api($vendor, 'offer', $offer, 'ungranted_offer_' . $tag)->get_status() === 403,
        'regular Dokan seller cannot offer before the Business OS grant');
}
update_user_meta($vendor, '_sfmwh_vendor_tools_grant', 'yes');
check(call_api($vendor, 'offer', $offer, 'quote_offer_' . $tag)->get_data()['state'] === 'offered', 'vendor offer');
update_user_meta($outsider, '_sfmwh_buyer_status', 'approved');
check(error_code(call_api($outsider, 'accept', $id, 'outsider_accept_' . $tag), 'not_quote_owner'), 'other buyer denied');
check(call_api($buyer, 'accept', $id, 'quote_accept_' . $tag)->get_data()['state'] === 'accepted', 'buyer accepts');

$rolledBackId = 0;
$failure = static function (int $orderId) use (&$rolledBackId): void {
    $rolledBackId = $orderId;
    throw new RuntimeException('synthetic_rollback');
};
add_action('sfm4u_b2b_synthetic_before_commit', $failure);
$key = 'quote_convert_' . $tag;
$failed = call_api($buyer, 'convert', $id, $key);
check(error_code($failed, 'database_unavailable'), 'simulated failure returns bilingual recovery');
remove_action('sfm4u_b2b_synthetic_before_commit', $failure);
check($rolledBackId > 0, 'failure happened after order, charge and stock hold');
$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
$table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
$column = $hpos ? 'id' : 'ID';
check(!$wpdb->get_var($wpdb->prepare('SELECT %i FROM %i WHERE %i=%d', $column, $table, $column, $rolledBackId)), 'order rolled back');
check(!$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE buyer=%d', $ledger, $buyer)), 'charge rolled back');
check((int) wc_get_held_stock_quantity($product) === 0, 'stock hold rolled back');

$out = call_api($buyer, 'convert', $id, $key);
$data = $out->get_data();
check($out->get_status() === 200 && $data['amount_minor'] === 6000 && $data['state'] === 'converted', 'order and charge commit ' . wp_json_encode($data));
check(call_api($buyer, 'convert', $id, $key)->get_data() === $data, 'network retry returns original conversion');
if (in_array('dokan', $args ?? [], true)) {
    update_user_meta($vendor, 'dokan_enable_selling', 'no');
    check(call_api($buyer, 'convert', $id, $key)->get_data() === $data,
        'Dokan seller disablement after commit does not change the original conversion on replay');
    update_user_meta($vendor, 'dokan_enable_selling', 'yes');
}
check(error_code(call_api($buyer, 'convert', $id, 'fresh_convert_' . $tag), 'transition_not_allowed'), 'new key cannot double charge');
$order = wc_get_order((int) $data['order_id']);
check($order && $order->get_created_via() === 'sfm4u-b2b-credit' && count($order->get_items()) === 1 && $order->get_status() === 'pending', 'Woo CRUD order persisted');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE buyer=%d', $ledger, $buyer)) === 1, 'one ledger entry persisted');
check((int) wc_get_held_stock_quantity($product) === 5 && (int) wc_get_product($product->get_id())->get_stock_quantity() === 20, 'stock held once with no deduction');
echo $hpos ? "HPOS integration passed\n" : "Legacy integration passed\n";

check(!$order->needs_payment(), 'NET30 order cannot enter Woo order-pay');
$accounts = $wpdb->prefix . 'sfm4u_b2b_accounts';
$convert = static function (string $suffix, ?string $expectedBlock = null) use ($buyer, $vendor, $lines, $offer, $tag): array {
    $submission = call_api($buyer, 'submit', ['lines' => $lines], "submit_{$suffix}_$tag")->get_data();
    $id = ['id' => $submission['id']];
    call_api($vendor, 'offer', $id + $offer, "offer_{$suffix}_$tag");
    call_api($buyer, 'accept', $id, "accept_{$suffix}_$tag");
    $converted = call_api($buyer, 'convert', $id, "convert_{$suffix}_$tag");
    if ($expectedBlock !== null) {
        check(error_code($converted, $expectedBlock), 'checkout blocked ' . $expectedBlock);
        return [];
    }
    check($converted->get_status() === 200, 'additional quote converted ' . $suffix);
    return $converted->get_data();
};
$convert('over-limit', 'credit_limit_exceeded');
$wpdb->update($accounts, ['credit_limit' => 20000], ['buyer' => $buyer]);
$originalId = (int) $data['order_id'];
$wpdb->update($ledger, ['due_at' => time() - 2 * DAY_IN_SECONDS], ['order_id' => $originalId]);
$convert('overdue', 'overdue_invoices');
$wpdb->update($ledger, ['due_at' => $data['due_at']], ['order_id' => $originalId]);
$second = $convert('second');
$third = $convert('third');
$manager = wp_create_user("b2b-settlement-$tag", wp_generate_password());
(new WP_User($manager))->add_cap('sfm4u_b2b_settle');
$settle = ['buyer' => $buyer, 'amount_minor' => 6500, 'reference' => 'FIXTURE-' . $tag];
$key = 'settlement_req_' . $tag;
check(error_code(call_api($buyer, 'settle', $settle, $key), 'forbidden'), 'buyer cannot record settlement');
$recorded = call_api($manager, 'settle', $settle, $key);
check($recorded->get_status() === 200 && array_column($recorded->get_data()['allocations'], 'amount_minor') === [6000, 500], 'payment allocated FIFO');
check(call_api($manager, 'settle', $settle, $key)->get_data() === $recorded->get_data(), 'settlement retry returns original allocations');
check(error_code(call_api($manager, 'settle', $settle, 'duplicate_ref_' . $tag), 'settlement_reference_exists'), 'reference unique across keys');
check(error_code(call_api($manager, 'settle', ['amount_minor' => 20000] + $settle, 'overpayment_req_' . $tag), 'overpayment'), 'overpayment rejected');
check($quotes->statement($buyer)['balance_minor'] === 11500, 'buyer statement includes partial allocation');

$thirdId = (int) $third['order_id'];
$secondId = (int) $second['order_id'];
$reconcile = ['buyer' => $buyer, 'order' => $thirdId];
$key = 'reconcile_req_' . $tag;
check(error_code(call_api($manager, 'reconcile', $reconcile, $key), 'hold_not_expired'), 'active hold protected');
foreach ([$thirdId, $secondId] as $expired) {
    $wpdb->update($ledger, ['hold_until' => time() - 120], ['order_id' => $expired]);
    $wpdb->query($wpdb->prepare(
        'UPDATE %i SET expires=NOW() - INTERVAL 2 MINUTE WHERE order_id=%d',
        $wpdb->prefix . 'wc_reserved_stock', $expired
    ));
}
check(error_code(call_api($manager, 'reconcile', ['order' => $secondId] + $reconcile, 'part_paid_hold_' . $tag), 'hold_requires_review'), 'partly paid expiry sent for review');
$expiryHook = 'sfm4u_b2b_reconcile_stock_holds';
check(as_has_scheduled_action($expiryHook, [], 'sfm4u-b2b'), 'recurring hold reconciliation installed');
$runExpiry = static function () use ($expiryHook): string {
    $action = as_schedule_single_action(time(), $expiryHook, [], 'sfm4u-b2b-test');
    ActionScheduler_QueueRunner::instance()->process_action($action, 'synthetic-test');
    return ActionScheduler_Store::instance()->get_status($action);
};
$failRelease = static function (WC_Order $order) use ($thirdId): void {
    if ($order->get_id() === $thirdId && $order->has_status('cancelled')) {
        throw new Error('Injected failure after reservation release');
    }
};
add_action('woocommerce_before_order_object_save', $failRelease);
wp_set_current_user(0);
check($runExpiry() === ActionScheduler_Store::STATUS_FAILED, 'scheduler records injected failure');
remove_action('woocommerce_before_order_object_save', $failRelease);
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',
    $wpdb->prefix . 'wc_reserved_stock', $thirdId)) === 1, 'failed job rolls back reservation removal');
check($quotes->statement($buyer)['balance_minor'] === 11500, 'failed job rolls back credit release');
add_filter('woocommerce_hold_stock_for_checkout', '__return_false');
check($runExpiry() === ActionScheduler_Store::STATUS_FAILED, 'scheduler does not report a skipped reservation removal as success');
remove_filter('woocommerce_hold_stock_for_checkout', '__return_false');
check(wc_get_order($thirdId)->has_status('pending') && $quotes->statement($buyer)['balance_minor'] === 11500,
    'skipped reservation removal preserves pending order and its credit charge');
check($runExpiry() === ActionScheduler_Store::STATUS_COMPLETE && $runExpiry() === ActionScheduler_Store::STATUS_COMPLETE,
    'scheduler retries and duplicate execution completes');
check(wc_get_order($secondId)->has_status('pending') && $wpdb->get_var($wpdb->prepare(
    'SELECT expiry_review FROM %i WHERE order_id=%d', $ledger, $secondId)) === 'payment_allocated',
    'partly paid order stays pending and enters staff review');
$notes = array_filter(wc_get_order_notes(['order_id' => $secondId]), static fn($note) =>
    str_contains($note->content, 'B2B stock hold expired'));
check(count($notes) === 1, 'one staff note after scheduler retries');
wp_set_current_user($manager);
ob_start();
(new \SFM4U\Wholesale\Pro\HoldReviewPage($quotes))->render();
$reviewPage = ob_get_clean();
check(str_contains($reviewPage, '#' . $secondId) && str_contains($reviewPage, 'Payment recorded'),
    'staff review page lists the partially paid order');
check(str_contains($reviewPage, 'Allocated payment BDT 5.00, open balance BDT 55.00'),
    'staff review displays ledger amounts for the partially paid order');
$released = call_api($manager, 'reconcile', $reconcile, $key);
check($released->get_status() === 200 && $released->get_data()['released'], 'expired unpaid hold reconciled');
check(call_api($manager, 'reconcile', $reconcile, $key)->get_data() === $released->get_data(), 'reconciliation retry has no duplicate release');
check(wc_get_order($thirdId)->has_status('cancelled') && (int) wc_get_product($product->get_id())->get_stock_quantity() === 20, 'cancelled order retains physical stock');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',
    $wpdb->prefix . 'wc_reserved_stock', $thirdId)) === 0, 'expired reservation row removed');
check($quotes->statement($buyer)['balance_minor'] === 5500, 'cancelled charge removed from open balance');

$render = static function (string $page): string {
    ob_start();
    do_action('woocommerce_account_' . $page . '_endpoint');
    return ob_get_clean();
};
wp_set_current_user($buyer);
check(str_contains($render('b2b-quotes'), $id['id']), 'buyer dashboard lists own quote');
$statementHtml = $render('b2b-credit');
check(str_contains($statementHtml, 'BDT 55.00') && str_contains($statementHtml, $settle['reference'])
    && str_contains($statementHtml, 'BDT 65.00'), 'print statement shows balance and recorded payment');
check(str_contains($render('b2b-credit'), 'Credit Statement') && !str_contains($render('b2b-credit'), 'sfm4u_lang'),
    'English statement without language selector');
$_POST = ['sfm4u_list_action' => 'save', 'sfm4u_list_nonce' => wp_create_nonce('sfm4u_list'),
    'list_name' => 'Monthly fixture', 'list_items' => "$sku,5"];
check(str_contains($render('b2b-lists'), 'Monthly fixture'), 'purchase list persisted');
$stored = get_user_meta($buyer, '_sfm4u_b2b_purchase_lists', true);
$_POST = ['sfm4u_list_action' => 'reorder', 'sfm4u_list_nonce' => wp_create_nonce('sfm4u_list'), 'list' => array_key_first($stored)];
check(str_contains($render('b2b-lists'), 'BDT 60.00'), 'purchase reorder preview checks current price and stock');
WC()->initialize_session();
WC()->initialize_cart();
$_POST['sfm4u_list_action'] = 'add_to_cart';
check(str_contains($render('b2b-lists'), 'Purchase list added to your cart.') && WC()->cart->get_cart_contents_count() === 5,
    'purchase list inserts revalidated items in WooCommerce cart');
WC()->cart->empty_cart();
$_POST = [];
wp_set_current_user($outsider);
check(!str_contains($render('b2b-quotes'), $id['id']) && !str_contains($render('b2b-lists'), 'Monthly fixture'), 'buyer dashboards are isolated');
$uiRequest = call_api($buyer, 'submit', ['lines' => $lines], 'quote_ui_submit_' . $tag)->get_data();
$uiId = $uiRequest['id'];
$uiOffer = ['id' => $uiId] + $offer;
check(call_api($vendor, 'offer', $uiOffer, 'quote_ui_first_' . $tag)->get_data()['state'] === 'offered', 'seller posts dashboard offer');
wp_set_current_user($buyer);
$quoteHtml = $render('b2b-quotes');
check(str_contains($quoteHtml, 'Accept offer') && str_contains($quoteHtml, 'Request revision')
    && str_contains($quoteHtml, 'Offer valid until'), 'quote shows named actions and expiry');
$_SERVER['REQUEST_METHOD'] = 'POST';
$reply = [
    'sfm4u_quote_action' => 'revise', 'sfm4u_quote_id' => $uiId, 'sfm4u_quote_key' => wp_generate_uuid4(),
    'sfm4u_quote_revision' => '0', 'sfm4u_quote_note' => 'Please revise the price.',
    'sfm4u_quote_nonce' => 'bad-token',
];
$_POST = $reply;
check(str_contains($render('b2b-quotes'), 'Refresh the page before responding'), 'dashboard quote action rejects bad nonce');
wp_set_current_user($outsider);
$_POST['sfm4u_quote_nonce'] = wp_create_nonce('sfm4u_quote_' . $uiId);
check(str_contains($render('b2b-quotes'), 'could not be updated'), 'dashboard rejects other buyer with valid nonce');
wp_set_current_user($buyer);
$_POST['sfm4u_quote_nonce'] = wp_create_nonce('sfm4u_quote_' . $uiId);
check(str_contains($render('b2b-quotes'), 'Revision requested.'), 'buyer requests revision via dashboard');
$snapshot = $wpdb->get_var($wpdb->prepare('SELECT snapshot FROM %i WHERE id=%s', $wpdb->prefix . 'sfm4u_b2b_quotes', $uiId));
check(str_contains($snapshot, 'Please revise the price.'), 'revision note persisted in quote history');
$_POST = [];
check(call_api($vendor, 'offer', $uiOffer, 'quote_ui_second_' . $tag)->get_data()['revision'] === 1, 'seller posts new revision');
wp_set_current_user($buyer);
$_POST = [
    'sfm4u_quote_action' => 'accept', 'sfm4u_quote_id' => $uiId, 'sfm4u_quote_key' => wp_generate_uuid4(),
    'sfm4u_quote_revision' => '0', 'sfm4u_quote_nonce' => wp_create_nonce('sfm4u_quote_' . $uiId),
];
check(str_contains($render('b2b-quotes'), 'vendor sent a new offer'), 'dashboard rejects stale offer revision');
$_POST['sfm4u_quote_revision'] = '1';
check(str_contains($render('b2b-quotes'), 'Offer accepted.'), 'buyer accepts revised offer via dashboard');
check(str_contains($render('b2b-quotes'), 'Offer accepted.'), 'dashboard acceptance replay is safe');
$_POST = [];

$settings = new \SFM4U\Wholesale\Pro\TierSettingsPage();
$renderTiers = static function () use ($settings): string {
    ob_start();
    try {
        $settings->render();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
};
$option = \SFM4U\Wholesale\Pro\TierSettingsPage::OPTION;
$priorTiers = get_option($option, []);
$_POST = ['sfm4u_save_tiers' => '1', 'sfm4u_tiers' => "base,0\nsilver,6000\ngold,7000",
    'sfm4u_tier_nonce' => wp_create_nonce('sfm4u_save_tiers')];
$die = static fn() => static function ($message, $title, $arguments): void {
    throw new RuntimeException((string) $message, $arguments['response'] ?? 0);
};
add_filter('wp_die_handler', $die);
$blocked = false;
try {
    $renderTiers();
} catch (RuntimeException $error) {
    $blocked = $error->getCode() === 403;
} finally {
    remove_filter('wp_die_handler', $die);
}
check($blocked && get_option($option, []) === $priorTiers, 'tier settings reject buyer with valid nonce');
wp_set_current_user(1);
check(str_contains($renderTiers(), 'Refresh the page before saving') && get_option($option, []) === $priorTiers,
    'tier settings reject invalid nonce without writes');
$_POST['sfm4u_tier_nonce'] = wp_create_nonce('sfm4u_save_tiers');
$validTiers = $_POST['sfm4u_tiers'];
foreach (["silver,6000", "base,0\nother,0", "base,0\nbase,100", "base,0\nsilver,60.00", "base,0\nsilver,99999999999999999999999"] as $invalid) {
    $_POST['sfm4u_tiers'] = $invalid;
    check(str_contains($renderTiers(), 'aria-invalid="true"') && get_option($option, []) === $priorTiers,
        'tier settings reject invalid thresholds and retain the prior option');
}
$_POST['sfm4u_tiers'] = $validTiers;
check(str_contains($renderTiers(), 'Spend tiers saved.') && get_option($option)[1]['min_spend_minor'] === 6000,
    'tier admin saves exact integer minor units');
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$paidOrder = static function (string $total, int $paid, string $currency = 'BDT') use ($buyer): WC_Order {
    $order = new WC_Order();
    $order->set_customer_id($buyer);
    $order->set_currency($currency);
    $order->set_total($total);
    $order->set_date_paid($paid);
    $order->set_status('completed');
    $order->save();
    return $order;
};
$qualified = $paidOrder('80.00', time() - 60);
$refund = wc_create_refund(['order_id' => $qualified->get_id(), 'amount' => '15.00',
    'refund_payment' => false, 'restock_items' => false]);
check($refund instanceof WC_Order_Refund, 'synthetic partial refund recorded');
$paidOrder('200.00', time() - 366 * DAY_IN_SECONDS);
$paidOrder('200.00', time() - 120, 'USD');
$refreshTier = static function () use ($qualified, $buyer): void {
    $hook = \SFM4U\Wholesale\Pro\WooSpendTiers::HOOK;
    do_action('woocommerce_payment_complete', $qualified->get_id());
    $action = as_next_scheduled_action($hook, [$buyer, $qualified->get_id()], 'sfm4u-b2b');
    check($action !== false, 'Woo paid event queues tier evaluation');
    $ids = as_get_scheduled_actions(['hook' => $hook, 'group' => 'sfm4u-b2b',
        'status' => ActionScheduler_Store::STATUS_PENDING, 'args' => [$buyer, $qualified->get_id()]], 'ids');
    ActionScheduler_QueueRunner::instance()->process_action(reset($ids), 'synthetic-test');
    check(ActionScheduler_Store::instance()->get_status(reset($ids)) === ActionScheduler_Store::STATUS_COMPLETE, 'tier worker completed');
};
$refreshTier();
check(get_user_meta($buyer, '_sfmwh_spend_tier', true) === 'silver',
    'rolling spend upgrades buyer using net refunds and store currency');
Commerce::saveRule($product, ['minor' => 999, 'buyer' => 'tier:silver']);
check(Commerce::ruleForBuyer($product->get_id(), new WP_User($buyer))['minor'] === 999
    && Commerce::ruleForBuyer($product->get_id(), new WP_User($outsider)) === null,
    'earned tier gates wholesale rule only for the qualifying buyer');
$qualified = wc_get_order($qualified->get_id());
$qualified->set_date_paid(time() - 366 * DAY_IN_SECONDS);
$qualified->save();
$refreshTier();
check(get_user_meta($buyer, '_sfmwh_spend_tier', true) === 'silver', 'tier is not downgraded when spend falls out of the window');
delete_option('sfm4u_b2b_spend_tiers');

require '/tests/b2b-po-vendor.php';

if (in_array('dokan', $args ?? [], true)) {
    $pluginRoot = dirname((new ReflectionClass(Commerce::class))->getFileName());
    require_once $pluginRoot . '/BuyerApplication.php';
    require_once $pluginRoot . '/Admin.php';
    $customerId = wp_insert_user(['user_login' => "b2b-customer-$tag", 'user_pass' => wp_generate_password(), 'role' => 'customer']);
    check(is_int($customerId), 'synthetic Woo customer account created');
    $application = \SFM4U\Wholesale\BuyerApplication::class;
    Commerce::saveRule($product, ['minor' => 999, 'buyer' => 'approved']);
    wp_set_current_user($customerId);
    check(!$application::submit('invalid') && $application::status($customerId) === '', 'application requires customer nonce');
    $nonce = wp_create_nonce('sfmwh_buyer_application_' . $customerId);
    check($application::submit($nonce) && $application::submit($nonce)
        && $application::status($customerId) === 'Pending review', 'customer applies once on the current account');
    check(Commerce::ruleForBuyer($product->get_id(), wp_get_current_user()) === null,
        'pending application does not grant wholesale price');
    $_POST['sfmwh_buyer_status'] = 'approved';
    $_POST['sfmwh_buyer_nonce'] = wp_create_nonce('sfmwh_buyer_' . $customerId);
    \SFM4U\Wholesale\Admin::saveBuyerProfile($customerId);
    check($application::status($customerId) === 'Pending review', 'customer cannot approve own application');
    wp_set_current_user(1);
    $_POST['sfmwh_buyer_nonce'] = wp_create_nonce('sfmwh_buyer_' . $customerId);
    \SFM4U\Wholesale\Admin::saveBuyerProfile($customerId);
    unset($_POST['sfmwh_buyer_status'], $_POST['sfmwh_buyer_nonce']);
    check($application::status($customerId) === 'Approved'
        && Commerce::ruleForBuyer($product->get_id(), new WP_User($customerId))['minor'] === 999,
        'staff approval grants the existing customer wholesale pricing');
    dokan_user_update_to_seller(new WP_User($customerId), [
        'shopurl' => "fixture-shop-$tag", 'fname' => 'Synthetic', 'lname' => 'Buyer',
        'shopname' => "Fixture Shop $tag", 'phone' => '5550100', 'address' => [],
    ]);
    check(dokan_is_user_seller($customerId) && $application::status($customerId) === 'Approved'
        && Commerce::ruleForBuyer($product->get_id(), new WP_User($customerId))['minor'] === 999,
        'same user retains wholesale approval after native Dokan vendor migration');
}

echo "Wholesale hold and account integration passed\n";

if (in_array('marketplace-only', $args ?? [], true)) {
    echo "Marketplace integration passed\n";
    return;
}
require '/tests/b2b-supply-finance.php';
require '/tests/b2b-returns-close.php';
require '/tests/b2b-register-sessions.php';
require '/tests/b2b-stock-counts.php';
require '/tests/b2b-native-inventory.php';
require '/tests/b2b-release-security.php';
require '/tests/b2b-native-matrix.php';
require '/tests/b2b-ui-locales.php';
require '/tests/b2b-t15-acceptance.php';
require '/tests/b2b-pos-warehouse-acceptance.php';
require '/tests/b2b-t15-setup-recovery.php';
require '/tests/b2b-t17-reports.php';
require '/tests/b2b-t18-labels.php';
require '/tests/b2b-t19-invoices.php';
require '/tests/b2b-t09-allocation.php';
require '/tests/b2b-t10-credits.php';
require '/tests/b2b-t11-tenders-counts.php';
require '/tests/b2b-t11-baskets.php';
require '/tests/b2b-report-exports.php';
require '/tests/b2b-counter-sales.php';
require_once '/tests/product-meta-security.php';
sfm4u_product_meta_security('check');

if (in_array('statement-html', $args ?? [], true)) {
    wp_set_current_user($buyer);
    $_GET['sfm4u_print'] = '1';
    ob_start();
    (new \SFM4U\Wholesale\Pro\Dashboard($quotes, new \SFM4U\Wholesale\Pro\QuickOrderCsv(new \SFM4U\Wholesale\Pro\WooCatalog())))->printStatement();
    $printHtml = ob_get_clean();
    unset($_GET['sfm4u_print']);
    check(str_contains($printHtml, 'viewport') && str_contains($printHtml, 'BDT 55.00')
        && str_contains($printHtml, 'Due'), 'print-friendly statement uses actual balance and due dates');
    file_put_contents('/tmp/sfm4u-credit-fixture.html', $printHtml);
    $shell = substr($printHtml, 0, strpos($printHtml, '<main>') + strlen('<main>'));
    file_put_contents('/tmp/sfm4u-quotes-fixture.html', $shell . $quoteHtml . '</main></body></html>');
    file_put_contents('/tmp/sfm4u-lists-fixture.html', $shell . $render('b2b-lists') . '</main></body></html>');
}


echo "Settlement and dashboard integration passed\n";
