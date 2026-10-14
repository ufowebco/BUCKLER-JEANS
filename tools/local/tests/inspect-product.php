<?php
/** Uso: php inspect-product.php "<REF>" → JSON con lo que quedó guardado (para productos.js). */
require '/tmp/claude-0/bk-local/site/wp-load.php';
$id = wc_get_product_id_by_sku( $argv[1] ?? '' );
$p  = $id ? wc_get_product( $id ) : null;
if ( ! $p ) {
	echo 'null';
	exit;
}
$vars = array();
foreach ( $p->get_children() as $vid ) {
	$v      = wc_get_product( $vid );
	$vars[] = array( 'size' => implode( ',', $v->get_attributes() ), 'status' => $v->get_status(), 'stock' => $v->get_stock_quantity(), 'regular' => $v->get_regular_price(), 'sale' => $v->get_sale_price() );
}
$imgs = array();
foreach ( array_merge( array( $p->get_image_id( 'edit' ) ), $p->get_gallery_image_ids( 'edit' ) ) as $aid ) {
	$m      = wp_get_attachment_metadata( $aid );
	$imgs[] = array(
		'id'     => (int) $aid,
		'mime'   => get_post_mime_type( $aid ),
		'alt'    => get_post_meta( $aid, '_wp_attachment_image_alt', true ),
		'parent' => wp_get_post_parent_id( $aid ),
		'sizes'  => isset( $m['sizes'] ) ? array_values( array_unique( wp_list_pluck( $m['sizes'], 'mime-type' ) ) ) : array(),
	);
}
echo wp_json_encode(
	array(
		'id'       => $id,
		'type'     => $p->get_type(),
		'status'   => $p->get_status(),
		'name'     => $p->get_name(),
		'featured' => $p->is_featured(),
		'cats'     => wp_list_pluck( array_map( static fn( $c ) => get_term( $c, 'product_cat' ), $p->get_category_ids() ), 'slug' ),
		'attr'     => array_keys( $p->get_attributes() ),
		'vars'     => $vars,
		'images'   => $imgs,
		'video'    => (int) $p->get_meta( '_bk_video_id' ),
		'desc'     => $p->get_description(),
		'short'    => $p->get_short_description(),
		'url'      => $p->get_permalink(),
		'price'    => $p->get_price(),
	),
	JSON_UNESCAPED_UNICODE
);
