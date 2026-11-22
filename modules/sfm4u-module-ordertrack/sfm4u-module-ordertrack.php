<?php
/**
 * Plugin Name: SFM4U Module — Order Tracking (E07)
 * Description: SFM4U Business Software module: customers track an order with the order number and phone. Shortcode [sfm4u_order_tracking].
 * Version: 0.1.0
 * Requires Plugins: sfm4u-wholesale
 * Author: SFM4U
 * Requires PHP: 8.1
 */
defined('ABSPATH') || exit;

add_filter('sfm4u_bos_modules', static function (array $list): array {
    require_once __DIR__ . '/src/OrderTrack.php';
    $list['ordertrack'] = ['SFM4U Order Tracking', 'SFM4U অর্ডার ট্র্যাকিং', 'Customers see where their order is with the order number and phone; courier tracking code when booked.', '', \SFM4U\Modules\OrderTrack\OrderTrack::class];
    return $list;
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists(\SFM4U\Wholesale\Modules::class) || !class_exists('WooCommerce') || !\SFM4U\Wholesale\Modules::on('ordertrack')) { return; }
    require_once __DIR__ . '/src/OrderTrack.php';
    \SFM4U\Modules\OrderTrack\OrderTrack::register();
}, 20);
