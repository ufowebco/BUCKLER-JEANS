<?php
/**
 * Gestión de tienda → Descuentos: crear, editar, pausar y borrar campañas y descuentos por cantidad.
 * El motor que aplica los precios está en includes/discounts.php (módulo `discounts`).
 *
 * Rutas (permiso bk_g_can):
 *   GET  gestion/discounts                 reglas con su estado, categorías y si el módulo está encendido
 *   POST gestion/discounts                 { type: campaign|qty, rule } crea o guarda
 *   POST gestion/discounts/delete          { type, id }
 *   GET  gestion/discounts/products?q=     buscar productos (destacados de la campaña / alcance por producto)
 *
 * Fechas: "AAAA-MM-DDTHH:MM" en la hora de la tienda (America/Bogota); se guardan también como timestamp.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_gd_routes' );

/**
 * Rutas.
 */
function bk_gd_routes() {
	$ns = 'bk/v1';
	register_rest_route(
		$ns,
		'/gestion/discounts',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_gd_list', 'permission_callback' => 'bk_g_can' ),
			array( 'methods' => 'POST', 'callback' => 'bk_gd_save', 'permission_callback' => 'bk_g_can' ),
		)
	);
	register_rest_route( $ns, '/gestion/discounts/module', array( 'methods' => 'POST', 'callback' => 'bk_gd_module', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( $ns, '/gestion/discounts/delete', array( 'methods' => 'POST', 'callback' => 'bk_gd_delete', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( $ns, '/gestion/discounts/products', array( 'methods' => 'GET', 'callback' => 'bk_gd_products', 'permission_callback' => 'bk_g_can' ) );
}

/**
 * Producto para el panel.
 *
 * @param int $id Producto.
 * @return array|null
 */
function bk_gd_product( $id ) {
	$p = wc_get_product( $id );
	return $p ? array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'image' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ), 'sale' => bk_disc_is_sale( $p ) ) : null;
}

/**
 * Regla para el panel (con estado y fechas legibles).
 *
 * @param array $r Regla.
 * @return array
 */
function bk_gd_view( $r ) {
	$r['state']      = bk_disc_state( $r );
	$r['start_text'] = wp_date( 'j M Y, g:i a', (int) $r['start_ts'] );
	$r['end_text']   = wp_date( 'j M Y, g:i a', (int) $r['end_ts'] );
	foreach ( array( 'featured', 'products' ) as $k ) {
		if ( ! empty( $r[ $k ] ) ) {
			$r[ $k . '_info' ] = array_values( array_filter( array_map( 'bk_gd_product', (array) $r[ $k ] ) ) );
		}
	}
	if ( ! empty( $r['tiers'] ) ) {
		$r['tier_labels'] = bk_disc_tier_labels( $r );
	}
	return $r;
}

/**
 * Lista.
 *
 * @return WP_REST_Response
 */
function bk_gd_list() {
	$d    = bk_disc_data( true );
	$sale = get_term_by( 'slug', (string) bk_config( 'sale_cat' ), 'product_cat' );
	return rest_ensure_response(
		array(
			'module'    => bk_on( 'discounts' ) || in_array( 'discounts', (array) get_option( 'bk_modules', array() ), true ), // bk_on() guarda en memoria el valor del inicio de la petición.
			'campaigns' => array_map( 'bk_gd_view', $d['campaigns'] ),
			'qty'       => array_map( 'bk_gd_view', $d['qty'] ),
			'cats'      => function_exists( 'bk_gp_cat_tree' ) ? bk_gp_cat_tree() : array(),
			'saleCat'   => $sale ? bk_nice_label( $sale->name ) : '',
			'now'       => wp_date( 'Y-m-d\TH:i' ),
			'tz'        => wp_timezone_string(),
			'yaypricing' => bk_plugin_folder_active( 'yaypricing-pro' ) || bk_plugin_folder_active( 'yaypricing' ),
		)
	);
}

/**
 * Fecha local "AAAA-MM-DDTHH:MM" → timestamp.
 *
 * @param string $s Fecha.
 * @return int
 */
function bk_gd_ts( $s ) {
	$s = trim( (string) $s );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $s ) ) {
		return 0;
	}
	$dt = date_create_immutable( strlen( $s ) === 10 ? $s . 'T00:00' : $s, wp_timezone() );
	return $dt ? $dt->getTimestamp() : 0;
}

/**
 * Error con el campo que lo causa.
 *
 * @param string $field Campo.
 * @param string $msg   Mensaje.
 * @return WP_Error
 */
function bk_gd_err( $field, $msg ) {
	return new WP_Error( 'bk_invalid', $msg, array( 'status' => 400, 'field' => $field ) );
}

/**
 * Crea o guarda una regla.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gd_save( WP_REST_Request $r ) {
	$type = 'qty' === $r['type'] ? 'qty' : 'campaigns';
	$in   = is_array( $r['rule'] ) ? $r['rule'] : array();
	$name = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
	if ( mb_strlen( $name ) < 3 ) {
		return bk_gd_err( 'name', 'Escribe un nombre para el descuento (ej.: Black Friday).' );
	}
	$start = bk_gd_ts( $in['start'] ?? '' );
	$end   = bk_gd_ts( $in['end'] ?? '' );
	if ( ! $start ) {
		return bk_gd_err( 'start', 'Elige la fecha de inicio.' );
	}
	if ( ! $end || $end <= $start ) {
		return bk_gd_err( 'end', 'La fecha de fin debe ser posterior al inicio.' );
	}
	$scope = in_array( $in['scope'] ?? 'all', array( 'all', 'cats', 'products' ), true ) ? $in['scope'] : 'all';
	$cats  = array_values( array_filter( array_map( 'absint', (array) ( $in['cats'] ?? array() ) ), static fn( $c ) => $c && term_exists( $c, 'product_cat' ) ) );
	$prods = array_values( array_filter( array_map( 'absint', (array) ( $in['products'] ?? array() ) ), static fn( $p ) => $p && wc_get_product( $p ) ) );
	if ( 'cats' === $scope && ! $cats ) {
		return bk_gd_err( 'cats', 'Elige al menos una categoría.' );
	}
	if ( 'products' === $scope && ! $prods ) {
		return bk_gd_err( 'products', 'Elige al menos un producto.' );
	}
	$rule = array(
		'id'           => preg_match( '/^[a-z0-9]{6,20}$/', (string) ( $in['id'] ?? '' ) ) ? $in['id'] : strtolower( wp_generate_password( 10, false ) ),
		'name'         => $name,
		'active'       => ! isset( $in['active'] ) || rest_sanitize_boolean( $in['active'] ),
		'start'        => wp_date( 'Y-m-d\TH:i', $start ),
		'end'          => wp_date( 'Y-m-d\TH:i', $end ),
		'start_ts'     => $start,
		'end_ts'       => $end,
		'scope'        => $scope,
		'cats'         => 'cats' === $scope ? $cats : array(),
		'products'     => 'products' === $scope ? $prods : array(),
		'exclude_sale' => ! isset( $in['exclude_sale'] ) || rest_sanitize_boolean( $in['exclude_sale'] ),
		'updated'      => time(),
		'updated_by'   => wp_get_current_user()->display_name,
	);
	if ( 'campaigns' === $type ) {
		$pct = round( (float) str_replace( ',', '.', (string) ( $in['pct'] ?? 0 ) ), 1 );
		if ( $pct < 1 || $pct > 90 ) {
			return bk_gd_err( 'pct', 'El porcentaje debe estar entre 1 y 90.' );
		}
		$rule['pct']      = $pct;
		$rule['badge']    = mb_substr( trim( sanitize_text_field( (string) ( $in['badge'] ?? '' ) ) ), 0, 18 );
		$rule['bar']      = ! isset( $in['bar'] ) || rest_sanitize_boolean( $in['bar'] );
		$rule['home']     = ! isset( $in['home'] ) || rest_sanitize_boolean( $in['home'] );
		$rule['featured'] = array_slice( array_values( array_filter( array_map( 'absint', (array) ( $in['featured'] ?? array() ) ), static fn( $p ) => $p && wc_get_product( $p ) ) ), 0, 12 );
	} else {
		$tiers = array();
		foreach ( array_slice( (array) ( $in['tiers'] ?? array() ), 0, 6 ) as $t ) {
			$min = absint( $t['min'] ?? 0 );
			$pct = round( (float) str_replace( ',', '.', (string) ( $t['pct'] ?? 0 ) ), 1 );
			if ( ! $min && ! $pct ) {
				continue;
			}
			if ( $min < 1 || $pct < 1 || $pct > 90 ) {
				return bk_gd_err( 'tiers', 'Cada tramo necesita "desde cuántas prendas" (1 o más) y un porcentaje entre 1 y 90.' );
			}
			$tiers[ $min ] = array( 'min' => $min, 'pct' => $pct );
		}
		ksort( $tiers );
		if ( ! $tiers ) {
			return bk_gd_err( 'tiers', 'Agrega al menos un tramo (ej.: desde 2 prendas, 20 %).' );
		}
		$prev = 0;
		foreach ( $tiers as $t ) {
			if ( $t['pct'] <= $prev ) {
				return bk_gd_err( 'tiers', 'Cada tramo debe dar más descuento que el anterior.' );
			}
			$prev = $t['pct'];
		}
		$rule['tiers'] = array_values( $tiers );
		$rule['count'] = 'product' === ( $in['count'] ?? 'cart' ) ? 'product' : 'cart'; // Por defecto se combinan productos.
	}

	$all  = bk_disc_data( true );
	$list = $all[ $type ];
	$done = false;
	foreach ( $list as $i => $x ) {
		if ( $x['id'] === $rule['id'] ) {
			$list[ $i ] = $rule;
			$done       = true;
		}
	}
	if ( ! $done ) {
		$list[] = $rule;
	}
	$all[ $type ] = array_values( $list );
	update_option( BK_DISC_OPTION, $all, true );
	if ( $rule['active'] ) {
		bk_gd_enable_module(); // Quien crea un descuento activo quiere verlo en la tienda.
	}
	bk_gd_after_change();
	$res = bk_gd_list();
	$res->data['saved'] = $rule['id'];
	return $res;
}

/**
 * Borra una regla.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gd_delete( WP_REST_Request $r ) {
	$type         = 'qty' === $r['type'] ? 'qty' : 'campaigns';
	$all          = bk_disc_data( true );
	$all[ $type ] = array_values( array_filter( $all[ $type ], static fn( $x ) => $x['id'] !== (string) $r['id'] ) );
	update_option( BK_DISC_OPTION, $all, true );
	bk_gd_after_change();
	return bk_gd_list();
}

/**
 * Programa los cambios de fecha y aplica ya los precios (catálogo y caché de páginas).
 */
function bk_gd_after_change() {
	bk_disc_data( true );
	bk_disc_schedule();
	bk_disc_flush();
}

/**
 * Enciende el módulo "Descuentos" (si estaba apagado los precios no cambian en la tienda).
 *
 * @return bool true si se encendió ahora.
 */
function bk_gd_enable_module() {
	if ( bk_on( 'discounts' ) || in_array( 'discounts', (array) get_option( 'bk_modules', array() ), true ) ) {
		return false;
	}
	$on   = (array) get_option( 'bk_modules', array() );
	$on[] = 'discounts';
	$on   = array_values( array_unique( array_intersect( array_keys( bk_modules() ), $on ) ) );
	update_option( 'bk_modules', $on );
	bk_g_log_module( 'discounts' );
	do_action( 'bk_modules_saved', $on ); // Mismos efectos que guardar en Ajustes → Buckler Core (purga la caché).
	return true;
}

/**
 * Deja constancia de quién encendió el módulo desde la gestión.
 *
 * @param string $key Módulo.
 */
function bk_g_log_module( $key ) {
	update_option( 'bk_module_enabled_by_' . $key, array( 'by' => wp_get_current_user()->display_name, 't' => time() ), false );
}

/**
 * POST gestion/discounts/module: botón "Encender descuentos".
 *
 * @return WP_REST_Response
 */
function bk_gd_module() {
	bk_gd_enable_module();
	bk_gd_after_change();
	return rest_ensure_response( array( 'module' => true ) );
}

/**
 * Buscar productos.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gd_products( WP_REST_Request $r ) {
	$q   = trim( sanitize_text_field( (string) $r['q'] ) );
	$ids = array();
	if ( '' !== $q ) {
		$ids = array_merge(
			wc_get_products( array( 'sku' => $q, 'limit' => 10, 'return' => 'ids', 'status' => 'publish' ) ),
			wc_get_products( array( 's' => $q, 'limit' => 20, 'return' => 'ids', 'status' => 'publish' ) )
		);
	}
	return rest_ensure_response( array_values( array_filter( array_map( 'bk_gd_product', array_slice( array_unique( $ids ), 0, 20 ) ) ) ) );
}
