<?php
namespace SFM4U\Inventory;

use DomainException;

/** Integer micro currency units. The value pool conserves cost when an average repeats. */
final class MovingAverage {
    private const SCALE = 1000000;
    private const MAX_QUANTITY = 1000000;
    private const MAX_VALUE = 1000000000000000;
    private int $quantity = 0;
    private int $value = 0;

    public function receive(int $quantity, int $goodsMicros, int $landedMicros = 0): void {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY - $this->quantity
            || $goodsMicros < 0 || $landedMicros < 0 || $goodsMicros > self::MAX_VALUE - $this->value
            || $landedMicros > self::MAX_VALUE - $this->value - $goodsMicros) {
            throw new DomainException('Receipt quantity or cost is outside supported bounds.');
        }
        $this->quantity += $quantity;
        $this->value += $goodsMicros + $landedMicros;
    }

    public function sell(int $quantity): array {
        if ($quantity < 1 || $quantity > $this->quantity) {
            throw new DomainException('Insufficient eligible stock.');
        }
        $cost = self::share($this->value, $this->quantity, $quantity);
        $this->quantity -= $quantity;
        $this->value -= $cost;
        return ['schema' => 1, 'quantity' => $quantity, 'cogs_micros' => $cost];
    }

    public function receiveAtAverage(int $quantity): int {
        if ($this->quantity === 0 || $quantity < 1 || $quantity > self::MAX_QUANTITY - $this->quantity) {
            throw new DomainException('A positive variance needs an existing valuation and a supported quantity.', 409);
        }
        if (intdiv($this->value, $this->quantity) > intdiv(self::MAX_VALUE - $this->value, $quantity)) {
            throw new DomainException('Stock variance cost is outside supported bounds.', 422);
        }
        $cost = self::share($this->value, $this->quantity, $quantity);
        $this->receive($quantity, $cost);
        return $cost;
    }

    /**
     * Allocate return cost against the original line, using the difference between cumulative shares.
     * The caller persists the returned count with the order item and validates idempotency.
     */
    public function returnCost(array $sale, int $alreadyReturned, int $quantity, bool $resellable): int {
        if (($sale['schema'] ?? null) !== 1 || !is_int($sale['quantity'] ?? null)
            || !is_int($sale['cogs_micros'] ?? null) || $alreadyReturned < 0 || $quantity < 1
            || $sale['quantity'] > self::MAX_QUANTITY || $sale['cogs_micros'] < 0
            || $sale['cogs_micros'] > self::MAX_VALUE || $alreadyReturned > $sale['quantity']
            || $quantity > $sale['quantity'] - $alreadyReturned) {
            throw new DomainException('Return does not match the original sale.');
        }
        if (!$resellable) {
            return 0;
        }
        $cost = self::share($sale['cogs_micros'], $sale['quantity'], $alreadyReturned + $quantity)
              - self::share($sale['cogs_micros'], $sale['quantity'], $alreadyReturned);
        $this->receive($quantity, $cost);
        return $cost;
    }

    public function lateLandedCost(int $amount, int $received, int $remaining): array {
        if ($received < 1 || $received > self::MAX_QUANTITY || $remaining < 0 || $remaining > $received
            || $remaining > $this->quantity || $amount < 0 || $amount > self::MAX_VALUE - $this->value) {
            throw new DomainException('Landed cost does not match remaining receipt stock.');
        }
        $stock = self::share($amount, $received, $remaining);
        $this->value += $stock;
        return ['stock_micros' => $stock, 'expense_micros' => $amount - $stock];
    }

    public function state(): array {
        return ['quantity' => $this->quantity, 'value_micros' => $this->value,
            'average_micros' => $this->quantity ? self::share($this->value, $this->quantity, 1) : 0];
    }

    private static function share(int $value, int $totalQuantity, int $part): int {
        $whole = intdiv($value, $totalQuantity) * $part;
        $remainder = ($value % $totalQuantity) * $part;
        return $whole + intdiv($remainder + intdiv($totalQuantity, 2), $totalQuantity);
    }
}
