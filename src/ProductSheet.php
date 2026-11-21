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
                $entry = dirname(__DIR__) . '/sfm4u-wholesale.php';
                wp_enqueue_script('sfm-wr-qrcode', plugins_url('assets/qrcode.js', $entry), [], '1.4.4', true);
                wp_enqueue_script('sfm-wr-members', plugins_url('assets/members.js', $entry), ['sfm-wr-qrcode'], '0.5.0', true);
                wp_enqueue_style('sfm-wr-members', plugins_url('assets/members.css', $entry), [], '0.3.0');
            }
        });
        add_action('post_submitbox_misc_actions', [self::class, 'editLink']);
        add_action('woocommerce_single_product_summary', [self::class, 'front'], 27);
    }

    public const SECTIONS = ['buy', 'costs', 'wholesale', 'steps', 'offer', 'details', 'delivery', 'label'];
    public const SHOP_SECTIONS = 'sfm_sheet_shop_sections';
    public const VIDEO_MAX_SECONDS = 15;
    public const VIDEO_MAX_BYTES = 25 * 1024 * 1024;

    /** Owner setting: which parts of the sheet a shop (not owner/manager/admin) may use. Default: all. */
    public static function allowed(string $section, int $uid = 0): bool {
        $uid = $uid ?: get_current_user_id();
        if (Purchase::canManage($uid)) {
            return true;
        }
        $o = get_option(self::SHOP_SECTIONS, self::SECTIONS);
        return in_array($section, is_array($o) ? $o : self::SECTIONS, true);
    }

    /** Short videos only (server is small): video file, at most 15 seconds and 25 MB. */
    public static function checkVideo(int $id): void {
        if ($id <= 0) {
            return;
        }
        if (!str_starts_with((string) get_post_mime_type($id), 'video/')) {
            throw new DomainException('The video must be a video file. / ভিডিও ফাইল দিন।');
        }
        $meta = wp_get_attachment_metadata($id);
        $len = is_array($meta) && isset($meta['length']) ? (int) $meta['length'] : 0;
        $file = get_attached_file($id);
        $size = is_array($meta) && !empty($meta['filesize']) ? (int) $meta['filesize'] : ($file && is_file($file) ? (int) filesize($file) : 0);
        if ($len > self::VIDEO_MAX_SECONDS) {
            throw new DomainException(sprintf('Video is %d seconds; at most %d seconds. / ভিডিও %d সেকেন্ড; সর্বোচ্চ %d সেকেন্ড।', $len, self::VIDEO_MAX_SECONDS, $len, self::VIDEO_MAX_SECONDS));
        }
        if ($size > self::VIDEO_MAX_BYTES) {
            throw new DomainException('Video is too big (max 25 MB). / ভিডিও খুব বড় (সর্বোচ্চ ২৫ MB)।');
        }
    }

    /** Product page: short video, company, dates, delivery. */
    public static function front(): void {
        global $product;
        if (!$product instanceof \WC_Product) {
            return;
        }
        $id = $product->get_id();
        $bn = str_starts_with(determine_locale(), 'bn');
        $v = (int) get_post_meta($id, self::VIDEO, true);
        $info = json_decode((string) get_post_meta($id, self::INFO, true), true) ?: [];
        $del = json_decode((string) get_post_meta($id, self::DELIVERY, true), true) ?: [];
        $h = '';
        if ($v && ($url = wp_get_attachment_url($v))) {
            $h .= '<video src="' . esc_url($url) . '" controls muted playsinline preload="none" style="max-width:100%;border-radius:8px"></video>';
        }
        $rows = [];
        if (!empty($info['brand'])) { $rows[] = ($bn ? 'কোম্পানি: ' : 'Company: ') . $info['brand']; }
        if (!empty($info['exp'])) { $rows[] = ($bn ? 'মেয়াদ শেষ: ' : 'Expiry: ') . $info['exp']; }
        if (!empty($info['mfg'])) { $rows[] = ($bn ? 'তৈরির তারিখ: ' : 'Made on: ') . $info['mfg']; }
        $mode = $del['mode'] ?? '';
        if ($mode !== '') {
            $rows[] = ['pickup' => $bn ? 'দোকান থেকে নেওয়া যাবে' : 'Store pickup', 'home' => $bn ? 'বাসায় ডেলিভারি' : 'Home delivery', 'both' => $bn ? 'পিকআপ ও বাসায় ডেলিভারি' : 'Pickup and home delivery'][$mode] ?? '';
        }
        $rows[] = $bn ? 'AlgiBazar-এ বিক্রি; বিক্রেতা দোকান দায়ী।' : 'Sold on AlgiBazar; the seller shop is responsible.';
        foreach (array_filter($rows) as $r) {
            $h .= '<p class="sfm-front-info" style="margin:2px 0">' . esc_html($r) . '</p>';
        }
        echo '<div class="sfm-front">' . $h . '</div>'; // phpcs:ignore -- escaped above
    }

    public const VIDEO = '_sfm_video';
    public const INFO = '_sfm_info';
    public const DELIVERY = '_sfm_delivery';
    public const RECEIVED = '_sfm_received_pieces';

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
            'only_me' => ['Only me', 'শুধু আমি'],
            'media' => ['Photos and short video', 'ছবি ও ছোট ভিডিও'], 'video' => ['Short video (max 15 seconds)', 'ছোট ভিডিও (সর্বোচ্চ ১৫ সেকেন্ড)'], 'add_video' => ['Add / change video', 'ভিডিও দিন / বদলান'],
            'details' => ['Product details', 'পণ্যের বিবরণ'], 'brand' => ['Company / brand', 'কোম্পানি / ব্র্যান্ড'], 'category' => ['Category', 'ক্যাটাগরি'],
            'unit' => ['Sold by', 'কিসে বিক্রি'], 'piece' => ['Piece', 'পিস'], 'kg' => ['Kg', 'কেজি'], 'litre' => ['Litre', 'লিটার'], 'bag' => ['Bag (বস্তা)', 'বস্তা'], 'dozen' => ['Dozen', 'ডজন'],
            'short' => ['Short description', 'ছোট বিবরণ'], 'desc' => ['Full description', 'পূর্ণ বিবরণ'], 'mfg' => ['Made on', 'তৈরির তারিখ'], 'exp' => ['Expiry date', 'মেয়াদ শেষের তারিখ'], 'batch' => ['Batch / lot', 'ব্যাচ / লট'],
            'stock' => ['Stock', 'স্টক'], 'received' => ['Received', 'এসেছে'], 'sold' => ['Sold', 'বিক্রি হয়েছে'], 'left' => ['In stock now', 'এখন স্টকে'],
            'delivery' => ['Delivery', 'ডেলিভারি'], 'pickup' => ['Store pickup', 'দোকান থেকে নেওয়া'], 'home' => ['Home delivery', 'বাসায় ডেলিভারি'], 'both' => ['Both', 'দুটোই'],
            'weight' => ['Weight of one piece (kg)', 'এক পিসের ওজন (কেজি)'], 'd_note' => ['Delivery note', 'ডেলিভারি নোট'],
            'sku' => ['Product code (SKU) — empty = made automatically', 'পণ্যের কোড (SKU) — খালি রাখলে নিজে তৈরি হবে'],
            'label' => ['Label and receipt', 'লেবেল ও রিসিপ্ট'], 'print_label' => ['Print label', 'লেবেল প্রিন্ট'], 'print_receipt' => ['Print receipt sample', 'রিসিপ্টের নমুনা প্রিন্ট'],
            'status' => ['Status', 'অবস্থা'], 'st_publish' => ['Published (customers see it)', 'প্রকাশিত (কাস্টমার দেখছে)'], 'st_draft' => ['Saved, not published', 'সেভ করা, প্রকাশ হয়নি'],
            'publish' => ['Save and publish', 'সেভ ও প্রকাশ'], 'just_save' => ['Save only', 'শুধু সেভ'],
            'shop_sections' => ['Owner setting: which parts shops may use', 'মালিকের সেটিং: দোকানদার কোন অংশ ব্যবহার করতে পারবে'], 'buy_s' => ['Buying', 'কেনা'], 'costs_s' => ['Trip costs', 'আনার খরচ'], 'wholesale_s' => ['Wholesale', 'পাইকারি'], 'steps_s' => ['Customer steps', 'কাস্টমারের ধাপ'], 'offer_s' => ['Offer', 'অফার'], 'details_s' => ['Details', 'বিবরণ'], 'delivery_s' => ['Delivery', 'ডেলিভারি'], 'label_s' => ['Label', 'লেবেল'], 'cost_see' => ['Who can see the buying price (staff never)', 'কেনা দাম কে দেখবে (স্টাফ কখনো না)'],
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
        $pieces = self::num((string) ($p['pieces'] ?? ''));   // 0 = not given: keep the saved pack size
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
        $date = static function ($v): string {
            $v = trim((string) $v);
            if ($v === '') { return ''; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) { throw new DomainException('Write dates like 2026-12-31. / তারিখ লিখুন যেমন 2026-12-31'); }
            return $v;
        };
        $mfg = $date($p['mfg'] ?? ''); $exp = $date($p['exp'] ?? '');
        if ($mfg !== '' && $exp !== '' && $exp < $mfg) {
            throw new DomainException('Expiry date is before the made-on date. / মেয়াদ তৈরির তারিখের আগে হতে পারে না।');
        }
        $sku = trim(sanitize_text_field((string) ($p['sku'] ?? '')));
        if ($sku !== '' && !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $sku)) {
            throw new DomainException('Product code: letters, numbers, dot, dash only. / কোডে শুধু অক্ষর, সংখ্যা, ডট, ড্যাশ।');
        }
        $weight = trim(strtr((string) ($p['weight'] ?? ''), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
        if ($weight !== '' && !preg_match('/^[0-9]{1,5}(\.[0-9]{1,3})?$/', $weight)) {
            throw new DomainException('Weight like 0.25 (kg). / ওজন লিখুন যেমন 0.25 (কেজি)।');
        }
        return ['name' => $name, 'pack_name' => sanitize_text_field((string) ($p['pack_name'] ?? '')) ?: 'case', 'pieces' => $pieces, 'packs' => $packs,
            'pack_cost' => $packCost, 'expenses' => $expenses, 'supplier' => sanitize_text_field((string) ($p['supplier'] ?? '')), 'retail' => $retail,
            'singles' => !empty($p['singles']), 'sets' => $sets, 'photos' => $photos, 'cost_see' => $costSee,
            'status' => !empty($p['publish']) ? 'publish' : (!empty($p['draft']) || !empty($p['just_save']) ? 'keep' : 'publish'),
            'video' => absint($p['video'] ?? 0), 'category' => absint($p['category'] ?? 0),
            'info' => ['brand' => sanitize_text_field((string) ($p['brand'] ?? '')), 'unit' => in_array($p['unit'] ?? '', ['piece', 'kg', 'litre', 'bag', 'dozen'], true) ? (string) $p['unit'] : 'piece',
                'mfg' => $mfg, 'exp' => $exp, 'batch' => sanitize_text_field((string) ($p['batch'] ?? ''))],
            'short' => wp_kses_post((string) ($p['short'] ?? '')), 'desc' => wp_kses_post((string) ($p['desc'] ?? '')), 'sku' => $sku, 'weight' => $weight,
            'delivery' => ['mode' => in_array($p['delivery_mode'] ?? '', ['pickup', 'home', 'both'], true) ? (string) $p['delivery_mode'] : 'pickup', 'note' => sanitize_text_field((string) ($p['delivery_note'] ?? ''))]];
    }

    public static function save(array $d, int $productId, int $author): int {
        $prod = $productId > 0 ? wc_get_product($productId) : null;
        if ($productId > 0 && !$prod) {
            throw new DomainException('Product not found.');
        }
        if (!$prod) {
            $prod = new \WC_Product_Simple();
        }
        self::checkVideo((int) ($d['video'] ?? 0));
        if ((int) $d['pieces'] < 1) {
            $old = $productId > 0 ? (json_decode((string) get_post_meta($productId, self::PACK, true), true) ?: []) : [];
            $d['pieces'] = max(1, (int) ($old['pieces'] ?? 1));
            if (($d['pack_name'] ?? 'case') === 'case' && !empty($old['name'])) { $d['pack_name'] = (string) $old['name']; }
        }
        $prod->set_name($d['name']);
        $status = $d['status'] === 'keep' ? ($productId > 0 ? $prod->get_status() : 'draft') : $d['status'];
        $prod->set_status($status);
        if (isset($d['short'])) { $prod->set_short_description($d['short']); }
        if (isset($d['desc'])) { $prod->set_description($d['desc']); }
        if (!empty($d['weight'])) { $prod->set_weight($d['weight']); }
        if (!empty($d['category'])) { $prod->set_category_ids([(int) $d['category']]); }
        if (!empty($d['sku']) && $d['sku'] !== $prod->get_sku()) {
            $other = wc_get_product_id_by_sku($d['sku']);
            if ($other && $other !== $productId) { throw new DomainException('This product code is used by another product. / এই কোড অন্য পণ্যে আছে।'); }
            $prod->set_sku($d['sku']);
        }
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
        if ($prod->get_sku() === '') {   // automatic product code for the QR / barcode label and POS scanning
            $code = 'AB' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            if (!wc_get_product_id_by_sku($code)) { $prod->set_sku($code); $prod->save(); }
        }
        if (isset($d['info'])) { update_post_meta($id, self::INFO, wp_slash(wp_json_encode($d['info']))); }
        if (isset($d['delivery'])) { update_post_meta($id, self::DELIVERY, wp_slash(wp_json_encode($d['delivery']))); }
        if (array_key_exists('video', $d)) { $d['video'] ? update_post_meta($id, self::VIDEO, (int) $d['video']) : delete_post_meta($id, self::VIDEO); }
        if ($d['packs'] > 0) { update_post_meta($id, self::RECEIVED, (int) get_post_meta($id, self::RECEIVED, true) + $d['packs'] * $d['pieces']); }
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
            if (!empty($_POST['sfm_shop_sections_save']) && current_user_can('manage_options')) {
                self::saveShopSections(array_map('sanitize_key', (array) wp_unslash($_POST['shop_sections'] ?? [])));
                wp_safe_redirect(admin_url('admin.php?page=sfm-product-sheet' . ($pid ? '&product=' . $pid : '') . '&saved=1'));
                exit;
            }
            $id = self::save(self::restrict(self::fromPost(wp_unslash($_POST)), $pid, get_current_user_id()), $pid, get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=sfm-product-sheet&product=' . $id . '&saved=1'));
            exit;
        } catch (DomainException $e) {
            add_settings_error('sfm_sheet', 'error', $e->getMessage());
        }
    }

    /* ---------------- page ---------------- */

    /** Stock picture of one product: received, sold, now (pieces) and in packs. */
    public static function stockInfo(int $id): array {
        $p = wc_get_product($id);
        $pack = json_decode((string) get_post_meta($id, self::PACK, true), true) ?: [];
        $per = max(1, (int) ($pack['pieces'] ?? 1));
        $now = $p && $p->managing_stock() ? (int) $p->get_stock_quantity() : 0;
        $rec = (int) get_post_meta($id, self::RECEIVED, true);
        return ['received' => $rec, 'now' => $now, 'sold' => max(0, $rec - $now), 'per' => $per, 'now_packs' => intdiv(max(0, $now), $per), 'now_loose' => max(0, $now) % $per];
    }

    /** A shop user may only change the parts the owner allowed; the rest keeps its saved values. */
    public static function restrict(array $d, int $pid, int $uid): array {
        if (Purchase::canManage($uid)) {
            return $d;
        }
        if (!self::allowed('buy', $uid)) { $d['packs'] = 0; $d['pack_cost'] = 0; }
        if (!self::allowed('costs', $uid)) { $d['expenses'] = []; }
        if (!self::allowed('details', $uid)) { unset($d['info'], $d['short'], $d['desc'], $d['category'], $d['sku']); }
        if (!self::allowed('delivery', $uid)) { unset($d['delivery'], $d['weight']); }
        $keep = [];
        foreach (['wholesale' => ['Wholesale', 'Wholesale qty'], 'steps' => ['Customer price', 'Member price', 'Premium price', 'Business price'], 'offer' => ['Offer']] as $sec => $labels) {
            if (!self::allowed($sec, $uid)) { $keep = array_merge($keep, $labels); }
        }
        if ($keep) {
            $old = $pid ? array_values(array_filter(PriceAudience::sets($pid), fn($s) => in_array($s['label'], $keep, true))) : [];
            $d['sets'] = PriceAudience::normalize(array_merge(array_values(array_filter($d['sets'], fn($s) => !in_array($s['label'], $keep, true))), $old));
        }
        return $d;
    }

    public static function saveShopSections(array $sections): void {
        update_option(self::SHOP_SECTIONS, array_values(array_intersect($sections, self::SECTIONS)), false);
    }

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
        $video = $pid ? (int) get_post_meta($pid, self::VIDEO, true) : 0;
        $info = $pid ? (json_decode((string) get_post_meta($pid, self::INFO, true), true) ?: []) : [];
        $del = $pid ? (json_decode((string) get_post_meta($pid, self::DELIVERY, true), true) ?: []) : [];
        $cat = $prod ? (int) (($prod->get_category_ids()[0] ?? 0)) : 0;
        $st = $pid ? self::stockInfo($pid) : null;
        $code = $prod ? Members\MembersAdmin::productCode($prod) : '';
        $A = fn(string $s): bool => self::allowed($s);
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
            . '<p><strong>' . $T('video') . '</strong><br><span id="sfm-video-name">' . esc_html($video ? (string) basename((string) get_attached_file($video)) : '') . '</span><input type="hidden" name="video" id="sfm-video" value="' . (int) $video . '"> <button type="button" class="button" id="sfm-video-btn">' . $T('add_video') . '</button></p>'
            . ($A('details') ? '<h2>' . $T('details') . '</h2><table class="form-table"><tr><th>' . $T('brand') . '</th><td><input name="brand" class="regular-text" value="' . esc_attr((string) ($info['brand'] ?? '')) . '"></td></tr>'
                . '<tr><th>' . $T('category') . '</th><td>' . wp_dropdown_categories(['taxonomy' => 'product_cat', 'name' => 'category', 'hide_empty' => false, 'show_option_none' => '—', 'option_none_value' => '0', 'selected' => $cat, 'echo' => false]) . '</td></tr>'
                . '<tr><th>' . $T('unit') . '</th><td><select name="unit">' . $opt(['piece', 'kg', 'litre', 'bag', 'dozen'], (string) ($info['unit'] ?? 'piece')) . '</select></td></tr>'
                . '<tr><th>' . $T('sku') . '</th><td><input name="sku" value="' . esc_attr($prod ? $prod->get_sku() : '') . '"></td></tr>'
                . '<tr><th>' . $T('mfg') . '</th><td><input type="date" name="mfg" value="' . esc_attr((string) ($info['mfg'] ?? '')) . '"> ' . $T('exp') . ' <input type="date" name="exp" value="' . esc_attr((string) ($info['exp'] ?? '')) . '"> ' . $T('batch') . ' <input name="batch" style="width:120px" value="' . esc_attr((string) ($info['batch'] ?? '')) . '"></td></tr>'
                . '<tr><th>' . $T('short') . '</th><td><textarea name="short" rows="2" class="large-text">' . esc_textarea($prod ? $prod->get_short_description() : '') . '</textarea></td></tr>'
                . '<tr><th>' . $T('desc') . '</th><td><textarea name="desc" rows="4" class="large-text">' . esc_textarea($prod ? $prod->get_description() : '') . '</textarea></td></tr></table>' : '')
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
        if ($A('delivery')) {
            echo '<h2>' . $T('delivery') . '</h2><table class="form-table"><tr><th>' . $T('delivery') . '</th><td><select name="delivery_mode">' . $opt(['pickup', 'home', 'both'], (string) ($del['mode'] ?? 'pickup')) . '</select></td></tr>'
                . '<tr><th>' . $T('weight') . '</th><td><input name="weight" inputmode="decimal" value="' . esc_attr($prod ? (string) $prod->get_weight() : '') . '"></td></tr>'
                . '<tr><th>' . $T('d_note') . '</th><td><input name="delivery_note" class="regular-text" value="' . esc_attr((string) ($del['note'] ?? '')) . '"></td></tr></table>'; // phpcs:ignore -- escaped parts
        }
        echo '<p><button class="button button-primary button-hero" name="publish" value="1">' . $T('publish') . '</button> <button class="button button-hero" name="just_save" value="1">' . $T('just_save') . '</button></p></div>'
            . '<div style="flex:1;min-width:260px">'
            . ($prod ? '<div class="postbox" style="padding:12px"><h2>' . $T('status') . '</h2><p><strong style="color:' . ($prod->get_status() === 'publish' ? '#1a7f37' : '#9a6700') . '">' . $T($prod->get_status() === 'publish' ? 'st_publish' : 'st_draft') . '</strong></p>'
                . '<h3>' . $T('stock') . '</h3><p>' . $T('received') . ': ' . (int) $st['received'] . ' · ' . $T('sold') . ': ' . (int) $st['sold'] . ' · ' . $T('left') . ': <strong>' . (int) $st['now'] . '</strong> (' . (int) $st['now_packs'] . ' ' . esc_html((string) ($pack['name'] ?? '')) . ' + ' . (int) $st['now_loose'] . ')</p></div>' : '')
            . ($prod && $A('label') ? '<div class="postbox" style="padding:12px"><h2>' . $T('label') . '</h2><div id="sfm-label" class="sfm-wr-labels sfm-wr-labels-small"><div class="sfm-wr-label"><div class="sfm-wr-qr" data-qr="' . esc_attr($code) . '"></div><div><b>' . esc_html($prod->get_name()) . '</b><span>৳' . esc_html((string) $prod->get_regular_price()) . '</span><small>' . esc_html($code) . '</small></div></div></div>'
                . '<div style="margin:6px 0">' . Members\Barcode::code128Svg($code) . '</div>'
                . '<p><button type="button" class="button" id="sfm-print-label">' . $T('print_label') . '</button> <button type="button" class="button" id="sfm-print-receipt">' . $T('print_receipt') . '</button></p>'
                . '<div id="sfm-receipt" style="display:none"><div style="width:72mm;font:12px/1.4 Arial,\'Noto Sans Bengali\',sans-serif"><b>' . esc_html(get_bloginfo('name')) . '</b><br>' . esc_html(current_time('Y-m-d H:i')) . '<hr>' . esc_html($prod->get_name()) . '<br>1 × ৳' . esc_html((string) $prod->get_regular_price()) . '<span style="float:right">৳' . esc_html((string) $prod->get_regular_price()) . '</span><hr><small>' . esc_html($code) . '</small></div></div></div>' : '')
            . '<div class="postbox" style="padding:12px;position:sticky;top:40px"><h2>' . $T('calc') . '</h2>'
            . '<p>' . $T('cost_pack') . ': <strong id="sfm-c-pack">—</strong></p><p>' . $T('cost_piece') . ': <strong id="sfm-c-piece">' . esc_html(is_array($landed) ? self::dec((int) $landed['piece_minor']) : '—') . '</strong></p>'
            . '<p>' . $T('retail') . ' → ' . $T('profit') . ': <strong id="sfm-p-retail">—</strong></p><p>' . $T('w_piece') . ' → ' . $T('profit') . ': <strong id="sfm-p-w">—</strong></p><div id="sfm-p-steps"></div></div></div></div></form>';
        if (current_user_can('manage_options')) {
            $cur = get_option(self::SHOP_SECTIONS, self::SECTIONS);
            echo '<form method="post" class="postbox" style="padding:12px;margin-top:16px">' . wp_nonce_field('sfm_sheet', '_wpnonce', true, false) . '<input type="hidden" name="sfm_sheet" value="1"><input type="hidden" name="sfm_shop_sections_save" value="1"><input type="hidden" name="product" value="' . (int) $pid . '"><h2>' . $T('shop_sections') . '</h2><p>';
            foreach (self::SECTIONS as $s) {
                echo '<label style="margin-right:12px"><input type="checkbox" name="shop_sections[]" value="' . esc_attr($s) . '"' . checked(in_array($s, is_array($cur) ? $cur : self::SECTIONS, true), true, false) . '> ' . $T($s . '_s') . '</label>';
            }
            echo '</p><p><button class="button">' . $T('just_save') . '</button></p></form>';
        }
        echo '</div>';
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
  const vb=$('sfm-video-btn'); if(vb) vb.addEventListener('click',function(e){e.preventDefault(); if(!window.wp||!wp.media)return;
    const f=wp.media({title:<?php echo wp_json_encode(self::t('video', $lang)); ?>,multiple:false,library:{type:'video'}});
    f.on('select',function(){const a=f.state().get('selection').first(); if(!a)return; const len=parseInt(a.attributes.fileLength?(a.attributes.fileLength.split(':').reduce((s,x)=>s*60+(+x),0)):0,10);
      if(len>15){alert(<?php echo wp_json_encode('Video max 15 seconds / ভিডিও সর্বোচ্চ ১৫ সেকেন্ড'); ?>);return;}
      $('sfm-video').value=a.id; $('sfm-video-name').textContent=a.attributes.filename||('#'+a.id);});
    f.open();});
  const pr=(id)=>{const el=$(id); if(!el)return; const w=window.open('','sfmprint','width=420,height=600'); if(!w)return;
    w.document.write('<html><head><meta charset="utf-8"><title>Print</title><style>body{font-family:Arial,\'Noto Sans Bengali\',sans-serif}.sfm-wr-label{display:flex;gap:2mm;align-items:center;width:50mm;height:30mm;padding:1.5mm;box-sizing:border-box;font:8pt/1.2 Arial}.sfm-wr-label b,.sfm-wr-label span,.sfm-wr-label small{display:block}.sfm-wr-qr svg{width:24mm;height:24mm}'+(id==='sfm-label'?'@page{size:50mm 30mm;margin:0}':'@page{size:80mm auto;margin:3mm}')+'</style></head><body style="margin:0">'+el.innerHTML.replace('display:none','')+'</body></html>'); w.document.close(); w.focus(); setTimeout(()=>w.print(),300);};
  const pl=$('sfm-print-label'); if(pl) pl.addEventListener('click',()=>pr('sfm-label'));
  const prc=$('sfm-print-receipt'); if(prc) prc.addEventListener('click',()=>pr('sfm-receipt'));
})();
</script>
        <?php
    }
}
