<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

use SFM4U\Wholesale\Commerce;

final class WooCatalog implements CatalogPort
{
    public function findForBuyer(string $sku, string $buyerId): ?array
    {
        $buyer = new \WP_User((int) $buyerId);
        $product = wc_get_product(wc_get_product_id_by_sku($sku));
        if (!$buyer->exists() || !$product || !$product->is_type(['simple', 'variation'])) {
            return null;
        }
        $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        if (!$parent || $parent->get_status() !== 'publish' || $parent->get_catalog_visibility() === 'hidden'
            || $product->get_status() !== 'publish') {
            return null;
        }
        $vendor = (int) get_post_field('post_author', $parent->get_id());
        if (function_exists('dokan_is_user_seller') && dokan_is_user_seller($vendor)
            && (!function_exists('dokan_is_seller_enabled') || !dokan_is_seller_enabled($vendor))) {
            return null;
        }
        $rule = Commerce::ruleForBuyer($product->get_id(), $buyer);
        if (!$rule) {
            return null;
        }
        if ($rule['scale'] !== 2) {
            throw new \DomainException('currency_precision_unsupported');
        }
        $stockOwner = wc_get_product($product->get_stock_managed_by_id());
        $stock = $stockOwner && $stockOwner->managing_stock()
            ? max(0, (int) $stockOwner->get_stock_quantity() - (int) wc_get_held_stock_quantity($stockOwner))
            : 0;
        $packs = $rule['packs'];
        $genericPack = isset($packs['pack']) ? 'pack' : (count($packs) === 1 ? array_key_first($packs) : null);
        return ['sku' => strtoupper($product->get_sku()), 'name' => $product->get_name(),
            'product_id' => $product->get_id(), 'vendor_id' => $vendor,
            'unit_price_minor' => $rule['minor'], 'currency' => $rule['currency'], 'tiers' => $rule['tiers'],
            'min_qty' => $rule['min'], 'step' => $rule['step'], 'max_qty' => $rule['max'],
            'pack_size' => $genericPack ? $packs[$genericPack] : 0, 'pack_sizes' => $packs,
            'pack_aliases' => $genericPack ? ['pack' => $genericPack] : [],
            'pack_prices' => $rule['pack_minor'], 'in_stock' => $stock,
            'purchasable' => $product->is_in_stock() && $product->get_price() !== ''];
    }
}
