<?php
declare(strict_types=1);

require '/wordpress/wp-load.php';
require_once '/modules/inventory-bridge/src/RequestJournal.php';
require_once '/modules/pos/src/ReceiptRegister.php';

if (!in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Local Playground only.');
}

global $wpdb;
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$journal->requirePinnedConnection();
$receipts = new \SFM4U\POS\ReceiptRegister($wpdb);
$merchant = (int) getenv('SFM4U_STRESS_MERCHANT');
$worker = (int) getenv('SFM4U_STRESS_WORKER');
if ($merchant < 1 || !in_array($worker, [1, 2], true)) {
    throw new RuntimeException('Missing synthetic scope.');
}

touch('/fixture-out/ready-' . $worker);
$deadline = microtime(true) + 180;
while (!is_file('/fixture-out/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    clearstatcache();
    usleep(100000);
}

$issued = $rolledBack = $replayed = 0;
for ($index = 1; $index <= 500; $index++) {
    $orderId = ($worker - 1) * 500 + $index;
    $key = sprintf('receipt_stress_%06d', $orderId);
    $intent = ['order_id' => $orderId];
    $issue = static function (bool $fail) use ($receipts, $merchant, $orderId): array {
        $record = $receipts->issue($merchant, 'STRESS', $orderId, ['currency' => 'BDT']);
        if ($fail) {
            throw new DomainException('synthetic rollback after receipt write');
        }
        return ['response' => $record, 'movements' => []];
    };

    if ($index % 50 === 0) {
        try {
            $journal->execute($merchant, 'receipt-stress', $key, $intent, fn() => $issue(true));
            throw new RuntimeException('Rollback injection did not run.');
        } catch (DomainException $error) {
            if ($error->getMessage() !== 'synthetic rollback after receipt write') {
                throw $error;
            }
            $rolledBack++;
        }
    }
    $record = $journal->execute($merchant, 'receipt-stress', $key, $intent, fn() => $issue(false));
    $issued++;
    if ($index % 25 === 0) {
        $replay = $journal->execute($merchant, 'receipt-stress', $key, $intent, fn() => $issue(true));
        if ($replay !== $record) {
            throw new RuntimeException('Incorrect committed receipt replay.');
        }
        $replayed++;
    }
}
file_put_contents('/fixture-out/worker-' . $worker . '.txt',
    "PASS worker $worker committed $issued receipts, rolled back $rolledBack writes and replayed $replayed keys\n");
