<?php
/**
 * Cambiar las prendas de un pedido desde Gestión de tienda (pedido del cliente 2026-11-14): cambios de talla o de
 * prenda de una clienta. Se quitan, cambian o agregan prendas; por cada prenda que sale del pedido se pregunta si
 * **vuelve al inventario**. Las prendas nuevas se descuentan al guardar (si el pedido ya tenía su inventario
 * descontado). El total del pedido se recalcula; no se envía correo a la clienta.
 *
 * Inventario: WooCommerce marca en cada línea `_reduced_stock` cuántas unidades descontó. Solo esas unidades pueden
 * volver; al quitar o reducir una línea se ajusta la marca (así, si después se cancela el pedido, Woo devuelve solo lo
 * que sigue en el pedido) y las líneas nuevas quedan marcadas igual que las originales.
 *
 * Rutas: GET  bk/v1/gestion/orders/{id}/items  (prendas editables)
 *        POST bk/v1/gestion/orders/{id}/items  (lines[{item?, id, qty, price?}], restock{item_id: bool}, confirm)
 *        confirm=false → vista previa (total antes/después, prendas que salen y entran); confirm=true → aplica.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( 'bk/v1', '/gestion/orders/(?P<id>\d+)/items', array( 'methods' => 'GET', 'callback' => 'bk_goi_get', 'permission_callback' => 'bk_g_can' ) );
		register_rest_route( 'bk/v1', '/gestion/orders/(?P<id>\d+)/items', array( 'methods' => 'POST', 'callback' => 'bk_goi_save', 'permission_callback' => 'bk_g_can' ) );
	}
);

/**
 * Estados en los que se pueden cambiar las prendas.
 *
 * @param WC_Order $o Pedido.
 * @return bool
 */
function bk_goi_editable( $o ) {
	return ! in_array( $o->get_status(), (array) apply_filters( 'bk_goi_locked_statuses', array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ) ), true );
}

/**
 * ¿El pedido tiene su inventario descontado? (Woo lo marca en el pedido o en cada línea; el snippet de producción
 * solo marca las líneas.)
 *
 * @param WC_Order $o Pedido.
 * @return bool
 */
function bk_goi_holds_stock( $o ) {
	if ( $o->get_data_store()->get_stock_reduced( $o->get_id() ) ) {
		return true;
	}
	foreach ( $o->get_items() as $item ) {
		if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Pedido válido para cambiar prendas.
 *
 * @param int $id Pedido.
 * @return WC_Order|WP_Error
 */
function bk_goi_order( $id ) {
	$o = wc_get_order( (int) $id );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	if ( ! bk_goi_editable( $o ) ) {
		return new WP_Error( 'bk_goi', sprintf( 'Un pedido en estado "%s" no se puede cambiar.', wc_get_order_status_name( $o->get_status() ) ), array( 'status' => 400 ) );
	}
	return $o;
}

/**
 * Talla legible de una variación ("10") o "Única".
 *
 * @param WC_Product $v Producto.
 * @return string
 */
function bk_goi_size( $v ) {
	if ( ! $v || 'variation' !== $v->get_type() ) {
		return 'Única';
	}
	$s = wc_get_formatted_variation( $v, true, false, false );
	return $s ? $s : '#' . $v->get_id();
}

/**
 * Prendas del pedido para el editor.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_goi_get( WP_REST_Request $r ) {
	$o = bk_goi_order( $r['id'] );
	if ( is_wp_error( $o ) ) {
		return $o;
	}
	$lines = array();
	foreach ( $o->get_items() as $iid => $item ) {
		/** @var WC_Order_Item_Product $item */
		$v      = $item->get_product();
		$parent = $v && $v->get_parent_id() ? wc_get_product( $v->get_parent_id() ) : $v;
		$qty    = max( 1, (int) $item->get_quantity() );
		$img    = $v && $v->get_image_id() ? wp_get_attachment_image_url( $v->get_image_id(), 'thumbnail' ) : '';
		$lines[] = array(
			'item'    => (int) $iid,
			'id'      => $v ? $v->get_id() : 0,
			'name'    => $parent ? $parent->get_name() : $item->get_name(),
			'size'    => $v ? bk_goi_size( $v ) : '',
			'sku'     => $parent ? (string) $parent->get_sku() : '',
			'img'     => $img ? $img : '',
			'qty'     => $qty,
			'price'   => round( (float) $item->get_total() / $qty, 2 ),
			'stock'   => $v && function_exists( 'bk_gm_available' ) ? bk_gm_available( $v ) : null,
			'reduced' => (int) $item->get_meta( '_reduced_stock', true ),
		);
	}
	return rest_ensure_response(
		array(
			'id'       => $o->get_id(),
			'number'   => $o->get_order_number(),
			'status'   => wc_get_order_status_name( $o->get_status() ),
			'total'    => (float) $o->get_total(),
			'shipping' => (float) $o->get_shipping_total(),
			'discount' => (float) $o->get_discount_total(),
			'holds'    => bk_goi_holds_stock( $o ),
			'lines'    => $lines,
		)
	);
}

/**
 * Vista previa o aplicación del cambio.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_goi_save( WP_REST_Request $r ) {
	$o = bk_goi_order( $r['id'] );
	if ( is_wp_error( $o ) ) {
		return $o;
	}
	$err   = static fn( $msg ) => new WP_Error( 'bk_goi', $msg, array( 'status' => 400, 'field' => 'items' ) );
	$holds = bk_goi_holds_stock( $o );
	$items = $o->get_items();

	// Lo pedido: cantidades de las líneas que siguen (por item) y líneas nuevas (por variación).
	$keep = array();
	$add  = array();
	foreach ( (array) $r['lines'] as $l ) {
		$iid = absint( $l['item'] ?? 0 );
		$qty = absint( $l['qty'] ?? 0 );
		if ( $iid && isset( $items[ $iid ] ) ) {
			$keep[ $iid ] = $qty;
			continue;
		}
		$vid = absint( $l['id'] ?? 0 );
		if ( ! $vid || $qty < 1 ) {
			continue;
		}
		$price = isset( $l['price'] ) && '' !== (string) $l['price'] ? max( 0, (float) $l['price'] ) : null;
		if ( isset( $add[ $vid ] ) ) {
			$add[ $vid ]['qty'] += $qty;
		} else {
			$add[ $vid ] = array( 'qty' => $qty, 'price' => $price );
		}
	}

	// Plan por línea original.
	$plan = array(); // iid => [from, to, unit, back (unidades que salieron del inventario y pueden volver)]
	$need = array(); // vid => unidades a sacar del inventario (o a comprobar si el pedido no lo descontó)
	$left = 0;
	foreach ( $items as $iid => $item ) {
		$from = (int) $item->get_quantity();
		$to   = array_key_exists( $iid, $keep ) ? $keep[ $iid ] : 0;
		$vid  = (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
		$red  = (int) $item->get_meta( '_reduced_stock', true );
		$plan[ $iid ] = array(
			'from' => $from,
			'to'   => $to,
			'unit' => $from ? (float) $item->get_total() / $from : 0,
			'back' => $to < $from ? min( $from - $to, $red ) : 0,
			'vid'  => $vid,
		);
		if ( $to > $from ) {
			$need[ $vid ] = ( $need[ $vid ] ?? 0 ) + ( $to - $from );
		}
		$left += $to;
	}
	$products = array();
	foreach ( $add as $vid => $a ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! in_array( $v->get_type(), array( 'variation', 'simple' ), true ) ) {
			return $err( 'Una de las prendas nuevas ya no existe. Búscala de nuevo.' );
		}
		$products[ $vid ] = $v;
		$need[ $vid ]     = ( $need[ $vid ] ?? 0 ) + $a['qty'];
		$left            += $a['qty'];
	}
	if ( ! $left ) {
		return $err( 'El pedido debe quedar con al menos una prenda. Si la clienta no se lleva nada, cancela el pedido.' );
	}
	foreach ( $need as $vid => $n ) {
		$v     = $products[ $vid ] ?? wc_get_product( $vid );
		$avail = $v && function_exists( 'bk_gm_available' ) ? bk_gm_available( $v ) : null;
		// Si el pedido no había descontado inventario, las unidades que ya tenía tampoco están apartadas.
		if ( ! $holds ) {
			foreach ( $plan as $p ) {
				if ( $p['vid'] === $vid ) {
					$n += min( $p['from'], $p['to'] );
				}
			}
		}
		if ( null !== $avail && $avail < $n ) {
			$name = $v ? $v->get_name() : '#' . $vid;
			return $err( 0 === $avail ? sprintf( '%s está agotada.', $name ) : sprintf( 'De %1$s solo quedan %2$d.', $name, $avail ) );
		}
	}

	// Totales nuevos (líneas que siguen al mismo precio por unidad; nuevas al precio elegido o el de la tienda).
	$sub = 0.0;
	foreach ( $plan as $p ) {
		$sub += $p['unit'] * $p['to'];
	}
	foreach ( $add as $vid => $a ) {
		$sub += ( null !== $a['price'] ? $a['price'] : (float) $products[ $vid ]->get_price() ) * $a['qty'];
	}
	$old   = (float) $o->get_total();
	$new   = round( $sub + (float) $o->get_shipping_total() + (float) $o->get_shipping_tax() + (float) $o->get_total_fees(), wc_get_price_decimals() );
	$label = static function ( $v, $name = '' ) {
		$parent = $v && $v->get_parent_id() ? wc_get_product( $v->get_parent_id() ) : $v;
		return trim( ( $parent ? $parent->get_name() : $name ) . ' · talla ' . bk_goi_size( $v ) );
	};
	$out = array();
	$in  = array();
	foreach ( $plan as $iid => $p ) {
		if ( $p['to'] < $p['from'] ) {
			$out[] = array(
				'item'  => (int) $iid,
				'label' => $label( $items[ $iid ]->get_product(), $items[ $iid ]->get_name() ),
				'qty'   => $p['from'] - $p['to'],
				'back'  => $p['back'],
			);
		} elseif ( $p['to'] > $p['from'] ) {
			$in[] = array( 'label' => $label( $items[ $iid ]->get_product(), $items[ $iid ]->get_name() ), 'qty' => $p['to'] - $p['from'] );
		}
	}
	foreach ( $add as $vid => $a ) {
		$in[] = array( 'label' => $label( $products[ $vid ] ), 'qty' => $a['qty'] );
	}
	if ( ! $out && ! $in ) {
		return $err( 'No hay cambios en las prendas.' );
	}
	$preview = array(
		'old'       => $old,
		'new'       => $new,
		'diff'      => round( $new - $old, 2 ),
		'old_money' => bk_g_money( $old ),
		'new_money' => bk_g_money( $new ),
		'diff_money' => bk_g_money( abs( $new - $old ) ),
		'out'       => $out,
		'in'        => $in,
		'holds'     => $holds,
		'paid'      => $o->is_paid(),
	);
	if ( ! rest_sanitize_boolean( $r['confirm'] ) ) {
		return rest_ensure_response( $preview );
	}

	// Aplicar.
	$restock = (array) $r['restock'];
	$user    = wp_get_current_user();
	$notes   = array();
	$GLOBALS['bk_g_via'] = 'gestion';
	foreach ( $plan as $iid => $p ) {
		/** @var WC_Order_Item_Product $item */
		$item = $items[ $iid ];
		$v    = $item->get_product();
		$name = $label( $v, $item->get_name() );
		$red  = (int) $item->get_meta( '_reduced_stock', true );
		if ( $p['to'] < $p['from'] ) {
			$gone = $p['from'] - $p['to'];
			$back = ! empty( $restock[ $iid ] ) && rest_sanitize_boolean( $restock[ $iid ] ) ? $p['back'] : 0;
			if ( $back && $v && $v->managing_stock() ) {
				$stock = wc_update_product_stock( $v, $back, 'increase' );
				$o->add_order_note( sprintf( 'Inventario: %1$s %2$s→%3$s (devuelta por cambio).', $name, $stock - $back, $stock ), false, false );
			}
			$notes[] = sprintf( '%1$d × %2$s sale del pedido (%3$s)', $gone, $name, $back ? 'vuelve al inventario' : ( $p['back'] ? 'no vuelve al inventario' : 'no se había descontado' ) );
			if ( 0 === $p['to'] ) {
				$o->remove_item( $iid );
				continue;
			}
			if ( $red ) {
				$item->update_meta_data( '_reduced_stock', max( 0, $red - $p['back'] ) );
			}
		} elseif ( $p['to'] > $p['from'] ) {
			$extra = $p['to'] - $p['from'];
			if ( $holds && $v && $v->managing_stock() ) {
				$stock = wc_update_product_stock( $v, $extra, 'decrease' );
				$item->update_meta_data( '_reduced_stock', $red + $extra );
				$o->add_order_note( sprintf( 'Inventario: %1$s %2$s→%3$s (cambio).', $name, $stock + $extra, $stock ), false, false );
			}
			$notes[] = sprintf( '+%1$d × %2$s', $extra, $name );
		} else {
			continue;
		}
		$item->set_quantity( $p['to'] );
		$item->set_subtotal( $p['unit'] * $p['to'] );
		$item->set_total( $p['unit'] * $p['to'] );
		$item->save();
	}
	foreach ( $add as $vid => $a ) {
		$v    = $products[ $vid ];
		$args = null !== $a['price'] ? array( 'subtotal' => $a['price'] * $a['qty'], 'total' => $a['price'] * $a['qty'] ) : array();
		$iid  = $o->add_product( $v, $a['qty'], $args );
		$name = $label( $v );
		if ( $holds && $iid && $v->managing_stock() ) {
			$stock = wc_update_product_stock( $v, $a['qty'], 'decrease' );
			wc_update_order_item_meta( $iid, '_reduced_stock', $a['qty'] );
			$o->add_order_note( sprintf( 'Inventario: %1$s %2$s→%3$s (cambio).', $name, $stock + $a['qty'], $stock ), false, false );
		}
		$notes[] = sprintf( '%1$d × %2$s entra al pedido', $a['qty'], $name );
	}
	$o->save(); // Aplica las líneas quitadas (remove_item queda pendiente hasta guardar).
	$o = wc_get_order( $o->get_id() ); // Líneas nuevas leídas de la BD antes de recalcular.
	$o->calculate_totals( false );
	$total = (float) $o->get_total();
	$o->add_order_note(
		sprintf(
			'Cambio de prendas desde Gestión de tienda por %1$s: %2$s. Total: %3$s → %4$s.',
			$user->display_name,
			implode( '; ', $notes ),
			wp_strip_all_tags( bk_g_money( $old ) ),
			wp_strip_all_tags( bk_g_money( $total ) )
		),
		false,
		true
	);
	if ( function_exists( 'bk_g_log' ) ) {
		bk_g_log( $o, 'prendas', implode( '; ', $notes ) . ( abs( $total - $old ) >= 0.01 ? sprintf( ' · total %1$s → %2$s', wp_strip_all_tags( bk_g_money( $old ) ), wp_strip_all_tags( bk_g_money( $total ) ) ) : '' ) );
	}
	$o->save();
	delete_transient( 'bk_g_summary' );
	return rest_ensure_response( array_merge( $preview, array( 'new' => $total, 'new_money' => bk_g_money( $total ), 'done' => true ) ) );
}
