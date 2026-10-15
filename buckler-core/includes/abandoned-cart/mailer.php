<?php
/**
 * Carritos abandonados: programación (Action Scheduler de WooCommerce), envío, cupón, plantilla,
 * limpieza y privacidad.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Programa los correos desde el paso indicado.
 *
 * @param int  $id        Carrito.
 * @param int  $from_step Primer paso pendiente (1–3).
 * @param bool $from_now  true = los tiempos cuentan desde ahora (reactivación tras pago fallido).
 */
function bk_ac_schedule( $id, $from_step = 1, $from_now = false ) {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}
	$delays = array_map( 'floatval', bk_ac_settings()['delays'] );
	$row    = bk_ac_get( $id );
	if ( ! $row || ! (int) $row->consent ) { // Sin autorización nunca se programan correos.
		return;
	}
	$base = $from_now ? time() : strtotime( $row->created_at . ' UTC' );
	$prev = ( $from_now && $from_step > 1 ) ? $delays[ $from_step - 2 ] : 0;
	for ( $step = max( 1, $from_step ); $step <= 3; $step++ ) {
		$when = $base + (int) round( ( $delays[ $step - 1 ] - $prev ) * HOUR_IN_SECONDS );
		$when = max( $when, time() + 5 * MINUTE_IN_SECONDS );
		as_schedule_single_action( $when, 'bk_ac_send', array( (int) $id, $step ), 'buckler-ac' );
	}
}

add_action( 'bk_ac_send', 'bk_ac_send', 10, 2 );

/**
 * Envía el correo de un paso si el carrito sigue abandonado.
 *
 * @param int $id   Carrito.
 * @param int $step Paso (1–3).
 * @return bool Enviado.
 */
function bk_ac_send( $id, $step ) {
	$s   = bk_ac_settings();
	$row = bk_ac_get( (int) $id );
	if ( ! $row || 'yes' !== $s['enabled'] || 'active' !== $row->status || (int) $row->emails_sent >= (int) $step
		|| ! $row->consent || ! is_email( $row->email ) || bk_ac_is_opted_out( $row->email ) || empty( $row->items ) ) {
		return false;
	}

	// ¿Ya compró con ese correo (otro dispositivo, otra sesión)? Entonces no se le escribe.
	$orders = wc_get_orders(
		array(
			'billing_email' => $row->email,
			'date_created'  => '>' . strtotime( $row->created_at . ' UTC' ),
			'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			'limit'         => 1,
			'return'        => 'ids',
		)
	);
	if ( $orders ) {
		bk_ac_unschedule( (int) $row->id );
		bk_ac_update( (int) $row->id, array( 'status' => 'converted', 'order_id' => (int) $orders[0] ) );
		return false;
	}

	$coupon = bk_ac_maybe_coupon( $row, (int) $step );
	$mail   = bk_ac_build_email( $row, (int) $step, $coupon );
	$sent   = bk_ac_mail( $row->email, $mail['subject'], $mail['html'], $row );
	if ( $sent ) {
		bk_ac_update(
			(int) $row->id,
			array(
				'emails_sent'   => (int) $step,
				'last_email_at' => bk_ac_now(),
				'coupon_code'   => $coupon ? $coupon['code'] : $row->coupon_code,
			)
		);
	}
	return $sent;
}

/**
 * Envía el HTML con el remitente de WooCommerce y cabecera de baja (List-Unsubscribe).
 *
 * @param string      $to      Destino.
 * @param string      $subject Asunto.
 * @param string      $html    Cuerpo.
 * @param object|null $row     Carrito (para el enlace de baja).
 * @return bool
 */
function bk_ac_mail( $to, $subject, $html, $row = null ) {
	$from_name  = wp_specialchars_decode( get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ), ENT_QUOTES );
	$from_email = sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
	$headers    = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $from_name . ' <' . $from_email . '>',
	);
	if ( $row ) {
		$headers[] = 'List-Unsubscribe: <' . esc_url_raw( bk_ac_unsub_url( $row ) ) . '>';
	}
	return (bool) wp_mail( $to, $subject, $html, $headers );
}

/**
 * Enlace que restaura el carrito.
 *
 * @param object $row  Carrito.
 * @param int    $step Paso (para la medición en GA4).
 * @return string
 */
function bk_ac_recover_url( $row, $step ) {
	return add_query_arg(
		array(
			'bk_ac'        => $row->token,
			'k'            => bk_ac_sig( $row->token, 'recover' ),
			'utm_source'   => 'email',
			'utm_medium'   => 'carrito-abandonado',
			'utm_campaign' => 'recordatorio-' . (int) $step,
		),
		home_url( '/' )
	);
}

/**
 * Enlace de baja.
 *
 * @param object $row Carrito.
 * @return string
 */
function bk_ac_unsub_url( $row ) {
	return add_query_arg(
		array(
			'bk_ac_unsub' => $row->token,
			'k'           => bk_ac_sig( $row->token, 'unsub' ),
		),
		home_url( '/' )
	);
}

/**
 * Crea (una sola vez por carrito) un cupón personal si el paso lo tiene activado.
 *
 * @param object $row  Carrito.
 * @param int    $step Paso.
 * @return array{code:string,pct:int,expires:int}|null
 */
function bk_ac_maybe_coupon( $row, $step ) {
	$s   = bk_ac_settings();
	$pct = (int) ( $s['coupon_pct'][ $step - 1 ] ?? 0 );
	if ( $pct <= 0 || ! class_exists( 'WC_Coupon' ) ) {
		return null;
	}
	if ( $row->coupon_code ) {
		$c = new WC_Coupon( $row->coupon_code );
		if ( $c->get_id() ) {
			return array(
				'code'    => $c->get_code(),
				'pct'     => (int) $c->get_amount(),
				'expires' => $c->get_date_expires() ? $c->get_date_expires()->getTimestamp() : 0,
			);
		}
	}
	$code    = 'VUELVE-' . strtoupper( wp_generate_password( 6, false, false ) );
	$expires = time() + (int) $s['coupon_hours'] * HOUR_IN_SECONDS;
	$coupon  = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( $pct );
	$coupon->set_individual_use( true );
	$coupon->set_usage_limit( 1 );
	$coupon->set_usage_limit_per_user( 1 );
	$coupon->set_email_restrictions( array( $row->email ) );
	$coupon->set_exclude_sale_items( true ); // Las prendas en descuento no acumulan otro descuento.
	$coupon->set_date_expires( $expires );
	$coupon->set_description( 'Cupón automático de carrito abandonado #' . (int) $row->id );
	$coupon->save();
	return array(
		'code'    => $code,
		'pct'     => $pct,
		'expires' => $expires,
	);
}

/**
 * Precio legible sin HTML ("$149.900").
 *
 * @param float $amount Valor.
 * @return string
 */
function bk_ac_price( $amount ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Textos de cada paso.
 *
 * @param int        $step   Paso.
 * @param object     $row    Carrito.
 * @param array|null $coupon Cupón.
 * @return array{pre:string,title:string,body:string,cta:string}
 */
function bk_ac_copy( $step, $row, $coupon ) {
	$name = $row->first_name ? $row->first_name : __( 'Hola', 'buckler-core' );
	$copy = array(
		1 => array(
			'pre'   => 'Guardamos tus prendas para que termines tu compra cuando quieras.',
			'title' => $row->first_name ? "{$name}, dejaste algo en tu carrito" : 'Dejaste algo en tu carrito',
			'body'  => 'Tus prendas siguen guardadas. Termina tu compra en un par de clics: el envío es gratis a todo el país y puedes pagar con Addi en 3 cuotas con 0 % de interés.',
			'cta'   => 'Terminar mi compra',
		),
		2 => array(
			'pre'   => '¿Dudas con la talla? Te ayudamos a elegir.',
			'title' => 'Tus prendas te siguen esperando',
			'body'  => '¿No estás segura de la talla? Escríbenos por WhatsApp y te ayudamos a elegir la que mejor te queda. Tu carrito sigue listo.',
			'cta'   => 'Ver mi carrito',
		),
		3 => array(
			'pre'   => 'Tu carrito sigue listo para terminar la compra.',
			'title' => 'Última llamada para tu carrito',
			'body'  => 'No queremos que te quedes sin tus prendas. Tu carrito sigue listo: termina la compra cuando quieras desde este correo.',
			'cta'   => 'Terminar mi compra',
		),
	);
	$c = $copy[ $step ];
	if ( $coupon ) {
		$date       = wp_date( 'j \d\e F', $coupon['expires'] );
		$c['pre']   = sprintf( 'Tienes %d %% de descuento para terminar tu compra.', $coupon['pct'] );
		$c['title'] = sprintf( 'Te regalamos %d %% para terminar tu compra', $coupon['pct'] );
		$c['body']  = sprintf( 'Se aplica automáticamente al abrir tu carrito desde este correo y es válido hasta el %s. No aplica a prendas en descuento.', $date );
		$c['cta']   = 'Usar mi descuento';
	}
	return $c;
}

/**
 * Arma el correo (asunto + HTML). También lo usa la vista previa del admin.
 *
 * @param object     $row    Carrito.
 * @param int        $step   Paso.
 * @param array|null $coupon Cupón.
 * @return array{subject:string,html:string}
 */
function bk_ac_build_email( $row, $step, $coupon = null ) {
	$s       = bk_ac_settings();
	$subject = str_replace( '{nombre}', $row->first_name ? $row->first_name : 'Hola', (string) ( $s['subjects'][ $step - 1 ] ?? '' ) );
	if ( $coupon ) {
		$subject = sprintf( '%d %% de descuento para terminar tu compra', $coupon['pct'] );
	}
	$copy = bk_ac_copy( $step, $row, $coupon );

	// Aviso de pocas unidades: SOLO si es verdad (stock real ≤ 3).
	$low = array();
	if ( 2 === $step ) {
		foreach ( $row->items as $item ) {
			$p = wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) );
			if ( $p && $p->managing_stock() && null !== $p->get_stock_quantity() && $p->get_stock_quantity() > 0 && $p->get_stock_quantity() <= 3 ) {
				$low[] = $item['name'];
			}
		}
	}

	$vars = array(
		'row'      => $row,
		'step'     => $step,
		'copy'     => $copy,
		'coupon'   => $coupon,
		'low'      => $low,
		'recover'  => bk_ac_recover_url( $row, $step ),
		'unsub'    => bk_ac_unsub_url( $row ),
		'logo'     => BK_URL . 'assets/img/logo-email.png', // PNG: Outlook no muestra WebP.
		'whatsapp' => bk_whatsapp_url(),
		'company'  => (string) bk_config( 'company' ),
		'city'     => (string) bk_config( 'city' ),
	);
	ob_start();
	include BK_DIR . 'templates/emails/abandoned-cart.php';
	return array(
		'subject' => $subject,
		'html'    => (string) ob_get_clean(),
	);
}

/* ---------------------------------------------------------------------------
 * Limpieza diaria: carritos vencidos y datos viejos (minimización de datos personales)
 * ------------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'bk_ac_cleanup', array(), 'buckler-ac' ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:00' ), DAY_IN_SECONDS, 'bk_ac_cleanup', array(), 'buckler-ac' );
		}
	},
	20
);

add_action( 'bk_ac_cleanup', 'bk_ac_cleanup' );

/**
 * Vence carritos sin actividad y borra los que superan el tiempo de retención.
 */
function bk_ac_cleanup() {
	global $wpdb;
	$table = bk_ac_table();
	$days  = max( 7, (int) bk_ac_settings()['retention'] );
	$wpdb->query( "UPDATE {$table} SET status = 'expired' WHERE status = 'active' AND emails_sent >= 3 AND updated_at < UTC_TIMESTAMP() - INTERVAL 7 DAY" ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < UTC_TIMESTAMP() - INTERVAL %d DAY", $days ) ); // phpcs:ignore WordPress.DB
}

/* ---------------------------------------------------------------------------
 * Privacidad (Herramientas → Exportar/Borrar datos personales de WordPress)
 * ------------------------------------------------------------------------- */

add_filter(
	'wp_privacy_personal_data_exporters',
	static function ( $exporters ) {
		$exporters['buckler-ac'] = array(
			'exporter_friendly_name' => 'Carritos abandonados (Buckler)',
			'callback'               => 'bk_ac_privacy_export',
		);
		return $exporters;
	}
);
add_filter(
	'wp_privacy_personal_data_erasers',
	static function ( $erasers ) {
		$erasers['buckler-ac'] = array(
			'eraser_friendly_name' => 'Carritos abandonados (Buckler)',
			'callback'             => 'bk_ac_privacy_erase',
		);
		return $erasers;
	}
);

/**
 * Exporta los carritos guardados de un correo.
 *
 * @param string $email Correo.
 * @return array
 */
function bk_ac_privacy_export( $email ) {
	global $wpdb;
	$table = bk_ac_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB
	$data  = array();
	foreach ( $rows as $r ) {
		$data[] = array(
			'group_id'    => 'buckler-ac',
			'group_label' => 'Carritos abandonados',
			'item_id'     => 'bk-ac-' . $r->id,
			'data'        => array(
				array( 'name' => 'Correo', 'value' => $r->email ),
				array( 'name' => 'Nombre', 'value' => $r->first_name ),
				array( 'name' => 'Teléfono', 'value' => $r->phone ),
				array( 'name' => 'Otros datos', 'value' => implode( ', ', bk_ac_extra( $r ) ) ),
				array( 'name' => 'Productos', 'value' => implode( ', ', wp_list_pluck( json_decode( $r->cart, true ) ?: array(), 'name' ) ) ),
				array( 'name' => 'Fecha', 'value' => $r->created_at ),
			),
		);
	}
	return array( 'data' => $data, 'done' => true );
}

/**
 * Borra los carritos guardados de un correo.
 *
 * @param string $email Correo.
 * @return array
 */
function bk_ac_privacy_erase( $email ) {
	global $wpdb;
	$table = bk_ac_table();
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $id ) {
		bk_ac_unschedule( (int) $id );
		bk_ac_delete( (int) $id );
	}
	return array( 'items_removed' => count( $ids ), 'items_retained' => false, 'messages' => array(), 'done' => true );
}
