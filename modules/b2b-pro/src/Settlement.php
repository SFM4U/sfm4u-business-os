<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

use DomainException;
use RuntimeException;

final class Settlement
{
    private string $payments;
    private string $allocations;
    private string $releases;
    private string $ledger;
    private string $credits;

    public function __construct(private \wpdb $db)
    {
        $this->payments = $db->prefix . 'sfm4u_b2b_payments';
        $this->allocations = $db->prefix . 'sfm4u_b2b_allocations';
        $this->releases = $db->prefix . 'sfm4u_b2b_releases';
        $this->ledger = $db->prefix . 'sfm4u_b2b_ledger';
        $this->credits = $db->prefix . 'sfm4u_b2b_credits';
    }

    public function install(): array
    {
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->payments} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            buyer bigint unsigned NOT NULL,
            actor bigint unsigned NOT NULL,
            request_hash char(64) NOT NULL,
            reference varchar(80) NOT NULL,
            amount_minor bigint unsigned NOT NULL,
            recorded_at bigint unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY buyer_request (buyer,request_hash),
            UNIQUE KEY buyer_reference (buyer,reference)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->allocations} (
            payment_id bigint unsigned NOT NULL,
            order_id bigint unsigned NOT NULL,
            amount_minor bigint unsigned NOT NULL,
            PRIMARY KEY  (payment_id,order_id),
            KEY order_ref (order_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->releases} (
            order_id bigint unsigned NOT NULL,
            released_at bigint unsigned NOT NULL,
            PRIMARY KEY  (order_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->credits} (
            refund_id bigint unsigned NOT NULL,
            order_id bigint unsigned NOT NULL,
            ar_minor bigint unsigned NOT NULL,
            refund_minor bigint unsigned NOT NULL,
            recorded_at bigint unsigned NOT NULL,
            PRIMARY KEY  (refund_id),
            KEY order_date (order_id,recorded_at)
        ) ENGINE=InnoDB {$charset};");
        return [$this->payments, $this->allocations, $this->releases, $this->credits];
    }

    public function invoices(int $buyer): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT l.order_id,l.amount_minor,l.issued_at,l.due_at,r.released_at,
                COALESCE((SELECT SUM(a.amount_minor) FROM %i a WHERE a.order_id=l.order_id),0) AS paid_minor,
                COALESCE((SELECT SUM(c.ar_minor) FROM %i c WHERE c.order_id=l.order_id),0) AS credit_minor
             FROM %i l LEFT JOIN %i r ON r.order_id=l.order_id
             WHERE l.buyer=%d ORDER BY l.issued_at,l.id',
            $this->allocations, $this->credits, $this->ledger, $this->releases, $buyer
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        return array_map(static function (array $row): array {
            foreach ($row as &$value) {
                if ($value !== null) {
                    $value = (int) $value;
                }
            }
            $row['open_minor'] = $row['released_at'] === null ? $row['amount_minor'] - $row['paid_minor'] - $row['credit_minor'] : 0;
            return $row;
        }, $rows);
    }

    public function payments(int $buyer): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT reference,amount_minor,recorded_at FROM %i WHERE buyer=%d ORDER BY recorded_at,id',
            $this->payments, $buyer
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        return array_map(static fn(array $row): array => [
            'reference' => $row['reference'],
            'amount_minor' => (int) $row['amount_minor'],
            'recorded_at' => (int) $row['recorded_at'],
        ], $rows);
    }

    /** Called under the buyer account row lock. */
    public function record(int $buyer, int $actor, string $key, array $invoices, int $amount, string $reference): array
    {
        if ($amount < 1 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,79}$/D', $reference)) {
            throw new DomainException('settlement_invalid');
        }
        if ($amount > array_sum(array_column($invoices, 'open_minor'))) {
            throw new DomainException('overpayment');
        }
        if ($this->db->get_var($this->db->prepare('SELECT id FROM %i WHERE buyer=%d AND reference=%s', $this->payments, $buyer, $reference))) {
            throw new DomainException('settlement_reference_exists');
        }
        $this->insert($this->payments, ['buyer' => $buyer, 'actor' => $actor, 'request_hash' => hash('sha256', $key),
            'reference' => $reference, 'amount_minor' => $amount, 'recorded_at' => time()]);
        $payment = $this->db->insert_id;
        $remaining = $amount;
        $allocations = [];
        foreach ($invoices as $invoice) {
            $allocated = min($remaining, $invoice['open_minor']);
            if ($allocated > 0) {
                $entry = ['order_id' => $invoice['order_id'], 'amount_minor' => $allocated];
                $this->insert($this->allocations, ['payment_id' => $payment] + $entry);
                $allocations[] = $entry;
                $remaining -= $allocated;
            }
        }
        return ['payment_id' => $payment, 'amount_minor' => $amount, 'allocations' => $allocations];
    }

    public function recordCredit(int $refundId, int $orderId, int $amount, int $open): array
    {
        $ar = min($amount, $open);
        $refund = $amount - $ar;
        $this->insert($this->credits, ['refund_id' => $refundId, 'order_id' => $orderId,
            'ar_minor' => $ar, 'refund_minor' => $refund, 'recorded_at' => time()]);
        return ['ar_minor' => $ar, 'refund_minor' => $refund];
    }

    public function aging(int $cutoff, string $currency): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT l.amount_minor,l.due_at,
                COALESCE((SELECT SUM(a.amount_minor) FROM %i a JOIN %i p ON p.id=a.payment_id
                    WHERE a.order_id=l.order_id AND p.recorded_at<%d),0) AS paid,
                COALESCE((SELECT SUM(c.ar_minor) FROM %i c WHERE c.order_id=l.order_id AND c.recorded_at<%d),0) AS credited
             FROM %i l JOIN %i b ON b.buyer=l.buyer LEFT JOIN %i r ON r.order_id=l.order_id
             WHERE b.currency=%s AND l.issued_at<%d AND (r.released_at IS NULL OR r.released_at>=%d)',
            $this->allocations, $this->payments, $cutoff, $this->credits, $cutoff,
            $this->ledger, $this->db->prefix . 'sfm4u_b2b_accounts', $this->releases, $currency, $cutoff, $cutoff
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('database_unavailable', 503);
        }
        $totals = ['0-30'=>0, '31-60'=>0, '61-90'=>0, '90+'=>0];
        foreach ($rows as $row) {
            $days = intdiv(($cutoff - DAY_IN_SECONDS) - ((int) $row['due_at'] - (int) $row['due_at'] % DAY_IN_SECONDS), DAY_IN_SECONDS);
            $bucket = match (true) { $days <= 30 => '0-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+' };
            $totals[$bucket] += (int) $row['amount_minor'] - (int) $row['paid'] - (int) $row['credited'];
        }
        return $totals;
    }

    public function release(int $order): void
    {
        $this->insert($this->releases, ['order_id' => $order, 'released_at' => time()]);
    }

    private function insert(string $table, array $row): void
    {
        if ($this->db->insert($table, $row) !== 1) {
            throw new RuntimeException('database_unavailable', 503);
        }
    }
}
