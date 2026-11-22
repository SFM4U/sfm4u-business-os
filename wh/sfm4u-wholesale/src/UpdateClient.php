<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;

defined('ABSPATH') || exit;

/**
 * CL-2 update client (Owner 2026-11-22): this site gets SFM4U updates from marketplace.sfm4u.com.
 *
 * - Settings (Module Manager → Updates): marketplace URL and this site's key (option, autoload no; never printed back).
 * - Every 12 hours (wp-cron) and on "Check now": GET <marketplace>/wp-json/sfm4u-releases/v1/check with the versions of
 *   sfm4u-wholesale and every installed sfm4u-module-* plugin.
 * - Three separate lists: Core update, Module updates, New modules.
 * - Install (admin click): download → SHA-256 must match → ModuleManager ZIP rules → old version saved → install;
 *   on failure the old version is put back.
 * - WordPress native updates: the core main file has "Update URI: https://marketplace.sfm4u.com", so
 *   Dashboard → Updates also shows it through the filter update_plugins_marketplace.sfm4u.com.
 */
final class UpdateClient {
    public const OPT_URL = 'sfm_uc_url';
    public const OPT_KEY = 'sfm_uc_key';
    public const OPT_STATE = 'sfm_uc_state';
    public const DEFAULT_URL = 'https://marketplace.sfm4u.com';
    public const UPDATE_HOST = 'marketplace.sfm4u.com';
    public const CRON = 'sfm4u_uc_check';
    public const CORE = 'sfm4u-wholesale';
    public const ROUTE = '/wp-json/sfm4u-releases/v1/';

    public static function register(): void {
        add_action('init', [self::class, 'schedule']);
        add_action(self::CRON, [self::class, 'cronCheck']);
        add_action('admin_init', [self::class, 'actions']);
        add_filter('update_plugins_' . self::UPDATE_HOST, [self::class, 'native'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'preDownload'], 10, 4);
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 300, 'twicedaily', self::CRON); }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON);
    }

    /* ---------------- settings ---------------- */

    public static function url(): string {
        $u = (string) get_option(self::OPT_URL, '');
        return $u !== '' ? $u : self::DEFAULT_URL;
    }

    /** Pure: a marketplace URL must be https with a host and nothing else. Returns the clean base URL or ''. */
    public static function cleanUrl(string $u): string {
        $u = trim($u);
        $p = wp_parse_url($u);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) { return ''; }
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        return 'https://' . strtolower((string) $p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $path;
    }

    public static function key(): string {
        return (string) get_option(self::OPT_KEY, '');
    }

    public static function saveSettings(string $url, ?string $key, bool $removeKey = false): void {
        $clean = self::cleanUrl($url === '' ? self::DEFAULT_URL : $url);
        if ($clean === '') { throw new DomainException('The marketplace address must start with https://'); }
        self::setOpt(self::OPT_URL, $clean);
        if ($removeKey) { delete_option(self::OPT_KEY); return; }
        if ($key !== null && $key !== '') {
            if (preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $key) !== 1) { throw new DomainException('This does not look like a site key.'); }
            self::setOpt(self::OPT_KEY, $key);
        }
    }

    /** Save an option with autoload off. */
    private static function setOpt(string $name, $value): void {
        if (get_option($name, null) === null) { add_option($name, $value, '', false); return; }
        update_option($name, $value, false);
    }

    public static function siteHost(): string {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    /* ---------------- installed versions ---------------- */

    /** Folder of the running core plugin (sfm4u-wholesale on live sites). */
    public static function coreFolder(): string {
        return basename(dirname(__DIR__));
    }

    /** @return array<string,string> slug => version (core first, then every sfm4u-module-* plugin) */
    public static function installed(): array {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $core = defined('SFM4U_WH_VERSION') ? (string) SFM4U_WH_VERSION : '';
        $out = [self::CORE => $core];
        foreach (get_plugins() as $file => $data) {
            $folder = dirname((string) $file);
            if (preg_match('/^sfm4u-module-[a-z0-9-]{2,40}$/', $folder) === 1 && $file === $folder . '/' . $folder . '.php') {
                $out[$folder] = (string) ($data['Version'] ?? '0');
            }
        }
        return $out;
    }

    /* ---------------- check ---------------- */

    /**
     * Pure: turn a /check answer into three lists. Anything not newer, not well-formed, or pointing at another host is dropped.
     * @return array{core:list<array>,modules:list<array>,new:list<array>}
     */
    public static function lists(array $answer, array $installed, string $base): array {
        $out = ['core' => [], 'modules' => [], 'new' => []];
        $host = strtolower((string) wp_parse_url($base, PHP_URL_HOST));
        $coreVer = (string) ($installed[self::CORE] ?? '');
        foreach ((array) ($answer['updates'] ?? []) as $u) {
            if (!is_array($u)) { continue; }
            $slug = (string) ($u['slug'] ?? '');
            $ver = (string) ($u['version'] ?? '');
            $sha = strtolower((string) ($u['sha256'] ?? ''));
            $pkg = (string) ($u['package_url'] ?? '');
            if (!isset($installed[$slug]) || preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,31}$/', $ver) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) { continue; }
            if (!version_compare($ver, $installed[$slug], '>')) { continue; }
            $p = wp_parse_url($pkg);
            if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || strtolower((string) ($p['host'] ?? '')) !== $host) { continue; }
            $req = isset($u['requires_core']) && is_string($u['requires_core']) && $u['requires_core'] !== '' ? $u['requires_core'] : '';
            $row = ['slug' => $slug, 'installed' => $installed[$slug], 'version' => $ver, 'sha256' => $sha, 'size' => (int) ($u['size'] ?? 0), 'requires_core' => $req, 'package_url' => $pkg,
                'blocked' => ($slug !== self::CORE && $req !== '' && version_compare($coreVer, $req, '<')) ? 'Needs SFM4U Business Software ' . $req . ' first.' : ''];
            $out[$slug === self::CORE ? 'core' : 'modules'][] = $row;
        }
        foreach ((array) ($answer['new'] ?? []) as $n) {
            if (!is_array($n)) { continue; }
            $slug = (string) ($n['slug'] ?? '');
            if (isset($installed[$slug]) || preg_match('/^sfm4u-module-[a-z0-9-]{2,40}$/', $slug) !== 1) { continue; }
            $out['new'][] = ['slug' => $slug, 'code' => (string) ($n['code'] ?? ''), 'name' => (string) ($n['name'] ?? $slug), 'version' => (string) ($n['version'] ?? '')];
        }
        return $out;
    }

    /** Raw call to /check. Returns the decoded answer or throws. */
    public static function fetch(array $installed): array {
        $key = self::key();
        if ($key === '') { throw new DomainException('Add this site\'s key first (Module Manager → Updates).'); }
        $items = [];
        foreach ($installed as $slug => $ver) { if ($ver !== '') { $items[] = $slug . ':' . $ver; } }
        $url = add_query_arg(['site' => self::siteHost(), 'items' => implode(',', $items)], self::url() . self::ROUTE . 'check');
        $r = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true, 'redirection' => 0, 'headers' => ['X-SFM4U-Site-Key' => $key, 'Accept' => 'application/json']]);
        if (is_wp_error($r)) { throw new DomainException('Could not reach the marketplace: ' . $r->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($r);
        $body = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code !== 200 || !is_array($body)) {
            $why = is_array($body) && isset($body['message']) ? (string) $body['message'] : 'HTTP ' . $code;
            throw new DomainException('The marketplace said no: ' . $why);
        }
        return $body;
    }

    /** Check now; saves and returns the state {core, modules, new, checked, error}. */
    public static function check(): array {
        $installed = self::installed();
        try {
            $state = self::lists(self::fetch($installed), $installed, self::url()) + ['checked' => time(), 'error' => ''];
        } catch (DomainException $e) {
            $old = self::state();
            $state = ['core' => $old['core'], 'modules' => $old['modules'], 'new' => $old['new'], 'checked' => time(), 'error' => $e->getMessage()];
        }
        self::setOpt(self::OPT_STATE, $state);
        delete_site_transient('update_plugins'); // Dashboard → Updates picks the new state up
        return $state;
    }

    public static function cronCheck(): void {
        if (self::key() !== '') { self::check(); }
    }

    public static function state(): array {
        $s = get_option(self::OPT_STATE, []);
        $s = is_array($s) ? $s : [];
        return ['core' => (array) ($s['core'] ?? []), 'modules' => (array) ($s['modules'] ?? []), 'new' => (array) ($s['new'] ?? []), 'checked' => (int) ($s['checked'] ?? 0), 'error' => (string) ($s['error'] ?? '')];
    }

    /* ---------------- install ---------------- */

    /** Download one package, check its SHA-256 and the ZIP rules. Returns the temp file path (caller deletes it). */
    public static function download(array $item): string {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url((string) $item['package_url'], 120);
        if (is_wp_error($tmp)) { throw new DomainException('Download failed: ' . $tmp->get_error_message()); }
        $sha = (string) hash_file('sha256', $tmp);
        if (!hash_equals((string) $item['sha256'], $sha)) {
            @unlink($tmp);
            throw new DomainException('The downloaded file does not match its checksum (SHA-256). Nothing was installed.');
        }
        $slug = (string) $item['slug'];
        try {
            [$folder, , $ver] = ModuleManager::inspectZip($tmp, $slug === self::CORE);
            if ($folder !== $slug) { throw new DomainException('The package holds "' . $folder . '", not "' . $slug . '". Nothing was installed.'); }
            if ($ver !== (string) $item['version']) { throw new DomainException('The package is version ' . $ver . ', expected ' . $item['version'] . '. Nothing was installed.'); }
        } catch (DomainException $e) {
            @unlink($tmp);
            throw $e;
        }
        return $tmp;
    }

    /** Find a fresh item for a slug (asks the marketplace again so the signed link is new). */
    public static function freshItem(string $slug): array {
        $state = self::check();
        if ($state['error'] !== '') { throw new DomainException($state['error']); }
        foreach (array_merge($state['core'], $state['modules']) as $u) {
            if ($u['slug'] === $slug) { return $u; }
        }
        throw new DomainException('No update is waiting for ' . $slug . '.');
    }

    /** Admin click: install the update (or new module) for $slug. Returns a message. */
    public static function installSlug(string $slug): string {
        if (preg_match('/^sfm4u-(wholesale|module-[a-z0-9-]{2,40})$/', $slug) !== 1) { throw new DomainException('Unknown item.'); }
        if ($slug === self::CORE && self::coreFolder() !== ModuleManager::CORE_FOLDER) {
            throw new DomainException('This copy of SFM4U Business Software runs from folder "' . self::coreFolder() . '", so it cannot be updated from the marketplace here.');
        }
        $item = isset(self::installed()[$slug]) ? self::freshItem($slug) : self::newItem($slug);
        if (($item['blocked'] ?? '') !== '') { throw new DomainException((string) $item['blocked']); }
        return self::installItem($item);
    }

    /** Install one checked item (already fresh). */
    public static function installItem(array $item): string {
        $tmp = self::download($item);
        try {
            return ModuleManager::install($tmp, (string) $item['slug'] === self::CORE);
        } finally {
            @unlink($tmp);
        }
    }

    /** A new module: ask the marketplace with "slug:0" so it answers with a signed link for that module. */
    private static function newItem(string $slug): array {
        $installed = self::installed() + [$slug => '0'];
        $lists = self::lists(self::fetch($installed), $installed, self::url());
        foreach ($lists['modules'] as $u) { if ($u['slug'] === $slug) { return $u; } }
        throw new DomainException('This module is not available on the marketplace.');
    }

    /* ---------------- WordPress native updates ---------------- */

    /** Filter update_plugins_marketplace.sfm4u.com: our version + package for Dashboard → Updates. */
    public static function native($update, $pluginData, $pluginFile) {
        $folder = dirname((string) $pluginFile);
        $slug = $folder === self::coreFolder() && basename((string) $pluginFile) === 'sfm4u-wholesale.php' ? self::CORE : $folder;
        if ($slug === self::CORE && self::coreFolder() !== ModuleManager::CORE_FOLDER) { return $update; }
        $state = self::state();
        foreach (array_merge($state['core'], $state['modules']) as $u) {
            if (($u['slug'] ?? '') === $slug && ($u['blocked'] ?? '') === '') {
                return ['slug' => $slug, 'version' => (string) $u['version'], 'url' => self::url(), 'package' => (string) $u['package_url']];
            }
        }
        return $update;
    }

    /** Native update: get a fresh signed link, check SHA-256 + ZIP rules, save the old version, then hand WordPress the file. */
    public static function preDownload($reply, $package, $upgrader, $hookExtra = []) {
        if ($reply !== false || !is_string($package) || strpos($package, self::url() . self::ROUTE . 'download') !== 0) { return $reply; }
        $q = [];
        parse_str((string) wp_parse_url($package, PHP_URL_QUERY), $q);
        $slug = (string) ($q['slug'] ?? '');
        try {
            $item = self::freshItem($slug);
            $tmp = self::download($item);
            $folder = $slug === self::CORE ? ModuleManager::CORE_FOLDER : $slug;
            if (is_dir(WP_PLUGIN_DIR . '/' . $folder)) { ModuleManager::backup($folder, ModuleManager::currentVersion($folder) ?: (string) ($item['installed'] ?? 'old')); }
            return $tmp;
        } catch (DomainException $e) {
            return new \WP_Error('sfm4u_update_failed', $e->getMessage());
        }
    }

    /* ---------------- admin ---------------- */

    public static function actions(): void {
        if (($_GET['page'] ?? '') !== ModuleManager::SLUG || empty($_POST['sfm_uc']) || !current_user_can('manage_options')) { return; }
        check_admin_referer('sfm_uc');
        $k = 'sfm_mm_msg_' . get_current_user_id();
        try {
            $act = sanitize_key(wp_unslash((string) $_POST['sfm_uc']));
            if ($act === 'settings') {
                $key = isset($_POST['site_key']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['site_key']))) : null;
                self::saveSettings(esc_url_raw(wp_unslash((string) ($_POST['market_url'] ?? ''))), $key, !empty($_POST['remove_key']));
                $msg = 'Update settings saved.';
            } elseif ($act === 'check') {
                $s = self::check();
                if ($s['error'] !== '') { throw new DomainException($s['error']); }
                $msg = sprintf('Checked: %d core update, %d module updates, %d new modules.', count($s['core']), count($s['modules']), count($s['new']));
            } elseif ($act === 'install') {
                if (!current_user_can('install_plugins')) { throw new DomainException('You may not install plugins.'); }
                $msg = self::installSlug(sanitize_text_field(wp_unslash((string) ($_POST['slug'] ?? ''))));
                self::check();
            } else { return; }
            set_transient($k, ['success', $msg], 300);
        } catch (DomainException $e) {
            set_transient($k, ['error', $e->getMessage()], 300);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . ModuleManager::SLUG));
        exit;
    }

    private static function button(string $slug, string $label, string $nonce, bool $primary = true): string {
        return '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="sfm_uc" value="install"><input type="hidden" name="slug" value="' . esc_attr($slug) . '"><button class="button' . ($primary ? ' button-primary' : '') . '" onclick="return confirm(\'Install now? The old version is saved first.\')">' . esc_html($label) . '</button></form>';
    }

    /** Drawn inside the Module Manager page. */
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $s = self::state();
        $nonce = wp_nonce_field('sfm_uc', '_wpnonce', true, false);
        $can = current_user_can('install_plugins');
        echo '<h2>Updates from marketplace / মার্কেটপ্লেস থেকে আপডেট</h2>';
        echo '<p>Last check: ' . ($s['checked'] ? esc_html(wp_date('Y-m-d H:i', $s['checked'])) : 'never') . '. Checks run every 12 hours.';
        if ($s['error'] !== '') { echo ' <span style="color:#b32d2e">' . esc_html($s['error']) . '</span>'; }
        echo '</p><form method="post" style="margin-bottom:12px">' . $nonce . '<input type="hidden" name="sfm_uc" value="check"><button class="button">Check now</button></form>';

        echo '<h3>Core update</h3>';
        if (!$s['core']) { echo '<p>SFM4U Business Software is up to date.</p>'; }
        foreach ($s['core'] as $u) {
            echo '<p><strong>SFM4U Business Software</strong> ' . esc_html($u['installed'] . ' → ' . $u['version']) . ' ' . ($can ? self::button($u['slug'], 'Update core', $nonce) : '') . '</p>';
        }
        echo '<h3>Module updates</h3>';
        if (!$s['modules']) { echo '<p>All modules are up to date.</p>'; } else {
            echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Installed</th><th>New</th><th></th></tr></thead><tbody>';
            foreach ($s['modules'] as $u) {
                echo '<tr><td>' . esc_html($u['slug']) . '</td><td>' . esc_html($u['installed']) . '</td><td>' . esc_html($u['version']) . '</td><td>'
                    . ($u['blocked'] !== '' ? esc_html($u['blocked']) : ($can ? self::button($u['slug'], 'Update', $nonce) : '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h3>New modules</h3>';
        if (!$s['new']) { echo '<p>No new modules.</p>'; } else {
            echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Code</th><th>Version</th><th></th></tr></thead><tbody>';
            foreach ($s['new'] as $n) {
                echo '<tr><td>' . esc_html($n['name']) . '<br><small>' . esc_html($n['slug']) . '</small></td><td>' . esc_html($n['code']) . '</td><td>' . esc_html($n['version']) . '</td><td>' . ($can ? self::button($n['slug'], 'Install', $nonce, false) : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>Update settings</h3><form method="post">' . $nonce . '<input type="hidden" name="sfm_uc" value="settings"><table class="form-table">'
            . '<tr><th><label for="sfm_uc_url">Marketplace address</label></th><td><input class="regular-text" id="sfm_uc_url" name="market_url" value="' . esc_attr(self::url()) . '"></td></tr>'
            . '<tr><th><label for="sfm_uc_key">Site key</label></th><td><input class="regular-text" type="password" id="sfm_uc_key" name="site_key" value="" autocomplete="new-password" placeholder="' . (self::key() !== '' ? '•••••••• (saved, hidden)' : 'Paste the key from marketplace → Update sites') . '">'
            . (self::key() !== '' ? ' <label><input type="checkbox" name="remove_key" value="1"> Remove key</label>' : '') . '<p class="description">Leave empty to keep the saved key. The key is never shown again.</p></td></tr>'
            . '</table><button class="button button-primary">Save</button></form>';
    }
}
