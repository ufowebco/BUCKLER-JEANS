<?php
/**
 * Pedido manual desde Gestión de tienda (pedido del cliente 2026-11-03): para clientas especiales, ventas por WhatsApp
 * o en persona. El pedido se crea con las prendas elegidas y **descuenta el inventario al instante** (queda reservado),
 * aunque todavía no esté pagado.
 *
 * - created_via 'admin' + atribución 'admin' → origen "Creado a mano"; no recibe los recordatorios ni la cancelación
 *   automática de pago (solo aplican a pedidos del checkout) y WooCommerce tampoco lo cancela a los 60 min.
 * - Inventario: wc_maybe_reduce_stock_levels() descuenta y deja la marca del pedido, así al cancelarlo las prendas
 *   vuelven solas (una sola vez) y al pasarlo a "En proceso" no se descuentan dos veces.
 * - Estado inicial: pending (reservado, sin pagar), on-hold (en espera) o processing (pagado). Correo a la clienta
 *   solo si se pide.
 *
 * Rutas: GET bk/v1/gestion/manual/products?q= (buscar prendas con tallas y existencias) y
 *        POST bk/v1/gestion/orders/manual (crear).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Medios de pago que se pueden elegir en un pedido manual (clave => texto).
 *
 * @return array<string,string>
 */
function bk_gm_payments() {
	return (array) apply_filters(
		'bk_gm_payments',
		array(
			'transferencia' => 'Transferencia bancaria',
			'nequi'         => 'Nequi / Daviplata',
			'efectivo'      => 'Efectivo',
			'datafono'      => 'Datáfono / tarjeta',
			'contraentrega' => 'Contra entrega',
			'link'          => 'Link de pago',
			'otro'          => 'Otro',
		)
	);
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( 'bk/v1', '/gestion/manual/products', array( 'methods' => 'GET', 'callback' => 'bk_gm_products', 'permission_callback' => 'bk_g_can' ) );
		register_rest_route( 'bk/v1', '/gestion/orders/manual', array( 'methods' => 'POST', 'callback' => 'bk_gm_create', 'permission_callback' => 'bk_g_can' ) );
	}
);

/**
 * Disponible de una talla: null = sin control de inventario (siempre disponible), 0 = agotada.
 *
 * @param WC_Product $v Variación o producto simple.
 * @return int|null
 */
function bk_gm_available( $v ) {
	// Talla desactivada en el editor (variación privada) o agotada: no se puede vender.
	$off = 'variation' === $v->get_type() && 'publish' !== get_post_status( $v->get_id() );
	if ( $off || ! $v->is_in_stock() ) {
		return 0;
	}
	return $v->managing_stock() ? max( 0, (int) $v->get_stock_quantity() ) : null;
}

/**
 * Busca prendas por nombre o REF y devuelve sus tallas con existencias y precio.
 *
 * @param WP_REST_Request $r Petición (q).
 * @return WP_REST_Response
 */
function bk_gm_products( WP_REST_Request $r ) {
	$q = trim( sanitize_text_field( (string) $r['q'] ) );
	if ( mb_strlen( $q ) < 2 ) {
		return rest_ensure_response( array( 'items' => array() ) );
	}
	$store = WC_Data_Store::load( 'product' );
	$ids   = array_slice( array_map( 'intval', (array) $store->search_products( $q, '', false, false, 30 ) ), 0, 30 );
	$out   = array();
	foreach ( $ids as $id ) {
		$p = $id ? wc_get_product( $id ) : null;
		if ( ! $p || 'publish' !== $p->get_status() || ! in_array( $p->get_type(), array( 'variable', 'simple' ), true ) ) {
			continue;
		}
		$sizes = array();
		$kids  = $p->is_type( 'variable' ) ? $p->get_children() : array( $p->get_id() );
		foreach ( $kids as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$label   = $p->is_type( 'variable' ) ? wc_get_formatted_variation( $v, true, false, false ) : 'Única';
			$sizes[] = array(
				'id'    => $v->get_id(),
				'label' => $label ? $label : '#' . $v->get_id(),
				'stock' => bk_gm_available( $v ),
				'price' => (float) $v->get_price(),
				'money' => bk_g_money( (float) $v->get_price() ),
			);
		}
		usort( $sizes, static fn( $a, $b ) => strnatcmp( $a['label'], $b['label'] ) );
		$img   = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '';
		$out[] = array(
			'id'    => $p->get_id(),
			'name'  => $p->get_name(),
			'sku'   => $p->get_sku(),
			'img'   => $img ? $img : '',
			'sizes' => $sizes,
		);
		if ( count( $out ) >= 12 ) {
			break;
		}
	}
	return rest_ensure_response( array( 'items' => $out ) );
}

/**
 * Crea el pedido manual.
 *
 * @param WP_REST_Request $r Petición: customer{}, items[{id, qty, price?}], shipping, payment, status, note, notify.
 * @return WP_REST_Response|WP_Error
 */
function bk_gm_create( WP_REST_Request $r ) {
	$c     = (array) $r['customer'];
	$txt   = static fn( $k ) => trim( sanitize_text_field( (string) ( $c[ $k ] ?? '' ) ) );
	$err   = static fn( $msg, $field = '' ) => new WP_Error( 'bk_manual', $msg, array( 'status' => 400, 'field' => $field ) );
	$first = $txt( 'first_name' );
	$phone = preg_replace( '/\D+/', '', $txt( 'phone' ) );
	$email = sanitize_email( $txt( 'email' ) );
	if ( '' === $first ) {
		return $err( 'Escribe el nombre de la clienta.', 'first_name' );
	}
	if ( 12 === strlen( $phone ) && 0 === strpos( $phone, '57' ) ) {
		$phone = substr( $phone, 2 );
	}
	if ( 10 !== strlen( $phone ) ) {
		return $err( 'El celular debe tener 10 dígitos.', 'phone' );
	}
	if ( '' !== $txt( 'email' ) && ! is_email( $email ) ) {
		return $err( 'El correo no es válido.', 'email' );
	}

	// Prendas: se suman las cantidades por talla y se revisa que alcance el inventario.
	$lines = array();
	foreach ( (array) $r['items'] as $it ) {
		$vid = absint( $it['id'] ?? 0 );
		$qty = absint( $it['qty'] ?? 0 );
		if ( ! $vid || $qty < 1 ) {
			continue;
		}
		$price = isset( $it['price'] ) && '' !== (string) $it['price'] ? max( 0, (float) $it['price'] ) : null;
		if ( isset( $lines[ $vid ] ) ) {
			$lines[ $vid ]['qty'] += $qty;
		} else {
			$lines[ $vid ] = array( 'qty' => $qty, 'price' => $price );
		}
	}
	if ( ! $lines ) {
		return $err( 'Agrega al menos una prenda.', 'items' );
	}
	$products = array();
	foreach ( $lines as $vid => $l ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! in_array( $v->get_type(), array( 'variation', 'simple' ), true ) ) {
			return $err( 'Una de las prendas ya no existe. Búscala de nuevo.', 'items' );
		}
		$avail = bk_gm_available( $v );
		if ( null !== $avail && $avail < $l['qty'] ) {
			$name = $v->get_name();
			return $err( 0 === $avail ? sprintf( '%s está agotada.', $name ) : sprintf( 'De %1$s solo quedan %2$d.', $name, $avail ), 'items' );
		}
		$products[ $vid ] = $v;
	}

	$status = in_array( $r['status'], array( 'pending', 'on-hold', 'processing' ), true ) ? (string) $r['status'] : 'pending';
	$pays   = bk_gm_payments();
	$pay    = isset( $pays[ (string) $r['payment'] ] ) ? (string) $r['payment'] : 'otro';
	$ship   = max( 0, (float) $r['shipping'] );
	$user   = wp_get_current_user();

	$GLOBALS['bk_g_via'] = 'gestion';
	$o                   = wc_create_order( array( 'status' => 'pending', 'created_via' => 'admin' ) );
	if ( is_wp_error( $o ) ) {
		return $err( 'No se pudo crear el pedido: ' . $o->get_error_message() );
	}
	foreach ( $lines as $vid => $l ) {
		$v    = $products[ $vid ];
		$args = array();
		if ( null !== $l['price'] ) {
			$args = array( 'subtotal' => $l['price'] * $l['qty'], 'total' => $l['price'] * $l['qty'] );
		}
		$o->add_product( $v, $l['qty'], $args );
	}
	if ( $ship > 0 ) {
		$si = new WC_Order_Item_Shipping();
		$si->set_method_title( 'Envío' );
		$si->set_method_id( 'bk_manual' );
		$si->set_total( $ship );
		$o->add_item( $si );
	}
	$addr = array(
		'first_name' => $first,
		'last_name'  => $txt( 'last_name' ),
		'address_1'  => $txt( 'address_1' ),
		'address_2'  => $txt( 'address_2' ),
		'city'       => $txt( 'city' ),
		'state'      => $txt( 'state' ),
		'country'    => 'CO',
	);
	$o->set_address( array_merge( $addr, array( 'phone' => $phone, 'email' => $email ) ), 'billing' );
	$o->set_address( array_merge( $addr, array( 'phone' => $phone ) ), 'shipping' );
	if ( $email && ( $cu = get_user_by( 'email', $email ) ) ) {
		$o->set_customer_id( $cu->ID );
	}
	$ced = $txt( 'id_number' );
	if ( '' !== $ced && function_exists( 'bk_g_fields' ) ) {
		$key = (string) ( bk_g_fields()['cedula'][0] ?? '_billing_id' );
		$o->update_meta_data( $key, $ced );
	}
	$o->set_payment_method( 'bk_manual_' . $pay );
	$o->set_payment_method_title( $pays[ $pay ] );
	$o->update_meta_data( '_wc_order_attribution_source_type', 'admin' );
	$o->update_meta_data( '_bk_manual', array( 'by' => $user->display_name, 't' => time() ) );
	$note = trim( sanitize_textarea_field( (string) $r['note'] ) );
	$o->calculate_totals( false );
	$o->save();
	$id = $o->get_id();

	// Reserva: descuenta ahora y deja la marca del pedido (para devolver al cancelar y no descontar dos veces).
	wc_maybe_reduce_stock_levels( $id );
	$o = wc_get_order( $id );
	/* translators: %s: usuario */
	$o->add_order_note( sprintf( __( 'Pedido creado a mano desde Gestión de tienda por %s. Las prendas quedan reservadas (inventario descontado).', 'buckler-core' ), $user->display_name ), false, true );
	if ( '' !== $note ) {
		$o->add_order_note( $note, false, true );
	}
	if ( function_exists( 'bk_g_log' ) ) {
		bk_g_log( $o, 'manual', $pays[ $pay ] );
		$o->save();
	}

	if ( 'pending' !== $status ) {
		$notify = rest_sanitize_boolean( $r['notify'] ) && $email;
		$mute   = static fn() => false;
		if ( ! $notify ) {
			add_filter( 'woocommerce_email_enabled_customer_processing_order', $mute );
			add_filter( 'woocommerce_email_enabled_customer_on_hold_order', $mute );
		}
		$o->update_status( $status, __( 'Estado elegido al crear el pedido manual.', 'buckler-core' ) );
		remove_filter( 'woocommerce_email_enabled_customer_processing_order', $mute );
		remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', $mute );
	}
	delete_transient( 'bk_g_summary' );
	$o = wc_get_order( $id );
	return rest_ensure_response(
		array(
			'id'     => $id,
			'number' => $o->get_order_number(),
			'total'  => bk_g_money( (float) $o->get_total() ),
			'status' => $o->get_status(),
		)
	);
}
