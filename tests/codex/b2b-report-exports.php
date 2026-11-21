<?php
declare(strict_types=1);

use SFM4U\Inventory\StockCounts;
use SFM4U\Wholesale\LowStock;

require_once '/source/sfm4u-wholesale/src/ReportCsv.php';
require_once '/source/sfm4u-wholesale/src/LowStock.php';

wp_set_current_user(1);
$exportDate = gmdate('Y-m-d');
$margin = $daily->margin($exportDate, $exportDate);
$dailyReference = $daily->report($exportDate, $merchant, 'R1');
check($margin[0] === $exportDate && $margin[2] === 'BDT' && $margin[6] === $dailyReference['coverage_gaps']
    && (float) $margin[3] === $dailyReference['net_sales_minor'] / 100
    && (float) $margin[4] === $dailyReference['net_cogs_micros'] / 1000000
    && ($margin[6] ? $margin[5] === '' : (float) $margin[5] === $dailyReference['gross_profit_micros'] / 1000000),
    'Margin report reconciles to Daily Close and suppresses incomplete profit');
try {
    $daily->margin('2025-02-29', $exportDate);
    check(false, 'invalid margin date must fail');
} catch (DomainException) {
    check(true, 'Margin report rejects impossible calendar dates');
}

$countsReportService = new StockCounts($wpdb);
$countsReport = $countsReportService->varianceReport();
$negative = array_filter($countsReport['rows'], static fn(array $row): bool => $row['variance'] < 0);
check($negative && array_filter($negative, static fn(array $row): bool =>
    $row['status'] === 'approved' && $row['variance'] === $row['counted'] - $row['expected'] && $row['value'] !== null),
    'Stock variance report includes approved signed quantity and posted value');

$exportProduct = new WC_Product_Simple();
$exportProduct->set_name('=1+1');
$exportProduct->set_sku('@synthetic-export');
$exportProduct->set_manage_stock(true);
$exportProduct->set_stock_quantity(2);
$exportProduct->set_low_stock_amount(3);
$exportId = $exportProduct->save();
$exportRows = iterator_to_array(LowStock::csvRows());
check(in_array([$exportId, "'=1+1", "'@synthetic-export", 2, 3], $exportRows, true),
    'Low-stock CSV includes signed-safe product fields and the warehouse threshold');
$exportProduct->set_low_stock_amount(2);
$exportProduct->save();
check(!in_array($exportId, array_column(iterator_to_array(LowStock::csvRows()), 0), true),
    'Low-stock CSV omits products exactly at threshold');

wp_set_current_user($buyer);
foreach ([
    fn() => $daily->margin($exportDate, $exportDate),
    fn() => $countsReportService->varianceReport(),
    fn() => iterator_to_array(LowStock::csvRows()),
] as $reportCall) {
    try {
        $reportCall();
        check(false, 'buyer report read must fail');
    } catch (DomainException $error) {
        check($error->getCode() === 403, 'Buyer cannot read manager report');
    }
}
wp_set_current_user(1);
