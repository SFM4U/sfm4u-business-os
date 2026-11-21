<?php
// Build the exact ERPNext documents the plugin sends (ErpSync pure builders), for a replay against a real ERPNext test site.
use SFM4U\Wholesale\Erp\ErpSync;
$keep = [get_option(ErpSync::OPT_CASH), get_option(ErpSync::OPT_ROUND_OFF)];
update_option(ErpSync::OPT_CASH, 'Cash - SBT');
$co = 'SFM4U BD Test'; $wh = 'Stores - SBT'; $d = gmdate('Y-m-d'); $ro = 'Round Off - SBT';
$member = 'SFM4U ' . \SFM4U\Wholesale\MemberPrice::mask('0001234') . ' #t' . substr(md5((string) microtime(true)), 0, 5);
// A: walk-in cash POS sale, rice 2 pcs line total 66.67 (odd unit rate) + cash rounding fee 0.33 -> 67.00
$a = ErpSync::invoiceDoc(['id' => 'T1', 'number' => 'T1', 'date' => $d, 'currency' => 'BDT', 'scale' => 2, 'source' => 'pos', 'register' => 'MAIN',
    'lines' => [['sku' => 'RN-RICE', 'qty' => 2, 'total_minor' => 6667]], 'rounding_minor' => 33, 'round_off_account' => $ro], $co, $wh, 'Walk-in Customer');
$a['is_pos'] = 0;
// B: khata member sale, salt 3 x 100 = 300, 100 on credit, 20 old due paid -> cash 220 (200 to this invoice)
$b = ErpSync::invoiceDoc(['id' => 'T2', 'number' => 'T2', 'date' => $d, 'currency' => 'BDT', 'scale' => 2, 'source' => 'pos', 'register' => 'MAIN',
    'lines' => [['sku' => 'RN-SALT', 'qty' => 3, 'total_minor' => 30000]], 'rounding_minor' => 0, 'round_off_account' => $ro], $co, $wh, '@MEMBER@');
$b['is_pos'] = 0; $b['remarks'] .= '; khata: on credit 10000, old due paid 2000 (minor units)';
$out = [
  'member' => $member,
  'steps' => [
    ['name' => 'customer', 'customer' => $member],
    ['name' => 'inv_a', 'doctype' => 'Sales Invoice', 'doc' => $a, 'expect_total' => 67.00],
    ['name' => 'pe_a', 'doctype' => 'Payment Entry', 'doc' => ErpSync::paymentDoc('Receive', $co, 'Walk-in Customer', 6700, 2, 'WC-PAY-T1-' . $d, $d, [['@inv_a@', 6700]]), 'expect_outstanding' => ['inv_a', 0]],
    ['name' => 'inv_b', 'doctype' => 'Sales Invoice', 'doc' => $b, 'expect_total' => 300.00],
    ['name' => 'pe_b', 'doctype' => 'Payment Entry', 'doc' => ErpSync::paymentDoc('Receive', $co, '@MEMBER@', 22000, 2, 'WC-PAY-T2-' . $d, $d, [['@inv_b@', 20000]]), 'expect_outstanding' => ['inv_b', 100.0]],
    ['name' => 'pe_due', 'doctype' => 'Payment Entry', 'doc' => ErpSync::paymentDoc('Receive', $co, '@MEMBER@', 5000, 2, 'KHATA-T3-' . $d, $d)],
    ['name' => 'ret_a', 'doctype' => 'Sales Invoice', 'doc' => (function () use ($co, $wh, $d, $ro) { $r = ErpSync::returnDoc(['id' => 'R1', 'number' => 'T1', 'date' => $d, 'currency' => 'BDT', 'scale' => 2, 'source' => 'pos',
        'lines' => [['sku' => 'RN-RICE', 'qty' => -1, 'total_minor' => -3334]], 'rounding_minor' => -16, 'round_off_account' => $ro, 'restock' => '1'], '@inv_a@', $co, $wh, 'Walk-in Customer'); $r['is_pos'] = 0; return $r; })(), 'expect_total' => -33.50],
    ['name' => 'pe_ret_a', 'doctype' => 'Payment Entry', 'doc' => ErpSync::paymentDoc('Pay', $co, 'Walk-in Customer', 3350, 2, 'WC-RPAY-R1-' . $d, $d)],
    ['name' => 'ret_b', 'doctype' => 'Sales Invoice', 'doc' => (function () use ($co, $wh, $d, $ro) { $r = ErpSync::returnDoc(['id' => 'R2', 'number' => 'T2', 'date' => $d, 'currency' => 'BDT', 'scale' => 2, 'source' => 'pos',
        'lines' => [['sku' => 'RN-SALT', 'qty' => -1, 'total_minor' => -10000]], 'rounding_minor' => 0, 'round_off_account' => $ro, 'restock' => '0'], '@inv_b@', $co, $wh, '@MEMBER@'); $r['is_pos'] = 0; return $r; })(), 'expect_total' => -100.00],
  ],
];
update_option(ErpSync::OPT_CASH, $keep[0] === false ? '' : $keep[0]);
echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
