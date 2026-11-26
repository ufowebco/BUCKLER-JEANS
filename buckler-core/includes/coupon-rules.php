<?php
/**
 * Cupones no acumulables (pedido urgente del cliente 2026-11-26): el cupón del 10 % por calificar en Google — y
 * cualquier otro cupón — NO aplica a prendas de ¡SALE! ni a prendas con una campaña vigente (p. ej. Black Friday).
 *
 * - Cupones por producto (porcentaje / fijo por producto): la prenda deja de ser válida para el cupón.
 * - Cupones de carrito (fijo al carrito): se reparten solo entre las prendas permitidas.
 * - Si en el carrito no queda ninguna prenda permitida, el cupón se rechaza con un mensaje claro.
 *
 * Excepción para un cupón concreto: filtro `bk_coupon_blocked` (devolver false).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Motivo por el que una prenda no admite cupones: 'sale', 'campaign' o '' si lo admite.
 *
 * @param WC_Product     $product Producto o variación.
 * @param WC_Coupon|null $coupon  Cupón.
 * @return string
 */
function bk_coupon_block_reason( $product, $coupon = null ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$why = '';
	if ( function_exists( 'bk_disc_is_sale' ) ? bk_disc_is_sale( $product ) : $product->is_on_sale( 'edit' ) ) {
		$why = 'sale';
	} elseif ( function_exists( 'bk_on' ) && bk_on( 'discounts' ) && function_exists( 'bk_disc_campaign_for' ) && bk_disc_campaign_for( $product ) ) {
		$why = 'campaign';
	}
	return (string) apply_filters( 'bk_coupon_blocked', $why, $product, $coupon ) ?: '';
}

/**
 * Texto del aviso: "no aplica en productos en SALE ni con Black Friday".
 *
 * @return string
 */
function bk_coupon_rule_text() {
	$c = function_exists( 'bk_disc_live_campaign' ) && function_exists( 'bk_on' ) && bk_on( 'discounts' ) ? bk_disc_live_campaign() : null;
	if ( ! $c && function_exists( 'bk_cd_campaign' ) ) {
		$c = bk_cd_campaign(); // Anunciada con cuenta regresiva.
	}
	return $c
		/* translators: %s: nombre de la campaña */
		? sprintf( __( 'No aplica en productos en SALE ni con %s.', 'buckler-core' ), $c['name'] )
		: __( 'No aplica en productos en SALE ni con otras promociones.', 'buckler-core' );
}

// Cupones por producto: la prenda bloqueada no es válida.
add_filter(
	'woocommerce_coupon_is_valid_for_product',
	static function ( $valid, $product, $coupon ) {
		return $valid && '' === bk_coupon_block_reason( $product, $coupon );
	},
	20,
	3
);

// Todos los cupones (también los de carrito): el descuento se calcula solo sobre prendas permitidas.
add_filter(
	'woocommerce_coupon_get_items_to_apply',
	static function ( $items, $coupon ) {
		return array_values( array_filter( (array) $items, static fn( $i ) => empty( $i->product ) || '' === bk_coupon_block_reason( $i->product, $coupon ) ) );
	},
	20,
	2
);

/**
 * Mensaje para la clienta si NINGUNA prenda del carrito admite el cupón por ¡SALE! o campaña; '' si alguna sí.
 *
 * @param WC_Coupon    $coupon    Cupón.
 * @param WC_Discounts $discounts Descuentos del carrito.
 * @return string
 */
function bk_coupon_cart_message( $coupon, $discounts ) {
	$items = $discounts instanceof WC_Discounts ? $discounts->get_items_to_validate() : array();
	if ( ! $items ) {
		return '';
	}
	$reasons = array();
	foreach ( $items as $i ) {
		$why = empty( $i->product ) ? '' : bk_coupon_block_reason( $i->product, $coupon );
		if ( '' === $why ) {
			return ''; // Hay al menos una prenda permitida.
		}
		$reasons[ $why ] = true;
	}
	$c    = function_exists( 'bk_disc_live_campaign' ) ? bk_disc_live_campaign() : null;
	$name = $c ? $c['name'] : __( 'la promoción vigente', 'buckler-core' );
	if ( isset( $reasons['campaign'], $reasons['sale'] ) ) {
		/* translators: %s: campaña */
		return sprintf( __( 'Este cupón no aplica en productos en SALE ni con %s.', 'buckler-core' ), $name );
	}
	if ( isset( $reasons['campaign'] ) ) {
		/* translators: %s: campaña */
		return sprintf( __( 'Este cupón no se suma a %s: tus prendas ya tienen el descuento.', 'buckler-core' ), $name );
	}
	return __( 'Este cupón no aplica en productos en SALE.', 'buckler-core' );
}

/**
 * Rechaza el cupón con el motivo claro (WC_Discounts convierte la excepción en aviso para la clienta).
 *
 * @param bool              $valid     Válido.
 * @param WC_Coupon         $coupon    Cupón.
 * @param WC_Discounts|null $discounts Descuentos.
 * @return bool
 * @throws Exception Con el mensaje para la clienta.
 */
function bk_coupon_validate( $valid, $coupon, $discounts = null ) {
	$msg = bk_coupon_cart_message( $coupon, $discounts );
	if ( '' !== $msg ) {
		throw new Exception( esc_html( $msg ), 109 );
	}
	return $valid;
}
// Cupones por producto: Woo los rechaza antes con un mensaje genérico ("not applicable") → se cambia por el motivo.
add_filter( 'woocommerce_coupon_is_valid_for_excluded_items', static fn( $valid, $coupon, $discounts ) => $valid ? $valid : bk_coupon_validate( $valid, $coupon, $discounts ), 20, 3 );
// Cupones de carrito (y respaldo): sin ninguna prenda permitida → rechazado.
add_filter( 'woocommerce_coupon_is_valid', static fn( $valid, $coupon, $discounts = null ) => $valid ? bk_coupon_validate( $valid, $coupon, $discounts ) : $valid, 20, 3 );
