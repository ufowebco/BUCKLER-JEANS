<?php
/**
 * Caché de páginas en el servidor LiteSpeed, sin plugin de caché.
 *
 * El servidor guarda la respuesta cuando recibe la cabecera X-LiteSpeed-Cache-Control (la quita antes de
 * enviarla al navegador) y la vacía al recibir X-LiteSpeed-Purge en cualquier respuesta.
 *
 * - Solo páginas de buckler-core (inicio, tienda/categorías/líneas/¡Sale!/búsqueda, ficha, FAQ y políticas),
 *   GET, sin sesión, sin carrito, sin avisos de WooCommerce pendientes. Carrito, pago y cuenta nunca.
 * - En esas respuestas se quitan las Set-Cookie (los plugins de Facebook y otro ponen cookies en cada página y
 *   el servidor no guarda páginas con cookies). El píxel del navegador sigue creando su cookie _fbp.
 * - Se vacía sola al cambiar productos o stock (bk_catalog_flush), entradas/páginas, opciones de ACF y módulos.
 *   Si el cambio ocurre sin respuesta HTTP (tareas programadas), queda pendiente y sale en la siguiente petición.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_CACHE_TTL = 36000; // 10 h, como la capa anterior.

add_action( 'bk_catalog_flushed', 'bk_cache_purge' );
add_action( 'save_post', 'bk_cache_purge_on_save', 10, 2 );
add_action( 'acf/save_post', 'bk_cache_purge' );
add_action( 'bk_modules_saved', 'bk_cache_purge' );
add_action( 'woocommerce_settings_saved', 'bk_cache_purge' );
add_action( 'wp_update_nav_menu', 'bk_cache_purge' );
add_action( 'init', 'bk_cache_send_pending', 1 );
add_action( 'admin_post_bk_cache_purge', 'bk_cache_purge_action' );

/**
 * ¿Se puede guardar esta petición en la caché?
 *
 * @return bool
 */
function bk_cache_cacheable() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_user_logged_in() || is_preview() || is_404() ) {
		return false;
	}
	if ( ! function_exists( 'bk_pages_route' ) || ! in_array( bk_pages_route(), (array) apply_filters( 'bk_cache_routes', array( 'home', 'shop', 'product', 'faq', 'policy' ) ), true ) ) {
		return false;
	}
	foreach ( array_keys( $_COOKIE ) as $c ) {
		if ( preg_match( '/^(wordpress_logged_in_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_|comment_author_)/', (string) $c ) ) {
			return false;
		}
	}
	foreach ( array( 'add-to-cart', 'removed_item', 'undo_item', 'wc-ajax', 'nocache', 'et_fb' ) as $q ) {
		if ( isset( $_GET[ $q ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
	}
	if ( function_exists( 'wc_notice_count' ) && WC()->session && wc_notice_count() > 0 ) {
		return false;
	}
	return (bool) apply_filters( 'bk_cache_cacheable', true );
}

/**
 * Cabeceras de caché y limpieza de cookies. Se llama justo antes de enviar el documento (document.php lo
 * tiene en un búfer, así ninguna cabecera salió aún).
 */
function bk_cache_headers() {
	if ( headers_sent() ) {
		return;
	}
	if ( ! bk_cache_cacheable() ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		return;
	}
	header_remove( 'Set-Cookie' );
	header( 'X-LiteSpeed-Cache-Control: public,max-age=' . BK_CACHE_TTL );
	header( 'X-LiteSpeed-Tag: bk,bk_' . bk_pages_route() );
	header( 'Cache-Control: public, max-age=0, must-revalidate' ); // El navegador siempre revalida.
	header( 'X-Bk-Cache: store' );
}

/**
 * Vacía la caché (en esta respuesta o, si ya no se puede, en la siguiente).
 */
function bk_cache_purge() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	if ( ! headers_sent() && ! wp_doing_cron() && 'cli' !== PHP_SAPI ) {
		header( 'X-LiteSpeed-Purge: *' );
		delete_option( 'bk_cache_purge_pending' );
		return;
	}
	update_option( 'bk_cache_purge_pending', time(), false );
}

/**
 * Limpieza pendiente (cambios hechos en tareas programadas).
 */
function bk_cache_send_pending() {
	if ( get_option( 'bk_cache_purge_pending' ) && ! headers_sent() && ! wp_doing_cron() && 'cli' !== PHP_SAPI ) {
		header( 'X-LiteSpeed-Purge: *' );
		delete_option( 'bk_cache_purge_pending' );
	}
}

/**
 * Vacía al guardar contenido público (no en borradores automáticos ni revisiones).
 *
 * @param int     $post_id ID.
 * @param WP_Post $post    Entrada.
 */
function bk_cache_purge_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
		return;
	}
	if ( in_array( $post->post_type, array( 'shop_order', 'shop_order_placehold', 'bk_abandoned' ), true ) ) {
		return;
	}
	bk_cache_purge();
}

/**
 * Botón "Vaciar caché de páginas" (Ajustes → Buckler Core).
 */
function bk_cache_purge_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '', 403 );
	}
	check_admin_referer( 'bk_cache_purge' );
	bk_cache_purge();
	wp_safe_redirect( admin_url( 'options-general.php?page=bk-modules&bk_msg=' . rawurlencode( 'Caché de páginas vaciada.' ) ) );
	exit;
}
