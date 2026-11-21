<?php
declare(strict_types=1);

namespace SFM4U\POS;

use DomainException;

final class CartCreditAllocation
{
    /**
     * Each line is keyed by its original order-item ID. Preserve receipt order
     * for rounding allocation, regardless of the order of the selected items.
     */
    public static function quote(array $lines, int $rounding, array $returned, array $requested): array
    {
        $net = array_sum(array_column($lines, 'total_minor'));
        if ($net < 0 || $net > 1000000000 || $rounding < -50 || $rounding > 50
            || $net + $rounding < 0 || (!$net && $rounding !== 0) || !$requested
            || array_diff_key($requested, $lines) || array_diff_key($returned, $lines)) {
            throw new DomainException('Invalid original cart or credit selection.', 422);
        }

        $credited = [];
        $offset = 0;
        $amount = 0;
        foreach ($lines as $item => $line) {
            $original = $line['quantity'];
            $total = $line['total_minor'];
            $prior = $returned[$item] ?? 0;
            $next = $requested[$item] ?? 0;
            if (!is_int($original) || $original < 1 || $original > 10000
                || !is_int($total) || $total < 0 || !is_int($prior) || $prior < 0 || $prior > $original
                || !is_int($next) || $next < 0 || (array_key_exists($item, $requested) && !$next)
                || $next > $original - $prior) {
                throw new DomainException('Credit exceeds available original line quantity.', 422);
            }
            $after = $offset + $total;
            $lineRounding = $net ? intdiv($rounding * $after, $net) - intdiv($rounding * $offset, $net) : 0;
            $offset = $after;
            if (!$next) {
                continue;
            }
            $gross = $total + $lineRounding;
            $base = intdiv($total * ($prior + $next), $original) - intdiv($total * $prior, $original);
            $credit = intdiv($gross * ($prior + $next), $original) - intdiv($gross * $prior, $original);
            $credited[$item] = ['quantity' => $next, 'net_minor' => $base, 'rounding_minor' => $credit - $base];
            $amount += $credit;
        }

        return ['amount_minor' => $amount, 'lines' => $credited];
    }
}
