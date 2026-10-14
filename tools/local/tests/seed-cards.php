<?php $_SERVER['HTTP_HOST']='localhost:8899'; require '/tmp/claude-0/bk-local/site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/image.php'; require_once ABSPATH.'wp-admin/includes/media.php';

$img='/tmp/claude-0/-home-user-BUCKLER-JEANS/ea46fa62-c53e-54bc-bdf1-8467d39c617f/scratchpad/img/';
function att($f){ global $img; $up=wp_upload_dir(); copy($img.$f,$up['path'].'/'.$f); $id=wp_insert_attachment(['post_mime_type'=>'image/jpeg','post_title'=>$f,'post_status'=>'inherit'],$up['path'].'/'.$f); wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$up['path'].'/'.$f)); return $id; }
$gal=['815 PLUS'=>['815P1.jpg','814P.jpg','814P1.jpg'],'9897 PLUS'=>['9897R1.jpg','9901R.jpg'],'9897'=>['9897R.jpg','9901R1.jpg'],'9907'=>['9907R1.jpg','819R.jpg'],'816 PLUS'=>['819R1.jpg']];
foreach($gal as $sku=>$fs){ $p=wc_get_products(['sku'=>$sku,'limit'=>1])[0]; if($p->get_gallery_image_ids()) continue; $p->set_gallery_image_ids(array_map('att',$fs)); if($sku==='815 PLUS') $p->set_featured(true); $p->save(); }
// Oferta en una variación del short
$short=wc_get_products(['sku'=>'9907','limit'=>1])[0]; $v=wc_get_product($short->get_children()[0]); $v->set_sale_price('104930'); $v->save();
// Agotar talla 18 del Flare
$flare=wc_get_products(['sku'=>'815 PLUS','limit'=>1])[0]; foreach($flare->get_children() as $c){ $vv=wc_get_product($c); if($vv->get_attribute('pa_tallas-plus-size')==='18'){ $vv->set_stock_quantity(0); $vv->save(); } }
WC_Product_Variable::sync($short->get_id()); WC_Product_Variable::sync($flare->get_id());
if(!get_page_by_path('lo-nuevo-test')) wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Prueba grilla','post_name'=>'lo-nuevo-test','post_content'=>'[bk_productos titulo="Lo nuevo" ver_todo="/shop/" limite="8" eager="1"]']);
foreach(wc_get_products(['limit'=>-1]) as $p){ $d=bk_card_data($p); echo $p->get_name().' | '.$d['line'].' | '.count($d['images']).' fotos | -'.$d['discount'].'% | '.implode(' ',array_map(fn($s)=>$s['label'].($s['in']?'':'✗'),$d['sizes']))."\n"; }
