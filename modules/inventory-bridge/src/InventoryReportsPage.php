<?php
declare(strict_types=1);
namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use SFM4U\Wholesale\Rule;

final class InventoryReportsPage
{
    public function __construct(private \wpdb $db) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Stock valuation and reorder', 'sfm4u-wholesale'), __('Stock valuation and reorder', 'sfm4u-wholesale'),
                'manage_woocommerce', 'sfm4u-inventory-report', [$this, 'render']);
        });
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'productFields']);
        add_action('woocommerce_admin_process_product_object', function (\WC_Product $product): void {
            try { $this->saveProduct($product); } catch (DomainException $error) {
                \WC_Admin_Meta_Boxes::add_error($error->getMessage());
            }
        });
    }

    public function productFields(): void
    {
        global $product_object;
        if (!$product_object || !current_user_can('manage_woocommerce') || !current_user_can('edit_post', $product_object->get_id())) return;
        wp_nonce_field('sfm4u_reorder_' . $product_object->get_id(), 'sfm4u_reorder_nonce', false);
        foreach (['_sfm4u_reorder_point' => 'Reorder point (stock units)', '_sfm4u_reorder_target' => 'Reorder target (stock units; zero disables suggestions)'] as $key => $label) {
            woocommerce_wp_text_input(['id' => $key, 'type' => 'number', 'label' => __($label, 'sfm4u-wholesale'),
                'custom_attributes' => ['min' => '0', 'max' => '1000000', 'step' => '1']]);
        }
    }

    public function saveProduct(\WC_Product $product): void
    {
        $nonce = is_string($_POST['sfm4u_reorder_nonce'] ?? null) ? wp_unslash($_POST['sfm4u_reorder_nonce']) : '';
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post', $product->get_id())
            || !wp_verify_nonce($nonce, 'sfm4u_reorder_' . $product->get_id())) return;
        $values = [];
        foreach (['_sfm4u_reorder_point', '_sfm4u_reorder_target'] as $key) {
            $raw = is_string($_POST[$key] ?? null) ? wp_unslash($_POST[$key]) : '';
            $values[$key] = Rule::number($raw === '' ? '0' : $raw, 0, 1000000);
        }
        if ($values['_sfm4u_reorder_target'] > 0 && $values['_sfm4u_reorder_target'] < $values['_sfm4u_reorder_point']) {
            throw new DomainException(__('Reorder target must be at least the reorder point.', 'sfm4u-wholesale'));
        }
        foreach ($values as $key => $value) $product->update_meta_data($key, $value);
    }

    public function report(int $after = 0): array
    {
        if (!current_user_can('manage_woocommerce') || !\SFM4U\POS\Runtime::allowedContext()) throw new DomainException('Inventory manager permission required.', 403);
        if ($after < 0) throw new DomainException('Invalid inventory report cursor.', 422);
        if (!$this->db instanceof LossAwareWpdb || $this->db !== $GLOBALS['wpdb']) throw new RuntimeException('Stable inventory connection required.');
        $this->db->pinTransaction();
        try {
            $this->read('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $table = $this->db->prefix . 'sfm4u_inventory_values';
            $values = $this->db->get_results($this->db->prepare(
                "SELECT candidates.product_id, COALESCE(stock.quantity, 0) AS quantity,
                    COALESCE(stock.value_micros, 0) AS value_micros, COALESCE(stock.currency, %s) AS currency
                 FROM (
                     SELECT product_id FROM %i WHERE product_id > %d
                     UNION
                     SELECT post_id FROM %i WHERE post_id > %d AND meta_key = '_sfm4u_reorder_target'
                         AND CAST(meta_value AS UNSIGNED) BETWEEN 1 AND 1000000
                 ) AS candidates
                 LEFT JOIN %i AS stock ON stock.product_id = candidates.product_id
                 ORDER BY candidates.product_id LIMIT 101",
                get_woocommerce_currency(), $table, $after, $this->db->postmeta, $after, $table
            ), ARRAY_A);
            if ($this->db->last_error !== '') throw new RuntimeException('Inventory report unavailable.');
            $totals = $this->db->get_results($this->db->prepare('SELECT currency,SUM(quantity) AS quantity,SUM(value_micros) AS value_micros FROM %i GROUP BY currency ORDER BY currency',
                $this->db->prefix . 'sfm4u_inventory_values'), ARRAY_A);
            if ($this->db->last_error !== '') throw new RuntimeException('Inventory totals unavailable.');
            foreach ($totals as &$total) {
                $total['quantity'] = self::aggregate($total['quantity']);
                $total['value_micros'] = self::aggregate($total['value_micros']);
            }
            unset($total);
            $rows = [];
            foreach (array_slice($values, 0, 100) as $value) {
                $id = (int) $value['product_id']; $product = wc_get_product($id);
                $actual = $product ? $product->get_stock_quantity() : null;
                $stock = $actual !== null && (float) $actual === (float) (int) $actual ? (int) $actual : null;
                $held = $product ? (int) wc_get_held_stock_quantity($product) : 0;
                $point = $product ? (int) $product->get_meta('_sfm4u_reorder_point') : 0;
                $target = $product ? (int) $product->get_meta('_sfm4u_reorder_target') : 0;
                $independent = $product && $product->managing_stock() && $product->get_stock_managed_by_id() === $id
                    && !in_array($product->get_status(), ['trash', 'auto-draft'], true);
                $available = $stock === null ? null : max(0, $stock - $held);
                $rows[] = ['product' => $id, 'name' => $product ? $product->get_name() : __('Unavailable product', 'sfm4u-wholesale'),
                    'quantity' => (int) $value['quantity'], 'stock_micros' => (int) $value['value_micros'], 'currency' => $value['currency'],
                    'woo_quantity' => $stock, 'held' => $held, 'available' => $available,
                    'needs_reconciliation' => $stock !== (int) $value['quantity'],
                    'suggested_quantity' => $independent && $available !== null && $target > 0 && $target >= $point && $available <= $point ? max(0, $target - $available) : 0];
            }
            $this->read('COMMIT');
            return ['totals' => $totals, 'rows' => $rows, 'next' => count($values) > 100 ? end($rows)['product'] : null];
        } catch (\Throwable $error) {
            try { $this->db->query('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        } finally { $this->db->unpinTransaction(); }
    }

    public function draftLines(array $selected, int $after): string
    {
        if (!$selected || count($selected) > 30 || !array_is_list($selected)) {
            throw new DomainException('Select 1–30 suggested products.');
        }
        $rows = array_column($this->report($after)['rows'], null, 'product');
        $lines = [];
        foreach ($selected as $raw) {
            $id = Rule::number($raw, 1, 9999999999);
            $row = $rows[$id] ?? null;
            if (!$row || isset($lines[$id]) || $row['needs_reconciliation'] || $row['suggested_quantity'] < 1
                || !current_user_can('edit_post', $id)) {
                throw new DomainException('A selected product no longer has an eligible reorder suggestion.');
            }
            $lines[$id] = $id . ',' . $row['suggested_quantity'] . ',';
        }
        return implode("\n", $lines);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        nocache_headers();
        echo '<div class="wrap"><h1>' . esc_html__('Stock valuation and reorder', 'sfm4u-wholesale') . '</h1>';
        try {
            $after = is_string($_GET['after'] ?? null) ? Rule::number(wp_unslash($_GET['after']), 0, PHP_INT_MAX) : 0;
            $report = $this->report($after);
            echo '<p>' . esc_html__('Moving-average warehouse value. Reorder suggestions use available stock after active reservations and do not place purchase orders.', 'sfm4u-wholesale') . '</p>';
            foreach ($report['totals'] as $total) {
                echo '<p>' . esc_html($total['currency'] . ' ' . Rule::decimal((int) $total['value_micros'], 6)) . '</p>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=sfm4u-supplier-orders')) . '">';
            wp_nonce_field('sfm4u_supplier_order', 'po_nonce', false);
            echo '<input type="hidden" name="po_after" value="' . esc_attr((string) $after) . '">';
            echo '<table class="widefat"><thead><tr>';
            foreach (['Select', 'Product', 'Warehouse quantity', 'WooCommerce quantity', 'Available quantity', 'Stock value', 'Suggested reorder', 'Status'] as $label) echo '<th>' . esc_html(__($label, 'sfm4u-wholesale')) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($report['rows'] as $row) {
                echo '<tr><td>';
                if (!$row['needs_reconciliation'] && $row['suggested_quantity'] > 0 && current_user_can('edit_post', $row['product'])) {
                    echo '<label><span class="screen-reader-text">' . esc_html($row['name']) . '</span><input type="checkbox" name="po_products[]" value="'
                        . esc_attr((string) $row['product']) . '"></label>';
                }
                echo '</td><td>' . esc_html($row['name']) . '</td>';
                foreach (['quantity','woo_quantity','available'] as $field) echo '<td>' . esc_html((string) $row[$field]) . '</td>';
                echo '<td>' . esc_html($row['currency'] . ' ' . Rule::decimal($row['stock_micros'], 6)) . '</td><td>' . esc_html((string) $row['suggested_quantity']) . '</td><td>'
                    . esc_html__($row['needs_reconciliation'] ? 'Needs reconciliation' : 'Reconciled', 'sfm4u-wholesale') . '</td></tr>';
            }
            echo '</tbody></table><p><button class="button button-primary" name="po_action" value="draft">'
                . esc_html__('Prepare purchase order draft', 'sfm4u-wholesale') . '</button></p></form>';
            if ($report['next']) echo '<p><a href="' . esc_url(add_query_arg(['page'=>'sfm4u-inventory-report','after'=>$report['next']], admin_url('admin.php'))) . '">' . esc_html__('Next page', 'sfm4u-wholesale') . '</a></p>';
        } catch (\Throwable) {
            echo '<p role="alert">' . esc_html__('Inventory report could not be read. Check setup and try again.', 'sfm4u-wholesale') . '</p>';
        }
        echo '</div>';
    }

    private function read(string $sql): void
    {
        if ($this->db->query($sql) === false) throw new RuntimeException('Inventory snapshot failed.');
    }

    private static function aggregate(mixed $value): int
    {
        $text = (string) $value;
        $max = (string) PHP_INT_MAX;
        if (!preg_match('/^[0-9]+$/D', $text) || strlen($text) > strlen($max)
            || (strlen($text) === strlen($max) && strcmp($text, $max) > 0)) {
            throw new RuntimeException('Inventory aggregate exceeds supported integer precision.');
        }
        return (int) $text;
    }
}
