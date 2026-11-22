<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Outcome.php';

use SFM4U\Modules\Returns\Outcome;

$cases = [
    ['return', 'declined', 0, true],
    ['return', 'refunded', 3, true],
    ['return', 'refunded', 0, false],
    ['return', 'refunded', '3', false],
    ['exchange', 'exchanged', 0, true],
    ['warranty', 'warranty_serviced', 0, true],
    ['return', 'exchanged', 0, false],
    ['exchange', 'warranty_serviced', 0, false],
    ['warranty', 'declined', 3, false],
    ['return', 'pending', 0, false],
];
$fail = 0;
foreach ($cases as $index => [$kind, $outcome, $refund, $expected]) {
    if (Outcome::valid($kind, $outcome, $refund) !== $expected) {
        ++$fail;
        echo "FAIL case $index\n";
    }
}
echo 'pass ' . (count($cases) - $fail) . " fail $fail\n";
exit($fail > 0 ? 1 : 0);
