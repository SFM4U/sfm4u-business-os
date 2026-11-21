<?php
require __DIR__ . '/../modules/inventory-bridge/src/MovingAverage.php';
use SFM4U\Inventory\MovingAverage;

function verifyValue($expected, $observed, string $label): void {
    if ($expected !== $observed) {
        throw new RuntimeException($label . ': ' . var_export($observed, true));
    }
    echo "PASS $label\n";
}
$pool = new MovingAverage();
$pool->receive(3, 1000000);
$pool->receive(2, 2000000);
verifyValue(600000, $pool->state()['average_micros'], 'receipts blend moving-average costs');
$pool = new MovingAverage();
$pool->receive(3, 1000000);
$sale = $pool->sell(3);
$sum = 0;
for ($i = 0; $i < 3; ++$i) {
    $sum += $pool->returnCost($sale, $i, 1, true);
}
verifyValue(1000000, $sum, 'partial return allocation conserves original repeating cost');
verifyValue(1000000, $pool->state()['value_micros'], 'full return restores exact historical value');
$pool = new MovingAverage();
$pool->receive(120, 2400000000);
$pool->sell(24);
verifyValue(['stock_micros' => 96000000, 'expense_micros' => 24000000],
    $pool->lateLandedCost(120000000, 120, 96), 'late freight apportions 96 stock and 24 expense');
$sale = $pool->sell(3);
verifyValue(63000000, $sale['cogs_micros'], 'sale snapshots the updated average');
verifyValue(0, $pool->returnCost($sale, 0, 3, false), 'damaged return contributes no eligible value');
verifyValue(93, $pool->state()['quantity'], 'damaged return never adds eligible stock');
try {
    $pool->sell(94);
    throw new RuntimeException('Oversell accepted');
} catch (DomainException $expected) {
    echo "PASS oversell rejected\n";
}

require __DIR__ . '/../modules/inventory-bridge/src/LandedCostAllocation.php';

verifyValue(
    [11 => 1, 22 => 0, 33 => 0],
    \SFM4U\Inventory\LandedCostAllocation::allocate(1, [33 => 0, 22 => 1, 11 => 1]),
    'landed-cost rounding uses product ID to break ties and leaves zero-weight lines at zero'
);
verifyValue(
    [11 => 0, 22 => 0],
    \SFM4U\Inventory\LandedCostAllocation::allocate(0, [22 => 7, 11 => 1]),
    'zero landed cost produces a zero allocation for each line'
);
verifyValue(
    [11 => 1, 22 => 2, 33 => 4],
    \SFM4U\Inventory\LandedCostAllocation::allocate(7, [33 => 6, 11 => 1, 22 => 3]),
    'landed-cost allocation preserves the entire charge with unequal weights'
);
try {
    \SFM4U\Inventory\LandedCostAllocation::allocate(1, [11 => intdiv(PHP_INT_MAX, 2), 22 => 1]);
    throw new RuntimeException('Overflow-prone weights accepted');
} catch (DomainException $expected) {
    verifyValue(422, $expected->getCode(), 'landed-cost allocation rejects weights outside the safe range');
}
