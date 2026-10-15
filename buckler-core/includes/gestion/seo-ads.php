<?php
/**
 * Gestión de tienda → SEO y ADS, y → Carritos abandonados.
 *
 * SEO y ADS:
 *   GET  gestion/seo/tags[?refresh=1]  etiquetas de Google y Meta encontradas en el HTML público (inicio, tienda y
 *                                      un producto), contenido del contenedor de GTM, verificación con pedidos reales
 *                                      (atribución y eventos de compra de Meta) y revisión SEO técnica. Caché 6 h.
 *   GET  gestion/seo/visits?days=30    visitas propias (módulo stats): hoy, 7 y 30 días, serie diaria, páginas,
 *                                      origen, ciudades, dispositivos; ciudades de compra (pedidos).
 *   POST gestion/seo/geo               (solo admin) descarga la base de ciudades DB-IP Lite en segundo plano.
 * Carritos:
 *   GET  gestion/abandoned?status=&page=
 *
 * Nada se inventa: todo sale del HTML real del sitio, de los pedidos o del contador propio.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_gs_routes' );

/**
 * Rutas.
 */
function bk_gs_routes() {
	$ns = 'bk/v1';
	register_rest_route( $ns, '/gestion/seo/tags', array( 'methods' => 'GET', 'callback' => 'bk_gs_tags', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( $ns, '/gestion/seo/visits', array( 'methods' => 'GET', 'callback' => 'bk_gs_visits', 'permission_callback' => 'bk_g_can' ) );
	register_rest_route( $ns, '/gestion/seo/geo', array( 'methods' => 'POST', 'callback' => 'bk_gs_geo', 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ) );
	register_rest_route( $ns, '/gestion/abandoned', array( 'methods' => 'GET', 'callback' => 'bk_gs_abandoned', 'permission_callback' => 'bk_g_can' ) );
}

/* ---------------------------------------------------------------------------
 * Etiquetas
 * ------------------------------------------------------------------------- */

/**
 * Descarga una página del sitio como la vería una visitante (sin sesión).
 *
 * @param string $url URL.
 * @return array{code:int,html:string,ms:int,headers:array}
 */
function bk_gs_fetch( $url ) {
	$t   = microtime( true );
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Mobile Safari/537.36 BucklerCore-Revision',
			'cookies'     => array(),
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 'code' => 0, 'html' => '', 'ms' => 0, 'headers' => array(), 'error' => $res->get_error_message() );
	}
	return array(
		'code'    => (int) wp_remote_retrieve_response_code( $res ),
		'html'    => (string) wp_remote_retrieve_body( $res ),
		'ms'      => (int) round( ( microtime( true ) - $t ) * 1000 ),
		'headers' => wp_remote_retrieve_headers( $res )->getAll(),
	);
}

/**
 * Valores únicos de un patrón.
 *
 * @param string $re   Expresión.
 * @param string $html Texto.
 * @param int    $g    Grupo.
 * @return string[]
 */
function bk_gs_all( $re, $html, $g = 1 ) {
	return preg_match_all( $re, $html, $m ) ? array_values( array_unique( $m[ $g ] ) ) : array();
}

/**
 * Lee el contenedor público de GTM (gtm.js) y resume qué etiquetas y eventos tiene.
 *
 * @param string $id GTM-XXXX.
 * @return array
 */
function bk_gs_gtm( $id ) {
	$res = wp_remote_get( 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode( $id ), array( 'timeout' => 20 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array( 'ok' => false, 'error' => is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
	}
	$js     = (string) wp_remote_retrieve_body( $res );
	$labels = array(
		'__googtag' => 'Etiqueta de Google (GA4 / Ads)',
		'__gaawc'   => 'Configuración de GA4',
		'__gaawe'   => 'Evento de GA4',
		'__awct'    => 'Conversión de Google Ads',
		'__sp'      => 'Remarketing de Google Ads',
		'__gclidw'  => 'Vinculador de conversiones (gclid)',
		'__ua'      => 'Universal Analytics (obsoleto)',
		'__html'    => 'HTML personalizado',
		'__img'     => 'Píxel de imagen',
		'__flc'     => 'Floodlight (contador)',
		'__fls'     => 'Floodlight (ventas)',
		'__hjtc'    => 'Hotjar',
		'__baut'    => 'Microsoft Ads (UET)',
	);
	$tags = array();
	// Las etiquetas viven en "tags":[ … ] del recurso; se cuentan sus "function".
	$block = preg_match( '/"tags":\[(.*?)\],"predicates"/s', $js, $mm ) ? $mm[1] : $js;
	foreach ( bk_gs_all( '/"function":"(__[a-z0-9_]+)"/i', $block ) as $fn ) {
		$n = preg_match_all( '/"function":"' . preg_quote( $fn, '/' ) . '"/', $block );
		if ( isset( $labels[ $fn ] ) || 0 === strpos( $fn, '__cvt_' ) ) {
			$tags[] = array( 'type' => $labels[ $fn ] ?? 'Plantilla de la comunidad (' . $fn . ')', 'count' => $n );
		}
	}
	$html_pixels = array();
	foreach ( array( 'fbq(' => 'Píxel de Meta dentro de GTM', 'ttq.' => 'TikTok dentro de GTM', 'clarity' => 'Microsoft Clarity dentro de GTM', 'hotjar' => 'Hotjar dentro de GTM' ) as $needle => $label ) {
		if ( false !== strpos( $js, $needle ) ) {
			$html_pixels[] = $label;
		}
	}
	return array(
		'ok'       => true,
		'tags'     => $tags,
		'ga4'      => bk_gs_all( '/\b(G-[A-Z0-9]{6,12})\b/', $js ),
		'ads'      => bk_gs_all( '/\b(AW-\d{6,12})\b/', $js ),
		'ads_conv' => bk_gs_all( '/"vtp_conversionId":"(\d{6,12})"/', $js ),
		'events'   => array_values( array_filter( bk_gs_all( '/"vtp_eventName":"([A-Za-z0-9_]{2,40})"/', $js ) ) ),
		'triggers' => array_values( array_filter( bk_gs_all( '/"arg1":"((?:bk_|gtm\.|purchase|add_to_cart|begin_checkout|view_item)[A-Za-z0-9_.]*)"/', $js ), static fn( $e ) => ! in_array( $e, array( 'gtm.js', 'gtm.dom', 'gtm.load', 'gtm.init', 'gtm.init_consent' ), true ) ) ),
		'extra'    => $html_pixels,
		'size_kb'  => (int) round( strlen( $js ) / 1024 ),
	);
}

/**
 * Pedidos pagados de los últimos 30 días: origen (atribución de WooCommerce) y eventos de compra registrados.
 *
 * @return array
 */
function bk_gs_orders_check() {
	$orders = wc_get_orders(
		array(
			'limit'        => 300,
			'type'         => 'shop_order',
			'status'       => array_map( static fn( $s ) => 'wc-' . $s, wc_get_is_paid_statuses() ),
			'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
		)
	);
	$src     = array();
	$cities  = array();
	$meta    = 0;
	$ufo     = 0;
	$attrib  = 0;
	$total   = 0.0;
	$devices = array();
	foreach ( $orders as $o ) {
		$total += (float) $o->get_total();
		$keys   = array();
		foreach ( $o->get_meta_data() as $m ) {
			$keys[] = (string) $m->get_data()['key'];
		}
		if ( preg_grep( '/^_meta_(purchase_tracked|event_id)/', $keys ) ) {
			++$meta;
		}
		if ( in_array( '_ufo_purchase_tracked', $keys, true ) ) {
			++$ufo;
		}
		$type = (string) $o->get_meta( '_wc_order_attribution_source_type' );
		if ( '' !== $type ) {
			++$attrib;
		}
		$s   = strtolower( (string) $o->get_meta( '_wc_order_attribution_utm_source' ) );
		$md  = strtolower( (string) $o->get_meta( '_wc_order_attribution_utm_medium' ) );
		$lab = '' === $type ? 'Sin dato de origen' : ( 'typein' === $type ? 'Directo' : ( 'admin' === $type ? 'Creado en el admin' : '' ) );
		if ( '' === $lab ) {
			$paid = (bool) preg_match( '/cpc|ppc|paid|ads?$/', $md );
			if ( preg_match( '/google/', $s ) ) {
				$lab = $paid ? 'Google Ads' : 'Google (orgánico)';
			} elseif ( preg_match( '/facebook|instagram|^fb|^ig|meta/', $s ) ) {
				$lab = $paid ? 'Meta Ads' : 'Facebook / Instagram';
			} else {
				$lab = $s ? ucfirst( $s ) : ucfirst( $type );
			}
		}
		$src[ $lab ]          = ( $src[ $lab ] ?? array( 'orders' => 0, 'total' => 0.0 ) );
		$src[ $lab ]['orders']++;
		$src[ $lab ]['total'] += (float) $o->get_total();
		$c                     = trim( ucwords( mb_strtolower( (string) $o->get_billing_city() ) ) );
		if ( '' !== $c ) {
			$cities[ $c ] = ( $cities[ $c ] ?? 0 ) + 1;
		}
		$dev = (string) $o->get_meta( '_wc_order_attribution_device_type' );
		if ( '' !== $dev ) {
			$devices[ $dev ] = ( $devices[ $dev ] ?? 0 ) + 1;
		}
	}
	uasort( $src, static fn( $a, $b ) => $b['orders'] <=> $a['orders'] );
	arsort( $cities );
	$out = array();
	foreach ( $src as $k => $v ) {
		$out[] = array( 'label' => $k, 'orders' => $v['orders'], 'total' => bk_g_money( $v['total'] ) );
	}
	$cty = array();
	foreach ( array_slice( $cities, 0, 12, true ) as $k => $v ) {
		$cty[] = array( 'label' => $k, 'n' => $v );
	}
	return array(
		'orders'     => count( $orders ),
		'total'      => bk_g_money( $total ),
		'meta'       => $meta,
		'ufo'        => $ufo,
		'attributed' => $attrib,
		'sources'    => $out,
		'cities'     => $cty,
		'devices'    => $devices,
	);
}

/**
 * Estado de etiquetas + SEO técnico.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gs_tags( WP_REST_Request $r ) {
	$cached = get_transient( 'bk_gs_tags' );
	if ( is_array( $cached ) && ! rest_sanitize_boolean( $r['refresh'] ) ) {
		return rest_ensure_response( $cached );
	}
	$prod  = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'stock_status' => 'instock', 'orderby' => 'date', 'order' => 'DESC' ) );
	$pages = array(
		'home'    => array( 'Inicio', home_url( '/' ) ),
		'shop'    => array( 'Tienda', wc_get_page_permalink( 'shop' ) ),
		'product' => array( 'Producto', $prod ? $prod[0]->get_permalink() : '' ),
	);
	$found = array();
	$seo   = array();
	$html  = '';
	foreach ( $pages as $key => $pg ) {
		if ( ! $pg[1] ) {
			continue;
		}
		$res          = bk_gs_fetch( add_query_arg( 'bk_scan', wp_rand( 1000, 9999 ), $pg[1] ) );
		$h            = $res['html'];
		$html        .= $h;
		$found[ $key ] = array(
			'label' => $pg[0],
			'url'   => $pg[1],
			'code'  => $res['code'],
			'ms'    => $res['ms'],
			'error' => $res['error'] ?? '',
			'fbq'   => bk_gs_all( '/fbq\(\s*[\'"]track[\'"]\s*,\s*[\'"](\w+)[\'"]/', $h ),
			'gtag'  => bk_gs_all( '/gtag\(\s*[\'"]event[\'"]\s*,\s*[\'"](\w+)[\'"]/', $h ),
			'dl'    => bk_gs_all( '/[\'"]event[\'"]\s*:\s*[\'"]([A-Za-z0-9_.]{3,40})[\'"]/', $h ),
		);
		// SEO técnico por página.
		$title         = preg_match( '#<title[^>]*>(.*?)</title>#is', $h, $m ) ? trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) ) : '';
		$desc          = preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $h, $m ) ? trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) : '';
		$seo[ $key ]   = array(
			'label'     => $pg[0],
			'url'       => $pg[1],
			'title'     => $title,
			'desc'      => $desc,
			'h1'        => preg_match_all( '#<h1[\s>]#i', $h ),
			'canonical' => (bool) preg_match( '#<link[^>]+rel=["\']canonical#i', $h ),
			'noindex'   => (bool) preg_match( '#<meta[^>]+name=["\']robots["\'][^>]+noindex#i', $h ),
			'og'        => (bool) preg_match( '#<meta[^>]+property=["\']og:image#i', $h ),
			'schema'    => bk_gs_all( '/"@type"\s*:\s*"([A-Za-z]+)"/', $h ),
			'imgs_alt'  => preg_match_all( '#<img(?![^>]*\balt=)[^>]*>#i', $h ),
		);
	}

	$delayed = (bool) preg_match( '/type=["\']text\/bk-delay["\']/', $html );
	$gtm     = bk_gs_all( '/\b(GTM-[A-Z0-9]{4,10})\b/', $html );
	$ga4     = bk_gs_all( '/\b(G-[A-Z0-9]{6,12})\b/', $html );
	$ads     = bk_gs_all( '/\b(AW-\d{6,12})\b/', $html );
	$pixels  = bk_gs_all( '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"]?(\d{10,20})/', $html );
	$inits   = preg_match_all( '/fbq\(\s*[\'"]init[\'"]/', (string) ( $html ) );
	$fb_src  = array();
	foreach ( array( 'facebook-for-woocommerce' => 'Facebook for WooCommerce', 'official-facebook-pixel' => 'Meta Pixel (plugin oficial)', 'pixelyoursite' => 'PixelYourSite' ) as $needle => $label ) {
		if ( false !== stripos( $html, $needle ) ) {
			$fb_src[] = $label;
		}
	}
	$extra = array();
	foreach (
		array(
			'TikTok Pixel'          => '/analytics\.tiktok\.com|ttq\.load/',
			'Microsoft Clarity'     => '/clarity\.ms\/tag/',
			'Hotjar'                => '/static\.hotjar\.com/',
			'Pinterest Tag'         => '/pintrk\(/',
			'AdsAgent'              => '/frontendInjection\.js|adsagent/i',
			'Google for WooCommerce' => '/google-listings-and-ads|gla_/',
		) as $label => $re
	) {
		if ( preg_match( $re, $html ) ) {
			$extra[] = $label;
		}
	}

	$tags = array();
	$tags[] = array(
		'name'   => 'Google Tag Manager',
		'ids'    => $gtm,
		'status' => $gtm ? ( count( $gtm ) > 1 ? 'warn' : 'ok' ) : 'missing',
		'note'   => $gtm ? ( count( $gtm ) > 1 ? 'Hay más de un contenedor: revisa que no se dupliquen eventos.' : 'Instalado en todas las páginas revisadas.' ) : 'No se encontró en el HTML público.',
	);
	$tags[] = array(
		'name'   => 'Google Analytics 4',
		'ids'    => $ga4,
		'status' => $ga4 ? 'ok' : ( $gtm ? 'info' : 'missing' ),
		'note'   => $ga4 ? 'Etiqueta gtag directa en el HTML.' : ( $gtm ? 'No está en el HTML: puede estar configurada dentro de GTM (ver abajo).' : 'No se encontró.' ),
	);
	$tags[] = array(
		'name'   => 'Google Ads',
		'ids'    => $ads,
		'status' => $ads ? 'ok' : ( $gtm ? 'info' : 'missing' ),
		'note'   => $ads ? 'Etiqueta de conversiones / remarketing presente.' : 'No está en el HTML: revisa el contenedor de GTM.',
	);
	$tags[] = array(
		'name'   => 'Píxel de Meta (Facebook / Instagram)',
		'ids'    => $pixels,
		'status' => $pixels ? ( $inits > count( $found ) || count( $fb_src ) > 1 ? 'warn' : 'ok' ) : 'missing',
		'note'   => $pixels ? ( count( $fb_src ) > 1 ? 'Lo cargan ' . implode( ' y ', $fb_src ) . ': el píxel está DUPLICADO (eventos dobles en Meta). Deja solo uno.' : 'Instalado' . ( $fb_src ? ' por ' . implode( ', ', $fb_src ) : '' ) . '.' ) : 'No se encontró en el HTML público.',
	);
	$tags[] = array(
		'name'   => 'Verificación de dominio',
		'ids'    => array_filter( array( preg_match( '#google-site-verification#i', $html ) ? 'Google Search Console' : '', preg_match( '#facebook-domain-verification#i', $html ) ? 'Meta' : '' ) ),
		'status' => 'info',
		'note'   => 'Meta y Google también pueden verificar por DNS (no se ve en el HTML).',
	);

	$robots  = bk_gs_fetch( home_url( '/robots.txt' ) );
	$sitemap = '';
	foreach ( array( '/sitemaps.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' ) as $sm ) {
		$t = bk_gs_fetch( home_url( $sm ) );
		if ( 200 === $t['code'] && false !== stripos( $t['html'], '<sitemap' ) ) {
			$sitemap = home_url( $sm );
			break;
		}
	}
	$llms = bk_gs_fetch( home_url( '/llms.txt' ) );

	$gtm_info = array();
	foreach ( $gtm as $id ) {
		$gtm_info[ $id ] = bk_gs_gtm( $id );
	}
	$data = array(
		'checked'  => wp_date( 'j M Y, g:i a' ),
		'delayed'  => $delayed,
		'tags'     => $tags,
		'extra'    => $extra,
		'pages'    => array_values( $found ),
		'gtm'      => $gtm_info,
		'orders'   => bk_gs_orders_check(),
		'seo'      => array_values( $seo ),
		'site'     => array(
			'robots'      => 200 === $robots['code'],
			'robots_all'  => (bool) preg_match( '#^Disallow:\s*/\s*$#mi', $robots['html'] ),
			'sitemap'     => $sitemap,
			'llms'        => 200 === $llms['code'],
			'blog_public' => (bool) get_option( 'blog_public' ),
			'https'       => 0 === strpos( home_url(), 'https://' ),
		),
		'feeds'    => array(
			'google' => class_exists( 'Automattic\WooCommerce\GoogleListingsAndAds\PluginFactory' ) || is_dir( WP_PLUGIN_DIR . '/google-listings-and-ads' ),
			'meta'   => class_exists( 'WC_Facebookcommerce' ) || is_dir( WP_PLUGIN_DIR . '/facebook-for-woocommerce' ),
		),
	);
	set_transient( 'bk_gs_tags', $data, 6 * HOUR_IN_SECONDS );
	return rest_ensure_response( $data );
}

/* ---------------------------------------------------------------------------
 * Visitas
 * ------------------------------------------------------------------------- */

/**
 * Resumen de visitas.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gs_visits( WP_REST_Request $r ) {
	global $wpdb;
	$days = in_array( (int) $r['days'], array( 7, 30, 90 ), true ) ? (int) $r['days'] : 30;
	$on   = function_exists( 'bk_stats_tables' );
	$base = array(
		'enabled' => $on,
		'days'    => $days,
		'geo'     => array(
			'has'    => $on && is_readable( bk_stats_geo_path() ),
			'status' => get_option( 'bk_stats_geo_status', array() ),
			'admin'  => current_user_can( 'manage_options' ),
		),
	);
	if ( ! $on ) {
		return rest_ensure_response( $base );
	}
	if ( get_option( 'bk_stats_db' ) !== BK_STATS_DB ) {
		bk_stats_install();
	}
	list( $t, $seen ) = bk_stats_tables();
	$today            = wp_date( 'Y-m-d' );
	$since            = static fn( $n ) => wp_date( 'Y-m-d', strtotime( '-' . ( $n - 1 ) . ' days' ) );
	$uniq             = static fn( $from ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT h) FROM {$seen} WHERE day >= %s", $from ) ); // phpcs:ignore WordPress.DB
	$views            = static fn( $from ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) FROM {$t} WHERE kind = 'total' AND day >= %s", $from ) ); // phpcs:ignore WordPress.DB
	$top              = static function ( $kind, $field, $from, $limit = 12 ) use ( $wpdb, $t ) {
		return array_map(
			static fn( $row ) => array( 'label' => $row->k, 'n' => (int) $row->n ),
			(array) $wpdb->get_results( $wpdb->prepare( "SELECT k, SUM({$field}) AS n FROM {$t} WHERE kind = %s AND day >= %s GROUP BY k ORDER BY n DESC LIMIT %d", $kind, $from, $limit ) ) // phpcs:ignore WordPress.DB
		);
	};
	$from   = $since( $days );
	$series = array();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT day, views, visitors FROM {$t} WHERE kind = 'total' AND day >= %s", $from ), OBJECT_K ); // phpcs:ignore WordPress.DB
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$d        = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
		$series[] = array( 'day' => $d, 'label' => wp_date( 'j M', strtotime( $d ) ), 'views' => (int) ( $rows[ $d ]->views ?? 0 ), 'visitors' => (int) ( $rows[ $d ]->visitors ?? 0 ) );
	}
	$first = $wpdb->get_var( "SELECT MIN(day) FROM {$t}" ); // phpcs:ignore WordPress.DB
	return rest_ensure_response(
		array_merge(
			$base,
			array(
				'since'   => $first ? wp_date( 'j M Y', strtotime( $first ) ) : '',
				'kpis'    => array(
					'today' => array( 'visitors' => $uniq( $today ), 'views' => $views( $today ) ),
					'week'  => array( 'visitors' => $uniq( $since( 7 ) ), 'views' => $views( $since( 7 ) ) ),
					'month' => array( 'visitors' => $uniq( $since( 30 ) ), 'views' => $views( $since( 30 ) ) ),
				),
				'series'  => $series,
				'pages'   => $top( 'page', 'views', $from, 15 ),
				'landing' => $top( 'landing', 'visitors', $from, 10 ),
				'sources' => $top( 'src', 'visitors', $from ),
				'cities'  => $top( 'city', 'visitors', $from ),
				'devices' => $top( 'device', 'visitors', $from, 3 ),
				'camps'   => $top( 'camp', 'visitors', $from, 10 ),
			)
		)
	);
}

/**
 * Programa la descarga de la base de ciudades.
 *
 * @return WP_REST_Response|WP_Error
 */
function bk_gs_geo() {
	if ( ! function_exists( 'bk_stats_geo_download' ) ) {
		return new WP_Error( 'bk_off', 'Enciende primero el módulo "Estadísticas de visitas".', array( 'status' => 400 ) );
	}
	update_option( 'bk_stats_geo_status', array( 'state' => 'queued', 't' => time() ), false );
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'bk_stats_geo_download', array(), 'buckler-stats' );
	} else {
		wp_schedule_single_event( time(), 'bk_stats_geo_download' );
	}
	return rest_ensure_response( array( 'status' => get_option( 'bk_stats_geo_status' ) ) );
}

/* ---------------------------------------------------------------------------
 * Carritos abandonados
 * ------------------------------------------------------------------------- */

/**
 * Lista y resumen de carritos abandonados.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gs_abandoned( WP_REST_Request $r ) {
	global $wpdb;
	$enabled = function_exists( 'bk_on' ) && bk_on( 'abandoned' ) && function_exists( 'bk_ac_table' );
	$table   = $enabled ? bk_ac_table() : '';
	if ( ! $enabled || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
		return rest_ensure_response( array( 'enabled' => false ) );
	}
	$s      = bk_ac_settings();
	$labels = array(
		'active'       => 'Pendiente',
		'recovered'    => 'Recuperado',
		'converted'    => 'Compró',
		'emptied'      => 'Vació el carrito',
		'expired'      => 'Vencido',
		'unsubscribed' => 'Se dio de baja',
	);
	$status = sanitize_key( (string) $r['status'] );
	$page   = max( 1, (int) $r['page'] );
	$per    = 25;
	$where  = isset( $labels[ $status ] ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
	$kpi    = $wpdb->get_row( // phpcs:ignore WordPress.DB
		"SELECT COUNT(*) AS captured, SUM(status = 'active') AS pending, SUM(status = 'recovered') AS recovered,
			SUM(CASE WHEN status = 'recovered' THEN cart_total ELSE 0 END) AS recovered_total, SUM(emails_sent) AS emails,
			SUM(clicked_at IS NOT NULL) AS clicked, SUM(CASE WHEN status = 'active' THEN cart_total ELSE 0 END) AS pending_total
		 FROM {$table} WHERE created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"
	);
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore WordPress.DB
	$list   = array();
	foreach ( (array) $rows as $row ) {
		$items  = json_decode( (string) $row->cart, true ) ?: array();
		$list[] = array(
			'id'      => (int) $row->id,
			'date'    => wp_date( 'j M Y, g:i a', strtotime( $row->created_at . ' UTC' ) ),
			'name'    => $row->first_name,
			'email'   => $row->email,
			'phone'   => $row->phone,
			'items'   => array_map(
				static fn( $i ) => array(
					'name'  => (string) ( $i['name'] ?? '' ),
					'qty'   => (int) ( $i['quantity'] ?? 1 ),
					'size'  => implode( ', ', wp_list_pluck( (array) ( $i['variation'] ?? array() ), 'value' ) ),
					'image' => (string) ( $i['image'] ?? '' ),
				),
				$items
			),
			'total'   => bk_g_money( (float) $row->cart_total ),
			'emails'  => (int) $row->emails_sent,
			'clicked' => (bool) $row->clicked_at,
			'status'  => $row->status,
			'label'   => $labels[ $row->status ] ?? $row->status,
			'order'   => (int) $row->order_id,
		);
	}
	return rest_ensure_response(
		array(
			'enabled'  => true,
			'labels'   => $labels,
			'settings' => array(
				'on'      => 'yes' === $s['enabled'],
				'delays'  => array_map( 'intval', (array) $s['delays'] ),
				'coupons' => array_map( 'intval', (array) ( $s['coupon_pct'] ?? array() ) ),
				'consent' => (string) ( $s['consent_mode'] ?? '' ),
				'admin'   => current_user_can( 'manage_woocommerce' ) ? admin_url( 'admin.php?page=bk-abandoned&tab=settings' ) : '',
			),
			'kpi'      => array(
				'captured'       => (int) $kpi->captured,
				'pending'        => (int) $kpi->pending,
				'pending_total'  => bk_g_money( (float) $kpi->pending_total ),
				'emails'         => (int) $kpi->emails,
				'clicked'        => (int) $kpi->clicked,
				'recovered'      => (int) $kpi->recovered,
				'recovered_total' => bk_g_money( (float) $kpi->recovered_total ),
			),
			'rows'     => $list,
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per ),
		)
	);
}
