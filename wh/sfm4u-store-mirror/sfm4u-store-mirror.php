<?php
/**
 * Plugin Name: SFM4U Store Mirror
 * Description: Shows a partner shop's products and live stock from its store on AlgiBazar (SFM4U marketplace). Buying always happens on AlgiBazar (one stock, one order book, AlgiBazar payment). Shortcode: [sfm4u_store_mirror]
 * Version: 0.1.0-dev
 * Author: SFM4U
 * Requires PHP: 8.0
 * Text Domain: sfm4u-store-mirror
 */
declare(strict_types=1);

namespace SFM4U\StoreMirror;

defined('ABSPATH') || exit;

final class Mirror {
    public const OPT = 'sfm4u_store_mirror';
    private const CACHE = 'sfm4u_store_mirror_products';
    private const STALE = 'sfm4u_store_mirror_products_stale';

    public static function boot(): void {
        add_shortcode('sfm4u_store_mirror', [self::class, 'shortcode']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'save']);
    }

    /** @return array{source:string,store:int,cache:int} */
    public static function settings(): array {
        $o = get_option(self::OPT, []);
        $o = is_array($o) ? $o : [];
        $source = isset($o['source']) && is_string($o['source']) && $o['source'] !== '' ? $o['source'] : 'https://algibazar.com';
        return [
            'source' => untrailingslashit(esc_url_raw($source)),
            'store' => max(0, (int) ($o['store'] ?? 0)),
            'cache' => min(3600, max(30, (int) ($o['cache'] ?? 60))),
        ];
    }

    /**
     * Products of the partner store from AlgiBazar (Dokan public REST). Cached; on an API error the last good list is used.
     * @return array{items:array<int,array<string,mixed>>,error:string}
     */
    public static function products(): array {
        $s = self::settings();
        if ($s['store'] < 1) {
            return ['items' => [], 'error' => 'not_configured'];
        }
        $key = self::CACHE . '_' . md5($s['source'] . '|' . $s['store']);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return ['items' => $cached, 'error' => ''];
        }
        $items = [];
        for ($page = 1; $page <= 10; $page++) {
            $url = $s['source'] . '/wp-json/dokan/v1/stores/' . $s['store'] . '/products?per_page=100&page=' . $page;
            $r = wp_remote_get($url, ['timeout' => 12, 'headers' => ['Accept' => 'application/json']]);
            $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            $body = $code === 200 ? json_decode((string) wp_remote_retrieve_body($r), true) : null;
            if (!is_array($body)) {
                $stale = get_option(self::STALE . '_' . md5($s['source'] . '|' . $s['store']), []);
                return ['items' => is_array($stale) ? $stale : [], 'error' => 'source_unavailable'];
            }
            foreach ($body as $p) {
                if (is_array($p) && ($p['status'] ?? 'publish') === 'publish' && ($p['catalog_visibility'] ?? 'visible') !== 'hidden') {
                    $items[] = self::clean($p);
                }
            }
            if (count($body) < 100) {
                break;
            }
        }
        set_transient($key, $items, $s['cache']);
        update_option(self::STALE . '_' . md5($s['source'] . '|' . $s['store']), $items, false);
        return ['items' => $items, 'error' => ''];
    }

    /** Keep only what the page shows. */
    public static function clean(array $p): array {
        $img = '';
        if (!empty($p['images'][0]['src']) && is_string($p['images'][0]['src'])) {
            $img = esc_url_raw($p['images'][0]['src']);
        }
        $qty = isset($p['stock_quantity']) && is_numeric($p['stock_quantity']) ? (int) $p['stock_quantity'] : null;
        return [
            'id' => (int) ($p['id'] ?? 0),
            'name' => sanitize_text_field((string) ($p['name'] ?? '')),
            'price' => is_numeric($p['price'] ?? null) ? (string) $p['price'] : '',
            'regular' => is_numeric($p['regular_price'] ?? null) ? (string) $p['regular_price'] : '',
            'link' => esc_url_raw((string) ($p['permalink'] ?? '')),
            'image' => $img,
            'stock_status' => in_array($p['stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? (string) $p['stock_status'] : 'instock',
            'qty' => $qty,
            'purchasable' => !isset($p['purchasable']) || (bool) $p['purchasable'],
        ];
    }

    private static function bn(): bool {
        return str_starts_with(determine_locale(), 'bn');
    }

    private static function t(string $en, string $bn): string {
        return self::bn() ? $bn : $en;
    }

    public static function shortcode(): string {
        $s = self::settings();
        $r = self::products();
        $out = '<div class="sfm4u-mirror">';
        if ($r['error'] === 'not_configured') {
            return current_user_can('manage_options')
                ? '<p class="sfm4u-mirror-note">' . esc_html__('SFM4U Store Mirror: set the AlgiBazar store ID in Settings → SFM4U Store Mirror.', 'sfm4u-store-mirror') . '</p>'
                : '';
        }
        if ($r['error'] === 'source_unavailable') {
            $out .= '<p class="sfm4u-mirror-note">' . esc_html(self::t('Live stock is updating. Prices and stock below may be a few minutes old.', 'লাইভ স্টক আপডেট হচ্ছে। নিচের দাম ও স্টক কয়েক মিনিট পুরোনো হতে পারে।')) . '</p>';
        }
        if (!$r['items']) {
            return $out . '<p class="sfm4u-mirror-note">' . esc_html(self::t('Products are coming soon.', 'পণ্য শিগগিরই আসছে।')) . '</p></div>';
        }
        $out .= '<ul class="sfm4u-mirror-grid">';
        foreach ($r['items'] as $p) {
            $in = $p['stock_status'] !== 'outofstock' && $p['purchasable'];
            $buy = $s['source'] . '/cart/?add-to-cart=' . (int) $p['id'] . '&quantity=1';
            $stock = !$in ? self::t('Out of stock', 'স্টক নেই')
                : ($p['qty'] !== null ? sprintf(self::t('%d in stock', 'স্টকে আছে: %d'), $p['qty']) : self::t('In stock', 'স্টকে আছে'));
            $out .= '<li class="sfm4u-mirror-item">'
                . ($p['image'] !== '' ? '<a href="' . esc_url($p['link']) . '"><img src="' . esc_url($p['image']) . '" alt="' . esc_attr($p['name']) . '" loading="lazy"></a>' : '')
                . '<h3>' . esc_html($p['name']) . '</h3>'
                . ($p['price'] !== '' ? '<p class="sfm4u-mirror-price">৳ ' . esc_html(number_format_i18n((float) $p['price'], 2)) . '</p>' : '')
                . '<p class="sfm4u-mirror-stock' . ($in ? '' : ' out') . '">' . esc_html($stock) . '</p>'
                . ($in ? '<a class="sfm4u-mirror-buy" href="' . esc_url($buy) . '" rel="nofollow">' . esc_html(self::t('Buy on AlgiBazar', 'AlgiBazar-এ কিনুন')) . '</a>' : '')
                . '</li>';
        }
        $out .= '</ul><p class="sfm4u-mirror-note">' . esc_html(self::t('Payment and delivery/pickup are handled securely by AlgiBazar.', 'পেমেন্ট ও ডেলিভারি/পিকআপ AlgiBazar নিরাপদে পরিচালনা করে।')) . '</p></div>';
        return $out . '<style>.sfm4u-mirror-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}.sfm4u-mirror-item{border:1px solid #e3e3e3;border-radius:10px;padding:10px;text-align:center}.sfm4u-mirror-item img{width:100%;height:150px;object-fit:contain}.sfm4u-mirror-item h3{font-size:15px;margin:8px 0 4px}.sfm4u-mirror-price{font-weight:700;margin:2px 0}.sfm4u-mirror-stock{font-size:13px;color:#1a7f37;margin:2px 0 8px}.sfm4u-mirror-stock.out{color:#b42318}.sfm4u-mirror-buy{display:inline-block;background:#0b6bcb;color:#fff;border-radius:6px;padding:8px 12px;text-decoration:none}.sfm4u-mirror-note{font-size:13px;color:#555}</style>';
    }

    public static function menu(): void {
        add_options_page('SFM4U Store Mirror', 'SFM4U Store Mirror', 'manage_options', 'sfm4u-store-mirror', [self::class, 'page']);
    }

    public static function save(): void {
        if (($_GET['page'] ?? '') !== 'sfm4u-store-mirror' || empty($_POST['sfm4u_mirror']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('sfm4u_mirror');
        update_option(self::OPT, [
            'source' => esc_url_raw(wp_unslash((string) ($_POST['source'] ?? ''))),
            'store' => absint($_POST['store'] ?? 0),
            'cache' => absint($_POST['cache'] ?? 60),
        ], false);
        delete_transient(self::CACHE . '_' . md5(self::settings()['source'] . '|' . self::settings()['store']));
        add_settings_error('sfm4u_mirror', 'saved', __('Saved.', 'sfm4u-store-mirror'), 'updated');
    }

    public static function page(): void {
        $s = self::settings();
        settings_errors('sfm4u_mirror');
        echo '<div class="wrap"><h1>SFM4U Store Mirror</h1><p>' . esc_html__('Shows this shop\'s products and live stock from its AlgiBazar store. Customers buy on AlgiBazar. Put [sfm4u_store_mirror] on any page.', 'sfm4u-store-mirror') . '</p>'
            . '<form method="post">' . wp_nonce_field('sfm4u_mirror', '_wpnonce', true, false) . '<input type="hidden" name="sfm4u_mirror" value="1"><table class="form-table">'
            . '<tr><th><label for="source">AlgiBazar URL</label></th><td><input id="source" name="source" class="regular-text" value="' . esc_attr($s['source']) . '"></td></tr>'
            . '<tr><th><label for="store">' . esc_html__('AlgiBazar store (vendor) ID', 'sfm4u-store-mirror') . '</label></th><td><input id="store" name="store" type="number" min="1" value="' . esc_attr((string) $s['store']) . '"></td></tr>'
            . '<tr><th><label for="cache">' . esc_html__('Refresh every (seconds)', 'sfm4u-store-mirror') . '</label></th><td><input id="cache" name="cache" type="number" min="30" max="3600" value="' . esc_attr((string) $s['cache']) . '"></td></tr>'
            . '</table>' . get_submit_button() . '</form></div>';
    }
}

Mirror::boot();
