<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use SFM4U\POS\RegisterSessions;
use SFM4U\Wholesale\Pro\Settlement;
use SFM4U\Wholesale\{ReportCsv, Rule};

final class DailyClose
{
    public function __construct(private \wpdb $db, private Settlement $ar, private SupplierPayables $ap,
        private ?RegisterSessions $sessions = null) {}

    private const MARGIN_EXPORT = 'sfmwh_margin_csv';

    public function register(): void
    {
        add_action('admin_post_' . self::MARGIN_EXPORT, function (): void {
            ReportCsv::download(self::MARGIN_EXPORT, 'wholesale-margin.csv',
                ['from_utc', 'through_utc', 'currency', 'net_sales', 'net_cogs', 'gross_profit', 'coverage_gaps', 'gross_margin_percent'],
                fn(): array => [$this->margin(...self::marginDates())]);
        });
        add_action('admin_menu', function (): void {
            add_submenu_page('woocommerce', __('Daily Close', 'sfm4u-wholesale'), __('Daily Close', 'sfm4u-wholesale'), 'manage_woocommerce', 'sfm4u-daily-close', [$this, 'render']);
            add_submenu_page('woocommerce', __('Wholesale margin', 'sfm4u-wholesale'), __('Wholesale margin', 'sfm4u-wholesale'),
                'manage_woocommerce', 'sfm4u-margin', [$this, 'renderMargin']);
        });
    }

    public function report(string $day, int $merchant, string $register, int $opening = 0, int $counted = 0): array
    {
        if (!current_user_can('manage_woocommerce') || !(\SFM4U\POS\Runtime::enabled() || in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1'], true))) {
            throw new DomainException('Daily close is restricted to synthetic store managers.', 403);
        }
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $day)) {
            throw new DomainException('Enter a valid close date.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $day || $day > gmdate('Y-m-d') || $merchant < 1
            || !preg_match('/^[A-Z0-9]{1,20}$/D', $register) || $opening < 0 || $counted < 0) {
            throw new DomainException('Check the close date, register and cash amounts.');
        }
        $end = $date->modify('+1 day');
        if (!$this->db instanceof LossAwareWpdb || $this->db !== $GLOBALS['wpdb']) {
            throw new RuntimeException('Daily close requires a stable database connection.');
        }
        $this->db->pinTransaction();
        try {
            $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $totals = $this->dayTotals($day, $end->format('Y-m-d'), $merchant, $register);
            $drawer = $this->drawer($merchant, $register, $day, $end->format('Y-m-d'), $totals, $opening, $counted);
            $ar = $this->ar->aging($end->getTimestamp(), 'BDT');
            $ap = $this->ap->aging($day)['currencies']['BDT'] ?? array_fill_keys(['0-30','31-60','61-90','90+'], 0);
            $this->db->query('COMMIT');
        } catch (\Throwable $error) {
            try {
                $this->db->query('ROLLBACK');
            } catch (\Throwable) {
                // The failed database session may already be closed.
            }
            throw $error;
        } finally {
            $this->db->unpinTransaction();
        }
        $netSales = $totals['sales_minor'] - $totals['returns_minor'];
        $netCogs = $totals['sale_cogs_micros'] - $totals['reversed_cogs_micros'] + $totals['writeoff_micros'] + $totals['late_cogs_micros'];
        return $totals + [
            'date' => $day, 'currency' => 'BDT', 'net_sales_minor' => $netSales, 'net_cogs_micros' => $netCogs,
            'gross_profit_micros' => $netSales * StockValuation::MINOR_TO_MICROS - $netCogs,
            'ar_aging' => $ar, 'ap_aging' => $ap,
        ] + $drawer;
    }

    public function margin(string $from, string $through): array
    {
        if (!current_user_can('manage_woocommerce') || !\SFM4U\POS\Runtime::allowedContext()) {
            throw new DomainException('Margin report access is required.', 403);
        }
        $first = self::marginDate($from);
        $last = self::marginDate($through);
        if ($first > $last || $last > self::marginDate(gmdate('Y-m-d')) || $first->diff($last)->days > 30) {
            throw new DomainException('Invalid margin date range.');
        }
        if (!$this->db instanceof LossAwareWpdb || $this->db !== $GLOBALS['wpdb']) {
            throw new RuntimeException('Margin report requires a stable database connection.');
        }
        $this->db->pinTransaction();
        try {
            if ($this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') === false) {
                throw new RuntimeException('Margin snapshot unavailable.');
            }
            $totals = $this->dayTotals($from, $last->modify('+1 day')->format('Y-m-d'), 0, '');
            if ($this->db->query('COMMIT') === false) {
                throw new RuntimeException('Margin snapshot unavailable.');
            }
        } catch (\Throwable $error) {
            try { $this->db->query('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        } finally {
            $this->db->unpinTransaction();
        }

        $sales = $totals['sales_minor'] - $totals['returns_minor'];
        $cost = $totals['sale_cogs_micros'] - $totals['reversed_cogs_micros'] + $totals['writeoff_micros'] + $totals['late_cogs_micros'];
        $salesMicros = $sales * StockValuation::MINOR_TO_MICROS;
        $profit = $salesMicros - $cost;
        $complete = $totals['coverage_gaps'] === 0;
        return [$from, $through, 'BDT', self::decimal($sales, 2), self::decimal($cost, 6),
            $complete ? self::decimal($profit, 6) : '', $totals['coverage_gaps'],
            $complete && $salesMicros > 0 ? number_format(100 * $profit / $salesMicros, 2, '.', '') : ''];
    }

    private static function marginDate(string $value): \DateTimeImmutable
    {
        $date = preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Invalid margin report date.');
        }
        return $date;
    }

    private static function marginDates(): array
    {
        $today = gmdate('Y-m-d');
        return [
            is_string($_GET['margin_from'] ?? null) ? wp_unslash($_GET['margin_from']) : $today,
            is_string($_GET['margin_through'] ?? null) ? wp_unslash($_GET['margin_through']) : $today,
        ];
    }

    public function renderMargin(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        [$from, $through] = self::marginDates();
        echo '<div class="wrap"><h1>' . esc_html__('Wholesale margin', 'sfm4u-wholesale') . '</h1>'
            . '<p>' . esc_html__('Store-wide BDT inventory ledger. Select up to 31 days in UTC.', 'sfm4u-wholesale') . '</p>'
            . '<form method="get"><input type="hidden" name="page" value="sfm4u-margin">';
        foreach (['margin_from' => [__('From date', 'sfm4u-wholesale'), $from], 'margin_through' => [__('Through date', 'sfm4u-wholesale'), $through]] as $name => [$label, $value]) {
            echo '<label>' . esc_html($label) . ' <input type="date" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" required></label> ';
        }
        submit_button(__('View margin', 'sfm4u-wholesale'), 'primary', '', false);
        echo '</form>';
        try {
            $result = $this->margin($from, $through);
            $url = wp_nonce_url(add_query_arg(['action' => self::MARGIN_EXPORT, 'margin_from' => $from, 'margin_through' => $through],
                admin_url('admin-post.php')), self::MARGIN_EXPORT);
            echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Export CSV', 'sfm4u-wholesale') . '</a></p><dl>';
            foreach ([__('Net sales', 'sfm4u-wholesale'), __('Net Cogs', 'sfm4u-wholesale'), __('Gross Profit', 'sfm4u-wholesale'),
                __('Coverage Gaps', 'sfm4u-wholesale'), __('Gross margin (%)', 'sfm4u-wholesale')] as $index => $label) {
                $value = $result[$index + 3];
                echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value === '' ? __('Unavailable', 'sfm4u-wholesale') : (string) $value) . '</dd>';
            }
            echo '</dl>';
        } catch (DomainException | RuntimeException | \JsonException) {
            echo '<p role="alert">' . esc_html__('Check the margin dates and try again.', 'sfm4u-wholesale') . '</p>';
        }
        echo '</div>';
    }

    private function drawer(int $merchant, string $register, string $day, string $end, array $totals, int $opening, int $counted): array
    {
        if (!$this->sessions || !$this->sessions->beganBefore($merchant, $register, $end)) {
            $expected = $opening + $totals['cash_sales_minor'] - $totals['cash_returns_minor'];
            return ['drawer_source'=>'Legacy manual count', 'opening_minor'=>$opening, 'expected_cash_minor'=>$expected,
                'counted_minor'=>$counted, 'variance_minor'=>$counted - $expected, 'z_reports'=>[]];
        }
        $reports = $this->sessions->endedOn($merchant, $register, $day, $end);
        $summary = array_fill_keys(['opening_minor','expected_cash_minor','counted_minor','variance_minor','cash_in_minor','cash_out_minor',
            'session_cash_sales_minor','session_cash_returns_minor','session_cash_expense_minor','session_ledger_mismatches'], 0);
        foreach ($reports as $report) {
            $cash = $this->sessions->cashTotals($merchant, $register, $report['session_id']);
            $expected = $report['opening_minor'] + $cash['cash_sales_minor'] - $cash['cash_returns_minor']
                + $cash['cash_in_minor'] - $cash['cash_out_minor'];
            $savedCash = array_intersect_key($report, $cash) + ['cash_expense_minor' => 0];
            $summary['session_ledger_mismatches'] += $expected !== $report['expected_cash_minor']
                || $report['counted_minor'] - $expected !== $report['variance_minor']
                || $savedCash !== $cash ? 1 : 0;
            foreach (['opening_minor','expected_cash_minor','counted_minor','variance_minor','cash_in_minor','cash_out_minor'] as $field) {
                $summary[$field] += $report[$field];
            }
            $summary['session_cash_sales_minor'] += $report['cash_sales_minor'];
            $summary['session_cash_returns_minor'] += $report['cash_returns_minor'];
            $summary['session_cash_expense_minor'] += $savedCash['cash_expense_minor'];
        }
        return $summary + ['drawer_source'=>'Closed register sessions', 'z_reports'=>$reports,
            'sessions_open_at_cutoff'=>$this->sessions->openAtCutoff($merchant, $register, $end)];
    }

    private function dayTotals(string $day, string $end, int $merchant, string $register): array
    {
        $prefix = $this->db->prefix;
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT channel,merchant_id,response FROM %i WHERE created_at>=%s AND created_at<%s AND response IS NOT NULL
             AND channel IN ('pos','pos_credit','pos_drawer','b2b-fulfill','b2b-credit','supplier-po','stock-count','woo-stock')",
            $prefix . 'sfm4u_inventory_requests', $day, $end
        ), ARRAY_A);
        $this->checkReportRead();
        $totals = array_fill_keys(['sales_minor','returns_minor','sale_cogs_micros','reversed_cogs_micros','writeoff_micros',
            'late_cogs_micros','cash_sales_minor','cash_returns_minor','cash_sale_rounding_minor','cash_return_rounding_minor',
            'ar_credit_minor','net30_refund_minor','cash_expense_minor','inventory_adjusted_units',
            'inventory_value_adjustment_micros','coverage_gaps','manual_card_sales_minor','manual_mfs_sales_minor','manual_card_returns_minor','manual_mfs_returns_minor'], 0);
        foreach ($rows as $row) {
            $event = json_decode($row['response'], true, 32, JSON_THROW_ON_ERROR);
            $channel = $row['channel'];
            if ($channel === 'woo-stock') {
                if (($event['currency'] ?? '') === 'BDT') {
                    foreach (['sales_minor', 'returns_minor', 'writeoff_micros', 'coverage_gaps'] as $field) {
                        $totals[$field] += $event[$field];
                    }
                    $totals['sale_cogs_micros'] += $event['cogs_micros'];
                    $totals['reversed_cogs_micros'] += $event['reversed_micros'];
                }
                continue;
            }
            if ($channel === 'stock-count') {
                if (($event['status'] ?? '') === 'approved' && ($event['currency'] ?? '') === 'BDT') {
                    foreach ($event['lines'] ?? [$event] as $line) {
                        $totals['inventory_adjusted_units'] += $line['delta'];
                        $totals['inventory_value_adjustment_micros'] += $line['value_delta_micros'];
                    }
                }
                continue;
            }
            if ($channel === 'pos_drawer') {
                if ((int) $row['merchant_id'] === $merchant && ($event['register'] ?? '') === $register
                    && ($event['direction'] ?? '') === 'out' && ($event['reason'] ?? '') === RegisterSessions::EXPENSE_REASON) {
                    $totals['cash_expense_minor'] += $event['amount_minor'];
                }
                continue;
            }
            if ($channel === 'supplier-po') {
                if (isset($event['cogs_adjustment_micros'])) {
                    if (($event['currency'] ?? '') === 'BDT') {
                        $totals['late_cogs_micros'] += $event['cogs_adjustment_micros'];
                    } elseif (!isset($event['currency'])) {
                        $totals['coverage_gaps']++;
                    }
                }
                continue;
            }
            $amounts = $event['sale'] ?? $event;
            if (!isset($amounts['currency'])) {
                $totals['coverage_gaps']++;
                continue;
            }
            if ($amounts['currency'] !== 'BDT') {
                continue;
            }
            $inDrawer = (int) $row['merchant_id'] === $merchant && ($event['register'] ?? '') === $register;
            $orderId = (int) $event['order_id'];
            if (in_array($channel, ['pos','b2b-fulfill'], true)) {
                $total = $amounts['sales_minor'] ?? $amounts['total_minor'];
                $totals['sales_minor'] += $total;
                if ($channel === 'pos' && $inDrawer) {
                    $totals['cash_sales_minor'] += $amounts['cash_received_minor'] ?? $amounts['tender_minor'] - $amounts['change_minor'];
                    if(in_array($amounts['tender_type']??'cash',['manual_card','manual_mfs'],true))$totals[$amounts['tender_type'].'_sales_minor']+=$total;
                    $totals['cash_sale_rounding_minor'] += $amounts['rounding_minor'] ?? 0;
                }
                $cost = $this->db->get_var($this->db->prepare('SELECT SUM(cogs_micros) FROM %i WHERE order_id=%d',
                    $prefix . 'sfm4u_inventory_sale_costs', $orderId));
                $this->checkReportRead();
                $totals['sale_cogs_micros'] += (int) $cost;
                $totals['coverage_gaps'] += (int) $this->db->get_var($this->db->prepare(
                    'SELECT GREATEST(COUNT(*)-(SELECT COUNT(*) FROM %i WHERE order_id=%d),0)
                     FROM %i WHERE order_id=%d AND order_item_type=%s',
                    $prefix . 'sfm4u_inventory_sale_costs', $orderId, $prefix . 'woocommerce_order_items', $orderId, 'line_item'
                ));
                $this->checkReportRead();
            } else {
                $totals['returns_minor'] += $amounts['total_minor'];
                if ($channel === 'b2b-credit') {
                    $totals['ar_credit_minor'] += $amounts['ar_minor'];
                    $totals['net30_refund_minor'] += $amounts['refund_minor'];
                } elseif ($inDrawer) {
                    $totals['cash_returns_minor'] += $amounts['cash_refund_minor'] ?? $amounts['refund_minor'] ?? $amounts['cash_minor'] ?? 0;
                    if(in_array($amounts['tender_type']??'cash',['manual_card','manual_mfs'],true))$totals[$amounts['tender_type'].'_returns_minor']+=$amounts['refund_minor'];
                    $totals['cash_return_rounding_minor'] += $amounts['rounding_minor'] ?? 0;
                }
                $cost = $this->db->get_row($this->db->prepare(
                    'SELECT SUM(reversed_micros) AS reversed,SUM(writeoff_micros) AS written_off FROM %i WHERE refund_id=%d',
                    $prefix . 'sfm4u_inventory_return_costs', $orderId
                ), ARRAY_A);
                $this->checkReportRead();
                $totals['reversed_cogs_micros'] += (int) $cost['reversed'];
                $totals['writeoff_micros'] += (int) $cost['written_off'];
                $totals['coverage_gaps'] += $cost['reversed'] === null ? 1 : 0;
            }
        }
        return $totals;
    }

    private function checkReportRead(): void
    {
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Inventory ledger report unavailable.');
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Daily Close access is required.', 'sfm4u-wholesale'), '', ['response'=>403]);
        }
        echo '<div class="wrap"><h1>' . esc_html__('Daily Close', 'sfm4u-wholesale') . '</h1><form method="get"><input type="hidden" name="page" value="sfm4u-daily-close">';
        $field = static fn(string $name, string $default): string =>
            is_string($_GET[$name] ?? null) ? wp_unslash($_GET[$name]) : $default;
        $values = ['day'=>$field('day', gmdate('Y-m-d')), 'merchant'=>$field('merchant', ''), 'register'=>$field('register', 'R1'),
            'opening'=>$field('opening', '0'), 'counted'=>$field('counted', '0')];
        foreach (['day'=>'Close date (UTC)', 'merchant'=>'Merchant ID', 'register'=>'Register',
            'opening'=>'Legacy only · opening cash (minor units)', 'counted'=>'Legacy only · counted cash (minor units)'] as $name => $label) {
            echo '<p><label for="close-' . esc_attr($name) . '">' . esc_html(__($label, 'sfm4u-wholesale'))
                . '</label><br><input required id="close-' . esc_attr($name) . '" name="' . esc_attr($name)
                . '" value="' . esc_attr($values[$name]) . '"></p>';
        }
        echo '<button class="button button-primary">' . esc_html__('View daily close', 'sfm4u-wholesale') . '</button></form>';
        if ($values['merchant'] !== '') {
            try {
                $result = $this->report($values['day'], Rule::number($values['merchant'], 1, 9999999999), $values['register'],
                    Rule::number($values['opening'], 0, 9999999999), Rule::number($values['counted'], 0, 9999999999));
                echo '<h2>' . esc_html__('Ledger totals · BDT', 'sfm4u-wholesale') . '</h2><dl>';
                foreach ($result as $name => $value) {
                    if ($name === 'z_reports') continue;
                    if (is_array($value)) {
                        $value = implode(' · ', array_map(static fn(string $bucket, int $balance): string =>
                            $bucket . ': ' . self::decimal($balance, 2), array_keys($value), $value));
                    } elseif (is_int($value) && str_ends_with($name, '_minor')) {
                        $value = self::decimal($value, 2);
                    } elseif (is_int($value) && str_ends_with($name, '_micros')) {
                        $value = self::decimal($value, 6);
                    }
                    echo '<dt>' . esc_html(__(ucwords(str_replace('_', ' ', preg_replace('/_(minor|micros)$/', '', $name))), 'sfm4u-wholesale'))
                        . '</dt><dd>' . esc_html($name === 'drawer_source' ? __((string) $value, 'sfm4u-wholesale') : (string) $value) . '</dd>';
                }
                echo '</dl>';
                foreach ($result['z_reports'] as $z) {
                    echo '<h3>' . esc_html__('Z-report', 'sfm4u-wholesale') . ' · ' . esc_html($z['number']) . '</h3><p>'
                        . esc_html(sprintf(__('Expected BDT %s · Counted BDT %s · Variance BDT %s', 'sfm4u-wholesale'),
                            self::decimal($z['expected_cash_minor'], 2), self::decimal($z['counted_minor'], 2),
                            self::decimal($z['variance_minor'], 2))) . '</p>';
                }
                if (!empty($result['session_ledger_mismatches'])) {
                    echo '<p role="alert">' . esc_html__('One or more Z-reports differ from the cash ledger.', 'sfm4u-wholesale') . '</p>';
                }
                if (!empty($result['sessions_open_at_cutoff'])) {
                    echo '<p role="status">' . esc_html__('Open sessions are excluded from closed drawer totals.', 'sfm4u-wholesale') . '</p>';
                }
                if ($result['coverage_gaps']) {
                    echo '<p role="status">' . esc_html__('Some historical entries lack original cost or currency. Gross profit is incomplete.', 'sfm4u-wholesale') . '</p>';
                }
            } catch (DomainException $error) {
                echo '<p role="alert">' . esc_html__('Check the submitted fields and stock balances, then retry with the same request key.', 'sfm4u-wholesale') . '</p>';
            } catch (RuntimeException) {
                echo '<p role="alert">' . esc_html__('Daily close could not be read.', 'sfm4u-wholesale') . '</p>';
            }
        }
        echo '</div>';
    }

    private static function decimal(int $amount, int $scale): string
    {
        return ($amount < 0 ? '-' : '') . Rule::decimal(abs($amount), $scale);
    }
}
