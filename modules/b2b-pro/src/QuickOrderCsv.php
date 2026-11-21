<?php
declare(strict_types=1);

namespace SFM4U\Wholesale\Pro;

/**
 * CSV / paste-box quick order: "SKU,qty[,unit]" per line where unit = pcs|pack.
 * Validates every row against the buyer's catalog rules and returns
 * accepted lines + row-level errors (error codes map to Bangla/English messages in the UI).
 * Nothing is added to a cart here; the adapter does that only for accepted lines.
 */
final class QuickOrderCsv
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 200_000;

    public function __construct(private CatalogPort $catalog) {}

    /**
     * @return array{lines: list<array{row:int, sku:string, qty_base:int, packs:?int, line_total_minor:int, currency:string}>,
     *               errors: list<array{row:int, code:string, sku:?string}>,
     *               total_minor:int, currency:?string}
     */
    public function parse(string $input, string $buyerId): array
    {
        if (strlen($input) > self::MAX_BYTES) {
            return ['lines' => [], 'errors' => [['row' => 0, 'code' => 'input_too_large', 'sku' => null]], 'total_minor' => 0, 'currency' => null];
        }
        $input = preg_replace('/^\xEF\xBB\xBF/', '', $input) ?? '';           // strip UTF-8 BOM (Excel)
        $rows = preg_split('/\r\n|\r|\n/', $input) ?: [];
        $lines = []; $errors = []; $pending = []; $currency = null; $total = 0; $n = 0;

        foreach ($rows as $i => $raw) {
            $rowNo = $i + 1;
            $raw = trim($raw);
            if ($raw === '' || str_starts_with($raw, '#')) { continue; }
            if (++$n > self::MAX_ROWS) { $errors[] = ['row' => $rowNo, 'code' => 'too_many_rows', 'sku' => null]; break; }

            $cols = array_map('trim', str_getcsv(str_replace(["\t", ';'], ',', $raw), ',', '"', ''));
            if ($n === 1 && isset($cols[0]) && strcasecmp($cols[0], 'sku') === 0) { continue; }   // header row
            if (count($cols) > 3) { $errors[] = ['row' => $rowNo, 'code' => 'columns_invalid', 'sku' => null]; continue; }
            $sku = strtoupper($cols[0] ?? '');
            $qtyRaw = $this->normaliseDigits($cols[1] ?? '');
            $unit = strtolower($cols[2] ?? 'pcs');

            if ($sku === '' || !preg_match('/^[A-Z0-9][A-Z0-9._\-]{0,63}$/', $sku)) { $errors[] = ['row' => $rowNo, 'code' => 'sku_invalid', 'sku' => $sku ?: null]; continue; }
            if (!preg_match('/^\d{1,7}$/', $qtyRaw) || (int) $qtyRaw <= 0) { $errors[] = ['row' => $rowNo, 'code' => 'qty_invalid', 'sku' => $sku]; continue; }
            if (!in_array($unit, ['pcs', 'pc', 'piece', 'pack', 'case', 'carton'], true)) { $errors[] = ['row' => $rowNo, 'code' => 'unit_invalid', 'sku' => $sku]; continue; }

            $p = $this->catalog->findForBuyer($sku, $buyerId);
            if ($p === null) { $errors[] = ['row' => $rowNo, 'code' => 'sku_not_found', 'sku' => $sku]; continue; }
            if (!$p['purchasable']) { $errors[] = ['row' => $rowNo, 'code' => 'not_purchasable', 'sku' => $sku]; continue; }

            $isPack = in_array($unit, ['pack', 'case', 'carton'], true);
            $pack = $isPack ? ($p['pack_aliases'][$unit] ?? $unit) : 'piece';
            $factor = $isPack ? ($p['pack_sizes'][$pack] ?? ($unit === 'pack' ? $p['pack_size'] : 0)) : 1;
            if ($factor < 1) { $errors[] = ['row' => $rowNo, 'code' => 'unit_invalid', 'sku' => $sku]; continue; }
            $pending[$sku]['product'] = $p;
            $pending[$sku]['rows'][] = ['row' => $rowNo, 'qty' => (int) $qtyRaw, 'factor' => $factor, 'pack' => $pack];
        }
        foreach ($pending as $sku => $group) {
            $sku = (string) $sku;
            $p = $group['product'];
            $qtyBase = 0;
            $counts = [];
            foreach ($group['rows'] as $row) {
                $qtyBase += $row['qty'] * $row['factor'];
                $counts[$row['pack']] = ($counts[$row['pack']] ?? 0) + $row['qty'];
            }
            $rowNo = $group['rows'][0]['row'];
            $code = match (true) {
                $currency !== null && $p['currency'] !== $currency => 'currency_mismatch',
                $qtyBase < $p['min_qty'] => 'below_minimum',
                ($qtyBase - $p['min_qty']) % $p['step'] !== 0 => 'step_mismatch',
                $p['max_qty'] !== null && $qtyBase > $p['max_qty'] => 'above_maximum',
                $qtyBase > $p['in_stock'] => 'insufficient_stock',
                default => null,
            };
            if ($code !== null) { $errors[] = ['row' => $rowNo, 'code' => $code, 'sku' => $sku]; continue; }
            $unitMinor = $p['unit_price_minor'];
            foreach ($p['tiers'] ?? [] as $threshold => $tierMinor) {
                if ($qtyBase >= $threshold) { $unitMinor = $tierMinor; }
            }
            try {
                $line = Money::of(0, $p['currency']);
                foreach ($counts as $pack => $count) {
                    $minor = $p['pack_prices'][$pack] ?? $unitMinor * ($p['pack_sizes'][$pack] ?? ($pack === 'piece' ? 1 : $p['pack_size']));
                    $line = $line->add(Money::of($minor, $p['currency'])->times($count));
                }
                $total = Money::of($total, $p['currency'])->add($line)->minor;
            } catch (\OverflowException) {
                $errors[] = ['row' => $rowNo, 'code' => 'money_overflow', 'sku' => $sku];
                continue;
            }
            $currency = $p['currency'];
            $lines[] = ['row' => $rowNo, 'sku' => $sku, 'qty_base' => $qtyBase,
                'packs' => array_diff_key($counts, ['piece' => true]) ? array_sum(array_diff_key($counts, ['piece' => true])) : null, 'pack_breakdown' => $counts,
                'line_total_minor' => $line->minor, 'currency' => $currency];
        }
        usort($errors, static fn(array $a, array $b): int => $a['row'] <=> $b['row']);
        return ['lines' => $lines, 'errors' => $errors, 'total_minor' => $total, 'currency' => $currency];
    }

    /** Accept Bangla digits (০-৯) typed by shop owners. */
    private function normaliseDigits(string $s): string
    {
        return strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}
