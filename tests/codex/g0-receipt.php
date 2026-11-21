<?php
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
$paper = ($args[0] ?? '') === 'a4' ? 'A4' : '80mm 115mm';
$order = null;
foreach (wc_get_orders(['limit' => 30, 'orderby' => 'date', 'order' => 'DESC']) as $candidate) {
    foreach ($candidate->get_items() as $item) {
        $quote = $item->get_meta('_sfmwh_snapshot_v1');
        if ($candidate->get_status() === 'pending' && $candidate->get_created_via() === 'sfm4u-g0-receipt' && is_array($quote)
            && $quote['pack'] === 'case' && $quote['line_minor'] === 10000) {
            $order = $candidate;
            break 2;
        }
    }
}
if (!$order) {
    throw new RuntimeException('Run the G0 price fixture first.');
}
?>
<!doctype html>
<html lang="bn"><meta charset="UTF-8">
<title>SFM4U synthetic receipt</title>
<style>
@page { size: <?php echo esc_html($paper); ?>; margin: 4mm; }
body { font: 11pt system-ui, sans-serif; margin: 0; color: #111; }
h1 { font-size: 15pt; } table { border-collapse: collapse; width: 100%; }
th, td { padding: 0.4rem 0; text-align: left; border-bottom: 1px solid #777; }
@media print { button { display: none; } }
</style>
<h1>রসিদ / Receipt</h1>
<p>SFM4U Wholesale · SYNTHETIC · COPY</p>
<p>Order <?php echo esc_html($order->get_order_number()); ?></p>
<table><thead><tr><th>পণ্য / Item</th><th>পরিমাণ / Qty</th><th>মূল্য / Total</th></tr></thead><tbody>
<?php foreach ($order->get_items() as $item) : $quote = $item->get_meta('_sfmwh_snapshot_v1'); ?>
<tr><td><?php echo esc_html($item->get_name()); ?></td>
<td><?php echo esc_html($quote['pack_count'] . ' ' . $quote['pack'] . ' × ' . $quote['pack_factor']); ?></td>
<td><?php echo esc_html($order->get_currency() . ' ' . wc_format_decimal($item->get_subtotal(), 2)); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p>মোট / Total: <?php echo esc_html($order->get_currency() . ' ' . wc_format_decimal($order->get_total(), 2)); ?></p>
<p>Test receipt · no payment recorded</p>
<button type="button" onclick="window.print()">প্রিন্ট / Print</button>
</html>
