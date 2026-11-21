<?php
declare(strict_types=1);
namespace SFM4U\Inventory;

use DomainException;
use SFM4U\Wholesale\{Commerce, Rule};
require_once __DIR__ . '/Ean13.php';
require_once __DIR__ . '/Code128.php';

final class BarcodeLabelsPage
{
    private const META = '_sfm4u_label_barcodes';

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Barcode labels', 'sfm4u-wholesale'), __('Barcode labels', 'sfm4u-wholesale'),
                'manage_woocommerce', 'sfm4u-barcode-labels', [$this, 'render']);
        });
        add_filter('bulk_actions-edit-product', static function (array $actions): array {
            if (current_user_can('manage_woocommerce')) {
                $actions['sfm4u_sku_labels'] = __('Preview SKU labels', 'sfm4u-wholesale');
            }
            return $actions;
        });
        add_filter('handle_bulk_actions-edit-product', static function (string $url, string $action, array $ids): string {
            if ($action !== 'sfm4u_sku_labels' || !current_user_can('manage_woocommerce')) {
                return $url;
            }
            return add_query_arg([
                'page' => 'sfm4u-barcode-labels',
                'sku_products' => count($ids) > 100 ? '0' : implode(',', array_map('absint', $ids)),
            ], admin_url('admin.php'));
        }, 10, 3);
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'productField']);
        add_action('woocommerce_admin_process_product_object', function (\WC_Product $product): void {
            try { $this->saveProduct($product); } catch (DomainException $error) { \WC_Admin_Meta_Boxes::add_error($error->getMessage()); }
        });
    }

    public function productField(): void
    {
        global $product_object;
        if (!$product_object || !current_user_can('manage_woocommerce') || !current_user_can('edit_post', $product_object->get_id())) return;
        wp_nonce_field('sfm4u_barcodes_' . $product_object->get_id(), 'sfm4u_barcode_nonce', false);
        $lines=[]; foreach ((array) $product_object->get_meta(self::META) as $unit=>$code) $lines[]=$unit.','.$code;
        echo '<p class="form-field"><label for="sfm4u-barcodes">' . esc_html__('Barcode aliases (piece or pack name, EAN-13; one per line)', 'sfm4u-wholesale')
            . '</label><textarea id="sfm4u-barcodes" name="sfm4u_barcodes" rows="4" maxlength="2000">' . esc_textarea(implode("\n",$lines)) . '</textarea></p>';
    }

    public function saveProduct(\WC_Product $product): void
    {
        $nonce = self::post('sfm4u_barcode_nonce');
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post',$product->get_id())
            || !wp_verify_nonce($nonce,'sfm4u_barcodes_'.$product->get_id())) return;
        $input = trim(self::post('sfm4u_barcodes')); $rows=$input===''?[]:preg_split('/\R/',$input); $aliases=[];
        if (strlen($input)>2000 || count($rows)>30) throw new DomainException(__('Enter at most 30 barcode aliases.', 'sfm4u-wholesale'));
        foreach ($rows as $row) {
            $cells=array_map('trim',explode(',',$row));
            if (count($cells)!==2 || isset($aliases[$cells[0]]) || in_array($cells[1],$aliases,true)) throw new DomainException(__('Barcode units and codes must be unique.', 'sfm4u-wholesale'));
            $this->factor($product,$cells[0]);
            try { $code=Ean13::validate($cells[1]); } catch (DomainException) { throw new DomainException(__('Enter a valid 13-digit EAN barcode including its check digit.', 'sfm4u-wholesale')); }
            $others=get_posts(['post_type'=>['product','product_variation'],'post_status'=>['publish','pending','draft','private'],
                'post__not_in'=>[$product->get_id()],'fields'=>'ids','numberposts'=>1,
                'meta_query'=>[['key'=>self::META,'value'=>'"'.$code.'"','compare'=>'LIKE']]]);
            if ($others) throw new DomainException(__('This barcode is already assigned to another product.', 'sfm4u-wholesale'));
            $aliases[$cells[0]]=$code;
        }
        $product->update_meta_data(self::META,$aliases);
    }

    public function labels(int $id, string $unit='piece', int $copies=1): string
    {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post',$id)) throw new DomainException('Barcode manager permission required.',403);
        if ($copies<1 || $copies>100) throw new DomainException('Choose 1 to 100 label copies.',422);
        $product=wc_get_product($id); if (!$product) throw new DomainException('Product unavailable.',404);
        $factor=$this->factor($product,$unit); $aliases=$product->get_meta(self::META);
        if (!is_array($aliases) || !is_string($aliases[$unit]??null)) throw new DomainException('Configure a barcode for this product unit first.',409);
        $barcode=Ean13::svg($aliases[$unit]);
        $label='<article class="sfm4u-label"><strong>'.esc_html($product->get_name()).'</strong>'.$barcode.'<span>'
            .esc_html($product->get_sku().' · '.$unit.' × '.$factor).'</span></article>';
        return '<section class="sfm4u-labels">'.str_repeat($label,$copies).'</section>';
    }

    public static function styles(): string
    {
        return '<style>.sfm4u-label{width:56mm;height:36mm;box-sizing:border-box;display:flex;align-items:center;justify-content:center;flex-direction:column;background:white;color:black;font:8pt sans-serif;overflow:hidden;break-after:page}.sfm4u-label strong{max-width:54mm;max-height:6mm;overflow:hidden;line-height:1.1}.sfm4u-label span{max-width:54mm;white-space:nowrap;overflow:hidden}@media print{@page{size:60mm 40mm;margin:2mm}body{margin:0!important}body *{visibility:hidden}body *:has(.sfm4u-labels){position:static!important;margin:0!important;padding:0!important;min-height:0!important;width:auto!important}body *:not(:has(.sfm4u-labels)):not(.sfm4u-labels):not(.sfm4u-labels *){display:none!important}.sfm4u-labels,.sfm4u-labels *{visibility:visible}.sfm4u-labels{position:static}.sfm4u-labels+.sfm4u-labels{break-before:page}.sfm4u-label:last-child{break-after:auto}}</style>';
    }

    public function skuLabels(array $ids, string $format, int $copies): string
    {
        if (!current_user_can('manage_woocommerce')) {
            throw new DomainException('Barcode manager permission required.', 403);
        }
        if (!in_array($format, ['a4', 'thermal'], true) || !$ids || count($ids) > 100
            || $copies < 1 || $copies > intdiv(100, count($ids))) {
            throw new DomainException(__('Choose A4 or 58 mm and a total of 1–100 labels.', 'sfm4u-wholesale'));
        }

        $labels = [];
        foreach ($ids as $raw) {
            try {
                $id = Rule::number($raw, 1, PHP_INT_MAX);
            } catch (DomainException) {
                throw new DomainException(__('Select 1–100 valid product IDs.', 'sfm4u-wholesale'));
            }
            if (isset($labels[$id])) {
                throw new DomainException(__('Select each product only once.', 'sfm4u-wholesale'));
            }
            $product = current_user_can('edit_post', $id) ? wc_get_product($id) : false;
            if (!$product || in_array($product->get_status(), ['trash', 'auto-draft'], true)) {
                throw new DomainException(__('A selected product is unavailable.', 'sfm4u-wholesale'));
            }
            $sku = $product->get_sku('edit');
            $price = $product->get_price('edit');
            try {
                $barcode = Code128::svg($sku, $format === 'thermal' ? 53 : 91);
            } catch (DomainException) {
                throw new DomainException(sprintf(
                    /* translators: %d: product ID. */
                    __('Product %d needs a printable ASCII SKU that fits this label size.', 'sfm4u-wholesale'),
                    $id
                ));
            }
            if ($price === '') {
                throw new DomainException(sprintf(
                    /* translators: %d: product ID. */
                    __('Product %d has no catalogue price.', 'sfm4u-wholesale'),
                    $id
                ));
            }
            $priceText = html_entity_decode(wp_strip_all_tags(wc_price($price)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = '<article class="sfm4u-sku-label"><strong>' . esc_html($product->get_name()) . '</strong>'
                . $barcode . '<span class="sfm4u-sku">' . esc_html($sku) . '</span><b>' . esc_html($priceText) . '</b></article>';
            $labels[$id] = str_repeat($label, $copies);
        }
        return '<section class="sfm4u-labels sfm4u-sku-sheet">' . implode('', $labels) . '</section>';
    }

    public static function skuStyles(string $format): string
    {
        $thermal = $format === 'thermal';
        $page = $thermal ? 'size:58mm 36mm;margin:2mm' : 'size:A4;margin:8mm';
        $width = $thermal ? '54mm' : '95mm';
        $layout = $thermal ? 'display:flex;break-after:page' : 'margin-right:2mm';
        return '<style>
            .sfm4u-sku-sheet{max-width:max-content;color:#000;background:#fff}
            .sfm4u-sku-label{box-sizing:border-box;width:' . $width . ';height:32mm;display:inline-flex;vertical-align:top;
                flex-direction:column;align-items:center;justify-content:center;gap:0.7mm;text-align:center;
                font:8pt sans-serif;color:#000;background:#fff;break-inside:avoid;overflow:hidden}
            .sfm4u-sku-label strong{overflow-wrap:anywhere;line-height:1.1;max-height:6mm;overflow:hidden}
            .sfm4u-sku-label svg{flex:none;fill:#000}
            .sfm4u-sku{white-space:pre}
            @media print{
                @page{' . $page . '}
                body{margin:0!important}
                body *:has(.sfm4u-sku-sheet){position:static!important;margin:0!important;padding:0!important;
                    min-height:0!important;width:auto!important;overflow:visible!important}
                body *:not(:has(.sfm4u-sku-sheet)):not(.sfm4u-sku-sheet):not(.sfm4u-sku-sheet *){display:none!important}
                .sfm4u-sku-sheet{max-width:none;font-size:0}
                .sfm4u-sku-label{' . $layout . '}
                .sfm4u-sku-label:last-child{break-after:auto}
            }</style>';
    }

    private function renderSkuForm(): bool
    {
        $selected = is_string($_GET['sku_products'] ?? null) ? wp_unslash($_GET['sku_products']) : '';
        $format = is_string($_GET['sku_format'] ?? null) ? wp_unslash($_GET['sku_format']) : 'a4';
        $copies = is_string($_GET['sku_copies'] ?? null) ? wp_unslash($_GET['sku_copies']) : '1';
        echo '<h2>' . esc_html__('SKU price labels', 'sfm4u-wholesale') . '</h2><p>'
            . esc_html__('Select products in the Products list or enter product IDs. Print at actual size with browser headers and footers off.', 'sfm4u-wholesale')
            . '</p><form method="get"><input type="hidden" name="page" value="sfm4u-barcode-labels"><p><label>'
            . esc_html__('Product IDs, separated by commas', 'sfm4u-wholesale')
            . ' <input name="sku_products" maxlength="1100" value="' . esc_attr(substr($selected, 0, 1100)) . '" required></label></p><p><label>'
            . esc_html__('Paper size', 'sfm4u-wholesale') . ' <select name="sku_format"><option value="a4" '
            . selected($format, 'a4', false) . '>' . esc_html__('A4', 'sfm4u-wholesale') . '</option><option value="thermal" '
            . selected($format, 'thermal', false) . '>' . esc_html__('58 mm thermal', 'sfm4u-wholesale') . '</option></select></label> <label>'
            . esc_html__('Copies per product', 'sfm4u-wholesale') . ' <input name="sku_copies" type="number" min="1" max="100" value="'
            . esc_attr($copies) . '" required></label></p>';
        submit_button(__('Preview SKU labels', 'sfm4u-wholesale'));
        echo '</form>';
        if (!array_key_exists('sku_products', $_GET)) {
            return false;
        }
        try {
            if (strlen($selected) > 1100) {
                throw new DomainException(__('Select 1–100 valid product IDs.', 'sfm4u-wholesale'));
            }
            $sheet = $this->skuLabels(array_map('trim', explode(',', $selected)), $format,
                preg_match('/^[0-9]{1,3}$/D', $copies) ? (int) $copies : 0);
            echo self::skuStyles($format), $sheet;
            return true;
        } catch (DomainException $error) {
            echo '<p role="alert">' . esc_html($error->getMessage()) . '</p>';
            return false;
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        nocache_headers();
        echo '<div class="wrap"><h1>'.esc_html__('Barcode labels','sfm4u-wholesale').'</h1>';
        if ($this->renderSkuForm()) {
            echo '</div>';
            return;
        }
        echo '<p>'
            .esc_html__('Configure piece and carton barcodes in product inventory. Print at 100% scale using the browser. Check-digit validation does not certify barcode allocation or physical print quality.','sfm4u-wholesale').'</p><form method="get"><input type="hidden" name="page" value="sfm4u-barcode-labels">';
        foreach (['product'=>'Product ID','unit'=>'Pack name (piece for single units)','copies'=>'Copies (1–100)'] as $key=>$label) {
            $value=is_string($_GET[$key]??null)?wp_unslash($_GET[$key]):($key==='unit'?'piece':($key==='copies'?'1':''));
            echo '<p><label>'.esc_html(__($label,'sfm4u-wholesale')).' <input name="'.esc_attr($key).'" value="'.esc_attr($value).'" required></label></p>';
        }
        submit_button(__('Preview labels','sfm4u-wholesale')); echo '</form>';
        if (isset($_GET['product'])) {
            try {
                $id=Rule::number(is_string($_GET['product'])?wp_unslash($_GET['product']):'',1,PHP_INT_MAX);
                $unit=is_string($_GET['unit']??null)?wp_unslash($_GET['unit']):'piece';
                $copies=Rule::number(is_string($_GET['copies']??null)?wp_unslash($_GET['copies']):'1',1,100);
                // All template text is escaped and SVG data is validated numeric EAN-13.
                echo self::styles(),$this->labels($id,$unit,$copies);
            } catch (\Throwable) { echo '<p role="alert">'.esc_html__('Check the product, pack and configured EAN barcode.','sfm4u-wholesale').'</p>'; }
        }
        echo '</div>';
    }

    private function factor(\WC_Product $product, string $unit): int
    {
        if ($unit==='piece') return 1;
        $data=$product->get_meta(Commerce::META);
        $rule=is_array($data)?Rule::validate($data):[];
        if (!isset($rule['packs'][$unit])) throw new DomainException(__('Choose a configured pack name or piece.','sfm4u-wholesale'));
        return (int)$rule['packs'][$unit];
    }

    private static function post(string $name): string { return is_string($_POST[$name]??null)?wp_unslash($_POST[$name]):''; }
}
