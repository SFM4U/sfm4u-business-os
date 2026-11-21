<?php
declare(strict_types=1);

use SFM4U\Wholesale\BuyerApplication;
use SFM4U\Wholesale\WorkEngineEvents;

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback synthetic fixture required.');
}

function checkEvent(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$endpoint = 'https://example.test/webhook/fixture';
$requests = [];
$httpStatus = 204;
add_filter('pre_http_request', static function ($pre, $args, $url) use ($endpoint, &$requests, &$httpStatus) {
    if ($url !== $endpoint) {
        return new WP_Error('fixture_network_blocked');
    }
    $requests[] = $args;
    return ['response' => ['code' => $httpStatus]];
}, 10, 3);
$pending = static fn(): array => as_get_scheduled_actions([
    'hook' => WorkEngineEvents::ACTION, 'group' => WorkEngineEvents::GROUP, 'status' => 'pending', 'per_page' => -1,
], 'ids');
$buyer = static fn(string $role = 'customer'): int => wp_insert_user([
    'user_login' => 'synthetic-engine-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => $role,
]);
$submit = static function (int $id): bool {
    wp_set_current_user($id);
    return BuyerApplication::submit(wp_create_nonce('sfmwh_buyer_application_' . $id));
};

$first = $buyer();
checkEvent($submit($first) && !$pending() && !$requests, 'default-disabled application queues and sends nothing');
wp_set_current_user($buyer('shop_manager'));
try {
    WorkEngineEvents::saveSettings(true, $endpoint);
    checkEvent(false, 'shop manager must not configure destination');
} catch (DomainException) {
    checkEvent(!WorkEngineEvents::settings()['enabled'], 'shop manager cannot redirect the authentication header');
}
wp_set_current_user(1);
try {
    WorkEngineEvents::saveSettings(true, 'http://example.test/webhook');
    checkEvent(false, 'HTTP must be rejected');
} catch (DomainException) {
    checkEvent(!WorkEngineEvents::settings()['enabled'], 'admin settings reject a non-HTTPS webhook');
}
WorkEngineEvents::saveSettings(true, $endpoint);
checkEvent($submit($buyer()) && !$pending(), 'enabled settings without a key cannot enqueue');
define('SFM4U_WORK_ENGINE_KEY', wp_generate_password(32, false));

$id = $buyer();
wp_set_current_user($id);
checkEvent(!BuyerApplication::submit('invalid') && !$pending(), 'invalid application nonce emits no event');
checkEvent($submit($id) && $submit($id) && count($pending()) === 1 && !$requests, 'new application queues once, outside HTTP delivery');
$pendingIds = $pending();
$application = reset($pendingIds);
ActionScheduler_QueueRunner::instance()->process_action($application, 'synthetic');
checkEvent(count($requests) === 1 && json_decode($requests[0]['body'], true) === [
    'business' => 'algibazar', 'type' => 'wholesale.request', 'record_id' => $id,
], 'application sends exactly the business, type, and account record ID');

update_option('woocommerce_manage_stock', 'yes');
$product = new WC_Product_Simple();
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->set_low_stock_amount(3);
$product->save();
checkEvent(!$pending(), 'healthy stock queues no event');
wc_update_product_stock($product, 8, 'decrease');
wc_trigger_stock_change_actions(wc_get_product($product->get_id()));
$pendingIds = $pending();
checkEvent(count($pendingIds) === 1 && count($requests) === 1, 'stock decrease and native low hook share one pending action');
ActionScheduler_QueueRunner::instance()->process_action(reset($pendingIds), 'synthetic');
checkEvent(count($requests) === 2 && json_decode($requests[1]['body'], true) === [
    'business' => 'algibazar', 'type' => 'stock.low', 'record_id' => $product->get_id(),
], 'low stock sends only the stock-owner record ID');
checkEvent($requests[1]['method'] === 'POST' && $requests[1]['reject_unsafe_urls'] && $requests[1]['redirection'] === 0
    && $requests[1]['headers']['X-SFM4U-Key'] === SFM4U_WORK_ENGINE_KEY, 'HTTPS sender safeguards and authentication header are set');

wc_update_product_stock($product, 0);
$pendingIds = $pending();
wc_update_product_stock($product, 10);
ActionScheduler_QueueRunner::instance()->process_action(reset($pendingIds), 'synthetic');
checkEvent(count($requests) === 2, 'restocked items suppress stale alerts before delivery');

global $wpdb;
$wpdb->query('START TRANSACTION');
try {
    wc_update_product_stock($product, 1);
    checkEvent(count($pending()) === 1, 'stock mutation queues inside the transaction');
} finally {
    $wpdb->query('ROLLBACK');
    clean_post_cache($product->get_id());
    WC_Cache_Helper::invalidate_cache_group('product_' . $product->get_id());
}
checkEvent(!$pending() && count($requests) === 2, 'rolled-back stock mutations leave no pending webhook');

$httpStatus = 503;
wc_update_product_stock($product, 1);
$pendingIds = $pending();
$failed = reset($pendingIds);
ActionScheduler_QueueRunner::instance()->process_action($failed, 'synthetic');
checkEvent(count($requests) === 3 && !$pending()
    && ActionScheduler_Store::instance()->get_status($failed) === 'failed', 'unconfirmed delivery is visible in Action Scheduler without automatic duplication');

checkEvent($submit($buyer()) && count($pending()) === 1, 'new application queues while enabled');
wp_set_current_user(1);
WorkEngineEvents::saveSettings(false, $endpoint);
$pendingIds = $pending();
ActionScheduler_QueueRunner::instance()->process_action(reset($pendingIds), 'synthetic');
checkEvent(count($requests) === 3, 'disabling stops delivery of already queued actions');
