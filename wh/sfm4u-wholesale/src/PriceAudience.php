<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;

defined('ABSPATH') || exit;

/**
 * Price audiences (Owner design 2026-11-21, "SFM4U BUSINESS SOFTWARE — PRODUCT PLAN" §5).
 *
 * A product can have several price sets. Each set says:
 *  - show_to: who can SEE it (like Facebook's "who can see your post"): public, regular, premium, business, vendor, only_me
 *  - buy_for: who can BUY at it: everyone, regular (logged-in), premium, business (Business / Business Black card)
 *  - ladder: quantity => unit price (minor units), e.g. 1=120, 3=114, 5=109
 *  - promo (optional): buy N, get M pieces off ("7+1")
 * The cost price (what the shop paid) is private ("only_me") unless the shop chooses to show it.
 * Buying: the system gives each buyer the LOWEST unit price among the sets they may buy from; it never raises a price
 * set by the other wholesale rules. Promotions become a cart discount line.
 */
final class PriceAudience {
    public const META = '_sfm_price_sets';
    public const COST = '_sfm_cost';
    public const SHOW = ['public', 'regular', 'premium', 'business', 'vendor', 'only_me'];
    public const BUY = ['everyone' => 0, 'regular' => 1, 'premium' => 2, 'business' => 3];

    public static function register(): void {
        add_action('woocommerce_before_calculate_totals', [self::class, 'priceCart'], 110);
        add_action('woocommerce_cart_calculate_fees', [self::class, 'promoFees']);
        add_action('woocommerce_single_product_summary', [self::class, 'box'], 26);
        add_action('add_meta_boxes', [self::class, 'metaBox']);
        add_action('save_post_product', [self::class, 'saveMetaBox'], 10, 2);
    }

    /* ---------------- who is the buyer ---------------- */

    /** public (guest) < regular (logged in) < premium (active Premium card) < business (Business / Black card that qualifies). */
    public static function buyerType(int $uid): string {
        if ($uid <= 0) {
            return 'public';
        }
        $m = class_exists(MemberPrice::class) ? MemberPrice::memberFor($uid) : null;
        if ($m && MemberPrice::qualifies($m)) {
            return 'business';
        }
        return self::premium($uid) ? 'premium' : 'regular';
    }

    /** Active Premium card: this shop's own card, or the card of the linked SFM4U account (account.sfm4u.com). */
    public static function premium(int $uid): bool {
        if (!class_exists(Members\Members::class) || !Modules::on('membership')) {
            return false;
        }
        $usable = !class_exists(Members\Identity::class) || Members\Identity::usable($uid);
        $card = Members\Members::cardsOf($uid) ? Members\Members::bestCardFor($uid) : null;
        if ($card && $card['status'] === 'active' && $card['tier'] === 'premium' && $usable) {
            return true;
        }
        if (class_exists(Members\CardLink::class) && get_option(Members\CardLink::OPT_ROLE) === 'shop') {
            $l = Members\MemberLink::linked($uid);
            return ($l['tier'] ?? '') === 'premium' && ($l['status'] ?? '') === 'active';
        }
        return false;
    }

    public static function isVendor(int $uid): bool {
        if ($uid <= 0) {
            return false;
        }
        if (function_exists('dokan_is_user_seller') && dokan_is_user_seller($uid)) {
            return true;
        }
        return user_can($uid, 'manage_woocommerce');
    }

    /* ---------------- data ---------------- */

    /** Parse "1=120, 3=114.50" (Bangla digits ok) into [qty => minor]; quantity 1+, prices must not rise with quantity. */
    public static function parseLadder(string $text, int $decimals): array {
        $text = strtr(trim($text), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $out = [];
        foreach (preg_split('/[,;\n]+/', $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^([0-9]{1,7})\s*[=:]\s*(?:৳|tk\.?|bdt)?\s*([0-9]{1,9})(?:\.([0-9]{1,6}))?$/iu', $part, $m)) {
                throw new DomainException('Write each step as quantity=price, for example 1=120, 3=114.');
            }
            $frac = $m[3] ?? '';
            if (strlen($frac) > $decimals && rtrim(substr($frac, $decimals), '0') !== '') {
                throw new DomainException('Too many decimal places in a price.');
            }
            $minor = (int) $m[2] * (10 ** $decimals) + (int) str_pad(substr($frac, 0, $decimals), $decimals, '0');
            if ((int) $m[1] < 1 || $minor < 1) {
                throw new DomainException('Quantity must be 1 or more and price more than 0.');
            }
            $out[(int) $m[1]] = $minor;
        }
        ksort($out, SORT_NUMERIC);
        $last = null;
        foreach ($out as $minor) {
            if ($last !== null && $minor > $last) {
                throw new DomainException('A bigger quantity cannot have a higher price.');
            }
            $last = $minor;
        }
        if (!$out) {
            throw new DomainException('A price set needs at least one quantity=price step.');
        }
        return $out;
    }

    /** Validate and normalise sets. */
    public static function normalize(array $sets): array {
        if (count($sets) > 10) {
            throw new DomainException('At most 10 price sets per product.');
        }
        $out = [];
        foreach ($sets as $s) {
            if (!is_array($s)) {
                throw new DomainException('Invalid price set.');
            }
            $show = array_values(array_unique(array_filter((array) ($s['show_to'] ?? ['public']), 'is_string')));
            if (!$show || array_diff($show, self::SHOW)) {
                throw new DomainException('Invalid "who can see" choice.');
            }
            $buy = (string) ($s['buy_for'] ?? 'everyone');
            if (!isset(self::BUY[$buy])) {
                throw new DomainException('Invalid "who can buy" choice.');
            }
            $ladder = [];
            foreach ((array) ($s['ladder'] ?? []) as $q => $minor) {
                $q = (int) $q; $minor = (int) $minor;
                if ($q < 1 || $minor < 1) {
                    throw new DomainException('Invalid quantity step.');
                }
                $ladder[$q] = $minor;
            }
            if (!$ladder) {
                throw new DomainException('A price set needs at least one quantity=price step.');
            }
            ksort($ladder, SORT_NUMERIC);
            $promo = null;
            if (!empty($s['promo']) && is_array($s['promo'])) {
                $b = (int) ($s['promo']['buy'] ?? 0); $o = (int) ($s['promo']['off'] ?? 0);
                if ($b < 2 || $o < 1 || $o >= $b) {
                    throw new DomainException('Offer must be like 7+1 (buy 7, 1 piece off).');
                }
                $promo = ['buy' => $b, 'off' => $o];
            }
            $label = sanitize_text_field((string) ($s['label'] ?? ''));
            $out[] = ['label' => $label !== '' ? mb_substr($label, 0, 40) : ucfirst($buy), 'show_to' => $show, 'buy_for' => $buy, 'ladder' => $ladder] + ($promo ? ['promo' => $promo] : []);
        }
        return $out;
    }

    public static function sets(int $productId): array {
        $raw = get_post_meta($productId, self::META, true);
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        try {
            return is_array($raw) ? self::normalize($raw) : [];
        } catch (DomainException $e) {
            return [];
        }
    }

    public static function saveSets(int $productId, array $sets, ?array $cost = null): void {
        $sets = self::normalize($sets);
        update_post_meta($productId, self::META, wp_slash(wp_json_encode($sets)));
        if ($cost !== null) {
            $minor = max(0, (int) ($cost['minor'] ?? 0));
            $show = array_values(array_intersect((array) ($cost['show_to'] ?? ['only_me']), self::SHOW)) ?: ['only_me'];
            if ($minor > 0) {
                update_post_meta($productId, self::COST, wp_slash(wp_json_encode(['minor' => $minor, 'show_to' => $show])));
            } else {
                delete_post_meta($productId, self::COST);
            }
        }
    }

    /* ---------------- rules ---------------- */

    public static function canBuy(array $set, string $buyer): bool {
        $rank = ['public' => 0, 'regular' => 1, 'premium' => 2, 'business' => 3][$buyer] ?? 0;
        return $rank >= self::BUY[$set['buy_for']];
    }

    /** Can this viewer SEE the set? The product's own shop and administrators always see everything. */
    public static function canSee(array $showTo, string $buyer, bool $vendor, bool $owner): bool {
        if ($owner) {
            return true;
        }
        if (in_array('public', $showTo, true)) {
            return true;
        }
        if ($vendor && in_array('vendor', $showTo, true)) {
            return true;
        }
        $seen = ['regular' => ['regular'], 'premium' => ['regular', 'premium'], 'business' => ['regular', 'premium', 'business']][$buyer] ?? [];
        return (bool) array_intersect($seen, $showTo);
    }

    public static function stepPrice(array $ladder, int $qty): ?int {
        $unit = null;
        foreach ($ladder as $from => $minor) {
            if ($qty >= $from) {
                $unit = $minor;
            }
        }
        return $unit;
    }

    /** Best unit price (minor) for this buyer and quantity, plus the set used; null if no set applies. */
    public static function best(array $sets, string $buyer, int $qty): ?array {
        $best = null;
        foreach ($sets as $i => $s) {
            if (!self::canBuy($s, $buyer)) {
                continue;
            }
            $u = self::stepPrice($s['ladder'], $qty);
            if ($u !== null && ($best === null || $u < $best['unit'])) {
                $best = ['unit' => $u, 'set' => $i];
            }
        }
        return $best;
    }

    /** Discount (minor) of a "buy N, get M off" offer for one line. */
    public static function promoDiscount(?array $promo, int $qty, int $unit): int {
        if (!$promo) {
            return 0;
        }
        return intdiv($qty, $promo['buy']) * $promo['off'] * $unit;
    }

    private static function owner(int $productId, int $uid): bool {
        return $uid > 0 && ((int) get_post_field('post_author', $productId) === $uid || user_can($uid, 'manage_woocommerce'));
    }

    /* ---------------- cart ---------------- */

    public static function priceCart(\WC_Cart $cart): void {
        $buyer = self::buyerType(get_current_user_id());
        $dec = wc_get_price_decimals();
        foreach ($cart->get_cart() as $key => $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $sets = $pid ? self::sets($pid) : [];
            if (!$sets) {
                continue;
            }
            $best = self::best($sets, $buyer, (int) $item['quantity']);
            if (!$best) {
                continue;
            }
            $current = (float) $item['data']->get_price('edit');
            $unit = $best['unit'] / (10 ** $dec);
            if ($current <= 0 || $unit < $current) {
                $item['data'] = clone $item['data'];
                $item['data']->set_price(wc_format_decimal($unit, $dec));
                $item['sfm_audience'] = $best['set'];
                $cart->cart_contents[$key] = $item;
            }
        }
    }

    public static function promoFees(\WC_Cart $cart): void {
        $buyer = self::buyerType(get_current_user_id());
        $dec = wc_get_price_decimals();
        $total = 0;
        foreach ($cart->get_cart() as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $sets = $pid ? self::sets($pid) : [];
            if (!$sets) {
                continue;
            }
            $best = self::best($sets, $buyer, (int) $item['quantity']);
            if ($best && !empty($sets[$best['set']]['promo'])) {
                $total += self::promoDiscount($sets[$best['set']]['promo'], (int) $item['quantity'], $best['unit']);
            }
        }
        if ($total > 0) {
            $cart->add_fee(determine_locale() === 'bn_BD' ? 'অফার ছাড়' : __('Offer discount', 'sfm4u-wholesale'), -1 * ($total / (10 ** $dec)), false);
        }
    }

    /* ---------------- product page ---------------- */

    private static function money(int $minor): string {
        return '৳' . number_format_i18n($minor / (10 ** wc_get_price_decimals()), wc_get_price_decimals());
    }

    public static function boxHtml(int $productId, int $uid): string {
        $sets = self::sets($productId);
        if (!$sets) {
            return '';
        }
        $bn = str_starts_with(determine_locale(), 'bn');
        $buyer = self::buyerType($uid);
        $vendor = self::isVendor($uid);
        $owner = self::owner($productId, $uid);
        $who = ['everyone' => $bn ? 'সবাই' : 'Everyone', 'regular' => $bn ? 'রেজিস্টার্ড কাস্টমার' : 'Registered customers',
            'premium' => $bn ? 'প্রিমিয়াম মেম্বার' : 'Premium members', 'business' => $bn ? 'বিজনেস কার্ড' : 'Business card members'];
        $h = '<div class="sfm-audience"><h4>' . esc_html($bn ? 'দাম ও পরিমাণ ছাড়' : 'Prices and quantity savings') . '</h4><ul>';
        $shown = 0;
        foreach ($sets as $s) {
            if (!self::canSee($s['show_to'], $buyer, $vendor, $owner)) {
                continue;
            }
            $shown++;
            $steps = [];
            foreach ($s['ladder'] as $q => $m) {
                $steps[] = sprintf($bn ? '%d পিস বা বেশি: %s প্রতি পিস' : '%d+ pcs: %s each', $q, self::money($m));
            }
            $h .= '<li><strong>' . esc_html($s['label']) . '</strong> <span class="sfm-aud-who">(' . esc_html($who[$s['buy_for']]) . ')</span><br>'
                . esc_html(implode(' · ', $steps));
            if (!empty($s['promo'])) {
                $h .= '<br>' . esc_html(sprintf($bn ? 'অফার: %d টা কিনলে %d টা ছাড়' : 'Offer: buy %d, %d off', $s['promo']['buy'], $s['promo']['off']));
            }
            if (!self::canBuy($s, $buyer)) {
                $h .= '<br><em>' . esc_html($s['buy_for'] === 'premium' ? ($bn ? 'প্রিমিয়াম মেম্বার হলে এই দাম পাবেন।' : 'Become a Premium member to get this price.')
                    : ($s['buy_for'] === 'business' ? ($bn ? 'বিজনেস কার্ড থাকলে এই দাম।' : 'Price for Business card members.') : ($bn ? 'লগইন করলে এই দাম।' : 'Log in to get this price.'))) . '</em>';
            }
            $h .= '</li>';
        }
        $cost = get_post_meta($productId, self::COST, true);
        $cost = is_string($cost) ? json_decode($cost, true) : null;
        if (is_array($cost) && !empty($cost['minor']) && self::canSee((array) ($cost['show_to'] ?? ['only_me']), $buyer, $vendor, $owner)) {
            $shown++;
            $h .= '<li><strong>' . esc_html($bn ? 'কেনা দাম' : 'Cost price') . '</strong>: ' . esc_html(self::money((int) $cost['minor']))
                . ($owner && in_array('only_me', (array) $cost['show_to'], true) ? ' <em>(' . esc_html($bn ? 'শুধু আপনি দেখছেন' : 'only you see this') . ')</em>' : '') . '</li>';
        }
        return $shown ? $h . '</ul></div>' : '';
    }

    public static function box(): void {
        global $product;
        if ($product instanceof \WC_Product) {
            echo self::boxHtml($product->get_id(), get_current_user_id()); // phpcs:ignore -- escaped inside
        }
    }

    /* ---------------- product edit (admin / shop) ---------------- */

    public static function metaBox(): void {
        add_meta_box('sfm-price-audience', __('SFM4U price sets (who sees, who buys)', 'sfm4u-wholesale'), [self::class, 'metaBoxHtml'], 'product', 'normal');
    }

    /** One set per line: label | who can see (comma) | who can buy | ladder | offer. */
    public static function toLines(array $sets): string {
        $dec = wc_get_price_decimals();
        $lines = [];
        foreach ($sets as $s) {
            $steps = [];
            foreach ($s['ladder'] as $q => $m) {
                $steps[] = $q . '=' . wc_format_decimal($m / (10 ** $dec), $dec);
            }
            $lines[] = implode(' | ', [$s['label'], implode(',', $s['show_to']), $s['buy_for'], implode(', ', $steps), !empty($s['promo']) ? $s['promo']['buy'] . '+' . $s['promo']['off'] : '']);
        }
        return implode("\n", $lines);
    }

    public static function fromLines(string $text): array {
        $dec = wc_get_price_decimals();
        $sets = [];
        foreach (preg_split('/\r?\n/', trim($text)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 4) {
                throw new DomainException('Each line: label | who can see | who can buy | 1=120, 3=114 | offer (optional, e.g. 7+1)');
            }
            $promo = null;
            if (($p[4] ?? '') !== '') {
                if (!preg_match('/^([0-9]+)\s*\+\s*([0-9]+)$/', $p[4], $m)) {
                    throw new DomainException('Offer must be like 7+1.');
                }
                $promo = ['buy' => (int) $m[1], 'off' => (int) $m[2]];
            }
            $sets[] = ['label' => $p[0], 'show_to' => array_filter(array_map('trim', explode(',', $p[1]))), 'buy_for' => $p[2],
                'ladder' => self::parseLadder($p[3], $dec)] + ($promo ? ['promo' => $promo] : []);
        }
        return self::normalize($sets);
    }

    public static function metaBoxHtml(\WP_Post $post): void {
        $cost = get_post_meta($post->ID, self::COST, true);
        $cost = is_string($cost) ? json_decode($cost, true) : null;
        $dec = wc_get_price_decimals();
        wp_nonce_field('sfm_price_audience', 'sfm_price_audience_nonce');
        echo '<p>' . esc_html__('One line per price set: label | who can see | who can buy | quantity=price steps | offer', 'sfm4u-wholesale') . '<br><code>Retail | public | everyone | 1=120, 3=114, 5=109 |</code><br><code>Premium | public | premium | 1=115, 5=105 | 7+1</code><br><code>Wholesale | business,vendor | business | 1=100, 12=95 |</code><br>'
            . esc_html__('Who can see: public, regular, premium, business, vendor, only_me. Who can buy: everyone, regular, premium, business.', 'sfm4u-wholesale') . '</p>'
            . '<textarea name="sfm_price_sets" rows="5" style="width:100%">' . esc_textarea(self::toLines(self::sets($post->ID))) . '</textarea>'
            . '<p><label>' . esc_html__('Cost price per piece (private)', 'sfm4u-wholesale') . ' <input name="sfm_cost" type="text" inputmode="decimal" value="' . esc_attr(is_array($cost) ? wc_format_decimal($cost['minor'] / (10 ** $dec), $dec) : '') . '"></label> '
            . '<label>' . esc_html__('Who can see the cost', 'sfm4u-wholesale') . ' <input name="sfm_cost_show" value="' . esc_attr(is_array($cost) ? implode(',', (array) $cost['show_to']) : 'only_me') . '"></label></p>';
    }

    public static function saveMetaBox(int $postId, \WP_Post $post): void {
        if (!isset($_POST['sfm_price_audience_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sfm_price_audience_nonce'])), 'sfm_price_audience')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $postId)) {
            return;
        }
        try {
            $sets = self::fromLines(sanitize_textarea_field(wp_unslash((string) ($_POST['sfm_price_sets'] ?? ''))));
            $dec = wc_get_price_decimals();
            $costText = trim(sanitize_text_field(wp_unslash((string) ($_POST['sfm_cost'] ?? ''))));
            $costMinor = $costText === '' ? 0 : (int) round((float) $costText * (10 ** $dec));
            $show = array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash((string) ($_POST['sfm_cost_show'] ?? 'only_me'))))));
            if ($sets) {
                self::saveSets($postId, $sets, ['minor' => $costMinor, 'show_to' => $show]);
            } else {
                delete_post_meta($postId, self::META);
                self::saveSets($postId, [], ['minor' => $costMinor, 'show_to' => $show]);
            }
        } catch (DomainException $e) {
            set_transient('sfm_price_audience_error_' . get_current_user_id(), $e->getMessage(), 60);
        }
    }
}
