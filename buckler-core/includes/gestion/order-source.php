<?php
/**
 * De dónde vino cada venta, a partir de la atribución nativa de WooCommerce (metas `_wc_order_attribution_*`, que
 * Woo guarda en cada pedido del checkout: tipo de fuente, utm_source/medium/campaign, página de entrada, referente,
 * dispositivo) + la recuperación de carrito abandonado de buckler-core (`_bk_ac_recovered`).
 *
 * Canales: Meta Ads, Meta orgánico (Facebook/Instagram), Google Ads, Google orgánico, otros buscadores, TikTok,
 * WhatsApp, correo de carrito/recordatorio, otros correos, directo, otros sitios, otras campañas, creado a mano y
 * "sin dato" (pedidos anteriores a la atribución o con bloqueadores).
 * Se usa en la lista de pedidos (etiqueta + filtro "Origen"), el detalle y el Resumen ("¿De dónde vienen las ventas?").
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_G_SRC_KEYS = array( '_wc_order_attribution_source_type', '_wc_order_attribution_utm_source', '_wc_order_attribution_utm_medium', '_wc_order_attribution_utm_campaign', '_wc_order_attribution_session_entry', '_wc_order_attribution_referrer', '_wc_order_attribution_device_type', '_bk_ac_recovered' );

/**
 * Canales (etiqueta, ícono).
 *
 * @return array<string,array{label:string,icon:string}>
 */
function bk_g_sources() {
	return array(
		'meta_ads'     => array( 'label' => 'Meta Ads', 'icon' => 'simple-icons:facebook' ),
		'meta'         => array( 'label' => 'Facebook / Instagram', 'icon' => 'simple-icons:instagram' ),
		'google_ads'   => array( 'label' => 'Google Ads', 'icon' => 'simple-icons:google' ),
		'google'       => array( 'label' => 'Google orgánico', 'icon' => 'simple-icons:google' ),
		'search'       => array( 'label' => 'Otros buscadores', 'icon' => 'lucide:search' ),
		'tiktok'       => array( 'label' => 'TikTok', 'icon' => 'simple-icons:tiktok' ),
		'whatsapp'     => array( 'label' => 'WhatsApp', 'icon' => 'simple-icons:whatsapp' ),
		'cart_email'   => array( 'label' => 'Correo de la tienda', 'icon' => 'lucide:shopping-cart' ),
		'email'        => array( 'label' => 'Otros correos', 'icon' => 'lucide:mail' ),
		'direct'       => array( 'label' => 'Directo', 'icon' => 'lucide:log-in' ),
		'referral'     => array( 'label' => 'Otros sitios', 'icon' => 'lucide:external-link' ),
		'campaign'     => array( 'label' => 'Otra campaña', 'icon' => 'lucide:send' ),
		'manual'       => array( 'label' => 'Creado a mano', 'icon' => 'lucide:store' ),
		'unknown'      => array( 'label' => 'Sin dato', 'icon' => 'lucide:circle-help' ),
	);
}

/**
 * Clasifica los datos de atribución en un canal.
 *
 * @param array $m Metas (claves de BK_G_SRC_KEYS).
 * @return string Clave del canal.
 */
function bk_g_source_classify( $m ) {
	$type  = strtolower( (string) ( $m['_wc_order_attribution_source_type'] ?? '' ) );
	$src   = strtolower( (string) ( $m['_wc_order_attribution_utm_source'] ?? '' ) );
	$med   = strtolower( (string) ( $m['_wc_order_attribution_utm_medium'] ?? '' ) );
	$entry = strtolower( (string) ( $m['_wc_order_attribution_session_entry'] ?? '' ) );
	$ref   = strtolower( (string) ( $m['_wc_order_attribution_referrer'] ?? '' ) );
	if ( ! empty( $m['_bk_ac_recovered'] ) || preg_match( '/carrito-abandonado|recordatorio-pago/', $med ) ) {
		return 'cart_email';
	}
	if ( in_array( $type, array( 'admin', 'mobile_app', 'pos' ), true ) ) {
		return 'manual';
	}
	if ( '' === $type && '' === $src && '' === $entry && '' === $ref ) {
		return 'unknown';
	}
	$hay  = $src . ' ' . $ref;
	$paid = (bool) preg_match( '/cpc|ppc|paid|ads|cpm|display|retarget|remarketing|pmax|shopping/', $med ) || preg_match( '/_ads?\b|ads_/', $src );
	if ( preg_match( '/facebook|instagram|\bfb\b|\big\b|\bmeta\b|fb\.com|fb\.me/', $hay ) ) {
		return ( 'utm' === $type && ( $paid || '' === $med || preg_match( '/social|paid_social|ad/', $med ) ) ) ? 'meta_ads' : 'meta';
	}
	if ( preg_match( '/[?&](gclid|gbraid|wbraid|gad_source)=/', $entry ) || ( preg_match( '/google|youtube/', $src ) && $paid ) ) {
		return 'google_ads';
	}
	if ( preg_match( '/tiktok/', $hay ) ) {
		return 'tiktok';
	}
	if ( preg_match( '/whatsapp|wa\.me|l\.wl\.co|wa\.link|api\.whatsapp/', $hay ) ) {
		return 'whatsapp';
	}
	if ( preg_match( '/google/', $hay ) ) {
		return 'google';
	}
	if ( 'organic' === $type ) {
		return 'search';
	}
	if ( preg_match( '/e-?mail|newsletter|correo/', $med . ' ' . $src ) ) {
		return 'email';
	}
	if ( 'typein' === $type ) {
		return 'direct';
	}
	if ( 'referral' === $type ) {
		return 'referral';
	}
	return 'utm' === $type ? 'campaign' : 'unknown';
}

/**
 * Metas de atribución de un pedido.
 *
 * @param WC_Order $o Pedido.
 * @return array
 */
function bk_g_source_meta( $o ) {
	$m = array();
	foreach ( BK_G_SRC_KEYS as $k ) {
		$m[ $k ] = (string) $o->get_meta( $k );
	}
	return $m;
}

/**
 * Canal + detalle legible de un pedido ("Campaña: rebajas-octubre · Celular").
 *
 * @param WC_Order $o Pedido.
 * @return array{key:string,label:string,detail:string}
 */
function bk_g_source( $o ) {
	$m    = bk_g_source_meta( $o );
	$key  = bk_g_source_classify( $m );
	$all  = bk_g_sources();
	$bits = array();
	if ( '' !== $m['_wc_order_attribution_utm_campaign'] ) {
		$bits[] = 'Campaña: ' . $m['_wc_order_attribution_utm_campaign'];
	}
	if ( in_array( $key, array( 'referral', 'campaign', 'search', 'email' ), true ) ) {
		$from   = $m['_wc_order_attribution_utm_source'] ? $m['_wc_order_attribution_utm_source'] : (string) wp_parse_url( $m['_wc_order_attribution_referrer'], PHP_URL_HOST );
		$bits[] = $from ? 'Desde: ' . preg_replace( '/^www\./', '', $from ) : '';
	}
	if ( '' !== $m['_wc_order_attribution_device_type'] ) {
		$dev    = array( 'mobile' => 'Celular', 'desktop' => 'Computador', 'tablet' => 'Tablet' );
		$bits[] = $dev[ strtolower( $m['_wc_order_attribution_device_type'] ) ] ?? $m['_wc_order_attribution_device_type'];
	}
	return array( 'key' => $key, 'label' => $all[ $key ]['label'], 'detail' => implode( ' · ', array_filter( $bits ) ) );
}

/**
 * Metas de atribución de muchos pedidos con una consulta (HPOS o postmeta).
 *
 * @param int[] $ids Pedidos (vacío = los últimos 5000 con datos).
 * @return array<int,array> id → metas.
 */
function bk_g_source_rows( $ids = array() ) {
	global $wpdb;
	$hpos  = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	$col   = $hpos ? 'order_id' : 'post_id';
	$kph   = implode( ',', array_fill( 0, count( BK_G_SRC_KEYS ), '%s' ) );
	$args  = BK_G_SRC_KEYS;
	$where = '';
	if ( $ids ) {
		$ids   = array_map( 'intval', $ids );
		$where = ' AND ' . $col . ' IN (' . implode( ',', $ids ) . ')';
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$col} AS id, meta_key AS k, meta_value AS v FROM {$table} WHERE meta_key IN ({$kph}){$where} ORDER BY {$col} DESC LIMIT 40000", $args ) ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->id ][ $r->k ] = (string) $r->v;
	}
	return $out;
}

/**
 * IDs de pedidos de un canal (para el filtro de la lista). "Sin dato" se resuelve con post__not_in.
 *
 * @param string $key Canal.
 * @return array{in?:int[],not_in?:int[]}
 */
function bk_g_source_filter( $key ) {
	$rows = bk_g_source_rows();
	if ( 'unknown' === $key ) {
		$known = array();
		foreach ( $rows as $id => $m ) {
			if ( 'unknown' !== bk_g_source_classify( $m ) ) {
				$known[] = $id;
			}
		}
		return array( 'not_in' => $known );
	}
	$in = array();
	foreach ( $rows as $id => $m ) {
		if ( bk_g_source_classify( $m ) === $key ) {
			$in[] = $id;
		}
	}
	return array( 'in' => $in ? $in : array( 0 ) );
}

/**
 * Ventas pagadas por canal desde una fecha (para el Resumen).
 *
 * @param int $from Timestamp.
 * @return array<int,array{key:string,label:string,count:int,total:string,raw:float,pct:int}>
 */
function bk_g_source_summary( $from ) {
	$paid   = array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() );
	$ids    = wc_get_orders( array( 'status' => $paid, 'date_created' => '>=' . $from, 'limit' => 2000, 'return' => 'ids', 'type' => 'shop_order' ) );
	if ( ! $ids ) {
		return array();
	}
	$rows   = bk_g_source_rows( $ids );
	$totals = array();
	global $wpdb;
	$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$list = implode( ',', array_map( 'intval', $ids ) );
	if ( $hpos ) {
		foreach ( (array) $wpdb->get_results( "SELECT id, total_amount AS t FROM {$wpdb->prefix}wc_orders WHERE id IN ({$list})" ) as $r ) { // phpcs:ignore WordPress.DB
			$totals[ (int) $r->id ] = (float) $r->t;
		}
	} else {
		foreach ( (array) $wpdb->get_results( "SELECT post_id AS id, meta_value AS t FROM {$wpdb->postmeta} WHERE meta_key = '_order_total' AND post_id IN ({$list})" ) as $r ) { // phpcs:ignore WordPress.DB
			$totals[ (int) $r->id ] = (float) $r->t;
		}
	}
	$agg = array();
	$sum = 0.0;
	foreach ( $ids as $id ) {
		$k = bk_g_source_classify( $rows[ $id ] ?? array() );
		$agg[ $k ]['count'] = ( $agg[ $k ]['count'] ?? 0 ) + 1;
		$agg[ $k ]['raw']   = ( $agg[ $k ]['raw'] ?? 0 ) + ( $totals[ $id ] ?? 0 );
		$sum               += $totals[ $id ] ?? 0;
	}
	$all = bk_g_sources();
	$out = array();
	foreach ( $agg as $k => $a ) {
		$out[] = array( 'key' => $k, 'label' => $all[ $k ]['label'], 'count' => $a['count'], 'raw' => $a['raw'], 'total' => bk_g_money( $a['raw'] ), 'pct' => $sum > 0 ? (int) round( $a['raw'] * 100 / $sum ) : 0 );
	}
	usort( $out, static fn( $a, $b ) => $b['raw'] <=> $a['raw'] );
	return $out;
}
