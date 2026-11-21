<?php
require '/wordpress/wp-load.php';
if(wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1')throw new LogicException('Local synthetic Playground only.');
function solo_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);file_put_contents('/fixture-out/single-store.txt','PASS '.$label."\n",FILE_APPEND);}
wp_set_current_user(1);
solo_check(!function_exists('dokan'),'single-store request has no loaded Dokan runtime');
$data=get_option('sfm4u_zip_operations');$pid=$data['product'];global $wpdb;$value=new \SFM4U\Inventory\StockValuation($wpdb);
$order=wc_create_order(['created_via'=>'checkout']);$order->set_currency('BDT');$order->add_product(wc_get_product($pid),1);$order->calculate_totals(false);$order->update_status('processing');
solo_check((int)wc_get_product($pid)->get_stock_quantity()===2 && $value->totals($pid)['stock_micros']===40000000,'single-store native checkout captures stock and original cost without Dokan');
$order->update_status('cancelled');
solo_check((int)wc_get_product($pid)->get_stock_quantity()===3 && $value->totals($pid)['stock_micros']===60000000,'single-store native cancellation restores stock and value without Dokan');
solo_check(class_exists('SFM4U\\Wholesale\\Commerce') && get_option('sfmwh_b2b_schema')==='1' && get_option('sfm4u_operations_schema')==='3','single-store core and optional schemas remain available');
solo_check(has_filter('woocommerce_rest_pre_insert_product_object',[\SFM4U\Wholesale\Commerce::class,'protectOperationsMeta'])!==false
    && has_filter('woocommerce_rest_pre_insert_product_variation_object',[\SFM4U\Wholesale\Commerce::class,'protectOperationsMeta'])!==false
    && str_starts_with((new ReflectionClass(\SFM4U\Wholesale\Commerce::class))->getFileName(),WP_PLUGIN_DIR.'/sfm4u-wholesale/'),
    'single-store installed entry automatically registers product and variation metadata guards');
