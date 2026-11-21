<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * Port to the host shop (WooCommerce adapter implements this; tests use an in-memory fake).
 * Keeps this library free of WordPress/Dokan code.
 */
interface CatalogPort
{
    /**
     * @return array{sku:string, name:string, unit_price_minor:int, currency:string,
     *               min_qty:int, step:int, max_qty:?int, pack_size:int, in_stock:int, purchasable:bool}|null
     *         Quantities are in base units; null when the SKU is unknown for this buyer.
     */
    public function findForBuyer(string $sku, string $buyerId): ?array;
}
