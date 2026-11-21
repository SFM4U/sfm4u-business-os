<?php
namespace SFM4U\Wholesale\Members;

/** Code 128 (set B) as inline SVG. Any USB / Bluetooth barcode scanner reads it. */
final class Barcode {
    private const P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];

    /** Bar/space widths for the text, including start B, checksum and stop. Pure; unit tested. */
    public static function modules(string $text): array {
        $codes = [104];
        foreach (str_split($text) as $ch) {
            $o = ord($ch);
            if ($o < 32 || $o > 126) { throw new \InvalidArgumentException('Code128-B supports printable ASCII only.'); }
            $codes[] = $o - 32;
        }
        $sum = $codes[0];
        foreach (array_slice($codes, 1) as $i => $c) { $sum += $c * ($i + 1); }
        $codes[] = $sum % 103;
        $codes[] = 106;
        $out = [];
        foreach ($codes as $c) { foreach (str_split(self::P[$c]) as $w) { $out[] = (int) $w; } }
        return $out;
    }

    public static function code128Svg(string $text): string {
        $x = 10;
        $rects = '';
        foreach (self::modules($text) as $i => $w) {
            if ($i % 2 === 0) { $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="60"/>'; }
            $x += $w;
        }
        return '<svg viewBox="0 0 ' . ($x + 10) . ' 60" preserveAspectRatio="none" role="img" aria-label="' . esc_attr($text) . '" xmlns="http://www.w3.org/2000/svg"><g fill="#0b0f0e">' . $rects . '</g></svg>';
    }
}
