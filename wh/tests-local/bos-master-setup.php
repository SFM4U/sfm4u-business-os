<?php
use SFM4U\Wholesale\Members\{Members, CardLink, MemberLink};
wp_set_current_user(1);
[$id, $secret] = CardLink::addClient('127.0.0.1:8894');
foreach (['m1@example.com' => 'Market Member', 'twin1@example.com' => 'Twin'] as $e => $n) {
    $u = get_user_by('email', $e);
    $uid = $u ? $u->ID : (int) wp_insert_user(['user_login' => strtok($e, '@'), 'user_pass' => wp_generate_password(20), 'user_email' => $e, 'display_name' => $n]);
    if (!Members::cardsOf($uid)) { Members::issue($uid, 'regular', 0, 0, 'main', null, '', 'main'); }
    delete_user_meta($uid, MemberLink::META_PENDING);
}
$m1 = get_user_by('email', 'm1@example.com')->ID;
update_user_meta($m1, 'billing_phone', '01711000111');
delete_option(MemberLink::OPT_REQUESTS);
$ne = get_user_by('email', 'newseller@example.com'); if ($ne) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($ne->ID); }
file_put_contents('/tmp/bos.json', json_encode(['id' => $id, 'secret' => $secret, 'm1' => $m1, 'no' => Members::memberNo($m1)]));
echo "master ok " . Members::memberNo($m1) . "\n";
