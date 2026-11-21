<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * Automatic tier upgrade by spend over a rolling window (e.g. last 365 days of PAID, non-refunded orders).
 * Downgrade is never automatic; it needs an admin decision (policy: protect good customers).
 */
final class TierPolicy
{
    /** @var list<array{tier:string, min_spend_minor:int}> sorted ascending */
    private array $tiers;

    /** @param list<array{tier:string, min_spend_minor:int}> $tiers */
    public function __construct(array $tiers)
    {
        if ($tiers === []) { throw new \InvalidArgumentException('tiers_empty'); }
        usort($tiers, fn($a, $b) => $a['min_spend_minor'] <=> $b['min_spend_minor']);
        $names = array_column($tiers, 'tier');
        if (count($names) !== count(array_unique($names))) { throw new \InvalidArgumentException('tier_duplicate'); }
        $this->tiers = $tiers;
    }

    /**
     * @param list<array{paid_at:int, net_minor:int}> $orders net = paid - refunds
     * @return array{tier:string, changed:bool, spend_minor:int, next_tier:?string, needed_minor:?int}
     */
    public function evaluate(string $currentTier, array $orders, int $now, int $windowDays = 365): array
    {
        $since = $now - $windowDays * 86400;
        $spend = 0;
        foreach ($orders as $o) { if ($o['paid_at'] >= $since && $o['paid_at'] <= $now) { $spend += max(0, $o['net_minor']); } }

        $earned = $this->tiers[0]['tier'];
        foreach ($this->tiers as $t) { if ($spend >= $t['min_spend_minor']) { $earned = $t['tier']; } }

        $rank = array_flip(array_column($this->tiers, 'tier'));
        $curRank = $rank[$currentTier] ?? -1;
        $newTier = ($rank[$earned] > $curRank) ? $earned : $currentTier;   // upgrade only

        $next = null; $needed = null;
        $nr = ($rank[$newTier] ?? -1) + 1;
        if (isset($this->tiers[$nr])) { $next = $this->tiers[$nr]['tier']; $needed = $this->tiers[$nr]['min_spend_minor'] - $spend; }

        return ['tier' => $newTier, 'changed' => $newTier !== $currentTier, 'spend_minor' => $spend, 'next_tier' => $next, 'needed_minor' => $needed];
    }
}
