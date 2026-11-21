<?php
use SFM4U\Wholesale\Members\{CardGuide, MembersAdmin};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
wp_set_current_user(1); delete_option(CardGuide::OPTION);
$h = CardGuide::html();
$ok(strpos($h,'শর্তাবলি ও নিষেধ')!==false && strpos($h,'এটা সরকারি পরিচয়পত্র নয়')!==false && strpos($h,'SAMPLE MEMBER')!==false, 'default guide renders with sample card');
$ok(count(CardGuide::defaults())===9, '9 sections');
CardGuide::saveSection('terms', 'নিয়ম', "এক\n\n দুই <script>x</script>\n");
$s = CardGuide::sections();
$ok($s['terms']['items']===['এক','দুই'] && $s['terms']['title']==='নিয়ম' && $s['terms']['edited'], 'save one section, cleaned');
$ok($s['lost']['edited']===false && $s['privacy']['items']===CardGuide::defaults()['privacy']['items'], 'other sections untouched');
CardGuide::saveSection('lost', 'হারালে', "ক");
$ok(CardGuide::sections()['terms']['items']===['এক','দুই'], 'second save keeps first edit');
$ok(strpos(CardGuide::html(),'<script>')===false, 'no script in output');
try { CardGuide::saveSection('terms','',''); $ok(false,'empty accepted'); } catch (DomainException $e) { $ok(true,''); }
try { CardGuide::saveSection('nope','a','b'); $ok(false,'unknown accepted'); } catch (DomainException $e) { $ok(true,''); }
CardGuide::resetSection('terms');
$ok(CardGuide::sections()['terms']['edited']===false && CardGuide::sections()['lost']['edited']===true, 'reset one only');
require_once ABSPATH.'wp-admin/includes/template.php';
ob_start(); MembersAdmin::guidePage(); $a=ob_get_clean();
$ok(substr_count($a,'name="sfm_wr_guide_save"')===9 && strpos($a,'Restore default')!==false, 'admin page: 8 forms');
delete_option(CardGuide::OPTION);
echo "pass $p fail $f\n";
