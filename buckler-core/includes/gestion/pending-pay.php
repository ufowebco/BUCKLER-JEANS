<?php
/**
 * Recordatorios de pago (decisión del cliente 2026-10-16): pedido creado en el checkout que sigue en "Pendiente de
 * pago" (o "Fallido") → correo a la 1 h y a las 24 h con el enlace para pagar ese mismo pedido; si a las 48 h sigue
 * sin pagar, se cancela solo (las unidades vuelven al stock). Sin cupón. Desde el pedido en la gestión también se
 * puede enviar a mano (con vista previa) o detener los automáticos.
 *
 * Tiempos en bk_config('pending_pay') = { remind: [1, 24], cancel: 48 } (horas desde que se creó el pedido).
 * Action Scheduler, grupo "buckler-pp": bk_pp_send [pedido, paso] y bk_pp_cancel [pedido].
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_PP_GROUP = 'buckler-pp';

/**
 * Configuración.
 *
 * @return array{remind:int[],cancel:int,statuses:string[]}
 */
function bk_pp_cfg() {
	$c = (array) bk_config( 'pending_pay' );
	return array(
		'remind'   => array_values( array_map( 'intval', (array) ( $c['remind'] ?? array( 1, 24 ) ) ) ),
		'cancel'   => (int) ( $c['cancel'] ?? 48 ),
		'statuses' => (array) apply_filters( 'bk_pp_statuses', array( 'pending', 'failed' ) ),
	);
}

/**
 * ¿El pedido sigue sin pagar y es de la tienda en línea (no creado a mano ni del punto de venta)?
 *
 * @param WC_Order $o Pedido.
 * @return bool
 */
function bk_pp_unpaid( $o ) {
	return $o instanceof WC_Order && 'shop_order' === $o->get_type() && in_array( $o->get_status(), bk_pp_cfg()['statuses'], true )
		&& in_array( $o->get_created_via(), (array) apply_filters( 'bk_pp_created_via', array( 'checkout', 'store-api' ) ), true );
}

/**
 * Momento (timestamp) de cada paso: 1, 2… y 'cancel'.
 *
 * @param WC_Order   $o    Pedido.
 * @param int|string $step Paso.
 * @return int
 */
function bk_pp_when( $o, $step ) {
	$cfg  = bk_pp_cfg();
	$base = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : time();
	$h    = 'cancel' === $step ? $cfg['cancel'] : (int) ( $cfg['remind'][ (int) $step - 1 ] ?? 0 );
	return $base + $h * HOUR_IN_SECONDS;
}

/**
 * Recordatorios ya enviados (meta _bk_pp_sent).
 *
 * @param WC_Order $o Pedido.
 * @return array
 */
function bk_pp_sent( $o ) {
	$sent = $o->get_meta( '_bk_pp_sent' );
	return is_array( $sent ) ? $sent : array();
}

/* ---------------------------------------------------------------------------
 * Programación
 * ------------------------------------------------------------------------- */

add_action( 'woocommerce_checkout_order_created', 'bk_pp_track', 20 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'bk_pp_track', 20 );
add_action( 'woocommerce_order_status_failed', 'bk_pp_track', 20 );
add_action( 'woocommerce_order_status_pending', 'bk_pp_track', 20 );

/**
 * Programa los recordatorios y la cancelación (una sola vez por pedido).
 *
 * @param WC_Order|int $order Pedido.
 */
function bk_pp_track( $order ) {
	$o = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! function_exists( 'as_schedule_single_action' ) || ! bk_pp_unpaid( $o ) || $o->get_meta( '_bk_pp_sched' ) || 'yes' === $o->get_meta( '_bk_pp_stopped' ) ) {
		return;
	}
	$id = $o->get_id();
	foreach ( array_keys( bk_pp_cfg()['remind'] ) as $i ) {
		as_schedule_single_action( max( time() + 5 * MINUTE_IN_SECONDS, bk_pp_when( $o, $i + 1 ) ), 'bk_pp_send', array( $id, $i + 1 ), BK_PP_GROUP );
	}
	if ( bk_pp_cfg()['cancel'] > 0 ) {
		as_schedule_single_action( max( time() + 10 * MINUTE_IN_SECONDS, bk_pp_when( $o, 'cancel' ) ), 'bk_pp_cancel', array( $id ), BK_PP_GROUP );
	}
	$o->update_meta_data( '_bk_pp_sched', time() );
	$o->save_meta_data();
}

/**
 * Quita lo programado de un pedido.
 *
 * @param int $id Pedido.
 */
function bk_pp_unschedule( $id ) {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}
	foreach ( array_keys( bk_pp_cfg()['remind'] ) as $i ) {
		as_unschedule_all_actions( 'bk_pp_send', array( (int) $id, $i + 1 ), BK_PP_GROUP );
	}
	as_unschedule_all_actions( 'bk_pp_cancel', array( (int) $id ), BK_PP_GROUP );
}

// Pagó (o la tienda lo pasó a otro estado): no se le escribe más.
foreach ( array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded' ) as $bk_pp_st ) {
	add_action( 'woocommerce_order_status_' . $bk_pp_st, 'bk_pp_unschedule' );
}

// WooCommerce cancela solo los pedidos sin pagar al vencer "Mantener inventario" (60 min por defecto): eso borraría
// los recordatorios. Mientras esté programada la cancelación propia, la de WooCommerce no actúa.
add_filter(
	'woocommerce_cancel_unpaid_order',
	static function ( $cancel, $o ) {
		return ( $o instanceof WC_Order && $o->get_meta( '_bk_pp_sched' ) && 'yes' !== $o->get_meta( '_bk_pp_stopped' ) && time() < bk_pp_when( $o, 'cancel' ) ) ? false : $cancel;
	},
	20,
	2
);

/* ---------------------------------------------------------------------------
 * Envío y cancelación
 * ------------------------------------------------------------------------- */

add_action( 'bk_pp_send', 'bk_pp_send', 10, 2 );

/**
 * Envía el recordatorio de un paso si el pedido sigue sin pagar.
 *
 * @param int  $id     Pedido.
 * @param int  $step   Paso (1, 2…).
 * @param bool $manual Enviado desde la gestión.
 * @return bool
 */
function bk_pp_send( $id, $step, $manual = false ) {
	$o = wc_get_order( (int) $id );
	if ( ! bk_pp_unpaid( $o ) || ! is_email( $o->get_billing_email() ) || ( ! $manual && 'yes' === $o->get_meta( '_bk_pp_stopped' ) ) ) {
		return false;
	}
	$sent = bk_pp_sent( $o );
	if ( ! $manual && isset( $sent[ (int) $step ] ) ) {
		return false;
	}
	$mail = bk_pp_email( $o, (int) $step );
	$from = wp_specialchars_decode( get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ), ENT_QUOTES ) . ' <' . sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) ) . '>';
	if ( ! wp_mail( $o->get_billing_email(), $mail['subject'], $mail['html'], array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from ) ) ) {
		return false;
	}
	$key          = $manual ? 'm' . time() : (int) $step;
	$sent[ $key ] = array( 't' => time(), 'step' => (int) $step, 'by' => $manual ? wp_get_current_user()->display_name : '' );
	$o->update_meta_data( '_bk_pp_sent', $sent );
	$label = $manual ? 'enviado a mano' : ( 1 === (int) $step ? '1.º automático' : (int) $step . '.º automático' );
	if ( function_exists( 'bk_g_log' ) ) {
		bk_g_log( $o, 'pago_recordatorio', $label );
	}
	/* translators: %s: automático o a mano */
	$o->add_order_note( sprintf( __( 'Recordatorio de pago enviado a la clienta (%s).', 'buckler-core' ), $label ), false, $manual );
	$o->save();
	return true;
}

add_action( 'bk_pp_cancel', 'bk_pp_cancel' );

/**
 * Cancela el pedido si sigue sin pagar (las unidades vuelven al stock).
 *
 * @param int $id Pedido.
 */
function bk_pp_cancel( $id ) {
	$o = wc_get_order( (int) $id );
	if ( ! bk_pp_unpaid( $o ) || 'yes' === $o->get_meta( '_bk_pp_stopped' ) ) {
		return;
	}
	$n = count( bk_pp_sent( $o ) );
	$o->update_meta_data( '_bk_pp_cancelled', time() );
	if ( function_exists( 'bk_g_log' ) ) {
		bk_g_log( $o, 'pago_cancelado', (string) bk_pp_cfg()['cancel'] );
	}
	/* translators: 1: horas, 2: recordatorios enviados */
	$o->update_status( 'cancelled', sprintf( __( 'Cancelado automáticamente: sin pago %1$d h después del pedido (%2$d recordatorios enviados). Las unidades volvieron al inventario.', 'buckler-core' ), bk_pp_cfg()['cancel'], $n ) );
}

/* ---------------------------------------------------------------------------
 * Enlace de pago firmado
 * ------------------------------------------------------------------------- */

/**
 * Firma del enlace de pago de los recordatorios.
 *
 * @param WC_Order $o Pedido.
 * @return string
 */
function bk_pp_sig( $o ) {
	return substr( hash_hmac( 'sha256', $o->get_id() . '|' . $o->get_order_key(), wp_salt( 'auth' ) ), 0, 20 );
}

/**
 * Enlace "Pagar pedido" con la firma (y medición en GA4).
 *
 * @param WC_Order $o    Pedido.
 * @param string   $from email-1, email-2, whatsapp…
 * @return string
 */
function bk_pp_pay_url( $o, $from = '' ) {
	$args = array( 'bk_pp' => bk_pp_sig( $o ) );
	if ( $from ) {
		$args += array( 'utm_source' => 0 === strpos( $from, 'email' ) ? 'email' : $from, 'utm_medium' => 'recordatorio-pago', 'utm_campaign' => $from );
	}
	return add_query_arg( $args, $o->get_checkout_payment_url() );
}

// WooCommerce pide "verificar el correo" en Pagar pedido a las clientas sin sesión cuando el pedido tiene más de
// 10 minutos: con el enlace firmado de los recordatorios no hace falta (la firma solo la tiene quien recibió el correo).
add_filter(
	'woocommerce_order_email_verification_required',
	static function ( $required, $o ) {
		$sig = isset( $_GET['bk_pp'] ) ? sanitize_text_field( wp_unslash( $_GET['bk_pp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return ( $required && $sig && $o instanceof WC_Order && hash_equals( bk_pp_sig( $o ), $sig ) ) ? false : $required;
	},
	10,
	2
);

/* ---------------------------------------------------------------------------
 * Correo
 * ------------------------------------------------------------------------- */

/**
 * Arma el correo (asunto + HTML). Texto fijo por paso; el último avisa la fecha de cancelación.
 *
 * @param WC_Order $o    Pedido.
 * @param int      $step Paso.
 * @return array{subject:string,html:string}
 */
function bk_pp_email( $o, $step ) {
	$name   = trim( (string) $o->get_billing_first_name() );
	$number = $o->get_order_number();
	$last   = $step >= count( bk_pp_cfg()['remind'] );
	$cancel = bk_pp_cfg()['cancel'] > 0 ? max( bk_pp_when( $o, 'cancel' ), time() + HOUR_IN_SECONDS ) : 0;
	$until  = $cancel ? wp_date( 'l j \d\e F \a \l\a\s g:i a', $cancel ) : '';
	$copy   = $last
		? array(
			'subject' => sprintf( 'Último aviso: tu pedido #%s se libera pronto', $number ),
			'pre'     => 'Tus prendas siguen apartadas, pero no por mucho tiempo.',
			'title'   => ( $name ? $name . ', tus' : 'Tus' ) . ' prendas siguen apartadas',
			'body'    => 'Tu pago aún no se ha completado. Guardamos tus tallas un poco más para que no te quedes sin ellas' . ( $until ? ': si el pago no se completa antes del <strong>' . esc_html( $until ) . '</strong>, cancelaremos el pedido y las prendas volverán a la tienda.' : '.' ),
			'cta'     => 'Pagar ahora',
		)
		: array(
			'subject' => ( $name ? $name . ', tu' : 'Tu' ) . sprintf( ' pedido #%s está a un paso', $number ),
			'pre'     => 'Solo falta el pago para preparar tu envío.',
			'title'   => 'Tu pedido está listo, solo falta el pago',
			'body'    => 'Vimos que el pago de tu pedido no se completó. No te preocupes: tus prendas están apartadas con tu talla. Termina en un clic con el mismo medio de pago o elige otro (Wompi, Bold o Addi en 3 cuotas con 0 % de interés).',
			'cta'     => 'Terminar mi pago',
		);
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
	$vars = array(
		'copy'     => $copy,
		'number'   => $number,
		'items'    => $items,
		'total'    => html_entity_decode( wp_strip_all_tags( wc_price( (float) $o->get_total(), array( 'currency' => $o->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
		'pay'      => bk_pp_pay_url( $o, 'email-' . (int) $step ),
		'logo'     => BK_URL . 'assets/img/logo-email.png',
		'whatsapp' => bk_whatsapp_url(),
		'company'  => (string) bk_config( 'company' ),
		'city'     => (string) bk_config( 'city' ),
	);
	ob_start();
	include BK_DIR . 'templates/emails/pending-payment.php';
	return array( 'subject' => $copy['subject'], 'html' => (string) ob_get_clean() );
}

/* ---------------------------------------------------------------------------
 * Gestión: estado en el detalle, envío a mano y detener
 * ------------------------------------------------------------------------- */

/**
 * Resumen para el detalle del pedido.
 *
 * @param WC_Order $o Pedido.
 * @return array|null Null si el pedido no aplica.
 */
function bk_pp_info( $o ) {
	$sent    = bk_pp_sent( $o );
	$unpaid  = bk_pp_unpaid( $o );
	if ( ! $unpaid && ! $sent && ! $o->get_meta( '_bk_pp_cancelled' ) ) {
		return null;
	}
	$stopped = 'yes' === $o->get_meta( '_bk_pp_stopped' );
	$fmt     = static fn( $t ) => wp_date( 'j M, g:i a', (int) $t );
	$list    = array();
	foreach ( $sent as $s ) {
		$list[] = array( 'date' => $fmt( $s['t'] ?? 0 ), 'label' => ! empty( $s['by'] ) ? 'Enviado a mano por ' . $s['by'] : 'Recordatorio automático ' . (int) ( $s['step'] ?? 1 ) );
	}
	$next = array();
	if ( $unpaid && ! $stopped && $o->get_meta( '_bk_pp_sched' ) ) {
		foreach ( array_keys( bk_pp_cfg()['remind'] ) as $i ) {
			if ( ! isset( $sent[ $i + 1 ] ) && bk_pp_when( $o, $i + 1 ) > time() - HOUR_IN_SECONDS ) {
				$next[] = array( 'date' => $fmt( max( time(), bk_pp_when( $o, $i + 1 ) ) ), 'label' => 'Recordatorio automático ' . ( $i + 1 ) );
			}
		}
		if ( bk_pp_cfg()['cancel'] > 0 ) {
			$next[] = array( 'date' => $fmt( max( time(), bk_pp_when( $o, 'cancel' ) ) ), 'label' => 'Cancelación si no paga', 'cancel' => true );
		}
	}
	$age = $o->get_date_created() ? time() - $o->get_date_created()->getTimestamp() : 0;
	return array(
		'unpaid'    => $unpaid,
		'stopped'   => $stopped,
		'sent'      => $list,
		'next'      => $next,
		'cancelled' => $o->get_meta( '_bk_pp_cancelled' ) ? $fmt( $o->get_meta( '_bk_pp_cancelled' ) ) : '',
		'can_send'  => $unpaid && is_email( $o->get_billing_email() ) && $age >= 30 * MINUTE_IN_SECONDS, // Puede estar pagando en este momento.
		'wait'      => $unpaid && $age < 30 * MINUTE_IN_SECONDS ? (int) ceil( ( 30 * MINUTE_IN_SECONDS - $age ) / MINUTE_IN_SECONDS ) : 0,
		'pay_url'   => $unpaid ? bk_pp_pay_url( $o, 'whatsapp' ) : '',
	);
}

add_action( 'rest_api_init', 'bk_pp_routes' );

/**
 * POST bk/v1/gestion/orders/{id}/payment-reminder  { action: preview | send | stop | resume }.
 */
function bk_pp_routes() {
	register_rest_route(
		'bk/v1',
		'/gestion/orders/(?P<id>\d+)/payment-reminder',
		array(
			'methods'             => 'POST',
			'callback'            => 'bk_pp_rest',
			'permission_callback' => static fn() => function_exists( 'bk_g_can' ) && bk_g_can(),
		)
	);
}

/**
 * Acciones de la gestión.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_pp_rest( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$action = sanitize_key( (string) $r['action'] );
	if ( in_array( $action, array( 'preview', 'send' ), true ) ) {
		$info = bk_pp_info( $o );
		if ( ! $info || ! $info['unpaid'] ) {
			return new WP_Error( 'bk_pp', 'Este pedido ya no está pendiente de pago.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $o->get_billing_email() ) ) {
			return new WP_Error( 'bk_pp', 'El pedido no tiene un correo válido.', array( 'status' => 400 ) );
		}
		$step = count( bk_pp_sent( $o ) ) >= 1 ? count( bk_pp_cfg()['remind'] ) : 1;
		if ( 'preview' === $action ) {
			$mail = bk_pp_email( $o, $step );
			return rest_ensure_response( array( 'subject' => $mail['subject'], 'to' => $o->get_billing_email(), 'html' => $mail['html'] ) );
		}
		if ( ! $info['can_send'] ) {
			return new WP_Error( 'bk_pp', sprintf( 'El pedido se creó hace poco: la clienta puede estar pagando. Podrás enviarlo en %d minutos.', $info['wait'] ), array( 'status' => 400 ) );
		}
		if ( ! bk_pp_send( $o->get_id(), $step, true ) ) {
			return new WP_Error( 'bk_pp', 'No se pudo enviar el correo.', array( 'status' => 500 ) );
		}
	} elseif ( 'stop' === $action ) {
		$o->update_meta_data( '_bk_pp_stopped', 'yes' );
		bk_pp_unschedule( $o->get_id() );
		/* translators: %s: usuario */
		$o->add_order_note( sprintf( __( 'Recordatorios de pago y cancelación automática detenidos por %s.', 'buckler-core' ), wp_get_current_user()->display_name ), false, true );
		$o->save();
	} elseif ( 'resume' === $action ) {
		$o->delete_meta_data( '_bk_pp_stopped' );
		$o->delete_meta_data( '_bk_pp_sched' );
		$o->save();
		bk_pp_track( $o );
	} else {
		return new WP_Error( 'bk_pp', 'Acción no válida.', array( 'status' => 400 ) );
	}
	delete_transient( 'bk_g_summary' );
	return bk_g_order( $r );
}
