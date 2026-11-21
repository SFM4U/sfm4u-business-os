<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Erp;

defined('ABSPATH') || exit;

/** Where website stock lives (WooCommerce in production, an array in tests). */
interface StockStore {
    /** @return array<string,float> SKU => quantity, managed-stock products only */
    public function quantities(): array;
    public function setQuantity(string $sku, float $qty): void;
    /** @return array<string,array{name:string,price:float}> SKU => product name and price (for creating ERPNext items) */
    public function details(): array;
}

final class WooStockStore implements StockStore {
    public function quantities(): array {
        $out = [];
        $page = 1;
        do {
            $ids = wc_get_products(['limit' => 200, 'page' => $page, 'return' => 'ids', 'type' => ['simple', 'variation'], 'status' => ['publish', 'private']]);
            foreach ($ids as $id) {
                $p = wc_get_product($id);
                if (!$p || !$p->managing_stock() || $p->get_sku() === '') { continue; }
                $out[$p->get_sku()] = (float) $p->get_stock_quantity();
            }
            $page++;
        } while (count($ids) === 200 && $page < 200);
        return $out;
    }

    public function details(): array {
        $out = [];
        foreach (array_keys($this->quantities()) as $sku) {
            $p = wc_get_product(wc_get_product_id_by_sku((string) $sku));
            if ($p) { $out[(string) $sku] = ['name' => wp_strip_all_tags($p->get_name()), 'price' => (float) $p->get_price()]; }
        }
        return $out;
    }

    public function setQuantity(string $sku, float $qty): void {
        $id = wc_get_product_id_by_sku($sku);
        if ($id) { wc_update_product_stock($id, $qty, 'set'); }
    }
}

final class ArrayStockStore implements StockStore {
    /** @param array<string,float> $q */
    public function __construct(public array $q) {}
    public function quantities(): array { return $this->q; }
    public function setQuantity(string $sku, float $qty): void { $this->q[$sku] = $qty; }
    public function details(): array { $o = []; foreach ($this->q as $k => $v) { $o[(string) $k] = ['name' => 'Item ' . $k, 'price' => 10.0]; } return $o; }
}
