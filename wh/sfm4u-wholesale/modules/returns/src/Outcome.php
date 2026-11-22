<?php
declare(strict_types=1);

namespace SFM4U\Modules\Returns;

final class Outcome
{
    public static function valid(string $kind, mixed $outcome, mixed $refundId): bool
    {
        if (!is_int($refundId)) {
            return false;
        }
        return match ($outcome) {
            'declined' => $refundId === 0,
            'exchanged' => $kind === 'exchange' && $refundId === 0,
            'warranty_serviced' => $kind === 'warranty' && $refundId === 0,
            'refunded' => $refundId > 0,
            default => false,
        };
    }
}
