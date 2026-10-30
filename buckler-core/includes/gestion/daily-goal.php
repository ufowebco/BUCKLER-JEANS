<?php
/**
 * Meta diaria de ventas en el Resumen de la gestión (pedido del cliente 2026-10-30: la meta de la empresa es 15 ventas
 * al día). Ventas = pedidos pagados (estados pagados de WooCommerce) por día de creación, en la zona horaria del sitio.
 * Se muestran hoy y los últimos 10 días frente a la meta, con mensajes de ánimo (todo el texto lo arma gestion.js).
 *
 * Meta: opción bk_g_daily_goal (la cambia el equipo desde el Resumen); por defecto bk_config('daily_goal') o 15.
 * Datos: bk_g_daily_sales() dentro de bk_g_summary() (transitorio de 5 min que se borra con cada pedido nuevo).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta diaria de ventas (número de pedidos pagados).
 *
 * @return int
 */
function bk_g_daily_goal() {
	$goal = (int) get_option( 'bk_g_daily_goal', 0 );
	if ( $goal < 1 ) {
		$goal = (int) ( bk_config( 'daily_goal' ) ?: 15 );
	}
	return (int) apply_filters( 'bk_g_daily_goal', max( 1, $goal ) );
}

/**
 * Ventas pagadas por día de los últimos $days días (incluye hoy), del más viejo al más nuevo.
 * Con HPOS: una consulta que trae solo fecha y total; sin HPOS: wc_get_orders.
 *
 * @param int $days Días.
 * @return array<int,array{date:string,count:int,total:string,sum:float}>
 */
function bk_g_daily_sales( $days = 10 ) {
	global $wpdb;
	$tz    = wp_timezone();
	$days  = max( 1, (int) $days );
	$start = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-' . ( $days - 1 ) . ' days' );
	$paid  = array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() );

	$out = array();
	for ( $i = 0; $i < $days; $i++ ) {
		$d         = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
		$out[ $d ] = array( 'date' => $d, 'count' => 0, 'sum' => 0.0 );
	}

	$rows = array();
	if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$table = $wpdb->prefix . 'wc_orders';
		$ph    = implode( ',', array_fill( 0, count( $paid ), '%s' ) );
		$res   = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT date_created_gmt AS d, total_amount AS t FROM {$table} WHERE type = 'shop_order' AND status IN ({$ph}) AND date_created_gmt >= %s", // phpcs:ignore WordPress.DB
				array_merge( $paid, array( gmdate( 'Y-m-d H:i:s', $start->getTimestamp() ) ) )
			)
		);
		foreach ( (array) $res as $r ) {
			$rows[] = array( strtotime( $r->d . ' UTC' ), (float) $r->t );
		}
	} else {
		$page = 1;
		do {
			$orders = wc_get_orders( array( 'status' => $paid, 'date_created' => '>=' . $start->getTimestamp(), 'limit' => 200, 'paged' => $page, 'type' => 'shop_order' ) );
			foreach ( $orders as $o ) {
				$rows[] = array( $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0, (float) $o->get_total() );
			}
			++$page;
		} while ( count( $orders ) === 200 );
	}

	foreach ( $rows as list( $ts, $total ) ) {
		$d = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'Y-m-d' );
		if ( isset( $out[ $d ] ) ) {
			++$out[ $d ]['count'];
			$out[ $d ]['sum'] += $total;
		}
	}
	foreach ( $out as &$day ) {
		$day['total'] = bk_g_money( $day['sum'] );
	}
	unset( $day );
	return array_values( $out );
}

/**
 * Bloque "goal" del resumen.
 *
 * @return array{goal:int,days:array,today:string}
 */
function bk_g_goal_data() {
	return array(
		'goal'  => bk_g_daily_goal(),
		'days'  => bk_g_daily_sales( (int) apply_filters( 'bk_g_daily_days', 10 ) ),
		'today' => ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' ),
	);
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'bk/v1',
			'/gestion/goal',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $r ) {
					$goal = (int) $r->get_param( 'goal' );
					if ( $goal < 1 || $goal > 1000 ) {
						return new WP_Error( 'bk_goal', __( 'Escribe una meta entre 1 y 1000 ventas al día.', 'buckler-core' ), array( 'status' => 400 ) );
					}
					update_option( 'bk_g_daily_goal', $goal, false );
					delete_transient( 'bk_g_summary' );
					return rest_ensure_response( array( 'goal' => $goal ) );
				},
				'permission_callback' => 'bk_g_can',
			)
		);
	}
);
