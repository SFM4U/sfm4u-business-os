<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use SFM4U\Wholesale\Rule;

final class StockCountsPage
{
    public function __construct(private StockCounts $counts) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Stock counts', 'sfm4u-wholesale'), __('Stock counts', 'sfm4u-wholesale'),
                'manage_woocommerce', 'sfm4u-stock-counts', [$this, 'render']);
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $record = null;
        $id = is_string($_GET['count'] ?? null) ? wp_unslash($_GET['count']) : '';
        echo '<div class="wrap"><h1>' . esc_html__('Stock counts', 'sfm4u-wholesale') . '</h1>';
        try {
            $action = self::post('stock_count_action');
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action !== '') {
                check_admin_referer('sfm4u_stock_count_' . $action);
                $key = self::post('request_key');
                $record = match ($action) {
                    'submit' => $this->counts->submit($key, Rule::number(self::post('product'), 1, PHP_INT_MAX),
                        Rule::number(self::post('expected'), 0, 1000000), Rule::number(self::post('counted'), 0, 1000000),
                        self::post('reason')),
                    'approve' => $this->counts->approve($key, self::post('count_id')),
                    'session' => $this->counts->submitSession($key, self::post('mode'), self::post('scope'),
                        self::sessionLines(self::post('lines')), self::post('reason')),
                    default => throw new DomainException('Invalid stock count action.'),
                };
            } elseif ($id !== '') {
                $record = $this->counts->find($id);
            }
        } catch (DomainException | RuntimeException $error) {
            echo '<p role="alert">' . esc_html__('Check the submitted fields and stock balances, then retry with the same request key.', 'sfm4u-wholesale') . '</p>';
        }

        if ($record) {
            $url = add_query_arg(['page' => 'sfm4u-stock-counts', 'count' => $record['id']], admin_url('admin.php'));
            echo '<p><a href="' . esc_url($url) . '">' . esc_html($record['id']) . '</a></p>';
            echo '<dl>';
            foreach (['status', 'mode', 'scope', 'product', 'expected', 'counted', 'reason', 'submitted_by', 'approved_by', 'value_delta_micros'] as $field) {
                if (isset($record[$field])) {
                    echo '<dt>' . esc_html(__(ucwords(str_replace('_', ' ', $field)), 'sfm4u-wholesale')) . '</dt><dd>'
                        . esc_html($field === 'status' || $field === 'mode' ? __(ucfirst((string) $record[$field]), 'sfm4u-wholesale') : (string) $record[$field]) . '</dd>';
                }
            }
            echo '</dl>';
            if (isset($record['lines'])) {
                echo '<table class="widefat"><thead><tr><th>' . esc_html__('Product ID', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Expected', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Counted', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Value change (micros)', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
                foreach ($record['lines'] as $line) {
                    echo '<tr><td>' . esc_html((string) $line['product']) . '</td><td>' . esc_html((string) $line['expected'])
                        . '</td><td>' . esc_html((string) $line['counted']) . '</td><td>'
                        . esc_html((string) ($line['value_delta_micros'] ?? '')) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            if ($record['status'] === 'pending' && $record['submitted_by'] !== get_current_user_id()) {
                echo '<form method="post"><input type="hidden" name="count_id" value="' . esc_attr($record['id']) . '">';
                self::fields('approve');
                submit_button(__('Approve count', 'sfm4u-wholesale'));
                echo '</form>';
            }
        }

        echo '<form method="get"><input type="hidden" name="page" value="sfm4u-stock-counts">';
        echo '<label>' . esc_html__('Count ID', 'sfm4u-wholesale') . ' <input name="count" required></label> ';
        submit_button(__('Find count', 'sfm4u-wholesale'), 'secondary', '', false);
        echo '</form><form method="post">';
        self::fields('submit');
        foreach (['product' => __('Product ID', 'sfm4u-wholesale'), 'expected' => __('Stock when counted', 'sfm4u-wholesale'),
            'counted' => __('Physical count', 'sfm4u-wholesale')] as $name => $label) {
            echo '<p><label>' . esc_html($label) . ' <input type="number" step="1" min="' . ($name === 'product' ? '1' : '0')
                . '" name="' . esc_attr($name) . '" required></label></p>';
        }
        echo '<p><label>' . esc_html__('Reason', 'sfm4u-wholesale') . ' <input name="reason" maxlength="250" required></label></p>';
        submit_button(__('Submit count for review', 'sfm4u-wholesale'));
        echo '</form><h2>' . esc_html__('Multi-product count', 'sfm4u-wholesale') . '</h2><form method="post">';
        self::fields('session');
        echo '<p><label>' . esc_html__('Count type', 'sfm4u-wholesale') . ' <select name="mode"><option value="cycle">' . esc_html__('Cycle', 'sfm4u-wholesale') . '</option>'
            . '<option value="full">' . esc_html__('Full declared scope', 'sfm4u-wholesale') . '</option><option value="reconcile">' . esc_html__('Reconcile an existing WooCommerce stock change', 'sfm4u-wholesale') . '</option></select></label></p>';
        echo '<p><label>' . esc_html__('Scope', 'sfm4u-wholesale') . ' <input name="scope" maxlength="120" required></label></p>';
        echo '<p><label>' . esc_html__('Product ID, expected quantity, counted quantity (one per line)', 'sfm4u-wholesale')
            . '<br><textarea name="lines" rows="6" maxlength="1600" required></textarea></label></p>';
        echo '<p>' . esc_html__('For reconciliation, enter the warehouse book quantity as expected and the verified current WooCommerce balance as counted.', 'sfm4u-wholesale') . '</p>';
        echo '<p><label>' . esc_html__('Reason', 'sfm4u-wholesale') . ' <input name="reason" maxlength="250" required></label></p>';
        submit_button(__('Submit session for review', 'sfm4u-wholesale'));
        echo '</form>';
        $this->showVariance();
        echo '</div>';
    }

    private function showVariance(): void
    {
        $page = is_string($_GET['variance_page'] ?? null) ? max(1, absint($_GET['variance_page'])) : 1;
        $page = min(1000000, $page);
        echo '<h2>' . esc_html__('Stock count variance', 'sfm4u-wholesale') . '</h2>';
        try {
            $report = $this->counts->varianceReport($page);
        } catch (\RuntimeException | \DomainException | \JsonException) {
            echo '<p role="alert">' . esc_html__('Stock variance report unavailable.', 'sfm4u-wholesale') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Count ID', 'sfm4u-wholesale'), __('Status', 'sfm4u-wholesale'), __('Product ID', 'sfm4u-wholesale'),
            __('Expected', 'sfm4u-wholesale'), __('Counted', 'sfm4u-wholesale'), __('Variance units', 'sfm4u-wholesale'),
            __('Value change (micros)', 'sfm4u-wholesale'), __('Currency', 'sfm4u-wholesale')] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $url = add_query_arg('page', 'sfm4u-stock-counts', admin_url('admin.php'));
        foreach ($report['rows'] as $row) {
            echo '<tr><th scope="row"><a href="' . esc_url(add_query_arg('count', $row['id'], $url)) . '">'
                . esc_html($row['id']) . '</a></th><td>'
                . esc_html($row['status'] === 'approved' ? __('Approved', 'sfm4u-wholesale') : __('Pending', 'sfm4u-wholesale')) . '</td>';
            foreach (['product', 'expected', 'counted', 'variance', 'value', 'currency'] as $key) {
                echo '<td>' . esc_html($key === 'value' && $row[$key] === null ? __('Unavailable', 'sfm4u-wholesale') : (string) $row[$key]) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table><p>';
        if ($page > 1) {
            echo '<a href="' . esc_url(add_query_arg('variance_page', $page - 1, $url)) . '">' . esc_html__('Previous page', 'sfm4u-wholesale') . '</a> ';
        }
        if ($report['next']) {
            echo '<a href="' . esc_url(add_query_arg('variance_page', $page + 1, $url)) . '">' . esc_html__('Next page', 'sfm4u-wholesale') . '</a>';
        }
        echo '</p>';
    }

    private static function fields(string $action): void
    {
        wp_nonce_field('sfm4u_stock_count_' . $action);
        echo '<input type="hidden" name="stock_count_action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
    }

    private static function post(string $key): string
    {
        return is_string($_POST[$key] ?? null) ? wp_unslash($_POST[$key]) : '';
    }

    private static function sessionLines(string $input): array
    {
        if (strlen($input) > 1600) {
            throw new DomainException('Stock count input is too long.');
        }
        $lines = [];
        foreach (preg_split('/\R/', trim($input)) as $row) {
            $cells = array_map('trim', explode(',', $row));
            if (count($cells) !== 3) {
                throw new DomainException('Each row requires product ID, expected and counted quantity.');
            }
            $lines[] = array_combine(['product', 'expected', 'counted'], $cells);
        }
        return $lines;
    }
}
