<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use SFM4U\Wholesale\Rule;

require_once __DIR__ . '/LandedCostAllocation.php';

final class PurchaseOrders
{
    private const MINOR_TO_MICROS = 10000;
    private string $orders;
    private string $values;
    private RequestJournal $journal;
    private StockValuation $valuation;
    private SupplierPayables $payables;

    public function __construct(private \wpdb $db)
    {
        $this->orders = $db->prefix . 'sfm4u_supplier_orders';
        $this->values = $db->prefix . 'sfm4u_inventory_values';
        $this->journal = new RequestJournal($db);
        $this->valuation = new StockValuation($db);
        $this->payables = new SupplierPayables($db, $this->valuation);
    }

    public function install(): void
    {
        $this->journal->install();
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->orders} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            snapshot longtext NOT NULL,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->values} (
            product_id bigint unsigned NOT NULL,
            quantity bigint unsigned NOT NULL,
            value_micros bigint unsigned NOT NULL,
            currency char(3) NOT NULL,
            PRIMARY KEY  (product_id)
        ) ENGINE=InnoDB {$charset};");
        foreach ([$this->orders, $this->values, $this->db->postmeta, $this->db->prefix . 'wc_product_meta_lookup', ...$this->valuation->install(), ...$this->payables->install()] as $table) {
            $engine = $this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ));
            if ($engine !== 'InnoDB') {
                throw new RuntimeException('Receiving requires transactional inventory tables.');
            }
        }
        update_option('sfm4u_cost_lots_ready', 'yes');
    }

    public function issue(string $key, string $supplier, array $lines): array
    {
        $this->authorize();
        $supplier = trim(sanitize_text_field($supplier));
        if ($supplier === '' || strlen($supplier) > 150 || !$lines || count($lines) > 30 || wc_get_price_decimals() !== 2) {
            throw new DomainException('Enter a supplier and 1–30 purchase lines in a two-decimal currency.', 422);
        }
        $items = [];
        foreach ($lines as $line) {
            $product = Rule::number($line['product'] ?? null, 1, 9999999999);
            if (isset($items[$product])) {
                throw new DomainException('Each product may only appear once per purchase order.', 422);
            }
            $items[$product] = ['ordered' => Rule::number($line['quantity'] ?? null, 1, 1000000),
                'unit_minor' => Rule::number($line['unit_minor'] ?? null, 0, 1000000000), 'received' => 0];
        }
        $currency = get_woocommerce_currency();
        return $this->execute($key, compact('supplier', 'items', 'currency'), function () use ($supplier, $items, $currency): array {
            foreach (array_keys($items) as $product) {
                $this->stockProduct($product);
            }
            $order = ['supplier' => $supplier, 'currency' => $currency, 'status' => 'open',
                'issued_at' => time(), 'lines' => $items];
            if ($this->db->insert($this->orders, ['snapshot' => wp_json_encode($order, JSON_THROW_ON_ERROR)]) !== 1) {
                throw new RuntimeException('Could not issue purchase order.');
            }
            return ['response' => ['id' => $this->db->insert_id] + $order, 'movements' => []];
        });
    }

    public function receive(string $key, int $id, array $lines, string $shortReason = ''): array
    {
        return $this->receiveInternal($key,$id,$lines,$shortReason,null);
    }

    public function receiveAllocated(string $key,int $id,array $lines,int $minor,string $basis,string $shortReason=''):array
    {
        $this->authorize();
        if(count($lines)>30 || !in_array($basis,['quantity','value'],true) || $minor<0 || $minor>1000000000)throw new DomainException('Invalid allocation total or basis.',422);
        foreach($lines as &$line){
            if(!is_array($line) || (isset($line['landed_minor']) && \SFM4U\Wholesale\Rule::number($line['landed_minor'],0,1000000000)!==0))throw new DomainException('Use zero per-line landed cost with automatic allocation.',422);
            $line['landed_minor']=0;
        }
        unset($line);
        return $this->receiveInternal($key,$id,$lines,$shortReason,['total_minor'=>$minor,'basis'=>$basis]);
    }

    private function receiveInternal(string $key,int $id,array $lines,string $shortReason,?array $allocation):array
    {
        $this->authorize();
        $shortReason = trim(sanitize_text_field($shortReason));
        if (strlen($shortReason) > 250 || (!$lines && $shortReason === '') || count($lines) > 30) {
            throw new DomainException('Add receipt lines or explain why the remaining order is closed short.', 422);
        }
        $receipt = [];
        foreach ($lines as $line) {
            $product = Rule::number($line['product'] ?? null, 1, 9999999999);
            if (isset($receipt[$product])) {
                throw new DomainException('Each product may only appear once per receipt.', 422);
            }
            $receipt[$product] = ['quantity' => Rule::number($line['quantity'] ?? null, 1, 1000000),
                'landed_minor' => Rule::number($line['landed_minor'] ?? null, 0, 1000000000)];
        }
        ksort($receipt, SORT_NUMERIC);
        try {
            return $this->execute($key, compact('id', 'receipt', 'shortReason') + ($allocation===null?[]:['allocation'=>$allocation]), function () use ($id, $receipt, $shortReason, $allocation): array {
                $order = $this->load($id, true);
                if ($order['status'] !== 'open' || $order['currency'] !== get_woocommerce_currency() || wc_get_price_decimals() !== 2) {
                    throw new DomainException('This order is closed or its currency has changed.', 409);
                }
                foreach ($receipt as $product => $delivery) {
                    $line = $order['lines'][$product] ?? null;
                    if (!$line || $delivery['quantity'] > $line['ordered'] - $line['received']) {
                        throw new DomainException('Receiving more than the outstanding order quantity is blocked.', 422);
                    }
                }
                if($allocation!==null){
                    $weights=[];foreach($receipt as $product=>$delivery)$weights[$product]=$allocation['basis']==='quantity'?$delivery['quantity']:$delivery['quantity']*$order['lines'][$product]['unit_minor'];
                    $shares=LandedCostAllocation::allocate($allocation['total_minor'],$weights);
                    foreach($receipt as $product=>&$delivery)$delivery['landed_minor']=$shares[$product];unset($delivery);
                }
                $movements = [];
                $received = [];
                $receiptId = $receipt ? wp_generate_uuid4() : null;
                foreach ($receipt as $product => $delivery) {
                    $line = &$order['lines'][$product];
                    $quantity = $delivery['quantity'];
                    $value = $this->receiveStock($product, $quantity, $line['unit_minor'], $delivery['landed_minor'], $order['currency']);
                    $this->valuation->recordReceipt($receiptId, $id, $product, $quantity, $line['unit_minor'] * $quantity, $delivery['landed_minor']);
                    $line['received'] += $quantity;
                    $line['landed_minor_received'] = ($line['landed_minor_received'] ?? 0) + $delivery['landed_minor'];
                    unset($line);
                    $received[$product] = $delivery + $value;
                    $movements[] = ['product_id' => $product, 'delta' => $quantity, 'type' => 'receipt'];
                }
                if ($receiptId) {
                    $order['receipt_ids'][] = $receiptId;
                }
                $outstanding = array_sum(array_column($order['lines'], 'ordered')) - array_sum(array_column($order['lines'], 'received'));
                if ($shortReason !== '' && $outstanding > 0) {
                    $order['status'] = 'closed_short';
                    $order['short_reason'] = $shortReason;
                } elseif ($outstanding === 0) {
                    $order['status'] = 'received';
                }
                if ($this->db->update($this->orders, ['snapshot' => wp_json_encode($order, JSON_THROW_ON_ERROR)], ['id' => $id]) !== 1) {
                    throw new RuntimeException('Could not update purchase order.');
                }
                do_action('sfm4u_synthetic_receipt_before_commit', $id);
                return ['response' => ['id' => $id, 'receipt_id' => $receiptId, 'receipt' => $received] + ($allocation===null?[]:['allocation'=>$allocation]) + $order, 'movements' => $movements];
            });
        } catch (\Throwable $error) {
            foreach (array_keys($receipt) as $product) {
                clean_post_cache($product);
            }
            throw $error;
        }
    }

    public function postLateCost(string $key, int $po, string $receipt, int $product, int $minor): array
    {
        $this->authorize();
        if (!wp_is_uuid($receipt, 4) || $product < 1 || $minor < 1 || $minor > 1000000000) {
            throw new DomainException('Enter a receipt ID, product, and positive landed cost.', 422);
        }
        return $this->execute($key, compact('po', 'receipt', 'product', 'minor'), function () use ($po, $receipt, $product, $minor): array {
            $order = $this->load($po, true);
            if ($order['currency'] !== get_woocommerce_currency() || wc_get_price_decimals() !== 2) {
                throw new DomainException('Post the receipt cost in the original two-decimal currency.', 409);
            }
            return ['response' => $this->valuation->lateCost($receipt, $po, $product, $minor) + ['currency' => $order['currency']], 'movements' => []];
        });
    }

    public function postAllocatedLateCost(string $key,int $po,string $receipt,int $minor,string $basis):array
    {
        $this->authorize();
        if(!wp_is_uuid($receipt,4) || $po<1 || $minor<1 || $minor>1000000000 || !in_array($basis,['quantity','value'],true))throw new DomainException('Invalid late-cost allocation.',422);
        return $this->execute($key,['action'=>'allocate_late']+compact('po','receipt','minor','basis'),function()use($po,$receipt,$minor,$basis):array{
            $order=$this->load($po,true);
            if($order['currency']!==get_woocommerce_currency() || wc_get_price_decimals()!==2)throw new DomainException('Use original receipt currency.',409);
            $lots=$this->db->get_results($this->db->prepare('SELECT product_id,received,goods_minor FROM %i WHERE receipt=%s AND purchase_order_id=%d ORDER BY product_id',
                $this->db->prefix.'sfm4u_inventory_receipt_lots',$receipt,$po),ARRAY_A);
            if($this->db->last_error!=='' || !$lots)throw new DomainException('Receipt is unavailable for allocation.',409);
            $weights=[];foreach($lots as $lot)$weights[(int)$lot['product_id']]=(int)($basis==='quantity'?$lot['received']:$lot['goods_minor']);
            $shares=LandedCostAllocation::allocate($minor,$weights);$parts=[];$stock=$cogs=0;
            foreach($shares as $product=>$amount){
                if($amount===0)continue;
                $parts[$product]=$this->valuation->lateCost($receipt,$po,$product,$amount);
                $stock+=$parts[$product]['stock_micros'];$cogs+=$parts[$product]['cogs_adjustment_micros'];
            }
            return ['response'=>['currency'=>$order['currency'],'allocation'=>$shares,'stock_micros'=>$stock,'cogs_adjustment_micros'=>$cogs,'parts'=>$parts], 'movements'=>[]];
        });
    }

    public function find(int $id): array
    {
        $this->authorize();
        return ['id' => $id] + $this->load($id, false);
    }

    private function receiveStock(int $id, int $quantity, int $minor, int $landed, string $currency): array
    {
        $product = $this->stockProduct($id);
        $stock = $this->valuation->lockedStock($id);
        $prior = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE product_id=%d FOR UPDATE', $this->values, $id), ARRAY_A);
        if (($stock > 0 && !$prior) || ($prior && ((int) $prior['quantity'] !== $stock || $prior['currency'] !== $currency))) {
            throw new DomainException('Reconcile the stock valuation before receiving this product.', 409);
        }
        $pool = new MovingAverage();
        if ($stock > 0) {
            $pool->receive($stock, (int) $prior['value_micros']);
        }
        $goods = $minor * $quantity;
        if ($goods > intdiv(PHP_INT_MAX, self::MINOR_TO_MICROS)) {
            throw new DomainException('Goods cost exceeds receiving limits.', 422);
        }
        $pool->receive($quantity, $goods * self::MINOR_TO_MICROS, $landed * self::MINOR_TO_MICROS);
        $value = $pool->state();
        if ((int) wc_update_product_stock($product, $quantity, 'increase') !== $value['quantity']) {
            throw new RuntimeException('WooCommerce stock did not match the new valuation.');
        }
        $stored = ['quantity' => $value['quantity'], 'value_micros' => $value['value_micros']];
        $changed = $prior ? $this->db->update($this->values, $stored, ['product_id' => $id])
            : $this->db->insert($this->values, $stored + ['product_id' => $id, 'currency' => $currency]);
        if ($changed !== 1) {
            throw new RuntimeException('Could not update inventory valuation.');
        }
        return $value;
    }

    private function stockProduct(int $id): \WC_Product
    {
        $product = wc_get_product($id);
        if (!$product || !$product->managing_stock() || $product->get_stock_managed_by_id() !== $id) {
            throw new DomainException('Choose a product with independently managed WooCommerce stock.', 422);
        }
        return $product;
    }

    private function load(int $id, bool $lock): array
    {
        $sql = 'SELECT snapshot FROM %i WHERE id=%d' . ($lock ? ' FOR UPDATE' : '');
        $snapshot = $this->db->get_var($this->db->prepare($sql, $this->orders, $id));
        if (!$snapshot) {
            throw new DomainException('Purchase order not found.', 404);
        }
        return json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
    }

    private function execute(string $key, array $intent, callable $operation): array
    {
        $this->journal->requirePinnedConnection();
        return $this->journal->execute(get_current_blog_id(), 'supplier-po', $key, $intent, $operation);
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_woocommerce') || !(\SFM4U\POS\Runtime::enabled() || in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true))) {
            throw new DomainException('Supplier receiving is restricted to synthetic store managers.', 403);
        }
    }
}
