<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') exit(1);
if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('Expected HPOS fixture.');
}
if (in_array('capture', $args, true)) {
    foreach (wc_get_orders(['limit' => 100, 'created_via' => 'sfm4u-g0-receipt']) as $order) {
        foreach ($order->get_items() as $line) {
            $snapshot = $line->get_meta('_sfmwh_snapshot_v1');
            if (is_array($snapshot) && $snapshot['line_minor'] === 10000) {
                update_option('sfmwh_upgrade_witness', [$order->get_id(), $line->get_id(), $snapshot], false);
                echo "PASS captured persisted pre-upgrade HPOS order\n";
                return;
            }
        }
    }
    throw new RuntimeException('No historical receipt order found.');
}
[$orderId,$lineId,$snapshot] = get_option('sfmwh_upgrade_witness');
$order = wc_get_order($orderId);
if (!$order || $order->get_item($lineId)->get_meta('_sfmwh_snapshot_v1') !== $snapshot) {
    throw new RuntimeException('Original wholesale snapshot changed.');
}
echo "PASS original HPOS order snapshot preserved with installed extension version " .
    get_file_data(WP_PLUGIN_DIR . '/sfm4u-wholesale/sfm4u-wholesale.php', ['version'=>'Version'])['version'] . "\n";
