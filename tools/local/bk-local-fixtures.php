<?php
/**
 * SOLO ENTORNO LOCAL (tools/local). Nunca subir a producción.
 * Reproduce lo que en el sitio real viene de otros plugins/tema: CPT de políticas y ACF (sin ACF Pro aquí).
 */
add_action( 'init', function () {
	register_post_type( 'politica-empresa', array( 'public' => true, 'label' => 'Políticas empresa', 'rewrite' => array( 'slug' => 'politica-empresa' ) ) );
	register_post_type( 'pregunta-frecuente', array( 'public' => true, 'label' => 'Preguntas frecuentes' ) );
	register_taxonomy( 'caregoria-pf', 'pregunta-frecuente', array( 'public' => true, 'label' => 'Categoría PF' ) );
}, 5 );

if ( ! function_exists( 'get_field' ) ) {
	function get_field( $name, $where = null ) { $o = get_option( 'bk_local_acf', array() ); return $o[ $name ] ?? null; }
}
if ( ! function_exists( 'acf_get_field' ) ) {
	function acf_get_field( $name ) {
		return 'banners_pagina_inicio' === $name ? array( 'sub_fields' => array(
			array( 'name' => 'banner_pc', 'label' => 'Banner PC', 'type' => 'image' ),
			array( 'name' => 'banner_moviles', 'label' => 'Banner Moviles', 'type' => 'image' ),
		) ) : false;
	}
}

// Página de maqueta: /?bk_test=layout
add_action( 'template_redirect', function () {
	if ( ( $_GET['bk_test'] ?? '' ) !== 'layout' ) return;
	status_header( 200 ); ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
	<body><?php wp_body_open(); ?><header class="et-l et-l--header"><?php echo do_shortcode( '[bk_header]' ); ?></header>
	<main id="main-content" style="max-width:1100px;margin:auto;padding:40px 16px;min-height:600px"><h1>Prueba</h1></main>
	<footer class="et-l et-l--footer"><?php echo do_shortcode( '[bk_footer]' ); ?></footer><?php wp_footer(); ?></body></html><?php exit;
} );

// Correos: no se envían; se guardan en wp-content/uploads/bk-mails/ para revisarlos.
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$dir = WP_CONTENT_DIR . '/uploads/bk-mails';
	wp_mkdir_p( $dir );
	$name = gmdate( 'Ymd-His' ) . '-' . sanitize_title( is_array( $atts['to'] ) ? implode( '-', $atts['to'] ) : $atts['to'] ) . '-' . wp_generate_password( 4, false );
	file_put_contents( "$dir/$name.html", $atts['message'] );
	file_put_contents( "$dir/$name.json", wp_json_encode( array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'headers' => $atts['headers'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	return true;
}, 10, 2 );

// El admin de WooCommerce en React no está compilado en este entorno (sin npm build): se desactiva.
add_filter( 'woocommerce_admin_disabled', '__return_true' );
