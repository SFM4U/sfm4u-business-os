<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

final class SupplierOrderPage
{
    private bool $submissionFailed = false;
    public function __construct(private PurchaseOrders $orders) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Supplier Purchase Orders', 'sfm4u-wholesale'), __('Supplier Purchase Orders', 'sfm4u-wholesale'),
                'manage_woocommerce', 'sfm4u-supplier-orders', [$this, 'render']);
        });
    }

    public function render(): void
    {
        $this->submissionFailed = false;
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Supplier purchase order access is required.', 'sfm4u-wholesale'), '', ['response' => 403]);
        }
        echo '<div class="wrap"><h1>' . esc_html__('Supplier Purchase Orders', 'sfm4u-wholesale') . '</h1>';
        $id = isset($_GET['po']) && is_string($_GET['po']) ? absint($_GET['po']) : 0;
        $fallbackOrder = null;
        $draft = '';
        try {
            if (get_option('sfm4u_supplier_orders_schema') !== '3') {
                $this->orders->install();
                update_option('sfm4u_supplier_orders_schema', '3');
            }
            if ($id) $fallbackOrder = $this->orders->find($id);
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['po_action'])) {
                if (!wp_verify_nonce($this->post('po_nonce'), 'sfm4u_supplier_order')) {
                    throw new \DomainException('Refresh the page before submitting.');
                }
                $action = $this->post('po_action');
                $key = $this->post('po_key');
                if ($action === 'draft') {
                    $selected = $_POST['po_products'] ?? [];
                    $after = \SFM4U\Wholesale\Rule::number($this->post('po_after'), 0, PHP_INT_MAX);
                    $draft = (new InventoryReportsPage($GLOBALS['wpdb']))->draftLines(is_array($selected) ? wp_unslash($selected) : [], $after);
                } elseif ($action === 'issue') {
                    $order = $this->orders->issue($key, $this->post('supplier'), $this->rows('unit_minor'));
                    $id = $order['id'];
                    $fallbackOrder = $order;
                } elseif ($action === 'receive' && $id > 0) {
                    $basis=$this->post('allocation_basis');
                    if($basis==='' || $basis==='manual')$this->orders->receive($key, $id, $this->rows('landed_minor'), $this->post('short_reason'));
                    else $this->orders->receiveAllocated($key,$id,$this->rows('landed_minor'),\SFM4U\Wholesale\Rule::number($this->post('landed_total'),0,1000000000),$basis,$this->post('short_reason'));
                } else {
                    throw new \DomainException('Choose a purchase order action.');
                }
                if ($action !== 'draft') {
                    echo '<p role="status">' . esc_html__('Purchase order saved.', 'sfm4u-wholesale') . '</p>';
                }
            }
            if ($id) {
                $this->show($this->orders->find($id));
            }
        } catch (\DomainException $error) {
            $this->submissionFailed = true;
            echo '<p role="alert">' . esc_html($error->getMessage() === 'Refresh the page before submitting.' ? __('Refresh the page before submitting.', 'sfm4u-wholesale') : __('Check the submitted fields and stock balances, then retry with the same request key.', 'sfm4u-wholesale')) . '</p>';
        } catch (\RuntimeException) {
            $this->submissionFailed = true;
            echo '<p role="alert">' . esc_html__('The purchase order could not be saved. Retry with the same submission.', 'sfm4u-wholesale') . '</p>';
        }
        if ($this->submissionFailed && $fallbackOrder) $this->show($fallbackOrder);
        echo '<form method="get"><input type="hidden" name="page" value="sfm4u-supplier-orders">'
            . '<label for="po-number">' . esc_html__('Purchase order number', 'sfm4u-wholesale') . '</label> <input id="po-number" name="po" type="number" min="1" required> '
            . '<button class="button">' . esc_html__('Find order', 'sfm4u-wholesale') . '</button></form><h2>' . esc_html__('Issue purchase order', 'sfm4u-wholesale') . '</h2>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=sfm4u-inventory-report')) . '">'
            . esc_html__('Choose products from reorder suggestions', 'sfm4u-wholesale') . '</a></p>';
        if ($draft !== '') {
            echo '<p role="status">' . esc_html__('Draft ready. Enter the supplier and unit cost on each line before issuing.', 'sfm4u-wholesale') . '</p>';
        }
        $this->openForm(admin_url('admin.php?page=sfm4u-supplier-orders'),'issue');
        echo '<p><label for="po-supplier">' . esc_html__('Supplier reference', 'sfm4u-wholesale') . '</label><br><input id="po-supplier" name="supplier" maxlength="150" required value="'.esc_attr($this->retryValue('issue','supplier')).'"></p>';
        $this->lines('po-issue-lines', 'Order lines (product ID, quantity, unit cost in minor units)', true, $draft);
        echo '<button class="button button-primary" name="po_action" value="issue">' . esc_html__('Issue purchase order', 'sfm4u-wholesale') . '</button></form></div>';
    }

    private function show(array $order): void
    {
        echo '<h2>' . esc_html__('Purchase order', 'sfm4u-wholesale') . ' #' . esc_html((string) $order['id']) . '</h2><p>'
            . esc_html($order['supplier'] . ' · ' . __(ucfirst(str_replace('_',' ',$order['status'])), 'sfm4u-wholesale') . ' · ' . $order['currency']) . '</p>';
        echo '<table class="widefat"><thead><tr><th scope="col">' . esc_html__('Product ID', 'sfm4u-wholesale') . '</th><th scope="col">' . esc_html__('Ordered', 'sfm4u-wholesale') . '</th><th scope="col">' . esc_html__('Received', 'sfm4u-wholesale') . '</th>'
            . '<th scope="col">' . esc_html__('Unit cost (minor units)', 'sfm4u-wholesale') . '</th><th scope="col">' . esc_html__('Received landed cost (minor units)', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        foreach ($order['lines'] as $product => $line) {
            echo '<tr><th scope="row">' . esc_html((string) $product) . '</th><td>' . esc_html((string) $line['ordered'])
                . '</td><td>' . esc_html((string) $line['received']) . '</td><td>' . esc_html((string) $line['unit_minor']) . '</td><td>'
                . esc_html((string) ($line['landed_minor_received'] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table>';
        foreach ($order['receipt_ids'] ?? [] as $receipt) {
            echo '<p>' . esc_html__('Goods receipt', 'sfm4u-wholesale') . ' ' . esc_html($receipt) . '</p>';
        }
        if (isset($order['short_reason'])) {
            echo '<p>' . esc_html__('Closed short', 'sfm4u-wholesale') . ': ' . esc_html($order['short_reason']) . '</p>';
        }
        if ($order['status'] === 'open') {
            echo '<h3>' . esc_html__('Receive goods', 'sfm4u-wholesale') . '</h3>';
            $this->openForm(add_query_arg('po', $order['id'], admin_url('admin.php?page=sfm4u-supplier-orders')),'receive');
            $this->lines('po-receipt-lines', 'Receipt lines (product ID, actual quantity, total landed cost allocated to this line in minor units)', false);
            echo '<p><label>'.esc_html__('Landed-cost allocation','sfm4u-wholesale').' <select name="allocation_basis">';
            foreach(['manual'=>'Manual per-line amounts','quantity'=>'By received quantity','value'=>'By goods value'] as $basis=>$label)echo '<option value="'.esc_attr($basis).'" '.selected($this->retryValue('receive','allocation_basis'),$basis,false).'>'.esc_html(__($label,'sfm4u-wholesale')).'</option>';
            echo '</select></label></p><p><label>'.esc_html__('Total landed cost (minor units; use zero per-line cost for automatic allocation)','sfm4u-wholesale').' <input name="landed_total" type="number" min="0" max="1000000000" value="'.esc_attr($this->retryValue('receive','landed_total')?:'0').'"></label></p>';
            echo '<p><label for="po-short">' . esc_html__('Reason to close with units outstanding (optional)', 'sfm4u-wholesale') . '</label><br>'
                . '<input id="po-short" name="short_reason" maxlength="250" value="'.esc_attr($this->retryValue('receive','short_reason')).'"></p>'
                . '<button class="button button-primary" name="po_action" value="receive">' . esc_html__('Record receipt', 'sfm4u-wholesale') . '</button></form>';
        }
    }

    private function openForm(string $url,string $action): void
    {
        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('sfm4u_supplier_order', 'po_nonce', false);
        echo '<input type="hidden" name="po_key" value="' . esc_attr($this->retryValue($action,'po_key')?:wp_generate_uuid4()) . '">';
    }

    private function lines(string $id, string $label, bool $required, string $draft = ''): void
    {
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html(__($label, 'sfm4u-wholesale')) . '</label><br><textarea id="' . esc_attr($id)
            . '" name="po_lines" rows="5" maxlength="3000"' . ($required ? ' required' : '') . '>'.esc_textarea($draft !== '' ? $draft : $this->retryValue($required?'issue':'receive','po_lines')).'</textarea></p>';
    }

    private function rows(string $cost): array
    {
        $input = trim($this->post('po_lines'));
        if ($input === '') {
            return [];
        }
        if (strlen($input) > 3000) {
            throw new \DomainException('Enter at most 30 receipt lines.');
        }
        return array_map(static function (string $row) use ($cost): array {
            $fields = array_map('trim', explode(',', $row));
            if (count($fields) !== 3) {
                throw new \DomainException('Each line needs product ID, quantity and cost.');
            }
            return ['product' => $fields[0], 'quantity' => $fields[1], $cost => $fields[2]];
        }, preg_split('/\R/', $input));
    }

    private function post(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? wp_unslash($_POST[$name]) : '';
    }

    private function retryValue(string $action,string $field):string
    {
        return $this->submissionFailed && $this->post('po_action')===$action?$this->post($field):'';
    }
}
