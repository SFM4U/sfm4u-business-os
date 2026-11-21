<?php
declare(strict_types=1);

define('ARRAY_A', 'ARRAY_A');
define('WP_CLI', true);

function current_user_can(string $capability): bool { return $GLOBALS['manager'] && $capability === 'manage_woocommerce'; }
function get_current_user_id(): int { return $GLOBALS['manager'] ? 1 : 0; }
function home_url(): string { return 'http://127.0.0.1'; }
function wp_parse_url(string $url, int $part): string { return parse_url($url, $part); }

class wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $ledger = [];
    public array $snapshots = [];
    public array $statements = [];
    public array $parameters = [];

    public function prepare(string $query, mixed ...$parameters): string
    {
        $this->parameters = $parameters;
        return $query;
    }

    public function query($query)
    {
        $this->statements[] = $query;
        return 1;
    }

    public function get_results(string $query, string $format): array { return $this->ledger; }
    public function get_col(string $query): array { return $this->snapshots; }
}

$root = dirname(__DIR__);
require "$root/plugin/sfm4u-wholesale/src/Rule.php";
require "$root/modules/pos/src/Runtime.php";
require "$root/modules/b2b-pro/src/Settlement.php";
foreach (['LossAwareWpdb', 'RequestJournal', 'StockValuation', 'SupplierPayables', 'StockCounts', 'DailyClose'] as $file) {
    require "$root/modules/inventory-bridge/src/$file.php";
}
function report_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$manager = true;
$wpdb = new \SFM4U\Inventory\LossAwareWpdb();
$stock = new \SFM4U\Inventory\StockValuation($wpdb);
$margin = new \SFM4U\Inventory\DailyClose($wpdb, new \SFM4U\Wholesale\Pro\Settlement($wpdb),
    new \SFM4U\Inventory\SupplierPayables($wpdb, $stock));
$native = ['currency' => 'BDT', 'sales_minor' => 15000, 'returns_minor' => 2000, 'writeoff_micros' => 1000000,
    'coverage_gaps' => 0, 'cogs_micros' => 72000000, 'reversed_micros' => 9000000];
$wpdb->ledger = [['channel' => 'woo-stock', 'merchant_id' => 1, 'response' => json_encode($native)]];
$result = $margin->margin('2025-01-01', '2025-01-31');
report_check($result === ['2025-01-01', '2025-01-31', 'BDT', '130.00', '64.000000', '66.000000', 0, '50.77'],
    'Margin subtracts returns, nets COGS and computes its percentage');
report_check($wpdb->parameters === ['wp_sfm4u_inventory_requests', '2025-01-01', '2025-02-01']
    && $wpdb->statements === ['START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', 'COMMIT'], 'Margin includes the through date in a read-only snapshot');
$native['coverage_gaps'] = 1;
$wpdb->ledger[0]['response'] = json_encode($native);
$result = $margin->margin('2025-01-01', '2025-01-01');
report_check($result[5] === '' && $result[6] === 1 && $result[7] === '', 'Incomplete cost coverage leaves profit and percentage unavailable');
try { $margin->margin('2025-01-01', '2025-02-01'); report_check(false, 'allowable range exceeded'); }
catch (DomainException) { report_check(true, 'Margin refuses ranges longer than 31 days'); }

$count = new \SFM4U\Inventory\StockCounts($wpdb);
$wpdb->snapshots = [
    json_encode(['id' => 'old-single', 'status' => 'pending', 'currency' => 'BDT', 'product' => 4, 'expected' => 5, 'counted' => 2]),
    json_encode(['id' => 'session', 'status' => 'approved', 'currency' => 'BDT', 'lines' => [
        ['product' => 2, 'expected' => 4, 'counted' => 7, 'value_delta_micros' => 3000000],
        ['product' => 3, 'expected' => 1, 'counted' => 1, 'value_delta_micros' => 0],
    ]]),
];
$rows = $count->varianceReport()['rows'];
report_check(array_column($rows, 'variance') === [-3, 3, 0] && array_column($rows, 'value') === [null, 3000000, 0],
    'Variance includes pending and approved lines and preserves zero value');
$manager = false;
foreach ([fn() => $count->varianceReport(), fn() => $margin->margin('2025-01-01', '2025-01-01')] as $report) {
    try { $report(); report_check(false, 'report must be denied'); }
    catch (DomainException $error) { report_check($error->getCode() === 403, 'Non-manager report access is denied'); }
}

require "$root/plugin/sfm4u-wholesale/src/ReportCsv.php";
require "$root/plugin/sfm4u-wholesale/src/LowStock.php";

final class WC_Product
{
    public function __construct(private int $id, private int $owner, private int $quantity) {}
    public function get_id(): int { return $this->id; }
    public function get_status(): string { return 'publish'; }
    public function managing_stock(): bool { return true; }
    public function get_stock_managed_by_id(): int { return $this->owner; }
    public function get_low_stock_amount(string $context): int { return 3; }
    public function get_stock_quantity(): int { return $this->quantity; }
    public function get_name(): string { return '=synthetic'; }
    public function get_sku(): string { return 'TEST-' . $this->id; }
}

function get_posts(array $query): array { return array_slice(range(1, 53), $query['offset'], $query['numberposts']); }
function wc_get_product(int $id): WC_Product
{
    return new WC_Product($id, $id === 2 ? 1 : $id, $id === 1 || $id === 2 || $id === 53 ? 2 : 3);
}

$manager = true;
$low = iterator_to_array(\SFM4U\Wholesale\LowStock::csvRows());
report_check($low === [[1, "'=synthetic", 'TEST-1', 2, 3], [53, "'=synthetic", 'TEST-53', 2, 3]],
    'Low stock export spans pages, guards names and skips shared and equal-threshold rows');
$manager = false;
try { iterator_to_array(\SFM4U\Wholesale\LowStock::csvRows()); report_check(false, 'export must be denied'); }
catch (DomainException $error) { report_check($error->getCode() === 403, 'Low stock export denies non-managers'); }
