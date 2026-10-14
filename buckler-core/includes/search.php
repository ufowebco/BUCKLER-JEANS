<?php
/**
 * Buscador en vivo del header: índice ligero de productos en stock (JSON) que el navegador filtra al escribir.
 *
 * - Un solo pedido por visita (al enfocar el buscador), cacheable: la URL lleva la versión del catálogo
 *   (`bk_catalog_v`, sube con cada cambio de producto o stock), así la caché nunca sirve datos viejos.
 * - El índice se guarda en un transitorio y se regenera solo cuando cambia la versión.
 * - Sin JS el formulario sigue usando la búsqueda nativa de WooCommerce.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_search_routes' );

/**
 * Ruta pública del índice.
 */
function bk_search_routes() {
	register_rest_route(
		'bk/v1',
		'/search-index',
		array(
			'methods'             => 'GET',
			'callback'            => 'bk_search_index_response',
			'permission_callback' => '__return_true', // Público: solo datos visibles en la tienda.
		)
	);
}

/**
 * URL del índice (con la versión del catálogo para la caché).
 *
 * @return string
 */
function bk_search_index_url() {
	return add_query_arg( 'v', rawurlencode( substr( md5( (string) get_option( 'bk_catalog_v', '1' ) ), 0, 10 ) ), rest_url( 'bk/v1/search-index' ) );
}

/**
 * Texto plano de un precio.
 *
 * @param float $amount Valor.
 * @return string
 */
function bk_search_price( $amount ) {
	return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Texto normalizado para buscar (minúsculas, sin tildes).
 *
 * @param string $text Texto.
 * @return string
 */
function bk_search_norm( $text ) {
	return trim( preg_replace( '/\s+/', ' ', strtolower( remove_accents( wp_strip_all_tags( (string) $text ) ) ) ) );
}

/**
 * Índice de productos en stock.
 *
 * @return array
 */
function bk_search_index() {
	$v     = (string) get_option( 'bk_catalog_v', '1' );
	$cache = get_transient( 'bk_search_index' );
	if ( is_array( $cache ) && ( $cache['v'] ?? '' ) === $v ) {
		return $cache['items'];
	}

	$ids   = wc_get_products(
		array(
			'status'       => 'publish',
			'stock_status' => 'instock',
			'visibility'   => 'search',
			'limit'        => 600,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'ids',
		)
	);
	$items = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			continue;
		}
		if ( $p->is_type( 'variable' ) ) {
			/** @var WC_Product_Variable $p */
			$price   = (float) $p->get_variation_price( 'min', true );
			$regular = (float) $p->get_variation_regular_price( 'min', true );
		} else {
			$price   = (float) $p->get_price();
			$regular = (float) $p->get_regular_price();
		}
		$line  = bk_product_line( $id );
		$cats  = wp_list_pluck( get_the_terms( $id, 'product_cat' ) ?: array(), 'name' );
		$thumb = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_gallery_thumbnail' ) : '';
		$items[] = array(
			'n' => $p->get_name(),
			'u' => $p->get_permalink(),
			'i' => $thumb ? $thumb : '',
			'p' => $price > 0 ? bk_search_price( $price ) : '',
			'o' => $regular > $price && $price > 0 ? bk_search_price( $regular ) : '',
			'l' => $line ? $line['label'] : '',
			't' => bk_search_norm( implode( ' ', array_merge( array( $p->get_name(), $p->get_sku(), $line ? $line['label'] : '' ), array_map( 'bk_nice_label', $cats ) ) ) ),
		);
	}
	set_transient( 'bk_search_index', array( 'v' => $v, 'items' => $items ), DAY_IN_SECONDS );
	return $items;
}

/**
 * Respuesta REST con caché pública (la URL cambia con el catálogo).
 *
 * @return WP_REST_Response
 */
function bk_search_index_response() {
	$res = new WP_REST_Response( bk_search_index() );
	$res->header( 'Cache-Control', 'public, max-age=3600' );
	return $res;
}
