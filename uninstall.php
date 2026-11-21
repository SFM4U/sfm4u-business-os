<?php
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/PosRoles.php';
\SFM4U\Wholesale\PosRoles::uninstall();
