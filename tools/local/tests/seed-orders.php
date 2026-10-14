<?php $_SERVER['HTTP_HOST']='localhost:8899'; require '/tmp/claude-0/bk-local/site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/image.php'; require_once ABSPATH.'wp-admin/includes/media.php';
update_option('bk_modules', array_keys(bk_modules()));
require_once BK_DIR.'includes/gestion/api.php'; require_once BK_DIR.'includes/gestion/page.php'; bk_g_activate(['gestion']);
if(!get_option('bk_seed_orders')){
  mt_srand(11); $names=[['Laura','Gómez','Medellín','1036111222'],['Carolina','Ríos','Bogotá','52333444'],['Daniela','Mejía','Cali','1144555666'],['Paula','Restrepo','Envigado','43777888'],['Sofía','Ospina','Barranquilla','1045999000']];
  $prods=wc_get_products(['limit'=>20,'type'=>'variable']); $statuses=['processing','processing','on-hold','completed','pending','processing','completed','cancelled'];
  for($i=0;$i<14;$i++){ $n=$names[$i%5]; $o=wc_create_order(); $p=$prods[$i%count($prods)]; $v=wc_get_product($p->get_children()[mt_rand(0,3)]); if(!$v) continue;
    $o->add_product($v, mt_rand(1,2)); $o->set_billing_first_name($n[0]); $o->set_billing_last_name($n[1]); $o->set_billing_city($n[2]); $o->set_billing_email(strtolower(remove_accents($n[0])).$i.'@ejemplo.com'); $o->set_billing_phone('300'.mt_rand(1000000,9999999));
    $o->set_billing_address_1('Calle '.mt_rand(1,99).' # '.mt_rand(1,99).'-'.mt_rand(1,99)); $o->set_billing_state('CO-ANT'); $o->set_billing_country('CO'); $o->set_payment_method_title(['Bold','Wompi','Addi'][$i%3]);
    $o->update_meta_data('billing_cedula',$n[3]); $o->update_meta_data('_wcdn_invoice_number', 2400+$i); if($i%3===0) $o->update_meta_data('_bk_impreso','yes');
    $o->calculate_totals(); $o->set_status($statuses[$i%count($statuses)]); $o->save(); }
  // Adjunto ACF en un pedido
  $last=wc_get_orders(['limit'=>1,'type'=>'shop_order'])[0]; $img=wc_get_product($prods[0]->get_id())->get_image_id();
  $last->update_meta_data('archivos_adjuntos',1); $last->update_meta_data('archivos_adjuntos_0_archivo',$img); $last->save();
  update_option('bk_seed_orders',1);
}
// Mismas claves y formatos que producción (detector de Ajustes, 2026-10-14): cédula en _billing_id,
// consecutivo WCDN "PEDIDO-NNNN", checks _impreso_status / _despachado_status = yes. Idempotente.
foreach(wc_get_orders(['limit'=>-1,'type'=>'shop_order','orderby'=>'ID','order'=>'ASC']) as $k=>$o){
  if($o->get_meta('_bk_seed_v2')) continue;
  if($o->get_meta('billing_cedula')) $o->update_meta_data('_billing_id',$o->get_meta('billing_cedula'));
  $inv=$o->get_meta('_wcdn_invoice_number'); if($inv!=='' && ctype_digit((string)$inv)) $o->update_meta_data('_wcdn_invoice_number','PEDIDO-'.$inv);
  if($o->get_meta('_bk_impreso')==='yes') $o->update_meta_data('_impreso_status','yes');
  if($k%2===0) $o->update_meta_data('_despachado_status','yes');
  $o->update_meta_data('_bk_seed_v2',1); $o->save();
}
delete_option('bk_gestion_fields'); // Valores por defecto = claves reales.
if(!get_user_by('login','gestora')){ $uid=wp_create_user('gestora','gestora','gestora@ejemplo.com'); (new WP_User($uid))->set_role('bk_gestor'); }
echo "pedidos: ".count(wc_get_orders(['limit'=>-1,'return'=>'ids','type'=>'shop_order']))."\n";
