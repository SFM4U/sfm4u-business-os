<?php
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
[$id, $barrier, $actor] = $args;
$order = wc_get_order((int) $id);
if (!$order || !touch($barrier . '/' . $actor)) {
    throw new RuntimeException('Worker could not start.');
}
for ($tries = 0; !is_file($barrier . '/go'); ++$tries) {
    if ($tries === 300) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(100000);
}
try {
    wc_reserve_stock_for_order($order);
    echo "RESERVED $actor\n";
} catch (\Automattic\WooCommerce\Checkout\Helpers\ReserveStockException $e) {
    echo "CONFLICT $actor " . $e->getErrorCode() . "\n";
}
