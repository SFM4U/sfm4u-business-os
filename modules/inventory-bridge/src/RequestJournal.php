<?php
namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use Throwable;

/** Single-database G0 journal. Endpoints and Woo lifecycle wiring are qualified separately. */
final class RequestJournal {
    private string $requests;
    private string $movements;

    public function __construct(private \wpdb $db) {
        $this->requests = $db->prefix . 'sfm4u_inventory_requests';
        $this->movements = $db->prefix . 'sfm4u_inventory_movements';
    }

    public function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->requests} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            merchant_id bigint unsigned NOT NULL,
            channel varchar(20) NOT NULL,
            key_hash char(64) NOT NULL,
            intent_hash char(64) NOT NULL,
            response longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scoped_request (merchant_id,channel,key_hash)
        ) ENGINE=InnoDB {$collation};");
        dbDelta("CREATE TABLE {$this->movements} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint unsigned NOT NULL,
            product_id bigint unsigned NOT NULL,
            stock_delta bigint NOT NULL,
            movement_type varchar(24) NOT NULL,
            order_id bigint unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY request_ref (request_id),
            KEY product_ref (product_id)
        ) ENGINE=InnoDB {$collation};");
        foreach ([$this->requests, $this->movements] as $table) {
            $engine = $this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
            ));
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('Inventory journal requires transactional tables.');
            }
        }
    }

    public function execute(int $merchant, string $channel, string $key, array $intent, callable $operation): array {
        if ($merchant < 1 || !preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $channel)
            || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) {
            throw new DomainException('Invalid request scope or idempotency key.', 422);
        }
        $keyHash = hash('sha256', $key);
        $payload = wp_json_encode(self::canonical($intent), JSON_THROW_ON_ERROR);
        if (strlen($payload) > 65536) {
            throw new DomainException('Request intent too large.', 422);
        }
        $intentHash = hash('sha256', $payload);
        $pinned = $this->db instanceof LossAwareWpdb;
        if ($pinned) {
            $this->requirePinnedConnection();
            $this->db->pinTransaction();
        }
        try {
            $this->query('START TRANSACTION');
            $this->query($this->db->prepare(
                'INSERT INTO %i (merchant_id,channel,key_hash,intent_hash,created_at) VALUES (%d,%s,%s,%s,UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE id = id',
                $this->requests, $merchant, $channel, $keyHash, $intentHash
            ));
            $request = $this->db->get_row($this->db->prepare(
                'SELECT id,intent_hash,response FROM %i WHERE merchant_id=%d AND channel=%s AND key_hash=%s FOR UPDATE',
                $this->requests, $merchant, $channel, $keyHash
            ), ARRAY_A);
            if (!$request) {
                throw new RuntimeException('Could not load the inventory request.');
            }
            if (!hash_equals($request['intent_hash'], $intentHash)) {
                throw new DomainException('Idempotency key belongs to different request data.', 409);
            }
            if ($request['response'] !== null) {
                $response = json_decode($request['response'], true, 32, JSON_THROW_ON_ERROR);
                $this->query('COMMIT');
                return $response;
            }
            $result = $operation();
            if (!is_array($result['response'] ?? null) || !is_array($result['movements'] ?? null)
                || count($result['movements']) > 100) {
                throw new RuntimeException('Missing or oversized inventory outcome.');
            }
            foreach ($result['movements'] as $move) {
                $this->recordMovement((int) $request['id'], $move);
            }
            $response = wp_json_encode($result['response'], JSON_THROW_ON_ERROR);
            if (strlen($response) > 65536) {
                throw new RuntimeException('Inventory response exceeds receipt capacity.');
            }
            $this->query($this->db->prepare(
                'UPDATE %i SET response=%s WHERE id=%d AND response IS NULL',
                $this->requests, $response, $request['id']
            ));
            $this->query('COMMIT');
            return $result['response'];
        } catch (Throwable $error) {
            try {
                $this->db->query('ROLLBACK');
            } catch (Throwable $ignored) {
                // A lost server session already discarded its uncommitted writes.
            }
            throw ($pinned ? $this->db->transactionFailure() : null) ?? $error;
        } finally {
            if ($pinned) $this->db->unpinTransaction();
        }
    }

    public function requirePinnedConnection(): void {
        global $wpdb;
        if (!$this->db instanceof LossAwareWpdb || $this->db !== $wpdb || !method_exists($this->db, 'transactionFailure')) {
            throw new RuntimeException('POS requires one shared connection that cannot reconnect during a transaction.',503);
        }
    }

    public function committed(int $merchant, string $channel, string $key): ?array {
        if ($merchant < 1 || !preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $channel)
            || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) {
            throw new DomainException('Invalid recovery scope.', 422);
        }
        $receipt = $this->db->get_var($this->db->prepare(
            'SELECT response FROM %i WHERE merchant_id=%d AND channel=%s AND key_hash=%s',
            $this->requests, $merchant, $channel, hash('sha256', $key)
        ));
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Cannot establish transaction outcome. Retry recovery.');
        }
        return $receipt === null ? null : json_decode($receipt, true, 32, JSON_THROW_ON_ERROR);
    }

    private function recordMovement(int $request, array $move): void {
        $product = $move['product_id'] ?? 0;
        $delta = $move['delta'] ?? 0;
        $type = $move['type'] ?? '';
        $order = $move['order_id'] ?? 0;
        if (!is_int($product) || $product < 1 || !is_int($delta) || abs($delta) > 1000000 || $delta === 0
            || !in_array($type, ['receipt', 'sale', 'return', 'adjustment'], true)
            || !is_int($order) || $order < 0 || ($type === 'sale' && ($delta > 0 || !$order))
            || (in_array($type, ['receipt', 'return'], true) && $delta < 0)) {
            throw new DomainException('Invalid inventory movement.', 422);
        }
        $this->query($this->db->prepare(
            'INSERT INTO %i (request_id,product_id,stock_delta,movement_type,order_id,created_at)
            VALUES (%d,%d,%d,%s,NULLIF(%d,0),UTC_TIMESTAMP())',
            $this->movements, $request, $product, $delta, $type, $order
        ));
    }

    private function query(string $sql): void {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Inventory journal database operation failed.');
        }
    }

    private static function canonical(array $data): array {
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as $name => $value) {
            if (is_array($value)) {
                $data[$name] = self::canonical($value);
            } elseif (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) {
                throw new DomainException('Inventory intent must contain exact JSON values.', 422);
            }
        }
        return $data;
    }
}
