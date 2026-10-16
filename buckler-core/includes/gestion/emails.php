<?php
/**
 * Gestión de tienda → Correos: ver cómo se ven los correos de pedidos (WooCommerce y buckler-core)
 * con un pedido real y enviar pruebas.
 *
 * - WooCommerce: se usa el propio WC_Email (misma plantilla, estilos y textos que recibe la clienta),
 *   con el pedido elegido y SIN disparar el envío real (trigger() no se llama).
 * - buckler-core: "Tu pedido ha sido enviado" (guía) y los 3 recordatorios de carrito abandonado.
 * - Prueba: el asunto lleva "[Prueba]" y va al correo que se indique (por defecto, el de quien prueba).
 *
 * Rutas (permiso bk_g_can):
 *   GET  gestion/emails                 lista de correos y pedidos de ejemplo
 *   POST gestion/emails/preview         { email, order } → { subject, to, html }
 *   POST gestion/emails/test            { email, order, to } → { sent, to }
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_gm_routes' );

/**
 * Rutas.
 */
function bk_gm_routes() {
	register_rest_route( 'bk/v1', '/gestion/emails', array( 'methods' => 'GET', 'callback' => 'bk_gm_list', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( 'bk/v1', '/gestion/emails/preview', array( 'methods' => 'POST', 'callback' => 'bk_gm_preview', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( 'bk/v1', '/gestion/emails/test', array( 'methods' => 'POST', 'callback' => 'bk_gm_test', 'permission_callback' => 'bk_g_can' ) );
}

/**
 * Correos de WooCommerce relacionados con pedidos, en orden de uso.
 *
 * @return array<string,array{class:string,group:string,when:string}>
 */
function bk_gm_woo_map() {
	return array(
		'WC_Email_New_Order'                       => array( 'Tienda', 'Llega a la tienda cuando entra un pedido pagado o pendiente.' ),
		'WC_Email_Customer_On_Hold_Order'          => array( 'Clienta', 'Pedido en espera (p. ej. transferencia o pago por confirmar).' ),
		'WC_Email_Customer_Processing_Order'       => array( 'Clienta', 'Pago recibido: el pedido pasa a Procesando.' ),
		'WC_Email_Customer_Completed_Order'        => array( 'Clienta', 'Pedido completado (si se envía guía desde la gestión, este no se manda).' ),
		'WC_Email_Customer_Cancelled_Order'        => array( 'Clienta', 'Pedido cancelado.' ),
		'WC_Email_Cancelled_Order'                 => array( 'Tienda', 'Aviso a la tienda de un pedido cancelado.' ),
		'WC_Email_Customer_Failed_Order'           => array( 'Clienta', 'El pago falló.' ),
		'WC_Email_Failed_Order'                    => array( 'Tienda', 'Aviso a la tienda de un pago fallido.' ),
		'WC_Email_Customer_Refunded_Order'         => array( 'Clienta', 'Reembolso total.' ),
		'WC_Email_Customer_Partially_Refunded_Order' => array( 'Clienta', 'Reembolso parcial.' ),
		'WC_Email_Customer_Invoice'                => array( 'Clienta', 'Detalles del pedido / enlace de pago (se envía a mano desde el pedido).' ),
		'WC_Email_Customer_Note'                   => array( 'Clienta', 'Nota para la clienta (casilla "Enviar a la clienta" en Notas).' ),
	);
}

/**
 * Correos WooCommerce disponibles: id => WC_Email.
 *
 * @return WC_Email[]
 */
function bk_gm_woo_emails() {
	$all = WC()->mailer()->get_emails();
	$out = array();
	foreach ( bk_gm_woo_map() as $class => $info ) {
		if ( isset( $all[ $class ] ) && $all[ $class ] instanceof WC_Email ) {
			$out[ $all[ $class ]->id ] = $all[ $class ];
		}
	}
	return $out;
}

/**
 * Lista para el panel.
 *
 * @return WP_REST_Response
 */
function bk_gm_list() {
	$map  = bk_gm_woo_map();
	$list = array();
	foreach ( bk_gm_woo_emails() as $id => $e ) {
		$info   = $map[ get_class( $e ) ] ?? array( 'Clienta', '' );
		$list[] = array(
			'id'      => 'wc:' . $id,
			'title'   => $e->get_title(),
			'group'   => $info[0],
			'when'    => $info[1],
			'enabled' => $e->is_enabled(),
			'source'  => 'WooCommerce',
		);
	}
	$list[] = array( 'id' => 'bk:shipped', 'title' => 'Tu pedido ha sido enviado (guía)', 'group' => 'Clienta', 'when' => 'Se envía desde el pedido al registrar transportadora y número de guía.', 'enabled' => true, 'source' => 'buckler-core' );
	if ( bk_ship_local_keys() ) {
		$list[] = array( 'id' => 'bk:local', 'title' => 'Tu pedido llega hoy (entrega local)', 'group' => 'Clienta', 'when' => 'Se envía desde el pedido al elegir "Transportador Local" (domicilios Medellín y área metropolitana, sin número de guía).', 'enabled' => true, 'source' => 'buckler-core' );
	}
	if ( function_exists( 'bk_pp_email' ) ) {
		$pp = bk_pp_cfg();
		foreach ( $pp['remind'] as $i => $h ) {
			$list[] = array(
				'id'      => 'bk:pp' . ( $i + 1 ),
				'title'   => sprintf( 'Recordatorio de pago %d', $i + 1 ),
				'group'   => 'Clienta',
				'when'    => sprintf( '%d h después de un pedido que quedó pendiente de pago.', $h ) . ( $i + 1 === count( $pp['remind'] ) && $pp['cancel'] ? sprintf( ' Si a las %d h no paga, el pedido se cancela solo.', $pp['cancel'] ) : '' ),
				'enabled' => true,
				'source'  => 'buckler-core',
			);
		}
	}
	if ( function_exists( 'bk_ac_build_email' ) ) {
		$s = bk_ac_settings();
		foreach ( array( 1, 2, 3 ) as $step ) {
			$list[] = array(
				'id'      => 'bk:ac' . $step,
				'title'   => sprintf( 'Carrito abandonado · recordatorio %d', $step ),
				'group'   => 'Clienta',
				'when'    => sprintf( '%d h después de dejar el carrito sin comprar.', (int) ( $s['delays'][ $step - 1 ] ?? 0 ) ),
				'enabled' => 'yes' === $s['enabled'],
				'source'  => 'buckler-core',
			);
		}
	}
	$orders = array();
	foreach ( wc_get_orders( array( 'limit' => 15, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
		$orders[] = array( 'id' => $o->get_id(), 'label' => '#' . $o->get_order_number() . ' · ' . trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) . ' · ' . wc_get_order_status_name( $o->get_status() ) );
	}
	return rest_ensure_response(
		array(
			'emails'   => $list,
			'orders'   => $orders,
			'me'       => wp_get_current_user()->user_email,
			'from'     => get_option( 'woocommerce_email_from_name' ) . ' <' . get_option( 'woocommerce_email_from_address' ) . '>',
			'settings' => current_user_can( 'manage_woocommerce' ) ? admin_url( 'admin.php?page=wc-settings&tab=email' ) : '',
		)
	);
}

/**
 * Arma un correo (sin enviarlo).
 *
 * @param string $id    "wc:customer_completed_order" | "bk:shipped" | "bk:ac1".
 * @param int    $order Pedido de ejemplo.
 * @return array{subject:string,to:string,html:string}|WP_Error
 */
function bk_gm_build( $id, $order ) {
	$o = $order ? wc_get_order( $order ) : null;
	if ( ! $o ) {
		$last = wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC' ) );
		$o    = $last ? $last[0] : null;
	}
	if ( 'bk:shipped' === $id ) {
		if ( ! $o ) {
			return new WP_Error( 'bk_no_orders', 'Necesitas al menos un pedido para la vista previa.', array( 'status' => 400 ) );
		}
		$t    = function_exists( 'bk_ship_get' ) ? bk_ship_get( $o ) : null;
		$keys = array_keys( bk_carriers() );
		$mail = bk_ship_email( $o, $t['carrier'] ?? ( $keys[0] ?? '' ), $t['number'] ?? 'EJEMPLO123456' );
		return array( 'subject' => $mail['subject'], 'to' => $o->get_billing_email(), 'html' => $mail['html'] );
	}
	if ( 'bk:local' === $id ) {
		$local = bk_ship_local_keys();
		if ( ! $o || ! $local ) {
			return new WP_Error( 'bk_no_orders', 'Necesitas al menos un pedido para la vista previa.', array( 'status' => 400 ) );
		}
		$mail = bk_ship_email( $o, $local[0], '' );
		return array( 'subject' => $mail['subject'], 'to' => $o->get_billing_email(), 'html' => $mail['html'] );
	}
	if ( preg_match( '/^bk:pp(\d)$/', $id, $m ) && function_exists( 'bk_pp_email' ) ) {
		if ( ! $o ) {
			return new WP_Error( 'bk_no_orders', 'Necesitas al menos un pedido para la vista previa.', array( 'status' => 400 ) );
		}
		$mail = bk_pp_email( $o, (int) $m[1] );
		return array( 'subject' => $mail['subject'], 'to' => $o->get_billing_email(), 'html' => $mail['html'] );
	}
	if ( preg_match( '/^bk:ac([123])$/', $id, $m ) ) {
		if ( ! function_exists( 'bk_ac_build_email' ) ) {
			return new WP_Error( 'bk_off', 'El módulo de carritos abandonados está apagado.', array( 'status' => 400 ) );
		}
		require_once BK_DIR . 'includes/abandoned-cart/admin.php';
		$step   = (int) $m[1];
		$pct    = (int) ( bk_ac_settings()['coupon_pct'][ $step - 1 ] ?? 0 );
		$coupon = $pct > 0 ? array( 'code' => 'VUELVE-EJEMPLO', 'pct' => $pct, 'expires' => time() + 3 * DAY_IN_SECONDS ) : null;
		$row    = bk_ac_sample_row();
		$mail   = bk_ac_build_email( $row, $step, $coupon );
		return array( 'subject' => $mail['subject'], 'to' => (string) $row->email, 'html' => $mail['html'] );
	}
	if ( 0 !== strpos( $id, 'wc:' ) ) {
		return new WP_Error( 'bk_email', 'Correo no encontrado.', array( 'status' => 404 ) );
	}
	$emails = bk_gm_woo_emails();
	$e      = $emails[ substr( $id, 3 ) ] ?? null;
	if ( ! $e ) {
		return new WP_Error( 'bk_email', 'Correo no encontrado.', array( 'status' => 404 ) );
	}
	if ( ! $o ) {
		return new WP_Error( 'bk_no_orders', 'Necesitas al menos un pedido para la vista previa.', array( 'status' => 400 ) );
	}
	$e = clone $e; // No tocar el objeto compartido del mailer.
	$e->object                         = $o;
	$e->placeholders['{order_date}']   = wc_format_datetime( $o->get_date_created() );
	$e->placeholders['{order_number}'] = $o->get_order_number();
	if ( property_exists( $e, 'customer_note' ) ) {
		$e->customer_note = 'Esta es una nota de ejemplo para la clienta.';
	}
	if ( property_exists( $e, 'partial_refund' ) ) {
		$refunds           = $o->get_refunds();
		$e->refund         = $refunds ? $refunds[0] : null;
		$e->partial_refund = false !== strpos( $e->id, 'partial' );
	}
	try {
		$e->setup_locale();
		$subject = $e->get_subject();
		$html    = $e->style_inline( $e->get_content_html() );
		$e->restore_locale();
	} catch ( Throwable $err ) {
		return new WP_Error( 'bk_email', 'No se pudo armar este correo: ' . $err->getMessage(), array( 'status' => 500 ) );
	}
	$to = $e->is_customer_email() ? $o->get_billing_email() : (string) $e->get_recipient();
	return array( 'subject' => $subject, 'to' => $to, 'html' => $html );
}

/**
 * Vista previa.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gm_preview( WP_REST_Request $r ) {
	$m = bk_gm_build( sanitize_text_field( (string) $r['email'] ), absint( $r['order'] ) );
	return is_wp_error( $m ) ? $m : rest_ensure_response( $m );
}

/**
 * Envío de prueba.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gm_test( WP_REST_Request $r ) {
	$to = sanitize_email( (string) $r['to'] );
	if ( ! is_email( $to ) ) {
		return new WP_Error( 'bk_email', 'Escribe un correo válido para la prueba.', array( 'status' => 400 ) );
	}
	// Límite simple: 20 pruebas por hora por usuario.
	$key = 'bk_gm_tests_' . get_current_user_id();
	$n   = (int) get_transient( $key );
	if ( $n >= 20 ) {
		return new WP_Error( 'bk_email', 'Llegaste al límite de 20 pruebas por hora.', array( 'status' => 429 ) );
	}
	$m = bk_gm_build( sanitize_text_field( (string) $r['email'] ), absint( $r['order'] ) );
	if ( is_wp_error( $m ) ) {
		return $m;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	$sent = bk_ship_mail( $to, '[Prueba] ' . $m['subject'], $m['html'] );
	return rest_ensure_response( array( 'sent' => $sent, 'to' => $to ) );
}
