<?php
/**
 * Instala WP + Woo + buckler-core con datos parecidos a buckler.com.co (nombres, precios y tallas reales
 * tomados del catálogo vía MCP). Idempotente. Uso: php tools/local/install.php <ruta_sitio>
 */
$site = $argv[1];
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = 'localhost:8899';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
	wp_install( 'Buckler Local', 'admin', 'admin@example.com', true, '', 'admin' );
}
update_option( 'siteurl', 'http://localhost:8899' );
update_option( 'home', 'http://localhost:8899' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'WPLANG', 'es_CO' );
update_option( 'timezone_string', 'America/Bogota' );

foreach ( array( 'woocommerce/woocommerce.php', 'buckler-core/buckler-core.php' ) as $p ) {
	if ( ! is_plugin_active( $p ) ) {
		$r = activate_plugin( $p );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, $p . ': ' . $r->get_error_message() . "\n" ); exit( 1 ); }
	}
}
WC_Install::install();
WC_Install::create_pages();
update_option( 'woocommerce_currency', 'COP' );
update_option( 'woocommerce_price_num_decimals', 0 );
update_option( 'woocommerce_price_thousand_sep', '.' );
update_option( 'woocommerce_default_country', 'CO:ANT' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Pago contra entrega (prueba)' ) );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' ); // HPOS, como en producción.
// Carrito y checkout clásicos (shortcodes), como los módulos Woo de Divi en producción.
wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => '[woocommerce_cart]' ) );
wp_update_post( array( 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => '[woocommerce_checkout]' ) );

if ( get_option( 'bk_local_seeded' ) ) { echo "ya tenía datos\n"; flush_rewrite_rules(); exit; }

// Atributos de talla reales.
$attrs = array( 'tallas-regular' => array( 'TALLAS REGULAR', array( '6', '8', '10', '12' ) ), 'tallas-plus-size' => array( 'TALLAS PLUS SIZE', array( '14', '16', '18', '20', '22', '24' ) ) );
foreach ( $attrs as $slug => $a ) {
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( array( 'name' => $a[0], 'slug' => $slug ) );
	}
	register_taxonomy( 'pa_' . $slug, 'product' );
	foreach ( $a[1] as $t ) { if ( ! term_exists( $t, 'pa_' . $slug ) ) wp_insert_term( $t, 'pa_' . $slug ); }
}
// Categorías reales.
$cats = array();
$tree = array(
	'linea-slim'  => array( 'LÍNEA SLIM', array( 'jeans-linea-slim' => 'JEANS', 'pantalones-linea-slim' => 'PANTALONES', 'prendas-cortas' => 'PRENDAS CORTAS', 'faldas' => 'FALDAS' ) ),
	'linea-curvy' => array( 'LÍNEA CURVY', array( 'jeans' => 'JEANS', 'pantalones' => 'PANTALONES', 'shorts' => 'PRENDAS CORTAS', 'sets' => 'SETS Y OVEROL' ) ),
	'linea-nueva' => array( 'LÍNEA NUEVA', array() ),
	'sale'        => array( '¡SALE!', array() ),
);
foreach ( $tree as $slug => list( $name, $kids ) ) {
	$t = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
	$cats[ $slug ] = is_wp_error( $t ) ? (int) $t->get_error_data() : $t['term_id'];
	foreach ( $kids as $ks => $kn ) {
		$c = wp_insert_term( $kn, 'product_cat', array( 'slug' => $ks, 'parent' => $cats[ $slug ] ) );
		$cats[ $ks ] = is_wp_error( $c ) ? (int) $c->get_error_data() : $c['term_id'];
	}
}
// Productos variables (nombres, SKU y precios reales del catálogo).
$products = array(
	array( 'JEAN BOTA ANCHA DAMA – OXFORD – TIRO ALTO', '9897', 154900, 'tallas-regular', array( 'jeans-linea-slim', 'linea-slim', 'linea-nueva' ) ),
	array( 'JEAN BOTA ANCHA DAMA – OXFORD – TIRO ALTO', '9897 PLUS', 154900, 'tallas-plus-size', array( 'jeans', 'linea-curvy', 'linea-nueva' ) ),
	array( 'JEAN BOTA CAMPANA – FLARE DAMA – TIRO ALTO', '815 PLUS', 149900, 'tallas-plus-size', array( 'jeans', 'linea-curvy' ) ),
	array( 'SHORT DAMA – TIRO ALTO', '9907', 119900, 'tallas-regular', array( 'prendas-cortas', 'linea-slim' ) ),
	array( 'BERMUDA ANCHA DAMA – GAMUZA – TIRO ALTO', '816 PLUS', 129800, 'tallas-plus-size', array( 'shorts', 'linea-curvy' ) ),
);
foreach ( $products as $p ) {
	list( $name, $sku, $price, $attr, $pcats ) = $p;
	$prod = new WC_Product_Variable();
	$prod->set_name( $name );
	$prod->set_sku( $sku );
	$prod->set_status( 'publish' );
	$prod->set_category_ids( array_map( fn( $s ) => $cats[ $s ], $pcats ) );
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $attr ) );
	$a->set_name( 'pa_' . $attr );
	$a->set_options( $attrs[ $attr ][1] );
	$a->set_visible( true );
	$a->set_variation( true );
	$prod->set_attributes( array( $a ) );
	$pid = $prod->save();
	foreach ( $attrs[ $attr ][1] as $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'pa_' . $attr => (string) $size ) );
		$v->set_regular_price( (string) $price );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 5 );
		$v->save();
	}
	WC_Product_Variable::sync( $pid );
}
foreach ( array( 'Política de privacidad', 'Aviso legal', 'Términos y condiciones del servicio', 'Política de envío' ) as $t ) {
	wp_insert_post( array( 'post_type' => 'politica-empresa', 'post_status' => 'publish', 'post_title' => $t ) );
}
foreach ( array( 'linea-slim' => 'Línea Slim', 'linea-curvy' => 'Línea Curvy', 'sales' => '¡Sales!', 'preguntas-frecuentes' => 'Preguntas frecuentes' ) as $slug => $t ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $t, 'post_name' => $slug ) );
}
update_option( 'bk_local_seeded', 1 );
flush_rewrite_rules();
echo "Datos de ejemplo creados\n";
