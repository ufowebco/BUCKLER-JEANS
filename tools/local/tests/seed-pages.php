<?php
/** Módulo "Páginas limpias" en local: lo activa y crea preguntas frecuentes de ejemplo (idempotente). */
require '/tmp/claude-0/bk-local/site/wp-load.php';
$m = (array) get_option( 'bk_modules', array() );
update_option( 'bk_modules', array_values( array_unique( array_merge( $m, array( 'pages', 'layout', 'cards', 'shop', 'sidecart' ) ) ) ) );
if ( ! get_posts( array( 'post_type' => 'pregunta-frecuente', 'posts_per_page' => 1 ) ) ) {
	$cats = array( 'envios-y-entregas' => 'Envíos y Entregas', 'pagos' => 'Pagos' );
	foreach ( $cats as $slug => $name ) {
		wp_insert_term( $name, 'caregoria-pf', array( 'slug' => $slug ) );
	}
	$qs = array(
		array( '¿Cuánto tarda mi pedido?', 'Respuesta de prueba sobre tiempos de entrega.', 'envios-y-entregas' ),
		array( '¿Hacen envíos a toda Colombia?', 'Respuesta de prueba sobre cobertura.', 'envios-y-entregas' ),
		array( '¿Qué medios de pago aceptan?', 'Respuesta de prueba sobre pagos.', 'pagos' ),
	);
	foreach ( $qs as $q ) {
		$id = wp_insert_post( array( 'post_type' => 'pregunta-frecuente', 'post_status' => 'publish', 'post_title' => $q[0], 'post_content' => $q[1] ) );
		wp_set_object_terms( $id, $q[2], 'caregoria-pf' );
	}
}
// Preguntas sin categoría (si se crearon antes de registrar la taxonomía).
$map = array( '¿Cuánto tarda mi pedido?' => 'envios-y-entregas', '¿Hacen envíos a toda Colombia?' => 'envios-y-entregas', '¿Qué medios de pago aceptan?' => 'pagos' );
foreach ( array( 'envios-y-entregas' => 'Envíos y Entregas', 'pagos' => 'Pagos' ) as $slug => $name ) {
	if ( ! term_exists( $slug, 'caregoria-pf' ) ) {
		wp_insert_term( $name, 'caregoria-pf', array( 'slug' => $slug ) );
	}
}
foreach ( get_posts( array( 'post_type' => 'pregunta-frecuente', 'posts_per_page' => -1 ) ) as $p ) {
	if ( isset( $map[ $p->post_title ] ) && ! wp_get_object_terms( $p->ID, 'caregoria-pf' ) ) {
		wp_set_object_terms( $p->ID, $map[ $p->post_title ], 'caregoria-pf' );
	}
}
// "Lo nuevo Curvy": un producto Curvy publicado y con stock en la categoría hija de Línea nueva.
$curvy = get_term_by( 'slug', 'curvy', 'product_cat' );
foreach ( wc_get_products( array( 'category' => array( 'linea-curvy' ), 'status' => 'publish', 'stock_status' => 'instock', 'limit' => 3 ) ) as $p ) {
	if ( $curvy ) {
		wp_set_object_terms( $p->get_id(), array( (int) $curvy->term_id ), 'product_cat', true );
	}
}
foreach ( array( 'slim', 'curvy' ) as $c ) {
	echo $c . ': ' . count( wc_get_products( array( 'category' => array( $c ), 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ) ) ) . "\n";
}
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bk_q_%' OR option_name LIKE '_transient_timeout_bk_q_%'" ); // Caché de [bk_productos].
flush_rewrite_rules();
echo "ok\n";
// Imágenes de las líneas (ACF de opciones simulado): dos fotos cualquiera de la mediateca.
$acf = (array) get_option( 'bk_local_acf', array() );
if ( empty( $acf['linea_slim_pc'] ) ) {
	$imgs = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => 2, 'fields' => 'ids' ) );
	if ( count( $imgs ) === 2 ) {
		$acf['linea_slim_pc']  = $imgs[0];
		$acf['linea_slim_mo']  = $imgs[0];
		$acf['linea_curvy_pc'] = $imgs[1];
		$acf['linea_curvy_mo'] = $imgs[1];
		update_option( 'bk_local_acf', $acf );
	}
}
echo "lineas: " . ( empty( $acf['linea_slim_pc'] ) ? 'no' : 'sí' ) . "\n";
// Reseñas de Google de ejemplo con el esquema de WP Social Ninja (tabla {prefix}wpsr_reviews).
global $wpdb;
$t = $wpdb->prefix . 'wpsr_reviews';
$wpdb->query( "CREATE TABLE IF NOT EXISTS `$t` ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, platform_name VARCHAR(255), source_id VARCHAR(255), reviewer_name VARCHAR(255), reviewer_url VARCHAR(255), reviewer_img TEXT, reviewer_text LONGTEXT, review_time TIMESTAMP NULL, rating INT, review_approved INT DEFAULT 1 )" );
if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ) ) {
	$rev = array(
		array( 'Reseña Uno', 'Texto de prueba de una reseña positiva sobre la talla y la entrega.', 5 ),
		array( 'Reseña Dos', 'Texto de prueba: el jean llegó rápido y la talla quedó perfecta.', 5 ),
		array( 'Reseña Tres', 'Texto de prueba con una opinión de cuatro estrellas.', 4 ),
		array( 'Reseña Cuatro', 'Texto de prueba que no debe mostrarse por tener 2 estrellas.', 2 ),
		array( 'Reseña Cinco', 'Texto de prueba largo para comprobar el recorte de varias líneas en la tarjeta de reseña. Texto de prueba largo para comprobar el recorte de varias líneas en la tarjeta de reseña. Texto de prueba largo para comprobar el recorte.', 5 ),
	);
	foreach ( $rev as $i => $r ) {
		$wpdb->insert( $t, array( 'platform_name' => 'google', 'reviewer_name' => $r[0], 'reviewer_text' => $r[1], 'rating' => $r[2], 'review_time' => gmdate( 'Y-m-d H:i:s', time() - ( $i + 1 ) * 20 * DAY_IN_SECONDS ), 'review_approved' => 1 ) );
	}
}
delete_transient( 'bk_greviews_10' );
delete_transient( 'bk_greviews_8' );
echo 'reseñas: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ) . "\n";
