<?php
use SFM4U\Wholesale\Members\{Members, CardLink};
update_option(CardLink::OPT_ROLE, 'master');
[$id, $secret] = CardLink::addClient('local shop test');
$login = 'central' . wp_rand(1000, 9999);
$uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'user_email' => $login . '@example.com', 'display_name' => 'Central Only']);
update_user_meta($uid, 'sfm_wr_name_en', 'CENTRAL ONLY');
update_user_meta($uid, 'sfm_wr_dob', '1999-09-09');
update_user_meta($uid, 'sfm_wr_address', 'Feni');
file_put_contents('/tmp/link.json', json_encode(['id' => $id, 'secret' => $secret, 'no' => Members::memberNo((int) $uid)]));
echo "master ready, member " . Members::memberNo((int) $uid) . "\n";
