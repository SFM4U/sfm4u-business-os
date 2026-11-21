<?php
namespace SFM4U\Wholesale;

use DomainException;

final class RuleCsv {
    private const COLUMNS = ['sku', 'currency', 'scale', 'stock_unit', 'buyer', 'minor', 'min', 'max', 'step', 'tiers', 'packs', 'pack_minor'];
    private const PRICE_COLUMNS = ['sku', 'price'];
    public const MAX_BYTES = 128000;
    private const MAX_ROWS = 100;

    public static function preview(string $content): array {
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            throw new DomainException(__('Upload a CSV of up to 128 KB.', 'sfm4u-wholesale'));
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($stream);
        try {
            $columns = fgetcsv($stream, 0, ',', '"', '');
            $priceList = $columns === self::PRICE_COLUMNS;
            if (!$priceList && $columns !== self::COLUMNS) {
                throw new DomainException(__('Use the sku,price header or the full wholesale export header.', 'sfm4u-wholesale'));
            }
            $rows = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw new DomainException(__('The CSV must contain at most 100 complete rules.', 'sfm4u-wholesale'));
                }
                $number = count($rows) + 2;
                try {
                    if (count($row) !== count($columns)) {
                        throw new DomainException(__('The row must have one value for each CSV column.', 'sfm4u-wholesale'));
                    }
                    $data = array_combine($columns, $row);
                    $sku = self::safeSku($data['sku']);
                    $id = (int) wc_get_product_id_by_sku($sku);
                    $product = Admin::product($id);
                    if ($product->get_sku('edit') !== $sku) {
                        throw new DomainException(__('Use the SKU exactly as it appears on the product.', 'sfm4u-wholesale'));
                    }
                    if (isset($rows[$id])) {
                        throw new DomainException(__('Duplicate SKU.', 'sfm4u-wholesale'));
                    }
                    $previous = $product->get_meta(Commerce::META, true, 'edit');
                    if ($priceList) {
                        $scale = Rule::number(wc_get_price_decimals(), 0, 6);
                        $price = self::priceMinor($data['price'], $scale);
                        $data = is_array($previous) ? $previous : ['buyer' => 'approved'];
                        $data += ['currency' => get_woocommerce_currency(), 'scale' => $scale];
                        $data['minor'] = $price;
                    } else {
                        $data['group_prices'] = $previous['group_prices'] ?? [];
                        foreach (['tiers', 'packs', 'pack_minor'] as $map) {
                            if (strlen($data[$map]) > 4096 || !str_starts_with(trim($data[$map]), '{')) {
                                throw new DomainException(__('Expected a bounded JSON object.', 'sfm4u-wholesale'));
                            }
                            $data[$map] = json_decode($data[$map], true, 5, JSON_THROW_ON_ERROR);
                        }
                    }
                    $rule = Rule::validate($data);
                    if ($rule['currency'] !== get_woocommerce_currency() || $rule['scale'] !== wc_get_price_decimals()) {
                        throw new DomainException(__('Currency or precision differs from this store.', 'sfm4u-wholesale'));
                    }
                    $rows[$id] = ['sku' => $sku, 'previous' => $previous, 'rule' => $rule];
                } catch (DomainException | \JsonException $error) {
                    $message = $error instanceof \JsonException ? __('Enter valid JSON for tiers and packs.', 'sfm4u-wholesale')
                        : __($error->getMessage(), 'sfm4u-wholesale');
                    throw new DomainException(sprintf(__('Row %d: %s', 'sfm4u-wholesale'), $number, $message), 0, $error);
                }
            }
            if (!$rows) {
                throw new DomainException(__('The CSV does not contain any wholesale rules.', 'sfm4u-wholesale'));
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private static function priceMinor(string $price, int $scale): int {
        $amount = explode('.', $price, 2);
        $fraction = $amount[1] ?? '';
        if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $price) || strlen($fraction) > $scale) {
            throw new DomainException(__('Enter a positive price in store currency using digits and a decimal point.', 'sfm4u-wholesale'));
        }
        $digits = ltrim($amount[0] . str_pad($fraction, $scale, '0'), '0');
        try {
            return Rule::number($digits, 1, 1000000000);
        } catch (DomainException $error) {
            throw new DomainException(__('The wholesale price is zero or exceeds the allowed limit.', 'sfm4u-wholesale'), 0, $error);
        }
    }

    public static function apply(array $preview): void {
        // Recheck all rows and object access before starting any write.
        $products = [];
        foreach ($preview as $id => $entry) {
            $product = Admin::product((int) $id);
            if ($product->get_sku('edit') !== $entry['sku']
                || $product->get_meta(Commerce::META, true, 'edit') !== $entry['previous']
                || $entry['rule']['currency'] !== get_woocommerce_currency()
                || $entry['rule']['scale'] !== wc_get_price_decimals()) {
                throw new DomainException(__('Rules, SKUs or currency changed after preview. Preview again.', 'sfm4u-wholesale'));
            }
            $products[$id] = $product;
        }
        wc_transaction_query('start');
        try {
            foreach ($products as $id => $product) {
                $expected = Commerce::saveRule($product, $preview[$id]['rule']);
                $key = \WC_Cache_Helper::get_cache_prefix('product_' . $id) . 'object_' . $id;
                wp_cache_delete($key, 'products');
                wp_cache_delete($id, 'post_meta');
                $actual = get_post_meta($id, Commerce::META, true);
                if ($actual !== $expected) {
                    throw new DomainException(__('A rule could not be saved.', 'sfm4u-wholesale'));
                }
            }
            wc_transaction_query('commit');
        } catch (\Throwable $error) {
            wc_transaction_query('rollback');
            throw $error;
        } finally {
            foreach (array_keys($products) as $id) {
                clean_post_cache($id);
                \WC_Cache_Helper::invalidate_cache_group('product_' . $id);
            }
        }
    }

    public static function export($stream, int $onlyProduct = 0): int {
        if (!current_user_can('manage_woocommerce')) {
            throw new DomainException(__('You cannot export wholesale rules.', 'sfm4u-wholesale'));
        }
        if ($onlyProduct) {
            $products = [Admin::product($onlyProduct)];
        } else {
            $ids = get_posts(['post_type' => ['product', 'product_variation'], 'post_status' => 'any',
                'numberposts' => self::MAX_ROWS + 1, 'fields' => 'ids',
                'meta_query' => [['key' => Commerce::META, 'compare' => 'EXISTS']]]);
            $products = array_filter(array_map('wc_get_product', $ids));
        }
        if (count($products) > self::MAX_ROWS) {
            throw new DomainException(__('Export supports at most 100 rules in this version.', 'sfm4u-wholesale'));
        }
        $rows = [];
        foreach ($products as $product) {
            $id = $product->get_id();
            if (!current_user_can('edit_post', $id)) {
                continue;
            }
            $stored = $product->get_meta(Commerce::META, true, 'edit');
            if (!is_array($stored)) {
                continue;
            }
            $rule = Rule::validate($stored);
            $rule['sku'] = self::safeSku($product->get_sku('edit'));
            $rows[] = array_map(static function ($field) use ($rule) {
                return is_array($rule[$field]) ? wp_json_encode((object) $rule[$field]) : $rule[$field];
            }, self::COLUMNS);
        }
        fputcsv($stream, self::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        return count($rows);
    }

    private static function safeSku(string $sku): string {
        if ($sku === '' || trim($sku) !== $sku || strlen($sku) > 100
            || preg_match('//u', $sku) !== 1 || preg_match('/^[=+@-]|[\x00-\x1f]/', $sku)) {
            throw new DomainException(__('Use a nonempty, spreadsheet-safe SKU.', 'sfm4u-wholesale'));
        }
        return $sku;
    }
}
