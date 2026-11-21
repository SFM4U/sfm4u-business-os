<?php
declare(strict_types=1);
namespace SFM4U\POS;
use DomainException;
use SFM4U\Wholesale\Pro\Money;

final class RegisterSettingsPage
{
    public function register():void
    {
        add_action('admin_menu',function():void{add_submenu_page('woocommerce',__('POS settings','sfm4u-wholesale'),__('POS settings','sfm4u-wholesale'),'manage_options','sfm4u-pos-settings',[$this,'render']);});
    }
    public function save():void
    {
        if(!current_user_can('manage_options') || !wp_verify_nonce(self::post('_wpnonce'),'sfm4u_pos_settings'))throw new DomainException('POS administrator permission required.',403);
        $rounding=self::post('rounding');$max=Money::parse(self::post('maximum'),'BDT')->minor;
        if(!in_array($rounding,['1','5','10','25','50','100'],true) || $max<0 || $max>1000000000)throw new DomainException('Invalid register policy.',422);
        update_option(SyntheticSale::CASH_ROUNDING_OPTION,$rounding);update_option('sfm4u_pos_max_difference_minor',(string)$max);
    }
    public function render():void
    {
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>'.esc_html__('POS settings','sfm4u-wholesale').'</h1>';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            try{$this->save();echo '<p role="status">'.esc_html__('POS settings saved.','sfm4u-wholesale').'</p>';}
            catch(\Throwable){echo '<p role="alert">'.esc_html__('Check the POS policy fields and permission.','sfm4u-wholesale').'</p>';}
        }
        echo '<form method="post">';wp_nonce_field('sfm4u_pos_settings');
        echo '<p><label>'.esc_html__('Cash rounding','sfm4u-wholesale').' <select name="rounding">';
        foreach(['1','5','10','25','50','100'] as $value)echo '<option value="'.$value.'" '.selected((string)get_option(SyntheticSale::CASH_ROUNDING_OPTION,'100'),$value,false).'>'.esc_html('BDT '.Money::of((int)$value,'BDT')->format()).'</option>';
        echo '</select></label></p><p><label>'.esc_html__('Allowed cash variance (BDT; larger differences require manager approval)','sfm4u-wholesale').' <input name="maximum" required value="'.esc_attr(Money::of((int)get_option('sfm4u_pos_max_difference_minor','0'),'BDT')->format()).'"></label></p>';
        submit_button(__('Save POS settings','sfm4u-wholesale'));echo '</form></div>';
    }
    private static function post(string $key):string{return is_string($_POST[$key]??null)?wp_unslash($_POST[$key]):'';}
}
