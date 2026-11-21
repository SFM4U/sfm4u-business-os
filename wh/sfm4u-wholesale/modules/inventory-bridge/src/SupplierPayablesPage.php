<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use SFM4U\Wholesale\Rule;

final class SupplierPayablesPage
{
    private const FORMS = [
        'cost' => ['Post late landed cost', ['po', 'receipt', 'product', 'amount']],
        'allocate_cost' => ['Allocate late landed cost', ['po', 'receipt', 'amount', 'basis']],
        'receipt_bill' => ['Bill a receipt', ['po', 'receipt', 'reference', 'issued', 'due']],
        'bill' => ['Record a supplier bill', ['supplier', 'reference', 'amount', 'issued', 'due']],
        'payment' => ['Record a supplier payment', ['bill_id', 'reference', 'amount', 'paid']],
        'credit' => ['Record a supplier credit', ['bill_id', 'reference', 'amount', 'paid']],
    ];
    private const LABELS = ['po'=>'PO number', 'receipt'=>'Goods receipt ID', 'product'=>'Product ID', 'amount'=>'Amount (minor units)',
        'reference'=>'Document reference', 'issued'=>'Issue date (UTC)', 'due'=>'Due date (UTC)', 'paid'=>'Settlement date (UTC)',
        'supplier'=>'Supplier reference', 'bill_id'=>'Supplier bill number', 'basis'=>'Allocation basis (quantity or value)'];

    public function __construct(private SupplierPayables $payables, private PurchaseOrders $orders) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Supplier Payables', 'sfm4u-wholesale'), __('Supplier Payables', 'sfm4u-wholesale'), 'manage_woocommerce',
                'sfm4u-supplier-ap', [$this, 'render']);
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Supplier payable access is required.', 'sfm4u-wholesale'), '', ['response'=>403]);
        }
        echo '<div class="wrap"><h1>' . esc_html__('Supplier Payables', 'sfm4u-wholesale') . '</h1>';
        $failed=false;
        try {
            if (get_option('sfm4u_supplier_orders_schema') !== '3') {
                $this->orders->install();
                update_option('sfm4u_supplier_orders_schema', '3');
            }
            $this->submit();
            $this->report();
        } catch (\DomainException $error) {
            $failed=true;
            echo '<p role="alert">' . esc_html__('Check the submitted fields and stock balances, then retry with the same request key.', 'sfm4u-wholesale') . '</p>';
        } catch (\RuntimeException) {
            $failed=true;
            echo '<p role="alert">' . esc_html__('Supplier payables could not be updated. Check the request before retrying.', 'sfm4u-wholesale') . '</p>';
        }
        foreach (self::FORMS as $action => [$label, $fields]) {
            $retry=$failed && $this->post('ap_action')===$action;
            echo '<details'.($retry?' open':'').'><summary>' . esc_html(__($label, 'sfm4u-wholesale')) . '</summary><form method="post">';
            wp_nonce_field('sfm4u_supplier_ap', 'ap_nonce', false);
            echo '<input type="hidden" name="ap_key" value="' . esc_attr($retry?$this->post('ap_key'):wp_generate_uuid4()) . '">';
            foreach ($fields as $field) {
                $id = $action . '-' . $field;
                $type = in_array($field, ['issued', 'due', 'paid'], true) ? 'date' : 'text';
                echo '<p><label for="' . esc_attr($id) . '">' . esc_html(__(self::LABELS[$field], 'sfm4u-wholesale')) . '</label><br>'
                    . '<input required id="' . esc_attr($id) . '" name="' . esc_attr($field) . '" type="' . esc_attr($type) . '" value="'.esc_attr($retry?$this->post($field):'').'"></p>';
            }
            echo '<button class="button button-primary" name="ap_action" value="' . esc_attr($action) . '">' . esc_html(__($label, 'sfm4u-wholesale'))
                . '</button></form></details>';
        }
        echo '</div>';
    }

    private function submit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['ap_action'])) {
            return;
        }
        if (!wp_verify_nonce($this->post('ap_nonce'), 'sfm4u_supplier_ap')) {
            throw new \DomainException('Refresh the page before submitting.');
        }
        $key = $this->post('ap_key');
        $number = fn(string $name): int => Rule::number($this->post($name), 1, 9999999999);
        $result = match ($this->post('ap_action')) {
            'allocate_cost' => $this->orders->postAllocatedLateCost($key,$number('po'),$this->post('receipt'),$number('amount'),$this->post('basis')),
            'cost' => $this->orders->postLateCost($key, $number('po'), $this->post('receipt'), $number('product'), $number('amount')),
            'receipt_bill' => $this->payables->billReceipt($key, $number('po'), $this->post('receipt'), $this->post('reference'),
                $this->post('issued'), $this->post('due')),
            'bill' => $this->payables->bill($key, $this->post('supplier'), $this->post('reference'), $number('amount'),
                $this->post('issued'), $this->post('due')),
            'credit' => $this->payables->recordCredit($key,$number('bill_id'),$this->post('reference'),$number('amount'),$this->post('paid')),
            'payment' => $this->payables->recordPayment($key, $number('bill_id'), $this->post('reference'), $number('amount'), $this->post('paid')),
            default => throw new \DomainException('Choose a supplier payable action.'),
        };
        if (isset($result['bill_id'])) {
            echo '<p role="status">' . esc_html(sprintf(__('Bill #%d · %s', 'sfm4u-wholesale'), $result['bill_id'], __(ucfirst(str_replace('_',' ',$result['status'])), 'sfm4u-wholesale'))) . '</p>';
        } else {
            echo '<p role="status">' . esc_html(sprintf(__('Cost posted. Stock +%s, COGS +%s', 'sfm4u-wholesale'), Rule::decimal($result['stock_micros'], 6), Rule::decimal($result['cogs_adjustment_micros'], 6))) . '</p>';
        }
    }

    private function report(): void
    {
        $asOf = is_string($_GET['ap_date'] ?? null) ? wp_unslash($_GET['ap_date']) : gmdate('Y-m-d');
        echo '<h2>' . esc_html__('AP ageing', 'sfm4u-wholesale') . '</h2><form method="get"><input type="hidden" name="page" value="sfm4u-supplier-ap">'
            . '<label for="ap-date">' . esc_html__('As of (UTC)', 'sfm4u-wholesale') . '</label> <input required type="date" name="ap_date" id="ap-date" value="' . esc_attr($asOf)
            . '"> <button class="button">' . esc_html__('Show ageing', 'sfm4u-wholesale') . '</button></form>';
        $report = $this->payables->aging($asOf);
        foreach ($report['currencies'] as $currency => $buckets) {
            echo '<p>' . esc_html($currency . ' · ' . implode(' · ', array_map(
                static fn(string $label, int $amount): string => sprintf(__('%s days · %s', 'sfm4u-wholesale'), $label, Rule::decimal($amount, 2)),
                array_keys($buckets), $buckets
            ))) . '</p>';
        }
        echo '<ul>';
        foreach ($report['bills'] as $bill) {
            echo '<li>' . esc_html('#' . $bill['id'] . ' · ' . $bill['supplier'] . ' · ' . $bill['reference'] . ' · ' . __('Due', 'sfm4u-wholesale') . ' ' . $bill['due_on'] . ' · ' . $bill['currency'] . ' ' . Rule::decimal($bill['open_minor'], 2)) . '</li>';
        }
        echo '</ul>';
    }

    private function post(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? wp_unslash($_POST[$name]) : '';
    }
}
