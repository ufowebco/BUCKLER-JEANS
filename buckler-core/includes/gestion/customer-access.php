<?php
/**
 * Acceso a Mi cuenta para clientas que ya compraron (pedido del cliente 2026-11-27).
 *
 * - Detalle del pedido: "Enviar acceso a su cuenta" (correo con enlace para crear la contraseña, 72 h, un solo uso)
 *   y "Copiar enlace" (para WhatsApp). Si no tiene cuenta, se crea con el correo y los datos del pedido y se le
 *   asignan todos sus pedidos con ese correo.
 * - Correos: envío a todas las clientas que compraron (pedidos pagados) y aún no tienen cuenta, por tandas
 *   (Action Scheduler, grupo buckler-acc) para no saturar el correo del hosting.
 *
 * Rutas: GET/POST bk/v1/gestion/orders/{id}/account · GET/POST bk/v1/gestion/access/bulk.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tandas del envío masivo (filtro bk_acc_bulk_cfg).
 *
 * @return array{batch:int,every:int}
 */
function bk_gca_cfg() {
	return (array) apply_filters( 'bk_acc_bulk_cfg', array( 'batch' => 20, 'every' => 10 * MINUTE_IN_SECONDS ) );
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( 'bk/v1', '/gestion/orders/(?P<id>\d+)/account', array( array( 'methods' => 'GET', 'callback' => 'bk_gca_get', 'permission_callback' => 'bk_g_can' ), array( 'methods' => 'POST', 'callback' => 'bk_gca_post', 'permission_callback' => 'bk_g_can' ) ) );
		register_rest_route( 'bk/v1', '/gestion/access/bulk', array( array( 'methods' => 'GET', 'callback' => 'bk_gca_bulk_get', 'permission_callback' => 'bk_g_can' ), array( 'methods' => 'POST', 'callback' => 'bk_gca_bulk_post', 'permission_callback' => 'bk_g_can' ) ) );
	}
);

/**
 * Estado de la cuenta de la clienta de un pedido.
 *
 * @param WC_Order $o Pedido.
 * @return array
 */
function bk_gca_state( $o ) {
	$email = strtolower( trim( (string) $o->get_billing_email() ) );
	$u     = is_email( $email ) ? get_user_by( 'email', $email ) : false;
	$sent  = $o->get_meta( '_bk_acc_sent' );
	$out   = array(
		'email'  => $email,
		'valid'  => (bool) is_email( $email ),
		'status' => 'none',        // none | pending (creada por la tienda, sin contraseña) | active.
		'staff'  => false,
		'sent'   => is_array( $sent ) ? array( 'date' => wp_date( 'j M Y, g:i a', (int) $sent['t'] ), 'by' => (string) ( $sent['by'] ?? '' ), 'how' => (string) ( $sent['how'] ?? 'correo' ) ) : null,
		'expires' => '',
		'hours'  => (int) bk_acc_cfg()['hours'],
	);
	if ( $u ) {
		$out['staff']  = user_can( $u, 'edit_posts' ) || user_can( $u, 'bk_gestion' );
		$active        = (int) get_user_meta( $u->ID, '_bk_acc_active', true );
		$by_store      = 'gestion' === get_user_meta( $u->ID, '_bk_acc_source', true );
		$out['status'] = ( $by_store && ! $active ) ? 'pending' : 'active';
		$exp           = (int) get_user_meta( $u->ID, '_bk_acc_exp', true );
		if ( $exp > time() ) {
			$out['expires'] = wp_date( 'j M, g:i a', $exp );
		}
	}
	return $out;
}

/**
 * GET: estado de la cuenta.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gca_get( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( bk_gca_state( $o ) );
}

/**
 * Usuario de la clienta (lo crea si no existe).
 *
 * @param WC_Order $o Pedido.
 * @return WP_User|WP_Error
 */
function bk_gca_user( $o ) {
	$email = strtolower( trim( (string) $o->get_billing_email() ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'bk_email', 'El pedido no tiene un correo válido. Corrígelo en "Editar datos".', array( 'status' => 400 ) );
	}
	$u = get_user_by( 'email', $email );
	if ( $u && ( user_can( $u, 'edit_posts' ) || user_can( $u, 'bk_gestion' ) ) ) {
		return new WP_Error( 'bk_staff', 'Ese correo es de una cuenta del equipo. No se envía acceso desde aquí.', array( 'status' => 400 ) );
	}
	if ( ! $u ) {
		$id = bk_acc_create_user( $o );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( $id, '_bk_acc_source', 'gestion' );
		$u = get_userdata( $id );
	} else {
		bk_acc_link_orders( $u->ID ); // Por si tenía pedidos como invitada.
	}
	return $u;
}

/**
 * Correo "Tu cuenta está lista".
 *
 * @param WP_User  $u   Usuario.
 * @param string   $url Enlace.
 * @param WC_Order $o   Pedido de referencia (para contar los pedidos).
 * @return array{subject:string,html:string}
 */
function bk_gca_email( $u, $url, $o = null ) {
	$name   = $u ? trim( (string) $u->first_name ) : ( $o ? trim( (string) $o->get_billing_first_name() ) : '' );
	$active = $u && (int) get_user_meta( $u->ID, '_bk_acc_active', true );
	$count  = 0;
	if ( $u ) {
		$count = count( wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => 50, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) ) );
	}
	if ( ! $count && $o ) {
		$count = 1;
	}
	$copy = array(
		'subject' => ( $name ? $name . ', tu' : 'Tu' ) . ' cuenta en Buckler Jeans está lista',
		'pre'     => 'Crea tu contraseña y sigue tus pedidos cuando quieras.',
		'title'   => $active ? 'Crea una contraseña nueva' : ( $name ? $name . ', ya tienes tu cuenta' : 'Ya tienes tu cuenta' ),
		'body'    => ( $count > 1 ? sprintf( 'Guardamos tus <strong>%d pedidos</strong> en tu cuenta de Buckler Jeans.', $count ) : 'Guardamos tu pedido en tu cuenta de Buckler Jeans.' ) . ' Solo falta que elijas tu contraseña para entrar: ahí podrás seguir tus envíos, ver tus compras y comprar más rápido la próxima vez.',
		'cta'     => 'Crear mi contraseña',
	);
	$vars = array(
		'copy'     => $copy,
		'url'      => $url,
		'email'    => $u ? $u->user_email : ( $o ? $o->get_billing_email() : '' ),
		'hours'    => (int) bk_acc_cfg()['hours'],
		'logo'     => BK_URL . 'assets/img/logo-email.png',
		'whatsapp' => bk_whatsapp_url( 'Hola, necesito ayuda para entrar a mi cuenta en buckler.com.co' ),
		'company'  => (string) bk_config( 'company' ),
		'city'     => (string) bk_config( 'city' ),
	);
	ob_start();
	include BK_DIR . 'templates/emails/account-access.php';
	return array( 'subject' => $copy['subject'], 'html' => (string) ob_get_clean() );
}

/**
 * Envía el acceso de un pedido.
 *
 * @param WC_Order $o   Pedido.
 * @param string   $how correo | enlace | masivo.
 * @return array{url:string,expires:int}|WP_Error
 */
function bk_gca_send( $o, $how = 'correo' ) {
	$u = bk_gca_user( $o );
	if ( is_wp_error( $u ) ) {
		return $u;
	}
	$link = bk_acc_make_link( $u->ID );
	if ( 'enlace' !== $how ) {
		$mail = bk_gca_email( $u, $link['url'], $o );
		$from = wp_specialchars_decode( get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ), ENT_QUOTES ) . ' <' . sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) ) . '>';
		if ( ! wp_mail( $u->user_email, $mail['subject'], $mail['html'], array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from ) ) ) {
			return new WP_Error( 'bk_mail', 'No se pudo enviar el correo. Copia el enlace y envíalo por WhatsApp.', array( 'status' => 500 ) );
		}
	}
	$by = 'masivo' === $how ? '' : wp_get_current_user()->display_name;
	$o->update_meta_data( '_bk_acc_sent', array( 't' => time(), 'by' => $by, 'how' => $how ) );
	$labels = array( 'correo' => 'enviado por correo', 'enlace' => 'enlace copiado para WhatsApp', 'masivo' => 'enviado en el envío masivo' );
	if ( function_exists( 'bk_g_log' ) ) {
		bk_g_log( $o, 'acceso', $labels[ $how ] ?? $how );
	}
	/* translators: %s: cómo */
	$o->add_order_note( sprintf( 'Acceso a Mi cuenta: %s (enlace válido %d h).', $labels[ $how ] ?? $how, (int) bk_acc_cfg()['hours'] ), false, false );
	$o->save();
	update_user_meta( $u->ID, '_bk_acc_invited', time() );
	return $link;
}

/**
 * POST: preview | send | link.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gca_post( WP_REST_Request $r ) {
	$o = wc_get_order( (int) $r['id'] );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$action = (string) $r['action'];
	if ( 'preview' === $action ) {
		$u    = get_user_by( 'email', strtolower( (string) $o->get_billing_email() ) );
		$mail = bk_gca_email( $u ? $u : null, home_url( '/' . bk_acc_cfg()['slug'] . '/?vista=previa' ), $o );
		return rest_ensure_response( array( 'subject' => $mail['subject'], 'html' => $mail['html'], 'to' => $o->get_billing_email() ) );
	}
	if ( ! in_array( $action, array( 'send', 'link' ), true ) ) {
		return new WP_Error( 'bk_action', 'Acción no válida.', array( 'status' => 400 ) );
	}
	$res = bk_gca_send( $o, 'send' === $action ? 'correo' : 'enlace' );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return rest_ensure_response(
		array(
			'ok'      => true,
			'url'     => 'link' === $action ? $res['url'] : '',
			'expires' => wp_date( 'j M, g:i a', $res['expires'] ),
			'state'   => bk_gca_state( wc_get_order( $o->get_id() ) ),
		)
	);
}

/* ---------------------------------------------------------------------------
 * Envío masivo
 * ------------------------------------------------------------------------- */

/**
 * Correos de clientas con pedidos pagados que aún no tienen cuenta (sin las que ya recibieron el acceso).
 *
 * @return array<string,int> correo => pedido más reciente.
 */
function bk_gca_candidates() {
	global $wpdb;
	$paid = array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() );
	$in   = implode( ',', array_fill( 0, count( $paid ), '%s' ) );
	$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos ) {
		$sql = "SELECT LOWER(billing_email) AS e, MAX(id) AS o FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND customer_id = 0 AND billing_email <> '' AND status IN ($in) GROUP BY LOWER(billing_email)";
	} else {
		$sql = "SELECT LOWER(m.meta_value) AS e, MAX(p.ID) AS o FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_billing_email' LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_customer_user' WHERE p.post_type = 'shop_order' AND p.post_status IN ($in) AND m.meta_value <> '' AND ( c.meta_value IS NULL OR c.meta_value = '0' ) GROUP BY LOWER(m.meta_value)";
	}
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $paid ) ); // phpcs:ignore WordPress.DB
	$done = (array) get_option( 'bk_acc_bulk_done', array() );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$e = trim( (string) $row->e );
		if ( ! is_email( $e ) || isset( $done[ md5( $e ) ] ) || email_exists( $e ) ) {
			continue;
		}
		$out[ $e ] = (int) $row->o;
	}
	return $out;
}

/**
 * Estado del envío masivo.
 *
 * @return array
 */
function bk_gca_bulk_state() {
	$q   = get_option( 'bk_acc_bulk' );
	$cfg = bk_gca_cfg();
	$out = array(
		'pending' => count( bk_gca_candidates() ),
		'invited' => count( (array) get_option( 'bk_acc_bulk_done', array() ) ),
		'batch'   => (int) $cfg['batch'],
		'minutes' => (int) round( $cfg['every'] / MINUTE_IN_SECONDS ),
		'hours'   => (int) bk_acc_cfg()['hours'],
		'run'     => null,
	);
	if ( is_array( $q ) ) {
		$left       = count( (array) $q['queue'] );
		$out['run'] = array(
			'running' => $left > 0 && empty( $q['stopped'] ),
			'stopped' => ! empty( $q['stopped'] ),
			'total'   => (int) $q['total'],
			'sent'    => (int) $q['sent'],
			'failed'  => (int) $q['failed'],
			'left'    => $left,
			'started' => wp_date( 'j M, g:i a', (int) $q['started'] ),
			'by'      => (string) $q['by'],
			'eta'     => $left ? wp_date( 'j M, g:i a', time() + (int) ceil( $left / max( 1, $cfg['batch'] ) ) * $cfg['every'] ) : '',
		);
	}
	return $out;
}

/**
 * GET: cuántas clientas faltan y cómo va el envío.
 *
 * @return WP_REST_Response
 */
function bk_gca_bulk_get() {
	return rest_ensure_response( bk_gca_bulk_state() );
}

/**
 * POST: start | stop.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gca_bulk_post( WP_REST_Request $r ) {
	$action = (string) $r['action'];
	if ( 'stop' === $action ) {
		$q = get_option( 'bk_acc_bulk' );
		if ( is_array( $q ) ) {
			$q['stopped'] = time();
			update_option( 'bk_acc_bulk', $q, false );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'bk_acc_bulk_tick', array(), 'buckler-acc' );
		}
		return rest_ensure_response( bk_gca_bulk_state() );
	}
	if ( 'start' !== $action ) {
		return new WP_Error( 'bk_action', 'Acción no válida.', array( 'status' => 400 ) );
	}
	$q = get_option( 'bk_acc_bulk' );
	if ( is_array( $q ) && $q['queue'] && empty( $q['stopped'] ) ) {
		return new WP_Error( 'bk_running', 'Ya hay un envío en curso.', array( 'status' => 409 ) );
	}
	$list = bk_gca_candidates();
	if ( ! $list ) {
		return new WP_Error( 'bk_empty', 'No hay clientas pendientes: todas tienen cuenta o ya recibieron el acceso.', array( 'status' => 400 ) );
	}
	update_option(
		'bk_acc_bulk',
		array(
			'queue'   => array_values( $list ), // IDs del pedido más reciente de cada clienta.
			'total'   => count( $list ),
			'sent'    => 0,
			'failed'  => 0,
			'started' => time(),
			'by'      => wp_get_current_user()->display_name,
		),
		false
	);
	bk_gca_bulk_tick(); // Primera tanda ya.
	return rest_ensure_response( bk_gca_bulk_state() );
}

add_action( 'bk_acc_bulk_tick', 'bk_gca_bulk_tick' );

/**
 * Envía una tanda y programa la siguiente.
 */
function bk_gca_bulk_tick() {
	$q = get_option( 'bk_acc_bulk' );
	if ( ! is_array( $q ) || ! empty( $q['stopped'] ) || ! $q['queue'] ) {
		return;
	}
	$cfg  = bk_gca_cfg();
	$done = (array) get_option( 'bk_acc_bulk_done', array() );
	for ( $i = 0; $i < (int) $cfg['batch'] && $q['queue']; $i++ ) {
		$o = wc_get_order( (int) array_shift( $q['queue'] ) );
		if ( ! $o ) {
			++$q['failed'];
			continue;
		}
		$email = strtolower( trim( (string) $o->get_billing_email() ) );
		if ( isset( $done[ md5( $email ) ] ) || ( email_exists( $email ) && 'gestion' !== get_user_meta( (int) email_exists( $email ), '_bk_acc_source', true ) ) ) {
			continue; // Ya tiene cuenta propia o ya se le envió.
		}
		$res = bk_gca_send( $o, 'masivo' );
		if ( is_wp_error( $res ) ) {
			++$q['failed'];
			continue;
		}
		$done[ md5( $email ) ] = time();
		++$q['sent'];
	}
	update_option( 'bk_acc_bulk_done', $done, false );
	update_option( 'bk_acc_bulk', $q, false );
	if ( $q['queue'] && function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + (int) $cfg['every'], 'bk_acc_bulk_tick', array(), 'buckler-acc' );
	}
}
