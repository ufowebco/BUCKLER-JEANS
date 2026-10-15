<?php
/**
 * Gestión de tienda: historial del pedido, archivos del pedido (repeater ACF) e impresión.
 *
 * Historial: meta `_bk_history` (lista de eventos) que se llena desde CUALQUIER origen
 * (Gestión de tienda, wp-admin, pasarelas, tareas automáticas) con `woocommerce_order_status_changed`,
 * más los checks (impreso, despachado, pago confirmado), la guía y los archivos.
 * Pedidos anteriores a esta versión: el historial se reconstruye con las notas de WooCommerce
 * ("Estado del pedido cambiado de X a Y").
 *
 * Archivos: repeater ACF `archivos_adjuntos` (bk_g_fields()['adjuntos']) con el mismo formato de ACF
 * (`{nombre}` = filas, `{nombre}_{i}_{subcampo}` = valor, `_…` = clave del campo), así el pedido
 * se sigue viendo igual en wp-admin.
 *
 * Impresión: /gestion-tienda/?imprimir=ID (o varios separados por coma).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_G_HISTORY = '_bk_history';

add_action( 'woocommerce_order_status_changed', 'bk_g_history_status', 20, 4 );
add_action( 'rest_api_init', 'bk_g_files_routes' );
add_filter(
	'rest_pre_dispatch',
	static function ( $res, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/bk/v1/gestion' ) ) {
			$GLOBALS['bk_g_via'] = 'gestion';
		}
		return $res;
	},
	10,
	3
);

/* ---------------------------------------------------------------------------
 * Historial
 * ------------------------------------------------------------------------- */

/**
 * Quién y desde dónde se hace un cambio.
 *
 * @param WC_Order|null $o Pedido.
 * @return array{by:string,via:string}
 */
function bk_g_actor( $o = null ) {
	$u   = wp_get_current_user();
	$by  = $u->exists() ? $u->display_name : '';
	$via = 'sistema';
	if ( ! empty( $GLOBALS['bk_g_via'] ) ) {
		$via = 'gestion';
	} elseif ( wp_doing_cron() ) {
		$via = 'automatico';
	} elseif ( is_admin() && ! wp_doing_ajax() ) {
		$via = 'admin';
	} elseif ( $u->exists() && $o && (int) $o->get_customer_id() === (int) $u->ID ) {
		$via = 'clienta';
	} elseif ( $u->exists() && current_user_can( 'edit_shop_orders' ) ) {
		$via = 'admin';
	}
	return array( 'by' => $by, 'via' => $via );
}

/**
 * Agrega un evento al historial (no guarda: lo hace quien llama).
 *
 * @param WC_Order $o     Pedido.
 * @param string   $ev    status|impreso|despachado|pago|guia|archivo.
 * @param string   $value Valor nuevo.
 * @param array    $extra Datos extra (from, carrier…).
 */
function bk_g_log( $o, $ev, $value, $extra = array() ) {
	$log   = $o->get_meta( BK_G_HISTORY );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array_merge( array( 't' => time(), 'ev' => $ev, 'v' => (string) $value ), bk_g_actor( $o ), $extra );
	$o->update_meta_data( BK_G_HISTORY, array_slice( $log, -80 ) );
}

/**
 * Cambio de estado desde cualquier origen.
 *
 * @param int      $id   Pedido.
 * @param string   $from Estado anterior.
 * @param string   $to   Estado nuevo.
 * @param WC_Order $o    Pedido.
 */
function bk_g_history_status( $id, $from, $to, $o = null ) {
	$o = $o instanceof WC_Order ? $o : wc_get_order( $id );
	if ( ! $o || 'shop_order' !== $o->get_type() ) {
		return;
	}
	bk_g_log( $o, 'status', $to, array( 'from' => $from ) );
	$o->save_meta_data();
}

/**
 * Nombre de un estado ("processing" → "Procesando").
 *
 * @param string $s Estado.
 * @return string
 */
function bk_g_status_name( $s ) {
	return wc_get_order_status_name( $s );
}

/**
 * Línea de tiempo del pedido (más antigua primero).
 *
 * @param WC_Order $o Pedido.
 * @return array<int,array{t:int,date:string,type:string,label:string,by:string}>
 */
function bk_g_history( $o ) {
	$vias = array(
		'gestion'    => 'Gestión de tienda',
		'admin'      => 'wp-admin',
		'automatico' => 'Automático',
		'clienta'    => 'Clienta',
		'sistema'    => 'Pasarela / sistema',
		'notas'      => '',
	);
	$out  = array();
	$add  = static function ( $t, $type, $label, $by = '', $via = '' ) use ( &$out, $vias ) {
		$out[] = array(
			't'     => (int) $t,
			'date'  => wp_date( 'j M Y, g:i a', (int) $t ),
			'type'  => $type,
			'label' => $label,
			'by'    => trim( implode( ' · ', array_filter( array( $by, $vias[ $via ] ?? '' ) ) ) ),
		);
	};

	if ( $o->get_date_created() ) {
		$add( $o->get_date_created()->getTimestamp(), 'created', 'Pedido creado' . ( $o->get_payment_method_title() ? ' · ' . $o->get_payment_method_title() : '' ), '', 'admin' === $o->get_created_via() ? 'admin' : '' );
	}

	$log        = $o->get_meta( BK_G_HISTORY );
	$log        = is_array( $log ) ? $log : array();
	$has_status = false;
	$checks     = array(
		'impreso'    => array( 'Marcado como impreso', 'Desmarcado como impreso' ),
		'despachado' => array( 'Marcado como despachado', 'Desmarcado como despachado' ),
		'pago'       => array( 'Pago confirmado', 'Pago sin confirmar' ),
	);
	foreach ( $log as $e ) {
		$ev  = (string) ( $e['ev'] ?? '' );
		$val = (string) ( $e['v'] ?? '' );
		if ( 'status' === $ev ) {
			$has_status = true;
			$label      = ( ! empty( $e['from'] ) ? bk_g_status_name( $e['from'] ) . ' → ' : '' ) . bk_g_status_name( $val );
		} elseif ( isset( $checks[ $ev ] ) ) {
			$label = $checks[ $ev ][ 'yes' === $val ? 0 : 1 ];
		} elseif ( 'guia' === $ev ) {
			$label = 'Guía de envío enviada: ' . trim( ( $e['carrier'] ?? '' ) . ' ' . $val );
		} elseif ( 'archivo' === $ev ) {
			$label = $val;
		} else {
			continue;
		}
		$add( $e['t'] ?? 0, $ev, $label, (string) ( $e['by'] ?? '' ), (string) ( $e['via'] ?? '' ) );
	}

	// Pedidos de antes del historial: estados tomados de las notas de WooCommerce (español o inglés).
	if ( ! $has_status ) {
		$re = '/(?:Order status changed from|Estado del pedido cambiado de|El estado del pedido cambió de)\s+(.+?)\s+(?:to|a)\s+([^.]+)\./iu';
		foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id(), 'limit' => 100 ) ) as $n ) {
			if ( preg_match( $re, wp_strip_all_tags( $n->content ), $m ) && $n->date_created ) {
				$add( $n->date_created->getTimestamp(), 'status', trim( $m[1] ) . ' → ' . trim( $m[2] ), 'system' === $n->added_by ? '' : (string) $n->added_by, 'notas' );
			}
		}
	}
	if ( $o->get_date_paid() ) {
		$add( $o->get_date_paid()->getTimestamp(), 'paid', 'Pago registrado por la pasarela' );
	}
	if ( $o->get_date_completed() && ! $has_status ) {
		$add( $o->get_date_completed()->getTimestamp(), 'status', 'Completado' );
	}
	usort( $out, static fn( $a, $b ) => $a['t'] <=> $b['t'] );
	return $out;
}

/* ---------------------------------------------------------------------------
 * Archivos del pedido (repeater ACF)
 * ------------------------------------------------------------------------- */

/**
 * Rutas de archivos.
 */
function bk_g_files_routes() {
	register_rest_route( 'bk/v1', '/gestion/orders/(?P<id>\d+)/files', array( 'methods' => 'POST', 'callback' => 'bk_g_files_upload', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( 'bk/v1', '/gestion/orders/(?P<id>\d+)/files/delete', array( 'methods' => 'POST', 'callback' => 'bk_g_files_delete', 'permission_callback' => 'bk_g_can' ) );
}

/**
 * Definición del repeater: nombre, etiqueta y subcampos (de ACF si está activo).
 *
 * @return array|null
 */
function bk_g_files_def() {
	$name = trim( (string) bk_g_fields()['adjuntos'] );
	// Ajustes guardados vacíos o con un nombre que no existe en ACF: se usa el campo real de la tienda.
	if ( '' === $name || ( function_exists( 'acf_get_field' ) && ! acf_get_field( $name ) && acf_get_field( 'archivos_adjuntos' ) ) ) {
		$name = 'archivos_adjuntos';
	}
	$def = array( 'name' => $name, 'key' => '', 'label' => 'Archivos del pedido', 'subs' => array(), 'file_sub' => '', 'file_key' => '' );
	if ( function_exists( 'acf_get_field' ) ) {
		$fd = acf_get_field( $name );
		if ( is_array( $fd ) && ! empty( $fd['sub_fields'] ) ) {
			$def['key']   = (string) $fd['key'];
			$def['label'] = (string) ( $fd['label'] ? $fd['label'] : $def['label'] );
			foreach ( $fd['sub_fields'] as $sf ) {
				$def['subs'][ $sf['name'] ] = array( 'label' => $sf['label'], 'type' => $sf['type'], 'key' => $sf['key'] );
				if ( ! $def['file_sub'] && in_array( $sf['type'], array( 'file', 'image' ), true ) ) {
					$def['file_sub'] = $sf['name'];
					$def['file_key'] = $sf['key'];
				}
			}
		}
	}
	return $def;
}

/**
 * Filas del repeater: [ índice => [ subcampo => valor ] ].
 *
 * @param WC_Order $o   Pedido.
 * @param array    $def Definición.
 * @return array
 */
function bk_g_files_rows( $o, $def ) {
	$name = $def['name'];
	$meta = array();
	foreach ( $o->get_meta_data() as $m ) {
		$d                           = $m->get_data();
		$meta[ (string) $d['key'] ] = $d['value'];
	}
	// Respaldo: pedidos guardados antes de HPOS (meta en la tabla de posts).
	if ( ! isset( $meta[ $name ] ) ) {
		foreach ( (array) get_post_meta( $o->get_id() ) as $k => $v ) {
			if ( 0 === strpos( (string) $k, $name ) ) {
				$meta[ $k ] = maybe_unserialize( $v[0] ?? '' );
			}
		}
	}
	$rows = array();
	foreach ( $meta as $k => $v ) {
		if ( preg_match( '/^' . preg_quote( $name, '/' ) . '_(\d+)_(.+)$/', $k, $m ) ) {
			$rows[ (int) $m[1] ][ $m[2] ] = $v;
		}
	}
	// Respaldo: preguntarle a ACF (sabe dónde guardó: tabla de pedidos HPOS "woo_order_ID" o la de posts).
	if ( ! $rows && function_exists( 'get_field' ) ) {
		$by_key = array();
		foreach ( $def['subs'] as $sub => $sd ) {
			$by_key[ $sd['key'] ] = $sub;
		}
		foreach ( array( 'woo_order_' . $o->get_id(), $o->get_id() ) as $pid ) {
			$val = get_field( $name, $pid, false );
			if ( ! is_array( $val ) || ! $val ) {
				continue;
			}
			foreach ( array_values( $val ) as $i => $row ) {
				foreach ( (array) $row as $k => $v ) {
					$rows[ $i ][ $by_key[ $k ] ?? $k ] = $v;
				}
			}
			break;
		}
	}
	ksort( $rows );
	return $rows;
}

/**
 * Un archivo de la Mediateca (o URL) listo para mostrar.
 *
 * @param mixed $v ID o URL.
 * @return array|null
 */
function bk_g_file_item( $v ) {
	if ( is_numeric( $v ) && (int) $v > 0 ) {
		$id  = (int) $v;
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return null;
		}
		$img = wp_attachment_is_image( $id );
		return array(
			'id'    => $id,
			'url'   => $url,
			'thumb' => $img ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '',
			'name'  => basename( (string) get_attached_file( $id ) ),
			'pdf'   => 'application/pdf' === get_post_mime_type( $id ),
		);
	}
	if ( is_string( $v ) && preg_match( '#^https?://#i', $v ) ) {
		$is_img = (bool) preg_match( '/\.(jpe?g|png|webp|gif)(\?|$)/i', $v );
		return array( 'id' => 0, 'url' => esc_url_raw( $v ), 'thumb' => $is_img ? esc_url_raw( $v ) : '', 'name' => basename( (string) wp_parse_url( $v, PHP_URL_PATH ) ), 'pdf' => (bool) preg_match( '/\.pdf(\?|$)/i', $v ) );
	}
	return null;
}

/**
 * Archivos del pedido para el panel: filas con archivos y textos (todos los subcampos).
 *
 * @param WC_Order $o Pedido.
 * @return array|null
 */
function bk_g_files( $o ) {
	$def = bk_g_files_def();
	if ( ! $def ) {
		return null;
	}
	$rows = array();
	$i    = 0;
	foreach ( bk_g_files_rows( $o, $def ) as $index => $row ) {
		$files = array();
		$texts = array();
		foreach ( $row as $sub => $v ) {
			$label = $def['subs'][ $sub ]['label'] ?? ucfirst( str_replace( array( '_', '-' ), ' ', $sub ) );
			$vals  = is_array( $v ) ? $v : array( $v );
			$found = false;
			foreach ( $vals as $one ) {
				$item = bk_g_file_item( $one );
				if ( $item ) {
					$files[] = $item;
					$found   = true;
				}
			}
			if ( ! $found && is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$texts[] = array( 'label' => $label, 'value' => mb_substr( wp_strip_all_tags( (string) $v ), 0, 300 ) );
			}
		}
		if ( $files || $texts ) {
			$rows[] = array( 'index' => (int) $index, 'n' => ++$i, 'files' => $files, 'texts' => $texts );
		}
	}
	return array(
		'label' => $def['label'],
		'rows'  => $rows,
		'max'   => size_format( bk_g_files_max() ),
	);
}

/**
 * Tamaño máximo de un archivo del pedido.
 *
 * @return int
 */
function bk_g_files_max() {
	return (int) min( 15 * MB_IN_BYTES, wp_max_upload_size() );
}

/**
 * Reescribe el repeater con filas consecutivas (formato ACF).
 *
 * @param WC_Order $o    Pedido.
 * @param array    $def  Definición.
 * @param array    $old  Filas anteriores (para borrar sus claves).
 * @param array    $rows Filas nuevas (lista).
 */
function bk_g_files_write( $o, $def, $old, $rows ) {
	$name = $def['name'];
	foreach ( $old as $index => $row ) {
		foreach ( array_keys( $row ) as $sub ) {
			$o->delete_meta_data( "{$name}_{$index}_{$sub}" );
			$o->delete_meta_data( "_{$name}_{$index}_{$sub}" );
		}
	}
	foreach ( array_values( $rows ) as $i => $row ) {
		foreach ( $row as $sub => $v ) {
			$o->update_meta_data( "{$name}_{$i}_{$sub}", $v );
			$key = $def['subs'][ $sub ]['key'] ?? '';
			if ( $key ) {
				$o->update_meta_data( "_{$name}_{$i}_{$sub}", $key );
			}
		}
	}
	$o->update_meta_data( $name, count( $rows ) );
	if ( $def['key'] ) {
		$o->update_meta_data( '_' . $name, $def['key'] );
	}
}

/**
 * Sube un archivo (foto o PDF) y lo agrega como fila nueva del repeater.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_files_upload( WP_REST_Request $r ) {
	$o   = wc_get_order( (int) $r['id'] );
	$def = bk_g_files_def();
	if ( ! $o || 'shop_order' !== $o->get_type() || ! $def ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$files = $r->get_file_params();
	if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
		return new WP_Error( 'bk_upload', 'No llegó el archivo. Si es muy pesado, redúcelo e inténtalo de nuevo.', array( 'status' => 400 ) );
	}
	$f     = $files['file'];
	$mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
	);
	$check = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], $mimes );
	if ( empty( $check['type'] ) ) {
		return new WP_Error( 'bk_upload', 'Formato no permitido. Usa PDF, JPG, PNG o WebP.', array( 'status' => 400 ) );
	}
	if ( (int) $f['size'] > bk_g_files_max() ) {
		return new WP_Error( 'bk_upload', sprintf( 'El archivo pesa más de %s.', size_format( bk_g_files_max() ) ), array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$_FILES = array( 'file' => $f ); // phpcs:ignore WordPress.Security.NonceVerification -- REST con nonce wp_rest.
	$id     = media_handle_upload( 'file', 0, array( 'post_title' => sprintf( 'Pedido %s – archivo', $o->get_order_number() ) ), array( 'test_form' => false, 'mimes' => $mimes ) );
	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'bk_upload', $id->get_error_message(), array( 'status' => 400 ) );
	}

	$old = bk_g_files_rows( $o, $def );
	$sub = $def['file_sub'];
	if ( ! $sub ) {
		// Sin ACF: el subcampo que ya usan los pedidos (el primero con un ID), o "archivo".
		foreach ( $old as $row ) {
			foreach ( $row as $k => $v ) {
				if ( ! $sub && is_numeric( $v ) ) {
					$sub = $k;
				}
			}
		}
		$sub = $sub ? $sub : 'archivo';
	}
	$rows   = array_values( $old );
	$rows[] = array( $sub => $id );
	bk_g_files_write( $o, $def, $old, $rows );
	bk_g_log( $o, 'archivo', 'Archivo agregado: ' . basename( (string) get_attached_file( $id ) ) );
	$o->save();
	return rest_ensure_response( bk_g_order( $r )->get_data() );
}

/**
 * Quita una fila del repeater (el archivo sigue en la Mediateca).
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_g_files_delete( WP_REST_Request $r ) {
	$o   = wc_get_order( (int) $r['id'] );
	$def = bk_g_files_def();
	if ( ! $o || 'shop_order' !== $o->get_type() || ! $def ) {
		return new WP_Error( 'bk_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}
	$old   = bk_g_files_rows( $o, $def );
	$index = (int) $r['index'];
	if ( ! isset( $old[ $index ] ) ) {
		return new WP_Error( 'bk_not_found', 'Ese archivo ya no está en el pedido.', array( 'status' => 404 ) );
	}
	$name = '';
	foreach ( $old[ $index ] as $v ) {
		$item = is_scalar( $v ) ? bk_g_file_item( $v ) : null;
		$name = $item ? $item['name'] : $name;
	}
	$rows = $old;
	unset( $rows[ $index ] );
	bk_g_files_write( $o, $def, $old, $rows );
	bk_g_log( $o, 'archivo', 'Archivo quitado' . ( $name ? ': ' . $name : '' ) );
	$o->save();
	return rest_ensure_response( bk_g_order( $r )->get_data() );
}

/* ---------------------------------------------------------------------------
 * Impresión
 * ------------------------------------------------------------------------- */

/**
 * URL de impresión de uno o varios pedidos.
 *
 * @param int|int[] $ids Pedidos.
 * @return string
 */
function bk_g_print_url( $ids ) {
	return add_query_arg( 'imprimir', implode( ',', array_map( 'intval', (array) $ids ) ), home_url( '/' . BK_G_SLUG . '/' ) );
}

/**
 * Hoja imprimible (A4 / carta) con toda la información del pedido.
 *
 * @param int[] $ids Pedidos.
 */
function bk_g_print_render( $ids ) {
	$f      = bk_g_fields();
	$orders = array();
	foreach ( array_slice( array_unique( array_map( 'absint', $ids ) ), 0, 50 ) as $id ) {
		$o = $id ? wc_get_order( $id ) : null;
		if ( $o && 'shop_order' === $o->get_type() ) {
			$orders[] = $o;
		}
	}
	if ( ! $orders ) {
		wp_die( esc_html__( 'Pedido no encontrado.', 'buckler-core' ), '', array( 'response' => 404 ) );
	}
	$yes  = static fn( $o, $key ) => $key && in_array( strtolower( (string) $o->get_meta( $key ) ), BK_G_TRUE, true );
	$user = wp_get_current_user()->display_name;
	$logo = (string) bk_config( 'logo_dark' );
	?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( 1 === count( $orders ) ? 'Pedido #' . $orders[0]->get_order_number() : count( $orders ) . ' pedidos' ); ?> · Buckler</title>
<style>
	@page{size:auto;margin:12mm}
	*{box-sizing:border-box}
	body{margin:0;font:13px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#111;background:#eef1f4}
	.bar{position:sticky;top:0;display:flex;gap:8px;justify-content:center;padding:10px;background:#1a3955}
	.bar button{font:inherit;font-weight:700;padding:8px 18px;border-radius:8px;border:0;background:#e4ec6a;color:#1a3955;cursor:pointer}
	.bar button.ghost{background:transparent;color:#fff;border:1px solid #fff}
	.sheet{max-width:820px;margin:16px auto;padding:28px 32px;background:#fff;border-radius:6px}
	.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:2px solid #1a3955;padding-bottom:12px}
	.head img{height:46px;width:auto}
	.head h1{margin:0;font-size:22px;color:#1a3955}
	.head .meta{text-align:right}
	.head .meta p{margin:2px 0}
	.chips{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}
	.chip{border:1px solid #c7ccd3;border-radius:99px;padding:2px 10px;font-size:12px}
	.chip.on{border-color:#1f7a4d;color:#1f7a4d;font-weight:700}
	.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:12px 0}
	.box{border:1px solid #dfe3e8;border-radius:6px;padding:10px 12px}
	.box h2{margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#1a3955}
	.box p{margin:0}
	table{width:100%;border-collapse:collapse;margin-top:8px}
	th,td{padding:7px 6px;border-bottom:1px solid #e3e6ea;text-align:left;vertical-align:top}
	th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#555}
	td.n,th.n{text-align:right;white-space:nowrap}
	td img{width:42px;height:56px;object-fit:cover;border-radius:3px}
	.totals{margin-left:auto;width:min(320px,100%);margin-top:8px}
	.totals div{display:flex;justify-content:space-between;padding:3px 0}
	.totals .t{font-size:16px;font-weight:800;border-top:2px solid #1a3955;margin-top:4px;padding-top:6px}
	.note{background:#fffbe6;border:1px solid #f1e3a1;border-radius:6px;padding:8px 10px;margin:10px 0}
	.check{display:flex;flex-wrap:wrap;gap:18px;margin-top:16px;font-size:12px}
	.check span::before{content:"";display:inline-block;width:12px;height:12px;border:1.5px solid #333;margin-right:6px;vertical-align:-2px}
	.foot{margin-top:16px;font-size:11px;color:#666;display:flex;justify-content:space-between;gap:12px}
	@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;padding:0;max-width:none;border-radius:0;page-break-after:always;break-after:page}.sheet:last-child{page-break-after:auto;break-after:auto}}
	@media (max-width:600px){.grid{grid-template-columns:1fr}.sheet{padding:18px}.head{flex-direction:column}.head .meta{text-align:left}}
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="print()"><?php esc_html_e( 'Imprimir', 'buckler-core' ); ?></button><button type="button" class="ghost" onclick="history.length>1?history.back():close()"><?php esc_html_e( 'Volver', 'buckler-core' ); ?></button></div>
	<?php
	foreach ( $orders as $o ) :
		$cedula = '';
		foreach ( $f['cedula'] as $key ) {
			$cedula = (string) $o->get_meta( $key );
			if ( '' !== $cedula ) {
				break;
			}
		}
		$cons     = $f['consecutivo'] ? (string) $o->get_meta( $f['consecutivo'] ) : '';
		$ship     = $o->get_formatted_shipping_address() ? $o->get_formatted_shipping_address() : $o->get_formatted_billing_address();
		$tracking = function_exists( 'bk_ship_get' ) ? bk_ship_get( $o ) : null;
		$coupons  = $o->get_coupon_codes();
		?>
	<article class="sheet">
		<header class="head">
			<div><?php if ( $logo ) : ?><img src="<?php echo esc_url( home_url( $logo ) ); ?>" alt="<?php echo esc_attr( bk_config( 'brand' ) ); ?>"><?php endif; ?>
				<p><?php echo esc_html( bk_config( 'company' ) . ' · ' . bk_config( 'city' ) ); ?></p></div>
			<div class="meta">
				<h1><?php echo esc_html( 'Pedido #' . $o->get_order_number() ); ?></h1>
				<?php if ( $cons ) : ?><p><?php echo esc_html( 'Consecutivo: ' . $cons ); ?></p><?php endif; ?>
				<p><?php echo esc_html( $o->get_date_created() ? $o->get_date_created()->date_i18n( 'j \d\e F \d\e Y, g:i a' ) : '' ); ?></p>
			</div>
		</header>
		<div class="chips">
			<span class="chip on"><?php echo esc_html( 'Estado: ' . wc_get_order_status_name( $o->get_status() ) ); ?></span>
			<span class="chip"><?php echo esc_html( 'Pago: ' . ( $o->get_payment_method_title() ? $o->get_payment_method_title() : '—' ) ); ?></span>
			<span class="chip <?php echo $yes( $o, $f['pago'] ) ? 'on' : ''; ?>"><?php echo esc_html( $yes( $o, $f['pago'] ) ? '✓ Pago confirmado' : 'Pago sin confirmar' ); ?></span>
			<?php if ( $o->get_transaction_id() ) : ?><span class="chip"><?php echo esc_html( 'Transacción: ' . $o->get_transaction_id() ); ?></span><?php endif; ?>
		</div>
		<div class="grid">
			<section class="box"><h2><?php esc_html_e( 'Clienta', 'buckler-core' ); ?></h2>
				<p><strong><?php echo esc_html( trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ); ?></strong></p>
				<?php if ( $cedula ) : ?><p><?php echo esc_html( 'Cédula: ' . $cedula ); ?></p><?php endif; ?>
				<p><?php echo esc_html( 'Celular: ' . $o->get_billing_phone() ); ?></p>
				<p><?php echo esc_html( $o->get_billing_email() ); ?></p>
			</section>
			<section class="box"><h2><?php esc_html_e( 'Envío', 'buckler-core' ); ?></h2>
				<p><?php echo wp_kses( (string) $ship, array( 'br' => array() ) ); ?></p>
				<?php if ( $o->get_shipping_method() ) : ?><p><?php echo esc_html( 'Método: ' . $o->get_shipping_method() ); ?></p><?php endif; ?>
				<?php if ( $tracking ) : ?><p><strong><?php echo esc_html( 'Guía: ' . ( bk_carriers()[ $tracking['carrier'] ]['name'] ?? $tracking['carrier'] ) . ' ' . $tracking['number'] ); ?></strong></p><?php endif; ?>
			</section>
		</div>
		<?php if ( $o->get_customer_note() ) : ?><p class="note"><strong><?php esc_html_e( 'Nota de la clienta:', 'buckler-core' ); ?></strong> <?php echo esc_html( $o->get_customer_note() ); ?></p><?php endif; ?>
		<table>
			<thead><tr><th></th><th><?php esc_html_e( 'Producto', 'buckler-core' ); ?></th><th><?php esc_html_e( 'REF', 'buckler-core' ); ?></th><th class="n"><?php esc_html_e( 'Cant.', 'buckler-core' ); ?></th><th class="n"><?php esc_html_e( 'Precio', 'buckler-core' ); ?></th><th class="n"><?php esc_html_e( 'Total', 'buckler-core' ); ?></th></tr></thead>
			<tbody>
			<?php
			foreach ( $o->get_items() as $item ) :
				/** @var WC_Order_Item_Product $item */
				$p     = $item->get_product();
				$qty   = max( 1, (int) $item->get_quantity() );
				$total = (float) $item->get_total() + (float) $item->get_total_tax();
				$img   = $p ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '';
				?>
				<tr>
					<td><?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt=""><?php endif; ?></td>
					<td><strong><?php echo esc_html( $item->get_name() ); ?></strong><br><?php echo esc_html( wp_strip_all_tags( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ', 'before' => '', 'after' => '' ) ) ) ); ?></td>
					<td><?php echo esc_html( $p ? $p->get_sku() : '' ); ?></td>
					<td class="n"><?php echo (int) $item->get_quantity(); ?></td>
					<td class="n"><?php echo esc_html( bk_g_money( $total / $qty ) ); ?></td>
					<td class="n"><?php echo esc_html( bk_g_money( $total ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="totals">
			<div><span><?php esc_html_e( 'Subtotal', 'buckler-core' ); ?></span><span><?php echo esc_html( bk_g_money( $o->get_subtotal() ) ); ?></span></div>
			<?php if ( (float) $o->get_discount_total() > 0 ) : ?><div><span><?php echo esc_html( 'Descuento' . ( $coupons ? ' (' . implode( ', ', $coupons ) . ')' : '' ) ); ?></span><span><?php echo esc_html( '−' . bk_g_money( $o->get_discount_total() ) ); ?></span></div><?php endif; ?>
			<div><span><?php esc_html_e( 'Envío', 'buckler-core' ); ?></span><span><?php echo esc_html( (float) $o->get_shipping_total() > 0 ? bk_g_money( $o->get_shipping_total() ) : 'Gratis' ); ?></span></div>
			<?php foreach ( $o->get_fees() as $fee ) : ?><div><span><?php echo esc_html( $fee->get_name() ); ?></span><span><?php echo esc_html( bk_g_money( $fee->get_total() ) ); ?></span></div><?php endforeach; ?>
			<div class="t"><span><?php esc_html_e( 'Total', 'buckler-core' ); ?></span><span><?php echo esc_html( bk_g_money( $o->get_total() ) ); ?></span></div>
		</div>
		<div class="check"><span><?php esc_html_e( 'Prendas completas', 'buckler-core' ); ?></span><span><?php esc_html_e( 'Tallas revisadas', 'buckler-core' ); ?></span><span><?php esc_html_e( 'Empacado', 'buckler-core' ); ?></span><span><?php esc_html_e( 'Guía pegada', 'buckler-core' ); ?></span></div>
		<footer class="foot"><span><?php echo esc_html( sprintf( 'Impreso el %s por %s', wp_date( 'j M Y, g:i a' ), $user ) ); ?></span><span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></span></footer>
	</article>
	<?php endforeach; ?>
<?php if ( ! empty( $_GET['auto'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
<script>addEventListener('load', () => setTimeout(() => print(), 150));</script>
<?php endif; ?>
</body>
</html>
	<?php
	exit;
}
