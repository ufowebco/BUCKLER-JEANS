<?php
/**
 * Carritos abandonados: captura en el checkout, sincronización del carrito, compra, recuperación y baja.
 *
 * Flujo:
 * 1. En el checkout, al escribir el correo (y autorizar, si el modo es "checkbox"), JS envía el correo
 *    a `?wc-ajax=bk_ac_capture`. Se guarda una copia del carrito y se programan los 3 correos.
 * 2. Si la clienta cambia el carrito, la copia se actualiza. Si lo vacía, se cancelan los correos.
 * 3. Al crear el pedido, el carrito se marca como comprado y se cancelan los correos. Si el pago falla o
 *    el pedido se cancela, se reactivan los correos que faltaban.
 * 4. El enlace del correo restaura el carrito (aunque la sesión ya no exista) y lleva al checkout.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * 1. Campo de autorización y script de captura en el checkout
 * ------------------------------------------------------------------------- */

add_filter( 'woocommerce_checkout_fields', 'bk_ac_consent_field' );

/**
 * Añade la casilla de autorización justo después del correo (modo "checkbox").
 *
 * @param array $fields Campos del checkout.
 * @return array
 */
function bk_ac_consent_field( $fields ) {
	$s = bk_ac_settings();
	if ( 'yes' !== $s['enabled'] || 'checkbox' !== $s['consent_mode'] || is_user_logged_in() && bk_ac_is_opted_out( wp_get_current_user()->user_email ) ) {
		return $fields;
	}
	$email_priority                       = (int) ( $fields['billing']['billing_email']['priority'] ?? 110 );
	$fields['billing']['bk_ac_consent'] = array(
		'type'     => 'checkbox',
		'label'    => esc_html( $s['consent_text'] ),
		'required' => false,
		'class'    => array( 'form-row-wide', 'bk-ac-consent' ),
		'priority' => $email_priority + 1,
	);
	return $fields;
}

add_filter( 'woocommerce_checkout_get_value', 'bk_ac_consent_default', 10, 2 );

/**
 * Casilla ya marcada si esta sesión tiene un carrito con autorización (p. ej. llegó desde el correo).
 *
 * @param mixed  $value Valor.
 * @param string $input Campo.
 * @return mixed
 */
function bk_ac_consent_default( $value, $input ) {
	if ( 'bk_ac_consent' !== $input || null !== $value || ! WC()->session ) {
		return $value;
	}
	$token = (string) WC()->session->get( 'bk_ac_token' );
	$row   = $token ? bk_ac_get( $token ) : null;
	return ( $row && $row->consent && 'active' === $row->status ) ? 1 : $value;
}

add_action( 'wp_enqueue_scripts', 'bk_ac_enqueue', 30 );

/**
 * Script de captura, solo en el checkout (nunca en páginas cacheadas).
 */
function bk_ac_enqueue() {
	if ( 'yes' !== bk_ac_settings()['enabled'] || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}
	$s = bk_ac_settings();
	wp_enqueue_script( 'bk-ac', BK_URL . 'assets/js/ac-capture.js', array(), BK_VERSION . '.' . filemtime( BK_DIR . 'assets/js/ac-capture.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'bk-ac',
		'bkAc',
		array(
			'url'    => WC_AJAX::get_endpoint( 'bk_ac_capture' ),
			'nonce'  => wp_create_nonce( 'bk_ac_capture' ),
			'mode'   => $s['consent_mode'],
			'notice' => 'notice' === $s['consent_mode'] ? __( 'Si tu compra queda sin terminar, te escribiremos a este correo para que la retomes. Puedes darte de baja en cualquier momento.', 'buckler-core' ) : '',
		)
	);
}

/* ---------------------------------------------------------------------------
 * 2. Captura (wc-ajax: más liviano que admin-ajax)
 * ------------------------------------------------------------------------- */

add_action( 'wc_ajax_bk_ac_capture', 'bk_ac_ajax_capture' );

/**
 * Copia del carrito actual.
 *
 * @return array{items:array,total:float}
 */
function bk_ac_cart_snapshot() {
	$items = array();
	foreach ( WC()->cart->get_cart() as $line ) {
		/** @var WC_Product $product */
		$product = $line['data'];
		$attrs   = array();
		foreach ( (array) ( $line['variation'] ?? array() ) as $key => $value ) {
			$tax           = str_replace( 'attribute_', '', $key );
			$term          = taxonomy_exists( $tax ) ? get_term_by( 'slug', $value, $tax ) : null;
			$attrs[ $key ] = array(
				'label' => wc_attribute_label( $tax, $product ),
				'value' => $term ? $term->name : $value,
				'raw'   => $value,
			);
		}
		$items[] = array(
			'product_id'   => (int) $line['product_id'],
			'variation_id' => (int) $line['variation_id'],
			'quantity'     => (int) $line['quantity'],
			'variation'    => $attrs,
			'name'         => $product->get_name(),
			'line_total'   => (float) $line['line_subtotal'] + (float) $line['line_subtotal_tax'],
			'image'        => (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
		);
	}
	return array(
		'items' => $items,
		'total' => (float) WC()->cart->get_total( 'edit' ),
	);
}

/**
 * Guarda o actualiza el carrito con el correo de la clienta.
 */
function bk_ac_ajax_capture() {
	check_ajax_referer( 'bk_ac_capture', 'nonce' );
	$s       = bk_ac_settings();
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$consent = ! empty( $_POST['consent'] ) || 'notice' === $s['consent_mode'];
	$token   = WC()->session ? (string) WC()->session->get( 'bk_ac_token' ) : '';
	$row     = $token ? bk_ac_get( $token ) : null;

	if ( 'yes' !== $s['enabled'] || ! is_email( $email ) || ! WC()->cart || WC()->cart->is_empty() || bk_ac_is_opted_out( $email ) ) {
		wp_send_json_success( array( 'saved' => false ) );
	}

	// Sin autorización el carrito se guarda igual (la tienda lo ve en Carritos), pero NUNCA recibe correos.
	$snap = bk_ac_cart_snapshot();
	$data = array(
		'email'      => $email,
		'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
		'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
		'cart'       => wp_json_encode( $snap['items'] ),
		'cart_total' => $snap['total'],
		'currency'   => get_woocommerce_currency(),
		'consent'    => $consent ? 1 : 0,
		'user_id'    => get_current_user_id(),
	);

	if ( $row && in_array( $row->status, array( 'active', 'emptied' ), true ) ) {
		$email_changed  = strtolower( $row->email ) !== strtolower( $email );
		$data['status'] = 'active';
		bk_ac_update( (int) $row->id, $data );
		if ( ! $consent ) {
			bk_ac_unschedule( (int) $row->id );
		} elseif ( $email_changed || 'emptied' === $row->status || ! (int) $row->consent ) {
			bk_ac_unschedule( (int) $row->id );
			bk_ac_schedule( (int) $row->id, (int) $row->emails_sent + 1 );
		}
		wp_send_json_success( array( 'saved' => true, 'consent' => $consent ) );
	}

	global $wpdb;
	$now   = bk_ac_now();
	$token = bin2hex( random_bytes( 16 ) ); // 32 caracteres hex: sobrevive a sanitize_key en los enlaces.
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		bk_ac_table(),
		array_merge(
			$data,
			array(
				'token'       => $token,
				'session_key' => (string) WC()->session->get_customer_id(),
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		)
	);
	$id = (int) $wpdb->insert_id;
	if ( $id ) {
		WC()->session->set( 'bk_ac_token', $token );
		WC()->session->set( 'bk_ac_hash', md5( $data['cart'] ) );
		if ( $consent ) {
			bk_ac_schedule( $id, 1 );
		}
	}
	wp_send_json_success( array( 'saved' => (bool) $id, 'consent' => $consent ) );
}

/**
 * Borra un carrito.
 *
 * @param int $id ID.
 */
function bk_ac_delete( $id ) {
	global $wpdb;
	$wpdb->delete( bk_ac_table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
}

/* ---------------------------------------------------------------------------
 * 3. El carrito cambia después de capturado
 * ------------------------------------------------------------------------- */

add_action( 'woocommerce_cart_updated', 'bk_ac_sync_cart' );

/**
 * Mantiene la copia al día. Solo escribe si el carrito realmente cambió (hash en sesión).
 */
function bk_ac_sync_cart() {
	if ( ! WC()->session || ! WC()->cart ) {
		return;
	}
	$token = (string) WC()->session->get( 'bk_ac_token' );
	if ( ! $token ) {
		return;
	}
	$snap = bk_ac_cart_snapshot();
	$json = wp_json_encode( $snap['items'] );
	if ( md5( $json ) === WC()->session->get( 'bk_ac_hash' ) ) {
		return;
	}
	$row = bk_ac_get( $token );
	if ( ! $row || ! in_array( $row->status, array( 'active', 'emptied' ), true ) ) {
		return;
	}
	WC()->session->set( 'bk_ac_hash', md5( $json ) );

	if ( empty( $snap['items'] ) ) {
		// Vació el carrito a propósito: no se le escribe.
		bk_ac_unschedule( (int) $row->id );
		bk_ac_update( (int) $row->id, array( 'status' => 'emptied', 'cart' => '[]', 'cart_total' => 0 ) );
		return;
	}
	bk_ac_update(
		(int) $row->id,
		array(
			'cart'       => $json,
			'cart_total' => $snap['total'],
			'status'     => 'active',
		)
	);
	if ( 'emptied' === $row->status ) {
		bk_ac_schedule( (int) $row->id, (int) $row->emails_sent + 1 );
	}
}

/* ---------------------------------------------------------------------------
 * 4. Compra: se detienen los correos
 * ------------------------------------------------------------------------- */

add_action( 'woocommerce_checkout_order_created', 'bk_ac_order_created' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'bk_ac_order_created' );

/**
 * Marca el carrito como comprado (o recuperado si vino del correo).
 *
 * @param WC_Order $order Pedido.
 */
function bk_ac_order_created( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$token = WC()->session ? (string) WC()->session->get( 'bk_ac_token' ) : '';
	$row   = $token ? bk_ac_get( $token ) : null;
	if ( $row ) {
		bk_ac_mark_ordered( $row, $order );
		WC()->session->set( 'bk_ac_token', null );
		WC()->session->set( 'bk_ac_hash', null );
	}
	// Otros carritos activos del mismo correo (otro dispositivo) tampoco deben recibir recordatorios.
	global $wpdb;
	$table = bk_ac_table();
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND status = 'active'", $order->get_billing_email() ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $id ) {
		bk_ac_unschedule( (int) $id );
		bk_ac_update( (int) $id, array( 'status' => 'converted', 'order_id' => $order->get_id() ) );
	}
}

/**
 * Marca un carrito como comprado.
 *
 * @param object   $row   Carrito.
 * @param WC_Order $order Pedido.
 */
function bk_ac_mark_ordered( $row, $order ) {
	bk_ac_unschedule( (int) $row->id );
	$status = ( $row->clicked_at && (int) $row->emails_sent > 0 ) ? 'recovered' : 'converted';
	bk_ac_update( (int) $row->id, array( 'status' => $status, 'order_id' => $order->get_id() ) );
	$order->update_meta_data( '_bk_ac_id', (int) $row->id );
	if ( 'recovered' === $status ) {
		$order->update_meta_data( '_bk_ac_recovered', 'yes' );
		$order->add_order_note( sprintf( 'Pedido recuperado desde el recordatorio de carrito abandonado n.º %d.', (int) $row->emails_sent ) );
	}
	$order->save();
}

add_action( 'woocommerce_order_status_cancelled', 'bk_ac_order_failed' );
add_action( 'woocommerce_order_status_failed', 'bk_ac_order_failed' );

/**
 * El pago no se completó: se reactivan los recordatorios que faltaban.
 *
 * @param int $order_id Pedido.
 */
function bk_ac_order_failed( $order_id ) {
	$order = wc_get_order( $order_id );
	$id    = $order ? (int) $order->get_meta( '_bk_ac_id' ) : 0;
	$row   = $id ? bk_ac_get( $id ) : null;
	if ( ! $row || ! in_array( $row->status, array( 'converted', 'recovered' ), true ) || (int) $row->emails_sent >= 3 || bk_ac_is_opted_out( $row->email ) ) {
		return;
	}
	bk_ac_update( $id, array( 'status' => 'active', 'order_id' => 0 ) );
	bk_ac_schedule( $id, (int) $row->emails_sent + 1, true );
}

/* ---------------------------------------------------------------------------
 * 5. Enlaces del correo: recuperar carrito y darse de baja
 * ------------------------------------------------------------------------- */

add_action( 'wp_loaded', 'bk_ac_handle_links', 30 );

/**
 * Procesa ?bk_ac=TOKEN&k=FIRMA y ?bk_ac_unsub=TOKEN&k=FIRMA.
 */
function bk_ac_handle_links() {
	if ( is_admin() || wp_doing_ajax() || ! function_exists( 'WC' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- enlaces de correo protegidos con HMAC.
	if ( isset( $_GET['bk_ac_unsub'], $_GET['k'] ) ) {
		$token = sanitize_key( wp_unslash( $_GET['bk_ac_unsub'] ) );
		$row   = bk_ac_get( $token );
		if ( $row && hash_equals( bk_ac_sig( $token, 'unsub' ), sanitize_key( wp_unslash( $_GET['k'] ) ) ) ) {
			bk_ac_opt_out( $row->email );
			bk_ac_unschedule( (int) $row->id );
			bk_ac_update( (int) $row->id, array( 'status' => 'unsubscribed' ) );
			wp_safe_redirect( add_query_arg( 'bk_ac_bye', 1, home_url( '/' ) ) );
			exit;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	if ( ! isset( $_GET['bk_ac'], $_GET['k'] ) ) {
		return;
	}
	$token = sanitize_key( wp_unslash( $_GET['bk_ac'] ) );
	$row   = bk_ac_get( $token );
	$utm   = array_intersect_key( wp_unslash( $_GET ), array_flip( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content' ) ) );
	// phpcs:enable

	if ( ! $row || ! hash_equals( bk_ac_sig( $token, 'recover' ), sanitize_key( wp_unslash( $_GET['k'] ) ) ) ) {
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
	if ( in_array( $row->status, array( 'converted', 'recovered' ), true ) ) {
		wc_add_notice( __( 'Este carrito ya se convirtió en un pedido. ¡Gracias por tu compra!', 'buckler-core' ), 'notice' );
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	if ( WC()->session && ! WC()->session->has_session() ) {
		WC()->session->set_customer_session_cookie( true );
	}
	WC()->cart->empty_cart();
	$missing = array();
	foreach ( $row->items as $item ) {
		$pid     = (int) ( $item['variation_id'] ?: $item['product_id'] );
		$product = wc_get_product( $pid );
		$attrs   = array();
		foreach ( (array) $item['variation'] as $key => $v ) {
			$attrs[ $key ] = $v['raw'] ?? '';
		}
		$ok = $product && $product->is_purchasable() && $product->is_in_stock()
			&& WC()->cart->add_to_cart( (int) $item['product_id'], max( 1, (int) $item['quantity'] ), (int) $item['variation_id'], $attrs );
		if ( ! $ok ) {
			$missing[] = $item['name'];
		}
	}
	wc_clear_notices();
	if ( $missing ) {
		/* translators: %s: productos */
		wc_add_notice( sprintf( __( 'Ya no hay disponibilidad de: %s. El resto de tu carrito está listo.', 'buckler-core' ), implode( ', ', $missing ) ), 'notice' );
	}
	if ( $row->coupon_code && ! WC()->cart->has_discount( $row->coupon_code ) ) {
		WC()->cart->apply_coupon( $row->coupon_code );
	}
	if ( WC()->customer ) {
		WC()->customer->set_billing_email( $row->email );
		if ( $row->first_name ) {
			WC()->customer->set_billing_first_name( $row->first_name );
		}
		if ( $row->phone ) {
			WC()->customer->set_billing_phone( $row->phone );
		}
		WC()->customer->save();
	}
	WC()->session->set( 'bk_ac_token', $token );
	WC()->session->set( 'bk_ac_hash', md5( wp_json_encode( bk_ac_cart_snapshot()['items'] ) ) );
	bk_ac_update( (int) $row->id, array( 'clicked_at' => bk_ac_now(), 'status' => 'active' ) );

	$target = WC()->cart->is_empty() ? wc_get_page_permalink( 'shop' ) : wc_get_checkout_url();
	wp_safe_redirect( add_query_arg( array_map( 'sanitize_text_field', $utm ), $target ) );
	exit;
}

add_action( 'wp_footer', 'bk_ac_bye_toast' );

/**
 * Confirmación de baja: aviso propio (la home de Divi no imprime los avisos de WooCommerce).
 */
function bk_ac_bye_toast() {
	if ( empty( $_GET['bk_ac_bye'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	printf(
		'<div class="bk-toast" role="status">%s</div>',
		esc_html__( 'Listo. No te enviaremos más recordatorios de carrito.', 'buckler-core' )
	);
}
