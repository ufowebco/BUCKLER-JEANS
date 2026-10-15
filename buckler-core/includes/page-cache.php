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
 * - Se vacía sola al cambiar productos, tallas agotadas/repuestas, entradas/páginas, opciones de ACF y módulos; una
 *   venta que no agota la talla solo refresca la ficha (etiqueta bk_p{id}). Tras vaciarla se precalienta.
 *   Si el cambio ocurre sin respuesta HTTP (tareas programadas), queda pendiente y sale en la siguiente petición.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_CACHE_TTL = 36000; // 10 h, como la capa anterior.

// Productos: una venta (stock que baja sin agotar la talla) solo refresca la ficha de ese producto; agotar o
// reponer una talla, editar precio/fotos/datos o crear/borrar productos vacía todo (cambian tarjetas y tienda).
add_filter( 'woocommerce_update_product_stock_query', 'bk_cache_stock_op_start' );
foreach ( array( 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product' ) as $bk_h ) {
	add_action( $bk_h, 'bk_cache_on_product_change' );
}
foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $bk_h ) {
	add_action( $bk_h, 'bk_cache_on_stock_qty' );
}
add_action( 'woocommerce_product_set_stock_status', 'bk_cache_purge' );
add_action( 'woocommerce_variation_set_stock_status', 'bk_cache_purge' );
unset( $bk_h );
add_action( 'save_post', 'bk_cache_purge_on_save', 10, 2 );
add_action( 'acf/save_post', 'bk_cache_purge' );
add_action( 'bk_modules_saved', 'bk_cache_purge' );
add_action( 'woocommerce_settings_saved', 'bk_cache_purge' );
add_action( 'wp_update_nav_menu', 'bk_cache_purge' );
add_action( 'init', 'bk_cache_send_pending', 1 );
add_action( 'admin_post_bk_cache_purge', 'bk_cache_purge_action' );
add_action( 'bk_cache_warm', 'bk_cache_warm' );

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
	$tags = 'bk,bk_' . bk_pages_route() . ( 'product' === bk_pages_route() ? ',bk_p' . get_queried_object_id() : '' );
	header( 'X-LiteSpeed-Tag: ' . $tags );
	header( 'Cache-Control: public, max-age=0, must-revalidate' ); // El navegador siempre revalida.
	header( 'X-Bk-Cache: store' );
}

/**
 * Marca que WooCommerce está descontando/reponiendo stock (wc_update_product_stock): los avisos de "producto
 * actualizado" que siguen son de esa operación y no de una edición.
 *
 * @param string $sql Consulta (sin cambios).
 * @return string
 */
function bk_cache_stock_op_start( $sql ) {
	$GLOBALS['bk_cache_stock_op'] = true;
	return $sql;
}

/**
 * Cambio de producto: si viene de una venta, solo la ficha; si no, todo.
 *
 * @param int|WC_Product $product Producto o ID.
 */
function bk_cache_on_product_change( $product ) {
	if ( ! empty( $GLOBALS['bk_cache_stock_op'] ) ) {
		bk_cache_purge_product( $product );
		return;
	}
	bk_cache_purge();
}

/**
 * Fin de una operación de stock: refresca la ficha del producto.
 *
 * @param WC_Product $product Producto o variación.
 */
function bk_cache_on_stock_qty( $product ) {
	bk_cache_purge_product( $product );
	$GLOBALS['bk_cache_stock_op'] = false;
}

/**
 * Vacía solo la ficha de un producto (etiqueta bk_p{id} del producto padre).
 *
 * @param int|WC_Product $product Producto, variación o ID.
 */
function bk_cache_purge_product( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( $product ) {
		bk_cache_send_purge( 'bk_p' . ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) );
	}
}

/**
 * Vacía toda la caché de páginas (en esta respuesta o, si ya no se puede, en la siguiente) y programa el
 * precalentamiento.
 */
function bk_cache_purge() {
	bk_cache_send_purge( '*' );
}

/**
 * Envía la cabecera de limpieza acumulando lo pedido en la petición ('*' gana a las etiquetas).
 *
 * @param string $what '*' o una etiqueta.
 */
function bk_cache_send_purge( $what ) {
	static $all  = false;
	static $tags = array();
	if ( '*' === $what ) {
		$all = true;
	} else {
		$tags[ $what ] = true;
	}
	if ( headers_sent() || wp_doing_cron() || 'cli' === PHP_SAPI ) {
		if ( $all ) {
			update_option( 'bk_cache_purge_pending', time(), false );
		}
		return;
	}
	if ( $all ) {
		header( 'X-LiteSpeed-Purge: *' );
		delete_option( 'bk_cache_purge_pending' );
		bk_cache_schedule_warm();
		return;
	}
	header( 'X-LiteSpeed-Purge: ' . implode( ', ', array_map( static fn( $t ) => 'tag=' . $t, array_keys( $tags ) ) ) );
}

/**
 * Programa el precalentamiento (una sola vez aunque haya varias limpiezas seguidas).
 */
function bk_cache_schedule_warm() {
	if ( ! wp_next_scheduled( 'bk_cache_warm' ) ) {
		wp_schedule_single_event( time() + 45, 'bk_cache_warm' );
	}
}

/**
 * Precalienta la caché tras vaciarla: visita en segundo plano las páginas más vistas (inicio, tienda, líneas,
 * ¡Sale!, Lo nuevo, FAQ y los productos más recientes) para que la siguiente clienta no espere ~2 s.
 * Máximo ~50 s por tanda. Filtro bk_cache_warm_urls.
 */
function bk_cache_warm() {
	$urls = array( home_url( '/' ), wc_get_page_permalink( 'shop' ), bk_new_url() );
	foreach ( (array) bk_config( 'lines' ) as $l ) {
		if ( ! empty( $l['page'] ) ) {
			$urls[] = bk_url( $l['page'] );
		}
	}
	foreach ( array( 'sale_page', 'faq_page' ) as $k ) {
		if ( bk_config( $k ) ) {
			$urls[] = bk_url( (string) bk_config( $k ) );
		}
	}
	foreach ( wc_get_products( array( 'status' => 'publish', 'stock_status' => 'instock', 'limit' => 16, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) ) as $id ) {
		$urls[] = get_permalink( $id );
	}
	$urls  = array_values( array_unique( array_filter( (array) apply_filters( 'bk_cache_warm_urls', $urls ) ) ) );
	$start = time();
	$agent = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 BucklerWarm';
	foreach ( $urls as $u ) {
		if ( time() - $start > 50 ) {
			break;
		}
		wp_remote_get( $u, array( 'timeout' => 15, 'redirection' => 2, 'user-agent' => $agent, 'headers' => array( 'Accept' => 'text/html' ) ) );
	}
}

/**
 * Limpieza pendiente (cambios hechos en tareas programadas).
 */
function bk_cache_send_pending() {
	if ( get_option( 'bk_cache_purge_pending' ) && ! headers_sent() && ! wp_doing_cron() && 'cli' !== PHP_SAPI ) {
		header( 'X-LiteSpeed-Purge: *' );
		delete_option( 'bk_cache_purge_pending' );
		bk_cache_schedule_warm();
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
