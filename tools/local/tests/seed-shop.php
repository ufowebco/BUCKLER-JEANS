<?php $_SERVER['HTTP_HOST']='localhost:8899'; require '/tmp/claude-0/bk-local/site/wp-load.php';
if (get_option('bk_seed_shop')) { echo "ya\n"; exit; }
mt_srand(7);
$snap=json_decode(file_get_contents('/home/user/BUCKLER-JEANS/snapshots/products_all.json'),true);
$imgs=array_values(array_filter(array_map(fn($p)=>wc_get_product($p)->get_image_id(), wc_get_products(['limit'=>-1,'return'=>'ids']))));
$gal=[]; foreach(wc_get_products(['limit'=>-1]) as $p) $gal=array_merge($gal,$p->get_gallery_image_ids());
$attrs=['tallas-regular'=>['6','8','10','12'],'tallas-plus-size'=>['14','16','18','20','22','24']];
$catmap=['jeans-linea-slim','pantalones-linea-slim','prendas-cortas','faldas','jeans','pantalones','shorts','sets','faldas-plus-size','set-y-overol','chaquetas-y-chalecos','chaquetas-y-chalecos-plus','linea-nueva','sale','linea-slim','linea-curvy'];
// crea categorías faltantes con su padre
$parents=['set-y-overol'=>'linea-slim','chaquetas-y-chalecos'=>'linea-slim','faldas-plus-size'=>'linea-curvy','chaquetas-y-chalecos-plus'=>'linea-curvy'];
$names=['set-y-overol'=>'SET Y OVEROL','chaquetas-y-chalecos'=>'CHAQUETAS Y CHALECOS','faldas-plus-size'=>'FALDAS','chaquetas-y-chalecos-plus'=>'CHAQUETAS Y CHALECOS'];
foreach($parents as $s=>$par){ if(!get_term_by('slug',$s,'product_cat')){ wp_insert_term($names[$s],'product_cat',['slug'=>$s,'parent'=>get_term_by('slug',$par,'product_cat')->term_id]); } }
$n=0;
foreach($snap as $sp){
  if(!$sp['price'] || $n>=60) continue;
  $slugs=array_values(array_filter(array_map(fn($c)=>$c['slug'],$sp['categories']),fn($s)=>get_term_by('slug',$s,'product_cat')));
  $isplus=in_array('linea-curvy',$slugs,true); $attr=$isplus?'tallas-plus-size':'tallas-regular';
  if(!in_array('linea-curvy',$slugs)&&!in_array('linea-slim',$slugs)) continue;
  $p=new WC_Product_Variable(); $p->set_name($sp['name']); $p->set_sku($sp['sku'].'-L'.$n); $p->set_status('publish');
  $p->set_category_ids(array_map(fn($s)=>get_term_by('slug',$s,'product_cat')->term_id,$slugs));
  $p->set_image_id($imgs[$n%count($imgs)]); $p->set_gallery_image_ids([$gal[$n%count($gal)],$gal[($n+3)%count($gal)]]);
  $p->set_date_created(time()-$n*86400);
  $a=new WC_Product_Attribute(); $a->set_id(wc_attribute_taxonomy_id_by_name($attr)); $a->set_name('pa_'.$attr); $a->set_options($attrs[$attr]); $a->set_visible(true); $a->set_variation(true);
  $p->set_attributes([$a]); $pid=$p->save();
  foreach($attrs[$attr] as $sz){ $v=new WC_Product_Variation(); $v->set_parent_id($pid); $v->set_attributes(['pa_'.$attr=>$sz]); $v->set_regular_price((string)($sp['regular_price']?:$sp['price'])); if($sp['on_sale']&&$sp['sale_price']) $v->set_sale_price((string)$sp['sale_price']); $v->set_manage_stock(true); $v->set_stock_quantity(mt_rand(0,3)); $v->save(); }
  WC_Product_Variable::sync($pid); update_post_meta($pid,'total_sales',mt_rand(0,40)); $n++;
}
// regenerar tabla de lookup de meta (precio/oferta/stock)
global $wpdb; $ids=wc_get_products(['limit'=>-1,'return'=>'ids']);
foreach($ids as $id){ $p=wc_get_product($id); $p->save(); }
update_option('bk_seed_shop',1);
foreach(['tienda-test'=>['Tienda (prueba)','[bk_tienda]'],'linea-curvy'=>null,'sales'=>null] as $slug=>$d){
  $page=get_page_by_path($slug);
  if($slug==='tienda-test' && !$page) wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$d[0],'post_name'=>$slug,'post_content'=>$d[1]]);
  if($slug==='linea-curvy' && $page) wp_update_post(['ID'=>$page->ID,'post_content'=>'[bk_tienda categoria="linea-curvy"]']);
  if($slug==='sales' && $page) wp_update_post(['ID'=>$page->ID,'post_content'=>'[bk_tienda oferta="1" titulo="¡Sale!"]']);
}
echo "creados $n · total ".count($ids)." · lookup ".$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup}")."\n";
