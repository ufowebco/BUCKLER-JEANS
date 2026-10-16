<?php
/**
 * Gestión de tienda: guía de envío y correo "Tu pedido ha sido enviado".
 *
 * Flujo en el dashboard (detalle del pedido):
 *   1. Elegir transportadora + número de guía → "Ver correo antes de enviar" (vista previa).
 *   2. "Confirmar y enviar" → guarda la guía, envía el correo, deja nota en el pedido y (opcional)
 *      pasa el pedido a Completado SIN el correo estándar de WooCommerce (evita dos correos).
 * Ajustes (solo admin): vista previa con un pedido de ejemplo y "Enviar correo de prueba".
 *
 * Meta del pedido (HPOS): `_bk_tracking` = { carrier, number, date, sent_by }.
 * Transportadoras: bk_config('carriers') (filtro `bk_site_config`).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_TRACKING_META = '_bk_tracking';

add_action( 'rest_api_init', 'bk_ship_routes' );
add_action( 'woocommerce_order_details_after_order_table', 'bk_ship_customer_box', 5 );

/**
 * Transportadoras.
 *
 * @return array<string,array{name:string,url:string,track:string}>
 */
function bk_carriers() {
	return (array) bk_config( 'carriers' );
}

/**
 * ¿Es una entrega local (sin número de guía)?
 *
 * @param string $carrier Clave.
 * @return bool
 */
function bk_carrier_is_local( $carrier ) {
	return ! empty( bk_carriers()[ $carrier ]['local'] );
}

/**
 * Claves de las transportadoras de entrega local.
 *
 * @return string[]
 */
function bk_ship_local_keys() {
	return array_keys( array_filter( bk_carriers(), static fn( $c ) => ! empty( $c['local'] ) ) );
}

/**
 * Guía guardada en un pedido (la entrega local no tiene número).
 *
 * @param WC_Order $o Pedido.
 * @return array|null
 */
function bk_ship_get( $o ) {
	$t = $o->get_meta( BK_TRACKING_META );
	return is_array( $t ) && ! empty( $t['carrier'] ) && ( ! empty( $t['number'] ) || bk_carrier_is_local( $t['carrier'] ) ) ? $t : null;
}

/**
 * Enlace de rastreo: plantilla con {guia} si existe; si no, el sitio de la transportadora.
 *
 * @param string $carrier Clave.
 * @param string $number  Guía.
 * @return string
 */
function bk_ship_url( $carrier, $number ) {
	$c = bk_carriers()[ $carrier ] ?? null;
	if ( ! $c || ! empty( $c['local'] ) ) {
		return '';
	}
	return $c['track'] ? str_replace( '{guia}', rawurlencode( $number ), $c['track'] ) : $c['url'];
}

/**
 * Rutas.
 */
function bk_ship_routes() {
	register_rest_route(
		'bk/v1',
		'/gestion/orders/(?P<id>\d+)/shipment',
		array(
			'methods'             => 'POST',
			'callback'            => 'bk_ship_handle',
			'permission_callback' => 'bk_g_can',
		)
	);
	register_rest_route(
		'bk/v1',
		'/gestion/shipment-sample',
		array(
			'methods'             => 'POST',
			'callback'            => 'bk_ship_sample',
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		)
	);
}

/**
 * Valida transportadora y número.
 *
 * @param WP_REST_Request $r Petición.
 * @return array{0:string,1:string}|WP_Error
 */
function bk_ship_input( WP_REST_Request $r ) {
	$carrier = sanitize_key( (string) $r['carrier'] );
	$number  = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $r['number'] ) );
	if ( ! isset( bk_carriers()[ $carrier ] ) ) {
		return new WP_Error( 'bk_carrier', 'Elige la transportadora.', array( 'status' => 400 ) );
	}
	if ( bk_carrier_is_local( $carrier ) ) {
		return array( $carrier, '' ); // Entrega local: no lleva guía.
	}
	if ( strlen( $number ) < 4 || strlen( $number ) > 40 ) {
		return new WP_Error( 'bk_number', 'Escribe un número de guía válido (solo letras, números y guiones).', array( 'status' => 400 ) );
	}
	return array( $carrier, $number );
}

/**
 * Vista previa (confirm=false) o envío (confirm=true) del correo de guía.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_ship_handle( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$in = bk_ship_input( $r );
	if ( is_wp_error( $in ) ) {
		return $in;
	}
	list( $carrier, $number ) = $in;
	$mail                     = bk_ship_email( $o, $carrier, $number );
	$to                       = $o->get_billing_email();

	if ( ! rest_sanitize_boolean( $r['confirm'] ) ) {
		return rest_ensure_response( array( 'to' => $to, 'subject' => $mail['subject'], 'html' => $mail['html'] ) );
	}
	if ( ! is_email( $to ) ) {
		return new WP_Error( 'bk_email', 'El pedido no tiene un correo válido.', array( 'status' => 400 ) );
	}

	$user = wp_get_current_user()->display_name;
	$f    = bk_g_fields();
	if ( $f['despachado'] ) {
		$o->update_meta_data( $f['despachado'], 'yes' ); // Guía enviada = despachado.
	}
	$o->update_meta_data(
		BK_TRACKING_META,
		array(
			'carrier' => $carrier,
			'number'  => $number,
			'date'    => current_time( 'mysql' ),
			'sent_by' => $user,
		)
	);
	if ( function_exists( 'bk_g_log' ) ) {
		bk_g_log( $o, 'guia', $number ? $number : 'sin guía (entrega local)', array( 'carrier' => bk_carriers()[ $carrier ]['name'] ) );
	}
	$o->save();

	$sent = bk_ship_mail( $to, $mail['subject'], $mail['html'] );
	$name = bk_carriers()[ $carrier ]['name'];
	/* translators: 1: transportadora, 2: guía, 3: correo, 4: usuario */
	$o->add_order_note( sprintf( 'Guía %1$s %2$s. Correo "pedido enviado" %3$s por %4$s.', $name, $number ? $number : '(entrega local, sin guía)', $sent ? 'enviado a ' . $to : 'NO se pudo enviar', $user ) );

	if ( rest_sanitize_boolean( $r['complete'] ) && 'completed' !== $o->get_status() ) {
		// Sin el correo estándar de "pedido completado": la clienta ya recibió el de envío.
		add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 99 );
		$o->update_status( 'completed', $number ? 'Completado al registrar la guía de envío.' : 'Completado al enviar el pedido con entrega local.' );
		remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 99 );
	}
	delete_transient( 'bk_g_summary' );

	if ( ! $sent ) {
		return new WP_Error( 'bk_mail', 'La guía quedó guardada, pero el correo no se pudo enviar. Revisa la configuración de correo (SMTP).', array( 'status' => 500 ) );
	}
	return bk_g_order( $r );
}

/**
 * Correo de prueba / vista previa desde Ajustes (pedido real más reciente o datos de ejemplo).
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_ship_sample( WP_REST_Request $r ) {
	$orders  = wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'status' => array( 'wc-processing', 'wc-completed' ) ) );
	$o       = $orders ? $orders[0] : null;
	$carrier = sanitize_key( (string) ( $r['carrier'] ? $r['carrier'] : 'servientrega' ) );
	$carrier = isset( bk_carriers()[ $carrier ] ) ? $carrier : 'servientrega';
	if ( ! $o ) {
		return new WP_Error( 'bk_no_orders', 'Necesitas al menos un pedido para la vista previa.', array( 'status' => 400 ) );
	}
	$mail = bk_ship_email( $o, $carrier, bk_carrier_is_local( $carrier ) ? '' : 'EJEMPLO123456' );
	$to   = sanitize_email( (string) $r['to'] );
	if ( $to ) {
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'bk_email', 'Correo de prueba no válido.', array( 'status' => 400 ) );
		}
		$ok = bk_ship_mail( $to, '[Prueba] ' . $mail['subject'], $mail['html'] );
		return rest_ensure_response( array( 'sent' => $ok, 'to' => $to ) );
	}
	return rest_ensure_response( array( 'subject' => $mail['subject'], 'html' => $mail['html'], 'order' => $o->get_order_number() ) );
}

/**
 * Envía un correo HTML con el remitente configurado en WooCommerce.
 *
 * @param string $to      Destino.
 * @param string $subject Asunto.
 * @param string $html    Cuerpo.
 * @return bool
 */
function bk_ship_mail( $to, $subject, $html ) {
	$from_name  = wp_specialchars_decode( get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ), ENT_QUOTES );
	$from_email = sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
	return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . $from_email . '>' ) );
}

/**
 * Arma el correo "Tu pedido ha sido enviado".
 *
 * @param WC_Order $o       Pedido.
 * @param string   $carrier Transportadora.
 * @param string   $number  Guía.
 * @return array{subject:string,html:string}
 */
function bk_ship_email( $o, $carrier, $number ) {
	$c     = bk_carriers()[ $carrier ];
	$items = array();
	foreach ( $o->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$p       = $item->get_product();
		$items[] = array(
			'name'  => $item->get_name(),
			'qty'   => (int) $item->get_quantity(),
			'meta'  => wp_strip_all_tags( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ', 'before' => '', 'after' => '' ) ) ),
			'image' => $p ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : '',
		);
	}
	$addr = $o->get_formatted_shipping_address() ? $o->get_formatted_shipping_address() : $o->get_formatted_billing_address();
	$vars = array(
		'name'     => $o->get_billing_first_name(),
		'number'   => $o->get_order_number(),
		'carrier'  => $c['name'],
		'guide'    => $number,
		'track'    => bk_ship_url( $carrier, $number ),
		'has_link' => '' !== $c['track'],
		'local'    => ! empty( $c['local'] ),
		'items'    => $items,
		'address'  => wp_strip_all_tags( str_replace( array( '<br/>', '<br>' ), "\n", (string) $addr ) ),
		'account'  => $o->get_customer_id() ? wc_get_endpoint_url( 'view-order', $o->get_id(), wc_get_page_permalink( 'myaccount' ) ) : '',
		'logo'     => BK_URL . 'assets/img/logo-email.png',
		'whatsapp' => bk_whatsapp_url(),
		'company'  => (string) bk_config( 'company' ),
		'city'     => (string) bk_config( 'city' ),
	);
	ob_start();
	include BK_DIR . 'templates/emails/order-shipped.php';
	/* translators: %s: número de pedido */
	$subject = ! empty( $c['local'] )
		/* translators: %s: número de pedido */
		? sprintf( __( 'Tu pedido #%s llega hoy', 'buckler-core' ), $o->get_order_number() )
		/* translators: %s: número de pedido */
		: sprintf( __( 'Tu pedido #%s ha sido enviado', 'buckler-core' ), $o->get_order_number() );
	return array(
		'subject' => $subject,
		'html'    => (string) ob_get_clean(),
	);
}

/**
 * La clienta ve su guía en Mi cuenta → Pedido (y en la página de gracias si ya existe).
 *
 * @param WC_Order $o Pedido.
 */
function bk_ship_customer_box( $o ) {
	$t = $o instanceof WC_Order ? bk_ship_get( $o ) : null;
	if ( ! $t || ! isset( bk_carriers()[ $t['carrier'] ] ) ) {
		return;
	}
	$c = bk_carriers()[ $t['carrier'] ];
	if ( ! empty( $c['local'] ) ) {
		printf(
			'<section class="bk-ship-box"><h2>%1$s</h2><p>%2$s</p></section>',
			esc_html__( 'Tu pedido llega hoy', 'buckler-core' ),
			esc_html__( 'Va con nuestro domiciliario (entrega local en Medellín y área metropolitana, sin número de guía). Quédate pendiente: puede llegar en cualquier momento del día.', 'buckler-core' )
		);
		return;
	}
	printf(
		'<section class="bk-ship-box"><h2>%1$s</h2><p>%2$s <strong>%3$s</strong> · %4$s <strong>%5$s</strong></p><p><a href="%6$s" target="_blank" rel="noopener">%7$s</a></p></section>',
		esc_html__( 'Tu pedido va en camino', 'buckler-core' ),
		esc_html__( 'Transportadora:', 'buckler-core' ),
		esc_html( $c['name'] ),
		esc_html__( 'Guía:', 'buckler-core' ),
		esc_html( $t['number'] ),
		esc_url( bk_ship_url( $t['carrier'], $t['number'] ) ),
		esc_html__( 'Rastrear mi envío', 'buckler-core' )
	);
}
