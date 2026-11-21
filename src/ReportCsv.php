<?php
declare(strict_types=1);

namespace SFM4U\Wholesale;

final class ReportCsv
{
    public static function text(string $value): string
    {
        // Prevent leading controls and formula prefixes from becoming executable cells.
        return preg_match('/^[\r\n\t]|^\s*[=+\-@＝＋－＠]/u', $value) ? "'" . $value : $value;
    }

    public static function write($stream, array $columns, iterable $rows): void
    {
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
    }

    public static function download(string $action, string $filename, array $columns, callable $rows): never
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('', '', ['response' => 403]);
        }
        check_admin_referer($action);
        try {
            $body = $rows();
        } catch (\DomainException | \RuntimeException | \JsonException) {
            wp_die(esc_html__('The report could not be exported.', 'sfm4u-wholesale'), '', ['response' => 400]);
        }
        nocache_headers();
        header('Cache-Control: private, no-store');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        self::write(fopen('php://output', 'wb'), $columns, $body);
        exit;
    }
}
