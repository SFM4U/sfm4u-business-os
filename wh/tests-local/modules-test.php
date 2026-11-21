<?php
use SFM4U\Wholesale\Modules;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
delete_option(Modules::OPT);
$ok(Modules::on('pos') && Modules::on('wholesale'), 'default all on');
Modules::set('receipt', false);
$ok(!Modules::on('receipt') && Modules::on('pos'), 'turn one off');
try { Modules::set('nope', true); $ok(false,'unknown'); } catch (DomainException $e) { $ok(true,''); }
$ok(Modules::grantValid('', '2026-11-20') && Modules::grantValid('2026-11-20', '2026-11-20') && !Modules::grantValid('2026-11-19', '2026-11-20') && !Modules::grantValid('x', '2026-11-20'), 'grant dates');
$u = get_user_by('login', 'modshop'); if ($u) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
$uid = (int) wp_insert_user(['user_login'=>'modshop','user_pass'=>wp_generate_password(20),'user_email'=>'modshop@example.com','role'=>'customer']);
$ok(!user_can($uid, 'sfm_bos_use_wholesale'), 'no access before grant');
Modules::grant($uid, 'wholesale', '');
$ok(user_can($uid, 'sfm_bos_use_wholesale') && Modules::userHas($uid, 'wholesale'), 'access after grant');
$ok(!user_can($uid, 'manage_woocommerce') && !user_can($uid, 'sfm4u_pos_cashier'), 'grant gives no shop powers');
Modules::grant($uid, 'receipt', '');
$ok(!user_can($uid, 'sfm_bos_use_receipt'), 'system off = no access even with grant');
Modules::grant($uid, 'pos', '2020-01-01');
$ok(!user_can($uid, 'sfm_bos_use_pos'), 'expired grant');
$ok(count(Modules::grantList()) >= 3, 'grant list');
Modules::revoke($uid, 'wholesale');
$ok(!user_can($uid, 'sfm_bos_use_wholesale'), 'revoked');
$ok(user_can(1, 'sfm_bos_use_pos') && !user_can(1, 'sfm_bos_use_receipt'), 'admin has on-systems only');
try { Modules::grant($uid, 'pos', '31/12/2026'); $ok(false,'bad date'); } catch (DomainException $e) { $ok(true,''); }
$s = Modules::posStatus();
$ok($s['other_dropin'] && !$s['dropin'], 'sqlite drop-in seen as other: ' . json_encode($s));
try { Modules::posInstall(); $ok(false, 'installed over other drop-in'); } catch (DomainException $e) { $ok(strpos($e->getMessage(), 'Another plugin') === 0, 'refuses other db.php'); }
wp_set_current_user(1); ob_start(); Modules::page(); $h = ob_get_clean();
$ok(substr_count($h, 'sfm-bos-card') >= 7 && strpos($h, 'SFM4U Wholesale System') !== false && strpos($h, 'SFM4U Receipt System') !== false && strpos($h, 'Another db.php') !== false, 'systems page');
delete_option(Modules::OPT);
echo "pass $p fail $f\n";
