<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

use SFM4U\Inventory\LossAwareWpdb;
use SFM4U\Wholesale\Pro\{CreditAccountsPage, WooCatalog, WooQuotes};

final class B2BRuntime
{
    private const SCHEMA = '1';
    private const SCHEMA_OPTION = 'sfmwh_b2b_schema';
    private const REWRITE_OPTION = 'sfmwh_b2b_refresh_rewrites';
    private const BOOTSTRAP = __DIR__ . '/../modules/b2b-pro/bootstrap.php';

    public static function register(): void
    {
        // Credit orders cannot enter ordinary WooCommerce payment even if B2B is disabled.
        add_filter('woocommerce_valid_order_statuses_for_payment', static function (array $statuses, \WC_Order $order): array {
            return $order->get_created_via() === 'sfm4u-b2b-credit' ? [] : $statuses;
        }, 10, 2);
        if (!self::enabled()) {
            return;
        }
        global $wpdb;
        if (!self::ready()) {
            add_action('admin_init', [self::class, 'upgrade']);
            self::notice();
            return;
        }
        add_filter('user_has_cap', [self::class, 'capabilities'], 10, 4);
        sfm4u_b2b_register($wpdb, \SFM4U\POS\Runtime::enabled());
        (new CreditAccountsPage($wpdb))->register();
        add_action('init', static function (): void {
            if (get_option(self::REWRITE_OPTION)) {
                flush_rewrite_rules(false);
                delete_option(self::REWRITE_OPTION);
            }
        }, 99);
    }

    public static function upgrade(): void
    {
        if (current_user_can('activate_plugins')) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        if (!self::enabled() || !class_exists('WooCommerce') || !self::load() || !self::compatibleDatabase()) {
            return;
        }
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA) {
            \SFM4U\POS\Runtime::install($wpdb);
            return;
        }
        try {
            // WooCommerce's legacy and HPOS tables and WP order-note tables participate in writes.
            $tables = [$wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta];
            foreach (['woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_reserved_stock',
                'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta'] as $suffix) {
                $tables[] = $wpdb->prefix . $suffix;
            }
            foreach ($tables as $table) {
                $engine = $wpdb->get_var($wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                    $table
                ));
                if (strtolower((string) $engine) !== 'innodb') {
                    return;
                }
            }
            (new WooQuotes($wpdb, new WooCatalog()))->install();
            update_option(self::SCHEMA_OPTION, self::SCHEMA);
            update_option(self::REWRITE_OPTION, true);
            \SFM4U\POS\Runtime::install($wpdb);
        } catch (\Throwable) {
            // Leave the runtime disabled until a later schema setup succeeds.
        }
    }

    public static function deactivate(): void
    {
        update_option(self::REWRITE_OPTION, true);
        delete_option('rewrite_rules');
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('sfm4u_b2b_reconcile_stock_holds', [], 'sfm4u-b2b');
            as_unschedule_all_actions('sfm4u_native_inventory_sweep', [], 'sfm4u-inventory');
            // Single retries carry per-event arguments; remove the complete inventory group.
            if (function_exists('as_get_scheduled_actions')) {
                foreach (as_get_scheduled_actions(['hook' => 'sfm4u_native_inventory_retry', 'group' => 'sfm4u-inventory',
                    'status' => 'pending', 'per_page' => -1]) as $action) {
                    as_unschedule_all_actions('sfm4u_native_inventory_retry', $action->get_args(), 'sfm4u-inventory');
                }
            }
        }
    }

    public static function capabilities(array $caps, array $required, array $args, \WP_User $user): array
    {
        if (in_array('sfm4u_b2b_buy', $required, true)) {
            $caps['sfm4u_b2b_buy'] = get_user_meta($user->ID, '_sfmwh_buyer_status', true) === 'approved';
        }
        if (in_array('sfm4u_b2b_offer', $required, true) && function_exists('dokan_is_user_seller')) {
            $caps['sfm4u_b2b_offer'] = dokan_is_user_seller($user->ID) && dokan_is_seller_enabled($user->ID);
        }
        if (in_array('sfm4u_b2b_settle', $required, true) && !empty($caps['manage_options'])) {
            $caps['sfm4u_b2b_settle'] = true;
        }
        return $caps;
    }

    private static function enabled(): bool
    {
        return defined('SFM4U_B2B_ENABLED') && SFM4U_B2B_ENABLED === true
            && (!class_exists(Modules::class) || Modules::on('wholesale') || Modules::on('pos'));
    }

    private static function load(): bool
    {
        if (!is_readable(self::BOOTSTRAP)) {
            return false;
        }
        require_once self::BOOTSTRAP;
        return true;
    }

    private static function ready(): bool
    {
        return self::load() && self::compatibleDatabase()
            && get_option(self::SCHEMA_OPTION) === self::SCHEMA;
    }

    private static function compatibleDatabase(): bool
    {
        global $wpdb;
        return $wpdb instanceof LossAwareWpdb && defined(LossAwareWpdb::class . '::API_VERSION')
            && LossAwareWpdb::API_VERSION === 1 && method_exists($wpdb, 'transactionFailure');
    }

    private static function notice(): void
    {
        add_action('admin_notices', static function (): void {
            if (current_user_can('activate_plugins') && !self::ready()) {
                echo '<div class="notice notice-warning"><p>' . esc_html__(
                    'SFM4U B2B requires the loss-aware database connection and initialized InnoDB tables.',
                    'sfm4u-wholesale'
                ) . '</p></div>';
            }
        });
    }
}
