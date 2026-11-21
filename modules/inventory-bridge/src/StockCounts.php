<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;

final class StockCounts
{
    private string $table;
    private RequestJournal $journal;
    private StockValuation $valuation;

    public function __construct(private \wpdb $db)
    {
        $this->table = $db->prefix . 'sfm4u_stock_counts';
        $this->journal = new RequestJournal($db);
        $this->valuation = new StockValuation($db);
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->table} (
            id char(36) NOT NULL,
            snapshot longtext NOT NULL,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB {$charset};");
        foreach ([$this->table, $this->db->prefix . 'wc_reserved_stock'] as $table) {
            if ($this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            )) !== 'InnoDB') {
                throw new RuntimeException('Stock counts require transactional tables.');
            }
        }
    }

    public function submit(string $key, int $product, int $expected, int $counted, string $reason): array
    {
        $reason = trim(sanitize_text_field($reason));
        if ($product < 1 || min($expected, $counted) < 0 || max($expected, $counted) > 1000000
            || $reason === '' || strlen($reason) > 250) {
            throw new DomainException('Enter a product, whole stock quantities and a count reason.', 422);
        }

        return $this->submitRecord($key, compact('product', 'expected', 'counted', 'reason'), [
            $product => compact('product', 'expected', 'counted'),
        ]);
    }

    public function submitSession(string $key, string $mode, string $scope, array $lines, string $reason): array
    {
        $scope = trim(sanitize_text_field($scope));
        $reason = trim(sanitize_text_field($reason));
        if (!in_array($mode, ['cycle', 'full', 'reconcile'], true) || $scope === '' || strlen($scope) > 120
            || $reason === '' || strlen($reason) > 250 || !$lines || count($lines) > 30) {
            throw new DomainException('Enter a count mode, scope, reason and 1–30 product rows.', 422);
        }
        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new DomainException('Invalid count row.', 422);
            }
            $product = \SFM4U\Wholesale\Rule::number($line['product'] ?? null, 1, PHP_INT_MAX);
            if (isset($items[$product])) {
                throw new DomainException('Each product must appear once per count.', 422);
            }
            $items[$product] = ['product' => $product,
                'expected' => \SFM4U\Wholesale\Rule::number($line['expected'] ?? null, 0, 1000000),
                'counted' => \SFM4U\Wholesale\Rule::number($line['counted'] ?? null, 0, 1000000)];
        }
        ksort($items, SORT_NUMERIC);
        return $this->submitRecord($key, compact('mode', 'scope', 'reason') + ['lines' => array_values($items)], $items);
    }

    private function submitRecord(string $key, array $intent, array $items): array
    {
        $actor = $this->authorize();
        return $this->journal->execute($actor, 'stock-count', $key, ['action' => 'submit'] + $intent,
            function () use ($actor, $intent, $items) {
                foreach ($items as $line) {
                    $this->product($line['product']);
                    $this->checkBaseline($line, ($intent['mode'] ?? '') === 'reconcile');
                }
                $record = $intent + [
                    'id' => wp_generate_uuid4(), 'status' => 'pending', 'submitted_by' => $actor,
                    'submitted_at' => time(), 'currency' => get_woocommerce_currency(),
                ];
                if ($this->db->insert($this->table, ['id' => $record['id'], 'snapshot' => wp_json_encode($record, JSON_THROW_ON_ERROR)]) !== 1) {
                    throw new RuntimeException('Stock count could not be submitted.');
                }
                return ['response' => $record, 'movements' => []];
            });
    }

    public function find(string $id): array
    {
        $this->authorize();
        return $this->load($id, false);
    }

    public function varianceReport(int $page = 1): array
    {
        $this->authorize();
        $page = max(1, min(1000000, $page));
        // The submission timestamp lives in the existing snapshot for both count formats.
        $snapshots = $this->db->get_col($this->db->prepare(
            "SELECT snapshot FROM %i ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.submitted_at')) AS UNSIGNED) DESC,
                 id DESC LIMIT 51 OFFSET %d", $this->table, ($page - 1) * 50
        ));
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Stock variance report unavailable.');
        }
        $rows = [];
        foreach (array_slice($snapshots, 0, 50) as $snapshot) {
            $record = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
            foreach ($record['lines'] ?? [$record] as $line) {
                $rows[] = [
                    'id' => $record['id'], 'status' => $record['status'], 'product' => $line['product'],
                    'expected' => $line['expected'], 'counted' => $line['counted'],
                    'variance' => $line['counted'] - $line['expected'],
                    'value' => $line['value_delta_micros'] ?? null, 'currency' => $record['currency'],
                ];
            }
        }
        return ['rows' => $rows, 'next' => count($snapshots) > 50];
    }

    public function approve(string $key, string $id): array
    {
        $actor = $this->authorize();
        $changedProducts = [];
        try {
            return $this->journal->execute($actor, 'stock-count', $key, ['action' => 'approve', 'id' => $id], function () use ($id, $actor, &$changedProducts) {
                $record = $this->load($id, true);
                if ($record['status'] !== 'pending' || $record['currency'] !== get_woocommerce_currency()) {
                    throw new DomainException('The count is closed or its currency has changed.', 409);
                }
                if ($record['submitted_by'] === $actor) {
                    throw new DomainException('A different inventory manager must approve this count.', 403);
                }

                $items = $record['lines'] ?? [$record];
                $reconcile = ($record['mode'] ?? '') === 'reconcile';
                $movements = [];
                $results = [];
                foreach ($items as $line) {
                    $productId = $line['product'];
                    $product = $this->product($productId);
                    $before = $this->checkBaseline($line, $reconcile);
                    // The Woo reservation writer also locks the product stock row.
                    $holds = $this->db->get_col($this->db->prepare(
                        'SELECT stock_quantity FROM %i WHERE product_id=%d AND expires > NOW() FOR UPDATE',
                        $this->db->prefix . 'wc_reserved_stock', $productId
                    ));
                    $counted = $line['counted'];
                    if ($counted < array_sum($holds)) {
                        throw new DomainException('The count would reduce stock below unexpired reservations.', 409);
                    }

                    $cost = $this->valuation->adjustCount($productId, $before, $counted, $id);
                    $delta = $counted - $before;
                    if ($delta !== 0 && !$reconcile) {
                        $changedProducts[] = $productId;
                        $updated = wc_update_product_stock($product, abs($delta), $delta > 0 ? 'increase' : 'decrease');
                        if ($updated === false || $updated === null || $this->valuation->lockedStock($productId) !== $counted) {
                            throw new RuntimeException('WooCommerce did not record the counted stock.');
                        }
                    }
                    if ($delta !== 0) {
                        $movements[] = ['product_id' => $productId, 'delta' => $delta, 'type' => 'adjustment'];
                    }
                    $results[] = $line + ['delta' => $delta, 'value_delta_micros' => $cost];
                }
                $record['status'] = 'approved';
                $record += ['approved_by' => $actor, 'approved_at' => time()];
                if (isset($record['lines'])) {
                    $record['lines'] = $results;
                } else {
                    $record += $results[0];
                }
                if ($this->db->update($this->table, ['snapshot' => wp_json_encode($record, JSON_THROW_ON_ERROR)], ['id' => $id]) !== 1) {
                    throw new RuntimeException('Stock count approval could not be saved.');
                }
                do_action('sfm4u_synthetic_stock_count_before_commit', $id);
                return ['response' => $record, 'movements' => $movements];
            });
        } catch (\Throwable $error) {
            foreach ($changedProducts as $productId) {
                clean_post_cache($productId);
                wp_cache_delete($productId, 'post_meta');
            }
            throw $error;
        }
    }

    private function checkBaseline(array $line, bool $reconcile): int
    {
        $actual = $this->valuation->lockedStock($line['product']);
        $expected = $line['expected'];
        if ($reconcile) {
            if ($actual === $line['counted'] && $this->valuation->lockedBookQuantity($line['product']) === $expected) {
                return $expected;
            }
            throw new DomainException('WooCommerce stock or warehouse quantity changed. Review the discrepancy again.', 409);
        }
        if ($actual !== $expected) {
            throw new DomainException('Stock changed since the count. Count the product again.', 409);
        }
        return $actual;
    }

    private function authorize(): int
    {
        $actor = get_current_user_id();
        if (!$actor || !current_user_can('manage_woocommerce')
            || !(\SFM4U\POS\Runtime::enabled() || in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true))) {
            throw new DomainException('Stock counts are restricted to synthetic inventory managers.', 403);
        }
        $this->journal->requirePinnedConnection();
        return $actor;
    }

    private function product(int $id): \WC_Product
    {
        $product = wc_get_product($id);
        if (!$product || !$product->managing_stock() || $product->get_stock_managed_by_id() !== $id) {
            throw new DomainException('Choose a product with independently managed stock.', 422);
        }
        return $product;
    }

    private function load(string $id, bool $lock): array
    {
        if (!wp_is_uuid($id, 4)) {
            throw new DomainException('Invalid stock count ID.', 422);
        }
        $snapshot = $this->db->get_var($this->db->prepare(
            'SELECT snapshot FROM %i WHERE id=%s' . ($lock ? ' FOR UPDATE' : ''),
            $this->table, $id
        ));
        if ($snapshot === null) {
            throw new DomainException('Stock count not found.', 404);
        }
        return json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
    }
}
