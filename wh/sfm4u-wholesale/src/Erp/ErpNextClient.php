<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Erp;

use DomainException;

defined('ABSPATH') || exit;

/** Minimal ERPNext (Frappe) REST client: token auth, Bin list, Stock Reconciliation. */
final class ErpNextClient {
    public function __construct(private string $url, private string $key, private string $secret) {}

    /** @return array<mixed> */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array {
        $u = $this->url . $path . ($query ? '?' . http_build_query($query) : '');
        $args = ['method' => $method, 'timeout' => 20, 'headers' => [
            'Authorization' => 'token ' . $this->key . ':' . $this->secret, 'Accept' => 'application/json',
        ]];
        if ($body !== null) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body); }
        $res = wp_remote_request($u, $args);
        if (is_wp_error($res)) { throw new DomainException(__('Could not reach ERPNext:', 'sfm4u-wholesale') . ' ' . $res->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code === 401 || $code === 403) { throw new DomainException(__('ERPNext refused the API key or secret.', 'sfm4u-wholesale')); }
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $msg = is_array($data) ? (string) ($data['exception'] ?? $data['message'] ?? '') : '';
            throw new DomainException(sprintf(__('ERPNext error %d.', 'sfm4u-wholesale'), $code) . ($msg !== '' ? ' ' . mb_substr(wp_strip_all_tags($msg), 0, 200) : ''));
        }
        return $data;
    }

    public function whoAmI(): string {
        $d = $this->request('GET', '/api/method/frappe.auth.get_logged_user');
        return (string) ($d['message'] ?? '');
    }

    /** @return array<string,float> item_code => actual_qty */
    public function binQuantities(string $warehouse): array {
        $out = [];
        for ($start = 0; $start < 50000; $start += 500) {
            $d = $this->request('GET', '/api/resource/Bin', [
                'fields' => wp_json_encode(['item_code', 'actual_qty']),
                'filters' => wp_json_encode([['warehouse', '=', $warehouse]]),
                'limit_start' => $start, 'limit_page_length' => 500,
            ]);
            $rows = is_array($d['data'] ?? null) ? $d['data'] : [];
            foreach ($rows as $r) { if (isset($r['item_code'])) { $out[(string) $r['item_code']] = (float) ($r['actual_qty'] ?? 0); } }
            if (count($rows) < 500) { break; }
        }
        return $out;
    }

    /** Submit one Stock Reconciliation that sets the given quantities. Returns the document name.
     *  $company is needed when ERPNext has more than one company (SFM4U BD and SFM4U LLC). */
    public function stockReconciliation(array $items, string $company = '', bool $opening = false, string $tempAccount = ''): string {
        // ERPNext: the first stock of an item must be an "Opening Stock" entry against the Temporary Opening account.
        $doc = ['purpose' => $opening ? 'Opening Stock' : 'Stock Reconciliation', 'docstatus' => 1, 'items' => array_values($items)];
        if ($company !== '') { $doc['company'] = $company; }
        if ($opening && $tempAccount !== '') { $doc['expense_account'] = $tempAccount; }
        $d = $this->request('POST', '/api/resource/Stock%20Reconciliation', [], $doc);
        return (string) ($d['data']['name'] ?? '');
    }

    /** The company's "Temporary" (opening) account, e.g. "Temporary Opening - S". */
    public function temporaryAccount(string $company): string {
        $d = $this->request('GET', '/api/resource/Account', ['fields' => wp_json_encode(['name']), 'filters' => wp_json_encode([['company', '=', $company], ['account_type', '=', 'Temporary'], ['is_group', '=', 0]]), 'limit_page_length' => 1]);
        return (string) ($d['data'][0]['name'] ?? '');
    }

    /** Create and submit a Sales Invoice (0.9.5). Returns its name. */
    public function salesInvoice(array $doc): string {
        $d = $this->request('POST', '/api/resource/Sales Invoice', [], $doc);
        return (string) ($d['data']['name'] ?? '');
    }

    /** The company a warehouse belongs to ('' when unknown). */
    public function warehouseCompany(string $warehouse): string {
        $d = $this->request('GET', '/api/resource/Warehouse/' . rawurlencode($warehouse));
        return (string) ($d['data']['company'] ?? '');
    }

    /** @return array<string,true> item codes that already exist, out of the given ones */
    public function existingItems(array $codes): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $codes))), 100) as $chunk) {
            $d = $this->request('GET', '/api/resource/Item', ['fields' => wp_json_encode(['name']), 'filters' => wp_json_encode([['name', 'in', $chunk]]), 'limit_page_length' => 200]);
            foreach ((array) ($d['data'] ?? []) as $r) { if (isset($r['name'])) { $out[(string) $r['name']] = true; } }
        }
        return $out;
    }

    /** Create a stock Item (item code = website SKU). */
    public function createItem(string $code, string $name, float $rate, string $group = 'Products', string $uom = 'Nos'): void {
        $this->request('POST', '/api/resource/Item', [], ['item_code' => $code, 'item_name' => mb_substr($name !== '' ? $name : $code, 0, 140),
            'item_group' => $group, 'stock_uom' => $uom, 'is_stock_item' => 1, 'include_item_in_manufacturing' => 0, 'standard_rate' => $rate, 'valuation_rate' => $rate]);
    }
}
