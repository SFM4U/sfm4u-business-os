<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    exit(1);
}

foreach (['inventory-bridge/src/LossAwareWpdb', 'inventory-bridge/src/RequestJournal', 'pos/src/ReceiptRegister',
    'pos/src/RegisterSessions', 'pos/src/SyntheticSale', 'pos/src/AuthorizedRegister'] as $module) {
    require_once '/modules/' . $module . '.php';
}

global $wpdb;
$original = $wpdb;
$wpdb = new \SFM4U\Inventory\LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb->set_prefix($original->base_prefix);
$wpdb->set_blog_id(get_current_blog_id());
foreach (get_object_vars($original) as $alias => $value) {
    if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) && is_string($value) && str_starts_with($value, $original->prefix)
        && preg_match('/^[a-zA-Z0-9_]+$/D', $value)) {
        $wpdb->$alias = $value;
    }
}
add_filter('pre_wp_mail', '__return_true');
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$receipts = new \SFM4U\POS\ReceiptRegister($wpdb);
$pos = new \SFM4U\POS\AuthorizedRegister($journal, $receipts, new \SFM4U\POS\SyntheticSale($journal, $receipts),
    new \SFM4U\POS\RegisterSessions($wpdb));

[$merchant, $sku, $actor] = array_map('intval', array_slice($args, 0, 3));
$input = json_decode($args[3], true, 16, JSON_THROW_ON_ERROR);
wp_set_current_user($actor);
try {
    $key = $input['key'] ?? '';
    $record = match ($input['action']) {
        'drawer' => $pos->drawer($merchant, 'R1', $input['drawerAction'], $key, $input['session'] ?? 0,
            $input['amount'] ?? 0, $input['reason'] ?? '', $input['approval'] ?? '', $input['expenseMemo'] ?? ''),
        'sale' => $pos->sale($merchant, 'R1', $key, $sku, $input['quantity'], $input['tender'], $input['session']),
        'recover' => $pos->recover($merchant, 'R1', $key, $input['credit']),
        'reprint' => $pos->reprint($merchant, 'R1', $input['order']),
        'credit' => $pos->credit($merchant, 'R1', $key, $input['order'], $input['quantity'], $input['resellable'] ?? true, $input['session']),
    };
    $result = ['status' => 200, 'record' => $record, 'stock' => (int) wc_get_product($sku)->get_stock_quantity()];
} catch (DomainException $error) {
    $status = $error->getCode();
    $result = ['status' => in_array($status, [403, 404, 409, 422], true) ? $status : 422, 'error' => $error->getMessage()];
} catch (Throwable $error) {
    $result = ['status' => 503, 'error' => 'Result unavailable. Recover with the same request key.'];
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);
