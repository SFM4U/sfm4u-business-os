<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    exit(1);
}
require '/modules/inventory-bridge/src/RequestJournal.php';
[$mode, $testKey] = $args;
global $wpdb;
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$intent = ['stock_delta' => 120, 'product_id' => 12345];
$expected = ['received' => 120, 'receipt' => 'synthetic'];
$movements = $wpdb->prefix . 'sfm4u_inventory_movements';
$count = static fn() => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $movements));

if ($mode === 'first') {
    $journal->install();
    $prior = $count();
    try {
        $journal->execute(1, 'receive', $testKey, $intent, static fn() => [
            'response' => ['never' => 'commit'],
            'movements' => [
                ['product_id' => 12345, 'type' => 'receipt', 'delta' => 120],
                ['product_id' => 12345, 'type' => 'receipt', 'delta' => -120],
            ],
        ]);
        throw new RuntimeException('Invalid movement committed.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 422 || $count() !== $prior) {
            throw new RuntimeException('The first movement survived rollback.');
        }
        echo "PASS invalid second movement rolls back the first movement and request\n";
    }
    $saved = $journal->execute(1, 'receive', $testKey, $intent, static fn() => [
        'response' => $expected,
        'movements' => [['product_id' => 12345, 'type' => 'receipt', 'delta' => 120]],
    ]);
    if ($saved !== $expected || $count() !== $prior + 1) {
        throw new RuntimeException('Journal did not commit one movement and response.');
    }
    echo "PASS request response and one stock movement commit together\n";
} elseif ($mode === 'replay') {
    $prior = $count();
    $never = static function () { throw new RuntimeException('Replay executed a second operation.'); };
    if ($journal->execute(1, 'receive', $testKey, array_reverse($intent, true), $never) !== $expected || $count() !== $prior) {
        throw new RuntimeException('Process restart changed the replay outcome.');
    }
    echo "PASS a new PHP process replays the same response without writing a movement\n";
    try {
        $journal->execute(1, 'receive', $testKey, array_replace($intent, ['stock_delta' => 121]), $never);
        throw new RuntimeException('Different payload reused request key.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409 || $count() !== $prior) {
            throw new RuntimeException('Replay conflict changed the ledger.');
        }
        echo "PASS a changed replay is a 409 and keeps the ledger unchanged\n";
    }
} else {
    exit(1);
}
