<?php
use SFM4U\Inventory\LossAwareWpdb;
use SFM4U\Wholesale\Pro\{CreditAccountsPage, WooCatalog, WooQuotes};

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Loopback fixture required.');
}
require_once '/modules/b2b-pro/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
function ledgerCheck(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
global $wpdb;
$original = $wpdb;
$wpdb = new LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb->set_prefix($original->base_prefix);
$wpdb->set_blog_id(get_current_blog_id());
foreach (get_object_vars($original) as $alias => $value) {
    if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) && is_string($value)
        && str_starts_with($value, $original->prefix) && preg_match('/^[a-zA-Z0-9_]+$/D', $value)) {
        $wpdb->$alias = $value;
    }
}
add_filter('pre_wp_mail', '__return_true');
$quotes = new WooQuotes($wpdb, new WooCatalog());
$quotes->install();
$page = new CreditAccountsPage($wpdb);
wp_set_current_user(1);
wp_get_current_user()->add_cap('sfm4u_b2b_settle');
$buyer = wp_create_user('ledger-buyer-' . wp_generate_uuid4(), wp_generate_password());
$other = wp_create_user('ledger-other-' . wp_generate_uuid4(), wp_generate_password());
foreach ([$buyer, $other] as $id) {
    $wpdb->insert($wpdb->prefix . 'sfm4u_b2b_accounts', ['buyer' => $id, 'credit_limit' => 15000,
        'net_days' => 30, 'grace_days' => 2, 'currency' => 'BDT']);
}
$charge = static function (int $id, int $amount, int $days) use ($wpdb): int {
    $order = wc_create_order(['customer_id' => $id, 'created_via' => 'sfm4u-b2b-credit']);
    $wpdb->insert($wpdb->prefix . 'sfm4u_b2b_ledger', ['buyer' => $id, 'idempotency_hash' => hash('sha256', wp_generate_uuid4()),
        'order_id' => $order->get_id(), 'amount_minor' => $amount, 'issued_at' => time() - 40 * DAY_IN_SECONDS,
        'due_at' => time() - $days * DAY_IN_SECONDS]);
    return $order->get_id();
};
$past = $charge($buyer, 7000, 4);
$grace = $charge($buyer, 3000, 1);
$otherOrder = $charge($other, 5000, 5);
$statement = $quotes->statement($buyer);
ledgerCheck($statement['credit_limit_minor'] === 15000 && $statement['balance_minor'] === 10000
    && $statement['overdue_minor'] === 7000 && $statement['invoices'][0]['overdue']
    && !$statement['invoices'][1]['overdue'], 'credit limit, due and overdue reflect the account and invoice grace');
$nonce = wp_create_nonce('sfm4u_b2b_record_payment_' . $buyer);
$key = wp_generate_uuid4();
try {
    $page->recordPayment($other, $nonce, $key, '5000', 'wrong account');
    throw new RuntimeException('Cross-account nonce was accepted.');
} catch (DomainException $error) {
    ledgerCheck($error->getCode() === 403, 'payment nonce is bound to the selected buyer');
}
wp_set_current_user($buyer);
try {
    $page->recordPayment($buyer, wp_create_nonce('sfm4u_b2b_record_payment_' . $buyer), $key, '5000', 'unauthorized');
    throw new RuntimeException('Buyer recorded a payment.');
} catch (DomainException $error) {
    ledgerCheck($error->getCode() === 403, 'buyer cannot post manual money entries');
}
wp_set_current_user(1);
$entry = $page->recordPayment($buyer, $nonce, $key, '5000', 'Cash memo 001');
ledgerCheck($entry['allocations'] === [['order_id' => $past, 'amount_minor' => 5000]]
    && $page->recordPayment($buyer, $nonce, $key, '5000', 'Cash memo 001') === $entry,
    'manual entry allocates oldest invoice and exact replay records once');
$statement = $quotes->statement($buyer);
ledgerCheck($statement['balance_minor'] === 5000 && $statement['overdue_minor'] === 2000
    && count($statement['payments']) === 1 && $quotes->statement($other)['balance_minor'] === 5000,
    'partial payment adjusts due and overdue without crossing buyer balances');
foreach ([['5001', 'Overage'], ['1', 'Cash memo 001'], ['1e3', 'Invalid']] as [$amount, $reference]) {
    try {
        $page->recordPayment($buyer, $nonce, wp_generate_uuid4(), $amount, $reference);
        throw new RuntimeException('Invalid payment was accepted.');
    } catch (DomainException) {
        ledgerCheck($quotes->statement($buyer)['balance_minor'] === 5000, 'overpayment, duplicate or malformed entry leaves ledger intact');
    }
}
$_GET['buyer'] = (string) $buyer;
ob_start();
$page->render();
$html = ob_get_clean();
ledgerCheck(str_contains($html, 'Cash memo 001') && str_contains($html, 'Overdue')
    && str_contains($html, 'sfm4u_b2b_record_payment') && !str_contains($html, '#' . $otherOrder . ' ·'),
    'scoped admin ledger renders selected invoices and manual form');
$page->recordPayment($buyer, $nonce, wp_generate_uuid4(), '5000', 'Cash memo 002');
ledgerCheck($quotes->statement($buyer)['balance_minor'] === 0 && $quotes->statement($buyer)['overdue_minor'] === 0
    && wc_get_order($past)->has_status('pending') && wc_get_order($grace)->get_payment_method() === '',
    'clearing the account updates due flags without initiating payment or changing order status');
