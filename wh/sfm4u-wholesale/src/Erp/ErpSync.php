<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Erp;

use DomainException;
use SFM4U\Wholesale\Members\Members;

defined('ABSPATH') || exit;

/**
 * ERPNext stock link for WooCommerce (off until an administrator turns it on).
 *
 * Products are matched by WooCommerce SKU = ERPNext Item Code, in one ERPNext warehouse.
 * The owner chooses ONE stock master:
 *  - "pull": ERPNext is the master; the website's stock quantity is set from ERPNext.
 *  - "push": the website is the master; ERPNext gets a Stock Reconciliation with the website quantities.
 * "Preview" shows every change first and changes nothing. API key/secret are stored encrypted.
 */
final class ErpSync {
    public const OPT = 'sfm_wr_erp';
    public const LOG = 'sfm_wr_erp_log';
    public const CRON = 'sfm_wr_erp_sync';

    public static function register(): void {
        add_action(self::CRON, [self::class, 'cron']);
        add_action('admin_init', [self::class, 'schedule']);
        // 0.9.5: send paid orders to ERPNext when the owner turns it on (option sfmwh_erp_orders = yes; off by default).
        add_action('woocommerce_order_status_processing', [self::class, 'onPaid'], 30);
        add_action('woocommerce_order_status_completed', [self::class, 'onPaid'], 30);
        // 0.9.9: POS sales and all refunds are exported by a one-off job AFTER their transaction commits,
        // so ERPNext never receives a sale/refund that the shop rolled back.
        add_action(self::JOB_ORDER, [self::class, 'runOrderJob']);
        add_action('woocommerce_refund_created', [self::class, 'onRefundCreated'], 5, 2);
        add_action('woocommerce_order_refunded', [self::class, 'onRefunded'], 30, 2);
        add_action(self::JOB_REFUND, [self::class, 'runRefundJob']);
    }

    public const JOB_ORDER = 'sfmwh_erp_export_order';
    public const JOB_REFUND = 'sfmwh_erp_export_refund';

    private static function ordersOn(): bool {
        return get_option('sfmwh_erp_orders', 'no') === 'yes' && self::settings()['enabled'] === '1';
    }

    private static function later(string $hook, int $id, int $delay = 30): void {
        if (!wp_next_scheduled($hook, [$id])) { wp_schedule_single_event(time() + $delay, $hook, [$id]); }
    }

    public static function onPaid($orderId): void {
        if (!self::ordersOn()) { return; }
        $order = wc_get_order((int) $orderId);
        if (!$order) { return; }
        if (str_starts_with((string) $order->get_created_via(), 'sfm4u-pos')) { self::later(self::JOB_ORDER, $order->get_id()); return; }
        self::exportNow($order);
    }

    /** One-off job for a POS sale: re-check the sale still stands, then export once. */
    public static function runOrderJob($orderId): void {
        if (!self::ordersOn()) { return; }
        $order = wc_get_order((int) $orderId);
        if (!$order || !in_array($order->get_status(), ['processing', 'completed'], true)) { return; }
        self::exportNow($order);
    }

    private static function exportNow(\WC_Order $order): void {
        try {
            self::exportOrder($order);
        } catch (\Throwable $e) {
            $order->add_order_note('ERPNext: ' . mb_substr($e->getMessage(), 0, 200));
            self::log('order ' . $order->get_id() . ': ' . $e->getMessage());
        }
    }

    public static function settings(): array {
        $s = get_option(self::OPT, []);
        $s = is_array($s) ? $s : [];
        return $s + ['enabled' => '0', 'url' => '', 'key' => '', 'secret_enc' => '', 'warehouse' => '', 'direction' => 'pull', 'auto' => '0'];
    }

    public static function save(array $in): void {
        $s = self::settings();
        $url = untrailingslashit(esc_url_raw(trim((string) ($in['url'] ?? ''))));
        if ($url !== '' && !preg_match('#^https://#i', $url) && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#i', $url)) {
            throw new DomainException(__('The ERPNext address must start with https://', 'sfm4u-wholesale'));
        }
        $s['url'] = $url;
        $s['key'] = preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['key'] ?? ''));
        if (!empty($in['secret'])) { $s['secret_enc'] = Members::encrypt(preg_replace('/[^A-Za-z0-9]/', '', (string) $in['secret'])); }
        $s['warehouse'] = sanitize_text_field((string) ($in['warehouse'] ?? ''));
        $s['direction'] = ($in['direction'] ?? '') === 'push' ? 'push' : 'pull';
        $s['enabled'] = !empty($in['enabled']) ? '1' : '0';
        $s['auto'] = !empty($in['auto']) ? '1' : '0';
        update_option(self::OPT, $s, false);
        self::schedule();
    }

    public static function schedule(): void {
        $s = self::settings();
        $on = $s['enabled'] === '1' && $s['auto'] === '1';
        $next = wp_next_scheduled(self::CRON);
        if ($on && !$next) { wp_schedule_event(time() + 300, 'hourly', self::CRON); }
        if (!$on && $next) { wp_clear_scheduled_hook(self::CRON); }
    }

    public static function client(): ErpNextClient {
        $s = self::settings();
        if ($s['url'] === '' || $s['key'] === '' || $s['secret_enc'] === '') { throw new DomainException(__('Enter the ERPNext address, API key and API secret first.', 'sfm4u-wholesale')); }
        return new ErpNextClient($s['url'], $s['key'], Members::decrypt($s['secret_enc']));
    }

    /**
     * Pure planning step (unit-tested): which SKUs change, in which direction.
     * @param array<string,float> $site SKU => website stock (managed-stock products only)
     * @param array<string,float> $erp  SKU => ERPNext actual_qty in the chosen warehouse
     * @return array<int,array{sku:string,site:?float,erp:?float,action:string,qty:?float}>
     */
    public static function plan(string $direction, array $site, array $erp): array {
        $rows = [];
        $skus = array_unique(array_merge(array_keys($site), array_keys($erp)));
        sort($skus, SORT_STRING);
        foreach ($skus as $sku) {
            $a = array_key_exists($sku, $site) ? (float) $site[$sku] : null;
            $b = array_key_exists($sku, $erp) ? (float) $erp[$sku] : null;
            if ($a === null) { $rows[] = ['sku' => (string) $sku, 'site' => null, 'erp' => $b, 'action' => 'not_on_site', 'qty' => null]; continue; }
            if ($b === null) { $rows[] = ['sku' => (string) $sku, 'site' => $a, 'erp' => null, 'action' => $direction === 'push' ? 'set_erp' : 'not_in_erp', 'qty' => $direction === 'push' ? $a : null]; continue; }
            if (abs($a - $b) < 0.0001) { $rows[] = ['sku' => (string) $sku, 'site' => $a, 'erp' => $b, 'action' => 'same', 'qty' => null]; continue; }
            $rows[] = $direction === 'push'
                ? ['sku' => (string) $sku, 'site' => $a, 'erp' => $b, 'action' => 'set_erp', 'qty' => $a]
                : ['sku' => (string) $sku, 'site' => $a, 'erp' => $b, 'action' => 'set_site', 'qty' => $b];
        }
        return $rows;
    }

    /** Run a preview (dry run) or a real sync. Returns the plan rows. */
    public static function run(bool $dryRun, ?StockStore $store = null, ?ErpNextClient $client = null): array {
        $s = self::settings();
        if (!$dryRun && $s['enabled'] !== '1') { throw new DomainException(__('Turn on the ERP link first.', 'sfm4u-wholesale')); }
        if ($s['warehouse'] === '') { throw new DomainException(__('Enter the ERPNext warehouse name.', 'sfm4u-wholesale')); }
        $store = $store ?: new WooStockStore();
        $client = $client ?: self::client();
        $rows = self::plan($s['direction'], $store->quantities(), $client->binQuantities($s['warehouse']));
        if ($dryRun) { return $rows; }
        $done = 0;
        if ($s['direction'] === 'pull') {
            foreach ($rows as $r) { if ($r['action'] === 'set_site') { $store->setQuantity($r['sku'], (float) $r['qty']); $done++; } }
        } else {
            $items = []; $opening = [];
            $det = $store->details();
            $noBin = array_map(static fn($r) => $r['sku'], array_filter($rows, static fn($r) => $r['action'] === 'set_erp' && $r['erp'] === null));
            $known = $noBin ? $client->existingItems($noBin) : [];
            foreach ($rows as $r) {
                // Only items that exist in ERPNext (use "Copy products to ERPNext" first). valuation_rate is needed for items with no stock yet.
                if ($r['action'] !== 'set_erp') { continue; }
                $line = ['item_code' => $r['sku'], 'warehouse' => $s['warehouse'], 'qty' => (float) $r['qty'], 'valuation_rate' => (float) ($det[$r['sku']]['price'] ?? 0)];
                if ($r['erp'] !== null) { $items[] = $line; }
                elseif (isset($known[$r['sku']]) && (float) $r['qty'] > 0) { $opening[] = $line; } // first stock of this item in this warehouse
            }
            if ($items || $opening) {
                $company = $client->warehouseCompany($s['warehouse']);
                if ($opening) {
                    $temp = $client->temporaryAccount($company);
                    if ($temp === '') { throw new DomainException(__('ERPNext has no Temporary Opening account for this company.', 'sfm4u-wholesale')); }
                    $client->stockReconciliation($opening, $company, true, $temp);
                }
                if ($items) { $client->stockReconciliation($items, $company); }
                $done = count($items) + count($opening);
            }
        }
        self::log(sprintf('%s: %d changed (%d products checked)', $s['direction'] === 'push' ? 'website → ERPNext' : 'ERPNext → website', $done, count($rows)));
        return $rows;
    }

    /**
     * Copy website products that ERPNext does not have yet as ERPNext Items (item code = SKU, group "Products", unit "Nos").
     * Preview first. Returns the list of SKUs created (or that would be created).
     */
    public static function exportItems(bool $dryRun, ?StockStore $store = null, ?ErpNextClient $client = null, string $group = 'Products'): array {
        $store = $store ?: new WooStockStore();
        $client = $client ?: self::client();
        $det = $store->details();
        $have = $client->existingItems(array_keys($det));
        $todo = array_values(array_filter(array_keys($det), static fn($sku) => !isset($have[(string) $sku])));
        if ($dryRun) { return $todo; }
        $made = [];
        foreach (array_slice($todo, 0, 300) as $sku) {
            $client->createItem((string) $sku, (string) $det[$sku]['name'], (float) $det[$sku]['price'], $group);
            $made[] = (string) $sku;
        }
        self::log(sprintf('Copied %d products to ERPNext as Items.', count($made)));
        return $made;
    }

    /* ---------------- 0.9.5: orders and member sales -> right company/branch ---------------- */

    /**
     * Pure: which ERPNext warehouse (branch) an order belongs to.
     * $map keys: 'pos:<REGISTER>' for counter sales, 'web' for online orders. Falls back to $default.
     */
    public static function branchFor(array $map, string $source, string $register, string $default): string {
        $key = $source === 'pos' ? 'pos:' . strtoupper($register) : 'web';
        $w = isset($map[$key]) && is_string($map[$key]) ? trim($map[$key]) : '';
        return $w !== '' ? $w : $default;
    }

    /** Pure: ERPNext Sales Invoice document for one shop order (amounts in minor units in $o). */
    public const OPT_ROUND_OFF = 'sfmwh_erp_round_off_account';
    public const META_RESTOCK = '_sfmwh_erp_restock';

    /** Remember whether WooCommerce put the refunded items back in stock (resellable) or not (damaged). */
    public static function onRefundCreated($refundId, $args = []): void {
        $refund = wc_get_order((int) $refundId);
        if (!$refund instanceof \WC_Order_Refund) { return; }
        $refund->update_meta_data(self::META_RESTOCK, !empty($args['restock_items']) ? '1' : '0');
        $refund->save_meta_data();
    }

    /**
     * ERPNext rounds each item rate; WooCommerce keeps exact line totals and a POS cash-rounding fee.
     * Returns one "Actual" charge row (to the round-off account) for the difference, so the ERP document total
     * equals the WooCommerce total to the minor unit. $sign is 1 for invoices, -1 for returns.
     */
    public static function roundingRow(array $items, int $targetMinor, int $scale, int $sign, string $account): array {
        $sent = 0;
        foreach ($items as $it) { $sent += (int) round($it['rate'] * (10 ** $scale)) * abs((int) $it['qty']); }
        $diff = $sign * ($targetMinor - $sent);
        if ($diff === 0) { return []; }
        if ($account === '') {
            throw new DomainException('Set the ERPNext round-off account (cash rounding) before exporting this document.');
        }
        return [['charge_type' => 'Actual', 'account_head' => $account, 'description' => 'Cash and unit-rate rounding (WooCommerce)',
            'tax_amount' => round($diff / (10 ** $scale), $scale)]];
    }

    /** Minor units of the POS cash-rounding fee lines on an order or refund. */
    public static function roundingFeeMinor($order, int $scale): int {
        $sum = 0;
        // Refund fee lines do not copy the original line's meta: look at the refunded original fee line instead.
        $parent = $order instanceof \WC_Order_Refund ? wc_get_order($order->get_parent_id()) : null;
        foreach ($order->get_items('fee') as $fee) {
            $source = $fee;
            if ($parent) {
                $source = $parent->get_item((int) $fee->get_meta('_refunded_item_id'));
                if (!$source) { continue; }
            }
            if ((string) $source->get_meta('_sfm4u_cash_rounding') === '1') { $sum += (int) round((float) $fee->get_total() * (10 ** $scale)); }
        }
        return $sum;
    }

    public static function invoiceDoc(array $o, string $company, string $warehouse, string $customer = 'Walk-in Customer'): array {
        if ($company === '' || $warehouse === '') {
            throw new DomainException('ERPNext company and warehouse are required.');
        }
        $scale = (int) ($o['scale'] ?? 2);
        $items = [];
        foreach ($o['lines'] as $l) {
            if (($l['sku'] ?? '') === '' || (int) $l['qty'] < 1) {
                throw new DomainException('Every order line needs a SKU (ERPNext item code) and a quantity.');
            }
            $rate = round(((int) $l['total_minor'] / (int) $l['qty']) / (10 ** $scale), $scale);
            $items[] = ['item_code' => (string) $l['sku'], 'qty' => (int) $l['qty'], 'rate' => $rate, 'warehouse' => $warehouse];
        }
        $member = !empty($o['member_no_masked']);
        $target = array_sum(array_map(fn($l) => (int) $l['total_minor'], $o['lines'])) + (int) ($o['rounding_minor'] ?? 0);
        $taxes = self::roundingRow($items, $target, $scale, 1, (string) ($o['round_off_account'] ?? ''));
        return ($taxes ? ['taxes' => $taxes, 'disable_rounded_total' => 1] : []) + [
            'doctype' => 'Sales Invoice', 'docstatus' => 1, 'company' => $company, 'customer' => $customer,
            'posting_date' => (string) $o['date'], 'currency' => (string) $o['currency'], 'update_stock' => 1,
            'set_warehouse' => $warehouse, 'is_pos' => ($o['source'] ?? '') === 'pos' ? 1 : 0, 'items' => $items,
            'remarks' => sprintf('WooCommerce order #%s (%s)%s', (string) $o['number'], (string) ($o['source'] ?? 'web'),
                $member ? sprintf('; SFM4U member %s (%s) price', (string) $o['member_no_masked'], (string) ($o['member_tier'] ?? '')) : ''),
            'po_no' => 'WC-' . (string) $o['id'],
        ];
    }

    /** Order data for invoiceDoc(). */
    public static function orderData(\WC_Order $order): array {
        $lines = [];
        $scale = wc_get_price_decimals();
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) { continue; }
            $p = $item->get_product();
            $lines[] = ['sku' => $p ? (string) $p->get_sku() : '', 'qty' => (int) $item->get_quantity(),
                'total_minor' => (int) round((float) $item->get_total() * (10 ** $scale))];
        }
        $pos = str_starts_with((string) $order->get_created_via(), 'sfm4u-pos');
        return ['id' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : gmdate('Y-m-d'),
            'currency' => $order->get_currency(), 'scale' => $scale, 'lines' => $lines, 'source' => $pos ? 'pos' : 'web',
            'register' => (string) $order->get_meta('_sfm4u_pos_register'),
            'member_no_masked' => (string) $order->get_meta('_sfmwh_member_no_masked'), 'member_tier' => (string) $order->get_meta('_sfmwh_member_tier'),
            'rounding_minor' => self::roundingFeeMinor($order, $scale), 'round_off_account' => (string) get_option(self::OPT_ROUND_OFF, '')];
    }

    /** Send one order to ERPNext once. Returns the invoice name (existing one if already sent). */
    public static function exportOrder(\WC_Order $order, ?ErpNextClient $client = null, ?array $map = null): string {
        $done = (string) $order->get_meta('_sfmwh_erp_invoice');
        if ($done !== '') { return $done; }
        $s = self::settings();
        $client = $client ?? self::client();
        $o = self::orderData($order);
        $map = $map ?? (array) json_decode((string) get_option('sfmwh_erp_branch_map', '{}'), true);
        $warehouse = self::branchFor($map, $o['source'], $o['register'], (string) $s['warehouse']);
        $company = $client->warehouseCompany($warehouse);
        $name = $client->salesInvoice(self::invoiceDoc($o, $company, $warehouse, (string) get_option('sfmwh_erp_customer', 'Walk-in Customer')));
        if ($name === '') { throw new DomainException('ERPNext did not return an invoice number.'); }
        $order->update_meta_data('_sfmwh_erp_invoice', $name);
        $order->update_meta_data('_sfmwh_erp_company', $company);
        $order->update_meta_data('_sfmwh_erp_warehouse', $warehouse);
        $order->save();
        return $name;
    }

    /* ---------- 0.9.9: refunds / returns -> ERPNext return Sales Invoice (credit note) ---------- */

    public static function onRefunded($orderId, $refundId): void {
        if (!self::ordersOn()) { return; }
        self::later(self::JOB_REFUND, (int) $refundId);
    }

    public static function runRefundJob($refundId): void {
        if (!self::ordersOn()) { return; }
        $refund = wc_get_order((int) $refundId);
        if (!$refund instanceof \WC_Order_Refund) { return; }
        try {
            self::exportRefund($refund);
        } catch (\Throwable $e) {
            $parent = wc_get_order($refund->get_parent_id());
            if ($parent) { $parent->add_order_note('ERPNext return: ' . mb_substr($e->getMessage(), 0, 200)); }
            self::log('refund ' . $refund->get_id() . ': ' . $e->getMessage());
            $tries = (int) $refund->get_meta('_sfmwh_erp_return_tries') + 1;
            $refund->update_meta_data('_sfmwh_erp_return_tries', $tries); $refund->save();
            if ($tries < 24) { self::later(self::JOB_REFUND, $refund->get_id(), 300 * $tries); }
        }
    }

    /** Pure: ERPNext return invoice against the original invoice (quantities negative, rates positive). */
    public static function returnDoc(array $r, string $against, string $company, string $warehouse, string $customer = 'Walk-in Customer'): array {
        if ($against === '' || $company === '' || $warehouse === '') {
            throw new DomainException('ERPNext return needs the original invoice, company and warehouse.');
        }
        $scale = (int) ($r['scale'] ?? 2);
        $items = [];
        foreach ($r['lines'] as $l) {
            $qty = abs((int) $l['qty']);
            if (($l['sku'] ?? '') === '' || $qty < 1) { throw new DomainException('Every returned line needs a SKU and a quantity.'); }
            $items[] = ['item_code' => (string) $l['sku'], 'qty' => -$qty, 'rate' => round((abs((int) $l['total_minor']) / $qty) / (10 ** $scale), $scale), 'warehouse' => $warehouse];
        }
        if (!$items) { throw new DomainException('Amount-only refund: no returned items. Make the credit note in ERPNext by hand.'); }
        $target = array_sum(array_map(fn($l) => abs((int) $l['total_minor']), $r['lines'])) + abs((int) ($r['rounding_minor'] ?? 0));
        $taxes = self::roundingRow($items, $target, $scale, -1, (string) ($r['round_off_account'] ?? ''));
        $restock = ($r['restock'] ?? '1') !== '0';
        return ($taxes ? ['taxes' => $taxes, 'disable_rounded_total' => 1] : []) + ['doctype' => 'Sales Invoice', 'docstatus' => 1, 'is_return' => 1, 'return_against' => $against, 'company' => $company,
            'customer' => $customer, 'posting_date' => (string) $r['date'], 'currency' => (string) $r['currency'], 'update_stock' => $restock ? 1 : 0,
            'set_warehouse' => $warehouse, 'is_pos' => ($r['source'] ?? '') === 'pos' ? 1 : 0, 'items' => $items,
            'remarks' => sprintf('WooCommerce refund #%s of order #%s%s', (string) $r['id'], (string) $r['number'], $restock ? '' : ' (damaged: not back in stock)'), 'po_no' => 'WC-R-' . (string) $r['id']];
    }

    /** Send one refund once. The original order must already be in ERPNext (else it throws and the job retries). */
    public static function exportRefund(\WC_Order_Refund $refund, ?ErpNextClient $client = null): string {
        $done = (string) $refund->get_meta('_sfmwh_erp_return');
        if ($done !== '') { return $done; }
        $order = wc_get_order($refund->get_parent_id());
        if (!$order) { throw new DomainException('Original order not found.'); }
        $against = (string) $order->get_meta('_sfmwh_erp_invoice');
        if ($against === '') { throw new DomainException('Original order is not in ERPNext yet; will retry.'); }
        $scale = wc_get_price_decimals();
        $lines = [];
        foreach ($refund->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product || (int) $item->get_quantity() === 0) { continue; }
            $p = $item->get_product();
            $lines[] = ['sku' => $p ? (string) $p->get_sku() : '', 'qty' => (int) $item->get_quantity(), 'total_minor' => (int) round((float) $item->get_total() * (10 ** $scale))];
        }
        $o = self::orderData($order);
        $r = ['id' => $refund->get_id(), 'number' => $o['number'], 'date' => gmdate('Y-m-d'), 'currency' => $o['currency'], 'scale' => $scale, 'source' => $o['source'], 'lines' => $lines,
            'rounding_minor' => self::roundingFeeMinor($refund, $scale), 'round_off_account' => (string) get_option(self::OPT_ROUND_OFF, ''),
            'restock' => (string) $refund->get_meta(self::META_RESTOCK)];
        $client = $client ?? self::client();
        $name = $client->salesInvoice(self::returnDoc($r, $against, (string) $order->get_meta('_sfmwh_erp_company'),
            (string) $order->get_meta('_sfmwh_erp_warehouse'), (string) get_option('sfmwh_erp_customer', 'Walk-in Customer')));
        if ($name === '') { throw new DomainException('ERPNext did not return a credit note number.'); }
        $refund->update_meta_data('_sfmwh_erp_return', $name);
        $refund->save();
        return $name;
    }

    public static function cron(): void {
        try { self::run(false); } catch (\Throwable $e) { self::log('ERROR: ' . $e->getMessage()); }
    }

    public static function log(string $msg): void {
        $l = get_option(self::LOG, []);
        $l = is_array($l) ? $l : [];
        $l[] = [current_time('mysql', true), mb_substr($msg, 0, 300)];
        update_option(self::LOG, array_slice($l, -50), false);
    }
}
