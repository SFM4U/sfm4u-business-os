<?php
/** Shared mounted-source/exact-ZIP regression for vendor product metadata boundaries. */
function sfm4u_product_meta_security(callable $assert):void {
    // Mounted-source harness explicitly boots the same core registration as the plugin entry.
    if(!has_filter('woocommerce_rest_pre_insert_product_object',[\SFM4U\Wholesale\Commerce::class,'protectOperationsMeta']))\SFM4U\Wholesale\Commerce::register();
    wp_set_current_user(1);$seller=wp_create_user('synthetic-meta-'.wp_generate_uuid4(),wp_generate_password());$user=new WP_User($seller);$user->set_role('seller');update_user_meta($seller,'dokan_enable_selling','yes');
    $p=new WC_Product_Simple();$p->set_name('Synthetic metadata security');$p->set_status('publish');$p->set_regular_price('1.00');$p->update_meta_data('_sfm4u_pos_merchant_id',123);$p->save();wp_update_post(['ID'=>$p->get_id(),'post_author'=>$seller]);
    global $wpdb;$metaId=(int)$wpdb->get_var($wpdb->prepare('SELECT meta_id FROM %i WHERE post_id=%d AND meta_key=%s',$wpdb->postmeta,$p->get_id(),'_sfm4u_pos_merchant_id'));
    $request=static function(string $route,array $entry)use($p):WP_REST_Response{
        $r=new WP_REST_Request('PUT',$route.$p->get_id());$r->set_header('Content-Type','application/json');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode(['meta_data'=>[$entry]]));return rest_do_request($r);
    };
    foreach(['/wc/v3/products/','/dokan/v1/products/'] as $route){
        wp_set_current_user($seller);
        foreach([
            ['key'=>'_sfm4u_pos_merchant_id','value'=>456],
            ['key'=>'_sfm4u_label_barcodes','value'=>['piece'=>'2000000000015']],
            ['key'=>'_sfm4u_reorder_point','value'=>100],
            ['key'=>'_SFM4U_POS_MERCHANT_ID','value'=>456],
            ['key'=>'_sfm4u_reorder_target','value'=>100],
            ['key'=>'synthetic_public_note','id'=>$metaId,'value'=>'renamed'],
            ['key'=>'_sfm4u_pos_merchant_id','id'=>$metaId,'value'=>null],
        ] as $n=>$entry){
            $r=$request($route,$entry);
            $assert($r->get_status()===403 && (int)wc_get_product($p->get_id())->get_meta('_sfm4u_pos_merchant_id')===123,'security '.$route.' rejects protected metadata mutation '.($n+1));
        }
        $ordinary=$request($route,['key'=>'synthetic_public_note','value'=>'allowed']);
        $assert($ordinary->get_status()===200 && wc_get_product($p->get_id())->get_meta('synthetic_public_note')==='allowed','security '.$route.' still permits vendor ordinary product metadata');
    }
    wp_set_current_user(1);$manager=$request('/wc/v3/products/',['key'=>'_sfm4u_pos_merchant_id','value'=>124]);
    $assert($manager->get_status()===200 && (int)wc_get_product($p->get_id())->get_meta('_sfm4u_pos_merchant_id')===124,'security inventory manager retains authorized product REST assignment');
}
