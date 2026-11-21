<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

use DomainException;
use RuntimeException;
use SFM4U\Inventory\RequestJournal;
use SFM4U\Wholesale\Rule;

final class WooQuotes
{
    private string $quotes;
    private string $accounts;
    private string $ledger;
    private RequestJournal $journal;
    private Settlement $settlement;

    public function __construct(private \wpdb $db, private WooCatalog $catalog)
    {
        $this->quotes = $db->prefix . 'sfm4u_b2b_quotes';
        $this->accounts = $db->prefix . 'sfm4u_b2b_accounts';
        $this->ledger = $db->prefix . 'sfm4u_b2b_ledger';
        $this->journal = new RequestJournal($db);
        $this->settlement = new Settlement($db);
    }

    public function install(): void
    {
        $this->journal->install();
        (new \SFM4U\POS\ReceiptRegister($this->db))->install();
        (new \SFM4U\POS\RegisterSessions($this->db))->install();
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->quotes} (
            id char(36) NOT NULL,
            buyer bigint unsigned NOT NULL,
            vendor bigint unsigned DEFAULT NULL,
            request_hash char(64) NOT NULL,
            order_id bigint unsigned DEFAULT NULL,
            snapshot longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY buyer_request (buyer,request_hash),
            UNIQUE KEY quote_order (order_id),
            KEY vendor_quote (vendor,id)
        ) ENGINE=InnoDB {$charset};");
        if ($this->db->query($this->db->prepare(
            "UPDATE %i SET vendor=CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.vendorId')) AS UNSIGNED)
             WHERE vendor IS NULL AND JSON_VALID(snapshot)",
            $this->quotes
        )) === false) {
            throw new RuntimeException('Could not index existing vendor quotes.');
        }
        dbDelta("CREATE TABLE {$this->accounts} (
            buyer bigint unsigned NOT NULL,
            credit_limit bigint unsigned NOT NULL,
            net_days smallint unsigned NOT NULL,
            grace_days smallint unsigned NOT NULL,
            currency char(3) NOT NULL,
            PRIMARY KEY  (buyer)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->ledger} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            buyer bigint unsigned NOT NULL,
            idempotency_hash char(64) NOT NULL,
            order_id bigint unsigned NOT NULL,
            amount_minor bigint unsigned NOT NULL,
            issued_at bigint unsigned NOT NULL,
            due_at bigint unsigned NOT NULL,
            hold_until bigint unsigned DEFAULT NULL,
            expiry_review varchar(32) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY buyer_idempotency (buyer,idempotency_hash),
            UNIQUE KEY charge_order (order_id),
            KEY hold_expiry (hold_until,order_id)
        ) ENGINE=InnoDB {$charset};");
        foreach ([$this->quotes, $this->accounts, $this->ledger, ...$this->settlement->install()] as $table) {
            if (strtolower((string) $this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ))) !== 'innodb') {
                throw new RuntimeException('database_unavailable', 503);
            }
        }
    }

    public function submit(int $buyer, string $key, array $lines): array
    {
        return $this->execute($buyer, $key, ['operation' => 'submit', 'lines' => $lines], function () use ($buyer, $key, $lines) {
            $quote = new Quote(wp_generate_uuid4(), (string) $buyer, $this->vendor($buyer, $lines), get_woocommerce_currency());
            $quote->setRequestLines($lines);
            $quote->apply('submit', (string) $buyer, 'buyer', $key, time());
            $this->save($quote, $key);
            return $this->view($quote);
        });
    }

    public function change(int $actor, string $role, string $id, string $key, string $action, array $payload): array
    {
        return $this->execute($actor, $key, compact('role', 'id', 'action', 'payload'), function () use ($actor, $role, $id, $key, $action, $payload) {
            $quote = $this->lock($id);
            $quote->apply($action, (string) $actor, $role, $key, time(), $payload);
            if ($action === 'offer' && $this->vendor((int) $quote->buyerId, $quote->offerLines) !== $quote->vendorId) {
                throw new DomainException('vendor_mismatch');
            }
            $this->save($quote);
            return $this->view($quote);
        });
    }

    public function convert(int $buyer, string $id, string $key): array
    {
        return $this->execute($buyer, $key, ['operation' => 'convert', 'id' => $id], function () use ($buyer, $id, $key) {
            $quote = $this->lock($id);
            if ($quote->buyerId !== (string) $buyer) {
                throw new DomainException('not_quote_owner');
            }
            if ($quote->state !== Quote::ACCEPTED) {
                throw new DomainException('transition_not_allowed');
            }
            if ($this->vendor($buyer, $quote->offerLines) !== $quote->vendorId) {
                throw new DomainException('vendor_mismatch');
            }
            $account = $this->account($buyer);
            if ($account->currency !== $quote->currency || $quote->currency !== get_woocommerce_currency()) {
                throw new DomainException('currency_mismatch');
            }
            $order = new \WC_Order();
            $order->set_customer_id($buyer);
            $order->set_currency($quote->currency);
            $order->set_created_via('sfm4u-b2b-credit');
            $order->set_address((new \WC_Customer($buyer))->get_billing(), 'billing');
            $order->set_status('pending');
            $order->update_meta_data('_sfm4u_b2b_quote', $quote->id);
            foreach ($quote->offerLines as $line) {
                $catalog = $this->catalog->findForBuyer($line['sku'], (string) $buyer);
                if ($line['qty'] > $catalog['in_stock'] || !$catalog['purchasable']) {
                    throw new DomainException('insufficient_stock');
                }
                $total = Money::of($line['unit_price_minor'], $quote->currency)->times($line['qty'])->minor;
                $decimal = Rule::decimal($total, 2);
                $item = new \WC_Order_Item_Product();
                $product = wc_get_product($catalog['product_id']);
                $item->set_product($product);
                $item->set_quantity($line['qty']);
                $item->set_subtotal($decimal);
                $item->set_total($decimal);
                if ($quote->currency === 'BDT' && class_exists(\SFM4U\Wholesale\PurchaseHistory::class, false)) {
                    \SFM4U\Wholesale\PurchaseHistory::capture($item, $product, $buyer);
                }
                $order->add_item($item);
            }
            // Woo calculates applicable taxes. The ledger uses the saved order total.
            $order->calculate_totals();
            if (!$order->get_id()) {
                throw new RuntimeException('database_unavailable', 503);
            }
            $minor = Money::parse((string) $order->get_total(), $quote->currency)->minor;
            $now = time();
            $due = $account->charge($key, $minor, $now, (string) $order->get_id());
            if ($this->db->insert($this->ledger, ['buyer' => $buyer, 'idempotency_hash' => hash('sha256', $key),
                'order_id' => $order->get_id(), 'amount_minor' => $minor, 'issued_at' => $now, 'due_at' => $due]) !== 1) {
                throw new RuntimeException('database_unavailable', 503);
            }
            wc_reserve_stock_for_order($order);
            $hold = $this->db->get_var($this->db->prepare(
                'SELECT UNIX_TIMESTAMP(MAX(expires)) FROM %i WHERE order_id=%d',
                $this->db->prefix . 'wc_reserved_stock', $order->get_id()
            ));
            if (!$hold || (int) $hold <= $now || $this->db->update($this->ledger, ['hold_until' => (int) $hold],
                ['order_id' => $order->get_id()]) !== 1) {
                throw new RuntimeException('database_unavailable', 503);
            }
            $quote->apply('convert', 'system', 'system', $key, $now, ['order_id' => (string) $order->get_id()]);
            $this->save($quote);
            do_action('sfm4u_b2b_synthetic_before_commit', $order->get_id());
            return $this->view($quote) + ['amount_minor' => $minor, 'due_at' => $due];
        });
    }

    public function forBuyer(int $buyer): array
    {
        $rows = $this->db->get_col($this->db->prepare('SELECT snapshot FROM %i WHERE buyer=%d ORDER BY id LIMIT 100', $this->quotes, $buyer));
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        return array_map(fn(string $snapshot): array => $this->view(Quote::restore(json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR))), $rows);
    }

    public function incomingForVendor(int $vendor): array
    {
        $this->authorizeVendor($vendor);
        $rows = $this->db->get_col($this->db->prepare(
            "SELECT snapshot FROM %i WHERE vendor=%d AND JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.state')) IN ('submitted','offered')
             ORDER BY id LIMIT 100",
            $this->quotes, $vendor
        ));
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        return array_map(function (string $snapshot): array {
            $quote = Quote::restore(json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR));
            $history = $quote->history();
            $last = end($history);
            return $this->view($quote) + ['revision_note' => $last['action'] === 'revise' ? ($last['note'] ?? '') : ''];
        }, $rows);
    }

    public function creditCustomersForVendor(int $vendor): array
    {
        $this->authorizeVendor($vendor);
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT DISTINCT a.buyer,a.net_days,a.currency FROM %i q JOIN %i a ON a.buyer=q.buyer
             WHERE q.vendor=%d AND q.order_id IS NOT NULL ORDER BY a.buyer LIMIT 100',
            $this->quotes, $this->accounts, $vendor
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        return $rows;
    }

    private function authorizeVendor(int $vendor): void
    {
        if ($vendor !== get_current_user_id() || !current_user_can('sfm4u_b2b_offer') || !function_exists('dokan_is_user_seller')
            || !dokan_is_user_seller($vendor) || !dokan_is_seller_enabled($vendor)) {
            throw new DomainException('forbidden', 403);
        }
    }

    public function statement(int $buyer): array
    {
        $account = $this->accountSettings($buyer, false);
        $invoices = $this->settlement->invoices($buyer);
        $now = time();
        $grace = (int) $account['grace_days'] * DAY_IN_SECONDS;
        $overdue = 0;
        foreach ($invoices as &$invoice) {
            $invoice['overdue'] = $invoice['open_minor'] > 0 && $now > $invoice['due_at'] + $grace;
            if ($invoice['overdue']) {
                $overdue += $invoice['open_minor'];
            }
        }
        unset($invoice);
        return ['currency' => $account['currency'], 'net_days' => (int) $account['net_days'], 'invoices' => $invoices,
            'credit_limit_minor' => (int) $account['credit_limit'], 'overdue_minor' => $overdue,
            'balance_minor' => array_sum(array_column($invoices, 'open_minor')),
            'payments' => $this->settlement->payments($buyer)];
    }

    public function settle(int $actor, int $buyer, string $key, int $amount, string $reference): array
    {
        if ($actor !== get_current_user_id() || !current_user_can('sfm4u_b2b_settle')) {
            throw new DomainException('forbidden', 403);
        }
        return $this->execute($actor, $key, ['operation' => 'settlement', 'buyer' => $buyer, 'amount' => $amount, 'reference' => $reference],
            function () use ($buyer, $actor, $key, $amount, $reference): array {
                $this->accountSettings($buyer, true);
                return $this->settlement->record($buyer, $actor, $key, $this->settlement->invoices($buyer), $amount, $reference);
            });
    }

    public function fulfill(int $actor, int $buyer, int $id, string $key): array
    {
        if ($actor !== get_current_user_id() || !current_user_can('sfm4u_b2b_settle')
            || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
            throw new DomainException('forbidden', 403);
        }
        $this->journal->requirePinnedConnection();
        return $this->journal->execute($actor, 'b2b-fulfill', $key, compact('buyer', 'id'), function () use ($buyer, $id): array {
            $this->accountSettings($buyer, true);
            $order = wc_get_order($id);
            if (!$order || (int) $order->get_customer_id() !== $buyer || !$order->has_status('pending')
                || $order->get_created_via() !== 'sfm4u-b2b-credit') {
                throw new DomainException('fulfillment_unavailable');
            }
            $holds = $this->db->get_results($this->db->prepare(
                'SELECT product_id,stock_quantity FROM %i WHERE order_id=%d AND expires>NOW() FOR UPDATE',
                $this->db->prefix . 'wc_reserved_stock', $id
            ), ARRAY_A);
            $remaining = array_column($holds, 'stock_quantity', 'product_id');
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $stockId = $product ? $product->get_stock_managed_by_id() : 0;
                $quantity = (int) $item->get_quantity();
                if (!$stockId || $quantity < 1 || (int) ($remaining[$stockId] ?? 0) < $quantity) {
                    throw new DomainException('fulfillment_unavailable');
                }
                $remaining[$stockId] -= $quantity;
            }
            $order->set_status('processing');
            \SFM4U\Inventory\ManagedOrderStock::reduce($order, [$order, 'save']);
            $movements = [];
            foreach ($order->get_items() as $item) {
                $quantity = (int) $item->get_quantity();
                if (\SFM4U\Inventory\StockValuation::reducedQuantity($this->db, $item->get_id()) !== $quantity) {
                    throw new RuntimeException('WooCommerce did not reduce reserved stock.');
                }
                $movements[] = ['product_id' => $item->get_product()->get_stock_managed_by_id(), 'delta' => -$quantity,
                    'type' => 'sale', 'order_id' => $id];
            }
            do_action('sfm4u_inventory_capture_sale', $order);
            wc_release_stock_for_order($order);
            $currency = $order->get_currency();
            $gross = Money::parse((string) $order->get_total(), $currency);
            $tax = Money::parse(wc_format_decimal($order->get_total_tax(), 2), $currency);
            return ['response' => ['order_id' => $id, 'status' => 'processing',
                'currency' => $currency, 'sales_minor' => $gross->sub($tax)->minor], 'movements' => $movements];
        });
    }

    public function credit(int $actor, int $buyer, int $id, int $lineId, int $quantity, bool $resellable, string $key): array
    {
        if ($actor !== get_current_user_id() || !current_user_can('sfm4u_b2b_settle')
            || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
            throw new DomainException('forbidden', 403);
        }
        $this->journal->requirePinnedConnection();
        $productId = 0;
        try {
            return $this->journal->execute($actor, 'b2b-credit', $key, compact('buyer', 'id', 'lineId', 'quantity', 'resellable'),
                function () use ($buyer, $id, $lineId, $quantity, $resellable, &$productId): array {
                    $this->accountSettings($buyer, true);
                    $order = wc_get_order($id);
                    $line = $order && isset($order->get_items()[$lineId]) ? $order->get_items()[$lineId] : null;
                    $invoice = array_column($this->settlement->invoices($buyer), null, 'order_id')[$id] ?? null;
                    if (!$line || !$invoice || $invoice['released_at'] !== null || (int) $order->get_customer_id() !== $buyer
                        || $order->get_created_via() !== 'sfm4u-b2b-credit' || !$order->has_status(['processing','completed'])
                        || $order->get_currency() !== 'BDT' || wc_get_price_decimals() !== 2 || (float) $line->get_total_tax() !== 0.0) {
                        throw new DomainException('credit_unavailable');
                    }
                    $sold = (int) $line->get_quantity();
                    $returned = abs((int) $order->get_qty_refunded_for_item($lineId));
                    if ($quantity < 1 || $quantity > $sold - $returned || !$line->get_product()
                        || \SFM4U\Inventory\StockValuation::reducedQuantity($this->db, $lineId) < ($resellable ? $quantity : 0)) {
                        throw new DomainException('credit_unavailable');
                    }
                    $total = Money::parse((string) $line->get_total(), 'BDT')->minor;
                    $share = static fn(int $count): int => intdiv($total, $sold) * $count + intdiv(($total % $sold) * $count, $sold);
                    $amount = $share($returned + $quantity) - $share($returned);
                    $decimal = Rule::decimal($amount, 2);
                    $productId = $line->get_product()->get_stock_managed_by_id();
                    $refund = \SFM4U\Inventory\ManagedOrderStock::refund(['order_id' => $id, 'amount' => $decimal,
                        'reason' => $resellable ? 'Synthetic credit return' : 'Synthetic damaged credit return',
                        'refund_payment' => false, 'restock_items' => $resellable,
                        'line_items' => [$lineId => ['qty' => $quantity, 'refund_total' => $decimal, 'refund_tax' => []]]]);
                    if (is_wp_error($refund)) {
                        throw new RuntimeException('WooCommerce refund could not be recorded.');
                    }
                    $refundId = $refund->get_id();
                    do_action('sfm4u_inventory_capture_return', $line, $refundId, $returned, $quantity, $resellable);
                    $balances = $this->settlement->recordCredit($refundId, $id, $amount, $invoice['open_minor']);
                    $document = (new \SFM4U\POS\ReceiptRegister($this->db))->issue(get_current_blog_id(), 'NETCN', $refundId,
                        ['currency'=>'BDT', 'original_order_id'=>$id, 'line_id'=>$lineId, 'total_minor'=>$amount,
                            'quantity'=>$quantity, 'resellable'=>$resellable, 'issued_at'=>gmdate('c'),
                            'sku'=>$line->get_product()->get_sku(), 'name'=>$line->get_name()] + $balances, 'credit');
                    do_action('sfm4u_synthetic_credit_before_commit', $document);
                    return ['response' => $document, 'movements' => $resellable ? [[
                        'product_id' => $line->get_product()->get_stock_managed_by_id(), 'delta' => $quantity,
                        'type' => 'return', 'order_id' => $refundId,
                    ]] : []];
            });
        } catch (\Throwable $error) {
            if ($productId) {
                \SFM4U\POS\ReceiptRegister::clearFailedCreditCaches($id, $lineId, $productId);
            }
            throw $error;
        }
    }

    public function reconcile(int $actor, int $buyer, int $orderId, string $key): array
    {
        if ($actor !== get_current_user_id() || !current_user_can('sfm4u_b2b_settle')) {
            throw new DomainException('forbidden', 403);
        }
        return $this->execute($actor, $key, ['operation' => 'expire_hold', 'buyer' => $buyer, 'order' => $orderId],
            fn(): array => $this->expireHold($buyer, $orderId, false));
    }

    public function holdReviews(int $after = 0): array
    {
        if (!current_user_can('sfm4u_b2b_settle')) {
            throw new DomainException('forbidden', 403);
        }
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT l.order_id,l.expiry_review,l.amount_minor,b.currency,
                COALESCE((SELECT SUM(a.amount_minor) FROM %i a WHERE a.order_id=l.order_id),0) AS paid_minor,
                COALESCE((SELECT SUM(c.ar_minor) FROM %i c WHERE c.order_id=l.order_id),0) AS credit_minor
             FROM %i l JOIN %i b ON b.buyer=l.buyer LEFT JOIN %i r ON r.order_id=l.order_id
             WHERE l.order_id > %d AND l.expiry_review IS NOT NULL AND r.order_id IS NULL
             ORDER BY l.order_id LIMIT 51',
            $this->db->prefix . 'sfm4u_b2b_allocations', $this->db->prefix . 'sfm4u_b2b_credits',
            $this->ledger, $this->accounts, $this->db->prefix . 'sfm4u_b2b_releases', $after
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        return $rows;
    }

    public function reconcileExpiredHolds(): void
    {
        $this->journal->requirePinnedConnection();
        $reserved = $this->db->prefix . 'wc_reserved_stock';
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT l.buyer,l.order_id FROM %i l LEFT JOIN %i r ON r.order_id=l.order_id
             WHERE r.order_id IS NULL AND l.expiry_review IS NULL
             AND (l.hold_until <= UNIX_TIMESTAMP()
                OR (l.hold_until IS NULL AND EXISTS (SELECT 1 FROM %i old WHERE old.order_id=l.order_id)))
             AND NOT EXISTS (SELECT 1 FROM %i active WHERE active.order_id=l.order_id AND active.expires > NOW())
             ORDER BY l.hold_until,l.order_id LIMIT 50',
            $this->ledger, $this->db->prefix . 'sfm4u_b2b_releases', $reserved, $reserved
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        $failed = [];
        foreach ($rows as $row) {
            $buyer = (int) $row['buyer'];
            $order = (int) $row['order_id'];
            try {
                $this->journal->execute($buyer, 'b2b-expiry', sprintf('stock-hold-%020d-v1', $order), ['order' => $order],
                    fn(): array => ['response' => $this->expireHold($buyer, $order, true), 'movements' => []]);
            } catch (DomainException $error) {
                if ($error->getMessage() !== 'hold_not_expired') {
                    $failed[] = $order;
                }
            } catch (\Throwable) {
                $failed[] = $order;
            }
        }
        if ($failed) {
            throw new RuntimeException('Hold reconciliation will retry orders ' . implode(', ', $failed));
        }
    }

    private function expireHold(int $buyer, int $orderId, bool $scheduled): array
    {
        $this->accountSettings($buyer, true);
        $charge = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE buyer=%d AND order_id=%d FOR UPDATE',
            $this->ledger, $buyer, $orderId), ARRAY_A);
        if (!$charge) {
            throw new DomainException('hold_not_found', 404);
        }
        $invoice = array_column($this->settlement->invoices($buyer), null, 'order_id')[$orderId];
        if ($invoice['released_at'] !== null) {
            return ['order_id' => $orderId, 'released' => true];
        }
        $order = wc_get_order($orderId);
        $nativeExpiry = $this->db->get_var($this->db->prepare(
            'SELECT UNIX_TIMESTAMP(MAX(expires)) FROM %i WHERE order_id=%d',
            $this->db->prefix . 'wc_reserved_stock', $orderId
        ));
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        $validOrder = $order && $order->get_created_via() === 'sfm4u-b2b-credit' && (int) $order->get_customer_id() === $buyer;
        $hasPayment = $invoice['paid_minor'] > 0 || ($validOrder && $order->get_date_paid());
        $hasCredit = $invoice['credit_minor'] > 0;
        $review = $hasPayment || $hasCredit || !$validOrder || !$order->has_status(['pending', 'cancelled']);
        if ($review && !$scheduled) {
            throw new DomainException('hold_requires_review');
        }
        $expires = max((int) $charge['hold_until'], (int) $nativeExpiry);
        if (!$expires || $expires > time()) {
            throw new DomainException('hold_not_expired');
        }
        if ($review) {
            $reason = match (true) {
                $hasPayment => 'payment_allocated',
                $hasCredit => 'credit_recorded',
                default => 'order_state',
            };
            if (!$charge['expiry_review']) {
                if ($this->db->update($this->ledger, ['expiry_review' => $reason], ['order_id' => $orderId]) !== 1) {
                    throw new RuntimeException('database_unavailable', 503);
                }
                if ($validOrder && !$order->add_order_note(__('B2B stock hold expired. Staff review is required before releasing this credit order.', 'sfm4u-wholesale'))) {
                    throw new RuntimeException('database_unavailable', 503);
                }
            }
            return ['order_id' => $orderId, 'released' => false, 'review' => $reason];
        }
        wc_release_stock_for_order($order);
        // WooCommerce's release API is void and can skip deletion when holds are disabled.
        $remaining = $this->db->get_var($this->db->prepare(
            'SELECT 1 FROM %i WHERE order_id=%d LIMIT 1',
            $this->db->prefix . 'wc_reserved_stock', $orderId
        ));
        if ($remaining !== null || $this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        if ($order->has_status('pending')) {
            $order->set_status('cancelled');
            $order->save();
        }
        $this->settlement->release($orderId);
        return ['order_id' => $orderId, 'released' => true];
    }

    private function execute(int $actor, string $key, array $intent, callable $operation): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) {
            throw new DomainException('idempotency_key_invalid');
        }
        $this->journal->requirePinnedConnection();
        return $this->journal->execute($actor, 'b2b', $key, $intent,
            static fn(): array => ['response' => $operation(), 'movements' => []]);
    }

    private function lock(string $id): Quote
    {
        $row = $this->db->get_row($this->db->prepare('SELECT snapshot FROM %i WHERE id=%s FOR UPDATE', $this->quotes, $id), ARRAY_A);
        if (!$row) {
            throw new DomainException('quote_not_found', 404);
        }
        return Quote::restore(json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR));
    }

    private function save(Quote $quote, ?string $createKey = null): void
    {
        $snapshot = wp_json_encode($quote->snapshot(), JSON_THROW_ON_ERROR);
        $written = $createKey !== null
            ? $this->db->insert($this->quotes, ['id' => $quote->id, 'buyer' => $quote->buyerId, 'vendor' => $quote->vendorId,
                'request_hash' => hash('sha256', $createKey), 'snapshot' => $snapshot])
            : $this->db->update($this->quotes, ['snapshot' => $snapshot, 'order_id' => $quote->orderId], ['id' => $quote->id]);
        if ($written !== 1) {
            throw new RuntimeException('database_unavailable', 503);
        }
    }

    private function vendor(int $buyer, array $lines): string
    {
        if (!$lines || count($lines) > 50 || !array_is_list($lines)) {
            throw new DomainException('lines_invalid');
        }
        $vendor = null;
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['sku'] ?? null) || !is_int($line['qty'] ?? null)) {
                throw new DomainException('line_invalid');
            }
            $catalog = $this->catalog->findForBuyer($line['sku'], (string) $buyer);
            if (!$catalog) {
                throw new DomainException('sku_not_found');
            }
            $qty = $line['qty'];
            if ($qty < $catalog['min_qty'] || $qty > $catalog['max_qty'] || ($qty - $catalog['min_qty']) % $catalog['step']) {
                throw new DomainException('quantity_rule');
            }
            if (isset($seen[$catalog['product_id']])) {
                throw new DomainException('sku_duplicate');
            }
            $seen[$catalog['product_id']] = true;
            if ($vendor !== null && $vendor !== $catalog['vendor_id']) {
                throw new DomainException('vendor_mismatch');
            }
            $vendor = $catalog['vendor_id'];
        }
        return (string) $vendor;
    }

    private function accountSettings(int $buyer, bool $lock): array
    {
        $sql = 'SELECT * FROM %i WHERE buyer=%d' . ($lock ? ' FOR UPDATE' : '');
        $row = $this->db->get_row($this->db->prepare($sql, $this->accounts, $buyer), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        if (!$row) {
            throw new DomainException('credit_account_missing');
        }
        return $row;
    }

    private function account(int $buyer): CreditAccount
    {
        $row = $this->accountSettings($buyer, true);
        $account = new CreditAccount((string) $buyer, (int) $row['credit_limit'], (int) $row['net_days'],
            (int) $row['grace_days'], $row['currency']);
        $open = [];
        foreach ($this->settlement->invoices($buyer) as $invoice) {
            if ($invoice['open_minor'] > 0) {
                $open[] = ['idempotency_hash' => 'order:' . $invoice['order_id'], 'amount_minor' => $invoice['open_minor']] + $invoice;
            }
        }
        $account->loadCharges($open);
        return $account;
    }

    private function view(Quote $quote): array
    {
        return ['id' => $quote->id, 'state' => $quote->state, 'revision' => $quote->revision, 'order_id' => $quote->orderId,
            'currency' => $quote->currency, 'request_lines' => $quote->requestLines, 'offer_lines' => $quote->offerLines,
            'valid_until' => $quote->validUntil];
    }
}
