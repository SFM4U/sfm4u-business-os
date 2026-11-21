<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

final class PosRoles
{
    public const CASHIER = 'sfm4u_pos_cashier';
    private const ROLE_VERSION = 'sfm4u_pos_role_version';
    private const VERSION = '1';

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            self::eachSite([self::class, 'grant']);
            return;
        }
        self::grant();
    }

    public static function upgrade(): void
    {
        if (get_option(self::ROLE_VERSION) !== self::VERSION) {
            self::grant();
        }
    }

    private static function grant(): void
    {
        $administrator = get_role('administrator');
        $manager = get_role('shop_manager');
        $administrator?->add_cap(self::CASHIER);
        $manager?->add_cap(self::CASHIER);
        $cashier = get_role(self::CASHIER) ?? add_role(self::CASHIER, __('POS cashier', 'sfm4u-wholesale'), [
            'read' => true,
            self::CASHIER => true,
        ]);
        if ($cashier && !$cashier->has_cap(self::CASHIER)) {
            $cashier->add_cap(self::CASHIER);
        }
        // WooCommerce may create its role after this plugin first activates.
        if ($administrator && $manager && $cashier) {
            update_option(self::ROLE_VERSION, self::VERSION, false);
        }
    }

    public static function uninstall(): void
    {
        if (is_multisite()) {
            self::eachSite([self::class, 'remove']);
            return;
        }
        self::remove();
    }

    private static function remove(): void
    {
        foreach (['administrator', 'shop_manager'] as $name) {
            get_role($name)?->remove_cap(self::CASHIER);
        }

        $cashiers = get_users(['role' => self::CASHIER, 'fields' => 'ID']);
        foreach ($cashiers as $id) {
            (new \WP_User((int) $id))->remove_role(self::CASHIER);
        }
        $assigned = get_users(['meta_key' => '_sfm4u_pos_registers', 'fields' => 'ID']);
        foreach ($assigned as $id) {
            (new \WP_User((int) $id))->remove_cap(self::CASHIER);
        }
        remove_role(self::CASHIER);
        delete_option(self::ROLE_VERSION);
    }

    private static function eachSite(callable $callback): void
    {
        $offset = 0;
        do {
            $ids = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                try {
                    $callback();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += count($ids);
        } while (count($ids) === 100);
    }
}
