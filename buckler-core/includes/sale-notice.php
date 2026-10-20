<?php
/**
 * Aviso "Producto en descuento: no tiene cambio" en las prendas de ¡Sale! (decisión del cliente 2026-10-15; desde
 * 2026-10-20 solo "sin cambio": la garantía se mantiene).
 *
 * ¡Sale! = categoría sale_cat (y sus hijas) o precio rebajado propio del producto (bk_disc_is_sale()).
 * Las campañas de Gestión → Descuentos NO cuentan como ¡Sale!: esas prendas conservan el cambio.
 * Se muestra en la ficha (junto al precio), en cada línea del carrito / checkout, antes de pagar y queda anotado en
 * la línea del pedido (respaldo ante reclamos). Texto: bk_config( 'sale_notice' ) (vacío = apagado).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

if ( '' !== trim( (string) bk_config( 'sale_notice' ) ) ) {
	add_action( 'woocommerce_single_product_summary', 'bk_sale_notice_pdp', 13 );
	add_filter( 'woocommerce_get_item_data', 'bk_sale_notice_item', 25, 2 );
	add_action( 'woocommerce_checkout_create_order_line_item', 'bk_sale_notice_order_line', 25, 3 );
	add_action( 'woocommerce_review_order_before_payment', 'bk_sale_notice_checkout', 6 );
}

/**
 * Texto del aviso.
 *
 * @return string
 */
function bk_sale_notice_text() {
	return trim( (string) bk_config( 'sale_notice' ) );
}

/**
 * Ficha.
 */
function bk_sale_notice_pdp() {
	global $product;
	if ( $product instanceof WC_Product && bk_disc_is_sale( $product ) ) {
		echo '<p class="bk-sale-notice" role="note">' . bk_icon_svg( 'lucide:info', 18 ) . '<span>' . esc_html( bk_sale_notice_text() ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/**
 * Línea del carrito / checkout.
 *
 * @param array $data Datos.
 * @param array $item Línea.
 * @return array
 */
function bk_sale_notice_item( $data, $item ) {
	if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product && bk_disc_is_sale( $item['data'] ) ) {
		$data[] = array( 'key' => __( 'Cambios', 'buckler-core' ), 'value' => __( 'Sin cambio (producto en descuento)', 'buckler-core' ) );
	}
	return $data;
}

/**
 * Queda anotado en la línea del pedido.
 *
 * @param WC_Order_Item_Product $item   Línea.
 * @param string                $key    Clave.
 * @param array                 $values Datos del carrito.
 */
function bk_sale_notice_order_line( $item, $key, $values ) {
	unset( $key );
	if ( isset( $values['data'] ) && $values['data'] instanceof WC_Product && bk_disc_is_sale( $values['data'] ) ) {
		$item->add_meta_data( __( 'Cambios', 'buckler-core' ), __( 'Sin cambio (producto en descuento)', 'buckler-core' ), true );
	}
}

/**
 * Antes de pagar, si el carrito tiene prendas de ¡Sale!.
 */
function bk_sale_notice_checkout() {
	if ( ! WC()->cart ) {
		return;
	}
	$names = array();
	foreach ( WC()->cart->get_cart() as $it ) {
		if ( bk_disc_is_sale( $it['data'] ) ) {
			$names[] = $it['data']->get_name();
		}
	}
	if ( $names ) {
		/* translators: %s: productos */
		echo '<p class="bk-sale-notice is-checkout" role="note">' . bk_icon_svg( 'lucide:info', 18 ) . '<span>' . esc_html( sprintf( __( 'Tu pedido incluye prendas en descuento (%s): no tienen cambio.', 'buckler-core' ), implode( ', ', array_unique( $names ) ) ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
