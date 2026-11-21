<?php
declare(strict_types=1);

require dirname(__DIR__) . '/plugin/sfm4u-wholesale/src/ReportCsv.php';

use SFM4U\Wholesale\ReportCsv;

$checks = [
    'normal product text is unchanged' => ReportCsv::text('Book, A4') === 'Book, A4',
    'formula prefix is neutralized' => ReportCsv::text('=1+1') === "'=1+1",
    'formula after whitespace is neutralized' => ReportCsv::text('  +1') === "'  +1",
    'leading spreadsheet controls are neutralized' => ReportCsv::text("\tbook") === "'\tbook",
    'CSV does not rely on a trailing escape byte' => ReportCsv::text('Book\\') === 'Book\\',
];
$stream = fopen('php://temp', 'w+b');
ReportCsv::write($stream, ['product', 'quantity'], [[ReportCsv::text('Book, "A4"'), -3], [ReportCsv::text('@sku'), 0]]);
rewind($stream);
$checks['CSV header round trips'] = fgetcsv($stream, 0, ',', '"', '') === ['product', 'quantity'];
$checks['CSV quoting and signed numeric values round trip'] = fgetcsv($stream, 0, ',', '"', '') === ['Book, "A4"', '-3'];
$checks['escaped dangerous text stays in one cell'] = fgetcsv($stream, 0, ',', '"', '') === ["'@sku", '0'];
fclose($stream);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
