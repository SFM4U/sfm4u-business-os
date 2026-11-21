<?php
use SFM4U\Wholesale\Members\{MemberLink, Members};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
Members::install(); wp_set_current_user(1);
// link-finish is a MASTER endpoint: run as master regardless of earlier two-site tests, restore afterwards.
$oldRole=get_option(\SFM4U\Wholesale\Members\CardLink::OPT_ROLE); update_option(\SFM4U\Wholesale\Members\CardLink::OPT_ROLE,'master');
$r=wp_rand(10000,99999); $u=(int)wp_insert_user(['user_login'=>"lk$r",'user_pass'=>wp_generate_password(20),'user_email'=>"lk$r@example.com"]);
Members::issue($u,'business',0,1);
$no=Members::memberNo($u); $id='shop_test1';
update_user_meta($u, MemberLink::META_PENDING, [$id=>['code'=>'123456','host'=>'x','exp'=>time()+600,'tries'=>0]]);
$req=function($code) use ($no,$id){ $q=new WP_REST_Request('POST','/sfm4u-card/v1/link-finish'); $q->set_header('x_sfm4u_key',$id); $q->set_body(wp_json_encode(['who'=>$no,'code'=>$code])); return MemberLink::finish($q); };
$lock='sfm_wr_link_lock_'.$u.'_'.md5($id);
add_option($lock, time(), '', 'no');
$ok($req('123456')->get_status()===422 && strpos($req('123456')->get_data()['message'],'try again')!==false, 'held lock -> try again');
delete_option($lock);
add_option($lock, time()-60, '', 'no');
$ok($req('123456')->get_status()===200, 'stale lock cleared, code works once');
$ok($req('123456')->get_status()===422, 'same code cannot be used twice');
$ok(get_option($lock, null)===null, 'lock released');
$oldRole!==false ? update_option(\SFM4U\Wholesale\Members\CardLink::OPT_ROLE,$oldRole) : delete_option(\SFM4U\Wholesale\Members\CardLink::OPT_ROLE);
echo "pass $p fail $f\n";
