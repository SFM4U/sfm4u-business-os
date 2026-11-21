<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * Saved purchase lists ("my monthly order"). Reorder re-checks today's price/stock
 * through the catalog — never trusts the price saved in the list.
 */
final class PurchaseList
{
    /** @var array<string,int> sku => qty */
    private array $items = [];

    public function __construct(public readonly string $id, public readonly string $buyerId, public string $name) {}

    public function set(string $sku, int $qty): void
    {
        $sku = strtoupper(trim($sku));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $sku)) { throw new \InvalidArgumentException('sku_invalid'); }
        if ($qty <= 0) { unset($this->items[$sku]); return; }
        $this->items[$sku] = $qty;
    }

    /** @return array<string,int> */
    public function items(): array { return $this->items; }

    /** Build quick-order text and validate it with the same rules as CSV quick order. */
    public function reorder(QuickOrderCsv $quick): array
    {
        $csv = '';
        foreach ($this->items as $sku => $qty) { $csv .= $sku . ',' . $qty . "\n"; }
        return $quick->parse($csv, $this->buyerId);
    }
}
