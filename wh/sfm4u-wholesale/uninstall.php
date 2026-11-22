<?php
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Guard: another copy of this plugin may already have loaded the class (two copies installed) — never fatal on uninstall.
if (!class_exists(\SFM4U\Wholesale\PosRoles::class, false)) {
    require_once __DIR__ . '/src/PosRoles.php';
}
\SFM4U\Wholesale\PosRoles::uninstall();
