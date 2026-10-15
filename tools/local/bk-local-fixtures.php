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

// ACF simulado con el MISMO almacenamiento que ACF Pro (opciones `options_{campo}`, repeater `options_{rep}` = filas y
// `options_{rep}_{i}_{sub}`, posts en post meta). Si no hay dato real, respaldo en la opción bk_local_acf (semillas viejas).
function bk_local_acf_defs() {
	return array(
		'banners_pagina_inicio' => array( 'key' => 'field_bk_banners', 'name' => 'banners_pagina_inicio', 'label' => 'Banners Página Inicio', 'type' => 'repeater', 'sub_fields' => array(
			array( 'key' => 'field_bk_banner_pc', 'name' => 'banner_pc', 'label' => 'Banner PC', 'type' => 'image' ),
			array( 'key' => 'field_bk_banner_mo', 'name' => 'banner_moviles', 'label' => 'Banner Moviles', 'type' => 'image' ),
			array( 'key' => 'field_bk_banner_link', 'name' => 'enlace', 'label' => 'Enlace', 'type' => 'link' ),
		) ),
		'linea_slim_pc' => array( 'key' => 'field_bk_lspc', 'name' => 'linea_slim_pc', 'label' => 'Línea Slim PC', 'type' => 'image' ),
		'linea_slim_mo' => array( 'key' => 'field_bk_lsmo', 'name' => 'linea_slim_mo', 'label' => 'Línea Slim MO', 'type' => 'image' ),
		'linea_curvy_pc' => array( 'key' => 'field_bk_lcpc', 'name' => 'linea_curvy_pc', 'label' => 'Línea Curvy PC', 'type' => 'image' ),
		'linea_curvy_mo' => array( 'key' => 'field_bk_lcmo', 'name' => 'linea_curvy_mo', 'label' => 'Línea Curvy MO', 'type' => 'image' ),
		'imagen_guia_de_tallas' => array( 'key' => 'field_bk_guia', 'name' => 'imagen_guia_de_tallas', 'label' => 'Imagen Guía de Tallas', 'type' => 'image' ),
		'imagen_siluetas' => array( 'key' => 'field_bk_silu', 'name' => 'imagen_siluetas', 'label' => 'Imagen Siluetas', 'type' => 'image' ),
		'texto_politica' => array( 'key' => 'field_bk_tpol', 'name' => 'texto_politica', 'label' => 'Texto Política', 'type' => 'wysiwyg' ),
		'respuesta_pregunta_frecuente' => array( 'key' => 'field_bk_rpf', 'name' => 'respuesta_pregunta_frecuente', 'label' => 'Respuesta pregunta frecuente', 'type' => 'wysiwyg' ),
		// Como en producción: grupo "Archivos Pedidos" en shop_order, repeater con el subcampo "archivo" (imagen).
		'archivos_adjuntos' => array( 'key' => 'field_bk_adj', 'name' => 'archivos_adjuntos', 'label' => 'Archivos Adjuntos', 'type' => 'repeater', 'sub_fields' => array(
			array( 'key' => 'field_bk_adj_archivo', 'name' => 'archivo', 'label' => 'Archivo', 'type' => 'image' ),
		) ),
	);
}
if ( ! function_exists( 'acf_get_field' ) ) {
	function acf_get_field( $name ) { return bk_local_acf_defs()[ $name ] ?? false; }
}
if ( ! function_exists( 'get_field' ) ) {
	function get_field( $name, $where = null ) {
		$def = bk_local_acf_defs()[ $name ] ?? null;
		if ( 'option' === $where || 'options' === $where ) {
			$raw = get_option( 'options_' . $name, null );
			if ( null !== $raw && $def && 'repeater' === $def['type'] ) {
				$rows = array();
				for ( $i = 0; $i < (int) $raw; $i++ ) {
					$row = array();
					foreach ( $def['sub_fields'] as $sf ) {
						$row[ $sf['name'] ] = get_option( "options_{$name}_{$i}_{$sf['name']}", '' );
					}
					$rows[] = $row;
				}
				return $rows;
			}
			if ( null !== $raw ) {
				return $raw;
			}
			$o = get_option( 'bk_local_acf', array() );
			return $o[ $name ] ?? null;
		}
		// ACF con pedidos HPOS ("woo_order_ID"): en las pruebas, filas sin formato (claves de campo) en una opción.
		if ( is_string( $where ) && 0 === strpos( $where, 'woo_order_' ) ) {
			return get_option( 'bk_test_acf_' . $where . '_' . $name, null );
		}
		$id = is_numeric( $where ) ? (int) $where : get_the_ID();
		return get_post_meta( $id, $name, true );
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

// Igual que producción (2026-10-14): un plugin envuelve galería + resumen de la ficha en un div.clearfix.
add_action( 'woocommerce_before_single_product_summary', function () { echo '<div class="clearfix">'; }, 1 );
add_action( 'woocommerce_after_single_product_summary', function () { echo '</div>'; }, 1 );
// Igual que producción: un plugin de "compartir" en la ficha (debe desaparecer con Páginas limpias).
add_action( 'woocommerce_share', function () { echo '<div class="bk-test-share">Share on</div>'; } );

// Simula el snippet del tema de producción que añade <span class="minus|plus"> a cada .quantity.
add_action(
	'wp_footer',
	static function () {
		echo "<script>jQuery(function($){ if ($('.quantity input.qty').length && !$('.quantity .minus').length) { $('.quantity').prepend('<span class=\"minus\">-</span>'); $('.quantity').append('<span class=\"plus\">+</span>'); } });</script>";
	},
	99
);

// Simula el <head> de producción: snippets antiguos del tema (ocultar la página hasta load, grilla de Divi) y
// Google Tag Manager. Con Páginas limpias, buckler-core quita los primeros y retrasa GTM hasta la interacción.
add_action(
	'wp_head',
	static function () {
		echo "<script>\n// 1. Ocultamos la página con una transición suave\ndocument.documentElement.style.opacity = '0';\nwindow.addEventListener('load', () => { document.documentElement.style.opacity = '1'; });\n</script>\n";
		echo "<script>jQuery(document).ready(function($) { function cargarGrillaAjax(urlDestino) { return urlDestino; } });</script>\n";
		echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js'});w.bkGtmRan=1;})(window,document,'script','dataLayer','GTM-TEST');</script>\n";
	},
	20
);

// Simula el snippet de producción que manda toda dirección inexistente a la portada (soft 404).
add_action(
	'template_redirect',
	static function () {
		if ( is_404() ) {
			// Como en producción: header() directo (sin wp_redirect ni X-Redirect-By), después de la cabecera Link.
			header( 'Location: ' . home_url(), true, 301 );
			exit;
		}
	},
	20
);

// Simula Divi en producción: mete recortes 16:9 (980×551) en el srcset de cualquier imagen.
add_filter(
	'wp_calculate_image_srcset',
	static function ( $sources, $size_array, $image_src ) {
		$sources[980] = array( 'url' => preg_replace( '/(-\d+x\d+)?\.(webp|jpe?g|png)$/', '-980x551.$2', $image_src ), 'descriptor' => 'w', 'value' => 980 );
		return $sources;
	},
	10,
	3
);

// Como Divi en producción: el tema declara la galería con lightbox (WooCommerce imprime el HTML de PhotoSwipe).
add_action( 'after_setup_theme', static function () { add_theme_support( 'wc-product-gallery-lightbox' ); add_theme_support( 'wc-product-gallery-zoom' ); add_theme_support( 'wc-product-gallery-slider' ); }, 99 );

// Como un plugin de Divi en producción: imprime el visor PhotoSwipe de WooCommerce en todas las páginas.
add_action( 'wp_footer', static function () { if ( function_exists( 'wc_get_template' ) ) { wc_get_template( 'single-product/photoswipe.php' ); } }, 5 );

// Como SEOPress en producción (revisión 2026-10-15): quita el JSON-LD Product de WooCommerce, título de tienda
// "Tienda -", títulos de producto "REF: … - NOMBRE - Buckler Jenas by Denimblue" y el inicio sin og:image.
add_action( 'init', static function () { if ( ! get_option( 'bk_test_keep_wc_ld' ) && function_exists( 'WC' ) && WC()->structured_data ) { remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 ); } }, 20 );
add_filter(
	'pre_get_document_title',
	static function ( $t ) {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'Tienda -';
		}
		if ( is_singular( 'product' ) ) {
			$p = wc_get_product( get_queried_object_id() );
			return 'REF: ' . $p->get_sku() . ' - ' . $p->get_name() . ' - Buckler Jenas by Denimblue';
		}
		return $t;
	},
	99
);
add_action( 'wp_head', static function () { echo '<meta property="og:site_name" content="Buckler Jenas by Denimblue">' . "\n" . '<meta property="og:title" content="SEOPress">' . "\n"; }, 1 );

// Como producción (PageSpeed 2026-10-15): algo encola el reproductor de WordPress (mediaelement + jQuery) en la tienda.
add_action( 'wp_enqueue_scripts', static function () { wp_enqueue_style( 'wp-mediaelement' ); wp_enqueue_script( 'wp-mediaelement' ); }, 50 );

// Como BodyCommerce en producción (hallazgo 2026-10-15): en la ficha borra TODO lo enganchado a
// woocommerce_single_product_summary y vuelve a poner solo lo de WooCommerce. Lo que buckler-core registra al cargar
// (recuadro de descuentos, aviso de ¡Sale!) desaparecía; lo de bk_pdp_hooks() (template_include) sobrevivía.
add_action(
	'template_redirect',
	static function () {
		if ( ! function_exists( 'is_product' ) || ! is_product() || get_option( 'bk_test_no_bodycommerce' ) ) {
			return;
		}
		remove_all_actions( 'woocommerce_single_product_summary' );
		foreach ( array( 'woocommerce_template_single_title' => 5, 'woocommerce_template_single_rating' => 10, 'woocommerce_template_single_price' => 10, 'woocommerce_template_single_excerpt' => 20, 'woocommerce_template_single_add_to_cart' => 30, 'woocommerce_template_single_meta' => 40, 'woocommerce_template_single_sharing' => 50 ) as $fn => $prio ) {
			add_action( 'woocommerce_single_product_summary', $fn, $prio );
		}
	},
	20
);

// Pruebas del modo anterior (casilla de autorización): la opción bk_test_ac_checkbox lo vuelve a encender.
add_filter( 'bk_ac_consent_mode', static fn( $m ) => get_option( 'bk_test_ac_checkbox' ) ? 'checkbox' : $m );
