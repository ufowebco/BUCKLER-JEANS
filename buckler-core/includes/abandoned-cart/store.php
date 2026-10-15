<?php
/**
 * Carritos abandonados: tabla, ajustes y acceso a datos.
 *
 * Tabla propia `{prefix}bk_abandoned_carts` (no usa postmeta: rápida y fácil de limpiar).
 * Compatible con HPOS: no depende de cómo WooCommerce guarda los pedidos.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_AC_DB_VERSION = '2'; // 2: columna extra (apellido, cédula, ciudad, dirección).

/**
 * Nombre de la tabla.
 *
 * @return string
 */
function bk_ac_table() {
	global $wpdb;
	return $wpdb->prefix . 'bk_abandoned_carts';
}

/**
 * Crea o actualiza la tabla (dbDelta). Se llama al activar y si cambia la versión.
 */
function bk_ac_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = bk_ac_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			session_key varchar(100) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			first_name varchar(100) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			extra longtext NULL,
			cart longtext NOT NULL,
			cart_total decimal(19,4) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'COP',
			status varchar(20) NOT NULL DEFAULT 'active',
			emails_sent tinyint(3) unsigned NOT NULL DEFAULT 0,
			consent tinyint(1) unsigned NOT NULL DEFAULT 0,
			coupon_code varchar(50) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			clicked_at datetime NULL DEFAULT NULL,
			last_email_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY session_key (session_key),
			KEY email (email),
			KEY status_updated (status, updated_at)
		) {$charset};"
	);
	update_option( 'bk_ac_db_version', BK_AC_DB_VERSION, false );
}

add_action( 'admin_init', 'bk_ac_maybe_install' );

/**
 * Actualiza la tabla si cambió su versión (en wp-admin y antes de cada captura: la tienda puede recibir
 * carritos antes de que alguien entre a wp-admin tras actualizar el plugin).
 */
function bk_ac_maybe_install() {
	if ( get_option( 'bk_ac_db_version' ) !== BK_AC_DB_VERSION ) {
		bk_ac_install();
	}
}

/**
 * Datos extra guardados con el carrito (apellido, cédula, ciudad, departamento, dirección).
 *
 * @param object $row Carrito.
 * @return array
 */
function bk_ac_extra( $row ) {
	$x = json_decode( (string) ( $row->extra ?? '' ), true );
	return is_array( $x ) ? $x : array();
}

/**
 * Ajustes con valores por defecto. Se editan en WooCommerce → Carritos abandonados → Ajustes.
 *
 * @return array
 */
function bk_ac_settings() {
	$defaults = array(
		'enabled'      => 'yes',
		// Decisión del cliente (2026-10-15): sin casilla, siempre activo para todas → "notice" (aviso bajo el correo).
		// "checkbox" (autorización expresa, Ley 1581) solo con el filtro bk_ac_consent_mode.
		'consent_mode' => 'notice',
		'consent_text' => 'Quiero que me escriban a este correo si mi compra queda sin terminar.',
		'delays'       => array( 2, 24, 48 ), // Horas desde que se captura el carrito.
		'subjects'     => array(
			'{nombre}, tu carrito te está esperando',
			'Seguimos guardando tus prendas',
			'Última llamada para tu carrito',
		),
		// Cupón por correo: 0 = sin cupón. Porcentaje de descuento. Por defecto apagado (pendiente de decisión).
		'coupon_pct'   => array( 0, 0, 0 ),
		'coupon_hours' => 72,
		'retention'    => 60, // Días que se guardan los datos (minimización de datos personales).
	);
	$saved = (array) get_option( 'bk_ac_settings', array() );
	$s     = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
	// El modo guardado antes ("checkbox") ya no aplica: siempre aviso, salvo que un filtro diga otra cosa.
	$s['consent_mode'] = 'checkbox' === apply_filters( 'bk_ac_consent_mode', 'notice' ) ? 'checkbox' : 'notice';
	return $s;
}

/**
 * Fecha actual en UTC para la base de datos.
 *
 * @return string
 */
function bk_ac_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Lee un carrito por ID o token.
 *
 * @param int|string $id_or_token ID numérico o token.
 * @return object|null
 */
function bk_ac_get( $id_or_token ) {
	global $wpdb;
	$table = bk_ac_table();
	if ( is_numeric( $id_or_token ) ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id_or_token ) ); // phpcs:ignore WordPress.DB
	} else {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $id_or_token ) ); // phpcs:ignore WordPress.DB
	}
	if ( $row ) {
		$row->items = json_decode( $row->cart, true ) ?: array();
	}
	return $row;
}

/**
 * Actualiza campos de un carrito.
 *
 * @param int   $id   ID.
 * @param array $data Columnas.
 */
function bk_ac_update( $id, array $data ) {
	global $wpdb;
	$data['updated_at'] = bk_ac_now();
	$wpdb->update( bk_ac_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
}

/**
 * Firma HMAC para enlaces de recuperación y baja (no se pueden adivinar ni alterar).
 *
 * @param string $token  Token del carrito.
 * @param string $action recover|unsub.
 * @return string
 */
function bk_ac_sig( $token, $action ) {
	return substr( hash_hmac( 'sha256', $action . '|' . $token, wp_salt( 'auth' ) ), 0, 20 );
}

/**
 * ¿El correo pidió no volver a recibir recordatorios?
 *
 * @param string $email Correo.
 * @return bool
 */
function bk_ac_is_opted_out( $email ) {
	$list = (array) get_option( 'bk_ac_optout', array() );
	return isset( $list[ md5( strtolower( trim( $email ) ) ) ] );
}

/**
 * Marca un correo como dado de baja (se guarda solo su hash, no el correo).
 *
 * @param string $email Correo.
 */
function bk_ac_opt_out( $email ) {
	$list = (array) get_option( 'bk_ac_optout', array() );
	$list[ md5( strtolower( trim( $email ) ) ) ] = time();
	update_option( 'bk_ac_optout', $list, false );
}

/**
 * Cancela los correos pendientes de un carrito.
 *
 * @param int $id ID.
 */
function bk_ac_unschedule( $id ) {
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}
	// Solo las acciones de ESTE carrito (los args identifican carrito y paso).
	foreach ( array( 1, 2, 3 ) as $step ) {
		as_unschedule_all_actions( 'bk_ac_send', array( (int) $id, $step ), 'buckler-ac' );
	}
}
