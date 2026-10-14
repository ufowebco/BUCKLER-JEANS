<?php
/**
 * Gestión de tienda: API REST (namespace bk/v1/gestion).
 *
 * Permisos: capacidad `bk_gestion` (rol "Gestor de tienda") o `manage_woocommerce` (administradores y
 * gestores de WooCommerce). Autenticación por cookie + nonce wp_rest (lo envía la página /gestion-tienda/).
 * Compatible con HPOS: todo pasa por wc_get_orders() / WC_Order.
 *
 * Campos de pedido configurables (Ajustes del dashboard, solo administradores):
 *   consecutivo: meta del consecutivo actual (vacío = no se muestra)
 *   impreso:     meta del check "Impreso"
 *   cedula:      metas donde se guarda la cédula (para buscar)
 *   adjuntos:    repeater ACF de archivos del pedido (archivos_adjuntos)
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_g_routes' );

/**
 * ¿Puede usar el dashboard?
 *
 * @return bool
 */
function bk_g_can() {
	return current_user_can( 'bk_gestion' ) || current_user_can( 'manage_woocommerce' );
}

/**
 * Campos de pedido configurables.
 *
 * @return array{consecutivo:string,impreso:string,cedula:string[],adjuntos:string}
 */
function bk_g_fields() {
	$d = array(
		'consecutivo' => '',
		'impreso'     => '_bk_impreso',
		'cedula'      => array( 'billing_cedula', '_billing_cedula', 'cedula', '_cedula', 'billing_document', '_billing_document', 'billing_nit', '_billing_nit', 'billing_dni', '_billing_dni' ),
		'adjuntos'    => 'archivos_adjuntos',
	);
	$s = (array) get_option( 'bk_gestion_fields', array() );
	$f = array_merge( $d, array_intersect_key( $s, $d ) );
	$f['cedula'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $f['cedula'] ) ) );
	return $f;
}

/**
 * Registro de rutas.
 */
function bk_g_routes() {
	$ns   = 'bk/v1';
	$perm = 'bk_g_can';
	register_rest_route( $ns, '/gestion/summary', array( 'methods' => 'GET', 'callback' => 'bk_g_summary', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/gestion/orders', array( 'methods' => 'GET', 'callback' => 'bk_g_orders', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/gestion/orders/(?P<id>\d+)',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_g_order', 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => 'bk_g_order_update', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/gestion/products', array( 'methods' => 'GET', 'callback' => 'bk_g_products', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/gestion/products/(?P<id>\d+)',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_g_product', 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => 'bk_g_product_update', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/gestion/variations/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'bk_g_variation_update', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/gestion/settings',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_g_settings', 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ),
			array( 'methods' => 'POST', 'callback' => 'bk_g_settings_save', 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ),
		)
	);
}

/**
 * Precio en texto ("$149.900").
 *
 * @param float $n Valor.
 * @return string
 */
function bk_g_money( $n ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $n ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * IDs de pedidos cuyo meta coincide, con UNA consulta indexada (HPOS o clásico).
 *
 * No se usa meta_query con varias claves en OR: en HPOS cada cláusula agrega un JOIN sobre
 * wc_orders_meta y la consulta crece de forma combinatoria (con 10 claves cuelga la base de datos).
 *
 * @param string[] $keys  Claves de meta.
 * @param string[] $values Valores exactos (o un patrón si $like).
 * @param bool     $like  Coincidencia parcial con el primer valor.
 * @param int      $limit Máximo.
 * @return int[]
 */
function bk_g_meta_ids( $keys, $values, $like = false, $limit = 500 ) {
	global $wpdb;
	$keys = array_values( array_filter( (array) $keys ) );
	if ( ! $keys || ! $values ) {
		return array();
	}
	$hpos  = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	$col   = $hpos ? 'order_id' : 'post_id';
	$kph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	if ( $like ) {
		$sql  = "SELECT DISTINCT {$col} FROM {$table} WHERE meta_key IN ({$kph}) AND meta_value LIKE %s LIMIT %d";
		$args = array_merge( $keys, array( '%' . $wpdb->esc_like( (string) reset( $values ) ) . '%', $limit ) );
	} else {
		$vph  = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$sql  = "SELECT DISTINCT {$col} FROM {$table} WHERE meta_key IN ({$kph}) AND meta_value IN ({$vph}) LIMIT %d";
		$args = array_merge( $keys, array_map( 'strval', $values ), array( $limit ) );
	}
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB
}

const BK_G_TRUE = array( '1', 'yes', 'true', 'si', 'sí', 'on' );

/* ---------------------------------------------------------------------------
 * Resumen
 * ------------------------------------------------------------------------- */

/**
 * Ventas de hoy y del mes, pendientes y tallas por agotarse.
 *
 * @return WP_REST_Response
 */
function bk_g_summary() {
	$cached = get_transient( 'bk_g_summary' );
	if ( is_array( $cached ) ) {
		return rest_ensure_response( $cached );
	}
	$tz    = wp_timezone();
	$today = ( new DateTimeImmutable( 'today', $tz ) )->getTimestamp();
	$month = ( new DateTimeImmutable( 'first day of this month 00:00', $tz ) )->getTimestamp();
	$paid  = array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() );

	$sum = static function ( $from ) use ( $paid ) {
		$total = 0.0;
		$count = 0;
		$page  = 1;
		do {
			$orders = wc_get_orders( array( 'status' => $paid, 'date_created' => '>=' . $from, 'limit' => 200, 'paged' => $page, 'type' => 'shop_order' ) );
			foreach ( $orders as $o ) {
				$total += (float) $o->get_total();
				++$count;
			}
			++$page;
		} while ( count( $orders ) === 200 );
		return array( 'total' => bk_g_money( $total ), 'count' => $count );
	};

	$pending = array();
	foreach ( array( 'processing', 'on-hold', 'pending' ) as $st ) {
		$pending[ $st ] = count( wc_get_orders( array( 'status' => 'wc-' . $st, 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' ) ) );
	}

	// Tallas con 1 o 2 unidades.
	$low = array();
	$q   = new WP_Query(
		array(
			'post_type'      => 'product_variation',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array( 'key' => '_manage_stock', 'value' => 'yes' ),
				array( 'key' => '_stock', 'value' => array( 1, 2 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ),
			),
		)
	);
	foreach ( $q->posts as $vid ) {
		$v = wc_get_product( $vid );
		$p = $v ? wc_get_product( $v->get_parent_id() ) : null;
		if ( $p && 'publish' === $p->get_status() ) {
			$low[] = array(
				'id'    => $p->get_id(),
				'name'  => $p->get_name(),
				'sku'   => $p->get_sku(),
				'size'  => wc_get_formatted_variation( $v, true, false, false ),
				'stock' => (int) $v->get_stock_quantity(),
			);
		}
	}

	$data = array(
		'today'   => $sum( $today ),
		'month'   => $sum( $month ),
		'pending' => $pending,
		'low'     => $low,
	);
	set_transient( 'bk_g_summary', $data, 5 * MINUTE_IN_SECONDS );
	return rest_ensure_response( $data );
}

/* ---------------------------------------------------------------------------
 * Pedidos
 * ------------------------------------------------------------------------- */

/**
 * Fila de pedido para la lista.
 *
 * @param WC_Order $o Pedido.
 * @return array
 */
function bk_g_order_row( $o ) {
	$f = bk_g_fields();
	return array(
		'id'          => $o->get_id(),
		'number'      => $o->get_order_number(),
		'date'        => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'j M Y, g:i a' ) : '',
		'status'      => $o->get_status(),
		'customer'    => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
		'city'        => $o->get_billing_city(),
		'total'       => bk_g_money( $o->get_total() ),
		'items'       => (int) $o->get_item_count(),
		'payment'     => $o->get_payment_method_title(),
		'printed'     => $f['impreso'] ? in_array( strtolower( (string) $o->get_meta( $f['impreso'] ) ), BK_G_TRUE, true ) : false,
		'consecutivo' => $f['consecutivo'] ? (string) $o->get_meta( $f['consecutivo'] ) : '',
		'tracking'    => function_exists( 'bk_ship_get' ) ? bk_ship_get( $o ) : null,
	);
}

/**
 * Lista de pedidos con filtros.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_g_orders( WP_REST_Request $r ) {
	$f      = bk_g_fields();
	$status = sanitize_key( (string) $r['status'] );
	$q      = trim( sanitize_text_field( (string) $r['q'] ) );
	$page   = max( 1, (int) $r['page'] );
	$args   = array(
		'limit'    => 25,
		'paged'    => $page,
		'paginate' => true,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'type'     => 'shop_order',
		'status'   => $status && 'all' !== $status ? array( 'wc-' . $status ) : array_keys( wc_get_order_statuses() ),
	);
	if ( $r['from'] ) {
		$args['date_created'] = sanitize_text_field( (string) $r['from'] ) . '...' . sanitize_text_field( (string) ( $r['to'] ? $r['to'] : gmdate( 'Y-m-d' ) ) );
	}
	if ( '' !== (string) $r['printed'] && $f['impreso'] ) {
		$printed = bk_g_meta_ids( array( $f['impreso'] ), BK_G_TRUE, false, 5000 );
		if ( 'yes' === $r['printed'] ) {
			$args['post__in'] = $printed ? $printed : array( 0 );
		} elseif ( $printed ) {
			$args['post__not_in'] = $printed;
		}
	}

	if ( '' !== $q ) {
		$num = ltrim( $q, '#' );
		if ( ctype_digit( $num ) ) {
			// Número de pedido o cédula (dígitos): se busca por ambos.
			$ids = array();
			if ( wc_get_order( (int) $num ) ) {
				$ids[] = (int) $num;
			}
			$ids = array_merge( $ids, bk_g_meta_ids( $f['cedula'], array( $num ), true, 100 ) );
			if ( $f['consecutivo'] ) {
				$ids = array_merge( $ids, bk_g_meta_ids( array( $f['consecutivo'] ), array( $num ), false, 20 ) );
			}
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			$res = array( 'orders' => array(), 'total' => count( $ids ), 'max_num_pages' => 1 );
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( $o && 'shop_order' === $o->get_type() ) {
					$res['orders'][] = $o;
				}
			}
			$res = (object) $res;
		} else {
			$args['s'] = $q; // Nombre, correo, teléfono, dirección, productos.
			$res       = wc_get_orders( $args );
		}
	} else {
		$res = wc_get_orders( $args );
	}

	return rest_ensure_response(
		array(
			'orders' => array_map( 'bk_g_order_row', $res->orders ),
			'total'  => (int) $res->total,
			'pages'  => (int) $res->max_num_pages,
		)
	);
}

/**
 * Archivos adjuntos (repeater ACF) de un pedido, leídos de la meta del pedido.
 *
 * @param WC_Order $o Pedido.
 * @return array
 */
function bk_g_attachments( $o ) {
	$f    = bk_g_fields();
	$name = $f['adjuntos'];
	$out  = array();
	if ( ! $name ) {
		return $out;
	}
	foreach ( $o->get_meta_data() as $m ) {
		$d = $m->get_data();
		if ( preg_match( '/^' . preg_quote( $name, '/' ) . '_\d+_/', (string) $d['key'] ) && is_numeric( $d['value'] ) && (int) $d['value'] > 0 ) {
			$id  = (int) $d['value'];
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$out[] = array(
					'id'    => $id,
					'url'   => $url,
					'thumb' => wp_attachment_is_image( $id ) ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '',
					'name'  => basename( (string) get_attached_file( $id ) ),
				);
			}
		}
	}
	return $out;
}

/**
 * Detalle de un pedido.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_order( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$f      = bk_g_fields();
	$cedula = '';
	foreach ( $f['cedula'] as $key ) {
		$cedula = (string) $o->get_meta( $key );
		if ( '' !== $cedula ) {
			break;
		}
	}
	$items = array();
	foreach ( $o->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$p       = $item->get_product();
		$items[] = array(
			'name'  => $item->get_name(),
			'sku'   => $p ? $p->get_sku() : '',
			'qty'   => (int) $item->get_quantity(),
			'total' => bk_g_money( (float) $item->get_total() + (float) $item->get_total_tax() ),
			'meta'  => wp_strip_all_tags( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ', 'before' => '', 'after' => '' ) ) ),
			'image' => $p ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
		);
	}
	$notes = array();
	foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id(), 'limit' => 20 ) ) as $n ) {
		$notes[] = array(
			'content'  => wp_strip_all_tags( $n->content ),
			'date'     => $n->date_created ? $n->date_created->date_i18n( 'j M, g:i a' ) : '',
			'customer' => (bool) $n->customer_note,
			'by'       => $n->added_by,
		);
	}
	return rest_ensure_response(
		array_merge(
			bk_g_order_row( $o ),
			array(
				'email'       => $o->get_billing_email(),
				'phone'       => $o->get_billing_phone(),
				'cedula'      => $cedula,
				'address'     => wp_strip_all_tags( str_replace( '<br/>', ', ', $o->get_formatted_shipping_address() ? $o->get_formatted_shipping_address() : $o->get_formatted_billing_address() ) ),
				'note'        => $o->get_customer_note(),
				'items'       => $items,
				'subtotal'    => bk_g_money( $o->get_subtotal() ),
				'shipping'    => bk_g_money( $o->get_shipping_total() ),
				'discount'    => (float) $o->get_discount_total() > 0 ? '−' . bk_g_money( $o->get_discount_total() ) : '',
				'notes'       => $notes,
				'attachments' => bk_g_attachments( $o ),
				'edit_url'    => $o->get_edit_order_url(),
			)
		)
	);
}

/**
 * Cambia estado, impreso o añade una nota.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_order_update( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$f    = bk_g_fields();
	$user = wp_get_current_user()->display_name;
	if ( null !== $r['status'] ) {
		$status = sanitize_key( (string) $r['status'] );
		if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			return new WP_Error( 'bk_bad_status', 'Estado no válido.', array( 'status' => 400 ) );
		}
		/* translators: %s: usuario */
		$o->update_status( $status, sprintf( __( 'Cambiado desde Gestión de tienda por %s.', 'buckler-core' ), $user ) );
	}
	if ( null !== $r['printed'] && $f['impreso'] ) {
		$o->update_meta_data( $f['impreso'], rest_sanitize_boolean( $r['printed'] ) ? 'yes' : 'no' );
		$o->save();
	}
	if ( $r['note'] ) {
		$o->add_order_note( sanitize_textarea_field( (string) $r['note'] ), rest_sanitize_boolean( $r['customer_note'] ), true );
	}
	delete_transient( 'bk_g_summary' );
	return bk_g_order( $r );
}

/* ---------------------------------------------------------------------------
 * Productos
 * ------------------------------------------------------------------------- */

/**
 * Lista de productos.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_g_products( WP_REST_Request $r ) {
	$args = array(
		'status'   => array( 'publish', 'draft', 'private' ),
		'limit'    => 30,
		'page'     => max( 1, (int) $r['page'] ),
		'paginate' => true,
		'orderby'  => 'date',
		'order'    => 'DESC',
	);
	$q = trim( sanitize_text_field( (string) $r['q'] ) );
	if ( '' !== $q ) {
		$by_sku = wc_get_products( array( 'sku' => $q, 'limit' => 50, 'return' => 'ids', 'status' => $args['status'] ) );
		$by_s   = wc_get_products( array( 's' => $q, 'limit' => 50, 'return' => 'ids', 'status' => $args['status'] ) );
		$args['include'] = array_values( array_unique( array_merge( $by_sku, $by_s ) ) ) ?: array( 0 );
	}
	if ( $r['line'] ) {
		$args['category'] = array( sanitize_title( (string) $r['line'] ) );
	}
	if ( $r['stock'] ) {
		$args['stock_status'] = 'out' === $r['stock'] ? 'outofstock' : 'instock';
	}
	$res  = wc_get_products( $args );
	$rows = array();
	foreach ( $res->products as $p ) {
		$line   = bk_product_line( $p->get_id() );
		$rows[] = array(
			'id'       => $p->get_id(),
			'name'     => $p->get_name(),
			'sku'      => $p->get_sku(),
			'status'   => $p->get_status(),
			'image'    => (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ),
			'price'    => wp_strip_all_tags( html_entity_decode( $p->get_price_html(), ENT_QUOTES, 'UTF-8' ) ),
			'stock'    => $p->get_stock_status(),
			'featured' => $p->is_featured(),
			'line'     => $line ? $line['label'] : '',
			'url'      => $p->get_permalink(),
		);
	}
	return rest_ensure_response( array( 'products' => $rows, 'total' => (int) $res->total, 'pages' => (int) $res->max_num_pages ) );
}

/**
 * Variaciones (tallas) de un producto con precio y stock.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_product( WP_REST_Request $r ) {
	$p = wc_get_product( (int) $r['id'] );
	if ( ! $p ) {
		return new WP_Error( 'bk_not_found', 'Producto no encontrado.', array( 'status' => 404 ) );
	}
	$vars = array();
	foreach ( $p->is_type( 'variable' ) ? $p->get_children() : array() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) {
			continue;
		}
		$vars[] = array(
			'id'      => $v->get_id(),
			'size'    => wc_get_formatted_variation( $v, true, false, false ),
			'regular' => $v->get_regular_price( 'edit' ),
			'sale'    => $v->get_sale_price( 'edit' ),
			'manage'  => $v->managing_stock(),
			'stock'   => $v->get_stock_quantity(),
			'status'  => $v->get_stock_status(),
		);
	}
	return rest_ensure_response(
		array(
			'id'         => $p->get_id(),
			'name'       => $p->get_name(),
			'featured'   => $p->is_featured(),
			'variations' => $vars,
			'edit_url'   => get_edit_post_link( $p->get_id(), 'raw' ),
		)
	);
}

/**
 * Destacado (= etiqueta "Nuevo") de un producto.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_product_update( WP_REST_Request $r ) {
	$p = wc_get_product( (int) $r['id'] );
	if ( ! $p ) {
		return new WP_Error( 'bk_not_found', 'Producto no encontrado.', array( 'status' => 404 ) );
	}
	if ( null !== $r['featured'] ) {
		if ( function_exists( 'bk_gp_set_new' ) ) {
			bk_gp_set_new( $p, rest_sanitize_boolean( $r['featured'] ) ); // Destacado + categorías de Línea nueva.
		} else {
			$p->set_featured( rest_sanitize_boolean( $r['featured'] ) );
			$p->save();
		}
	}
	return bk_g_product( $r );
}

/**
 * Precio, precio rebajado y stock de una talla.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_variation_update( WP_REST_Request $r ) {
	$v = wc_get_product( (int) $r['id'] );
	if ( ! $v || ! $v->is_type( 'variation' ) ) {
		return new WP_Error( 'bk_not_found', 'Talla no encontrada.', array( 'status' => 404 ) );
	}
	$num = static fn( $x ) => '' === (string) $x ? '' : wc_format_decimal( $x, wc_get_price_decimals() );
	if ( null !== $r['regular'] ) {
		$v->set_regular_price( $num( $r['regular'] ) );
	}
	if ( null !== $r['sale'] ) {
		$sale = $num( $r['sale'] );
		if ( '' !== $sale && (float) $sale >= (float) $v->get_regular_price( 'edit' ) ) {
			return new WP_Error( 'bk_bad_price', 'El precio rebajado debe ser menor que el precio normal.', array( 'status' => 400 ) );
		}
		$v->set_sale_price( $sale );
	}
	if ( null !== $r['stock'] && '' !== (string) $r['stock'] ) {
		$v->set_manage_stock( true );
		$v->set_stock_quantity( max( 0, (int) $r['stock'] ) );
	}
	$v->save();
	WC_Product_Variable::sync( $v->get_parent_id() );
	delete_transient( 'bk_g_summary' );
	return rest_ensure_response(
		array(
			'id'      => $v->get_id(),
			'regular' => $v->get_regular_price( 'edit' ),
			'sale'    => $v->get_sale_price( 'edit' ),
			'stock'   => $v->get_stock_quantity(),
			'status'  => $v->get_stock_status(),
		)
	);
}

/* ---------------------------------------------------------------------------
 * Ajustes y detector de campos (solo administradores)
 * ------------------------------------------------------------------------- */

/**
 * Ajustes actuales + claves de meta encontradas en los últimos pedidos, con un ejemplo de valor.
 * Sirve para identificar en producción dónde se guardan el consecutivo, la cédula y el "impreso".
 *
 * @return WP_REST_Response
 */
function bk_g_settings() {
	$found = array();
	foreach ( wc_get_orders( array( 'limit' => 40, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
		foreach ( $o->get_meta_data() as $m ) {
			$d   = $m->get_data();
			$key = (string) $d['key'];
			if ( isset( $found[ $key ] ) || is_array( $d['value'] ) || is_object( $d['value'] ) ) {
				if ( isset( $found[ $key ] ) ) {
					++$found[ $key ]['orders'];
				}
				continue;
			}
			$found[ $key ] = array(
				'key'     => $key,
				'example' => mb_substr( (string) $d['value'], 0, 60 ),
				'orders'  => 1,
			);
		}
	}
	ksort( $found );
	return rest_ensure_response( array( 'fields' => bk_g_fields(), 'meta' => array_values( $found ) ) );
}

/**
 * Guarda los campos configurables.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_g_settings_save( WP_REST_Request $r ) {
	update_option(
		'bk_gestion_fields',
		array(
			'consecutivo' => sanitize_text_field( (string) $r['consecutivo'] ),
			'impreso'     => sanitize_text_field( (string) $r['impreso'] ),
			'cedula'      => array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $r['cedula'] ) ) ) ),
			'adjuntos'    => sanitize_text_field( (string) $r['adjuntos'] ),
		),
		false
	);
	return bk_g_settings();
}
