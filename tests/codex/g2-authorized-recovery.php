<?php
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1') exit(1);
require_once '/modules/inventory-bridge/src/LossAwareWpdb.php';
require_once '/modules/inventory-bridge/src/RequestJournal.php';
require_once '/modules/pos/src/ReceiptRegister.php';
require_once '/modules/pos/src/SyntheticSale.php';
require_once '/modules/pos/src/AuthorizedRegister.php';
require_once '/modules/b2b-pro/src/Money.php';

global $wpdb;
$wpdbOriginal=$wpdb;
$pinned=new \SFM4U\Inventory\LossAwareWpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
$pinned->set_prefix($wpdbOriginal->base_prefix);
$pinned->set_blog_id(get_current_blog_id());
foreach (get_object_vars($wpdbOriginal) as $alias=>$value) {
    if (preg_match('/^[a-z][a-z0-9_]*$/D',$alias)
        && is_string($value) && str_starts_with($value,$wpdbOriginal->prefix)
        && preg_match('/^[a-zA-Z0-9_]+$/D',$value)) {
        $pinned->$alias=$value;
    }
}
$wpdb=$pinned;
add_filter('pre_wp_mail','__return_true');
$journal=new \SFM4U\Inventory\RequestJournal($wpdb);
$archive=new \SFM4U\POS\ReceiptRegister($wpdb);
$pos=new \SFM4U\POS\AuthorizedRegister($journal,$archive,new \SFM4U\POS\SyntheticSale($journal,$archive));
$mode=$args[0];
$must=static function (bool $ok,string $label) {
    if (!$ok) throw new RuntimeException($label);
    fwrite(STDERR,"PASS $label\n");
};
$deny=static function (int $status,callable $fn,string $label) use ($must) {
    try { $fn(); } catch (DomainException $e) { $must($e->getCode()===$status,$label); return; }
    throw new RuntimeException($label);
};
if ($mode==='seed') {
    $journal->install();
    $archive->install();
    require_once '/modules/pos/src/RegisterSessions.php';
    (new \SFM4U\POS\RegisterSessions($wpdb))->install();
    update_option('woocommerce_currency','BDT');
    update_option('woocommerce_price_num_decimals',2);
    update_option('woocommerce_calc_taxes','no');
    update_option('woocommerce_manage_stock','yes');
    update_option('woocommerce_hold_stock_minutes',15);
    $product=new WC_Product_Simple();
    $product->set_name('Synthetic WATER 500 mL');
    $product->set_sku('RECOVERY-' . wp_generate_uuid4());
    $product->set_regular_price('30.00');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $merchant=random_int(10000000,999999999);
    $product->update_meta_data('_sfm4u_pos_merchant_id',$merchant);
    $product->save();
    $staff=[];
    foreach ([true,false] as $credit) {
        $id=wp_insert_user(['user_login'=>'synthetic-pos-' . wp_generate_uuid4(),'user_pass'=>wp_generate_password(),'role'=>'subscriber']);
        if (is_wp_error($id)) throw new RuntimeException('Cannot create synthetic cashier.');
        $user=new WP_User($id);
        $user->add_cap('sfm4u_pos_cashier');
        if ($credit) $user->add_cap('sfm4u_pos_credit');
        update_user_meta($id,'_sfm4u_pos_registers',[$merchant=>['R1'=>$credit?'C1':'C2']]);
        $staff[]=$id;
    }
    echo implode(' ',[$merchant,$product->get_id(),...$staff,wp_generate_uuid4(),wp_generate_uuid4(),wp_generate_uuid4()]) . "\n";
    return;
}
[$merchant,$sku,$lead,$cashier]=array_map('intval',array_slice($args,1,4));
[$preKey,$postKey,$creditKey,$barrier]=$args ? array_slice($args,5,4) : [];
wp_set_current_user($lead);
$stock=static fn()=>(int)wc_get_product($sku)->get_stock_quantity();
$movement=static fn()=>(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE product_id=%d',$wpdb->prefix.'sfm4u_inventory_movements',$sku));
$pause=static function (string $phase,array $record) use ($barrier) {
    if (!file_put_contents($barrier.'/'.$phase,wp_json_encode($record))) throw new RuntimeException('Barrier failed.');
    for ($i=0;$i<300;$i++) usleep(100000);
    throw new RuntimeException('Worker was expected to be interrupted.');
};
if ($mode==='lose-before') {
    add_action('sfm4u_pos_synthetic_before_commit',static fn(array $record)=>$pause('pre',$record));
    $pos->sale($merchant,'R1',$preKey,$sku,3,10000);
} elseif ($mode==='lose-socket') {
    $wpdb->suppress_errors(true);
    add_action('sfm4u_pos_synthetic_before_commit',static function (array $record) use ($barrier,$wpdb,$wpdbOriginal) {
        $connection=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
        if (!file_put_contents($barrier.'/pre',wp_json_encode($record)) || $connection<1
            || $wpdbOriginal->query('KILL CONNECTION '.$connection)===false) {
            throw new RuntimeException('Failed to inject socket loss.');
        }
    });
    try {
        $pos->sale($merchant,'R1',$preKey,$sku,3,10000);
        throw new RuntimeException('Killed database connection was silently replaced.');
    } catch (RuntimeException $error) {
        $wpdb=$wpdbOriginal;
        if ($error->getCode()!==503) throw $error;
        $must(true,'surviving PHP process rejects automatic reconnect after socket loss');
    }
} elseif ($mode==='retry-before') {
    $aborted=json_decode(file_get_contents($barrier.'/pre'),true,32,JSON_THROW_ON_ERROR);
    $must($pos->recover($merchant,'R1',$preKey)===null && $stock()===10 && $movement()===0
        && !wc_get_order($aborted['order_id']),'real precommit disconnect rolls back order, stock, journal and receipt');
    $saved=$pos->sale($merchant,'R1',$preKey,$sku,3,10000);
    $must($saved['number']==='R1-000001' && $stock()===7 && $movement()===1,
        'retry on a new connection commits once and reuses serial one');
} elseif ($mode==='lose-after') {
    $pause('post',$pos->sale($merchant,'R1',$postKey,$sku,3,10000));
} elseif ($mode==='recover-and-credit') {
    $expected=json_decode(file_get_contents($barrier.'/post'),true,32,JSON_THROW_ON_ERROR);
    $saved=$pos->recover($merchant,'R1',$postKey);
    $must($saved===$expected && $saved['number']==='R1-000002' && $stock()===4 && $movement()===2,
        'new connection recovers committed sale after response loss');
    $must($pos->sale($merchant,'R1',$postKey,$sku,3,10000)===$saved && $stock()===4 && $movement()===2,
        'postcommit retry returns original receipt without duplicate movement');
    $deny(409,fn()=>$pos->sale($merchant,'R1',$postKey,$sku,2,10000),'changed retry payload conflicts');
    $deny(403,fn()=>$pos->sale($merchant,'R2',wp_generate_uuid4(),$sku,1,3000),'wrong register rejected');
    $deny(403,fn()=>$pos->sale($merchant+1,'R1',wp_generate_uuid4(),$sku,1,3000),'wrong merchant rejected');
    $foreign=new WC_Product_Simple();
    $foreign->set_name('Synthetic foreign product');
    $foreign->set_regular_price('30.00');
    $foreign->set_manage_stock(true);
    $foreign->set_stock_quantity(5);
    $foreign->update_meta_data('_sfm4u_pos_merchant_id',$merchant+1);
    $foreign->save();
    $deny(403,fn()=>$pos->sale($merchant,'R1',wp_generate_uuid4(),$foreign->get_id(),1,3000),'cross-merchant SKU rejected');
    $must((int)wc_get_product($foreign->get_id())->get_stock_quantity()===5,'foreign stock remains unchanged');
    wp_set_current_user(0);
    $deny(403,fn()=>$pos->reprint($merchant,'R1',$saved['order_id']),'unauthenticated reprint rejected');
    wp_set_current_user($cashier);
    $deny(404,fn()=>$pos->recover($merchant,'R1',$postKey),'different cashier cannot recover another actors request');
    $deny(403,fn()=>$pos->credit($merchant,'R1',$creditKey,$saved['order_id'],1),'cashier without credit capability denied');
    $must($stock()===4 && $movement()===2,'all authorization denials preserve stock and ledger');
    wp_set_current_user($lead);
    $credit=$pos->credit($merchant,'R1',$creditKey,$saved['order_id'],1);
    $must($credit['type']==='credit' && $credit['number']==='R1-000003'
        && $credit['sale']['original_number']==='R1-000002' && $credit['sale']['total_minor']===3000
        && $stock()===5 && $movement()===3,'authorized credit note restores one item and records original receipt and next serial');
    $original=wc_get_order($saved['order_id']);
    $must($original->get_payment_method()==='' && wc_get_order($credit['order_id']) instanceof WC_Order_Refund,
        'linked WooCommerce refund record uses no payment gateway');
    $must($pos->recover($merchant,'R1',$creditKey,true)===$credit
        && $pos->credit($merchant,'R1',$creditKey,$saved['order_id'],1)===$credit
        && $stock()===5 && $movement()===3,'credit recovery and retry return original document and do not restock again');
    $deny(422,fn()=>$pos->credit($merchant,'R1',wp_generate_uuid4(),$saved['order_id'],3),'over-credit after partial return rejected');
    $copy=$pos->reprint($merchant,'R1',$credit['order_id']);
    $must($copy===['kind'=>'COPY','receipt'=>$credit] && $stock()===5 && $movement()===3,
        'credit COPY reads original snapshot without stock effects');
    echo wp_json_encode($copy,JSON_THROW_ON_ERROR) . "\n";
}
