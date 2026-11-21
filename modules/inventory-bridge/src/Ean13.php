<?php
declare(strict_types=1);
namespace SFM4U\Inventory;

use DomainException;

/** Original SVG encoder for the standard EAN-13 symbol; no barcode library bundled. */
final class Ean13
{
    private const LEFT = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];
    private const PARITY = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];

    public static function validate(string $code): string
    {
        if (!preg_match('/^[0-9]{13}$/D', $code)) throw new DomainException('Enter a complete 13-digit EAN barcode.', 422);
        $sum = 0;
        for ($i=0; $i<12; $i++) $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        if ((10 - $sum % 10) % 10 !== (int) $code[12]) throw new DomainException('EAN barcode check digit is invalid.', 422);
        return $code;
    }

    public static function bits(string $code): string
    {
        self::validate($code);
        $bits = '101'; $parity = self::PARITY[(int) $code[0]];
        for ($i=1; $i<=6; $i++) {
            $pattern = self::LEFT[(int) $code[$i]];
            $bits .= $parity[$i-1] === 'L' ? $pattern : strrev(strtr($pattern, '01', '10'));
        }
        $bits .= '01010';
        for ($i=7; $i<=12; $i++) $bits .= strtr(self::LEFT[(int) $code[$i]], '01', '10');
        return $bits . '101';
    }

    public static function svg(string $code): string
    {
        $bits = self::bits($code);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="EAN-13 ' . $code
            . '" viewBox="0 0 117 80" style="width:38.61mm;height:26.4mm;display:block" shape-rendering="crispEdges">'
            . '<title>EAN-13 ' . $code . '</title><rect width="117" height="80" fill="white"/>';
        for ($i=0; $i<95; $i++) {
            if ($bits[$i] !== '1') continue;
            $guard = $i<3 || ($i>=45 && $i<=49) || $i>=92;
            $svg .= '<rect x="' . (11+$i) . '" y="1" width="1" height="' . ($guard ? 63 : 58) . '" fill="black"/>';
        }
        return $svg . '<text x="58.5" y="76" text-anchor="middle" font-family="monospace" font-size="10" fill="black">' . $code . '</text></svg>';
    }
}
