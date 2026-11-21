<?php
namespace SFM4U\POS;

use RuntimeException;

/** Receipt numbers live in the transaction that writes the authoritative sale. */
final class ReceiptRegister {
    private string $counters;
    private string $receipts;

    public function __construct(private \wpdb $db) {
        $this->counters = $db->prefix . 'sfm4u_pos_receipt_counters';
        $this->receipts = $db->prefix . 'sfm4u_pos_receipts';
    }

    public function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->counters} (
            merchant bigint unsigned NOT NULL,
            register_code varchar(20) NOT NULL,
            last_number bigint unsigned NOT NULL,
            PRIMARY KEY  (merchant,register_code)
        ) ENGINE=InnoDB {$collation};");
        dbDelta("CREATE TABLE {$this->receipts} (
            merchant bigint unsigned NOT NULL,
            register_code varchar(20) NOT NULL,
            sequence_number bigint unsigned NOT NULL,
            order_id bigint unsigned NOT NULL,
            snapshot longtext NOT NULL,
            PRIMARY KEY  (merchant,register_code,sequence_number),
            UNIQUE KEY order_ref (merchant,order_id)
        ) ENGINE=InnoDB {$collation};");
        foreach ([$this->counters, $this->receipts] as $table) {
            $engine = $this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ));
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('Receipt numbering requires InnoDB.');
            }
        }
    }

    public function issue(int $merchant, string $register, int $orderId, array $snapshot, string $type = 'sale'): array {
        self::validateScope($merchant, $register);
        if (!in_array($type, ['sale','credit'], true)) throw new RuntimeException('Unsupported document type.');
        if ($orderId < 1) {
            throw new RuntimeException('Receipt issuance must join the sale transaction.');
        }
        $this->write('INSERT INTO %i (merchant,register_code,last_number) SELECT %d,%s,0 WHERE CONNECTION_ID()=%d
            ON DUPLICATE KEY UPDATE last_number=last_number', $this->counters, $merchant, $register);
        $previous = $this->db->get_var($this->db->prepare(
            'SELECT last_number FROM %i WHERE merchant=%d AND register_code=%s FOR UPDATE',
            $this->counters, $merchant, $register
        ));
        if ($previous === null || (int) $previous >= 999999999) {
            throw new RuntimeException('Receipt sequence unavailable.');
        }
        $next = (int) $previous + 1;
        $record = [
            'schema' => 1,
            'type' => $type,
            'merchant' => $merchant,
            'register' => $register,
            'number' => sprintf('%s-%06d', $register, $next),
            'order_id' => $orderId,
            'sale' => $snapshot,
        ];
        $this->write('UPDATE %i SET last_number=%d WHERE merchant=%d AND register_code=%s AND CONNECTION_ID()=%d',
            $this->counters, $next, $merchant, $register);
        $this->write('INSERT INTO %i (merchant,register_code,sequence_number,order_id,snapshot)
            SELECT %d,%s,%d,%d,%s WHERE CONNECTION_ID()=%d',
            $this->receipts, $merchant, $register, $next, $orderId, wp_json_encode($record, JSON_THROW_ON_ERROR));
        return $record;
    }

    public function reprint(int $merchant, string $register, int $orderId): array {
        return ['kind' => 'COPY', 'receipt' => $this->read($merchant, $register, $orderId)];
    }

    public function read(int $merchant, string $register, int $orderId, bool $lock = false): array {
        self::validateScope($merchant, $register);
        if ($lock) {
            $this->requireTransaction();
        }
        $sql = 'SELECT snapshot FROM %i WHERE merchant=%d AND register_code=%s AND order_id=%d';
        $data = $this->db->get_var($this->db->prepare($sql . ($lock ? ' FOR UPDATE' : ''), $this->receipts, $merchant, $register, $orderId));
        if ($data === null) {
            throw new \DomainException('Receipt not found in this register.', 404);
        }
        return json_decode($data, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function clearFailedCreditCaches(int $order, int $line, int $product): void {
        if ($product) {
            clean_post_cache($product);
            wp_cache_delete($product, 'post_meta');
        }
        if ($line) {
            wp_cache_delete('item-' . $line, 'order-items');
            wp_cache_delete($line, 'order_item_meta');
        }
        \WC_Cache_Helper::invalidate_cache_group('orders');
        wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($order);
    }

    private static function validateScope(int $merchant, string $register): void {
        if ($merchant < 1 || !preg_match('/^[A-Z0-9]{1,20}$/D', $register)) {
            throw new \DomainException('Invalid register scope.', 422);
        }
    }

    private function write(string $query, ...$args): void {
        // A legacy wpdb may retry a statement after reconnecting. Keep that retry from writing.
        $connectionId = (int) $this->db->get_var('SELECT CONNECTION_ID()');
        $this->requireTransaction();
        $args[] = $connectionId;
        if ($connectionId < 1 || $this->db->query($this->db->prepare($query, ...$args)) === false) {
            throw new RuntimeException('Receipt transaction was interrupted.');
        }
        $this->requireTransaction();
    }

    private function requireTransaction(): void {
        // The marker survives to the next statement only inside a transaction.
        $previousSuppression = $this->db->suppress_errors(true);
        try {
            if ($this->db->query('SAVEPOINT sfm4u_receipt_transaction_probe') === false
                || $this->db->query('RELEASE SAVEPOINT sfm4u_receipt_transaction_probe') === false) {
                throw new RuntimeException('Receipt operations require an active transaction.');
            }
        } finally {
            $this->db->suppress_errors($previousSuppression);
        }
    }
}
