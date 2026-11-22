<?php
use SFM4U\Modules\Returns\Returns;
use SFM4U\Wholesale\{ModuleHome, Modules};

if (!defined('WP_CLI') || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback fixture required.');
}
require_once WP_PLUGIN_DIR . '/sfm4u-wholesale/modules/returns/bootstrap.php';
$pass = $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { ++$pass; return; }
    ++$fail;
    echo "FAIL $label\n";
};
$GLOBALS['wp_rest_server'] = null;
$check(Modules::all()['returns'][4] === Returns::class && count(ModuleHome::dashboard('returns')['help']) === 2, 'contract');
$buyer = wp_create_user('returns-buyer-' . wp_generate_uuid4(), wp_generate_password());
$outsider = wp_create_user('returns-other-' . wp_generate_uuid4(), wp_generate_password());
$product = new WC_Product_Simple();
$product->set_name('Synthetic returns item');
$product->set_regular_price('20.00');
$product->save();
$order = wc_create_order(['customer_id' => $buyer]);
$line = $order->add_product($product, 1);
$order->calculate_totals();
$order->save();
$call = static function (string $method, int $user, array $data = [], bool $nonce = true) use ($order, $line): WP_REST_Response {
    wp_set_current_user($user);
    $request = new WP_REST_Request($method, '/sfm4u/v1/returns/' . $order->get_id() . '/' . $line);
    $request->set_header('Content-Type', 'application/json');
    if ($nonce) {
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    }
    $request->set_body(wp_json_encode($data));
    $response = rest_do_request($request);
    if ($response->get_status() >= 400) { echo 'HTTP ' . $response->get_status() . ' ' . ($response->get_data()['code'] ?? '') . "\n"; }
    return $response;
};
$body = ['kind' => 'return', 'reason' => 'Synthetic damage'];
$check($call('POST', $outsider, $body)->get_status() === 403, 'unrelated customer cannot submit');
$check($call('POST', $buyer, $body, false)->get_status() === 403, 'nonce required');
$check($call('POST', $buyer, $body + ['photos' => [99999999]])->get_status() === 422, 'invalid image refused');
$check($call('POST', $buyer, $body)->get_status() === 200, 'buyer submits');
$check($call('POST', $buyer, $body)->get_status() === 200, 'same request is replayable');
$check($call('POST', $buyer, array_replace($body, ['reason' => 'Changed']))->get_status() === 409, 'conflicting request refused');
$check($call('GET', $outsider)->get_status() === 403, 'unrelated customer cannot read');
$close = ['action' => 'resolve', 'outcome' => 'refunded', 'refund_id' => 123];
$check($call('POST', $buyer, $close)->get_status() === 403, 'buyer cannot resolve');
$check($call('POST', 1, $close)->get_status() === 422, 'manager cannot invent a refund');
$refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => 20,
    'line_items' => [$line => ['qty' => 1, 'refund_total' => 20, 'refund_tax' => []]]]);
$close['refund_id'] = $refund->get_id();
$check($call('POST', 1, $close)->get_status() === 200, 'manager can link existing refund');
$check($call('POST', 1, $close)->get_status() === 200 && count(wc_get_order($order->get_id())->get_refunds()) === 1, 'retry does not refund again');
$check(($call('GET', $buyer)->get_data()['refund_id'] ?? null) === $refund->get_id(), 'buyer sees outcome');
$check($call('POST', 1, ['action' => 'resolve', 'outcome' => 'declined'])->get_status() === 409, 'outcome cannot be overwritten');
wp_set_current_user(1);
Modules::set('returns', false);
$check($call('GET', $buyer)->get_status() === 403, 'disabled module fails closed');
echo "pass $pass fail $fail\n";
if ($fail) { exit(1); }
