<?php
namespace SFM4U\Wholesale;

use DomainException;

/** Exact scale conversions. A sealed pack enters separately as a merchant-defined factor. */
final class Units {
    private const UNITS = [
        'piece' => ['count', 1],
        'mg' => ['mass', 1], 'g' => ['mass', 1000], 'kg' => ['mass', 1000000],
        'ml' => ['volume', 1], 'l' => ['volume', 1000],
        'mm' => ['length', 1], 'cm' => ['length', 10], 'm' => ['length', 1000],
    ];

    public static function base($unit): string {
        if (!is_string($unit) || !isset(self::UNITS[$unit])) {
            throw new DomainException('Choose a stock unit of piece, mg, g, kg, ml, l, mm, cm or m.');
        }
        return $unit;
    }

    public static function convert($quantity, string $from, string $to): int {
        self::base($from);
        self::base($to);
        [$fromDimension, $factor] = self::UNITS[$from];
        [$toDimension, $divisor] = self::UNITS[$to];
        if ($fromDimension !== $toDimension) {
            throw new DomainException('Quantity conversion crosses stock dimensions.');
        }
        if ((!is_int($quantity) && !is_string($quantity))
            || !preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,6}))?$/D', (string) $quantity, $parts)) {
            throw new DomainException('Enter a decimal quantity with at most six decimal places.');
        }
        $scaled = (int) $parts[1] * 1000000 + (int) str_pad($parts[2] ?? '', 6, '0', STR_PAD_RIGHT);
        if ($scaled > intdiv(PHP_INT_MAX, $factor)) {
            throw new DomainException('Quantity exceeds the stock conversion bound.');
        }
        $numerator = $scaled * $factor;
        $denominator = $divisor * 1000000;
        if ($numerator < $denominator || $numerator % $denominator || intdiv($numerator, $denominator) > 1000000) {
            throw new DomainException('Quantity must resolve to whole supported stock units.');
        }
        return intdiv($numerator, $denominator);
    }
}
