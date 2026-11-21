<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * Credit limit + NET terms (Net 7/15/30/60/90). Ledger-based: charges and payments
 * are append-only entries; balance is derived. Blocks new credit orders if the limit
 * would be exceeded or any invoice is overdue beyond the grace days.
 */
final class CreditAccount
{
    /** @var list<array{key:string, type:string, amount_minor:int, at:int, due_at:?int, ref:string}> */
    private array $ledger = [];
    /** @var array<string,array> */
    private array $keys = [];

    public function __construct(
        public readonly string $buyerId,
        public int $limitMinor,
        public int $netDays = 30,
        public int $graceDays = 0,
        public readonly string $currency = 'BDT',
    ) {
        if ($graceDays < 0 || $graceDays > 365) { throw new \InvalidArgumentException('grace_invalid'); }
        if ($limitMinor < 0) { throw new \InvalidArgumentException('limit_negative'); }
        if (!in_array($netDays, [0, 7, 15, 30, 45, 60, 90], true)) { throw new \InvalidArgumentException('net_terms_invalid'); }
    }

    public function balanceMinor(): int
    {
        $b = 0;
        foreach ($this->ledger as $e) { $b += $e['type'] === 'charge' ? $e['amount_minor'] : -$e['amount_minor']; }
        return $b;
    }

    public function availableMinor(): int { return max(0, $this->limitMinor - $this->balanceMinor()); }

    /** Oldest-first allocation of payments to charges (FIFO) to find overdue amount. */
    public function overdueMinor(int $now): int
    {
        $paid = 0;
        foreach ($this->ledger as $e) { if ($e['type'] === 'payment') { $paid += $e['amount_minor']; } }
        $overdue = 0;
        foreach ($this->ledger as $e) {
            if ($e['type'] !== 'charge') { continue; }
            $cover = min($paid, $e['amount_minor']);
            $paid -= $cover;
            $open = $e['amount_minor'] - $cover;
            if ($open > 0 && $e['due_at'] !== null && $now > $e['due_at'] + $this->graceDays * 86400) { $overdue += $open; }
        }
        return $overdue;
    }

    /** @return array{ok:bool, code:?string, available_minor:int} */
    public function check(int $orderMinor, int $now): array
    {
        if ($orderMinor <= 0) { return ['ok' => false, 'code' => 'amount_invalid', 'available_minor' => $this->availableMinor()]; }
        if ($this->overdueMinor($now) > 0) { return ['ok' => false, 'code' => 'overdue_invoices', 'available_minor' => $this->availableMinor()]; }
        if ($orderMinor > $this->availableMinor()) { return ['ok' => false, 'code' => 'credit_limit_exceeded', 'available_minor' => $this->availableMinor()]; }
        return ['ok' => true, 'code' => null, 'available_minor' => $this->availableMinor()];
    }

    /** Charge an order on terms. Idempotent by key. Returns due timestamp. */
    public function charge(string $key, int $amountMinor, int $now, string $orderRef): int
    {
        if ($this->isReplay($key, 'charge', $amountMinor, $orderRef)) { return $this->keys[$key]['due_at']; }
        $c = $this->check($amountMinor, $now);
        if (!$c['ok']) { throw new \DomainException((string) $c['code']); }
        $due = $now + $this->netDays * 86400;
        $this->ledger[] = ['key' => $key, 'type' => 'charge', 'amount_minor' => $amountMinor, 'at' => $now, 'due_at' => $due, 'ref' => $orderRef];
        $this->keys[$key] = $this->ledger[array_key_last($this->ledger)];
        return $due;
    }

    public function pay(string $key, int $amountMinor, int $now, string $ref): void
    {
        if ($this->isReplay($key, 'payment', $amountMinor, $ref)) { return; }
        if ($amountMinor <= 0) { throw new \InvalidArgumentException('amount_invalid'); }
        if ($amountMinor > $this->balanceMinor()) { throw new \DomainException('overpayment'); }
        $this->ledger[] = ['key' => $key, 'type' => 'payment', 'amount_minor' => $amountMinor, 'at' => $now, 'due_at' => null, 'ref' => $ref];
        $this->keys[$key] = $this->ledger[array_key_last($this->ledger)];
    }

    /** Restore committed charges without reapplying current account policy. */
    public function loadCharges(array $charges): void
    {
        foreach ($charges as $charge) {
            $entry = ['key' => $charge['idempotency_hash'], 'type' => 'charge', 'amount_minor' => (int) $charge['amount_minor'],
                'at' => (int) $charge['issued_at'], 'due_at' => (int) $charge['due_at'], 'ref' => (string) $charge['order_id']];
            $this->ledger[] = $entry;
            $this->keys[$entry['key']] = $entry;
        }
    }

    private function isReplay(string $key, string $type, int $amountMinor, string $ref): bool
    {
        if (!isset($this->keys[$key])) { return false; }
        $entry = $this->keys[$key];
        if ($entry['type'] !== $type || $entry['amount_minor'] !== $amountMinor || $entry['ref'] !== $ref) {
            throw new \DomainException('idempotency_conflict');
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function statement(): array { return $this->ledger; }
}
