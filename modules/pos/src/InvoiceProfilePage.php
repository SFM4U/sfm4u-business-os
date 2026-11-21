<?php
declare(strict_types=1);
namespace SFM4U\POS;

use DomainException;
use SFM4U\Wholesale\Rule;

final class InvoiceProfilePage
{
    private const FIELDS=['name'=>'Merchant name','address'=>'Issuing address','bin'=>'Merchant BIN','note'=>'Invoice footer'];

    public function register(): void
    {
        add_action('admin_menu',function():void{
            add_submenu_page('woocommerce',__('Invoice settings','sfm4u-wholesale'),__('Invoice settings','sfm4u-wholesale'),
                'manage_options','sfm4u-invoice-settings',[$this,'render']);
        });
    }

    public static function profile(int $merchant): array
    {
        $data=get_option('sfm4u_invoice_profile_'.$merchant,[]);
        $profile=['schema'=>1,'merchant'=>$merchant,'name'=>sanitize_text_field(get_bloginfo('name')),'address'=>'','bin'=>'','note'=>''];
        if (is_array($data)) foreach(self::FIELDS as $field=>$label) if(is_string($data[$field]??null)) $profile[$field]=$data[$field];
        return $profile;
    }

    public function save(): int
    {
        if(!current_user_can('manage_options') || !wp_verify_nonce(self::post('_wpnonce'),'sfm4u_invoice_profile')) throw new DomainException('Invoice administrator permission required.',403);
        $merchant=Rule::number(self::post('merchant'),1,9999999999);$profile=[];
        foreach(self::FIELDS as $field=>$label){
            $value=trim(sanitize_textarea_field(self::post($field)));$limit=in_array($field,['address','note'],true)?500:150;
            if(mb_strlen($value,'UTF-8')>$limit || ($field==='name' && $value==='')) throw new DomainException(__('Check the merchant name and invoice field lengths.','sfm4u-wholesale'));
            $profile[$field]=$value;
        }
        update_option('sfm4u_invoice_profile_'.$merchant,$profile);
        return $merchant;
    }

    public function render(): void
    {
        if(!current_user_can('manage_options'))return;
        $merchant=is_string($_GET['merchant']??null)?absint($_GET['merchant']):1;
        echo '<div class="wrap"><h1>'.esc_html__('Invoice settings','sfm4u-wholesale').'</h1><p>'
            .esc_html__('Merchant details are frozen into new POS receipts. Editing these settings does not rewrite previous invoices. Review fiscal requirements before statutory use.','sfm4u-wholesale').'</p>';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            try{$merchant=$this->save();echo '<p role="status">'.esc_html__('Invoice settings saved.','sfm4u-wholesale').'</p>';}
            catch(\Throwable){echo '<p role="alert">'.esc_html__('Invoice settings were not saved. Check permission, nonce and fields.','sfm4u-wholesale').'</p>';}
        }
        echo '<form method="get"><input type="hidden" name="page" value="sfm4u-invoice-settings"><label>'
            .esc_html__('Merchant ID','sfm4u-wholesale').' <input name="merchant" type="number" min="1" required value="'.esc_attr((string)$merchant).'"></label> ';
        submit_button(__('Open merchant','sfm4u-wholesale'),'secondary','',false);echo '</form><form method="post">';
        wp_nonce_field('sfm4u_invoice_profile');echo '<input type="hidden" name="merchant" value="'.esc_attr((string)$merchant).'">';
        $profile=self::profile($merchant);
        foreach(self::FIELDS as $field=>$label)echo '<p><label>'.esc_html(__($label,'sfm4u-wholesale')).'<br><textarea name="'.esc_attr($field).'" maxlength="'.(in_array($field,['address','note'],true)?500:150).'">'.esc_textarea($profile[$field]).'</textarea></label></p>';
        submit_button(__('Save invoice settings','sfm4u-wholesale'));echo '</form></div>';
    }

    private static function post(string $key):string{return is_string($_POST[$key]??null)?wp_unslash($_POST[$key]):'';}
}
