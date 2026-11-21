<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;

defined('ABSPATH') || exit;

/**
 * SFM4U Purchase & Cost (Owner design 2026-11-22). Inside SFM4U Business Software, NOT ERPNext: a shop records each buying
 * trip — goods (packs/cases, pieces per pack, pack cost) and every expense (own fare, manager/worker fare, truck, labour,
 * other; who was paid, when). The software spreads the expenses over the goods and shows the real cost up to the shop
 * (landed cost) per pack and per piece; the shop may override it. The landed piece cost becomes the product's private cost
 * price (Retail Prices system) and the product's price sheet shows cost, wholesale (B2B), customer (B2C) prices and profit.
 * Money: integer minor units.
 */
final class Purchase {
    public const CPT = 'sfm_purchase';
    public const LANDED = '_sfm_landed';
    public const MARKUP = 'sfm_purchase_markup_pct';
    public const EXPENSES = ['own_fare' => ['Own fare', 'নিজের ভাড়া'], 'staff_fare' => ['Manager / worker fare', 'ম্যানেজার/কর্মচারীর ভাড়া'],
        'truck' => ['Truck / transport', 'ট্রাক/পরিবহন ভাড়া'], 'labour' => ['Labour', 'লেবার খরচ'], 'other' => ['Other', 'অন্যান্য']];

    public static function register(): void {
        add_action('init', [self::class, 'cpt']);
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_init', [self::class, 'handle']);
        add_action('add_meta_boxes', [self::class, 'sheetBox']);
    }

    public static function cpt(): void {
        register_post_type(self::CPT, ['label' => 'SFM4U Purchases', 'public' => false, 'show_ui' => false, 'supports' => ['title', 'author']]);
    }

    public static function canManage(int $uid = 0): bool {
        $uid = $uid ?: get_current_user_id();
        if ($uid <= 0) {
            return false;
        }
        return user_can($uid, 'manage_woocommerce') || (class_exists(Members\Members::class) && in_array(Members\Members::shopRole($uid), ['ceo', 'owner', 'manager'], true));
    }

    /* ---------------- maths ---------------- */

    /**
     * @param array $items    [['product_id'=>int,'name'=>string,'packs'=>int,'pieces'=>int (per pack),'pack_minor'=>int], ...]
     * @param array $expenses [['type'=>string,'payee'=>string,'minor'=>int,'note'=>string], ...]
     * @param string $by       'value' (default) or 'quantity' — how expenses are spread over the goods
     * @param array $override  [line index => landed piece minor] set by the shop by hand
     */
    public static function calculate(array $items, array $expenses, string $by = 'value', array $override = []): array {
        if (!$items) {
            throw new DomainException('Add at least one product.');
        }
        $lines = [];
        foreach (array_values($items) as $i => $it) {
            $packs = (int) ($it['packs'] ?? 0); $pieces = (int) ($it['pieces'] ?? 0); $cost = (int) ($it['pack_minor'] ?? 0);
            if ($packs < 1 || $pieces < 1 || $cost < 1) {
                throw new DomainException('Each product needs packs, pieces per pack and pack cost.');
            }
            $lines[$i] = ['product_id' => (int) ($it['product_id'] ?? 0), 'name' => sanitize_text_field((string) ($it['name'] ?? '')), 'packs' => $packs,
                'pieces' => $pieces, 'pack_minor' => $cost, 'goods_minor' => $packs * $cost, 'units' => $packs * $pieces];
        }
        $exp = 0;
        $clean = [];
        foreach ($expenses as $e) {
            $m = (int) ($e['minor'] ?? 0);
            if ($m < 0) {
                throw new DomainException('An expense cannot be negative.');
            }
            if ($m === 0) {
                continue;
            }
            $type = isset(self::EXPENSES[$e['type'] ?? '']) ? (string) $e['type'] : 'other';
            $clean[] = ['type' => $type, 'payee' => sanitize_text_field((string) ($e['payee'] ?? '')), 'minor' => $m, 'note' => sanitize_text_field((string) ($e['note'] ?? ''))];
            $exp += $m;
        }
        $base = array_sum(array_column($lines, $by === 'quantity' ? 'units' : 'goods_minor'));
        $given = 0;
        $largest = 0;
        foreach ($lines as $i => $l) {
            $share = intdiv($exp * ($by === 'quantity' ? $l['units'] : $l['goods_minor']), max(1, $base));
            $lines[$i]['expense_minor'] = $share;
            $given += $share;
            if ($l['goods_minor'] > $lines[$largest]['goods_minor']) {
                $largest = $i;
            }
        }
        $lines[$largest]['expense_minor'] += $exp - $given;   // rounding remainder to the biggest line, totals stay exact
        foreach ($lines as $i => $l) {
            $landed = $l['goods_minor'] + $l['expense_minor'];
            $lines[$i]['landed_minor'] = $landed;
            $lines[$i]['landed_pack_minor'] = self::div($landed, $l['packs']);
            $lines[$i]['landed_piece_minor'] = self::div($landed, $l['units']);
            $lines[$i]['landed_piece_exact'] = self::exact($landed, $l['units']);
            if (isset($override[$i]) && (int) $override[$i] > 0) {
                $lines[$i]['landed_piece_minor'] = (int) $override[$i];
                $lines[$i]['manual'] = true;
            }
        }
        return ['lines' => $lines, 'expenses' => $clean, 'goods_minor' => array_sum(array_column($lines, 'goods_minor')), 'expense_minor' => $exp,
            'total_minor' => array_sum(array_column($lines, 'goods_minor')) + $exp, 'by' => $by === 'quantity' ? 'quantity' : 'value'];
    }

    /** Rounded half up. */
    public static function div(int $a, int $b): int {
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /** Exact decimal (store scale + 2 more digits) for showing, e.g. 37.5000. */
    public static function exact(int $minor, int $units): string {
        $dec = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $scaled = intdiv($minor * 100, $units);
        $s = str_pad((string) $scaled, $dec + 3, '0', STR_PAD_LEFT);
        return substr($s, 0, -($dec + 2)) . '.' . substr($s, -($dec + 2));
    }

    /** Suggested selling price per piece = landed × (1 + markup %), rounded up to a whole minor unit. */
    public static function suggest(int $landedPiece, int $pct): int {
        return intdiv($landedPiece * (100 + $pct) + 99, 100);
    }

    /* ---------------- save ---------------- */

    public static function save(array $calc, string $supplier, string $date, int $author, int $merchant = 0): int {
        $id = wp_insert_post(['post_type' => self::CPT, 'post_status' => 'private', 'post_author' => $author,
            'post_title' => sanitize_text_field(($supplier !== '' ? $supplier : 'Purchase') . ' ' . $date)], true);
        if (is_wp_error($id)) {
            throw new DomainException($id->get_error_message());
        }
        update_post_meta($id, '_sfm_purchase', wp_slash(wp_json_encode($calc + ['supplier' => $supplier, 'date' => $date, 'merchant' => $merchant])));
        foreach ($calc['lines'] as $l) {
            if ($l['product_id'] > 0 && get_post_type($l['product_id']) === 'product') {
                update_post_meta($l['product_id'], self::LANDED, wp_slash(wp_json_encode(['piece_minor' => $l['landed_piece_minor'], 'pack_minor' => $l['landed_pack_minor'],
                    'pieces' => $l['pieces'], 'purchase' => $id, 'date' => $date, 'manual' => !empty($l['manual'])])));
                // The landed piece cost becomes the private cost price (shop may publish it later in the price sets).
                $sets = PriceAudience::sets($l['product_id']);
                $old = get_post_meta($l['product_id'], PriceAudience::COST, true);
                $old = is_string($old) ? json_decode($old, true) : null;
                PriceAudience::saveSets($l['product_id'], $sets, ['minor' => $l['landed_piece_minor'], 'show_to' => is_array($old) ? (array) ($old['show_to'] ?? ['only_me']) : ['only_me']]);
            }
        }
        return (int) $id;
    }

    public static function get(int $id): ?array {
        $raw = get_post_meta($id, '_sfm_purchase', true);
        $raw = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($raw) ? $raw + ['id' => $id] : null;
    }

    /** Expense ledger: who was paid what, when (newest first). */
    public static function ledger(int $limit = 200): array {
        $rows = [];
        foreach (get_posts(['post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => $limit, 'orderby' => 'ID', 'order' => 'DESC']) as $p) {
            $d = self::get($p->ID);
            foreach ($d['expenses'] ?? [] as $e) {
                $rows[] = ['purchase' => $p->ID, 'date' => $d['date'] ?? '', 'type' => $e['type'], 'payee' => $e['payee'], 'minor' => $e['minor'], 'note' => $e['note']];
            }
        }
        return $rows;
    }

    public static function ledgerTotals(array $rows): array {
        $t = [];
        foreach ($rows as $r) {
            $k = $r['payee'] !== '' ? $r['payee'] : '—';
            $t[$k] = ($t[$k] ?? 0) + $r['minor'];
        }
        arsort($t);
        return $t;
    }

    /* ---------------- product price sheet (cost → wholesale → customer → profit) ---------------- */

    public static function sheetRows(int $productId): array {
        $l = get_post_meta($productId, self::LANDED, true);
        $l = is_string($l) ? json_decode($l, true) : null;
        $cost = is_array($l) ? (int) $l['piece_minor'] : 0;
        $rows = [];
        foreach (PriceAudience::sets($productId) as $s) {
            foreach ($s['ladder'] as $q => $minor) {
                $rows[] = ['label' => $s['label'], 'buy_for' => $s['buy_for'], 'qty' => $q, 'price_minor' => $minor,
                    'profit_minor' => $cost > 0 ? $minor - $cost : null, 'margin_pct' => $cost > 0 ? round(($minor - $cost) * 100 / $minor, 1) : null];
            }
        }
        return ['landed' => is_array($l) ? $l : null, 'suggest_minor' => $cost > 0 ? self::suggest($cost, (int) get_option(self::MARKUP, 10)) : null, 'rows' => $rows];
    }

    private static function money(int $minor): string {
        $dec = wc_get_price_decimals();
        return '৳' . number_format_i18n($minor / (10 ** $dec), $dec);
    }

    public static function sheetBox(): void {
        if (self::canManage()) {
            add_meta_box('sfm-price-sheet', __('SFM4U price sheet: real cost → wholesale → customer → profit', 'sfm4u-wholesale'), [self::class, 'sheetHtml'], 'product', 'normal', 'high');
        }
    }

    public static function sheetHtml(\WP_Post $post): void {
        $s = self::sheetRows($post->ID);
        if (!$s['landed']) {
            echo '<p>' . esc_html__('No purchase recorded for this product yet. Record one in SFM4U Business Software → Purchases; the real cost per piece then appears here.', 'sfm4u-wholesale') . '</p>';
            return;
        }
        $l = $s['landed'];
        echo '<p><strong>' . esc_html__('Real cost up to the shop', 'sfm4u-wholesale') . ':</strong> ' . esc_html(self::money((int) $l['piece_minor'])) . ' / ' . esc_html__('piece', 'sfm4u-wholesale')
            . ' · ' . esc_html(self::money((int) $l['pack_minor'])) . ' / ' . esc_html(sprintf(__('pack of %d', 'sfm4u-wholesale'), (int) $l['pieces']))
            . (!empty($l['manual']) ? ' (' . esc_html__('set by hand', 'sfm4u-wholesale') . ')' : '') . ' · ' . esc_html((string) $l['date'])
            . ' · ' . esc_html__('Suggested price', 'sfm4u-wholesale') . ': ' . esc_html(self::money((int) $s['suggest_minor'])) . '</p>';
        if ($s['rows']) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Price set', 'sfm4u-wholesale') . '</th><th>' . esc_html__('From qty', 'sfm4u-wholesale') . '</th><th>'
                . esc_html__('Price / piece', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Profit / piece', 'sfm4u-wholesale') . '</th><th>%</th></tr></thead><tbody>';
            foreach ($s['rows'] as $r) {
                echo '<tr><td>' . esc_html($r['label']) . '</td><td>' . (int) $r['qty'] . '</td><td>' . esc_html(self::money($r['price_minor'])) . '</td><td'
                    . ($r['profit_minor'] < 0 ? ' style="color:#b42318"' : '') . '>' . esc_html(self::money((int) $r['profit_minor'])) . '</td><td>' . esc_html((string) $r['margin_pct']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    /* ---------------- admin: purchases page ---------------- */

    public static function menu(): void {
        add_submenu_page(Modules::SLUG, __('Purchases & cost', 'sfm4u-wholesale'), __('Purchases & cost', 'sfm4u-wholesale'), 'edit_products', 'sfm-purchases', [self::class, 'page']);
    }

    private static function minor(string $text): int {
        $dec = wc_get_price_decimals();
        $text = strtr(trim($text), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if ($text === '') {
            return 0;
        }
        if (!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,6})?$/', $text)) {
            throw new DomainException('Write money like 400 or 37.50.');
        }
        [$w, $f] = array_pad(explode('.', $text), 2, '');
        return (int) $w * (10 ** $dec) + (int) str_pad(substr($f, 0, $dec), $dec, '0');
    }

    private static function count(string $text): int {
        return absint(strtr(trim($text), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
    }

    public static function fromPost(array $p): array {
        $items = [];
        foreach ((array) ($p['item_product'] ?? []) as $i => $pid) {
            if (trim((string) ($p['item_packs'][$i] ?? '')) === '') {
                continue;
            }
            $items[] = ['product_id' => absint($pid), 'name' => (string) ($p['item_name'][$i] ?? ''), 'packs' => self::count((string) ($p['item_packs'][$i] ?? '')),
                'pieces' => self::count((string) ($p['item_pieces'][$i] ?? '')), 'pack_minor' => self::minor((string) ($p['item_cost'][$i] ?? ''))];
        }
        $expenses = [];
        foreach ((array) ($p['exp_type'] ?? []) as $i => $type) {
            $m = self::minor((string) ($p['exp_amount'][$i] ?? ''));
            if ($m > 0) {
                $expenses[] = ['type' => (string) $type, 'payee' => (string) ($p['exp_payee'][$i] ?? ''), 'minor' => $m, 'note' => (string) ($p['exp_note'][$i] ?? '')];
            }
        }
        $override = [];
        foreach ((array) ($p['item_override'] ?? []) as $i => $v) {
            if (trim((string) $v) !== '') {
                $override[(int) $i] = self::minor((string) $v);
            }
        }
        return [$items, $expenses, ($p['by'] ?? 'value') === 'quantity' ? 'quantity' : 'value', $override];
    }

    public static function handle(): void {
        if (($_GET['page'] ?? '') !== 'sfm-purchases' || empty($_POST['sfm_purchase']) || !self::canManage()) {
            return;
        }
        check_admin_referer('sfm_purchase');
        $post = wp_unslash($_POST);
        try {
            [$items, $expenses, $by, $override] = self::fromPost($post);
            $calc = self::calculate($items, $expenses, $by, $override);
            if (!empty($post['save'])) {
                $id = self::save($calc, sanitize_text_field((string) ($post['supplier'] ?? '')), sanitize_text_field((string) ($post['date'] ?? current_time('Y-m-d'))), get_current_user_id());
                add_settings_error('sfm_purchase', 'saved', sprintf(__('Purchase #%d saved. Real cost per piece is now on each product.', 'sfm4u-wholesale'), $id), 'updated');
            } else {
                set_transient('sfm_purchase_preview_' . get_current_user_id(), $calc, 600);
            }
        } catch (DomainException $e) {
            add_settings_error('sfm_purchase', 'error', $e->getMessage());
        }
    }

    public static function page(): void {
        if (!self::canManage()) {
            wp_die(esc_html__('Only the shop owner or manager can see purchases and costs.', 'sfm4u-wholesale'));
        }
        settings_errors('sfm_purchase');
        $preview = get_transient('sfm_purchase_preview_' . get_current_user_id());
        $types = '';
        foreach (self::EXPENSES as $k => [$en, $bn]) {
            $types .= '<option value="' . esc_attr($k) . '">' . esc_html($en . ' / ' . $bn) . '</option>';
        }
        echo '<div class="wrap"><h1>' . esc_html__('Purchases & real cost / কেনা ও আসল খরচ', 'sfm4u-wholesale') . '</h1>'
            . '<p>' . esc_html__('Write what you bought and every cost of the trip (your fare, manager/worker fare, truck, labour). The software spreads the costs and shows the real cost per pack and per piece. You can also type the cost per piece yourself.', 'sfm4u-wholesale') . '</p>'
            . '<form method="post">' . wp_nonce_field('sfm_purchase', '_wpnonce', true, false) . '<input type="hidden" name="sfm_purchase" value="1">'
            . '<p><label>' . esc_html__('Supplier', 'sfm4u-wholesale') . ' <input name="supplier"></label> <label>' . esc_html__('Date', 'sfm4u-wholesale') . ' <input type="date" name="date" value="' . esc_attr(current_time('Y-m-d')) . '"></label> '
            . '<label>' . esc_html__('Spread costs by', 'sfm4u-wholesale') . ' <select name="by"><option value="value">' . esc_html__('value', 'sfm4u-wholesale') . '</option><option value="quantity">' . esc_html__('pieces', 'sfm4u-wholesale') . '</option></select></label></p>'
            . '<h2>' . esc_html__('Goods', 'sfm4u-wholesale') . '</h2><table class="widefat"><thead><tr><th>' . esc_html__('Product ID', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Name', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Packs/cases', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Pieces per pack', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Cost per pack', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Cost per piece by hand (optional)', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        for ($i = 0; $i < 6; $i++) {
            echo '<tr><td><input name="item_product[' . $i . ']" type="number" min="0" style="width:90px"></td><td><input name="item_name[' . $i . ']"></td><td><input name="item_packs[' . $i . ']" type="number" min="1" style="width:80px"></td><td><input name="item_pieces[' . $i . ']" type="number" min="1" style="width:80px"></td><td><input name="item_cost[' . $i . ']" inputmode="decimal" style="width:100px"></td><td><input name="item_override[' . $i . ']" inputmode="decimal" style="width:100px"></td></tr>';
        }
        echo '</tbody></table><h2>' . esc_html__('Costs of the trip (who was paid)', 'sfm4u-wholesale') . '</h2><table class="widefat"><thead><tr><th>' . esc_html__('Type', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Paid to', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Amount', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Note', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
        for ($i = 0; $i < 5; $i++) {
            echo '<tr><td><select name="exp_type[' . $i . ']">' . $types . '</select></td><td><input name="exp_payee[' . $i . ']"></td><td><input name="exp_amount[' . $i . ']" inputmode="decimal" style="width:100px"></td><td><input name="exp_note[' . $i . ']"></td></tr>'; // phpcs:ignore -- $types escaped above
        }
        echo '</tbody></table><p><button class="button">' . esc_html__('Calculate', 'sfm4u-wholesale') . '</button> <button class="button button-primary" name="save" value="1">' . esc_html__('Save purchase', 'sfm4u-wholesale') . '</button></p></form>';
        if (is_array($preview)) {
            echo '<h2>' . esc_html__('Result', 'sfm4u-wholesale') . '</h2><p>' . esc_html(sprintf(__('Goods %1$s + costs %2$s = %3$s', 'sfm4u-wholesale'), self::money($preview['goods_minor']), self::money($preview['expense_minor']), self::money($preview['total_minor']))) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Share of costs', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Real cost / pack', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Real cost / piece', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach ($preview['lines'] as $l) {
                echo '<tr><td>' . esc_html($l['name'] !== '' ? $l['name'] : '#' . $l['product_id']) . '</td><td>' . esc_html(self::money($l['expense_minor'])) . '</td><td>' . esc_html(self::money($l['landed_pack_minor'])) . '</td><td>' . esc_html(self::money($l['landed_piece_minor'])) . ' (' . esc_html($l['landed_piece_exact']) . ')</td></tr>';
            }
            echo '</tbody></table>';
        }
        $rows = self::ledger();
        if ($rows) {
            echo '<h2>' . esc_html__('Who was paid what', 'sfm4u-wholesale') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Date', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Type', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Paid to', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Amount', 'sfm4u-wholesale') . '</th><th>' . esc_html__('Purchase', 'sfm4u-wholesale') . '</th></tr></thead><tbody>';
            foreach (array_slice($rows, 0, 50) as $r) {
                echo '<tr><td>' . esc_html($r['date']) . '</td><td>' . esc_html(self::EXPENSES[$r['type']][0] ?? $r['type']) . '</td><td>' . esc_html($r['payee']) . '</td><td>' . esc_html(self::money($r['minor'])) . '</td><td>#' . (int) $r['purchase'] . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
