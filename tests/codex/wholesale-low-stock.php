<?php
declare(strict_types=1);

use SFM4U\Wholesale\{LowStock, WorkEngineEvents};

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback synthetic fixture required.');
}

function lowCheck(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_notify_low_stock_amount', 20);
$pending = static fn(): array => as_get_scheduled_actions([
    'hook' => WorkEngineEvents::ACTION, 'group' => WorkEngineEvents::GROUP, 'status' => 'pending', 'per_page' => -1,
], 'ids');
$create = static function (string $name, int $quantity, $threshold, bool $managed = true, string $type = 'simple'): WC_Product {
    $product = $type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_manage_stock($managed);
    $product->set_stock_quantity($quantity);
    $product->set_low_stock_amount($threshold);
    $product->save();
    return $product;
};
wp_set_current_user(1);
$low = $create('Synthetic low & stock', 2, 3);
$equal = $create('Synthetic at threshold', 3, 3);
$unconfigured = $create('Synthetic store threshold only', 1, '');
$unmanaged = $create('Synthetic untracked stock', 1, 3, false);
lowCheck(!WorkEngineEvents::settings()['wholesale_low_stock'] && !$pending(), 'new webhook event is off by default');
$items = static fn(): array => array_map(static fn(WC_Product $p): int => $p->get_id(), LowStock::report()['items']);
$sampleIds = array_map(static fn(WC_Product $p): int => $p->get_id(), [$low, $equal, $unconfigured, $unmanaged]);
lowCheck(array_values(array_intersect($items(), $sampleIds)) === [$low->get_id()],
    'admin excludes equal, unconfigured and unmanaged stock');
ob_start();
LowStock::render();
$html = ob_get_clean();
lowCheck(str_contains($html, 'Synthetic low &amp; stock') && !str_contains($html, $equal->get_name()),
    'admin table escapes names and renders only products below threshold');

$parent = $create('Synthetic shared parent', 2, 4, true, 'variable');
$child = new WC_Product_Variation();
$child->set_parent_id($parent->get_id());
$child->set_low_stock_amount(8);
$child->set_manage_stock(false);
$child->set_status('publish');
$child->save();
$independent = new WC_Product_Variation();
$independent->set_parent_id($parent->get_id());
$independent->set_manage_stock(true);
$independent->set_stock_quantity(1);
$independent->set_low_stock_amount(4);
$independent->set_status('publish');
$independent->save();
lowCheck(in_array($parent->get_id(), $items(), true) && in_array($independent->get_id(), $items(), true)
    && !in_array($child->get_id(), $items(), true), 'shared variation is listed once on its owner and independent variation is listed');

$buyer = wp_create_user('stock-alert-' . wp_generate_uuid4(), wp_generate_password());
wp_set_current_user($buyer);
try {
    LowStock::report();
    throw new RuntimeException('Unexpected buyer access.');
} catch (DomainException $error) {
    lowCheck($error->getCode() === 403, 'buyer cannot read admin stock report');
}
wp_set_current_user(1);

define('SFM4U_WORK_ENGINE_KEY', wp_generate_password(32, false));
$url = 'https://example.test/webhook/stock';
$requests = [];
add_filter('pre_http_request', static function ($pre, $args, $destination) use ($url, &$requests) {
    if ($destination !== $url) {
        return new WP_Error('fixture_network_blocked');
    }
    $requests[] = $args;
    return ['response' => ['code' => 204]];
}, 10, 3);
WorkEngineEvents::saveSettings(true, $url);
WorkEngineEvents::lowStock($low);
$actions = $pending();
$legacy = reset($actions);
lowCheck(count($actions) === 1 && ActionScheduler_Store::instance()->fetch_action($legacy)->get_args()[0] === 'stock.low',
    'existing webhook setup does not opt in to the new event');
WorkEngineEvents::saveSettings(true, $url, true);
ActionScheduler_QueueRunner::instance()->process_action($legacy, 'synthetic');
lowCheck(!$requests, 'switching event route suppresses a queued legacy duplicate');
WorkEngineEvents::lowStock($equal);
lowCheck(!$pending(), 'equality does not enqueue the strict threshold event');
wc_update_product_stock($equal, 2);
WorkEngineEvents::lowStock(wc_get_product($equal->get_id()));
$actions = $pending();
lowCheck(count($actions) === 1 && !$requests, 'stock change queues a unique asynchronous alert');
ActionScheduler_QueueRunner::instance()->process_action(reset($actions), 'synthetic');
lowCheck(count($requests) === 1 && json_decode($requests[0]['body'], true) === [
    'business' => 'algibazar', 'type' => 'wholesale.low_stock', 'record_id' => $equal->get_id(),
] && $requests[0]['headers']['X-SFM4U-Key'] === SFM4U_WORK_ENGINE_KEY
    && $requests[0]['reject_unsafe_urls'] && $requests[0]['redirection'] === 0,
    'new event uses the exact record-only JSON and config key header');

WorkEngineEvents::lowStock(wc_get_product($child->get_id()));
$actions = $pending();
$action = reset($actions);
lowCheck(ActionScheduler_Store::instance()->fetch_action($action)->get_args() === ['wholesale.low_stock', $parent->get_id()],
    'inherited variation alerts use the canonical parent ID');
wc_update_product_stock($parent, 8);
ActionScheduler_QueueRunner::instance()->process_action($action, 'synthetic');
lowCheck(count($requests) === 1, 'restocking suppresses a stale alert at delivery');

WorkEngineEvents::lowStock($independent);
$actions = $pending();
WorkEngineEvents::saveSettings(true, $url);
ActionScheduler_QueueRunner::instance()->process_action(reset($actions), 'synthetic');
lowCheck(count($requests) === 1, 'disabling the dedicated toggle suppresses queued new events');
