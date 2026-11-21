<?php
// Run on site3 (WooCommerce + Wholesale). Mock Steadfast/WhatsApp on :8895.
use SFM4U\Wholesale\Ops\{Courier, WhatsApp, OrderPrint};
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
wp_set_current_user(1);
delete_option(Courier::OPT); delete_option(WhatsApp::OPT);
$ok(Courier::bdPhone('+880 1712-345678')==='01712345678' && Courier::bdPhone('০১৭১২৩৪৫৬৭৮')==='01712345678' && Courier::bdPhone('12345')==='', 'bd phone');
$mk = function(array $b = []) {
    $o = wc_create_order();
    $prod = new WC_Product_Simple(); $prod->set_name('Rice 5kg'); $prod->set_regular_price('450'); $prod->save();
    $o->add_product($prod, 2);
    $o->set_billing_first_name($b['name'] ?? 'Karim'); $o->set_billing_last_name('Uddin');
    $o->set_billing_phone($b['phone'] ?? '01712345678'); $o->set_billing_address_1($b['addr'] ?? 'House 1, Road 2'); $o->set_billing_city('Noakhali');
    $o->set_payment_method($b['pm'] ?? 'cod'); $o->calculate_totals(); $o->save();
    return $o;
};
$o = $mk();
$pl = Courier::payload($o);
$ok($pl['recipient_phone']==='01712345678' && (float)$pl['cod_amount']===900.0 && strpos($pl['item_description'],'Rice 5kg x2')!==false && $pl['invoice']==='WC-'.$o->get_order_number(), 'payload');
try { Courier::payload($mk(['phone'=>'123'])); $ok(false,'bad phone accepted'); } catch (DomainException $e) { $ok(true,''); }
try { Courier::book($o); $ok(false,'booked while off'); } catch (DomainException $e) { $ok(true,''); }
try { Courier::save(['enabled'=>'1','key'=>'','secret'=>'']); $ok(false,'on without key'); } catch (DomainException $e) { $ok(true,''); }
try { Courier::save(['enabled'=>'1','key'=>'a','secret'=>'b','base'=>'http://evil.example']); $ok(false,'http base accepted'); } catch (DomainException $e) { $ok(true,''); }
Courier::save(['enabled'=>'1','key'=>'sfkey','secret'=>'wrong','base'=>'http://127.0.0.1:8895/api/v1']);
try { Courier::balance(); $ok(false,'wrong secret ok'); } catch (DomainException $e) { $ok(strpos($e->getMessage(),'refused')!==false,'wrong secret refused'); }
Courier::save(['enabled'=>'1','key'=>'sfkey','secret'=>'sfsecret','base'=>'http://127.0.0.1:8895/api/v1']);
$ok(strpos(wp_json_encode(get_option(Courier::OPT)),'sfsecret')===false, 'secret stored encrypted');
$ok(Courier::balance()==='1250', 'balance');
$i = Courier::book($o);
$ok($i['consignment_id']!=='' && $i['tracking_code']==='TRK'.$i['consignment_id'], 'booked');
$o = wc_get_order($o->get_id());
$ok(Courier::info($o)['consignment_id']===$i['consignment_id'], 'saved on order');
try { Courier::book($o); $ok(false,'double booking'); } catch (DomainException $e) { $ok(true,''); }
$i2 = Courier::refresh($o);
$ok($i2['status']==='delivered', 'status refresh');
$paid = $mk(['pm'=>'bacs']); $paid->set_date_paid(time()); $paid->save();
$ok((float)Courier::payload($paid)['cod_amount']===0.0, 'paid order: no COD');
// auto book on processing
Courier::save(['enabled'=>'1','key'=>'sfkey','secret'=>'','base'=>'http://127.0.0.1:8895/api/v1','auto_status'=>'processing']);
$o3 = $mk(); $o3->update_status('processing'); $o3 = wc_get_order($o3->get_id());
$ok(!empty(Courier::info($o3)['consignment_id']), 'auto booked on processing (secret kept when left empty)');
$o4 = $mk(['phone'=>'555']); $o4->update_status('processing'); $o4 = wc_get_order($o4->get_id());
$notes = wc_get_order_notes(['order_id'=>$o4->get_id()]);
$ok(empty(Courier::info($o4)) && strpos(implode(' ', wp_list_pluck($notes,'content')),'automatic booking failed')!==false, 'auto booking failure noted');
// WhatsApp free link
$l = WhatsApp::link($o);
$ok(strpos($l,'https://wa.me/8801712345678?text=')===0 && strpos(rawurldecode($l),'ট্র্যাকিং: https://steadfast.com.bd/t/TRK')!==false, 'wa.me link with tracking');
// Cloud API
try { WhatsApp::save(['enabled'=>'1']); $ok(false,'wa on without setup'); } catch (DomainException $e) { $ok(true,''); }
WhatsApp::save(['enabled'=>'1','base'=>'http://127.0.0.1:8895','phone_id'=>'106540352242922','token'=>'watoken','template'=>'order_update','lang'=>'bn','checkout_box'=>'1']);
$ok(strpos(wp_json_encode(get_option(WhatsApp::OPT)),'watoken')===false, 'token encrypted');
$o5 = $mk(); $o5->update_status('processing');
$o5 = wc_get_order($o5->get_id());
$ok(empty($o5->get_meta(WhatsApp::SENT)), 'not sent without opt-in');
$o6 = $mk(); $o6->update_meta_data(WhatsApp::OPTIN,'yes'); $o6->save(); $o6->update_status('processing'); $o6->update_status('completed'); $o6->update_status('processing');
$o6 = wc_get_order($o6->get_id()); $sent = $o6->get_meta(WhatsApp::SENT);
$ok(is_array($sent) && isset($sent['processing'],$sent['completed']) && count($sent)===2, 'sent once per event');
$st = json_decode(wp_remote_retrieve_body(wp_remote_get('http://127.0.0.1:8895/_state')), true);
$last = end($st['wa'])['body'];
$ok($last['to']==='8801712345678' && $last['template']['name']==='order_update' && $last['template']['language']['code']==='bn' && count($last['template']['components'][0]['parameters'])===4, 'template body');
$ok(strpos(end($st['wa'])['path'],'/v26.0/106540352242922/messages')===0, 'graph path');
WhatsApp::save(['enabled'=>'1','base'=>'http://127.0.0.1:8895','phone_id'=>'106540352242922','token'=>'bad','template'=>'order_update','lang'=>'bn']);
$o7 = $mk(); $o7->update_meta_data(WhatsApp::OPTIN,'yes'); $o7->save(); $o7->update_status('processing');
$notes = implode(' ', wp_list_pluck(wc_get_order_notes(['order_id'=>$o7->get_id()]),'content'));
$ok(strpos($notes,'not sent')!==false, 'bad token: noted, order not broken');
// slip
$h = OrderPrint::html($o);
$ok(strpos($h,'Rice 5kg')!==false && strpos($h,'TRK')!==false && strpos($h,'order-slip.css')!==false, 'slip html');
delete_option(Courier::OPT); delete_option(WhatsApp::OPT);
echo "pass $p fail $f\n";
