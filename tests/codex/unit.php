<?php
require __DIR__ . '/../plugin/sfm4u-wholesale/src/Rule.php';
use SFM4U\Wholesale\Rule;
function same($expected, $actual, $name) {
    if ($expected !== $actual) {
        throw new RuntimeException($name . ': ' . var_export($actual, true));
    }
    echo "PASS $name\n";
}
function rejects(callable $call, string $name) {
    try { $call(); } catch (DomainException $e) { echo "PASS $name\n"; return; }
    throw new RuntimeException('Expected rejection: ' . $name);
}
$input = ['currency' => 'BDT', 'minor' => 1000, 'min' => 12, 'max' => 120, 'step' => 12,
    'packs' => ['case' => 12], 'tiers' => [24 => 800, 12 => 900]];
$rule = Rule::validate($input);
same(10800, Rule::quote($rule, 12, 'case')['line_minor'], 'first all-units tier');
$quote = Rule::quote($rule, 24, 'case');
same([19200, 24, 2], [$quote['line_minor'], $quote['stock_quantity'], $quote['pack_count']], 'two cases and second tier');
same('1375.05', Rule::decimal(137505, 2), 'exact decimal conversion');
same('0.000001', Rule::decimal(1, 6), 'six-decimal currency precision');
rejects(fn() => Rule::quote($rule, 11, 'case'), 'minimum');
rejects(fn() => Rule::quote($rule, 25, 'piece'), 'step');
rejects(fn() => Rule::quote($rule, 132, 'case'), 'maximum');
rejects(fn() => Rule::quote($rule, 24, 'carton'), 'unknown pack');
rejects(fn() => Rule::quote(Rule::validate(array_replace($input, ['step' => 1])), 25, 'case'), 'partial sealed case');
rejects(fn() => Rule::validate(array_replace($input, ['minor' => '1e3'])), 'scientific notation');
rejects(fn() => Rule::validate(array_replace($input, ['packs' => ['case' => 0]])), 'zero pack factor');
rejects(fn() => Rule::number(1.5, 1, 100), 'fractional stock quantity');

same(1000, \SFM4U\Wholesale\Units::convert('1', 'l', 'ml'), 'one loose litre uses a thousand ml');
same(250, \SFM4U\Wholesale\Units::convert('0.250', 'kg', 'g'), 'one quarter kg uses 250 grams');
rejects(fn() => \SFM4U\Wholesale\Units::convert('1', 'piece', 'ml'), 'sealed bottle cannot convert to loose volume');
rejects(fn() => \SFM4U\Wholesale\Units::convert('0.000001', 'kg', 'g'), 'fractional canonical quantity rejected');
$other = Rule::validate(array_replace($input, ['min' => 1, 'step' => 1, 'packs' => ['case' => 20]]));
same(20, Rule::factor($other, 'case'), 'case factor is per product');
same(12, Rule::factor($rule, 'case'), 'other product keeps its original case factor');
rejects(fn() => Rule::validate(array_replace($input, ['packs' => ['case' => ['parent' => 'case']]])), 'recursive pack rejected in flat model');
