<?php
/**
 * Datos del negocio usados por header, footer y otros componentes.
 * Tomados del sitio en vivo (header 1114 y footer 119) el 2026-10-14.
 *
 * Para cambiarlos sin tocar el plugin: filtro `bk_site_config` desde el tema hijo.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configuración del sitio.
 *
 * @param string|null $key Clave opcional.
 * @return mixed
 */
function bk_config( $key = null ) {
	static $config = null;
	if ( null === $config ) {
		$config = apply_filters(
			'bk_site_config',
			array(
				'company'       => 'DENIM BLUE JEANS S.A.S.',
				'brand'         => 'Buckler Jeans',
				'since'         => 1986,
				// Logos de la Mediateca (navy para fondo claro, lima para fondo navy).
				'logo_dark'     => '/wp-content/uploads/2026/05/Logo-Buckler-Denim-Blue-1.webp',
				'logo_light'    => '/wp-content/uploads/2026/05/Logo-Buckler-Denim-Blue-2.webp',
				'whatsapp'      => '573178228720',
				'whatsapp_text' => 'Hola, acabo de visitar su sitio web y quisiera más información.',
				'instagram'     => 'denimbluecolombia',
				'maps_url'      => 'https://share.google/8loyXYXpFXVM6biHe',
				'review_url'    => 'https://share.google/SLJE5jBd2NyT6HPgU',
				'addi_url'      => 'https://preapproval.addi.com/?allySlug=addi-preapproval-pm',
				'city'          => 'Medellín, Colombia',
				'hours'         => array(
					array( 'Lunes a jueves', '8:00 a. m. a 5:30 p. m.' ),
					array( 'Viernes', '8:00 a. m. a 5:00 p. m.' ),
				),
				// Barra de anuncios (textos actuales del sitio).
				'announcements' => array(
					'Envíos gratis a todo el país',
					'Paga con Addi en 3 cuotas con 0 % de interés',
					'Paga con Wompi, Rapyd o Bold',
					'Envíos a San Andrés Islas tienen costo adicional',
					'Las prendas en descuento no tienen cambio',
				),
				// Medios de pago: [archivo en uploads, texto alternativo].
				'payments'      => array(
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-1.webp', 'Addi' ),
					array( '/wp-content/uploads/2026/09/Medios-de-Pago-El-Santuario-del-Mueble-13-1-1.webp', 'Bold' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-3.webp', 'Wompi' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-2.webp', 'Rapyd' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-5.webp', 'Bancolombia' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-9.webp', 'Nequi' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-10.webp', 'Daviplata' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-6.webp', 'Visa' ),
					array( '/wp-content/uploads/2026/08/Medios-de-Pago-Denim-Blue-Buckler-7.webp', 'Mastercard' ),
				),
				// Categorías raíz que forman el menú de la tienda (slugs de product_cat).
				'lines'         => array(
					'linea-slim'  => array( 'label' => 'Línea Slim', 'sizes' => 'Tallas 6 a 12', 'page' => '/linea-slim/' ),
					'linea-curvy' => array( 'label' => 'Línea Curvy', 'sizes' => 'Tallas 14 a 24', 'page' => '/linea-curvy/' ),
				),
				'new_cat'       => 'linea-nueva',
				'sale_page'     => '/sales/',
				'faq_page'      => '/preguntas-frecuentes/',
				'credit_url'    => 'https://ufoweb.co/',
			)
		);
	}
	return null === $key ? $config : ( $config[ $key ] ?? null );
}

/**
 * URL absoluta desde una ruta del sitio.
 *
 * @param string $path Ruta ("/linea-slim/") o URL completa.
 * @return string
 */
function bk_url( $path ) {
	return preg_match( '#^https?://#', $path ) ? $path : home_url( $path );
}

/**
 * Imagen de la Mediateca por su ruta en uploads (usa el adjunto real para tener srcset).
 *
 * @param string $path  Ruta de uploads.
 * @param string $alt   Texto alternativo.
 * @param array  $attrs Atributos extra (class, width, height, loading, sizes...).
 * @return string
 */
function bk_upload_img( $path, $alt, $attrs = array() ) {
	$url = bk_url( $path );
	$key = 'bk_att_' . md5( $url );
	$id  = wp_cache_get( $key, 'bk' );
	if ( false === $id ) {
		$id = get_transient( $key );
		if ( false === $id ) {
			$id = (int) attachment_url_to_postid( $url );
			set_transient( $key, $id, WEEK_IN_SECONDS );
		}
		wp_cache_set( $key, $id, 'bk' );
	}
	$attrs = array_merge( array( 'alt' => $alt, 'loading' => 'lazy', 'decoding' => 'async' ), $attrs );
	if ( $id ) {
		return wp_get_attachment_image( (int) $id, 'medium', false, $attrs );
	}
	$html = '<img src="' . esc_url( $url ) . '"';
	foreach ( $attrs as $k => $v ) {
		$html .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
	}
	return $html . '>';
}

/**
 * Enlace de WhatsApp con mensaje.
 *
 * @return string
 */
function bk_whatsapp_url() {
	return 'https://wa.me/' . rawurlencode( (string) bk_config( 'whatsapp' ) ) . '?text=' . rawurlencode( (string) bk_config( 'whatsapp_text' ) );
}

/**
 * Teléfono legible: +57 317 822 8720.
 *
 * @return string
 */
function bk_whatsapp_display() {
	$n = (string) bk_config( 'whatsapp' );
	return preg_match( '/^57(\d{3})(\d{3})(\d{4})$/', $n, $m ) ? "+57 {$m[1]} {$m[2]} {$m[3]}" : '+' . $n;
}

/**
 * Subcategorías con productos de una categoría raíz (cacheadas; se limpian al editar categorías).
 *
 * @param string $parent_slug Slug de la categoría padre.
 * @return array<int,array{name:string,url:string}>
 */
function bk_child_cats( $parent_slug ) {
	$cache = get_transient( 'bk_cats_' . $parent_slug );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$out    = array();
	$parent = get_term_by( 'slug', $parent_slug, 'product_cat' );
	if ( $parent ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $parent->term_id,
				'hide_empty' => true,
				'orderby'    => 'menu_order', // Orden personalizado de WooCommerce (arrastrar en Productos → Categorías).
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array(
				'name' => bk_nice_label( $t->name ),
				'url'  => (string) get_term_link( $t ),
			);
		}
	}
	set_transient( 'bk_cats_' . $parent_slug, $out, DAY_IN_SECONDS );
	return $out;
}

add_action( 'created_product_cat', 'bk_flush_menu_cache' );
add_action( 'edited_product_cat', 'bk_flush_menu_cache' );
add_action( 'delete_product_cat', 'bk_flush_menu_cache' );
add_action( 'woocommerce_update_product', 'bk_flush_menu_cache' );

/**
 * Limpia la caché de menús de categorías.
 */
function bk_flush_menu_cache() {
	foreach ( array_keys( (array) bk_config( 'lines' ) ) as $slug ) {
		delete_transient( 'bk_cats_' . $slug );
	}
}

/**
 * "CHAQUETAS Y CHALECOS" → "Chaquetas y chalecos" (los nombres de categoría están en mayúsculas).
 *
 * @param string $name Nombre.
 * @return string
 */
function bk_nice_label( $name ) {
	$name = trim( wp_strip_all_tags( $name ) );
	if ( mb_strtoupper( $name ) !== $name ) {
		return $name;
	}
	$lower = mb_strtolower( $name );
	return mb_strtoupper( mb_substr( $lower, 0, 1 ) ) . mb_substr( $lower, 1 );
}
