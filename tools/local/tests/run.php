<?php
// Utilidades CLI para la prueba: php run.php <accion> [args]
$_SERVER['HTTP_HOST']='localhost:8899'; require '/tmp/claude-0/bk-local/site/wp-load.php';
global $wpdb; $t = $wpdb->prefix.'bk_abandoned_carts';
switch ($argv[1]) {
  case 'rows': echo json_encode($wpdb->get_results("SELECT id,email,first_name,status,emails_sent,consent,clicked_at,order_id,cart_total FROM $t ORDER BY id"), JSON_UNESCAPED_UNICODE), "\n"; break;
  case 'actions': echo json_encode(array_values(array_map(fn($a)=>[$a->get_hook(),$a->get_args(),$a->get_schedule()->get_date()->format('H:i')], as_get_scheduled_actions(['hook'=>'bk_ac_send','status'=>'pending','per_page'=>50])))), "\n"; break;
  case 'send': var_export(bk_ac_send((int)$argv[2], (int)$argv[3])); echo "\n"; break;
  case 'recover': $r=bk_ac_get((int)$argv[2]); echo bk_ac_recover_url($r,(int)$argv[3]), "\n"; break;
  case 'unsub': $r=bk_ac_get((int)$argv[2]); echo bk_ac_unsub_url($r), "\n"; break;
  case 'reset': foreach(wc_get_product(22)->get_children() as $c){ $v=wc_get_product($c); if($v->get_attribute('pa_tallas-plus-size')!=='18'){ $v->set_stock_quantity(5); $v->save(); } }
    $wpdb->query("DELETE FROM $t"); delete_option('bk_ac_optout'); as_unschedule_all_actions('bk_ac_send'); array_map('unlink', glob(WP_CONTENT_DIR.'/uploads/bk-mails/*') ?: []); echo "ok\n"; break;
  case 'settings': update_option('bk_ac_settings', array_merge(bk_ac_settings(), json_decode($argv[2], true))); echo "ok\n"; break;
}
