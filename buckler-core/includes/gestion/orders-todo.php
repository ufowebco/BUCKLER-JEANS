<?php
/**
 * Pedidos por marcar (2026-11-28, pedido del cliente): píldoras al lado de "Pedidos" con cuántos pedidos faltan por
 * marcar Pago confirmado, Impreso y Despachado; al tocar una, la lista muestra solo esos.
 *
 * Cuenta pedidos activos de los últimos 30 días (filtro bk_g_todo_days): estados pagados (bk_g_paid_statuses(), incluye
 * estados propios de pasarelas como "Transacción Aprobada") + En espera. Sin cancelados, fallidos, reembolsados ni
 * pendientes de pago.
 *
 * También define bk_g_paid_statuses(): la meta diaria y el resumen la usan para contar ventas pagadas.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Estados que cuentan como venta pagada (con prefijo wc-).
 *
 * Los de WooCommerce (Procesando, Completado) + los estados propios que existen en la tabla de pedidos y no suenan a
 * "sin pagar" (p. ej. "Transacción Aprobada" de una pasarela). En la API rápida esos estados no están registrados,
 * por eso se leen de la tabla (bk_g_all_statuses) y se juzgan por su nombre interno y su etiqueta si existe.
 *
 * @return string[]
 */
function bk_g_paid_statuses() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out  = array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() );
	$core = array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft' );
	$no   = '/pend|wait|espera|fail|fall|cancel|anul|reemb|refund|rechaz|declin|draft|borrador|devol|return|expir|venc/i';
	$all  = function_exists( 'bk_g_all_statuses' ) ? bk_g_all_statuses() : array();
	$lbl  = wc_get_order_statuses();
	foreach ( $all as $st ) {
		if ( in_array( $st, $core, true ) || in_array( $st, $out, true ) ) {
			continue;
		}
		$name = $st . ' ' . ( $lbl[ $st ] ?? '' );
		if ( ! preg_match( $no, remove_accents( $name ) ) ) {
			$out[] = $st;
		}
	}
	$out = array_values( array_unique( (array) apply_filters( 'bk_g_paid_statuses', $out ) ) );
	return $out;
}

/**
 * Estados que entran en "Faltan por marcar".
 *
 * @return string[]
 */
function bk_g_todo_statuses() {
	return array_values( array_unique( array_merge( bk_g_paid_statuses(), array( 'wc-on-hold' ) ) ) );
}

/**
 * Días hacia atrás que se revisan.
 *
 * @return int
 */
function bk_g_todo_days() {
	return max( 1, (int) apply_filters( 'bk_g_todo_days', 30 ) );
}

/**
 * IDs de los pedidos activos del periodo.
 *
 * @return int[]
 */
function bk_g_todo_base_ids() {
	global $wpdb;
	$from = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( bk_g_todo_days() - 1 ) . ' days' )->getTimestamp();
	$st   = bk_g_todo_statuses();
	if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$ph = implode( ',', array_fill( 0, count( $st ), '%s' ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status IN ({$ph}) AND date_created_gmt >= %s LIMIT 5000", // phpcs:ignore WordPress.DB
					array_merge( $st, array( gmdate( 'Y-m-d H:i:s', $from ) ) )
				)
			)
		);
	}
	return array_map( 'intval', wc_get_orders( array( 'status' => $st, 'date_created' => '>=' . $from, 'limit' => 5000, 'return' => 'ids', 'type' => 'shop_order' ) ) );
}

/**
 * IDs que faltan por marcar en un check.
 *
 * @param string $flag paid_ok | printed | dispatched.
 * @param int[]  $base IDs del periodo.
 * @return int[]|null null si el check no está configurado.
 */
function bk_g_todo_ids( $flag, $base = null ) {
	$map   = array( 'paid_ok' => 'pago', 'printed' => 'impreso', 'dispatched' => 'despachado' );
	$f     = bk_g_fields();
	$field = $map[ $flag ] ?? '';
	if ( ! $field || empty( $f[ $field ] ) ) {
		return null;
	}
	$base = null === $base ? bk_g_todo_base_ids() : $base;
	if ( ! $base ) {
		return array();
	}
	$on = array_map( 'intval', bk_g_meta_ids( array( $f[ $field ] ), BK_G_TRUE, false, 20000 ) );
	return array_values( array_diff( $base, $on ) );
}

/**
 * GET gestion/orders/todo → { days, counts: { paid_ok, printed, dispatched } } (null = check no configurado).
 *
 * @return WP_REST_Response
 */
function bk_g_todo_rest() {
	$base = bk_g_todo_base_ids();
	$out  = array();
	foreach ( array( 'paid_ok', 'printed', 'dispatched' ) as $flag ) {
		$ids          = bk_g_todo_ids( $flag, $base );
		$out[ $flag ] = null === $ids ? null : count( $ids );
	}
	$res = rest_ensure_response( array( 'days' => bk_g_todo_days(), 'counts' => $out ) );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

add_action(
	'rest_api_init',
	static function () {
		if ( ! function_exists( 'bk_g_can' ) ) {
			return;
		}
		register_rest_route( 'bk/v1', '/gestion/orders/todo', array( 'methods' => 'GET', 'callback' => 'bk_g_todo_rest', 'permission_callback' => 'bk_g_can' ) );
	}
);
