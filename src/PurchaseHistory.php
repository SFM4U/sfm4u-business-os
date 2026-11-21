<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

use DateTimeImmutable;
use DomainException;

final class PurchaseHistory {
    private const ENDPOINT = 'sfmwh-purchases';
    private const EXPORT = 'sfmwh_purchase_csv';
    public const META = '_sfmwh_purchase_v1';

    public static function register(): void {
        add_filter('woocommerce_get_query_vars', static function (array $vars): array {
            return $vars + [self::ENDPOINT => self::ENDPOINT];
        });
        add_action('woocommerce_account_dashboard', static function (): void {
            echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Wholesale purchase history', 'sfm4u-wholesale') . '</a></p>';
        });
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_action('admin_post_' . self::EXPORT, [self::class, 'download']);
        add_action('template_redirect', static function (): void {
            if (is_account_page() && is_wc_endpoint_url(self::ENDPOINT)) {
                nocache_headers();
                header('Cache-Control: private, no-store', true);
            }
        });
    }

    public static function capture(\WC_Order_Item_Product $line, \WC_Product $product, int $buyer): void {
        if ($buyer < 1 || !ProfitCard::supportedCurrency() || $product->get_regular_price() === '') {
            return;
        }
        try {
            $suggested = ProfitCard::minor(wc_format_decimal(wc_get_price_excluding_tax($product, [
                'price' => $product->get_regular_price(),
            ]), 2));
        } catch (DomainException) {
            return;
        }
        $override = $buyer === get_current_user_id() ? ProfitCard::override($product->get_id()) : null;
        $line->update_meta_data(self::META, ['suggested_unit_minor' => $suggested, 'retail_unit_minor' => $override ?? $suggested]);
    }

    public static function report(string $month): array {
        $buyer = get_current_user_id();
        if (!$buyer) {
            throw new DomainException('Purchase history requires an account.', 403);
        }
        $start = preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)
            ? DateTimeImmutable::createFromFormat('!Y-m', $month, wp_timezone()) : false;
        if (!$start) {
            throw new DomainException('Invalid purchase month.', 422);
        }
        $range = $start->getTimestamp() . '...' . ($start->modify('+1 month')->getTimestamp() - 1);
        $totals = array_fill_keys(['cost', 'suggested', 'retail', 'profit'], 0);
        $rows = [];
        for ($page = 1; ; $page++) {
            $orders = wc_get_orders(['type' => 'shop_order', 'customer' => $buyer, 'customer_id' => $buyer,
                'status' => ['wc-on-hold', 'wc-processing', 'wc-completed', 'wc-refunded'], 'date_created' => $range,
                'orderby' => 'ID', 'order' => 'ASC', 'limit' => 100, 'page' => $page]);
            foreach ($orders as $order) {
                // The adapter query is an optimization, not the ownership boundary.
                if ((int) $order->get_customer_id() !== $buyer || $order->get_currency() !== 'BDT' || $order->get_parent_id()) {
                    continue;
                }
                $row = self::orderRow($order);
                if ($row === null) {
                    continue;
                }
                $rows[] = $row;
                foreach ($totals as $key => $amount) {
                    $totals[$key] = $amount === null || $row[$key] === null ? null : $amount + $row[$key];
                }
            }
            if (count($orders) < 100) {
                break;
            }
        }
        return ['month' => $month, 'rows' => array_reverse($rows), 'totals' => $totals];
    }

    private static function orderRow(\WC_Order $order): ?array {
        $amounts = ['cost' => 0, 'suggested' => 0, 'retail' => 0];
        $matched = false;
        $credit = $order->get_created_via() === 'sfm4u-b2b-credit' && $order->get_meta('_sfm4u_b2b_quote') !== '';
        foreach ($order->get_items() as $id => $line) {
            $quote = $line->get_meta('_sfmwh_snapshot_v1');
            if (!$credit && (!is_array($quote) || ($quote['currency'] ?? '') !== 'BDT' || ($quote['scale'] ?? null) !== 2)) {
                continue;
            }
            $matched = true;
            $net = self::minor($line->get_total()) - abs(self::minor($order->get_total_refunded_for_item($id)));
            $amounts['cost'] += max(0, $net);
            $units = max(0, (int) $line->get_quantity() - abs((int) $order->get_qty_refunded_for_item($id)));
            $snapshot = $line->get_meta(self::META);
            foreach (['suggested', 'retail'] as $key) {
                $unit = is_array($snapshot) ? ($snapshot[$key . '_unit_minor'] ?? null) : null;
                $amounts[$key] = $amounts[$key] === null || !is_int($unit) ? null : $amounts[$key] + $unit * $units;
            }
        }
        return $matched ? ['order' => $order->get_id(), 'date' => wp_date('Y-m-d', $order->get_date_created()->getTimestamp()),
            'profit' => $amounts['retail'] === null ? null : $amounts['retail'] - $amounts['cost']] + $amounts : null;
    }

    private static function minor($value): int {
        return (int) str_replace('.', '', wc_format_decimal($value, 2));
    }

    private static function format(?int $value): string {
        return $value === null ? '' : ($value < 0 ? '-' : '') . Rule::decimal(abs($value), 2);
    }

    private static function selectedMonth(): string {
        return is_string($_GET['purchase_month'] ?? null) ? wp_unslash($_GET['purchase_month']) : wp_date('Y-m');
    }

    private static function url(): string {
        return add_query_arg(self::ENDPOINT, '1', wc_get_page_permalink('myaccount'));
    }

    public static function writeCsv($stream, string $month): void {
        $report = self::report($month);
        fputcsv($stream, ['order_id', 'date', 'currency', 'cost', 'suggested_retail', 'estimated_retail', 'expected_profit'], ',', '"', '');
        foreach ([...$report['rows'], ['order' => 'TOTAL', 'date' => $month] + $report['totals']] as $row) {
            fputcsv($stream, [$row['order'], $row['date'], 'BDT', self::format($row['cost']), self::format($row['suggested']),
                self::format($row['retail']), self::format($row['profit'])], ',', '"', '');
        }
    }

    public static function download(): void {
        $month = self::selectedMonth();
        if (!get_current_user_id() || !wp_verify_nonce(
            is_string($_GET['_wpnonce'] ?? null) ? wp_unslash($_GET['_wpnonce']) : '', self::EXPORT . '_' . $month)) {
            wp_die(esc_html__('Access denied.', 'sfm4u-wholesale'), '', ['response' => 403]);
        }
        $stream = fopen('php://temp', 'w+');
        try {
            self::writeCsv($stream, $month);
        } catch (DomainException) {
            fclose($stream);
            wp_die(esc_html__('Choose a valid purchase month.', 'sfm4u-wholesale'), '', ['response' => 422]);
        }
        nocache_headers();
        header('Cache-Control: private, no-store', true);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wholesale-purchases-' . $month . '.csv"');
        rewind($stream);
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    public static function render(): void {
        try {
            $report = self::report(self::selectedMonth());
        } catch (DomainException) {
            echo '<p>' . esc_html__('Choose a valid purchase month.', 'sfm4u-wholesale') . ' <a href="' . esc_url(self::url()) . '">'
                . esc_html__('Wholesale purchase history', 'sfm4u-wholesale') . '</a></p>';
            return;
        }
        $month = $report['month'];
        $url = wp_nonce_url(add_query_arg(['action' => self::EXPORT, 'purchase_month' => $month], admin_url('admin-post.php')), self::EXPORT . '_' . $month);
        echo '<h2>' . esc_html__('Wholesale purchase history', 'sfm4u-wholesale') . '</h2><form method="get" action="'
            . esc_url(wc_get_page_permalink('myaccount')) . '">';
        $accountPage = wp_parse_url(wc_get_page_permalink('myaccount'), PHP_URL_QUERY);
        if (is_string($accountPage)) {
            parse_str($accountPage, $args);
            if (isset($args['page_id'])) {
                echo '<input type="hidden" name="page_id" value="' . esc_attr((string) wc_get_page_id('myaccount')) . '">';
            }
        }
        echo '<input type="hidden" name="' . esc_attr(self::ENDPOINT) . '" value="1"><label>'
            . esc_html__('Purchase month', 'sfm4u-wholesale') . ' <input type="month" name="purchase_month" value="' . esc_attr($month)
            . '" required></label> <button>' . esc_html__('Show month', 'sfm4u-wholesale') . '</button></form>';
        echo '<p><a href="' . esc_url($url) . '">' . esc_html__('Download CSV', 'sfm4u-wholesale') . '</a></p><p>'
            . esc_html__('BDT merchandise only, after item refunds. Older orders may lack retail estimates.', 'sfm4u-wholesale')
            . '</p><table><thead><tr>';
        foreach ([__('Order', 'sfm4u-wholesale'), __('Date', 'sfm4u-wholesale'), __('Buy cost', 'sfm4u-wholesale'),
            __('Suggested customer price', 'sfm4u-wholesale'), __('Customer price used', 'sfm4u-wholesale'),
            __('Estimated profit', 'sfm4u-wholesale')] as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ([...$report['rows'], ['order' => __('Total', 'sfm4u-wholesale'), 'date' => $month] + $report['totals']] as $row) {
            echo '<tr><th scope="row">' . esc_html((string) $row['order']) . '</th><td>' . esc_html($row['date']) . '</td>';
            foreach (['cost', 'suggested', 'retail', 'profit'] as $key) {
                echo '<td>' . esc_html(self::format($row[$key]) ?: __('Unavailable', 'sfm4u-wholesale')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
