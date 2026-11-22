<?php
declare(strict_types=1);
namespace SFM4U\ModuleStore;

use WP_Error;
use WP_REST_Request;

defined('ABSPATH') || exit;

/**
 * CL-2 Update server (Owner 2026-11-22): our sites ask marketplace.sfm4u.com for updates of SFM4U software.
 *
 * - Release store: WP_CONTENT_DIR/sfm4u-release-store/ with releases.json [{slug, code, version, sha256, size, requires_core?, name?}]
 *   and one ZIP per release named "<slug>-<version>.zip". The folder is denied by .htaccess; no raw path is ever sent out.
 * - GET  /wp-json/sfm4u-releases/v1/check?site=<host>&items=slug:version,...   header X-SFM4U-Site-Key
 *        → {updates:[{slug, version, sha256, size, requires_core, package_url}], new:[{slug, code, name, version}], checked}
 * - GET  /wp-json/sfm4u-releases/v1/download?slug&version&site&expires&sig     signed link, valid 10 minutes, streams the ZIP.
 * - Site keys: wp-admin → SFM4U Business Software → Update sites. A key is shown once; only its HMAC is stored.
 * - CL-3: a key made by a buyer from a licence (user_id > 0) only sees the slugs that filter
 *   'sfm4u_releases_site_slugs' returns for it (Sales: active licences bound to that host). Owner keys (user_id 0) see everything.
 */
final class ReleaseServer {
    public const NS = 'sfm4u-releases/v1';
    public const OPT_SECRET = 'sfm_rs_secret';
    public const OPT_SITES = 'sfm_rs_sites';
    public const PAGE = 'sfm-rs-sites';
    public const STORE = 'sfm4u-release-store';
    public const LINK_TTL = 600;          // signed download link: 10 minutes
    public const RATE_LIMIT = 60;         // /check calls per site per hour
    public const MAX_ITEMS = 100;
    public const SLUG_RE = '/^sfm4u-(wholesale|module-[a-z0-9-]{2,40})$/';
    public const VER_RE = '/^[0-9A-Za-z][0-9A-Za-z.+-]{0,31}$/';
    public const HOST_RE = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'menu'], 30);
    }

    /* ---------------- store + secret ---------------- */

    public static function storeDir(): string {
        return (string) apply_filters('sfm4u_release_store_dir', WP_CONTENT_DIR . '/' . self::STORE);
    }

    /** Create the store folder with deny rules (activation, and whenever it is missing). */
    public static function protectStore(): void {
        $d = self::storeDir();
        if (!is_dir($d) && !wp_mkdir_p($d)) { return; }
        if (!is_file($d . '/.htaccess')) { @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
        if (!is_file($d . '/index.php')) { @file_put_contents($d . '/index.php', "<?php // Silence.\n"); }
    }

    public static function activate(): void {
        self::secret();
        self::protectStore();
    }

    /** Signing secret: a WP option made once with wp_generate_password(64); never in code. */
    public static function secret(): string {
        $s = (string) get_option(self::OPT_SECRET, '');
        if (strlen($s) < 64) {
            $s = wp_generate_password(64, true, true);
            delete_option(self::OPT_SECRET);
            add_option(self::OPT_SECRET, $s, '', false);
        }
        return $s;
    }

    /** @return list<array{slug:string,code:string,version:string,sha256:string,size:int,requires_core:?string,name:string}> */
    public static function releases(): array {
        $f = self::storeDir() . '/releases.json';
        if (!is_file($f)) { return []; }
        $rows = json_decode((string) file_get_contents($f), true);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r)) { continue; }
            $slug = (string) ($r['slug'] ?? '');
            $ver = (string) ($r['version'] ?? '');
            $sha = strtolower((string) ($r['sha256'] ?? ''));
            if (preg_match(self::SLUG_RE, $slug) !== 1 || preg_match(self::VER_RE, $ver) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) { continue; }
            $req = isset($r['requires_core']) && is_string($r['requires_core']) && preg_match(self::VER_RE, $r['requires_core']) === 1 ? $r['requires_core'] : null;
            $out[] = ['slug' => $slug, 'code' => (string) ($r['code'] ?? ''), 'version' => $ver, 'sha256' => $sha, 'size' => (int) ($r['size'] ?? 0), 'requires_core' => $req, 'name' => (string) ($r['name'] ?? '')];
        }
        return $out;
    }

    /** Pure: newest release per slug. @return array<string,array> */
    public static function latest(array $releases): array {
        $out = [];
        foreach ($releases as $r) {
            if (!isset($out[$r['slug']]) || version_compare($r['version'], $out[$r['slug']]['version'], '>')) { $out[$r['slug']] = $r; }
        }
        return $out;
    }

    /**
     * One release whose ZIP exists and whose sha256 still matches releases.json; WP_Error otherwise.
     * Returns the release plus its internal 'path' (never sent out).
     */
    public static function verifiedRelease(string $slug, string $version) {
        foreach (self::releases() as $r) {
            if ($r['slug'] === $slug && $r['version'] === $version) {
                $path = self::zipPath($slug, $version);
                if (!is_file($path) || !hash_equals($r['sha256'], (string) hash_file('sha256', $path))) { break; }
                return $r + ['path' => $path];
            }
        }
        return new WP_Error('sfm4u_release_missing', 'This release is not available right now.', ['status' => 404]);
    }

    /** Newest release of a slug, verified as above (CL-3: admin download, buyer download). */
    public static function latestVerified(string $slug) {
        if (preg_match(self::SLUG_RE, $slug) !== 1) { return new WP_Error('sfm4u_bad_slug', 'Unknown release.', ['status' => 400]); }
        $l = self::latest(self::releases());
        if (!isset($l[$slug])) { return new WP_Error('sfm4u_release_missing', 'This release is not available right now.', ['status' => 404]); }
        return self::verifiedRelease($slug, $l[$slug]['version']);
    }

    /** Absolute ZIP path of one release (internal only, never sent out). */
    public static function zipPath(string $slug, string $version): string {
        return self::storeDir() . '/' . $slug . '-' . $version . '.zip';
    }

    /* ---------------- site keys ---------------- */

    public static function keyHash(string $key): string {
        return hash_hmac('sha256', $key, self::secret());
    }

    public static function normalHost(string $h): string {
        $h = strtolower(trim($h));
        $h = (string) preg_replace('#^https?://#', '', $h);
        $h = explode('/', $h)[0];
        $h = explode(':', $h)[0];
        return preg_match(self::HOST_RE, $h) === 1 ? $h : '';
    }

    /** @return array<string,array{host:string,hash:string,hint:string,created:int,revoked:int,user_id?:int}> */
    public static function sites(): array {
        $s = get_option(self::OPT_SITES, []);
        return is_array($s) ? $s : [];
    }

    private static function saveSites(array $s): void {
        if (get_option(self::OPT_SITES, null) === null) { add_option(self::OPT_SITES, $s, '', false); return; }
        update_option(self::OPT_SITES, $s, false);
    }

    /**
     * Add a site; returns [id, key]. The key is returned ONCE and only its HMAC is stored.
     * $userId 0 = Owner/admin key (sees every release); > 0 = buyer key made from a licence (sees owned slugs only).
     */
    public static function addSite(string $host, int $userId = 0): array {
        $host = self::normalHost($host);
        if ($host === '') { throw new \DomainException('Write a valid host name, e.g. algibazar.com.'); }
        foreach (self::sites() as $s) {
            if ($s['host'] === $host && empty($s['revoked']) && (int) ($s['user_id'] ?? 0) === $userId) { throw new \DomainException('This site already has an active key. Revoke it first to make a new one.'); }
        }
        $key = 'sfm4u_' . wp_generate_password(40, false, false);
        $id = substr(hash('sha256', $host . microtime() . wp_rand()), 0, 12);
        $sites = self::sites();
        $sites[$id] = ['host' => $host, 'hash' => self::keyHash($key), 'hint' => substr($key, -4), 'created' => time(), 'revoked' => 0, 'user_id' => max(0, $userId)];
        self::saveSites($sites);
        return [$id, $key];
    }

    public static function revokeSite(string $id): void {
        $sites = self::sites();
        if (!isset($sites[$id])) { throw new \DomainException('Unknown site.'); }
        $sites[$id]['revoked'] = time();
        self::saveSites($sites);
    }

    /** Find the active site of a key, or null. */
    public static function siteForKey(string $key): ?array {
        if (strlen($key) < 20 || strlen($key) > 200) { return null; }
        $h = self::keyHash($key);
        foreach (self::sites() as $id => $s) {
            if (empty($s['revoked']) && hash_equals((string) $s['hash'], $h)) { return $s + ['id' => (string) $id]; }
        }
        return null;
    }

    /** null = every slug (Owner key); list = only these slugs (buyer key from a licence; none without the filter). */
    public static function allowedSlugs(array $site): ?array {
        if ((int) ($site['user_id'] ?? 0) === 0) { return null; }
        $slugs = apply_filters('sfm4u_releases_site_slugs', [], $site);
        return array_values(array_filter(is_array($slugs) ? $slugs : [], static fn($x) => is_string($x) && preg_match(self::SLUG_RE, $x) === 1));
    }

    /** May some active key of this host get this slug? (signed links carry only the host) */
    private static function hostMaySee(string $host, string $slug): bool {
        foreach (self::sites() as $id => $s) {
            if ($s['host'] !== $host || !empty($s['revoked'])) { continue; }
            $allowed = self::allowedSlugs($s + ['id' => (string) $id]);
            if ($allowed === null || in_array($slug, $allowed, true)) { return true; }
        }
        return false;
    }

    /* ---------------- rate limit ---------------- */

    /** Count one /check call; false when the site used up its hour. */
    public static function hit(string $siteId): bool {
        $k = 'sfm_rs_rate_' . $siteId;
        $v = get_transient($k);
        $now = time();
        if (!is_array($v) || $now - (int) ($v[1] ?? 0) >= HOUR_IN_SECONDS) { $v = [0, $now]; }
        if ((int) $v[0] >= self::RATE_LIMIT) { return false; }
        $v[0] = (int) $v[0] + 1;
        set_transient($k, $v, max(1, HOUR_IN_SECONDS - ($now - (int) $v[1])));
        return true;
    }

    /* ---------------- signing ---------------- */

    public static function sign(string $slug, string $version, string $site, int $expires): string {
        return hash_hmac('sha256', $slug . '|' . $version . '|' . $site . '|' . $expires, self::secret());
    }

    public static function packageUrl(string $slug, string $version, string $site, ?int $now = null): string {
        $exp = ($now ?? time()) + self::LINK_TTL;
        return add_query_arg(['slug' => $slug, 'version' => $version, 'site' => $site, 'expires' => $exp, 'sig' => self::sign($slug, $version, $site, $exp)], rest_url(self::NS . '/download'));
    }

    /** Check a signed link. Returns the release (with its internal path) or WP_Error. */
    public static function verifyDownload(array $p, ?int $now = null) {
        $now = $now ?? time();
        $slug = (string) ($p['slug'] ?? '');
        $ver = (string) ($p['version'] ?? '');
        $site = (string) ($p['site'] ?? '');
        $exp = (string) ($p['expires'] ?? '');
        $sig = (string) ($p['sig'] ?? '');
        if (preg_match(self::SLUG_RE, $slug) !== 1 || preg_match(self::VER_RE, $ver) !== 1 || self::normalHost($site) !== $site || preg_match('/^\d{1,12}$/', $exp) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sig) !== 1) {
            return new WP_Error('sfm4u_bad_link', 'This download link is not valid.', ['status' => 400]);
        }
        if (!hash_equals(self::sign($slug, $ver, $site, (int) $exp), $sig)) {
            return new WP_Error('sfm4u_bad_signature', 'This download link is not valid.', ['status' => 403]);
        }
        if ((int) $exp < $now || (int) $exp > $now + self::LINK_TTL + 60) {
            return new WP_Error('sfm4u_link_expired', 'This download link has expired. Check for updates again.', ['status' => 403]);
        }
        if (!self::hostMaySee($site, $slug)) {
            return new WP_Error('sfm4u_site_revoked', 'This site may not download updates.', ['status' => 403]);
        }
        return self::verifiedRelease($slug, $ver);
    }

    /* ---------------- check ---------------- */

    /** Pure: "slug:version,slug:version" → [slug => version] (bad items dropped). */
    public static function parseItems(string $items): array {
        $out = [];
        foreach (array_slice(explode(',', $items), 0, self::MAX_ITEMS) as $it) {
            $parts = explode(':', trim($it), 2);
            if (count($parts) !== 2) { continue; }
            [$slug, $ver] = [trim($parts[0]), trim($parts[1])];
            if (preg_match(self::SLUG_RE, $slug) === 1 && preg_match(self::VER_RE, $ver) === 1) { $out[$slug] = $ver; }
        }
        return $out;
    }

    /** Build the /check answer for one site. $allowed null = all slugs, else only these (licence keys). */
    public static function answer(array $installed, string $site, ?int $now = null, ?array $allowed = null): array {
        $updates = [];
        $new = [];
        foreach (self::latest(self::releases()) as $slug => $r) {
            if ($allowed !== null && !in_array($slug, $allowed, true)) { continue; }
            if (isset($installed[$slug])) {
                if (version_compare($r['version'], $installed[$slug], '>')) {
                    $updates[] = ['slug' => $slug, 'version' => $r['version'], 'sha256' => $r['sha256'], 'size' => $r['size'], 'requires_core' => $r['requires_core'], 'package_url' => self::packageUrl($slug, $r['version'], $site, $now)];
                }
            } elseif (str_starts_with($slug, 'sfm4u-module-')) {
                $new[] = ['slug' => $slug, 'code' => $r['code'], 'name' => self::displayName($r), 'version' => $r['version']];
            }
        }
        return ['updates' => $updates, 'new' => $new, 'checked' => gmdate('c', $now ?? time())];
    }

    private static function displayName(array $r): string {
        if ($r['code'] !== '' && post_type_exists(CPT)) {
            $ids = get_posts(['post_type' => CPT, 'post_status' => 'publish', 'meta_key' => '_sfm_code', 'meta_value' => $r['code'], 'fields' => 'ids', 'numberposts' => 1]);
            if ($ids) { return (string) get_the_title((int) $ids[0]); }
        }
        return $r['name'] !== '' ? $r['name'] : $r['slug'];
    }

    /* ---------------- REST ---------------- */

    public static function routes(): void {
        register_rest_route(self::NS, '/check', [
            'methods' => 'GET',
            'callback' => [self::class, 'restCheck'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => ['items' => ['type' => 'string', 'required' => false], 'site' => ['type' => 'string', 'required' => false]],
        ]);
        register_rest_route(self::NS, '/download', [
            'methods' => 'GET',
            'callback' => [self::class, 'restDownload'],
            'permission_callback' => '__return_true', // the signed link is the permission; checked in the callback
        ]);
    }

    /** The site key is the permission for /check; the rate limit is counted here too. */
    public static function checkPermission(WP_REST_Request $req) {
        $site = self::siteForKey((string) $req->get_header('X-SFM4U-Site-Key'));
        if ($site === null) { return new WP_Error('sfm4u_bad_key', 'Unknown or revoked site key.', ['status' => 401]); }
        $asked = (string) $req->get_param('site');
        if ($asked !== '' && self::normalHost($asked) !== $site['host']) { return new WP_Error('sfm4u_wrong_site', 'This key belongs to another site.', ['status' => 403]); }
        if (!self::hit($site['id'])) { return new WP_Error('sfm4u_rate_limited', 'Too many update checks. Try again in an hour.', ['status' => 429]); }
        $req->set_param('_sfm_site', $site['host']);
        $req->set_param('_sfm_site_id', $site['id']);
        return true;
    }

    public static function restCheck(WP_REST_Request $req) {
        $host = (string) $req->get_param('_sfm_site');
        $id = (string) $req->get_param('_sfm_site_id');
        $sites = self::sites();
        $allowed = isset($sites[$id]) ? self::allowedSlugs($sites[$id] + ['id' => $id]) : [];
        $res = rest_ensure_response(self::answer(self::parseItems((string) $req->get_param('items')), $host, null, $allowed));
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    public static function restDownload(WP_REST_Request $req) {
        $r = self::verifyDownload($req->get_query_params());
        if (is_wp_error($r)) { return $r; }
        $pre = apply_filters('sfm4u_releases_pre_stream', null, $r); // tests: stop before sending the file
        if ($pre !== null) { return $pre; }
        self::stream($r['path'], $r['slug'] . '-' . $r['version'] . '.zip');
        exit;
    }

    /** Send the ZIP through PHP (never a redirect to the raw file). */
    public static function stream(string $path, string $name): void {
        while (ob_get_level() > 0) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    /* ---------------- admin: Update sites ---------------- */

    public static function menu(): void {
        add_submenu_page('edit.php?post_type=' . CPT, 'Update sites', 'Update sites', 'manage_options', self::PAGE, [self::class, 'page']);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-module-store')); }
        self::protectStore();
        $msg = null;
        $newKey = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sfm_rs'])) {
            check_admin_referer('sfm_rs_sites');
            try {
                $act = sanitize_key(wp_unslash((string) $_POST['sfm_rs']));
                if ($act === 'add') {
                    [, $newKey] = self::addSite(sanitize_text_field(wp_unslash((string) ($_POST['host'] ?? ''))));
                    $msg = ['success', 'Site added. Copy the key now: it is shown only once.'];
                } elseif ($act === 'revoke') {
                    self::revokeSite(sanitize_key(wp_unslash((string) ($_POST['id'] ?? ''))));
                    $msg = ['success', 'Key revoked. That site can no longer check or download updates.'];
                }
            } catch (\DomainException $e) {
                $msg = ['error', $e->getMessage()];
            }
        }
        echo '<div class="wrap"><h1>Update sites <small style="font-weight:400">/ আপডেট সাইট</small></h1>'
            . '<p>Each SFM4U site that gets updates from this marketplace needs its own site key. The key is shown once; only a fingerprint is kept here. Paste the key on the site in SFM4U Business Software → Module Manager → Updates.</p>';
        if ($msg) { echo '<div class="notice notice-' . esc_attr($msg[0]) . '"><p>' . esc_html($msg[1]) . '</p></div>'; }
        if ($newKey !== '') {
            echo '<div class="notice notice-warning"><p><strong>Site key (shown once):</strong> <code style="user-select:all">' . esc_html($newKey) . '</code></p></div>';
        }
        $nonce = wp_nonce_field('sfm_rs_sites', '_wpnonce', true, false);
        echo '<h2>Add a site</h2><form method="post">' . $nonce . '<input type="hidden" name="sfm_rs" value="add"><input class="regular-text" name="host" placeholder="algibazar.com" required> <button class="button button-primary">Make key</button></form>';
        echo '<h2>Sites</h2><table class="widefat striped"><thead><tr><th>Site</th><th>Made by</th><th>Key ends</th><th>Added</th><th>Status</th><th></th></tr></thead><tbody>';
        $sites = self::sites();
        if (!$sites) { echo '<tr><td colspan="6">No sites yet.</td></tr>'; }
        foreach ($sites as $id => $s) {
            $uid = (int) ($s['user_id'] ?? 0);
            $u = $uid > 0 ? get_userdata($uid) : null;
            echo '<tr><td>' . esc_html($s['host']) . '</td><td>' . esc_html($uid === 0 ? 'Owner (all releases)' : 'Buyer licence: ' . ($u ? $u->user_login : '#' . $uid)) . '</td><td>…' . esc_html((string) $s['hint']) . '</td><td>' . esc_html(gmdate('Y-m-d', (int) $s['created'])) . '</td><td>'
                . (empty($s['revoked']) ? 'Active' : 'Revoked ' . esc_html(gmdate('Y-m-d', (int) $s['revoked']))) . '</td><td>';
            if (empty($s['revoked'])) {
                echo '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="sfm_rs" value="revoke"><input type="hidden" name="id" value="' . esc_attr((string) $id) . '"><button class="button" onclick="return confirm(\'Revoke this key?\')">Revoke</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $rel = self::releases();
        echo '<h2>Release store</h2><p>' . esc_html(sprintf('%d releases in releases.json (%d items).', count($rel), count(self::latest($rel)))) . ' Signed download links last 10 minutes; each site may check 60 times per hour.</p></div>';
    }
}
