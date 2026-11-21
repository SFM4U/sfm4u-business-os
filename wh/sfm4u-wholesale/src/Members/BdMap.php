<?php
declare(strict_types=1);
namespace SFM4U\Wholesale\Members;

defined('ABSPATH') || exit;

/**
 * Small Bangladesh map, flag and home-district shape for the member card.
 * The member's district comes from the manager's choice (user meta sfm_wr_district),
 * otherwise it is read from the home address, otherwise from the shop address.
 */
final class BdMap {
    public const META = 'sfm_wr_district';
    private const GREEN = '#006a4e';
    private const RED = '#f42a41';

    /** District keys => English label, for the manager's select box. */
    public static function choices(): array {
        $out = [];
        foreach (BdMapData::DISTRICTS as $k => $d) { $out[$k] = $d['en'] . ' — ' . $d['bn']; }
        uasort($out, 'strcasecmp');
        return $out;
    }

    public static function valid(string $key): bool {
        return isset(BdMapData::DISTRICTS[$key]);
    }

    public static function info(string $key): ?array {
        return BdMapData::DISTRICTS[$key] ?? null;
    }

    /** Lower-case, one form of য়/ড়/ঢ়, no apostrophes or extra spaces. */
    public static function normalize(string $s): string {
        $s = str_replace(["\u{09AF}\u{09BC}", "\u{09A1}\u{09BC}", "\u{09A2}\u{09BC}"], ["\u{09DF}", "\u{09DC}", "\u{09DD}"], $s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = str_replace(["'", '’', '`'], '', $s);
        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /**
     * Find the district named in a free-text address. When several names appear,
     * names followed by "division"/"বিভাগ" are ignored and the last remaining one wins
     * (addresses usually end with the district).
     */
    public static function detect(string $address): ?string {
        $text = self::normalize($address);
        if (trim($text) === '') { return null; }
        $hits = [];
        foreach (BdMapData::DISTRICTS as $key => $d) {
            $names = array_merge([$key, $d['en'], $d['bn']], $d['alt']);
            foreach ($names as $name) {
                $n = self::normalize($name);
                $offset = 0;
                while (($pos = mb_strpos($text, $n, $offset, 'UTF-8')) !== false) {
                    $offset = $pos + 1;
                    $before = $pos > 0 ? mb_substr($text, $pos - 1, 1, 'UTF-8') : ' ';
                    $after = mb_substr($text, $pos + mb_strlen($n, 'UTF-8'), 1, 'UTF-8');
                    // Whole word only (Latin): "Feni" must not match inside "Fenix".
                    if (preg_match('/[a-z]/', $before) || preg_match('/[a-z]/', $after)) { continue; }
                    $rest = ltrim(mb_substr($text, $pos + mb_strlen($n, 'UTF-8'), 12, 'UTF-8'), " ,");
                    if (preg_match('/^(division|বিভাগ)/u', $rest)) { continue; }
                    $len = mb_strlen($n, 'UTF-8');
                    // Prefer the longer name at the same place ("Chapainawabganj" over "Nawabganj").
                    $end = $pos + $len;
                    if (!isset($hits[$end]) || $hits[$end][1] < $len) { $hits[$end] = [$key, $len, $pos]; }
                }
            }
        }
        if (!$hits) { return null; }
        // Drop hits that sit inside a longer hit.
        foreach ($hits as $end => $h) {
            foreach ($hits as $end2 => $h2) {
                if ($h2 !== $h && $h2[2] <= $h[2] && $end2 >= $end && $h2[1] > $h[1]) { unset($hits[$end]); break; }
            }
        }
        ksort($hits);
        $last = end($hits);
        return $last ? $last[0] : null;
    }

    /** The member's district key, or null when it cannot be told. */
    public static function districtFor(int $userId): ?string {
        $set = (string) get_user_meta($userId, self::META, true);
        if (self::valid($set)) { return $set; }
        foreach (['sfm_wr_address', 'sfm_wr_shop_address', 'billing_city', 'billing_address_1'] as $meta) {
            $found = self::detect((string) get_user_meta($userId, $meta, true));
            if ($found) { return $found; }
        }
        return null;
    }

    /**
     * Whole country as thin outlines on the dark card (no fill), the member's district in green,
     * and the member's photo shown faintly inside the country shape (like a ghost portrait on a licence).
     */
    public static function countrySvg(?string $key, string $class, string $photoUrl = '', bool $light = false): string {
        static $n = 0;
        $n++;
        $v = BdMapData::VIEW;
        $all = '';
        foreach (BdMapData::DISTRICTS as $d) { $all .= $d['d']; }
        $clip = 'sfmbd' . $n;
        $ghost = $photoUrl !== ''
            ? '<defs><clipPath id="' . $clip . '"><path d="' . esc_attr($all) . '"/></clipPath></defs>'
              . '<image href="' . esc_url($photoUrl) . '" x="' . (int) $v[0] . '" y="' . (int) $v[1] . '" width="' . (int) $v[2] . '" height="' . (int) $v[3] . '" preserveAspectRatio="xMidYMid slice" clip-path="url(#' . $clip . ')" opacity="' . ($light ? '.55' : '.4') . '" style="filter:grayscale(1) brightness(.85) contrast(1.2)"/>'
            : '';
        $hl = $key && self::valid($key) ? '<path d="' . esc_attr(BdMapData::DISTRICTS[$key]['d']) . '" fill="rgba(0,170,100,.45)" stroke="' . ($light ? '#00804f' : '#1ee08f') . '" stroke-width="4" stroke-linejoin="round"/>' : '';
        return '<svg class="' . esc_attr($class) . '" viewBox="' . ((int) $v[0] - 4) . ' ' . ((int) $v[1] - 4) . ' ' . ((int) $v[2] + 8) . ' ' . ((int) $v[3] + 8) . '" aria-hidden="true">'
            . $ghost
            . '<path d="' . esc_attr($all) . '" fill="none" stroke="' . ($light ? 'rgba(11,15,14,.55)' : 'rgba(230,240,236,.5)') . '" stroke-width="1.2" stroke-linejoin="round"/>' . $hl . '</svg>';
    }

    /** One district as a thin outline only (no fill), with its name written across the middle. */
    public static function districtOutlineSvg(string $key, string $class, string $label): string {
        $d = self::info($key);
        if (!$d) { return ''; }
        [$x, $y, $w, $h] = $d['box'];
        $pad = (int) round(max($w, $h) * 0.06) + 2;
        $vw = $w + 2 * $pad;
        $fs = max(6, (int) round(min($vw * 0.9 / (max(4, mb_strlen($label)) * 0.66), $h * 0.11)));
        return '<svg class="' . esc_attr($class) . '" viewBox="' . ($x - $pad) . ' ' . ($y - $pad) . ' ' . $vw . ' ' . ($h + 2 * $pad) . '" aria-hidden="true">'
            . '<path d="' . esc_attr($d['d']) . '" fill="none" stroke="rgba(230,240,236,.75)" stroke-width="' . max(0.8, round(max($w, $h) / 160, 1)) . '" stroke-linejoin="round"/>'
            . ($label === '' ? '' : '<text x="' . ($x + $w / 2) . '" y="' . ((int) $d['c'][1]) . '" text-anchor="middle" dominant-baseline="middle" font-family="Space Grotesk,Arial,sans-serif" font-weight="700" letter-spacing="' . round($fs * .08, 1) . '" font-size="' . $fs . '" fill="#22e3c3" transform="rotate(-18 ' . ($x + $w / 2) . ' ' . ((int) $d['c'][1]) . ')">' . esc_html($label) . '</text>') . '</svg>';
    }

    /** Bangladesh flag (10:6, red disc slightly toward the hoist). */
    public static function flagSvg(string $class): string {
        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 100 60" aria-hidden="true"><rect width="100" height="60" fill="' . self::GREEN . '"/><circle cx="45" cy="30" r="20" fill="' . self::RED . '"/></svg>';
    }

    /** One district shape in flag green with a red disc in its middle. */
    public static function districtSvg(string $key, string $class): string {
        $d = self::info($key);
        if (!$d) { return ''; }
        [$x, $y, $w, $h] = $d['box'];
        $pad = (int) round(max($w, $h) * 0.06) + 2;
        $r = (int) max(4, round(min($w, $h) * 0.2));
        return '<svg class="' . esc_attr($class) . '" viewBox="' . ($x - $pad) . ' ' . ($y - $pad) . ' ' . ($w + 2 * $pad) . ' ' . ($h + 2 * $pad) . '" aria-hidden="true">'
            . '<path d="' . esc_attr($d['d']) . '" fill="' . self::GREEN . '" stroke="#003d2c" stroke-width="' . max(1, round(max($w, $h) / 120, 1)) . '" stroke-linejoin="round"/>'
            . '<circle cx="' . (int) $d['c'][0] . '" cy="' . (int) $d['c'][1] . '" r="' . $r . '" fill="' . self::RED . '"/></svg>';
    }
}
