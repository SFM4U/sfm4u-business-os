<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * Money in integer minor units (BDT poisha). No floats anywhere.
 */
final class Money
{
    private function __construct(public readonly int $minor, public readonly string $currency) {}

    public static function of(int $minor, string $currency = 'BDT'): self
    {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency_invalid');
        }
        return new self($minor, $currency);
    }

    /** Parse "1,234.50" style decimal strings exactly. */
    public static function parse(string $amount, string $currency = 'BDT'): self
    {
        $s = trim($amount);
        if (!preg_match('/^(-?)(\d+|\d{1,3}(?:,\d{3})+)(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw new \InvalidArgumentException('money_format_invalid');
        }
        $digits = ltrim(str_replace(',', '', $m[2]) . str_pad($m[3] ?? '0', 2, '0'), '0') ?: '0';
        $limit = $m[1] === '-' ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new \OverflowException('money_overflow');
        }
        return self::of((int) ($m[1] . $digits), $currency);
    }

    public function add(self $o): self { $this->same($o); return self::of(self::exact($this->minor + $o->minor), $this->currency); }
    public function sub(self $o): self { $this->same($o); return self::of(self::exact($this->minor - $o->minor), $this->currency); }
    public function times(int $qty): self
    {
        $r = $this->minor * $qty;
        if (is_float($r)) { throw new \OverflowException('money_overflow'); }
        return self::of($r, $this->currency);
    }
    public function gt(self $o): bool { $this->same($o); return $this->minor > $o->minor; }
    public function gte(self $o): bool { $this->same($o); return $this->minor >= $o->minor; }
    public function isNegative(): bool { return $this->minor < 0; }

    public function format(): string
    {
        $whole = abs(intdiv($this->minor, 100));
        $fraction = abs($this->minor % 100);
        return ($this->minor < 0 ? '-' : '') . preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', (string) $whole) . '.' . str_pad((string) ($fraction), 2, '0', STR_PAD_LEFT);
    }

    private static function exact(int|float $minor): int
    {
        if (!is_int($minor)) { throw new \OverflowException('money_overflow'); }
        return $minor;
    }

    private function same(self $o): void
    {
        if ($o->currency !== $this->currency) { throw new \InvalidArgumentException('currency_mismatch'); }
    }
}
