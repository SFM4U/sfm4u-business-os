<?php
declare(strict_types=1);

require __DIR__ . '/../../wh/sfm4u-wholesale/modules/pos/src/CartCreditAllocation.php';

use SFM4U\POS\CartCreditAllocation;

$lines = [10 => ['quantity' => 3, 'total_minor' => 101], 20 => ['quantity' => 2, 'total_minor' => 149]];
foreach ([-50, -1, 0, 1, 50] as $rounding) {
    $full = CartCreditAllocation::quote($lines, $rounding, [], [10 => 3, 20 => 2]);
    $reverse = CartCreditAllocation::quote($lines, $rounding, [], [20 => 2, 10 => 3]);
    $a = CartCreditAllocation::quote($lines, $rounding, [], [10 => 1, 20 => 1]);
    $b = CartCreditAllocation::quote($lines, $rounding, [10 => 1, 20 => 1], [20 => 1]);
    $c = CartCreditAllocation::quote($lines, $rounding, [10 => 1, 20 => 2], [10 => 2]);
    if ($full !== $reverse || $full['amount_minor'] !== 250 + $rounding
        || $a['amount_minor'] + $b['amount_minor'] + $c['amount_minor'] !== $full['amount_minor']
        || min($a['amount_minor'], $b['amount_minor'], $c['amount_minor']) < 0) {
        throw new RuntimeException('Credit does not reconcile: ' . $rounding);
    }
    echo "PASS split and reordered credit reconciles for rounding $rounding\n";
}
foreach ([[10 => 4], [99 => 1], [10 => 0], [20 => '1']] as $selection) {
    $denied = false;
    try {
        CartCreditAllocation::quote($lines, 0, [], $selection);
    } catch (DomainException $e) {
        $denied = $e->getCode() === 422;
    }
    if (!$denied) {
        throw new RuntimeException('Invalid refund selection accepted');
    }
    echo "PASS invalid credit selection rejected\n";
}

for ($i = 1; $i <= 100; ++$i) {
    $sample = [1 => ['quantity' => 3, 'total_minor' => $i], 2 => ['quantity' => 2, 'total_minor' => $i % 9]];
    $net = $i + $i % 9;
    for ($rounding = -min($net, 50); $rounding <= 50; ++$rounding) {
        $sum = 0;
        $used = [];
        foreach ([1, 2, 1, 2, 1] as $id) {
            $part = CartCreditAllocation::quote($sample, $rounding, $used, [$id => 1])['amount_minor'];
            if ($part < 0) {
                throw new RuntimeException('Negative credit amount');
            }
            $sum += $part;
            $used[$id] = ($used[$id] ?? 0) + 1;
        }
        if ($sum !== $net + $rounding) {
            throw new RuntimeException('Split credit lost a minor unit');
        }
    }
}
echo "PASS split allocation reconciles across 100 carts and all valid rounding adjustments\n";
