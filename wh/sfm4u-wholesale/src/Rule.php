<?php
namespace SFM4U\Wholesale;

use DomainException;

require_once __DIR__ . '/Units.php';

/** All money is in store minor units and all quantities are integer stock units. */
final class Rule {
    public const GROUPS = ['bronze', 'silver', 'gold'];
    public static function number($value, int $min, int $max): int {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,10}$/D', (string) $value)
            || (int) $value < $min || (int) $value > $max) {
            throw new DomainException('Invalid numeric rule value.');
        }
        return (int) $value;
    }

    public static function validate(array $data): array {
        $min = self::number($data['min'] ?? 1, 1, 1000000);
        $max = self::number($data['max'] ?? 1000000, $min, 1000000);
        $step = self::number($data['step'] ?? 1, 1, 1000000);
        $price = self::number($data['minor'] ?? null, 1, 1000000000);
        $buyer = $data['buyer'] ?? 'logged_in';
        if (!is_string($buyer) || !preg_match('/^(everyone|logged_in|approved|(?:role|approved|tier):[a-z0-9_-]{1,64})$/D', $buyer)) {
            throw new DomainException('Invalid buyer group.');
        }
        if (!empty($data['public']) && $buyer !== 'everyone') {
            throw new DomainException('A public price table must be open to everyone.');
        }
        if (!empty($data['public']) && $min < 2) {
            throw new DomainException('A public wholesale price needs a minimum of 2 or more; 1 piece is the retail price.');
        }
        $tiers = $data['tiers'] ?? [];
        $packs = $data['packs'] ?? [];
        if (!is_array($tiers) || !is_array($packs) || count($tiers) > 30 || count($packs) > 30) {
            throw new DomainException('Too many packs or tiers.');
        }
        $normalizedTiers = [];
        foreach ($tiers as $from => $minor) {
            $normalizedTiers[self::number($from, $min, $max)] = self::number($minor, 1, 1000000000);
        }
        ksort($normalizedTiers, SORT_NUMERIC);
        $normalizedPacks = ['piece' => 1];
        foreach ($packs as $name => $factor) {
            if (!is_string($name) || $name === 'piece' || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $name)) {
                throw new DomainException('Invalid pack name.');
            }
            $normalizedPacks[$name] = self::number($factor, 1, 1000000);
        }
        $packPrices = $data['pack_minor'] ?? [];
        if (!is_array($packPrices) || array_diff_key($packPrices, array_diff_key($normalizedPacks, ['piece' => true]))) {
            throw new DomainException('Pack price requires a configured pack.');
        }
        foreach ($packPrices as $name => $packPrice) {
            $packPrices[$name] = self::number($packPrice, 1, 1000000000);
        }
        $groupPrices = $data['group_prices'] ?? [];
        if (!is_array($groupPrices) || array_diff(array_keys($groupPrices), self::GROUPS)) {
            throw new DomainException('Invalid buyer group prices.');
        }
        foreach ($groupPrices as $group => $value) {
            $groupPrices[$group] = self::number($value, 1, 1000000000);
        }
        // Vendor floor (cost guard, plan section 4 step 4): no wholesale, tier or group price may go below it.
        $floor = isset($data['floor_minor']) && $data['floor_minor'] !== '' ? self::number($data['floor_minor'], 1, 1000000000) : null;
        if ($floor !== null) {
            foreach (array_merge([$price], array_values($normalizedTiers), array_values($groupPrices)) as $unit) {
                if ($unit < $floor) {
                    throw new DomainException('A price is below the vendor floor price.');
                }
            }
            foreach ($packPrices as $name => $packPrice) {
                if ($packPrice < $floor * $normalizedPacks[$name]) {
                    throw new DomainException('A pack price is below the vendor floor price.');
                }
            }
        }
        $currency = $data['currency'] ?? '';
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new DomainException('Invalid currency.');
        }
        return ['schema' => 1, 'currency' => $currency, 'stock_unit' => Units::base($data['stock_unit'] ?? 'piece'), 'scale' => self::number($data['scale'] ?? 2, 0, 6),
            'revision' => self::number($data['revision'] ?? 1, 1, 1000000000), 'buyer' => $buyer,
            'minor' => $price, 'min' => $min, 'max' => $max, 'step' => $step,
            'tiers' => $normalizedTiers, 'pack_minor' => $packPrices, 'packs' => array_diff_key($normalizedPacks, ['piece' => true])]
            + ($groupPrices ? ['group_prices' => $groupPrices] : [])
            + (!empty($data['public']) ? ['public' => true] : [])
            + ($floor !== null ? ['floor_minor' => $floor] : []);
    }

    public static function factor(array $rule, string $pack): int {
        if ($pack === 'piece') {
            return 1;
        }
        if (!isset($rule['packs'][$pack])) {
            throw new DomainException('The selected pack is unavailable.');
        }
        return $rule['packs'][$pack];
    }

    /**
     * Wholesale quote, or null when a PUBLIC rule is below its wholesale quantity (then the normal
     * retail price applies; one piece can always be bought). Non-public rules keep the strict minimum.
     */
    public static function quote(array $rule, int $quantity, string $pack): ?array {
        $factor = self::factor($rule, $pack);
        if (!empty($rule['public']) && $pack === 'piece' && $quantity < $rule['min']) {
            return null;
        }
        if ($quantity < $rule['min'] || $quantity > $rule['max']
            || ($quantity - $rule['min']) % $rule['step'] || $quantity % $factor) {
            throw new DomainException('Quantity must meet the wholesale minimum, maximum, step and pack size.');
        }
        $minor = $rule['minor'];
        foreach ($rule['tiers'] as $from => $tierPrice) {
            if ($quantity >= $from) {
                $minor = $tierPrice;
            }
        }
        $packMinor = $rule['pack_minor'][$pack] ?? $minor * $factor;
        return ['schema' => 1, 'revision' => $rule['revision'], 'currency' => $rule['currency'],
            'scale' => $rule['scale'], 'stock_unit' => $rule['stock_unit'], 'pack' => $pack, 'pack_factor' => $factor,
            'pack_count' => intdiv($quantity, $factor), 'stock_quantity' => $quantity,
            'pack_minor' => $packMinor, 'line_minor' => $packMinor * intdiv($quantity, $factor)];
    }

    /**
     * Member quote (SFM4U Business / Business Black card): wholesale at ANY quantity.
     * Plan section 4 step 1: unit = the member's group price if given, else the lowest price in the
     * rule (base or any tier); a quantity tier still applies when it is lower than the group price.
     * Min/step do not apply; pack size and max do. Floor is enforced by validate().
     */
    public static function memberQuote(array $rule, int $quantity, string $pack, ?int $groupMinor = null, string $mode = 'lowest'): array {
        $factor = self::factor($rule, $pack);
        if ($quantity < 1 || $quantity > $rule['max'] || $quantity % $factor) {
            throw new DomainException('Quantity must fit the pack size.');
        }
        // mode 'lowest': member pays the lowest price in the rule at any quantity.
        // mode 'base': member pays the base wholesale (or own group) price; bigger tiers still apply by quantity.
        $minor = $groupMinor ?? ($mode === 'base' ? $rule['minor'] : min(array_merge([$rule['minor']], array_values($rule['tiers']))));
        if (isset($rule['floor_minor']) && $minor < $rule['floor_minor']) {
            $minor = $rule['floor_minor'];
        }
        foreach ($rule['tiers'] as $from => $tierPrice) {
            if ($quantity >= $from && $tierPrice < $minor) {
                $minor = $tierPrice;
            }
        }
        $packMinor = isset($rule['pack_minor'][$pack]) ? min($rule['pack_minor'][$pack], $minor * $factor) : $minor * $factor;
        return ['schema' => 1, 'revision' => $rule['revision'], 'currency' => $rule['currency'],
            'scale' => $rule['scale'], 'stock_unit' => $rule['stock_unit'], 'pack' => $pack, 'pack_factor' => $factor,
            'pack_count' => intdiv($quantity, $factor), 'stock_quantity' => $quantity,
            'pack_minor' => $packMinor, 'line_minor' => $packMinor * intdiv($quantity, $factor), 'member' => true];
    }

    /**
     * Shared tier text (same format as AlgiBazar Product Experience): "12=95, 50=90" or one per line.
     * Returns [qty => minor]. Bangla digits allowed. Throws on bad text.
     */
    public static function parseTierText(string $text, int $decimals): array {
        $text = strtr(trim($text), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5',
            '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $out = [];
        if ($text === '') {
            return $out;
        }
        foreach (preg_split('/[,;\n]+/', $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^([0-9]{1,7})\s*[=:+]\s*(?:৳|tk\.?|bdt)?\s*([0-9]{1,9})(?:\.([0-9]{1,6}))?$/iu', $part, $m)) {
                throw new DomainException('Write each step as quantity=price, for example 12=95.');
            }
            $frac = $m[3] ?? '';
            if (strlen($frac) > $decimals && rtrim(substr($frac, $decimals), '0') !== '') {
                throw new DomainException('Too many decimal places in a price.');
            }
            $minor = (int) $m[2] * (10 ** $decimals) + (int) str_pad(substr($frac, 0, $decimals), $decimals, '0');
            $qty = (int) $m[1];
            if ($qty < 2 || $minor < 1) {
                throw new DomainException('Quantity must be 2 or more and price more than 0.');
            }
            $out[$qty] = $minor;
        }
        ksort($out, SORT_NUMERIC);
        $last = null;
        foreach ($out as $minor) {
            if ($last !== null && $minor >= $last) {
                throw new DomainException('A bigger quantity must have a lower price.');
            }
            $last = $minor;
        }
        return $out;
    }

    public static function tierText(array $tiers, int $scale): string {
        $parts = [];
        foreach ($tiers as $q => $minor) {
            $d = self::decimal((int) $minor, $scale);
            $parts[] = (int) $q . '=' . (str_contains($d, '.') ? rtrim(rtrim($d, '0'), '.') : $d);
        }
        return implode(', ', $parts);
    }

    public static function unitPrice(array $quote): string {
        $factor = $quote['pack_factor'];
        $minor = $quote['pack_minor'];
        // Long division avoids rounding the per-unit equivalent to a currency minor unit.
        $result = self::decimal(intdiv($minor, $factor), $quote['scale']);
        $result .= $quote['scale'] ? '' : '.';
        $remainder = $minor % $factor;
        for ($i = 0; $i < 12; ++$i) {
            $remainder *= 10;
            $result .= (string) intdiv($remainder, $factor);
            $remainder %= $factor;
        }
        return $result;
    }

    public static function decimal(int $minor, int $scale): string {
        if (!$scale) {
            return (string) $minor;
        }
        $digits = str_pad((string) $minor, $scale + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }
}
