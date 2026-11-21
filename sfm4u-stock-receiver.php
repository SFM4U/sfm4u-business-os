<?php
/**
 * Plugin Name: SFM4U Business OS — AlgiBazar Connector
 * Description: Part of SFM4U Business OS. Sellers and customers link their SFM4U member account (My Account → SFM4U membership), sellers ask for the Business / Business Black plan, checkout can take an SFM4U card, sellers see their monthly profit & loss, and it receives Business Black purchases from the SFM4U wholesale shop and adds the goods to the member's own AlgiBazar store (new products as drafts, more stock for existing ones). Off until set to "AlgiBazar marketplace" and paired.
 * Version: 0.8.1-dev
 * Author: SFM4U
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: sfm4u-wholesale
 */
defined('ABSPATH') || exit;
// If the full SFM4U Wholesale plugin is active here, it already contains this feature.
add_action('plugins_loaded', static function (): void {
    if (class_exists('SFM4U\\Wholesale\\Ops\\StockFeed') || !class_exists('WooCommerce')) { return; }
    define('SFM4U_STOCK_ONLY', true);
    if (!class_exists('SFM4U\\Wholesale\\Members\\Members')) { require_once __DIR__ . '/src/Members/Members.php'; }
    if (!class_exists('SFM4U\\Wholesale\\Members\\CardLink')) { require_once __DIR__ . '/src/Members/CardLink.php'; }
    if (!class_exists('SFM4U\\Wholesale\\Members\\Tiers')) { require_once __DIR__ . '/src/Members/Tiers.php'; }
    if (!class_exists('SFM4U\\Wholesale\\Members\\MemberLink')) { require_once __DIR__ . '/src/Members/MemberLink.php'; }
    require_once __DIR__ . '/src/Market/MarketConnect.php';
    require_once __DIR__ . '/src/Market/VendorProfit.php';
    require_once __DIR__ . '/src/Ops/StockFeed.php';
    require_once __DIR__ . '/src/Ops/StockFeedAdmin.php';
    \SFM4U\Wholesale\Ops\StockFeed::register();
    \SFM4U\Wholesale\Ops\StockFeedAdmin::register();
    \SFM4U\Wholesale\Members\CardLink::register();
    \SFM4U\Wholesale\Market\MarketConnect::register();
    \SFM4U\Wholesale\Market\VendorProfit::register();
}, 20);
register_deactivation_hook(__FILE__, static function () { wp_clear_scheduled_hook('sfm_wr_stockfeed_retry'); });
