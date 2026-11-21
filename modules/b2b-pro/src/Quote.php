<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * RFQ -> Offer -> Order state machine.
 * draft -> submitted -> offered -> accepted -> converted
 *                    \-> rejected      \-> expired (offer past valid_until)
 * Buyer may request revision from "offered" (back to submitted, revision++).
 * Every transition is idempotent by key and appended to immutable history.
 */
final class Quote
{
    public const DRAFT = 'draft', SUBMITTED = 'submitted', OFFERED = 'offered', ACCEPTED = 'accepted',
        CONVERTED = 'converted', REJECTED = 'rejected', EXPIRED = 'expired', CANCELLED = 'cancelled';

    private const ALLOWED = [
        'submit'   => [[self::DRAFT], self::SUBMITTED, 'buyer'],
        'offer'    => [[self::SUBMITTED], self::OFFERED, 'seller'],
        'revise'   => [[self::OFFERED], self::SUBMITTED, 'buyer'],
        'accept'   => [[self::OFFERED], self::ACCEPTED, 'buyer'],
        'reject'   => [[self::SUBMITTED, self::OFFERED], self::REJECTED, 'any'],
        'convert'  => [[self::ACCEPTED], self::CONVERTED, 'system'],
        'cancel'   => [[self::DRAFT, self::SUBMITTED], self::CANCELLED, 'buyer'],
        'expire'   => [[self::OFFERED], self::EXPIRED, 'system'],
    ];

    public string $state = self::DRAFT;
    public int $revision = 0;
    /** @var list<array{sku:string, qty:int, target_price_minor:?int}> */
    public array $requestLines = [];
    /** @var list<array{sku:string, qty:int, unit_price_minor:int}> */
    public array $offerLines = [];
    public ?int $validUntil = null;
    public ?string $orderId = null;
    /** @var list<array{key:string, action:string, actor:string, role:string, from:string, to:string, at:int, note:?string}> */
    private array $history = [];
    /** @var array<string,string> idempotency key => resulting state */
    private array $seen = [];

    public function __construct(public readonly string $id, public readonly string $buyerId, public readonly string $vendorId, public readonly string $currency = 'BDT') {}

    /** @param list<array{sku:string, qty:int, target_price_minor?:?int}> $lines */
    public function setRequestLines(array $lines): void
    {
        if ($this->state !== self::DRAFT && $this->state !== self::SUBMITTED) { throw new \DomainException('quote_locked'); }
        if ($lines === []) { throw new \InvalidArgumentException('lines_empty'); }
        $out = [];
        foreach ($lines as $l) {
            if (!self::validLine($l) || (isset($l['target_price_minor']) && (!is_int($l['target_price_minor']) || $l['target_price_minor'] < 0))) { throw new \InvalidArgumentException('line_invalid'); }
            $out[] = ['sku' => strtoupper($l['sku']), 'qty' => (int) $l['qty'], 'target_price_minor' => $l['target_price_minor'] ?? null];
        }
        $this->requestLines = $out;
    }

    /**
     * @param array{offer_lines?: list<array{sku:string, qty:int, unit_price_minor:int}>, valid_until?: int, order_id?: string, note?: string, expected_revision?: int} $payload
     */
    public function apply(string $action, string $actor, string $role, string $idempotencyKey, int $now, array $payload = []): string
    {
        if (!isset(self::ALLOWED[$action])) { throw new \InvalidArgumentException('action_unknown'); }
        [$from, $to, $needRole] = self::ALLOWED[$action];

        if (!in_array($role, ['buyer', 'seller', 'system'], true) || ($needRole === 'any' ? $role === 'system' : $role !== $needRole)) { throw new \DomainException('role_not_allowed'); }
        if ($role === 'buyer' && $actor !== $this->buyerId) { throw new \DomainException('not_quote_owner'); }
        if ($role === 'seller' && $actor !== $this->vendorId) { throw new \DomainException('not_quote_vendor'); }

        $fingerprint = hash('sha256', json_encode([$action, $actor, $role, $payload], JSON_THROW_ON_ERROR));
        if (isset($this->seen[$idempotencyKey])) {
            $reply = $this->seen[$idempotencyKey];
            if ($reply['intent'] !== $fingerprint) { throw new \DomainException('idempotency_conflict'); }
            return $reply['state'];
        }
        if (isset($payload['expected_revision']) && $payload['expected_revision'] !== $this->revision) {
            throw new \DomainException('offer_changed');
        }
        if ($this->state === self::OFFERED && $this->validUntil !== null && $now >= $this->validUntil && $action !== 'expire') {
            $this->transition('expire', 'system', 'system', $idempotencyKey . ':auto-expire', $now, self::EXPIRED, null);
            throw new \DomainException('offer_expired');
        }
        if (!in_array($this->state, $from, true)) { throw new \DomainException('transition_not_allowed:' . $this->state . '->' . $action); }
        if ($action === 'expire' && $now < $this->validUntil) { throw new \DomainException('offer_not_expired'); }
        switch ($action) {
            case 'submit':
                if ($this->requestLines === []) { throw new \DomainException('lines_empty'); }
                break;
            case 'offer':
                $lines = $payload['offer_lines'] ?? [];
                $valid = $payload['valid_until'] ?? 0;
                if (!is_array($lines) || $lines === []) { throw new \InvalidArgumentException('offer_lines_empty'); }
                if (!is_int($valid) || $valid <= $now) { throw new \InvalidArgumentException('valid_until_past'); }
                foreach ($lines as $l) {
                    if (!self::validLine($l) || !is_int($l['unit_price_minor'] ?? null) || $l['unit_price_minor'] < 0) { throw new \InvalidArgumentException('offer_line_invalid'); }
                }
                $this->offerLines = array_values($lines);
                $this->validUntil = $valid;
                break;
            case 'revise':
                $this->revision++;
                $this->offerLines = [];
                $this->validUntil = null;
                break;
            case 'convert':
                if (empty($payload['order_id'])) { throw new \InvalidArgumentException('order_id_required'); }
                $this->orderId = $payload['order_id'];
                break;
        }
        $this->transition($action, $actor, $role, $idempotencyKey, $now, $to, $payload['note'] ?? null);
        $this->seen[$idempotencyKey] = ['intent' => $fingerprint, 'state' => $to];
        return $this->state;
    }

    public function offerTotalMinor(): int
    {
        $total = Money::of(0, $this->currency);
        foreach ($this->offerLines as $line) {
            $total = $total->add(Money::of($line['unit_price_minor'], $this->currency)->times($line['qty']));
        }
        return $total->minor;
    }

    /** @return list<array<string,mixed>> */
    public function history(): array { return $this->history; }

    public function snapshot(): array
    {
        return get_object_vars($this);
    }

    public static function restore(array $data): self
    {
        $quote = new self($data['id'], $data['buyerId'], $data['vendorId'], $data['currency']);
        foreach (['state', 'revision', 'requestLines', 'offerLines', 'validUntil', 'orderId', 'history', 'seen'] as $field) {
            $quote->$field = $data[$field];
        }
        return $quote;
    }

    private static function validLine(mixed $line): bool
    {
        return is_array($line) && is_string($line['sku'] ?? null)
            && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/iD', $line['sku'])
            && is_int($line['qty'] ?? null) && $line['qty'] > 0 && $line['qty'] <= 1000000;
    }

    private function transition(string $action, string $actor, string $role, string $key, int $now, string $to, ?string $note): void
    {
        $this->history[] = ['key' => $key, 'action' => $action, 'actor' => $actor, 'role' => $role, 'from' => $this->state, 'to' => $to, 'at' => $now, 'note' => $note, 'revision' => $this->revision];
        $this->state = $to;

    }
}
