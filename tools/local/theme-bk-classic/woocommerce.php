<?php
// Imita las plantillas del Theme Builder de Divi: tienda/categorías/búsqueda con [bk_tienda] si el módulo está activo.
get_header();
if ( function_exists( 'bk_on' ) && bk_on( 'shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
	echo do_shortcode( '[bk_tienda]' );
} else {
	woocommerce_content();
}
get_footer();
