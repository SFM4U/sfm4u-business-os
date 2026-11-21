<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') exit(1);
require '/modules/inventory-bridge/src/RequestJournal.php';
require '/modules/pos/src/ReceiptRegister.php';
require '/modules/pos/src/SyntheticSale.php';
require_once '/modules/inventory-bridge/src/LossAwareWpdb.php';
global $wpdb;
$original = $wpdb;
$wpdb = new \SFM4U\Inventory\LossAwareWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb->set_prefix($original->base_prefix);
$wpdb->set_blog_id(get_current_blog_id());
foreach (get_object_vars($original) as $alias => $value) {
    if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) && is_string($value)
        && str_starts_with($value, $original->prefix) && preg_match('/^[a-zA-Z0-9_]+$/D', $value)) {
        $wpdb->$alias = $value;
    }
}
add_filter('pre_wp_mail', '__return_true');
$journal = new \SFM4U\Inventory\RequestJournal($wpdb);
$journal->requirePinnedConnection();
$receipts = new \SFM4U\POS\ReceiptRegister($wpdb);
$sales = new \SFM4U\POS\SyntheticSale($journal,$receipts);
$mode = $args[0];
if ($mode === 'seed') {
    $journal->install();
    $receipts->install();
    update_option('woocommerce_currency','BDT');
    update_option('woocommerce_price_num_decimals',2);
    update_option('woocommerce_calc_taxes','no');
    $products=[];
    for ($i=0;$i<4;$i++) {
        $p=new WC_Product_Simple();
        $p->set_name('Synthetic concurrent POS bottle ' . $i);
        $p->set_regular_price('30.00');
        $p->set_manage_stock(true);
        $p->set_stock_quantity(1);
        $p->save();
        $products[]=$p->get_id();
    }
    echo implode(' ', [random_int(10000000,999999999), ...$products]) . "\n";
} elseif ($mode === 'sale') {
    $merchant=(int)$args[1]; $product=(int)$args[2]; $barrier=$args[3];
    if (!touch($barrier . '/' . $product)) throw new RuntimeException('Worker barrier failed.');
    for ($tries=0; !is_file($barrier . '/go'); $tries++) {
        if ($tries>=300) throw new RuntimeException('Worker barrier timed out.');
        usleep(100000);
    }
    try {
        $receipt=$sales->commit($merchant,'R1',"fixture-pos-$merchant-$product",$product,1,3000,'C1');
        echo $receipt['number'] . "\n";
    } catch (RuntimeException $error) {
        if ($error->getCode() !== 503) {
            throw $error;
        }
        // Match register recovery. The next PHP process retries with the same key.
        echo "RETRY\n";
    }
} elseif ($mode === 'verify') {
    $serials=$wpdb->get_col($wpdb->prepare('SELECT sequence_number FROM %i WHERE merchant=%d AND register_code=%s ORDER BY sequence_number',
        $wpdb->prefix . 'sfm4u_pos_receipts',(int)$args[1],'R1'));
    if (array_map('intval',$serials)!==[1,2,3,4]) throw new RuntimeException('Concurrent numbering gap.');
    foreach (array_slice($args,2) as $sku) {
        $count=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE product_id=%d AND stock_delta=-1',
            $wpdb->prefix . 'sfm4u_inventory_movements',(int)$sku));
        if ((int)$count!==1 || (int)wc_get_product((int)$sku)->get_stock_quantity()!==0) {
            throw new RuntimeException('Concurrent sale stock mismatch.');
        }
    }
    $probe = 'sfm4u_pinned_probe_' . $args[1];
    add_option($probe, 'original');
    $key = "fixture-pinned-probe-{$args[1]}";
    $writeBlocked = false;
    $previousSuppression = $wpdb->suppress_errors(true);
    try {
        $journal->execute((int) $args[1], 'pos', $key, [], function () use ($wpdb, $probe, &$writeBlocked) {
            try {
                $wpdb->query('SELECT invalid_column_for_fixture FROM ' . $wpdb->options);
            } catch (RuntimeException $ignored) {
                // Simulate a Woo save path that catches the first database error.
            }
            try {
                $wpdb->update($wpdb->options, ['option_value' => 'corrupt'], ['option_name' => $probe]);
            } catch (RuntimeException $error) {
                $writeBlocked = $error->getCode() === 503;
            }
            throw new RuntimeException('Woo wrapper lost the original database error.');
        });
        throw new RuntimeException('Failed database operation unexpectedly committed.');
    } catch (RuntimeException $error) {
        if ($error->getCode() !== 503 || !$writeBlocked || $journal->committed((int) $args[1], 'pos', $key) !== null
            || $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $probe)) !== 'original') {
            throw $error;
        }
    } finally {
        $wpdb->suppress_errors($previousSuppression);
        delete_option($probe);
    }
    echo "PASS caught database failure blocks subsequent writes and retains retryable outcome\n";
    echo "PASS four separate PHP workers commit contiguous register serials and exactly one stock movement per sale\n";
}
