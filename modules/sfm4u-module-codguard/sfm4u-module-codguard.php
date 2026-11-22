<?php
/**
 * Plugin Name: SFM4U Module — COD Control (E04)
 * Description: SFM4U Business Software module: cash on delivery limits and fake-order guard (max COD amount, phones with many cancelled orders, block list).
 * Version: 0.1.0
 * Requires Plugins: sfm4u-wholesale
 * Author: SFM4U
 * Requires PHP: 8.1
 */
defined('ABSPATH') || exit;

add_filter('sfm4u_bos_modules', static function (array $list): array {
    require_once __DIR__ . '/src/CodGuard.php';
    $list['codguard'] = ['SFM4U COD Control', 'SFM4U ক্যাশ অন ডেলিভারি নিয়ন্ত্রণ', 'Cash on delivery limits and fake-order guard: maximum COD amount, phones with many cancelled orders, block list.', '', \SFM4U\Modules\CodGuard\CodGuard::class];
    return $list;
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists(\SFM4U\Wholesale\Modules::class) || !class_exists('WooCommerce') || !\SFM4U\Wholesale\Modules::on('codguard')) { return; }
    require_once __DIR__ . '/src/CodGuard.php';
    \SFM4U\Modules\CodGuard\CodGuard::register();
}, 20);
