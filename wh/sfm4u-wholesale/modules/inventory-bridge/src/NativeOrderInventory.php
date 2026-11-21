<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use SFM4U\Wholesale\Pro\Money;

/** Journal native Woo stock events after Woo has authenticated their normal entry point. */
final class NativeOrderInventory
{
    public const RETRY_HOOK = 'sfm4u_native_inventory_retry';
    private const SWEEP_HOOK = 'sfm4u_native_inventory_sweep';
    private string $states;
    private string $pending;
    private RequestJournal $journal;
    private StockValuation $valuation;
    private array $refundArguments = [];

    public function __construct(private \wpdb $db)
    {
        $this->states = $db->prefix . 'sfm4u_native_inventory_orders';
        $this->pending = $db->prefix . 'sfm4u_native_inventory_pending';
        $this->journal = new RequestJournal($db);
        $this->valuation = new StockValuation($db);
    }

    public function install(): void
    {
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->states} (
            order_id bigint unsigned NOT NULL,
            snapshot longtext NOT NULL,
            PRIMARY KEY  (order_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->pending} (
            event_key varchar(80) NOT NULL,
            order_id bigint unsigned NOT NULL,
            sequence bigint unsigned NOT NULL AUTO_INCREMENT,
            payload longtext NOT NULL,
            last_error varchar(250) NOT NULL,
            PRIMARY KEY  (event_key),
            UNIQUE KEY sequence (sequence),
            KEY order_queue (order_id,sequence)
        ) ENGINE=InnoDB {$charset};");
        foreach ([$this->states, $this->pending] as $table) {
            if (strtolower((string) $this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ))) !== 'innodb') {
                throw new RuntimeException('Native inventory requires transactional tables.');
            }
        }
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Inventory recovery', 'sfm4u-wholesale'), __('Inventory recovery', 'sfm4u-wholesale'),
                'manage_woocommerce', 'sfm4u-inventory-recovery', [$this, 'render']);
        });
        add_filter('woocommerce_can_reduce_order_stock', fn(bool $allowed, \WC_Order $order): bool =>
            $this->intercept($allowed, $order, 'sale'), 200, 2);
        add_filter('woocommerce_can_restore_order_stock', fn(bool $allowed, \WC_Order $order): bool =>
            $this->intercept($allowed, $order, 'cancel'), 200, 2);
        add_action('woocommerce_create_refund', function (\WC_Order_Refund $refund, array $args): void {
            $this->refundArguments[spl_object_id($refund)] = ['refund' => $refund, 'args' => $args];
        }, 200, 2);
        add_filter('woocommerce_can_restock_refunded_items', function (bool $allowed, \WC_Order $order): bool {
            return $this->handles($order) ? false : $allowed;
        }, 200, 2);
        add_action('woocommerce_order_refunded', function (int $order, int $refund): void {
            foreach ($this->refundArguments as $key => $entry) {
                if ($entry['refund']->get_id() === $refund) {
                    unset($this->refundArguments[$key]);
                    $parent = wc_get_order($order);
                    if ($parent && $this->handles($parent)) {
                        $this->dispatch('refund', $order, $refund, (bool) $entry['args']['restock_items']);
                    }
                    return;
                }
            }
        }, PHP_INT_MAX, 2);
        add_action(self::RETRY_HOOK, [$this, 'retry']);
        add_action(self::SWEEP_HOOK, [$this, 'sweep']);
        $ensure = static function (): void {
            if (!as_has_scheduled_action(self::SWEEP_HOOK, [], 'sfm4u-inventory')) {
                as_schedule_recurring_action(time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS,
                    self::SWEEP_HOOK, [], 'sfm4u-inventory', true);
            }
        };
        add_action('action_scheduler_init', $ensure);
        add_action('action_scheduler_ensure_recurring_actions', $ensure);
        if (did_action('action_scheduler_init')) $ensure();
    }

    private function handles(\WC_Order $order): bool
    {
        if (in_array($order->get_created_via(), ['sfm4u-b2b-credit', 'sfm4u-pos-fixture'], true)) {
            return false;
        }
        if ($this->state($order->get_id())['revision'] > 0) {
            return true;
        }
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->managing_stock() && $this->valuation->hasValue($product->get_stock_managed_by_id())) {
                return true;
            }
        }
        return false;
    }

    private function intercept(bool $allowed, \WC_Order $order, string $action): bool
    {
        if (!$allowed || !$this->handles($order)) {
            return $allowed;
        }
        $state = $this->state($order->get_id());
        $waiting = $this->db->get_var($this->db->prepare('SELECT event_key FROM %i WHERE order_id=%d LIMIT 1', $this->pending, $order->get_id()));
        if ($this->db->last_error !== '') throw new RuntimeException('Native recovery queue could not be read.');
        if ($waiting === null && (($action === 'sale' && $state['active']) || ($action === 'cancel' && !$state['active'] && $state['revision'] > 0))) {
            return false;
        }
        $this->dispatch($action, $order->get_id(), 0, true);
        return false;
    }

    private function dispatch(string $action, int $order, int $refund, bool $restock): void
    {
        $payload = compact('action', 'order', 'refund', 'restock');
        // Queued lifecycle transitions can precede the next committed revision.
        // Give a new transition its own durable key; reuse the latest identical pending intent.
        $key = sprintf('native_%s_%020d_%020d', $action, $order, $refund ?: random_int(1, PHP_INT_MAX));
        $latest = $this->db->get_row($this->db->prepare('SELECT event_key,payload FROM %i WHERE order_id=%d ORDER BY sequence DESC LIMIT 1', $this->pending, $order), ARRAY_A);
        if ($this->db->last_error !== '') throw new RuntimeException('Native recovery queue could not be read.');
        if ($latest && json_decode($latest['payload'], true, 32, JSON_THROW_ON_ERROR) === $payload) $key = $latest['event_key'];
        try {
            $this->post($key, $payload);
            $this->db->delete($this->pending, ['event_key' => $key]);
        } catch (\Throwable $error) {
            if ($this->db->query($this->db->prepare(
                'INSERT INTO %i (event_key,order_id,payload,last_error) VALUES (%s,%d,%s,%s)
                 ON DUPLICATE KEY UPDATE last_error=VALUES(last_error)', $this->pending, $key, $order,
                wp_json_encode($payload, JSON_THROW_ON_ERROR), substr(sanitize_text_field($error->getMessage()), 0, 250))) === false) {
                throw new RuntimeException('Native inventory needs review and its recovery record could not be stored.', 503, $error);
            }
            if (function_exists('as_schedule_single_action') && !as_has_scheduled_action(self::RETRY_HOOK, [$key], 'sfm4u-inventory')) {
                as_schedule_single_action(time() + MINUTE_IN_SECONDS, self::RETRY_HOOK, [$key], 'sfm4u-inventory', true);
            }
            do_action('sfm4u_native_inventory_needs_review', $order, $key);
        }
    }

    public function retry(string $key): void
    {
        $payload = $this->db->get_var($this->db->prepare('SELECT payload FROM %i WHERE event_key=%s', $this->pending, $key));
        if ($payload === null) return;
        $this->post($key, json_decode($payload, true, 32, JSON_THROW_ON_ERROR));
        $this->db->delete($this->pending, ['event_key' => $key]);
    }

    public function sweep(): void
    {
        $keys = $this->db->get_col($this->db->prepare('SELECT event_key FROM %i ORDER BY sequence LIMIT 50', $this->pending));
        foreach ($keys as $key) {
            try {
                $this->retry($key);
            } catch (\Throwable) {
                // The persistent review row remains available for the next sweep.
            }
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="wrap"><h1>' . esc_html__('Inventory recovery', 'sfm4u-wholesale') . '</h1>';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('sfm4u_inventory_retry');
            try {
                $key = is_string($_POST['event_key'] ?? null) ? wp_unslash($_POST['event_key']) : '';
                if (!preg_match('/^native_(sale|cancel|refund)_[0-9]{20}_[0-9]{20}$/D', $key)) {
                    throw new DomainException('Invalid inventory recovery key.');
                }
                $this->retry($key);
                echo '<p role="status">' . esc_html__('Inventory retry completed.', 'sfm4u-wholesale') . '</p>';
            } catch (\Throwable) {
                echo '<p role="alert">' . esc_html__('Inventory is still awaiting review. Reconcile stock and original cost before retrying.', 'sfm4u-wholesale') . '</p>';
            }
        }
        echo '<p>' . esc_html__('Pending inventory events retry automatically. Review failed stock and cost reconciliation before fulfilling an order.', 'sfm4u-wholesale') . '</p>';
        $rows = $this->db->get_results($this->db->prepare('SELECT event_key,payload FROM %i ORDER BY sequence LIMIT 50', $this->pending), ARRAY_A);
        if (!$rows) echo '<p>' . esc_html__('No pending inventory events.', 'sfm4u-wholesale') . '</p>';
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
            echo '<form method="post"><p>' . esc_html(sprintf(__('Order %d · %s', 'sfm4u-wholesale'), $payload['order'], __(ucfirst($payload['action']), 'sfm4u-wholesale'))) . '</p>';
            wp_nonce_field('sfm4u_inventory_retry');
            echo '<input type="hidden" name="event_key" value="' . esc_attr($row['event_key']) . '">';
            submit_button(__('Retry inventory event', 'sfm4u-wholesale'), 'secondary');
            echo '</form>';
        }
        echo '</div>';
    }

    private function post(string $key, array $intent): array
    {
        $this->journal->requirePinnedConnection();
        $products = [];
        try {
            return $this->journal->execute(get_current_blog_id(), 'woo-stock', $key, $intent, function () use ($key, $intent, &$products): array {
                $order = wc_get_order($intent['order']);
                if (!$order) throw new DomainException('Native inventory order is unavailable.', 409);
                if ($order->get_currency() !== get_woocommerce_currency() || wc_get_price_decimals() !== 2) {
                    throw new DomainException('Review native order currency before stock capture.', 409);
                }
                $initial = wp_json_encode($this->emptyState(), JSON_THROW_ON_ERROR);
                $this->query($this->db->prepare('INSERT INTO %i (order_id,snapshot) VALUES (%d,%s) ON DUPLICATE KEY UPDATE order_id=order_id',
                    $this->states, $order->get_id(), $initial));
                $state = $this->state($order->get_id(), true);
                $action = $intent['action'];
                $first = $this->db->get_var($this->db->prepare('SELECT event_key FROM %i WHERE order_id=%d ORDER BY sequence LIMIT 1', $this->pending, $order->get_id()));
                if ($this->db->last_error !== '') throw new RuntimeException('Native recovery queue could not be read.');
                if ($first !== null && $first !== $key) throw new DomainException('An earlier inventory event is awaiting recovery.', 409);
                if ($action !== 'sale' && $state['revision'] === 0) {
                    if ($action === 'cancel' && !$order->get_data_store()->get_stock_reduced($order->get_id())) {
                        return ['response' => ['kind' => 'ignored', 'order_id' => $order->get_id()], 'movements' => []];
                    }
                    throw new DomainException('Review historical native stock and original cost before restoration.', 409);
                }
                if ($action === 'sale' && ($state['active'] || $order->has_status(['cancelled', 'failed', 'refunded']))) {
                    return ['response' => ['kind' => 'ignored', 'order_id' => $order->get_id()], 'movements' => []];
                }
                if ($action === 'sale') {
                    $state['products'] = [];
                    $state['items'] = [];
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        $id = $product && $product->managing_stock() ? $product->get_stock_managed_by_id() : 0;
                        $originalQuantity = (int) $item->get_quantity();
                        $quantity = $originalQuantity - abs((int) $order->get_qty_refunded_for_item($item->get_id()));
                        if ($originalQuantity < 1 || (float) $item->get_quantity() !== (float) $originalQuantity
                            || ($id && ((float) apply_filters('woocommerce_order_item_quantity', $item->get_quantity(), $order, $item) !== (float) $originalQuantity
                                || StockValuation::reducedQuantity($this->db, $item->get_id()) > 0))) {
                            throw new DomainException('Review historical or fractional native stock before capture.', 409);
                        }
                        if ($quantity < 1) continue;
                        if ($id) {
                            $state['products'][$id] = $state['products'][$id] ?? ['quantity' => 0, 'returned' => 0];
                            $state['products'][$id]['quantity'] += $quantity;
                        }
                        $state['items'][$item->get_id()] = ['product' => $id, 'quantity' => $quantity, 'returned' => 0,
                            'returned_minor' => 0,
                            'sales_minor' => Money::parse((string) $item->get_total(), $order->get_currency())->minor
                                - Money::parse(wc_format_decimal($order->get_total_refunded_for_item($item->get_id()), 2), $order->get_currency())->minor];
                    }
                    ksort($state['products'], SORT_NUMERIC);
                }
                $products = array_keys($state['products']);
                if (count($products) > 100) throw new DomainException('Native inventory supports at most 100 stock products per order.', 409);
                $movements = [];
                $cogs = $reversed = $writeoff = $sales = $returns = $coverage = 0;
                $refund = $intent['refund'] ? wc_get_order($intent['refund']) : false;
                $returnItems = [];
                $returnValues = [];
                if ($action === 'refund') {
                    if (!$refund instanceof \WC_Order_Refund || $refund->get_parent_id() !== $order->get_id()) {
                        throw new DomainException('Native refund is unavailable.', 409);
                    }
                    foreach ($refund->get_items() as $item) {
                        $lineId = (int) $item->get_meta('_refunded_item_id');
                        $quantity = abs((int) $item->get_quantity());
                        if (!isset($state['items'][$lineId]) || abs((float) $item->get_quantity()) !== (float) $quantity) {
                            throw new DomainException('Review the native refund quantity.', 409);
                        }
                        $amount = abs(Money::parse((string) $item->get_total(), $order->get_currency())->minor);
                        $line = $state['items'][$lineId];
                        $returnValues[$lineId] = ($returnValues[$lineId] ?? 0) + $amount;
                        if ($returnValues[$lineId] > $line['sales_minor'] - ($line['returned_minor'] ?? 0)) {
                            throw new DomainException('Native line refund exceeds remaining line value.', 409);
                        }
                        $returns += $amount;
                        $returnItems[$lineId] = ($returnItems[$lineId] ?? 0) + $quantity;
                    }
                } elseif ($action === 'cancel') {
                    foreach ($state['items'] as $lineId => $line) {
                        $returnItems[$lineId] = $line['quantity'] - $line['returned'];
                        $returnValues[$lineId] = $line['sales_minor'] - ($line['returned_minor'] ?? 0);
                        $returns += $returnValues[$lineId];
                    }
                }
                $quantities = [];
                foreach ($returnItems as $lineId => $quantity) {
                    $line = $state['items'][$lineId];
                    if ($quantity > $line['quantity'] - $line['returned']) {
                        throw new DomainException('Native return exceeds the original sale quantity.', 409);
                    }
                    if ($line['product']) $quantities[$line['product']] = ($quantities[$line['product']] ?? 0) + $quantity;
                }
                foreach ($state['products'] as $id => &$sale) {
                    $tracked = $sale['tracked'] ?? $this->valuation->hasValue((int) $id);
                    $quantity = $action === 'sale' ? $sale['quantity'] : ($quantities[$id] ?? 0);
                    if ($quantity === 0) continue;
                    $before = $this->valuation->lockedStock((int) $id);
                    if ($action === 'sale') {
                        if ($before - (int) wc_get_held_stock_quantity(wc_get_product($id), $order->get_id()) < $quantity) {
                            throw new DomainException('Native order exceeds available stock.', 409);
                        }
                        $sale += ['tracked' => $tracked, 'schema' => 1];
                        if ($tracked) {
                            $sale += $this->valuation->nativeSale((int) $id, $quantity);
                            $cogs += $sale['cogs_micros'];
                        } else {
                            wc_update_product_stock((int) $id, $quantity, 'decrease');
                            $sale['cogs_micros'] = 0;
                            $coverage++;
                        }
                    } else {
                        if ($tracked) {
                            $cost = $this->valuation->nativeReturn((int) $id, $sale, $sale['returned'], $quantity, $intent['restock']);
                            $reversed += $cost;
                            $writeoff += $intent['restock'] ? 0 : $cost;
                        } elseif ($intent['restock']) {
                            wc_update_product_stock((int) $id, $quantity, 'increase');
                        }
                        $sale['returned'] += $quantity;
                    }
                    $delta = $action === 'sale' ? -$quantity : ($intent['restock'] ? $quantity : 0);
                    if ($this->valuation->lockedStock((int) $id) !== $before + $delta) {
                        throw new RuntimeException('Native Woo stock posting did not reconcile.');
                    }
                    if ($delta !== 0) $movements[] = ['product_id' => (int) $id, 'delta' => $delta,
                        'type' => $action === 'sale' ? 'sale' : 'return', 'order_id' => $order->get_id()];
                }
                unset($sale);
                foreach ($order->get_items() as $item) {
                    $lineId = $item->get_id();
                    if (!isset($state['items'][$lineId])) continue;
                    $state['items'][$lineId]['returned'] += $returnItems[$lineId] ?? 0;
                    $state['items'][$lineId]['returned_minor'] = ($state['items'][$lineId]['returned_minor'] ?? 0) + ($returnValues[$lineId] ?? 0);
                    $line = $state['items'][$lineId];
                    if ($line['product']) {
                        $item->update_meta_data('_reduced_stock', $line['quantity'] - $line['returned']);
                        $item->save();
                    } elseif ($action === 'sale') {
                        $coverage++;
                    }
                    if ($action === 'sale') $sales += $line['sales_minor'];
                }
                $state['active'] = $action === 'sale' || ($state['active'] && $action === 'refund');
                $state['revision']++;
                $this->query($this->db->prepare('UPDATE %i SET snapshot=%s WHERE order_id=%d', $this->states,
                    wp_json_encode($state, JSON_THROW_ON_ERROR), $order->get_id()));
                $order->get_data_store()->set_stock_reduced($order->get_id(), $state['active']);
                do_action('sfm4u_native_inventory_before_commit', $action, $order->get_id());
                return ['response' => ['kind' => $action, 'order_id' => $order->get_id(), 'refund_id' => $intent['refund'],
                    'currency' => $order->get_currency(), 'sales_minor' => $sales,
                    'returns_minor' => $returns, 'coverage_gaps' => $coverage,
                    'cogs_micros' => $cogs, 'reversed_micros' => $reversed, 'writeoff_micros' => $writeoff,
                    'revision' => $state['revision']], 'movements' => $movements];
            });
        } finally {
            foreach ($products as $id) {
                clean_post_cache($id);
                wp_cache_delete($id, 'post_meta');
            }
            \WC_Cache_Helper::invalidate_cache_group('orders');
            wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($intent['order']);
        }
    }

    private function emptyState(): array
    {
        return ['revision' => 0, 'active' => false, 'products' => [], 'items' => []];
    }

    private function state(int $order, bool $lock = false): array
    {
        $row = $this->db->get_var($this->db->prepare('SELECT snapshot FROM %i WHERE order_id=%d' . ($lock ? ' FOR UPDATE' : ''), $this->states, $order));
        if ($this->db->last_error !== '') throw new RuntimeException('Native inventory state could not be read.');
        return $row === null ? $this->emptyState() : json_decode($row, true, 32, JSON_THROW_ON_ERROR);
    }

    private function query(string $sql): void
    {
        if ($this->db->query($sql) === false) throw new RuntimeException('Native inventory write failed.');
    }
}
