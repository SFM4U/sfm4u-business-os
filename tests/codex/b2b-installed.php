<?php
declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new LogicException('Local Playground only.');
}

use SFM4U\Wholesale\Commerce;

function installed_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    file_put_contents('/fixture-out/installed-workflow.txt', "PASS $message\n", FILE_APPEND);
}

function installed_call(int $user, string $action, array $payload): WP_REST_Response
{
    wp_set_current_user($user);
    $request = new WP_REST_Request('POST', '/sfm4u-b2b/v1/' . $action);
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_header('Idempotency-Key', wp_generate_uuid4());
    $request->set_body(wp_json_encode($payload));
    return rest_do_request($request);
}

$entry = 'sfm4u-wholesale/sfm4u-wholesale.php';
$phase = getenv('SFM4U_TEST_PHASE');
global $wpdb;
add_filter('pre_wp_mail', '__return_true');
wp_set_current_user(1);
installed_check(is_plugin_active($entry), "$phase plugin active");
$version = get_plugin_data(WP_PLUGIN_DIR . '/' . $entry)['Version'];

if ($phase === 'old' || $phase === 'fresh') {
    if ($phase === 'old') {
        installed_check($version === '0.1.2-dev', 'previous ZIP version loaded');
    } else {
        installed_check($version === '0.2.1-dev' && get_option('sfmwh_b2b_schema') === '1', 'clean ZIP activation creates schema');
    }
    update_option('sfmwh_enabled', 'yes');
    update_option('woocommerce_currency', 'BDT');
    update_option('woocommerce_hold_stock_minutes', '10');
    update_option('woocommerce_custom_orders_table_enabled', 'yes');
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic ZIP upgrade');
    $product->set_sku('ZIP-LIFECYCLE');
    $product->set_regular_price('20.00');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(20);
    $product->save();
    Commerce::saveRule($product, ['minor' => 1500, 'buyer' => 'approved']);
    update_option('sfmwh_zip_test_product', $product->get_id());
    return;
}

$productId = (int) get_option('sfmwh_zip_test_product');
$product = wc_get_product($productId);
installed_check($product && (int) $product->get_stock_quantity() === 20
    && $product->get_meta(Commerce::META)['minor'] === 1500, "$phase preserves stock and wholesale rule");
if ($phase === 'rollback') {
    installed_check($version === '0.1.2-dev' && !class_exists('SFM4U\\Wholesale\\B2BRuntime'), 'rollback restores previous runtime implementation');
    return;
}
if ($phase === 'snapshot') {
    $creditTable = $wpdb->get_var($wpdb->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
        $wpdb->prefix . 'sfm4u_b2b_ledger'
    ));
    installed_check($version === '0.1.2-dev' && !get_option('sfmwh_b2b_schema') && $creditTable === null
        && !get_user_by('login', 'zip-wholesale-buyer'), 'matched database snapshot predates credit accounts and restores previous ZIP');
    return;
}
installed_check($version === '0.2.1-dev' && $wpdb instanceof \SFM4U\Inventory\LossAwareWpdb, 'candidate uses the shared loss-aware database');
if ($phase === 'upgrade') {
    \SFM4U\Wholesale\B2BRuntime::upgrade();
    installed_check(get_option('sfmwh_b2b_schema') === '1', 'active previous installation migrates additively');
    return;
}

installed_check(get_option('sfmwh_b2b_schema') === '1', 'schema initialized on activation');
installed_check(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS active');
installed_check(has_action('woocommerce_account_b2b-credit_endpoint') !== false, 'NET30 statement is registered');
if ($phase === 'seed') {
    $buyer = wp_insert_user(['user_login' => 'zip-wholesale-buyer', 'user_pass' => wp_generate_password(), 'role' => 'customer']);
    $vendor = wp_insert_user(['user_login' => 'zip-seller', 'user_pass' => wp_generate_password(), 'role' => 'seller']);
    installed_check(is_int($buyer) && is_int($vendor), 'synthetic actors created');
    update_user_meta($buyer, '_sfmwh_buyer_status', 'approved');
    update_user_meta($vendor, 'dokan_enable_selling', 'yes');
    update_user_meta($vendor, '_sfmwh_vendor_tools_grant', 'yes');
    wp_update_post(['ID' => $productId, 'post_author' => $vendor]);
    $_POST = ['buyer' => (string) $buyer, 'limit' => '15000', 'days' => '30', 'grace' => '1',
        '_wpnonce' => wp_create_nonce('sfm4u_b2b_save_account_' . $buyer)];
    $_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
    do_action('admin_post_sfm4u_b2b_save_account');
    throw new RuntimeException('Credit admin form did not complete.');
}

$buyer = get_user_by('login', 'zip-wholesale-buyer')->ID;
$vendor = get_user_by('login', 'zip-seller')->ID;
$terms = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE buyer=%d', $wpdb->prefix . 'sfm4u_b2b_accounts', $buyer), ARRAY_A);
installed_check($terms && (int) $terms['credit_limit'] === 15000 && (int) $terms['net_days'] === 30, 'staff credit account form saves NET30 terms');
$lines = [['sku' => 'ZIP-LIFECYCLE', 'qty' => 2]];
$submitted = installed_call($buyer, 'submit', ['lines' => $lines]);
installed_check($submitted->get_status() === 200, 'approved existing customer submits RFQ');
$id = ['id' => $submitted->get_data()['id']];
$offer = installed_call($vendor, 'offer', $id + ['lines' => [$lines[0] + ['unit_price_minor' => 1500]], 'valid_until' => time() + 3600]);
installed_check($offer->get_status() === 200, 'enabled Dokan seller can offer');
installed_check(installed_call($buyer, 'accept', $id)->get_status() === 200, 'buyer accepts offer');
$converted = installed_call($buyer, 'convert', $id);
installed_check($converted->get_status() === 200 && $converted->get_data()['amount_minor'] === 3000, 'installed plugin converts NET30 order');
$order = wc_get_order((int) $converted->get_data()['order_id']);
installed_check($order && $order->get_created_via() === 'sfm4u-b2b-credit' && !$order->needs_payment()
    && (int) wc_get_held_stock_quantity($product) === 2, 'credit order holds stock and excludes ordinary payment');
$line = array_values($order->get_items())[0];
installed_check($line->get_meta(\SFM4U\Wholesale\PurchaseHistory::META) === [
    'suggested_unit_minor' => 2000, 'retail_unit_minor' => 2000,
], 'installed NET30 order snapshots the suggested resale value');
$settled = installed_call(1, 'settle', ['buyer' => $buyer, 'amount_minor' => 500, 'reference' => 'ZIP SYNTHETIC']);
installed_check($settled->get_status() === 200, 'staff records synthetic allocation through installed NET30 route');
