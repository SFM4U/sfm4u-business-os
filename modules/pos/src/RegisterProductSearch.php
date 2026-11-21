<?php
declare(strict_types=1);
namespace SFM4U\POS;
use DomainException;
require_once dirname(__DIR__,2).'/inventory-bridge/src/Ean13.php';

/** Product lookup only. AuthorizedRegister owns actor/register permission checks. */
final class RegisterProductSearch
{
    public static function find(int $merchant,string $term):array
    {
        $term=trim(sanitize_text_field($term));if($term==='' || strlen($term)>100)throw new DomainException('Enter a SKU, product name or EAN barcode.',422);
        // Product QR labels without a SKU carry "SFM4U-P:<id>".
        if(preg_match('/^SFM4U-P:([0-9]{1,20})$/D',$term,$m)){$product=wc_get_product((int)$m[1]);return ($product && self::eligible($product,$merchant))?[self::row($product,'piece',1)]:[];}
        $sku=wc_get_product_id_by_sku($term);$product=$sku?wc_get_product($sku):false;
        if($product && self::eligible($product,$merchant))return [self::row($product,'piece',1)];
        $meta=[['key'=>'_sfm4u_pos_merchant_id','value'=>$merchant,'compare'=>'=','type'=>'NUMERIC']];
        if(preg_match('/^[0-9]{13}$/D',$term)){
            \SFM4U\Inventory\Ean13::validate($term);
            $meta[]=['key'=>'_sfm4u_label_barcodes','value'=>'"'.$term.'"','compare'=>'LIKE'];
            $ids=get_posts(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','numberposts'=>2,'meta_query'=>$meta]);
            if(count($ids)>1)throw new DomainException('Barcode matches multiple products in this register scope.',409);
            if(!$ids)return [];$product=wc_get_product($ids[0]);if(!$product || !self::eligible($product,$merchant))return [];
            foreach((array)$product->get_meta('_sfm4u_label_barcodes') as $unit=>$code){
                if($code!==$term)continue;
                $factor=1;if($unit!=='piece'){
                    $data=$product->get_meta(\SFM4U\Wholesale\Commerce::META);$rule=is_array($data)?\SFM4U\Wholesale\Rule::validate($data):[];
                    if(!isset($rule['packs'][$unit]))throw new DomainException('Barcode pack configuration changed.',409);$factor=(int)$rule['packs'][$unit];
                }
                return [self::row($product,$unit,$factor)];
            }
            return [];
        }
        $ids=get_posts(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','numberposts'=>20,'s'=>$term,'meta_query'=>$meta,'orderby'=>'ID','order'=>'ASC']);$rows=[];
        foreach($ids as $id){$product=wc_get_product($id);if($product && self::eligible($product,$merchant))$rows[]=self::row($product,'piece',1);}
        return $rows;
    }
    public static function eligible(\WC_Product $product,int $merchant):bool
    {
        return $product->get_status()==='publish' && $product->is_type('simple') && $product->managing_stock()
            && !$product->backorders_allowed() && (int)$product->get_meta('_sfm4u_pos_merchant_id')===$merchant;
    }
    public static function row(\WC_Product $product,string $unit,int $quantity):array
    {
        return ['product'=>$product->get_id(),'name'=>$product->get_name(),'sku'=>$product->get_sku(),'unit'=>$unit,'quantity'=>$quantity,
            'available'=>max(0,(int)$product->get_stock_quantity()-(int)wc_get_held_stock_quantity($product)),'retail_price'=>$product->get_price()];
    }
}
