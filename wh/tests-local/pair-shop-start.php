<?php
use SFM4U\Wholesale\Members\CardLink;
wp_set_current_user(1);
update_option(CardLink::OPT_ROLE, 'off');
delete_option(CardLink::OPT_MASTER);
$u = CardLink::startPair('http://127.0.0.1:8899');
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
file_put_contents('/tmp/pair.json', json_encode($q));
echo (strpos($u, 'http://127.0.0.1:8899/wp-admin/admin.php?') === 0 && strlen($q['state']) === 32 ? "ok start\n" : "FAIL start $u\n");
