<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;

final class StockValuation
{
    public const MINOR_TO_MICROS = 10000;
    private string $values;
    private string $lots;
    private string $sales;
    private string $returns;

    public function __construct(private \wpdb $db)
    {
        $this->values = $db->prefix . 'sfm4u_inventory_values';
        $this->lots = $db->prefix . 'sfm4u_inventory_receipt_lots';
        $this->sales = $db->prefix . 'sfm4u_inventory_sale_costs';
        $this->returns = $db->prefix . 'sfm4u_inventory_return_costs';
    }

    public function install(): array
    {
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->lots} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            receipt char(36) NOT NULL,
            purchase_order_id bigint unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            received bigint unsigned NOT NULL,
            remaining bigint unsigned NOT NULL,
            goods_minor bigint unsigned NOT NULL,
            landed_minor bigint unsigned NOT NULL,
            late_minor bigint unsigned DEFAULT NULL,
            late_stock_micros bigint unsigned DEFAULT NULL,
            late_cogs_micros bigint unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY receipt_product (receipt,product_id),
            KEY product_fifo (product_id,id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->sales} (
            order_item_id bigint unsigned NOT NULL,
            order_id bigint unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            quantity bigint unsigned NOT NULL,
            cogs_micros bigint unsigned NOT NULL,
            PRIMARY KEY  (order_item_id),
            KEY product_ref (product_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->returns} (
            refund_id bigint unsigned NOT NULL,
            order_item_id bigint unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            quantity bigint unsigned NOT NULL,
            reversed_micros bigint unsigned NOT NULL,
            writeoff_micros bigint unsigned NOT NULL,
            PRIMARY KEY  (refund_id,order_item_id),
            KEY original_line (order_item_id)
        ) ENGINE=InnoDB {$charset};");
        return [$this->lots, $this->sales, $this->returns];
    }

    public function recordReceipt(string $receipt, int $po, int $product, int $quantity, int $goods, int $landed): void
    {
        $this->write($this->db->insert($this->lots, ['receipt' => $receipt, 'purchase_order_id' => $po,
            'product_id' => $product, 'received' => $quantity, 'remaining' => $quantity, 'goods_minor' => $goods, 'landed_minor' => $landed]));
    }

    /** Run on the same transaction after WooCommerce reduced the order stock. */
    public function capture(\WC_Order $order): void
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                throw new DomainException('Order contains a missing stock product.', 409);
            }
            $id = $product->get_stock_managed_by_id();
            $prior = $this->value($id);
            if (!$prior) {
                continue;
            }
            if ($this->db->get_var($this->db->prepare('SELECT order_item_id FROM %i WHERE order_item_id=%d', $this->sales, $item->get_id()))) {
                continue;
            }
            $quantity = self::reducedQuantity($this->db, $item->get_id());
            if ($quantity < 1 || $quantity !== (int) $item->get_quantity() || $this->lockedStock($id) !== (int) $prior['quantity'] - $quantity) {
                throw new DomainException('Sale stock and valuation do not reconcile.', 409);
            }
            $lots = $this->lotsForProduct($id, (int) $prior['quantity']);
            $pool = $this->pool($prior);
            $snapshot = $pool->sell($quantity);
            $this->consumeLots($lots, $quantity);
            $this->store($id, $pool);
            $this->write($this->db->insert($this->sales, ['order_item_id' => $item->get_id(), 'order_id' => $order->get_id(),
                'product_id' => $id, 'quantity' => $quantity, 'cogs_micros' => $snapshot['cogs_micros']]));
        }
    }

    public function captureReturn(\WC_Order_Item_Product $item, int $refund, int $returned, int $quantity, bool $resellable): void
    {
        $product = $item->get_product();
        $id = $product ? $product->get_stock_managed_by_id() : 0;
        $prior = $this->value($id);
        if (!$prior) {
            return; // A legacy sale without a valuation pool keeps its previous behavior.
        }
        $sale = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE order_item_id=%d FOR UPDATE',
            $this->sales, $item->get_id()), ARRAY_A);
        $recorded = $this->db->get_var($this->db->prepare('SELECT SUM(quantity) FROM %i WHERE order_item_id=%d',
            $this->returns, $item->get_id()));
        if (!$sale || (int) $sale['product_id'] !== $id || (int) $recorded !== $returned
            || $this->lockedStock($id) !== (int) $prior['quantity'] + ($resellable ? $quantity : 0)) {
            throw new DomainException('Return stock and original cost do not reconcile.', 409);
        }
        $this->lotsForProduct($id, (int) $prior['quantity']);
        $pool = $resellable ? $this->pool($prior) : new MovingAverage();
        $cost = $pool->returnCost(['schema' => 1, 'quantity' => (int) $sale['quantity'],
            'cogs_micros' => (int) $sale['cogs_micros']], $returned, $quantity, true);
        if ($resellable) {
            $this->store($id, $pool);
            // A resellable return becomes saleable at the immutable original cost.
            $this->recordReceipt(wp_generate_uuid4(), 0, $id, $quantity, 0, 0);
        }
        $this->write($this->db->insert($this->returns, ['refund_id' => $refund, 'order_item_id' => $item->get_id(),
            'product_id' => $id, 'quantity' => $quantity, 'reversed_micros' => $cost, 'writeoff_micros' => $resellable ? 0 : $cost]));
    }

    /** The caller holds the PO lock and provides a validated minor-unit amount. */
    public function lateCost(string $receipt, int $po, int $id, int $minor): array
    {
        $stock = $this->lockedStock($id);
        $prior = $this->value($id);
        if (!$prior || $stock !== (int) $prior['quantity']) {
            throw new DomainException('Reconcile stock and cost before adjusting this receipt.', 409);
        }
        $this->lotsForProduct($id, $stock);
        $lot = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE receipt=%s AND purchase_order_id=%d AND product_id=%d FOR UPDATE',
            $this->lots, $receipt, $po, $id), ARRAY_A);
        if (!$lot || $lot['late_minor'] !== null) {
            throw new DomainException('Receipt line is missing or already adjusted.', 409);
        }
        $pool = $this->pool($prior);
        $parts = $pool->lateLandedCost($minor * self::MINOR_TO_MICROS, (int) $lot['received'], (int) $lot['remaining']);
        if ($parts['stock_micros'] > 0) {
            $this->store($id, $pool);
        }
        $this->write($this->db->update($this->lots, ['late_minor' => $minor, 'late_stock_micros' => $parts['stock_micros'],
            'late_cogs_micros' => $parts['expense_micros']], ['id' => $lot['id']]));
        return ['stock_micros' => $parts['stock_micros'], 'cogs_adjustment_micros' => $parts['expense_micros']] + $pool->state();
    }

    public function receiptCost(string $receipt, int $po): int
    {
        $amount = $this->db->get_var($this->db->prepare(
            'SELECT SUM(goods_minor+landed_minor+COALESCE(late_minor,0)) FROM %i WHERE receipt=%s AND purchase_order_id=%d',
            $this->lots, $receipt, $po
        ));
        return (int) $amount;
    }

    public function totals(int $product): array
    {
        $prior = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE product_id=%d', $this->values, $product), ARRAY_A);
        return [
            'quantity' => (int) ($prior['quantity'] ?? 0),
            'stock_micros' => (int) ($prior['value_micros'] ?? 0),
            'cogs_micros' => (int) $this->db->get_var($this->db->prepare('SELECT SUM(cogs_micros) FROM %i WHERE product_id=%d',
                $this->sales, $product)),
            'cogs_adjustment_micros' => (int) $this->db->get_var($this->db->prepare('SELECT SUM(late_cogs_micros) FROM %i WHERE product_id=%d',
                $this->lots, $product)),
        ];
    }

    public static function reducedQuantity(\wpdb $db, int $item): int
    {
        // Woo status hooks can reduce stock through a separately loaded order.
        $value = $db->get_var($db->prepare(
            "SELECT meta_value FROM %i WHERE order_item_id=%d AND meta_key='_reduced_stock'",
            $db->prefix . 'woocommerce_order_itemmeta', $item
        ));
        return preg_match('/^[0-9]{1,7}$/D', (string) $value) ? (int) $value : 0;
    }

    private function value(int $id): ?array
    {
        return $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE product_id=%d FOR UPDATE', $this->values, $id), ARRAY_A);
    }

    public function lockedStock(int $id): int
    {
        $rows = $this->db->get_col($this->db->prepare(
            "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_stock' FOR UPDATE", $this->db->postmeta, $id
        ));
        if (count($rows) !== 1 || !preg_match('/^([0-9]{1,7})(?:\.0+)?$/D', (string) $rows[0], $parts)) {
            throw new DomainException('Expected one whole nonnegative WooCommerce stock balance.', 409);
        }
        return (int) $parts[1];
    }

    /** Apply a count variance after taking the Woo stock lock, in the caller's journal transaction. */
    public function adjustCount(int $id, int $before, int $counted, string $receipt): int
    {
        $prior = $this->value($id);
        if (!$prior || (int) $prior['quantity'] !== $before || $prior['currency'] !== get_woocommerce_currency()) {
            throw new DomainException('Reconcile WooCommerce stock and valuation before approval.', 409);
        }
        $lots = $this->lotsForProduct($id, $before);
        $pool = $this->pool($prior);
        $delta = $counted - $before;
        if ($delta === 0) {
            return 0;
        }
        if ($delta > 0) {
            $cost = $pool->receiveAtAverage($delta);
            // An internal stock-count lot has no supplier liability.
            $this->recordReceipt($receipt, 0, $id, $delta, 0, 0);
        } else {
            $cost = -$pool->sell(-$delta)['cogs_micros'];
            $this->consumeLots($lots, -$delta);
        }
        $this->store($id, $pool);
        return $cost;
    }

    public function lockedBookQuantity(int $id): int
    {
        $prior = $this->value($id);
        if (!$prior || $prior['currency'] !== get_woocommerce_currency()) {
            throw new DomainException('A matching warehouse valuation is required for reconciliation.', 409);
        }
        return (int) $prior['quantity'];
    }

    public function hasValue(int $id): bool
    {
        $value = $this->db->get_var($this->db->prepare(
            'SELECT product_id FROM %i WHERE product_id=%d', $this->values, $id
        ));
        if ($this->db->last_error !== '') throw new RuntimeException('Inventory valuation could not be read.');
        return $value !== null;
    }

    /** Change native Woo stock and its value under the caller's journal transaction. */
    public function nativeSale(int $id, int $quantity): array
    {
        $stock = $this->lockedStock($id);
        $prior = $this->value($id);
        if (!$prior || (int) $prior['quantity'] !== $stock || $prior['currency'] !== get_woocommerce_currency()) {
            throw new DomainException('Reconcile stock and valuation before posting this order.', 409);
        }
        $lots = $this->lotsForProduct($id, $stock);
        $pool = $this->pool($prior);
        $cost = $pool->sell($quantity);
        $this->consumeLots($lots, $quantity);
        $this->store($id, $pool);
        $this->changeStock($id, -$quantity, $stock - $quantity);
        return $cost;
    }

    public function nativeReturn(int $id, array $sale, int $alreadyReturned, int $quantity, bool $restock): int
    {
        $stock = $this->lockedStock($id);
        $prior = $this->value($id);
        if (!$prior || (int) $prior['quantity'] !== $stock || $prior['currency'] !== get_woocommerce_currency()) {
            throw new DomainException('Reconcile stock and valuation before posting this return.', 409);
        }
        $this->lotsForProduct($id, $stock);
        $pool = $restock ? $this->pool($prior) : new MovingAverage();
        $cost = $pool->returnCost($sale, $alreadyReturned, $quantity, true);
        if ($restock) {
            $this->store($id, $pool);
            $this->recordReceipt(wp_generate_uuid4(), 0, $id, $quantity, 0, 0);
            $this->changeStock($id, $quantity, $stock + $quantity);
        }
        return $cost;
    }

    private function changeStock(int $id, int $delta, int $expected): void
    {
        $result = wc_update_product_stock($id, abs($delta), $delta > 0 ? 'increase' : 'decrease');
        if ($result === false || $result === null || $this->lockedStock($id) !== $expected) {
            throw new RuntimeException('WooCommerce stock and the posted inventory quantity differ.');
        }
    }

    private function consumeLots(array $lots, int $remaining): void
    {
        foreach ($lots as $lot) {
            $taken = min((int) $lot['remaining'], $remaining);
            $this->write($this->db->update($this->lots, ['remaining' => (int) $lot['remaining'] - $taken], ['id' => $lot['id']]));
            $remaining -= $taken;
            if ($remaining === 0) {
                break;
            }
        }
    }

    private function lotsForProduct(int $product, int $quantity): array
    {
        $lots = $this->db->get_results($this->db->prepare(
            'SELECT id,remaining FROM %i WHERE product_id=%d AND remaining>0 ORDER BY id FOR UPDATE', $this->lots, $product
        ), ARRAY_A);
        if (array_sum(array_column($lots, 'remaining')) !== $quantity) {
            throw new DomainException('Receipt quantities and valuation do not reconcile.', 409);
        }
        return $lots;
    }

    private function pool(array $prior): MovingAverage
    {
        $pool = new MovingAverage();
        if ((int) $prior['quantity'] > 0) {
            $pool->receive((int) $prior['quantity'], (int) $prior['value_micros']);
        } elseif ((int) $prior['value_micros'] !== 0) {
            throw new DomainException('Stock value must be zero when stock is zero.', 409);
        }
        return $pool;
    }

    private function store(int $id, MovingAverage $pool): void
    {
        $state = $pool->state();
        $this->write($this->db->update($this->values, ['quantity' => $state['quantity'], 'value_micros' => $state['value_micros']],
            ['product_id' => $id]));
    }

    private function write(int|false $result): void
    {
        if ($result !== 1) {
            throw new RuntimeException('Inventory valuation write failed.');
        }
    }
}
