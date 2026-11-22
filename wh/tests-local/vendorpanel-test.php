<?php
use SFM4U\Modules\Stores\Stores;
use SFM4U\Modules\VendorPanel\VendorPanel;
use SFM4U\Wholesale\Modules;

if (PHP_SAPI !== 'cli' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Synthetic loopback fixture required.');
}
$pass = $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { ++$pass; return; }
    ++$fail;
    echo "FAIL $label\n";
};
wp_set_current_user(1);
$a = wp_create_user('vendor-panel-fixture-a', wp_generate_password());
$b = wp_create_user('vendor-panel-fixture-b', wp_generate_password());
Stores::update(0, ['name' => 'Vendor A fixture', 'owner_id' => $a]);
Stores::update(0, ['name' => 'Vendor B fixture', 'owner_id' => $b]);
Modules::grant($a, 'stores');
Modules::grant($a, 'vendorpanel');
Modules::grant($b, 'vendorpanel');
$check(Modules::all()['vendorpanel'][4] === VendorPanel::class, 'module registered from main plugin');
$call = static function (int $id): WP_REST_Response {
    wp_set_current_user($id);
    return rest_do_request(new WP_REST_Request('GET', '/sfm4u/v1/vendorpanel'));
};
$check($call(0)->get_status() === 401, 'anonymous access denied');
$check($call($b)->get_status() === 403, 'seller needs both grants');
$reply = $call($a);
$check($reply->get_status() === 200 && array_column($reply->get_data()['stores'], 'name') === ['Vendor A fixture'],
    'granted seller receives only assigned shops');
$check(($reply->get_headers()['Cache-Control'] ?? '') === 'private, no-store', 'private response forbids caching');
$reply = $call(1);
$check($reply->get_status() === 200 && count($reply->get_data()['stores']) === 2, 'administrator sees the two fixture shops');
Modules::set('vendorpanel', false);
$check($call($a)->get_status() === 403, 'disabled endpoint rejects requests');
echo "pass $pass fail $fail\n";
if ($fail) { exit(1); }
