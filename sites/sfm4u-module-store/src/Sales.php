<?php
declare(strict_types=1);
namespace SFM4U\ModuleStore;

use WP_Error;
use WP_REST_Request;

defined('ABSPATH') || exit;

/**
 * CL-3 Sales (Owner 2026-11-22). Store plugin 0.6.0.
 *
 * - Admin-only "Download ZIP" (manage_options + nonce) on the module edit screen and the module list. Streams the newest
 *   release of the module's slug from the private release store (sha256 checked by ReleaseServer). Never a raw URL.
 * - WooCommerce (only when active; everything else keeps working without it):
 *   - "Sell this" box links/creates a simple virtual + downloadable product. Price is empty until the Owner writes one.
 *   - The product's file URL is our own endpoint /wp-json/sfm4u-releases/v1/buy/<slug>. WooCommerce checks the order,
 *     download limit (default 5) and expiry (default 365 days), then we add a signature for that order item (10 minutes)
 *     and stream the ZIP ourselves (method "sfm4u", Force-Downloads style). Hit directly without a valid signature: 403.
 *   - Order completed → one licence row per sold module in {prefix}sfm_store_licences.
 *   - My Account → "SFM4U licences": the buyer binds a licence to a domain and gets a site key (shown once, only its HMAC
 *     is stored, 5 keys per hour). That key on ReleaseServer /check only sees slugs of active licences on that domain.
 */
final class Sales {
    public const DB_VERSION = '1';
    public const OPT_DB = 'sfm_store_db_version';
    public const OPT_LIMIT = 'sfm_store_download_limit';
    public const OPT_EXPIRY = 'sfm_store_download_expiry';
    public const OPT_LICENCE_DAYS = 'sfm_store_licence_days';
    public const DEFAULT_LIMIT = 5;
    public const DEFAULT_EXPIRY = 365;
    public const ENDPOINT = 'sfm4u-licences';
    public const BUY_TTL = 600;           // signed buyer link: 10 minutes
    public const KEY_RATE = 5;            // site keys per user per hour
    public const META_PRODUCT = '_sfm_product_id';   // on sfm_module
    public const META_MODULE = '_sfm_store_module';  // on the WooCommerce product
    public const METHOD = 'sfm4u';

    /** @var array{0:string,1:string}|null result of the last key form (shown in the same request only) */
    private static ?array $keyResult = null;

    public static function register(): void {
        add_action('init', [self::class, 'maybeInstall'], 5);
        add_action('init', [self::class, 'maybeFlush'], 99);
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('sfm4u_releases_site_slugs', [self::class, 'siteSlugs'], 10, 2);
        // admin download
        add_action('add_meta_boxes', [self::class, 'metaBoxes']);
        add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
        add_action('admin_post_sfm_store_admin_dl', [self::class, 'handleAdminDownload']);
        add_action('admin_notices', [self::class, 'adminNotices']);
        // sell
        add_action('save_post_' . CPT, [self::class, 'saveSell'], 20);
        // WooCommerce hooks (never fire without WooCommerce)
        add_filter('woocommerce_download_product_filepath', [self::class, 'signFilePath'], 10, 5);
        add_filter('woocommerce_file_download_method', [self::class, 'downloadMethod'], 10, 3);
        add_action('woocommerce_download_file_' . self::METHOD, [self::class, 'wcStream'], 10, 2);
        add_action('woocommerce_order_status_completed', [self::class, 'orderCompleted']);
        add_action('woocommerce_order_status_refunded', [self::class, 'orderRevoked']);
        add_action('woocommerce_order_status_cancelled', [self::class, 'orderRevoked']);
        add_filter('woocommerce_get_query_vars', [self::class, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [self::class, 'accountMenu']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'accountPage']);
        add_action('template_redirect', [self::class, 'handleKeyForm']);
    }

    public static function wooActive(): bool {
        return (bool) apply_filters('sfm4u_store_woo_active', class_exists('WooCommerce') && function_exists('wc_get_product'));
    }

    /* ---------------- licences table ---------------- */

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sfm_store_licences';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE {$t} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 user_id bigint(20) unsigned NOT NULL DEFAULT 0,
 order_id bigint(20) unsigned NOT NULL DEFAULT 0,
 slug varchar(64) NOT NULL DEFAULT '',
 plan varchar(32) NOT NULL DEFAULT 'single',
 status varchar(20) NOT NULL DEFAULT 'active',
 site varchar(253) NOT NULL DEFAULT '',
 expires datetime NULL DEFAULT NULL,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY order_slug (order_id,slug),
 KEY user_site (user_id,site)
) " . $wpdb->get_charset_collate() . ';');
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function maybeInstall(): void {
        if (get_option(self::OPT_DB) !== self::DB_VERSION) { self::install(); }
    }

    /** New My Account endpoint needs one rewrite flush after an update (activation flushes too). */
    public static function maybeFlush(): void {
        if (get_option('sfm_store_rewrite_ver') !== VERSION) {
            flush_rewrite_rules(false);
            update_option('sfm_store_rewrite_ver', VERSION, false);
        }
    }

    /* ---------------- module → release slug ---------------- */

    /** Slug from the "Release slug" field, else the release whose code equals the module code. '' when none. */
    public static function slugForModule(int $postId): string {
        $s = meta($postId, 'slug');
        if ($s !== '') { return preg_match(ReleaseServer::SLUG_RE, $s) === 1 ? $s : ''; }
        $code = meta($postId, 'code');
        if ($code === '') { return ''; }
        foreach (self::latestMap() as $slug => $r) { if ($r['code'] === $code) { return $slug; } }
        return '';
    }

    /** Newest release per slug, read once per request per releases.json version (module list calls this per row). */
    private static function latestMap(): array {
        static $memo = [];
        $f = ReleaseServer::storeDir() . '/releases.json';
        clearstatcache(true, $f);
        $k = $f . '|' . (is_file($f) ? filemtime($f) . '|' . filesize($f) : '-');
        if (!isset($memo[$k])) { $memo = [$k => ReleaseServer::latest(ReleaseServer::releases())]; }
        return $memo[$k];
    }

    /* ---------------- admin download ---------------- */

    /** Newest verified release of a module for an admin, or WP_Error. */
    public static function adminRelease(int $postId) {
        if (!current_user_can('manage_options')) { return new WP_Error('sfm4u_forbidden', 'Only an administrator may download release files.', ['status' => 403]); }
        if (get_post_type($postId) !== CPT) { return new WP_Error('sfm4u_bad_module', 'Unknown module.', ['status' => 404]); }
        $slug = self::slugForModule($postId);
        if ($slug === '') { return new WP_Error('sfm4u_no_release', 'No release file for this module yet. Set "Release slug" or add it to releases.json.', ['status' => 404]); }
        return ReleaseServer::latestVerified($slug);
    }

    public static function adminDownloadUrl(int $postId): string {
        return wp_nonce_url(add_query_arg(['action' => 'sfm_store_admin_dl', 'post' => $postId], admin_url('admin-post.php')), 'sfm_store_dl_' . $postId);
    }

    /** admin-post.php?action=sfm_store_admin_dl&post=ID&_wpnonce=… */
    public static function handleAdminDownload() {
        $id = isset($_GET['post']) ? absint(wp_unslash((string) $_GET['post'])) : 0;
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Only an administrator may download release files.', 'sfm4u-module-store'), 403); }
        check_admin_referer('sfm_store_dl_' . $id);
        $r = self::adminRelease($id);
        if (is_wp_error($r)) { wp_die(esc_html($r->get_error_message()), (int) ($r->get_error_data()['status'] ?? 404)); }
        return self::deliver($r, 'admin');
    }

    /** Stream a verified release through PHP; tests stop it with the 'sfm4u_store_pre_stream' filter. */
    private static function deliver(array $r, string $context) {
        $pre = apply_filters('sfm4u_store_pre_stream', null, $r, $context);
        if ($pre !== null) { return $pre; }
        ReleaseServer::stream($r['path'], $r['slug'] . '-' . $r['version'] . '.zip');
        exit;
    }

    public static function rowActions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== CPT || !current_user_can('manage_options')) { return $actions; }
        $slug = self::slugForModule((int) $post->ID);
        if ($slug !== '' && isset(self::latestMap()[$slug])) {
            $actions['sfm_dl'] = '<a href="' . esc_url(self::adminDownloadUrl((int) $post->ID)) . '">' . esc_html(sprintf('Download ZIP (%s)', self::latestMap()[$slug]['version'])) . '</a>';
        }
        return $actions;
    }

    public static function metaBoxes(): void {
        if (current_user_can('manage_options')) {
            add_meta_box('sfm_store_release', 'Release file / রিলিজ ফাইল', [self::class, 'releaseBox'], CPT, 'side', 'high');
        }
        add_meta_box('sfm_store_sell', 'Sell this / বিক্রি করুন', [self::class, 'sellBox'], CPT, 'side', 'default');
    }

    public static function releaseBox(\WP_Post $p): void {
        $slug = self::slugForModule((int) $p->ID);
        $l = self::latestMap();
        if ($slug === '' || !isset($l[$slug])) {
            echo '<p>No release file for this module yet. Set <strong>Release slug</strong> in Module details, or add the release to releases.json.</p>';
            return;
        }
        $r = $l[$slug];
        echo '<p><code>' . esc_html($slug) . '</code><br>Version <strong>' . esc_html($r['version']) . '</strong>, ' . esc_html(size_format((int) $r['size'])) . '<br>SHA-256 <code>' . esc_html(substr($r['sha256'], 0, 12)) . '…</code></p>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::adminDownloadUrl((int) $p->ID)) . '">Download ZIP</a></p><p class="description">Admins only. The file comes from the private release store after a checksum check.</p>';
    }

    /* ---------------- sell: product link ---------------- */

    public static function buyUrl(string $slug): string {
        return rest_url(ReleaseServer::NS . '/buy/' . $slug);
    }

    public static function linkedProduct(int $postId): ?\WC_Product {
        if (!self::wooActive()) { return null; }
        $pid = (int) get_post_meta($postId, self::META_PRODUCT, true);
        $p = $pid > 0 ? wc_get_product($pid) : null;
        return $p instanceof \WC_Product && (int) $p->get_meta(self::META_MODULE) === $postId ? $p : null;
    }

    public static function sellBox(\WP_Post $p): void {
        if (!self::wooActive()) {
            echo '<p>WooCommerce is not active on this site, so selling is off. Visitors see the request form.</p>';
            return;
        }
        wp_nonce_field('sfm_store_sell_' . $p->ID, '_sfm_sell_nonce');
        $prod = self::linkedProduct((int) $p->ID);
        $price = $prod ? (string) $prod->get_regular_price() : '';
        echo '<p><label><input type="checkbox" name="sfm_sell[on]" value="1"' . checked($prod && $prod->get_status() === 'publish', true, false) . '> Sell this module in the shop</label></p>';
        echo '<p><label>Price (' . esc_html(get_woocommerce_currency()) . ')<br><input type="text" inputmode="decimal" name="sfm_sell[price]" value="' . esc_attr($price) . '" placeholder="Owner sets the price" style="width:100%"></label></p>';
        echo '<p><label>Link an existing product ID (optional)<br><input type="number" min="1" name="sfm_sell[product]" value="" style="width:100%"></label></p>';
        if ($prod) {
            echo '<p>Linked product: <a href="' . esc_url((string) get_edit_post_link($prod->get_id())) . '">#' . (int) $prod->get_id() . ' ' . esc_html($prod->get_name()) . '</a> (' . esc_html($prod->get_status()) . ')</p>';
        }
        echo '<p class="description">Empty price = not for sale yet (the product stays a draft). Downloads: ' . (int) self::limit() . ' per order, link valid ' . (int) self::expiry() . ' days.</p>';
    }

    public static function limit(): int { return max(0, (int) get_option(self::OPT_LIMIT, self::DEFAULT_LIMIT)); }
    public static function expiry(): int { return max(0, (int) get_option(self::OPT_EXPIRY, self::DEFAULT_EXPIRY)); }

    public static function saveSell(int $id): void {
        if (!self::wooActive() || wp_is_post_autosave($id) || wp_is_post_revision($id)) { return; }
        if (!isset($_POST['_sfm_sell_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_sfm_sell_nonce'])), 'sfm_store_sell_' . $id)) { return; }
        if (!current_user_can('edit_post', $id) || !current_user_can('edit_products')) { return; }
        $in = isset($_POST['sfm_sell']) && is_array($_POST['sfm_sell']) ? wp_unslash($_POST['sfm_sell']) : [];
        if (empty($in['on']) && !self::linkedProduct($id)) { return; } // nothing to sell, nothing linked
        try {
            self::linkProduct($id, !empty($in['on']), (string) ($in['price'] ?? ''), absint($in['product'] ?? 0));
        } catch (\DomainException $e) {
            set_transient('sfm_store_notice_' . get_current_user_id(), $e->getMessage(), 60);
        }
    }

    /** Pure-ish: '' or a clean non-negative decimal string. */
    public static function cleanPrice(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') { return ''; }
        $v = function_exists('wc_format_decimal') ? (string) wc_format_decimal($raw) : $raw;
        if ($v === '' || !is_numeric($v) || (float) $v < 0) { throw new \DomainException('Price must be a number, e.g. 25 or 25.50.'); }
        return $v;
    }

    /**
     * Create or update the WooCommerce product of a module. Returns the product id.
     * $sell false keeps the link but puts the product back to draft. Price '' = not purchasable (draft).
     */
    public static function linkProduct(int $postId, bool $sell, string $price, int $existingId = 0): int {
        if (!self::wooActive()) { throw new \DomainException('WooCommerce is not active.'); }
        $slug = self::slugForModule($postId);
        if ($slug === '') { throw new \DomainException('This module has no release file yet, so it cannot be sold. Set "Release slug" first.'); }
        $price = self::cleanPrice($price);
        $prod = self::linkedProduct($postId);
        if ($existingId > 0) {
            $ex = wc_get_product($existingId);
            if (!$ex instanceof \WC_Product || $ex->get_type() !== 'simple') { throw new \DomainException('Product #' . $existingId . ' is not a simple product.'); }
            $other = (int) $ex->get_meta(self::META_MODULE);
            if ($other > 0 && $other !== $postId) { throw new \DomainException('Product #' . $existingId . ' is already linked to another module.'); }
            $prod = $ex;
        }
        if (!$prod) { $prod = new \WC_Product_Simple(); }
        self::approveBuyDir();
        $prod->set_name((string) get_the_title($postId));
        $prod->set_virtual(true);
        $prod->set_downloadable(true);
        $prod->set_sold_individually(true);
        $prod->set_regular_price($price);
        $prod->set_download_limit(self::limit() ?: -1);
        $prod->set_download_expiry(self::expiry() ?: -1);
        $dl = new \WC_Product_Download();
        $dl->set_id(md5('sfm4u|' . $slug));
        $dl->set_name((string) get_the_title($postId) . ' (ZIP)');
        $dl->set_file(self::buyUrl($slug));
        $prod->set_downloads([$dl]);
        $prod->set_status($sell && $price !== '' ? 'publish' : 'draft');
        $prod->update_meta_data(self::META_MODULE, $postId);
        $pid = (int) $prod->save();
        if ($pid <= 0) { throw new \DomainException('Could not save the product.'); }
        update_post_meta($postId, self::META_PRODUCT, $pid);
        return $pid;
    }

    /** WooCommerce "Approved download directories": allow only our /buy/ endpoint folder. */
    private static function approveBuyDir(): void {
        $cls = 'Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
        if (!function_exists('wc_get_container') || !class_exists($cls)) { return; }
        try {
            $reg = wc_get_container()->get($cls);
            $dir = trailingslashit(rest_url(ReleaseServer::NS . '/buy'));
            $row = $reg->get_by_url($dir);
            if (!$row) { $reg->add_approved_directory($dir, true); } elseif (!$row->is_enabled()) { $reg->enable_by_id($row->get_id()); }
        } catch (\Throwable $e) {
            // keep going: an unapproved file only makes WooCommerce refuse the download, never exposes it
        }
    }

    public static function adminNotices(): void {
        $k = 'sfm_store_notice_' . get_current_user_id();
        $m = get_transient($k);
        if (is_string($m) && $m !== '') { delete_transient($k); echo '<div class="notice notice-error"><p>' . esc_html('Sell this: ' . $m) . '</p></div>'; }
    }

    /** For the single template: price + Buy link when a published, priced product is linked; null otherwise. */
    public static function buyBox(int $postId): ?array {
        $p = self::linkedProduct($postId);
        if (!$p || $p->get_status() !== 'publish' || $p->get_price() === '' || !$p->is_purchasable()) { return null; }
        return ['price_html' => (string) $p->get_price_html(), 'url' => add_query_arg('add-to-cart', $p->get_id(), wc_get_checkout_url()), 'product_id' => $p->get_id()];
    }

    /* ---------------- buyer download (WooCommerce) ---------------- */

    public static function buySig(string $slug, int $order, int $item, int $exp): string {
        return hash_hmac('sha256', 'buy|' . $slug . '|' . $order . '|' . $item . '|' . $exp, ReleaseServer::secret());
    }

    private static function isBuyUrl(string $url): string {
        $base = self::buyUrl('');
        if (!str_starts_with($url, $base)) { return ''; }
        $slug = (string) strtok(substr($url, strlen($base)), '?');
        return preg_match(ReleaseServer::SLUG_RE, $slug) === 1 ? $slug : '';
    }

    /** woocommerce_download_product_filepath: WooCommerce already found the order's download; add a 10-minute signature for that order item. */
    public static function signFilePath($path, $email = '', $order = null, $product = null, $download = null) {
        $slug = self::isBuyUrl((string) $path);
        if ($slug === '' || !$order instanceof \WC_Order || !$product instanceof \WC_Product) { return $path; }
        $item = 0;
        foreach ($order->get_items() as $iid => $it) {
            if ($it instanceof \WC_Order_Item_Product && (int) $it->get_product_id() === (int) $product->get_id()) { $item = (int) $iid; break; }
        }
        if ($item === 0) { return $path; }
        $exp = time() + self::BUY_TTL;
        return add_query_arg(['order' => $order->get_id(), 'item' => $item, 'expires' => $exp, 'sig' => self::buySig($slug, $order->get_id(), $item, $exp)], self::buyUrl($slug));
    }

    /** Our files never use WooCommerce's redirect/X-Sendfile: our own method streams the verified ZIP. */
    public static function downloadMethod($method, $productId = 0, $path = '') {
        return self::isBuyUrl((string) $path) !== '' ? self::METHOD : $method;
    }

    /** Check a signed buyer link. Returns the verified release (+path) or WP_Error. */
    public static function verifyBuy(string $slug, array $q, ?int $now = null) {
        $now = $now ?? time();
        if (!self::wooActive()) { return new WP_Error('sfm4u_shop_off', 'The shop is not open.', ['status' => 503]); }
        $order = (string) ($q['order'] ?? ''); $item = (string) ($q['item'] ?? ''); $exp = (string) ($q['expires'] ?? ''); $sig = (string) ($q['sig'] ?? '');
        if (preg_match(ReleaseServer::SLUG_RE, $slug) !== 1 || !ctype_digit($order) || !ctype_digit($item) || preg_match('/^\d{1,12}$/', $exp) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sig) !== 1) {
            return new WP_Error('sfm4u_bad_link', 'This download link is not valid.', ['status' => 403]);
        }
        if (!hash_equals(self::buySig($slug, (int) $order, (int) $item, (int) $exp), $sig)) { return new WP_Error('sfm4u_bad_signature', 'This download link is not valid.', ['status' => 403]); }
        if ((int) $exp < $now || (int) $exp > $now + self::BUY_TTL + 60) { return new WP_Error('sfm4u_link_expired', 'This download link has expired. Open My Account → Downloads again.', ['status' => 403]); }
        $o = wc_get_order((int) $order);
        if (!$o instanceof \WC_Order || !$o->is_download_permitted()) { return new WP_Error('sfm4u_not_paid', 'This order does not allow downloads.', ['status' => 403]); }
        $it = $o->get_item((int) $item);
        if (!$it instanceof \WC_Order_Item_Product) { return new WP_Error('sfm4u_bad_link', 'This download link is not valid.', ['status' => 403]); }
        $prod = wc_get_product($it->get_product_id());
        $mid = $prod ? (int) $prod->get_meta(self::META_MODULE) : 0;
        if ($mid <= 0 || self::slugForModule($mid) !== $slug) { return new WP_Error('sfm4u_bad_link', 'This download link is not valid.', ['status' => 403]); }
        return ReleaseServer::latestVerified($slug);
    }

    /** woocommerce_download_file_sfm4u: runs after WooCommerce counted the download. */
    public static function wcStream($path, $filename = '') {
        $slug = self::isBuyUrl((string) $path);
        parse_str((string) wp_parse_url((string) $path, PHP_URL_QUERY), $q);
        $r = self::verifyBuy($slug, $q);
        if (is_wp_error($r)) { wp_die(esc_html($r->get_error_message()), '', ['response' => (int) ($r->get_error_data()['status'] ?? 403)]); }
        return self::deliver($r, 'woocommerce');
    }

    public static function routes(): void {
        register_rest_route(ReleaseServer::NS, '/buy/(?P<slug>sfm4u-[a-z0-9-]{2,60})', [
            'methods' => 'GET',
            'callback' => [self::class, 'restBuy'],
            'permission_callback' => '__return_true', // the per-order-item signature is the permission; checked in the callback
        ]);
    }

    public static function restBuy(WP_REST_Request $req) {
        $r = self::verifyBuy((string) $req['slug'], $req->get_query_params());
        if (is_wp_error($r)) { return $r; }
        return self::deliver($r, 'rest');
    }

    /* ---------------- licences ---------------- */

    public static function licenceDays(): int { return max(0, (int) get_option(self::OPT_LICENCE_DAYS, 365)); }

    /** Order completed: one licence per sold module (idempotent per order + slug). Returns rows created. */
    public static function orderCompleted($orderId): int {
        if (!self::wooActive()) { return 0; }
        $o = wc_get_order((int) $orderId);
        if (!$o instanceof \WC_Order) { return 0; }
        global $wpdb;
        $n = 0;
        $days = self::licenceDays();
        foreach ($o->get_items() as $it) {
            if (!$it instanceof \WC_Order_Item_Product) { continue; }
            $prod = wc_get_product($it->get_product_id());
            $mid = $prod ? (int) $prod->get_meta(self::META_MODULE) : 0;
            $slug = $mid > 0 ? self::slugForModule($mid) : '';
            if ($slug === '') { continue; }
            $have = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE order_id = %d AND slug = %s', $o->get_id(), $slug));
            if ($have) {
                $wpdb->update(self::table(), ['status' => 'active'], ['id' => (int) $have], ['%s'], ['%d']);
                continue;
            }
            $plan = sanitize_key((string) $prod->get_meta('_sfm_store_plan')) ?: 'single';
            $row = ['user_id' => (int) $o->get_customer_id(), 'order_id' => $o->get_id(), 'slug' => $slug, 'plan' => $plan, 'status' => 'active', 'site' => '', 'created' => gmdate('Y-m-d H:i:s')];
            $fmt = ['%d', '%d', '%s', '%s', '%s', '%s', '%s'];
            if ($days > 0) { $row['expires'] = gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS); $fmt[] = '%s'; }
            if ($wpdb->insert(self::table(), $row, $fmt)) { $n++; }
        }
        if ($n > 0) { $o->add_order_note(sprintf('SFM4U: %d licence(s) created. The buyer makes a site key in My Account → SFM4U licences.', $n)); }
        return $n;
    }

    /** Refunded / cancelled order: its licences stop working (the key no longer sees those slugs). */
    public static function orderRevoked($orderId): void {
        global $wpdb;
        $wpdb->update(self::table(), ['status' => 'revoked'], ['order_id' => (int) $orderId], ['%s'], ['%d']);
    }

    public static function licences(int $userId): array {
        global $wpdb;
        if ($userId <= 0) { return []; }
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC', $userId), ARRAY_A);
    }

    private static function isLive(array $l): bool {
        return $l['status'] === 'active' && ($l['expires'] === null || $l['expires'] === '' || strtotime($l['expires'] . ' UTC') > time());
    }

    /** Slugs of active, unexpired licences of a user bound to a host. */
    public static function ownedSlugs(int $userId, string $host): array {
        global $wpdb;
        if ($userId <= 0 || $host === '') { return []; }
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT slug, status, expires FROM ' . self::table() . ' WHERE user_id = %d AND site = %s', $userId, $host), ARRAY_A);
        return array_values(array_unique(array_column(array_filter($rows, [self::class, 'isLive']), 'slug')));
    }

    /** sfm4u_releases_site_slugs: a buyer key sees only its owned slugs. */
    public static function siteSlugs($slugs, array $site) {
        return self::ownedSlugs((int) ($site['user_id'] ?? 0), (string) ($site['host'] ?? ''));
    }

    /**
     * Bind a licence to a domain and make a new site key for that user + domain (an older key of the same user + domain is revoked).
     * Returns the key ONCE; only its HMAC is stored. Max 5 per user per hour.
     */
    public static function generateKey(int $userId, int $licenceId, string $host): string {
        global $wpdb;
        if ($userId <= 0) { throw new \DomainException('Please log in.'); }
        $host = ReleaseServer::normalHost($host);
        if ($host === '') { throw new \DomainException('Write your site address, e.g. myshop.com.'); }
        $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d AND user_id = %d', $licenceId, $userId), ARRAY_A);
        if (!$l) { throw new \DomainException('Unknown licence.'); }
        if (!self::isLive($l)) { throw new \DomainException('This licence is not active.'); }
        if ($l['site'] !== '' && $l['site'] !== $host) { throw new \DomainException('This licence is already used on ' . $l['site'] . '. Ask SFM4U to move it.'); }
        $rk = 'sfm_store_keyrate_' . $userId;
        $v = get_transient($rk);
        $now = time();
        if (!is_array($v) || $now - (int) ($v[1] ?? 0) >= HOUR_IN_SECONDS) { $v = [0, $now]; }
        if ((int) $v[0] >= self::KEY_RATE) { throw new \DomainException('Too many keys made. Please try again in an hour.'); }
        set_transient($rk, [(int) $v[0] + 1, (int) $v[1]], max(1, HOUR_IN_SECONDS - ($now - (int) $v[1])));
        foreach (ReleaseServer::sites() as $sid => $s) {
            if ($s['host'] === $host && empty($s['revoked']) && (int) ($s['user_id'] ?? 0) === $userId) { ReleaseServer::revokeSite((string) $sid); }
        }
        if ($l['site'] === '') { $wpdb->update(self::table(), ['site' => $host], ['id' => (int) $l['id']], ['%s'], ['%d']); }
        [, $key] = ReleaseServer::addSite($host, $userId);
        return $key;
    }

    /* ---------------- My Account → SFM4U licences ---------------- */

    public static function queryVars(array $vars): array {
        $vars[self::ENDPOINT] = self::ENDPOINT;
        return $vars;
    }

    public static function accountMenu(array $items): array {
        $out = [];
        foreach ($items as $k => $v) {
            if ($k === 'customer-logout') { $out[self::ENDPOINT] = 'SFM4U licences'; }
            $out[$k] = $v;
        }
        if (!isset($out[self::ENDPOINT])) { $out[self::ENDPOINT] = 'SFM4U licences'; }
        return $out;
    }

    public static function handleKeyForm(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['sfm_lic_key'])) { return; }
        if (!is_user_logged_in()) { self::$keyResult = ['error', 'Please log in.']; return; }
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_sfm_lic_nonce'] ?? '')));
        if (!wp_verify_nonce($nonce, 'sfm_lic_key_' . get_current_user_id())) { self::$keyResult = ['error', 'Please reload the page and try again.']; return; }
        try {
            $key = self::generateKey(get_current_user_id(), absint(wp_unslash((string) ($_POST['licence'] ?? '0'))), sanitize_text_field(wp_unslash((string) ($_POST['site'] ?? ''))));
            self::$keyResult = ['key', $key];
        } catch (\DomainException $e) {
            self::$keyResult = ['error', $e->getMessage()];
        }
    }

    public static function accountPage(): void {
        $uid = get_current_user_id();
        echo '<h3>SFM4U licences / লাইসেন্স</h3>';
        if (self::$keyResult) {
            [$t, $m] = self::$keyResult;
            if ($t === 'key') {
                echo '<div class="woocommerce-message"><p><strong>Your site key (shown only once — copy it now):</strong><br><code style="user-select:all">' . esc_html($m) . '</code></p><p>Paste it on your site in SFM4U Business Software → Module Manager → Updates.</p></div>';
            } else {
                echo '<div class="woocommerce-error"><p>' . esc_html($m) . '</p></div>';
            }
        }
        $rows = self::licences($uid);
        if (!$rows) { echo '<p>You have no SFM4U licences yet. A licence is created when your order is completed.</p>'; return; }
        $nonce = wp_nonce_field('sfm_lic_key_' . $uid, '_sfm_lic_nonce', true, false);
        echo '<table class="shop_table shop_table_responsive"><thead><tr><th>Software</th><th>Order</th><th>Status</th><th>Valid until</th><th>Site</th><th></th></tr></thead><tbody>';
        foreach ($rows as $l) {
            $live = self::isLive($l);
            echo '<tr><td>' . esc_html($l['slug']) . '</td><td>#' . (int) $l['order_id'] . '</td><td>' . esc_html($live ? 'Active' : ucfirst((string) $l['status'] === 'active' ? 'expired' : (string) $l['status'])) . '</td><td>'
                . esc_html($l['expires'] ? substr((string) $l['expires'], 0, 10) : 'No end') . '</td><td>' . esc_html($l['site'] !== '' ? (string) $l['site'] : '—') . '</td><td>';
            if ($live) {
                echo '<form method="post">' . $nonce . '<input type="hidden" name="sfm_lic_key" value="1"><input type="hidden" name="licence" value="' . (int) $l['id'] . '">';
                if ($l['site'] === '') { echo '<input name="site" placeholder="myshop.com" required maxlength="253"> '; } else { echo '<input type="hidden" name="site" value="' . esc_attr((string) $l['site']) . '">'; }
                echo '<button class="button" type="submit">' . esc_html($l['site'] === '' ? 'Make site key' : 'Make new key') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p>One key per site. Making a new key stops the old key of that site. Up to ' . (int) self::KEY_RATE . ' keys per hour.</p>';
    }
}
