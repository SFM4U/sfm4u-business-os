<?php
declare(strict_types=1);
namespace SFM4U\Wholesale;

use DomainException;
use ZipArchive;

defined('ABSPATH') || exit;

/**
 * Module Manager (A02, Owner 2026-11-22).
 * - Lists every module: version, where it comes from (built in / add-on), ON/OFF, its dashboard.
 * - Add or update a module from a ZIP. ONLY SFM4U modules are accepted: the ZIP must hold one folder "sfm4u-module-…"
 *   whose main file says "Plugin Name: SFM4U Module …". Anything else is refused (no outside software).
 * - Before an update the current version is saved as a ZIP; "Roll back" puts the last saved version back.
 */
final class ModuleManager {
    public const SLUG = 'sfm-bos-manager';
    public const BACKUP_DIR = 'sfm4u-module-backups';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu'], 21);
        add_action('admin_init', [self::class, 'actions']);
    }

    public static function menu(): void {
        add_submenu_page(Modules::SLUG, 'Module Manager', 'Module Manager', 'manage_options', self::SLUG, [self::class, 'page']);
    }

    public static function backupDir(): string {
        return WP_CONTENT_DIR . '/' . self::BACKUP_DIR;
    }

    /* ---------------- module list ---------------- */

    /** @return list<array{slug:string,name:string,bn:string,version:string,source:string,on:bool,file:string}> */
    public static function list(): array {
        if (!function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $core = defined('SFM4U_WH_VERSION') ? (string) SFM4U_WH_VERSION : (string) (get_plugin_data(dirname(__DIR__) . '/sfm4u-wholesale.php', false, false)['Version'] ?? '');
        $out = [];
        foreach (Modules::all() as $slug => $d) {
            $row = ['slug' => (string) $slug, 'name' => (string) $d[0], 'bn' => (string) $d[1], 'version' => $core, 'source' => 'Built in', 'on' => Modules::on((string) $slug), 'file' => ''];
            $class = $d[4] ?? '';
            if (is_string($class) && $class !== '' && class_exists($class)) {
                $file = (string) (new \ReflectionClass($class))->getFileName();
                $main = self::addonMainFile($file);
                if ($main !== '') {
                    $data = get_plugin_data($main, false, false);
                    $row['version'] = (string) ($data['Version'] ?? '?');
                    $row['source'] = 'Add-on';
                    $row['file'] = plugin_basename($main);
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** The add-on's main plugin file for a class file inside wp-content/plugins/sfm4u-module-*, or ''. */
    public static function addonMainFile(string $classFile): string {
        $plugins = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        $f = wp_normalize_path($classFile);
        if (strpos($f, $plugins) !== 0) { return ''; }
        $folder = explode('/', substr($f, strlen($plugins)))[0];
        if (strpos($folder, 'sfm4u-module-') !== 0) { return ''; }
        $main = $plugins . $folder . '/' . $folder . '.php';
        return is_file($main) ? $main : '';
    }

    /* ---------------- ZIP check ---------------- */

    public const CORE_FOLDER = 'sfm4u-wholesale';

    /** Is this a folder name the Module Manager may replace? Core only when $allowCore (marketplace core update). */
    public static function folderAllowed(string $folder, bool $allowCore = false): bool {
        return preg_match('/^sfm4u-module-[a-z0-9-]{2,40}$/', $folder) === 1 || ($allowCore && $folder === self::CORE_FOLDER);
    }

    /**
     * Pure-ish: read a ZIP and accept it only if it is one SFM4U module. Returns [folder, name, version].
     * $allowCore (CL-2 marketplace updates only): the ZIP may instead be the core folder "sfm4u-wholesale"
     * whose main file says "Plugin Name: SFM4U Business Software …".
     */
    public static function inspectZip(string $zip, bool $allowCore = false): array {
        if (!class_exists(ZipArchive::class)) { throw new DomainException('ZIP support (ZipArchive) is missing on this server.'); }
        $z = new ZipArchive();
        if ($z->open($zip) !== true) { throw new DomainException('This is not a ZIP file.'); }
        $tops = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string) $z->getNameIndex($i);
            if ($n === '' || strpos($n, '__MACOSX/') === 0) { continue; }
            if (strpos($n, '..') !== false || $n[0] === '/' || strpos($n, '\\') !== false) { $z->close(); throw new DomainException('The ZIP has unsafe file paths.'); }
            $tops[explode('/', $n)[0]] = true;
        }
        if (count($tops) !== 1) { $z->close(); throw new DomainException('The ZIP must contain exactly one module folder.'); }
        $folder = (string) array_key_first($tops);
        if (!self::folderAllowed($folder, $allowCore)) { $z->close(); throw new DomainException('Only SFM4U modules can be added (folder must be "sfm4u-module-…").'); }
        $isCore = $folder === self::CORE_FOLDER;
        $main = $z->getFromName($folder . '/' . $folder . '.php');
        $z->close();
        if (!is_string($main) || $main === '') { throw new DomainException('Main file ' . $folder . '/' . $folder . '.php is missing.'); }
        $head = substr($main, 0, 8192);
        $nameRe = $isCore ? '/^[ \t\/*#@]*Plugin Name:\s*(SFM4U Business Software[^\r\n]*)/mi' : '/^[ \t\/*#@]*Plugin Name:\s*(SFM4U Module[^\r\n]*)/mi';
        if (!preg_match($nameRe, $head, $m)) { throw new DomainException($isCore ? 'This is not the SFM4U Business Software core ZIP.' : 'Only SFM4U modules can be added ("Plugin Name: SFM4U Module …").'); }
        $ver = preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $head, $v) ? trim($v[1]) : '0';
        return [$folder, trim($m[1]), $ver];
    }

    /* ---------------- backup / roll back ---------------- */

    /** Save an installed module folder as a ZIP before it is replaced. Returns the ZIP path. */
    public static function backup(string $folder, string $version): string {
        $src = WP_PLUGIN_DIR . '/' . $folder;
        if (!is_dir($src)) { throw new DomainException('Module folder not found.'); }
        if (!wp_mkdir_p(self::backupDir())) { throw new DomainException('Could not create the backup folder.'); }
        @file_put_contents(self::backupDir() . '/index.php', "<?php // Silence.\n");
        @file_put_contents(self::backupDir() . '/.htaccess', "Require all denied\nDeny from all\n");
        $zipPath = self::backupDir() . '/' . $folder . '__' . preg_replace('/[^0-9A-Za-z.\-]/', '_', $version) . '__' . gmdate('Ymd-His') . '-' . wp_rand(100, 999) . '.zip';
        $z = new ZipArchive();
        if ($z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new DomainException('Could not write the backup.'); }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) { $z->addFile($f->getPathname(), $folder . '/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($src))), '/')); }
        }
        $z->close();
        return $zipPath;
    }

    /** Saved versions of a module, newest first. */
    public static function backups(string $folder): array {
        $l = glob(self::backupDir() . '/' . $folder . '__*.zip') ?: [];
        usort($l, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
        return $l;
    }

    /** Put the newest saved version back (the version being replaced is saved first, so nothing is lost). */
    public static function rollback(string $folder, bool $allowCore = false): string {
        if (!self::folderAllowed($folder, $allowCore)) { throw new DomainException('Unknown module.'); }
        $list = self::backups($folder);
        if (!$list) { throw new DomainException('No saved version to roll back to.'); }
        $target = $list[0];
        [, , $ver] = self::inspectZip($target, $allowCore);
        $dst = WP_PLUGIN_DIR . '/' . $folder;
        if (is_dir($dst)) {
            $cur = self::currentVersion($folder);
            self::backup($folder, $cur . '-replaced');
            self::rrmdir($dst);
        }
        $z = new ZipArchive();
        if ($z->open($target) !== true || !$z->extractTo(WP_PLUGIN_DIR)) { throw new DomainException('Could not restore the saved version.'); }
        $z->close();
        @unlink($target);
        return $ver;
    }

    public static function currentVersion(string $folder): string {
        $main = WP_PLUGIN_DIR . '/' . $folder . '/' . $folder . '.php';
        if (!is_file($main)) { return ''; }
        if (!function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        return (string) (get_plugin_data($main, false, false)['Version'] ?? '');
    }

    private static function rrmdir(string $d): void {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($d);
    }

    /**
     * Install or update a checked SFM4U module ZIP. Returns a message.
     * The old version is saved first; if the install fails, it is put back (roll back) automatically.
     */
    public static function install(string $zip, bool $allowCore = false): string {
        [$folder, $name, $ver] = self::inspectZip($zip, $allowCore);
        $existing = is_dir(WP_PLUGIN_DIR . '/' . $folder);
        if ($existing) { self::backup($folder, self::currentVersion($folder) ?: 'old'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $up = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $ok = $up->install($zip, ['overwrite_package' => true]);
        if ($ok !== true || !is_file(WP_PLUGIN_DIR . '/' . $folder . '/' . $folder . '.php')) {
            $why = 'Install failed' . (is_wp_error($ok) ? ': ' . $ok->get_error_message() : '.');
            if ($existing) {
                try { self::rollback($folder, $allowCore); $why .= ' The old version was put back.'; } catch (DomainException $e) { $why .= ' Roll back also failed: ' . $e->getMessage(); }
            }
            throw new DomainException($why);
        }
        $file = $folder . '/' . $folder . '.php';
        if (!is_plugin_active($file)) {
            $r = activate_plugin($file);
            if (is_wp_error($r)) { throw new DomainException('Installed, but could not switch it on: ' . $r->get_error_message()); }
        }
        return sprintf('%s %s %s.', $name, $ver, $existing ? 'updated (old version saved for roll back)' : 'added');
    }

    /* ---------------- admin ---------------- */

    public static function actions(): void {
        if (($_GET['page'] ?? '') !== self::SLUG || empty($_POST['sfm_mm']) || !current_user_can('manage_options') || !current_user_can('install_plugins')) { return; }
        check_admin_referer('sfm_mm');
        $k = 'sfm_mm_msg_' . get_current_user_id();
        try {
            $act = sanitize_key(wp_unslash((string) $_POST['sfm_mm']));
            if ($act === 'upload') {
                $f = $_FILES['module_zip'] ?? null;
                if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) { throw new DomainException('Choose a module ZIP file.'); }
                if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'zip') { throw new DomainException('The file must be a .zip.'); }
                $msg = self::install((string) $f['tmp_name']);
            } elseif ($act === 'rollback') {
                $folder = sanitize_key(wp_unslash((string) ($_POST['folder'] ?? '')));
                $msg = sprintf('Rolled back to version %s.', self::rollback($folder));
            } else { return; }
            set_transient($k, ['success', $msg], 300);
        } catch (DomainException $e) {
            set_transient($k, ['error', $e->getMessage()], 300);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not allowed.', 'sfm4u-wholesale')); }
        $k = 'sfm_mm_msg_' . get_current_user_id();
        echo '<div class="wrap"><h1>Module Manager <small style="font-weight:400;color:#9fb5ad">/ মডিউল ম্যানেজার</small></h1>'
            . '<p>All modules of SFM4U Business Software in one place. Add or update a module from a ZIP, or roll back to the saved version. Only SFM4U modules are accepted. / শুধু SFM4U-এর নিজের মডিউল যোগ করা যায়।</p>';
        if (is_array($m = get_transient($k))) { delete_transient($k); echo '<div class="notice notice-' . esc_attr((string) $m[0]) . '"><p>' . esc_html((string) $m[1]) . '</p></div>'; }
        echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Version</th><th>Source</th><th>Status</th><th></th></tr></thead><tbody>';
        $nonce = wp_nonce_field('sfm_mm', '_wpnonce', true, false);
        foreach (self::list() as $r) {
            $folder = $r['file'] !== '' ? dirname($r['file']) : '';
            echo '<tr><td><strong>' . esc_html($r['name']) . '</strong><br><small>' . esc_html($r['bn']) . '</small></td><td>' . esc_html($r['version']) . '</td><td>' . esc_html($r['source']) . '</td><td>'
                . ($r['on'] ? '<span style="color:#00E0B0">● ON</span>' : '<span style="color:#b32d2e">● OFF</span>') . '</td><td><a class="button" href="' . esc_url(ModuleHome::url($r['slug'])) . '">Open dashboard</a>';
            if ($folder !== '' && self::backups($folder) && current_user_can('install_plugins')) {
                echo ' <form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="sfm_mm" value="rollback"><input type="hidden" name="folder" value="' . esc_attr($folder) . '"><button class="button" onclick="return confirm(\'Roll back to the saved version?\')">Roll back</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (class_exists(UpdateClient::class)) { UpdateClient::render(); }
        if (current_user_can('install_plugins')) {
            echo '<h2>Add or update a module / মডিউল যোগ বা আপডেট</h2><form method="post" enctype="multipart/form-data">' . $nonce
                . '<input type="hidden" name="sfm_mm" value="upload"><input type="file" name="module_zip" accept=".zip" required> <button class="button button-primary">Upload module ZIP</button></form>'
                . '<p class="description">The ZIP must contain one folder named sfm4u-module-… with "Plugin Name: SFM4U Module …". When a module is updated, the old version is saved first so you can roll back.</p>';
        }
        echo '</div>';
    }
}
