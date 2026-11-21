<?php
/**
 * Plugin Name: SFM4U Business OS — Membership Card
 * Description: SFM4U member cards for any WordPress site, no WooCommerce needed (for account.sfm4u.com): every new account gets a card, members see and print their card, fill details from their NID, sign on their phone, and read the card guide and rules. Shortcodes: [sfm4u_member_card], [sfm4u_card_guide].
 * Version: 0.7.4-dev
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Author: SFM4U
 * License: GPL-2.0-or-later
 * Text Domain: sfm4u-wholesale
 */
defined('ABSPATH') || exit;

// Same code as the member-card part of "SFM4U Wholesale & Retail". Load it only once per site.
if (!class_exists('SFM4U\\Wholesale\\Members\\Members')) {
    define('SFM4U_MEMBERS_FROM', 'card');
    foreach (['Members', 'Barcode', 'BdMapData', 'BdMap', 'CardView', 'CardGuide', 'CardLink', 'Receipt', 'MembersAdmin', 'MembersFront', 'Rewards', 'Tiers', 'MemberLink'] as $sfm4uMembersFile) {
        require_once __DIR__ . '/src/Members/' . $sfm4uMembersFile . '.php';
    }
    unset($sfm4uMembersFile);
}

add_action('init', static function () {
    load_plugin_textdomain('sfm4u-wholesale', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
add_action('plugins_loaded', static function () {
    \SFM4U\Wholesale\Members\Members::boot();
}, 20);
register_activation_hook(__FILE__, static function () {
    \SFM4U\Wholesale\Members\Members::maybeInstall();
});
