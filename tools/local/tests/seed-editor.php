<?php
/**
 * Categorías reales que usa el editor de productos: Línea nueva (slim/curvy) y ¡Sale! con sus prendas,
 * más la prenda SETS Y OVEROL de Slim (nombre distinto al de Sale: "SET Y OVEROL"). Idempotente.
 */
require '/tmp/claude-0/bk-local/site/wp-load.php';
$mk = static function ( $slug, $name, $parent = '' ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $t ) {
		return $t->term_id;
	}
	$p = $parent ? get_term_by( 'slug', $parent, 'product_cat' ) : null;
	$r = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'parent' => $p ? $p->term_id : 0 ) );
	return is_wp_error( $r ) ? 0 : $r['term_id'];
};
$mk( 'linea-nueva', 'LÍNEA NUEVA' );
$mk( 'slim', 'NUEVO - SLIM', 'linea-nueva' );
$mk( 'curvy', 'NUEVO - CURVY', 'linea-nueva' );
$mk( 'sale', '¡SALE!' );
$mk( 'jeans-sale', 'JEANS', 'sale' );
$mk( 'prendas-cortas-sale', 'PRENDAS CORTAS', 'sale' );
$mk( 'set-y-overol-sale', 'SET Y OVEROL', 'sale' );
$mk( 'set-y-overol', 'SET Y OVEROL', 'linea-slim' );
// Gestora (rol Gestor de tienda) sin upload_files: la subida debe funcionar igual por la API propia.
$u = get_user_by( 'login', 'gestora' );
echo 'gestora upload_files: ' . ( $u && user_can( $u, 'upload_files' ) ? 'sí' : 'no' ) . "\n";
// Limpia productos de pruebas anteriores del editor.
foreach ( wc_get_products( array( 'sku' => 'TEST', 'limit' => -1, 'status' => 'any' ) ) as $p ) {
	$p->delete( true );
}
foreach ( array( 'TEST-901', 'TEST-901 PLUS' ) as $sku ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		wc_get_product( $id )->delete( true );
	}
}
delete_transient( 'bk_g_summary' );
echo "ok\n";
