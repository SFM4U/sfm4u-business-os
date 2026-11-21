<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

final class Code128
{
    // Standard run lengths in symbol order. Even positions are black bars.
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public static function svg(string $sku, int $maxWidthMm): string
    {
        if (!preg_match('/^[\x20-\x7e]{1,60}$/D', $sku)) {
            throw new \DomainException('Unsupported SKU.');
        }
        if (strlen($sku) % 2 === 0 && preg_match('/^[0-9]+$/D', $sku)) {
            $symbols = array_merge([105], array_map('intval', str_split($sku, 2)));
        } else {
            $symbols = [104];
            foreach (str_split($sku) as $char) {
                $symbols[] = ord($char) - 32;
            }
        }

        $checksum = $symbols[0];
        for ($i = 1; $i < count($symbols); $i++) {
            $checksum += $symbols[$i] * $i;
        }
        $symbols[] = $checksum % 103;
        $symbols[] = 106;

        // Leave ten modules of quiet space on both sides and keep modules at
        // least 0.25 mm wide (approximately two dots at 203 dpi).
        $width = 11 * (count($symbols) - 1) + 13 + 20;
        if ($width > 4 * $maxWidthMm) {
            throw new \DomainException('SKU exceeds the printable barcode width.');
        }
        $physical = min($width * 0.33, $maxWidthMm);
        $svg = '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="' . number_format($physical, 2, '.', '')
            . 'mm" height="13mm" viewBox="0 0 ' . $width . ' 32" preserveAspectRatio="none">';
        $x = 10;
        foreach ($symbols as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $position => $run) {
                if ($position % 2 === 0) {
                    $svg .= '<rect x="' . $x . '" width="' . $run . '" height="32"/>';
                }
                $x += (int) $run;
            }
        }
        return $svg . '</svg>';
    }
}
