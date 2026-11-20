<?php
/**
 * Pedidos de la misma clienta (pedido del cliente 2026-11-20): en el detalle de un pedido se listan los demás pedidos
 * que comparten **cédula, correo o celular**, con cuántas compras lleva, cuánto ha pagado en total y desde cuándo
 * compra. Sirve para reconocer clientas que vuelven (aunque compren como invitadas) y revisar su historial.
 *
 * Ruta: GET bk/v1/gestion/orders/{id}/related (se pide aparte para no hacer más lento el detalle).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( 'bk/v1', '/gestion/orders/(?P<id>\d+)/related', array( 'methods' => 'GET', 'callback' => 'bk_gco_related', 'permission_callback' => 'bk_g_can' ) );
	}
);

/**
 * Celular en 10 dígitos (sin +57, espacios ni guiones), o '' si no parece un celular.
 *
 * @param string $phone Teléfono.
 * @return string
 */
function bk_gco_phone( $phone ) {
	$d = preg_replace( '/\D+/', '', (string) $phone );
	if ( 12 === strlen( $d ) && 0 === strpos( $d, '57' ) ) {
		$d = substr( $d, 2 );
	}
	return strlen( $d ) >= 7 ? $d : '';
}

/**
 * IDs de pedidos que comparten cédula, correo o celular con el pedido dado (incluido él), con el motivo de cada uno.
 *
 * @param WC_Order $o Pedido.
 * @return array<int,string[]> id => ['cedula','correo','celular']
 */
function bk_gco_ids( $o ) {
	$f     = bk_g_fields();
	$found = array();
	$add   = static function ( $ids, $why ) use ( &$found ) {
		foreach ( (array) $ids as $id ) {
			$found[ (int) $id ][] = $why;
		}
	};
	$limit = (int) apply_filters( 'bk_gco_limit', 60 );

	$ced = '';
	foreach ( (array) $f['cedula'] as $key ) {
		$ced = trim( (string) $o->get_meta( $key ) );
		if ( '' !== $ced ) {
			break;
		}
	}
	$raw = $ced;
	$ced = preg_replace( '/[\s.\-]+/', '', $ced );
	if ( strlen( $ced ) >= 5 && function_exists( 'bk_g_meta_ids' ) ) {
		// Mismo número con o sin puntos/espacios (1.036.111.222 = 1036111222).
		$variants = array( $ced, $raw );
		if ( ctype_digit( $ced ) ) {
			$variants[] = number_format( (float) $ced, 0, ',', '.' );
		}
		$add( bk_g_meta_ids( $f['cedula'], array_values( array_unique( $variants ) ), false, $limit ), 'cedula' );
	}

	$email = strtolower( trim( (string) $o->get_billing_email() ) );
	if ( is_email( $email ) ) {
		$add( wc_get_orders( array( 'billing_email' => $email, 'limit' => $limit, 'return' => 'ids', 'type' => 'shop_order', 'status' => 'any' ) ), 'correo' );
	}

	$phone = bk_gco_phone( $o->get_billing_phone() );
	if ( $phone ) {
		$add( bk_gco_phone_ids( $phone, $limit ), 'celular' );
	}
	foreach ( $found as $id => $why ) {
		$found[ $id ] = array_values( array_unique( $why ) );
	}
	return $found;
}

/**
 * Pedidos cuyo celular de facturación termina en esos dígitos, sin importar cómo se escribió
 * ("300 111 2233", "+57 300-111-2233", "573001112233"…).
 *
 * @param string $digits Celular normalizado.
 * @param int    $limit  Máximo.
 * @return int[]
 */
function bk_gco_phone_ids( $digits, $limit ) {
	global $wpdb;
	$clean = static fn( $col ) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$col},' ',''),'-',''),'+',''),'.',''),'(',''),')','')";
	$hpos  = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos ) {
		$sql = "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type = 'billing' AND phone <> '' AND " . $clean( 'phone' ) . ' LIKE %s LIMIT %d';
	} else {
		$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_billing_phone' AND meta_value <> '' AND " . $clean( 'meta_value' ) . ' LIKE %s LIMIT %d';
	}
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, '%' . $wpdb->esc_like( $digits ), $limit ) ) ); // phpcs:ignore WordPress.DB
}

/**
 * Historial de la clienta para el detalle del pedido.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gco_related( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$ids   = bk_gco_ids( $o );
	$ids[ $o->get_id() ] = $ids[ $o->get_id() ] ?? array();
	$rows  = array();
	$paid  = 0.0;
	$npaid = 0;
	$first = null;
	foreach ( array_keys( $ids ) as $id ) {
		$x = $id === $o->get_id() ? $o : wc_get_order( $id );
		if ( ! $x || 'shop_order' !== $x->get_type() || 'trash' === $x->get_status() ) {
			continue;
		}
		$t = $x->get_date_created() ? $x->get_date_created()->getTimestamp() : 0;
		if ( $x->is_paid() ) {
			$paid += (float) $x->get_total();
			++$npaid;
		}
		if ( $t && ( null === $first || $t < $first ) ) {
			$first = $t;
		}
		if ( $id === $o->get_id() ) {
			continue;
		}
		$rows[] = array(
			'id'     => $x->get_id(),
			'number' => $x->get_order_number(),
			't'      => $t,
			'date'   => $t ? wp_date( 'j M Y', $t ) : '',
			'status' => $x->get_status(),
			'label'  => wc_get_order_status_name( $x->get_status() ),
			'total'  => bk_g_money( (float) $x->get_total() ),
			'items'  => (int) $x->get_item_count(),
			'match'  => $ids[ $id ],
		);
	}
	usort( $rows, static fn( $a, $b ) => $b['t'] <=> $a['t'] );
	$count = count( $rows ) + 1;
	return rest_ensure_response(
		array(
			'count'      => $count,
			'paid'       => $npaid,
			'paid_total' => bk_g_money( $paid ),
			'since'      => $first ? wp_date( 'F Y', $first ) : '',
			'orders'     => $rows,
		)
	);
}
