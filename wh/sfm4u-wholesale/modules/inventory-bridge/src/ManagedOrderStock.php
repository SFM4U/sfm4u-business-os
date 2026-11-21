<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

final class ManagedOrderStock
{
    private static ?int $refunding = null;
    private static ?int $reducing = null;

    public static function register(): void
    {
        add_filter('woocommerce_can_restore_order_stock', static fn(bool $allowed, \WC_Order $order): bool =>
            $allowed && !self::managed($order), 100, 2);
        add_filter('woocommerce_can_reduce_order_stock', static fn(bool $allowed, \WC_Order $order): bool =>
            $allowed && (!self::managed($order) || self::$reducing === $order->get_id()), 100, 2);
        add_filter('woocommerce_can_restock_refunded_items', static fn(bool $allowed, \WC_Order $order): bool =>
            $allowed && (!self::managed($order) || self::$refunding === $order->get_id()), 100, 2);
    }

    public static function reduce(\WC_Order $order, callable $operation): void
    {
        $previous = self::$reducing;
        self::$reducing = $order->get_id();
        try {
            $operation();
        } finally {
            self::$reducing = $previous;
        }
    }

    public static function refund(array $args): \WC_Order_Refund|\WP_Error
    {
        $previous = self::$refunding;
        self::$refunding = (int) $args['order_id'];
        try {
            return wc_create_refund($args);
        } finally {
            self::$refunding = $previous;
        }
    }

    private static function managed(\WC_Order $order): bool
    {
        return in_array($order->get_created_via(), ['sfm4u-b2b-credit', 'sfm4u-pos-fixture'], true);
    }
}
