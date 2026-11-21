<?php
$root = '/contracts/';
$validSamples = [
    'quote' => ['product_id' => 1, 'pack_id' => 'case12', 'pack_count' => 2],
    'pos_sale' => ['quote_id' => 'Q-1', 'register_id' => 'R1', 'shift_id' => 'S1',
        'idempotency_key' => 'test-key-1234567', 'tender_type' => 'cash', 'tender_minor' => 10000],
    'return' => ['order_id' => 5, 'item_id' => 7, 'stock_quantity' => 12,
        'condition' => 'resellable', 'reason' => 'Synthetic return', 'idempotency_key' => 'test-key-1234567'],
    'goods_receipt' => ['purchase_order_id' => 'PO-1', 'purchase_order_line_id' => 'L1', 'stock_quantity' => 120,
        'expected_revision' => 1, 'idempotency_key' => 'test-key-1234567'],
    'stock_adjustment' => ['product_id' => 1, 'change' => -3, 'reason' => 'Synthetic count',
        'expected_revision' => 2, 'idempotency_key' => 'test-key-1234567'],
    'cash_event' => ['shift_id' => 'S1', 'register_id' => 'R1', 'event_type' => 'cash_out',
        'reason_code' => 'FREIGHT', 'amount_minor' => 12000, 'idempotency_key' => 'test-key-1234567'],
    'error' => ['status' => 409, 'code' => 'stock_conflict', 'message' => 'Out of stock'],
];
foreach ($validSamples as $name => $payload) {
    $contract = json_decode(file_get_contents($root . $name . '.schema.json'), true, 16, JSON_THROW_ON_ERROR);
    $payload['version'] = 1;
    $valid = rest_validate_value_from_schema($payload, $contract);
    $spoof = rest_validate_value_from_schema($payload + ['unit_price' => '0.01'], $contract);
    $badVersion = rest_validate_value_from_schema(array_replace($payload, ['version' => 2]), $contract);
    if ($valid !== true || !is_wp_error($spoof) || !is_wp_error($badVersion)) {
        throw new RuntimeException($name . ': contract validation mismatch');
    }
    echo "PASS $name schema accepts v1 and rejects spoofed price or unsupported version\n";
}
