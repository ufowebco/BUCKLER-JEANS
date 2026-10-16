<?php
/**
 * Comercio electrónico de GA4 en el dataLayer (formato estándar de Google), listo para que la agencia lo lea desde
 * Google Tag Manager con una sola etiqueta "Evento de GA4" (Enviar datos de comercio electrónico → Capa de datos).
 *
 * Eventos: view_item (ficha), view_cart (carrito), begin_checkout (checkout), add_payment_info (al pagar),
 * purchase (página de gracias, una vez por pedido) + add_to_cart / remove_from_cart desde el navegador
 * (carrito flotante y carrito; ver datalayer.js).
 * Cada evento va precedido de { ecommerce: null } (recomendación de Google para no mezclar datos).
 * Compra: solo pagada o en espera; si el pago aún se está confirmando, el navegador pregunta cada 5 s (2 min)
 * a GET bk/v1/dl/purchase y la empuja cuando la pasarela confirma. Se marca en el pedido (`_bk_dl_purchase`).
 * Enhanced conversions: `user_data` con correo y celular en SHA-256 (nunca en claro).
 * Apagar: add_filter( 'bk_datalayer_enabled', '__return_false' ).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_DL_SENT = '_bk_dl_purchase';

add_action( 'wp_head', 'bk_dl_head', 2 );
add_action( 'wp_enqueue_scripts', 'bk_dl_assets', 30 );
add_action( 'rest_api_init', 'bk_dl_routes' );

/**
 * ¿Activo?
 *
 * @return bool
 */
function bk_dl_enabled() {
	return (bool) apply_filters( 'bk_datalayer_enabled', true );
}

/**
 * Producto como item de GA4.
 *
 * @param WC_Product $p     Producto o variación.
 * @param int        $qty   Cantidad.
 * @param float|null $price Precio unitario (por defecto, el que ve la clienta).
 * @return array
 */
function bk_dl_item( $p, $qty = 1, $price = null ) {
	$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
	$parent = $parent ? $parent : $p;
	$item   = array(
		'item_id'    => (string) ( $parent->get_sku() ? $parent->get_sku() : $parent->get_id() ),
		'item_name'  => wp_strip_all_tags( $parent->get_name() ),
		'item_brand' => 'Buckler Jeans',
	);
	$cats = bk_dl_categories( $parent->get_id() );
	foreach ( array_values( $cats ) as $i => $c ) {
		$item[ 0 === $i ? 'item_category' : 'item_category' . ( $i + 1 ) ] = $c;
	}
	if ( $p->is_type( 'variation' ) ) {
		$item['item_variant'] = implode( ' / ', array_filter( array_map( 'strval', $p->get_variation_attributes( false ) ) ) );
	}
	$item['price']    = round( (float) ( null === $price ? wc_get_price_to_display( $p ) : $price ), 2 );
	$item['quantity'] = max( 1, (int) $qty );
	return $item;
}

/**
 * Categorías del modelo: línea (Slim / Curvy) y prenda (Jeans, Faldas…), sin Nuevo ni ¡Sale!.
 *
 * @param int $product_id Producto padre.
 * @return string[]
 */
function bk_dl_categories( $product_id ) {
	static $cache = array();
	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}
	$out  = array();
	$line = bk_product_line( $product_id );
	if ( $line ) {
		$out[] = preg_match( '/^l[ií]nea\b/iu', $line['label'] ) ? $line['label'] : 'Línea ' . $line['label'];
	}
	$skip  = array_merge( array_keys( (array) bk_config( 'lines' ) ), array( (string) bk_config( 'new_cat' ), (string) bk_config( 'sale_cat' ), 'sale', 'linea-nueva', 'uncategorized', 'sin-categorizar' ) );
	$terms = get_the_terms( $product_id, 'product_cat' );
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		if ( in_array( $t->slug, $skip, true ) || preg_match( '/nuevo|sale|nueva/i', $t->slug ) ) {
			continue;
		}
		$name = trim( preg_replace( '/\s*-?\s*(l[ií]nea\s+)?(slim|curvy|plus)\s*$/iu', '', $t->name ) ); // "Jeans Línea Slim" → "Jeans".
		$name = mb_convert_case( mb_strtolower( $name ), MB_CASE_TITLE );
		if ( $name && ! in_array( $name, $out, true ) ) {
			$out[] = $name;
		}
		if ( count( $out ) >= 3 ) {
			break;
		}
	}
	$cache[ $product_id ] = $out;
	return $out;
}

/**
 * Items del carrito actual (clave del carrito → item).
 *
 * @return array{items:array,value:float,keys:array}
 */
function bk_dl_cart() {
	$items = array();
	$keys  = array();
	$value = 0.0;
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array( 'items' => $items, 'value' => $value, 'keys' => $keys );
	}
	foreach ( WC()->cart->get_cart() as $key => $line ) {
		$p = $line['data'] ?? null;
		if ( ! $p instanceof WC_Product ) {
			continue;
		}
		$qty  = (int) $line['quantity'];
		$unit = $qty ? ( (float) $line['line_total'] + ( wc_prices_include_tax() ? (float) $line['line_tax'] : 0 ) ) / $qty : 0;
		$item = bk_dl_item( $p, $qty, $unit ? $unit : null );
		$item['index'] = count( $items );
		$items[]       = $item;
		$keys[ $key ]  = $item;
		$value        += $item['price'] * $qty;
	}
	return array( 'items' => $items, 'value' => round( $value, 2 ), 'keys' => $keys );
}

/**
 * Pedido → evento purchase.
 *
 * @param WC_Order $o Pedido.
 * @return array
 */
function bk_dl_purchase_event( $o ) {
	$items = array();
	foreach ( $o->get_items() as $line ) {
		/** @var WC_Order_Item_Product $line */
		$p = $line->get_product();
		if ( ! $p ) {
			continue;
		}
		$qty           = max( 1, (int) $line->get_quantity() );
		$item          = bk_dl_item( $p, $qty, ( (float) $line->get_total() + ( wc_prices_include_tax() ? (float) $line->get_total_tax() : 0 ) ) / $qty );
		$item['index'] = count( $items );
		$items[]       = $item;
	}
	$coupons = implode( ',', $o->get_coupon_codes() );
	$event   = array(
		'event'     => 'purchase',
		'ecommerce' => array_filter(
			array(
				'transaction_id' => (string) $o->get_order_number(),
				'affiliation'    => 'Buckler Jeans · Tienda online',
				'value'          => round( (float) $o->get_total() - (float) $o->get_shipping_total() - (float) $o->get_total_tax(), 2 ),
				'tax'            => round( (float) $o->get_total_tax(), 2 ),
				'shipping'       => round( (float) $o->get_shipping_total(), 2 ),
				'currency'       => $o->get_currency(),
				'coupon'         => $coupons,
				'payment_type'   => (string) $o->get_payment_method_title(),
				'items'          => $items,
			),
			static fn( $v ) => '' !== $v
		),
		'user_data' => bk_dl_user_data( $o->get_billing_email(), $o->get_billing_phone() ),
	);
	return $event;
}

/**
 * Datos de la clienta para conversiones mejoradas (Google Ads / GA4), solo en SHA-256.
 *
 * @param string $email Correo.
 * @param string $phone Celular.
 * @return array
 */
function bk_dl_user_data( $email, $phone ) {
	$out   = array();
	$email = strtolower( trim( (string) $email ) );
	if ( is_email( $email ) ) {
		$out['sha256_email_address'] = hash( 'sha256', $email );
	}
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	if ( strlen( $digits ) >= 10 ) {
		$digits                     = 10 === strlen( $digits ) ? '57' . $digits : $digits; // Celular colombiano sin indicativo.
		$out['sha256_phone_number'] = hash( 'sha256', '+' . $digits );
	}
	return $out;
}

/**
 * ¿La compra ya cuenta (pagada o en espera de verificación manual)?
 *
 * @param WC_Order $o Pedido.
 * @return bool
 */
function bk_dl_order_counts( $o ) {
	return $o->is_paid() || $o->has_status( 'on-hold' );
}

/**
 * Pedido de la página de gracias (con su clave).
 *
 * @return WC_Order|null
 */
function bk_dl_thankyou_order() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return null;
	}
	$id  = absint( get_query_var( 'order-received' ) );
	$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$o   = $id ? wc_get_order( $id ) : null;
	return $o && $key && hash_equals( $o->get_order_key(), $key ) ? $o : null;
}

/**
 * Eventos de la página (se empujan en el head, antes de que cargue GTM).
 */
function bk_dl_head() {
	if ( ! bk_dl_enabled() ) {
		return;
	}
	$route  = function_exists( 'bk_pages_route' ) ? bk_pages_route() : '';
	$events = array();
	$data   = array();
	if ( 'product' === $route ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			$item     = bk_dl_item( $p );
			$events[] = array( 'event' => 'view_item', 'ecommerce' => array( 'currency' => get_woocommerce_currency(), 'value' => $item['price'], 'items' => array( $item ) ) );
			$data['product'] = $item;
		}
	} elseif ( 'cart' === $route ) {
		$c = bk_dl_cart();
		if ( $c['items'] ) {
			$events[]     = array( 'event' => 'view_cart', 'ecommerce' => array( 'currency' => get_woocommerce_currency(), 'value' => $c['value'], 'items' => $c['items'] ) );
			$data['cart'] = $c['keys'];
		}
	} elseif ( 'checkout' === $route ) {
		$o = bk_dl_thankyou_order();
		if ( $o ) {
			if ( ! $o->get_meta( BK_DL_SENT ) && bk_dl_order_counts( $o ) ) {
				$events[] = bk_dl_purchase_event( $o );
				$o->update_meta_data( BK_DL_SENT, time() );
				$o->save_meta_data();
			} elseif ( ! $o->get_meta( BK_DL_SENT ) && $o->has_status( array( 'pending', 'failed' ) ) ) {
				// Pasarela aún confirmando: el navegador pregunta hasta que se pague.
				$data['wait'] = array( 'url' => rest_url( 'bk/v1/dl/purchase' ), 'order' => $o->get_id(), 'key' => $o->get_order_key() );
			}
		} elseif ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$c                = bk_dl_cart();
			$events[]         = array( 'event' => 'begin_checkout', 'ecommerce' => array( 'currency' => get_woocommerce_currency(), 'value' => $c['value'], 'coupon' => implode( ',', WC()->cart->get_applied_coupons() ), 'items' => $c['items'] ) );
			$data['checkout'] = array( 'value' => $c['value'], 'items' => $c['items'] );
		}
	}
	$data['currency'] = get_woocommerce_currency();
	$js               = 'window.dataLayer=window.dataLayer||[];window.bkDL=' . wp_json_encode( $data ) . ';';
	foreach ( $events as $e ) {
		$js .= 'dataLayer.push({ecommerce:null});dataLayer.push(' . wp_json_encode( $e ) . ');';
	}
	echo '<script id="bk-datalayer">' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON codificado.
}

/**
 * JS del navegador (add_to_cart, remove_from_cart, add_payment_info, compra en espera).
 */
function bk_dl_assets() {
	if ( ! bk_dl_enabled() || ! function_exists( 'bk_pages_route' ) || ! bk_pages_route() ) {
		return;
	}
	wp_enqueue_script( 'bk-datalayer', BK_URL . 'assets/js/datalayer.js', array(), BK_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

/**
 * Rutas.
 */
function bk_dl_routes() {
	register_rest_route(
		'bk/v1',
		'/dl/purchase',
		array(
			'methods'             => 'GET',
			'callback'            => 'bk_dl_purchase_poll',
			'permission_callback' => '__return_true', // Protegido por la clave del pedido.
		)
	);
}

/**
 * Compra confirmada después de cargar la página de gracias (pasarela lenta).
 *
 * @param WP_REST_Request $r Petición (order, key).
 * @return WP_REST_Response
 */
function bk_dl_purchase_poll( WP_REST_Request $r ) {
	nocache_headers();
	$o   = wc_get_order( absint( $r['order'] ) );
	$key = (string) $r['key'];
	if ( ! $o || ! $key || ! hash_equals( $o->get_order_key(), $key ) ) {
		return new WP_REST_Response( array( 'status' => 'invalid' ), 404 );
	}
	if ( $o->get_meta( BK_DL_SENT ) ) {
		return rest_ensure_response( array( 'status' => 'sent' ) );
	}
	if ( ! bk_dl_order_counts( $o ) ) {
		return rest_ensure_response( array( 'status' => $o->get_status() ) );
	}
	$o->update_meta_data( BK_DL_SENT, time() );
	$o->save_meta_data();
	return rest_ensure_response( array( 'status' => 'paid', 'event' => bk_dl_purchase_event( $o ) ) );
}
