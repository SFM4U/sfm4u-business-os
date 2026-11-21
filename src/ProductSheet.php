<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;

defined('ABSPATH') || exit;

/**
 * SFM4U product sheet (Owner 2026-11-22): ONE page to add or edit a product the way a Bangladeshi shop thinks —
 * name and photos; case/pack, pieces per case, how many cases bought, buying price (কেনা দাম) per case; trip costs
 * (fare, truck, labour); selling price per piece; wholesale price (B2B) and who sees it; customer price steps (B2C)
 * per audience; special offer. Live maths beside it (cost per piece, profit per piece and %). One click Bangla / English.
 * Saving creates/updates the WooCommerce product (price, stock, photos), the price sets (Retail Prices) and a purchase
 * record with the real cost (Purchase & Cost).
 */
final class ProductSheet {
    public const PACK = '_sfm_pack';
    public const LANG = 'sfm_sheet_lang';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 19);
        add_action('admin_init', [self::class, 'handle']);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (str_contains($hook, 'sfm-product-sheet')) {
                wp_enqueue_media();
            }
        });
        add_action('post_submitbox_misc_actions', [self::class, 'editLink']);
    }

    public static function lang(int $uid = 0): string {
        $l = (string) get_user_meta($uid ?: get_current_user_id(), self::LANG, true);
        return $l === 'en' ? 'en' : 'bn';
    }

    /** Labels in both languages. */
    public static function t(string $key, string $lang): string {
        static $L = [
            'title' => ['Add product', 'পণ্য যোগ করুন'], 'edit' => ['Edit product', 'পণ্য সম্পাদনা'],
            'name' => ['Product name', 'পণ্যের নাম'], 'photos' => ['Product photos', 'পণ্যের ছবি'], 'add_photos' => ['Add / change photos', 'ছবি দিন / বদলান'],
            'buy' => ['Buying', 'কেনা'], 'pack_name' => ['Pack name (case, dozen, packet)', 'প্যাকের নাম (কেস, ডজন, প্যাকেট)'],
            'pieces' => ['Pieces in one pack', 'এক প্যাকে কত পিস'], 'packs' => ['How many packs bought (stock)', 'কত প্যাক কিনলেন (স্টক)'],
            'pack_cost' => ['Buying price of one pack (কেনা দাম)', 'এক প্যাকের কেনা দাম'], 'supplier' => ['Bought from', 'কোথা থেকে কিনলেন'],
            'costs' => ['Trip costs (optional)', 'আনার খরচ (ইচ্ছামতো)'], 'own_fare' => ['Own fare', 'নিজের ভাড়া'], 'staff_fare' => ['Manager / worker fare', 'ম্যানেজার/কর্মচারীর ভাড়া'],
            'truck' => ['Truck / transport', 'ট্রাক/পরিবহন ভাড়া'], 'labour' => ['Labour', 'লেবার খরচ'], 'paid_to' => ['Paid to', 'কাকে দিলেন'],
            'sell' => ['Selling', 'বিক্রি'], 'retail' => ['Selling price of one piece (retail)', 'এক পিসের বিক্রির দাম (খুচরা)'],
            'singles' => ['Single pieces are sold', 'এক পিস করে বিক্রি হয়'],
            'wholesale' => ['Wholesale price (B2B)', 'পাইকারি দাম (B2B)'], 'w_piece' => ['Wholesale price per piece', 'প্রতি পিস পাইকারি দাম'],
            'w_min' => ['Wholesale from how many pieces (Business card: any quantity)', 'কত পিস থেকে পাইকারি (বিজনেস কার্ডে যেকোনো পরিমাণ)'],
            'w_see' => ['Who can see the wholesale price', 'পাইকারি দাম কে দেখবে'],
            'steps' => ['Customer price steps (B2C)', 'কাস্টমারের দামের ধাপ (B2C)'], 'qty' => ['From quantity', 'কত পিস থেকে'], 'price' => ['Price per piece', 'প্রতি পিসের দাম'],
            'for' => ['For whom', 'কার জন্য'], 'see' => ['Who can see', 'কে দেখবে'],
            'offer' => ['Special offer', 'বিশেষ অফার'], 'offer_buy' => ['Buy', 'কিনলে'], 'offer_off' => ['pieces off', 'পিস ছাড়'],
            'calc' => ['Live calculation', 'সাথে সাথে হিসাব'], 'cost_piece' => ['Real cost per piece', 'প্রতি পিসের আসল খরচ'],
            'cost_pack' => ['Real cost per pack', 'প্রতি প্যাকের আসল খরচ'], 'profit' => ['Profit per piece', 'প্রতি পিসে লাভ'],
            'save' => ['Save product', 'পণ্য সেভ করুন'], 'draft' => ['Save as draft', 'খসড়া রাখুন'], 'saved' => ['Product saved.', 'পণ্য সেভ হয়েছে।'],
            'everyone' => ['Everyone', 'সবাই'], 'regular' => ['Registered customers', 'রেজিস্টার্ড কাস্টমার'], 'premium' => ['Premium members', 'প্রিমিয়াম মেম্বার'],
            'business' => ['Business card members', 'বিজনেস কার্ড মেম্বার'], 'public' => ['Public (all)', 'পাবলিক (সবাই)'], 'vendor' => ['Shops / vendors', 'দোকানদার'],
            'only_me' => ['Only me', 'শুধু আমি'], 'cost_see' => ['Who can see the buying price (staff never)', 'কেনা দাম কে দেখবে (স্টাফ কখনো না)'],
        ];
        return $L[$key][$lang === 'en' ? 0 : 1] ?? $key;
    }

    public static function menu(): void {
        add_submenu_page(Modules::SLUG, 'Add product / পণ্য যোগ করুন', 'Add product / পণ্য', 'edit_products', 'sfm-product-sheet', [self::class, 'page']);
    }

    public static function editLink(): void {
        global $post;
        if ($post && $post->post_type === 'product') {
            echo '<div class="misc-pub-section"><a class="button" href="' . esc_url(admin_url('admin.php?page=sfm-product-sheet&product=' . (int) $post->ID)) . '">SFM4U product sheet / পণ্যের শিট</a></div>';
        }
    }

    /* ---------------- money helpers ---------------- */

    public static function minor(string $text): int {
        $dec = wc_get_price_decimals();
        $text = strtr(trim($text), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9', ',' => '']);
        if ($text === '') {
            return 0;
        }
        if (!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,6})?$/', $text)) {
            throw new DomainException('Write money like 400 or 37.50 / টাকা লিখুন যেমন 400 বা 37.50');
        }
        [$w, $f] = array_pad(explode('.', $text), 2, '');
        return (int) $w * (10 ** $dec) + (int) str_pad(substr($f, 0, $dec), $dec, '0');
    }

    public static function num(string $text): int {
        return absint(strtr(trim($text), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
    }

    private static function dec(int $minor): string {
        $d = wc_get_price_decimals();
        return $minor > 0 ? wc_format_decimal($minor / (10 ** $d), $d) : '';
    }

    /* ---------------- save ---------------- */

    /** Turn the form into product data; throws DomainException with a clear message. */
    public static function fromPost(array $p): array {
        $name = sanitize_text_field((string) ($p['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('Write the product name / পণ্যের নাম লিখুন');
        }
        $pieces = max(1, self::num((string) ($p['pieces'] ?? '1')));
        $retail = self::minor((string) ($p['retail'] ?? ''));
        if ($retail < 1) {
            throw new DomainException('Write the selling price of one piece / এক পিসের বিক্রির দাম লিখুন');
        }
        $sets = [['label' => 'Retail', 'show_to' => ['public'], 'buy_for' => 'everyone', 'ladder' => [1 => $retail]]];
        $wp = self::minor((string) ($p['w_piece'] ?? ''));
        if ($wp > 0) {
            $wsee = array_values(array_intersect((array) ($p['w_see'] ?? ['business', 'vendor']), PriceAudience::SHOW)) ?: ['business', 'vendor'];
            $min = max(1, self::num((string) ($p['w_min'] ?? '1')));
            $sets[] = ['label' => 'Wholesale', 'show_to' => $wsee, 'buy_for' => 'business', 'ladder' => [1 => $wp]];
            if ($min > 1) {   // anyone who buys the wholesale quantity gets the wholesale price (business card: any quantity)
                $sets[] = ['label' => 'Wholesale qty', 'show_to' => $wsee, 'buy_for' => 'everyone', 'ladder' => [$min => $wp]];
            }
        }
        $offer = null;
        $ob = self::num((string) ($p['offer_buy'] ?? '')); $oo = self::num((string) ($p['offer_off'] ?? ''));
        if ($ob > 0 || $oo > 0) {
            $offer = ['buy' => $ob, 'off' => $oo];
        }
        $steps = [];
        foreach ((array) ($p['step_qty'] ?? []) as $i => $q) {
            $q = self::num((string) $q); $m = self::minor((string) ($p['step_price'][$i] ?? ''));
            if ($q < 1 || $m < 1) {
                continue;
            }
            $for = in_array($p['step_for'][$i] ?? '', ['everyone', 'regular', 'premium', 'business'], true) ? (string) $p['step_for'][$i] : 'everyone';
            $see = in_array($p['step_see'][$i] ?? '', PriceAudience::SHOW, true) ? (string) $p['step_see'][$i] : 'public';
            $steps[$for . '|' . $see][$q] = $m;
        }
        foreach ($steps as $k => $ladder) {
            [$for, $see] = explode('|', $k);
            ksort($ladder);
            $set = ['label' => ['everyone' => 'Customer price', 'regular' => 'Member price', 'premium' => 'Premium price', 'business' => 'Business price'][$for], 'show_to' => [$see], 'buy_for' => $for, 'ladder' => $ladder];
            if ($offer && ($p['offer_for'] ?? '') === $for) {
                $set['promo'] = $offer;
                $offer = null;
            }
            $sets[] = $set;
        }
        if ($offer) {   // offer without its own steps: attach to the audience chosen, at the retail price
            $for = in_array($p['offer_for'] ?? '', ['everyone', 'regular', 'premium', 'business'], true) ? (string) $p['offer_for'] : 'everyone';
            $sets[] = ['label' => 'Offer', 'show_to' => ['public'], 'buy_for' => $for, 'ladder' => [1 => $retail], 'promo' => $offer];
        }
        $sets = PriceAudience::normalize($sets);
        $packs = self::num((string) ($p['packs'] ?? ''));
        $packCost = self::minor((string) ($p['pack_cost'] ?? ''));
        $expenses = [];
        foreach (['own_fare', 'staff_fare', 'truck', 'labour'] as $type) {
            $m = self::minor((string) ($p['exp_' . $type] ?? ''));
            if ($m > 0) {
                $expenses[] = ['type' => $type, 'payee' => (string) ($p['payee_' . $type] ?? ''), 'minor' => $m, 'note' => ''];
            }
        }
        $photos = array_values(array_filter(array_map('absint', explode(',', (string) ($p['photos'] ?? '')))));
        $costSee = array_values(array_intersect((array) ($p['cost_see'] ?? ['only_me']), PriceAudience::SHOW)) ?: ['only_me'];
        return ['name' => $name, 'pack_name' => sanitize_text_field((string) ($p['pack_name'] ?? '')) ?: 'case', 'pieces' => $pieces, 'packs' => $packs,
            'pack_cost' => $packCost, 'expenses' => $expenses, 'supplier' => sanitize_text_field((string) ($p['supplier'] ?? '')), 'retail' => $retail,
            'singles' => !empty($p['singles']), 'sets' => $sets, 'photos' => $photos, 'cost_see' => $costSee, 'status' => !empty($p['draft']) ? 'draft' : 'publish'];
    }

    public static function save(array $d, int $productId, int $author): int {
        $prod = $productId > 0 ? wc_get_product($productId) : null;
        if ($productId > 0 && !$prod) {
            throw new DomainException('Product not found.');
        }
        if (!$prod) {
            $prod = new \WC_Product_Simple();
        }
        $prod->set_name($d['name']);
        $prod->set_status($d['status']);
        $prod->set_regular_price(wc_format_decimal($d['retail'] / (10 ** wc_get_price_decimals()), wc_get_price_decimals()));
        if ($d['packs'] > 0) {
            $prod->set_manage_stock(true);
            $prod->set_stock_quantity(($productId > 0 ? (int) $prod->get_stock_quantity() : 0) + $d['packs'] * $d['pieces']);
        }
        if ($d['photos']) {
            $prod->set_image_id($d['photos'][0]);
            $prod->set_gallery_image_ids(array_slice($d['photos'], 1));
        }
        $id = $prod->save();
        if ($author > 0 && $productId === 0) {
            wp_update_post(['ID' => $id, 'post_author' => $author]);
        }
        update_post_meta($id, self::PACK, wp_slash(wp_json_encode(['name' => $d['pack_name'], 'pieces' => $d['pieces'], 'singles' => $d['singles']])));
        PriceAudience::saveSets($id, $d['sets'], null);
        if ($d['packs'] > 0 && $d['pack_cost'] > 0) {
            $calc = Purchase::calculate([['product_id' => $id, 'name' => $d['name'], 'packs' => $d['packs'], 'pieces' => $d['pieces'], 'pack_minor' => $d['pack_cost']]], $d['expenses']);
            Purchase::save($calc, $d['supplier'], current_time('Y-m-d'), $author);
        }
        $cost = get_post_meta($id, PriceAudience::COST, true);
        $cost = is_string($cost) ? json_decode($cost, true) : null;
        if (is_array($cost)) {
            PriceAudience::saveSets($id, $d['sets'], ['minor' => (int) $cost['minor'], 'show_to' => $d['cost_see']]);
        }
        return $id;
    }

    public static function handle(): void {
        if (($_GET['page'] ?? '') !== 'sfm-product-sheet' || !current_user_can('edit_products')) {
            return;
        }
        if (isset($_GET['sfm_lang']) && in_array($_GET['sfm_lang'], ['bn', 'en'], true)) {
            check_admin_referer('sfm_lang');
            update_user_meta(get_current_user_id(), self::LANG, sanitize_key($_GET['sfm_lang']));
            wp_safe_redirect(remove_query_arg(['sfm_lang', '_wpnonce']));
            exit;
        }
        if (empty($_POST['sfm_sheet'])) {
            return;
        }
        check_admin_referer('sfm_sheet');
        try {
            $pid = absint($_POST['product'] ?? 0);
            if ($pid && !current_user_can('edit_post', $pid)) {
                throw new DomainException('You cannot edit this product.');
            }
            $id = self::save(self::fromPost(wp_unslash($_POST)), $pid, get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=sfm-product-sheet&product=' . $id . '&saved=1'));
            exit;
        } catch (DomainException $e) {
            add_settings_error('sfm_sheet', 'error', $e->getMessage());
        }
    }

    /* ---------------- page ---------------- */

    public static function page(): void {
        $lang = self::lang();
        $T = fn($k) => esc_html(self::t($k, $lang));
        $pid = absint($_GET['product'] ?? 0);
        $prod = $pid ? wc_get_product($pid) : null;
        $pack = $pid ? json_decode((string) get_post_meta($pid, self::PACK, true), true) : null;
        $sets = $pid ? PriceAudience::sets($pid) : [];
        $byLabel = [];
        foreach ($sets as $s) {
            $byLabel[$s['label']] = $s;
        }
        $photos = $prod ? array_filter(array_merge([(int) $prod->get_image_id()], array_map('intval', $prod->get_gallery_image_ids()))) : [];
        $dec = wc_get_price_decimals();
        $retail = $prod ? (string) $prod->get_regular_price() : '';
        $wp = isset($byLabel['Wholesale']) ? self::dec((int) reset($byLabel['Wholesale']['ladder'])) : '';
        $wmin = isset($byLabel['Wholesale qty']) ? (string) array_key_first($byLabel['Wholesale qty']['ladder']) : '';
        $wsee = isset($byLabel['Wholesale']) ? $byLabel['Wholesale']['show_to'] : ['business', 'vendor'];
        $landed = $pid ? json_decode((string) get_post_meta($pid, Purchase::LANDED, true), true) : null;
        $cost = $pid ? json_decode((string) get_post_meta($pid, PriceAudience::COST, true), true) : null;
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>' . $T('saved') . '</p></div>';
        }
        settings_errors('sfm_sheet');
        $switch = fn($l, $label) => '<a class="button' . ($lang === $l ? ' button-primary' : '') . '" href="' . esc_url(wp_nonce_url(add_query_arg('sfm_lang', $l), 'sfm_lang')) . '">' . esc_html($label) . '</a>';
        $opt = function (array $keys, $sel) use ($lang): string {
            $h = '';
            foreach ($keys as $k) {
                $h .= '<option value="' . esc_attr($k) . '"' . selected($sel, $k, false) . '>' . esc_html(self::t($k, $lang)) . '</option>';
            }
            return $h;
        };
        $checks = function (string $name, array $sel) use ($lang): string {
            $h = '';
            foreach (PriceAudience::SHOW as $k) {
                $h .= '<label style="margin-right:10px"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($k) . '"' . checked(in_array($k, $sel, true), true, false) . '> ' . esc_html(self::t($k, $lang)) . '</label>';
            }
            return $h;
        };
        echo '<div class="wrap sfm-sheet"><h1>' . ($pid ? $T('edit') : $T('title')) . ' <span style="float:right">' . $switch('bn', 'বাংলা') . ' ' . $switch('en', 'English') . '</span></h1>'
            . '<form method="post">' . wp_nonce_field('sfm_sheet', '_wpnonce', true, false) . '<input type="hidden" name="sfm_sheet" value="1"><input type="hidden" name="product" value="' . (int) $pid . '">'
            . '<div style="display:flex;gap:24px;flex-wrap:wrap"><div style="flex:2;min-width:320px">'
            . '<p><label><strong>' . $T('name') . '</strong><br><input name="name" class="regular-text" required value="' . esc_attr($prod ? $prod->get_name() : '') . '"></label></p>'
            . '<p><strong>' . $T('photos') . '</strong><br><span id="sfm-photo-list">';
        foreach ($photos as $ph) {
            echo wp_get_attachment_image($ph, [60, 60], false, ['style' => 'margin-right:6px']);
        }
        echo '</span><input type="hidden" name="photos" id="sfm-photos" value="' . esc_attr(implode(',', $photos)) . '"> <button type="button" class="button" id="sfm-photo-btn">' . $T('add_photos') . '</button></p>'
            . '<h2>1. ' . $T('buy') . '</h2><table class="form-table"><tr><th>' . $T('pack_name') . '</th><td><input name="pack_name" value="' . esc_attr($pack['name'] ?? '') . '" placeholder="case / কেস"></td></tr>'
            . '<tr><th>' . $T('pieces') . '</th><td><input name="pieces" id="sfm-pieces" inputmode="numeric" value="' . esc_attr((string) ($pack['pieces'] ?? '')) . '"></td></tr>'
            . '<tr><th>' . $T('packs') . '</th><td><input name="packs" id="sfm-packs" inputmode="numeric"></td></tr>'
            . '<tr><th>' . $T('pack_cost') . '</th><td><input name="pack_cost" id="sfm-pack-cost" inputmode="decimal" value="' . esc_attr(is_array($landed) ? '' : '') . '"></td></tr>'
            . '<tr><th>' . $T('supplier') . '</th><td><input name="supplier"></td></tr></table>'
            . '<h3>' . $T('costs') . '</h3><table class="form-table">';
        foreach (['own_fare', 'staff_fare', 'truck', 'labour'] as $t) {
            echo '<tr><th>' . $T($t) . '</th><td><input name="exp_' . $t . '" class="sfm-exp" inputmode="decimal" style="width:120px"> ' . $T('paid_to') . ' <input name="payee_' . $t . '" style="width:160px"></td></tr>';
        }
        echo '</table><p><strong>' . $T('cost_see') . '</strong><br>' . $checks('cost_see', is_array($cost) ? (array) $cost['show_to'] : ['only_me']) . '</p>'
            . '<h2>2. ' . $T('sell') . '</h2><table class="form-table"><tr><th>' . $T('retail') . '</th><td><input name="retail" id="sfm-retail" inputmode="decimal" required value="' . esc_attr($retail) . '"> <label><input type="checkbox" name="singles" value="1"' . checked(!isset($pack['singles']) || !empty($pack['singles']), true, false) . '> ' . $T('singles') . '</label></td></tr></table>'
            . '<h3>' . $T('wholesale') . '</h3><table class="form-table"><tr><th>' . $T('w_piece') . '</th><td><input name="w_piece" id="sfm-wp" inputmode="decimal" value="' . esc_attr($wp) . '"></td></tr>'
            . '<tr><th>' . $T('w_min') . '</th><td><input name="w_min" inputmode="numeric" value="' . esc_attr($wmin) . '"></td></tr>'
            . '<tr><th>' . $T('w_see') . '</th><td>' . $checks('w_see', $wsee) . '</td></tr></table>'
            . '<h3>' . $T('steps') . '</h3><table class="widefat" id="sfm-steps"><thead><tr><th>' . $T('qty') . '</th><th>' . $T('price') . '</th><th>' . $T('for') . '</th><th>' . $T('see') . '</th></tr></thead><tbody>';
        $rows = [];
        foreach ($sets as $s) {
            if (in_array($s['label'], ['Retail', 'Wholesale', 'Wholesale qty', 'Offer'], true)) {
                continue;
            }
            foreach ($s['ladder'] as $q => $m) {
                $rows[] = [$q, self::dec((int) $m), $s['buy_for'], $s['show_to'][0] ?? 'public'];
            }
        }
        for ($i = 0; $i < max(4, count($rows) + 2); $i++) {
            [$q, $m, $f, $s] = $rows[$i] ?? ['', '', 'everyone', 'public'];
            echo '<tr><td><input name="step_qty[' . $i . ']" inputmode="numeric" style="width:80px" value="' . esc_attr((string) $q) . '"></td><td><input name="step_price[' . $i . ']" class="sfm-step-price" inputmode="decimal" style="width:100px" value="' . esc_attr($m) . '"></td>'
                . '<td><select name="step_for[' . $i . ']">' . $opt(['everyone', 'regular', 'premium', 'business'], $f) . '</select></td><td><select name="step_see[' . $i . ']">' . $opt(PriceAudience::SHOW, $s) . '</select></td></tr>'; // phpcs:ignore -- built from escaped parts
        }
        $promo = null; $promoFor = 'premium';
        foreach ($sets as $s) {
            if (!empty($s['promo'])) {
                $promo = $s['promo']; $promoFor = $s['buy_for'];
            }
        }
        echo '</tbody></table><h3>' . $T('offer') . '</h3><p>' . $T('offer_buy') . ' <input name="offer_buy" inputmode="numeric" style="width:70px" value="' . esc_attr((string) ($promo['buy'] ?? '')) . '"> · <input name="offer_off" inputmode="numeric" style="width:70px" value="' . esc_attr((string) ($promo['off'] ?? '')) . '"> ' . $T('offer_off')
            . ' · ' . $T('for') . ' <select name="offer_for">' . $opt(['everyone', 'regular', 'premium', 'business'], $promoFor) . '</select></p>'; // phpcs:ignore
        echo '<p><button class="button button-primary button-hero">' . $T('save') . '</button> <button class="button" name="draft" value="1">' . $T('draft') . '</button></p></div>'
            . '<div style="flex:1;min-width:260px"><div class="postbox" style="padding:12px;position:sticky;top:40px"><h2>' . $T('calc') . '</h2>'
            . '<p>' . $T('cost_pack') . ': <strong id="sfm-c-pack">—</strong></p><p>' . $T('cost_piece') . ': <strong id="sfm-c-piece">' . esc_html(is_array($landed) ? self::dec((int) $landed['piece_minor']) : '—') . '</strong></p>'
            . '<p>' . $T('retail') . ' → ' . $T('profit') . ': <strong id="sfm-p-retail">—</strong></p><p>' . $T('w_piece') . ' → ' . $T('profit') . ': <strong id="sfm-p-w">—</strong></p><div id="sfm-p-steps"></div></div></div></div></form></div>';
        $known = is_array($landed) ? (int) $landed['piece_minor'] / (10 ** $dec) : 0;
        ?>
<script>
(function(){
  const $=id=>document.getElementById(id), n=v=>{v=String(v||'').replace(/[০-৯]/g,d=>'০১২৩৪৫৬৭৮৯'.indexOf(d)).replace(/,/g,'');return parseFloat(v)||0;};
  const known=<?php echo wp_json_encode($known); ?>, profitTxt=<?php echo wp_json_encode(self::t('profit', $lang)); ?>;
  const fmt=v=>'৳'+v.toFixed(2), pct=(p,c)=>p>0?((p-c)*100/p).toFixed(1)+'%':'';
  function calc(){
    const pieces=Math.max(1,n($('sfm-pieces').value)), packs=n($('sfm-packs').value), pc=n($('sfm-pack-cost').value);
    let exp=0; document.querySelectorAll('.sfm-exp').forEach(e=>exp+=n(e.value));
    let cost=known;
    if(packs>0&&pc>0){ const total=packs*pc+exp; cost=total/(packs*pieces); $('sfm-c-pack').textContent=fmt(total/packs); }
    $('sfm-c-piece').textContent=cost>0?fmt(cost):'—';
    const show=(id,p)=>{$(id).textContent=(p>0&&cost>0)?fmt(p-cost)+' ('+pct(p,cost)+')':'—';};
    show('sfm-p-retail',n($('sfm-retail').value)); show('sfm-p-w',n($('sfm-wp').value));
    let h=''; document.querySelectorAll('.sfm-step-price').forEach(e=>{const p=n(e.value); if(p>0&&cost>0){const q=e.closest('tr').querySelector('input').value; h+='<p>'+q+'+ : '+fmt(p)+' → '+profitTxt+' '+fmt(p-cost)+' ('+pct(p,cost)+')</p>';}});
    $('sfm-p-steps').innerHTML=h;
  }
  document.querySelector('.sfm-sheet form').addEventListener('input',calc); calc();
  $('sfm-photo-btn').addEventListener('click',function(e){e.preventDefault(); if(!window.wp||!wp.media)return;
    const f=wp.media({title:<?php echo wp_json_encode(self::t('photos', $lang)); ?>,multiple:true,library:{type:'image'}});
    f.on('select',function(){const ids=[],list=$('sfm-photo-list'); list.innerHTML='';
      f.state().get('selection').each(a=>{ids.push(a.id); const u=(a.attributes.sizes&&a.attributes.sizes.thumbnail)?a.attributes.sizes.thumbnail.url:a.attributes.url; const i=document.createElement('img'); i.src=u; i.width=60; i.height=60; i.style.marginRight='6px'; list.appendChild(i);});
      $('sfm-photos').value=ids.join(',');});
    f.open();});
})();
</script>
        <?php
    }
}
