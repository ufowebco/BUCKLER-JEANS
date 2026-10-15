<?php
/**
 * Estadísticas de visitas propias (módulo `stats`), sin cookies y sin guardar IP.
 *
 * - Front: un script mínimo en el pie envía UNA señal por página vista (navigator.sendBeacon) a
 *   POST /wp-json/bk/v1/hit. Funciona con la caché de páginas (el script va en el HTML cacheado).
 * - Servidor: suma por día en `{prefix}bk_stats` (total, página, origen, ciudad, dispositivo).
 *   Visitante único = huella anónima sha256(IP + navegador + sal del mes) recortada; la sal se cambia
 *   cada mes y la anterior se borra, así no se puede rehacer la huella (sin datos personales, Ley 1581).
 * - No cuenta robots ni al equipo (usuarios con acceso a wp-admin o a la gestión).
 * - Ciudad: base libre DB-IP Lite (CC BY 4.0) en uploads/bk-geo/city.mmdb, si se descargó desde
 *   Gestión → SEO y ADS. Sin base, la ciudad se toma solo de los pedidos.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_STATS_DB = '1';

add_action( 'rest_api_init', 'bk_stats_routes' );
add_action( 'wp_footer', 'bk_stats_script', 99 );
add_action( 'admin_init', 'bk_stats_maybe_install' );
add_action( 'bk_stats_cleanup', 'bk_stats_cleanup' );
add_action( 'bk_stats_geo_download', 'bk_stats_geo_download' );
add_action(
	'init',
	static function () {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'bk_stats_cleanup' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'bk_stats_cleanup', array(), 'buckler-stats' );
		}
	}
);

/**
 * Tablas.
 *
 * @return array{0:string,1:string}
 */
function bk_stats_tables() {
	global $wpdb;
	return array( $wpdb->prefix . 'bk_stats', $wpdb->prefix . 'bk_stats_seen' );
}

/**
 * Crea las tablas si faltan.
 */
function bk_stats_maybe_install() {
	if ( get_option( 'bk_stats_db' ) === BK_STATS_DB ) {
		return;
	}
	bk_stats_install();
}

/**
 * dbDelta.
 */
function bk_stats_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	list( $t, $seen ) = bk_stats_tables();
	$c                = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$t} (
			day date NOT NULL,
			kind varchar(12) NOT NULL,
			k varchar(190) NOT NULL,
			views int(10) unsigned NOT NULL DEFAULT 0,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,kind,k),
			KEY kind_day (kind,day)
		) {$c};"
	);
	dbDelta(
		"CREATE TABLE {$seen} (
			day date NOT NULL,
			h char(16) NOT NULL,
			PRIMARY KEY  (day,h)
		) {$c};"
	);
	update_option( 'bk_stats_db', BK_STATS_DB, false );
}

/**
 * Ruta del contador.
 */
function bk_stats_routes() {
	register_rest_route(
		'bk/v1',
		'/hit',
		array(
			'methods'             => 'POST',
			'callback'            => 'bk_stats_hit',
			'permission_callback' => '__return_true', // Público: solo suma contadores anónimos.
		)
	);
}

/**
 * Script del pie (≈ 500 bytes). No usa cookies ni almacenamiento.
 */
function bk_stats_script() {
	if ( is_admin() || is_preview() || is_customize_preview() || isset( $_GET['et_fb'] ) || get_query_var( 'bk_gestion' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$url = esc_url_raw( rest_url( 'bk/v1/hit' ) );
	?>
<script id="bk-stats">(()=>{if(navigator.webdriver||!navigator.sendBeacon)return;const s=()=>{const q=new URLSearchParams(location.search),d={p:location.pathname,r:document.referrer,w:innerWidth,us:q.get('utm_source')||'',um:q.get('utm_medium')||'',uc:q.get('utm_campaign')||'',g:q.has('gclid')||q.has('gbraid')||q.has('wbraid')?1:0,f:q.has('fbclid')?1:0};navigator.sendBeacon(<?php echo wp_json_encode( $url ); ?>,new Blob([JSON.stringify(d)],{type:'text/plain'}))};document.readyState==='complete'?setTimeout(s,800):addEventListener('load',()=>setTimeout(s,800),{once:true})})();</script>
	<?php
}

/**
 * ¿Robot?
 *
 * @param string $ua Navegador.
 * @return bool
 */
function bk_stats_is_bot( $ua ) {
	return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|meta-externalagent|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|preview|curl|wget|python|java\/|httpclient|go-http|axios|node-fetch|phantom|selenium|playwright|puppeteer/i', $ua );
}

/**
 * Origen de la visita.
 *
 * @param array  $d    Datos del navegador.
 * @param string $host Dominio propio.
 * @return string
 */
function bk_stats_source( $d, $host ) {
	$us = strtolower( (string) $d['us'] );
	$um = strtolower( (string) $d['um'] );
	$ad = (bool) preg_match( '/cpc|ppc|paid|ads?$|display|retarget/', $um );
	if ( ! empty( $d['g'] ) || ( 'google' === $us && $ad ) ) {
		return 'Google Ads';
	}
	if ( ! empty( $d['f'] ) || preg_match( '/^(facebook|fb|instagram|ig|meta)/', $us ) ) {
		return $ad || preg_match( '/^(meta|fb_ad)/', $us ) ? 'Meta Ads (Facebook / Instagram)' : 'Facebook / Instagram';
	}
	if ( '' !== $us ) {
		return ucfirst( mb_substr( $us, 0, 40 ) ) . ( $um ? ' / ' . mb_substr( $um, 0, 30 ) : '' );
	}
	$ref = strtolower( (string) wp_parse_url( (string) $d['r'], PHP_URL_HOST ) );
	if ( '' === $ref || $ref === $host ) {
		return 'Directo';
	}
	$map = array(
		'/(^|\.)google\./'                      => 'Google (orgánico)',
		'/(^|\.)bing\.com|duckduckgo|yahoo\./'  => 'Otros buscadores',
		'/facebook\.com|instagram\.com|fb\.me/' => 'Facebook / Instagram',
		'/tiktok\.com/'                         => 'TikTok',
		'/whatsapp|wa\.me|l\.wl\.co/'           => 'WhatsApp',
		'/chatgpt|openai|perplexity|claude\.ai|gemini|copilot/' => 'Asistentes de IA',
		'/youtube\.com/'                        => 'YouTube',
		'/pinterest\./'                         => 'Pinterest',
	);
	foreach ( $map as $re => $label ) {
		if ( preg_match( $re, $ref ) ) {
			return $label;
		}
	}
	return mb_substr( preg_replace( '/^www\./', '', $ref ), 0, 60 );
}

/**
 * Sal del mes (se crea al empezar el mes; las anteriores se borran).
 *
 * @return string
 */
function bk_stats_salt() {
	$key  = 'bk_stats_salt_' . gmdate( 'Ym' );
	$salt = get_option( $key );
	if ( ! $salt ) {
		$salt = wp_generate_password( 32, true, true );
		add_option( $key, $salt, '', false );
		$salt = get_option( $key ); // Por si otra petición la creó a la vez.
		// La del mes anterior se borra: las huellas viejas ya no se pueden rehacer.
		delete_option( 'bk_stats_salt_' . gmdate( 'Ym', strtotime( 'first day of last month' ) ) );
	}
	return (string) $salt;
}

/**
 * IP del visitante.
 *
 * @return string
 */
function bk_stats_ip() {
	if ( class_exists( 'WC_Geolocation' ) ) {
		return (string) WC_Geolocation::get_ip_address();
	}
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Ruta de la base de ciudades.
 *
 * @return string
 */
function bk_stats_geo_path() {
	return wp_upload_dir( null, false )['basedir'] . '/bk-geo/city.mmdb';
}

/**
 * Ciudad de una IP ("Medellín, Antioquia") con la base DB-IP / GeoLite2 si existe.
 *
 * @param string $ip IP.
 * @return string
 */
function bk_stats_city( $ip ) {
	$path = bk_stats_geo_path();
	if ( '' === $ip || ! is_readable( $path ) ) {
		return '';
	}
	if ( ! class_exists( 'MaxMind\Db\Reader' ) && defined( 'WC_ABSPATH' ) && is_readable( WC_ABSPATH . 'vendor/autoload.php' ) ) {
		require_once WC_ABSPATH . 'vendor/autoload.php';
	}
	if ( ! class_exists( 'MaxMind\Db\Reader' ) ) {
		return '';
	}
	try {
		$reader = new MaxMind\Db\Reader( $path );
		$rec    = $reader->get( $ip );
		$reader->close();
	} catch ( Throwable $e ) {
		return '';
	}
	if ( ! is_array( $rec ) ) {
		return '';
	}
	$name    = static fn( $n ) => is_array( $n ) ? (string) ( $n['es'] ?? $n['en'] ?? reset( $n ) ) : '';
	$city    = $name( $rec['city']['names'] ?? null );
	$region  = $name( $rec['subdivisions'][0]['names'] ?? null );
	$country = (string) ( $rec['country']['iso_code'] ?? '' );
	$label   = implode( ', ', array_filter( array( $city, $region ) ) );
	if ( $country && 'CO' !== $country ) {
		$label = $label ? $label . ' (' . $country . ')' : 'Fuera de Colombia (' . $country . ')';
	}
	return mb_substr( $label, 0, 120 );
}

/**
 * Suma una vista.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_stats_hit( WP_REST_Request $r ) {
	global $wpdb;
	$done = new WP_REST_Response( null, 204 );
	$done->header( 'Cache-Control', 'no-store' );
	$d  = json_decode( (string) $r->get_body(), true );
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '';
	if ( ! is_array( $d ) || bk_stats_is_bot( $ua ) ) {
		return $done;
	}
	// El equipo no cuenta (cookie de sesión de WordPress, sin nonce: solo se lee quién es).
	$uid = wp_validate_auth_cookie( '', 'logged_in' );
	if ( $uid && ( user_can( $uid, 'edit_posts' ) || user_can( $uid, 'bk_gestion' ) || user_can( $uid, 'manage_woocommerce' ) ) ) {
		return $done;
	}
	$d    = wp_parse_args( $d, array( 'p' => '/', 'r' => '', 'w' => 0, 'us' => '', 'um' => '', 'uc' => '', 'g' => 0, 'f' => 0 ) );
	$path = '/' . ltrim( (string) wp_parse_url( (string) $d['p'], PHP_URL_PATH ), '/' );
	$path = mb_substr( strtolower( rawurldecode( $path ) ), 0, 180 );
	if ( preg_match( '#^/(wp-admin|wp-json|wp-login|gestion-tienda)#', $path ) ) {
		return $done;
	}
	if ( get_option( 'bk_stats_db' ) !== BK_STATS_DB ) {
		bk_stats_install();
	}
	list( $t, $seen ) = bk_stats_tables();
	$day              = wp_date( 'Y-m-d' );
	$h                = substr( hash( 'sha256', bk_stats_ip() . '|' . $ua . '|' . bk_stats_salt() ), 0, 16 );
	$new              = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$seen} (day, h) VALUES (%s, %s)", $day, $h ) ); // phpcs:ignore WordPress.DB
	$w                = (int) $d['w'];
	$rows             = array(
		array( 'total', 'all', 1, $new ),
		array( 'page', $path, 1, 0 ),
	);
	if ( $new ) {
		$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$rows[] = array( 'src', bk_stats_source( $d, $host ), 0, 1 );
		$rows[] = array( 'device', $w && $w < 768 ? 'Celular' : ( $w && $w < 1100 ? 'Tableta' : 'Computador' ), 0, 1 );
		$city   = bk_stats_city( bk_stats_ip() );
		if ( $city ) {
			$rows[] = array( 'city', $city, 0, 1 );
		}
		if ( '' !== (string) $d['uc'] ) {
			$rows[] = array( 'camp', mb_substr( sanitize_text_field( (string) $d['uc'] ), 0, 120 ), 0, 1 );
		}
		$rows[] = array( 'landing', $path, 0, 1 );
	}
	foreach ( $rows as $row ) {
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$t} (day, kind, k, views, visitors) VALUES (%s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views), visitors = visitors + VALUES(visitors)", // phpcs:ignore WordPress.DB
				$day,
				$row[0],
				sanitize_text_field( $row[1] ),
				$row[2],
				$row[3]
			)
		);
	}
	return $done;
}

/**
 * Limpieza diaria: huellas de más de 62 días (solo sirven para contar únicos del mes) y datos de más de 2 años.
 */
function bk_stats_cleanup() {
	global $wpdb;
	list( $t, $seen ) = bk_stats_tables();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$seen} WHERE day < %s", wp_date( 'Y-m-d', strtotime( '-62 days' ) ) ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", wp_date( 'Y-m-d', strtotime( '-730 days' ) ) ) ); // phpcs:ignore WordPress.DB
	// Base de ciudades: se renueva cada mes si ya se había descargado.
	$path = bk_stats_geo_path();
	if ( is_file( $path ) && filemtime( $path ) < time() - 35 * DAY_IN_SECONDS ) {
		bk_stats_geo_download();
	}
}

/**
 * Descarga la base libre DB-IP "IP to City Lite" (mes actual o anterior) y la deja en uploads/bk-geo/city.mmdb.
 * Se ejecuta en segundo plano (Action Scheduler).
 *
 * @return bool
 */
function bk_stats_geo_download() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	update_option( 'bk_stats_geo_status', array( 'state' => 'running', 't' => time() ), false );
	$tmp = null;
	foreach ( array( 'now', 'first day of last month' ) as $when ) {
		$url = 'https://download.db-ip.com/free/dbip-city-lite-' . gmdate( 'Y-m', strtotime( $when ) ) . '.mmdb.gz';
		$tmp = download_url( $url, 600 );
		if ( ! is_wp_error( $tmp ) ) {
			break;
		}
	}
	if ( is_wp_error( $tmp ) ) {
		update_option( 'bk_stats_geo_status', array( 'state' => 'error', 't' => time(), 'msg' => $tmp->get_error_message() ), false );
		return false;
	}
	$path = bk_stats_geo_path();
	wp_mkdir_p( dirname( $path ) );
	if ( ! is_file( dirname( $path ) . '/index.php' ) ) {
		file_put_contents( dirname( $path ) . '/index.php', "<?php\n// Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( dirname( $path ) . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$in  = gzopen( $tmp, 'rb' );
	$out = fopen( $path . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	while ( $in && $out && ! gzeof( $in ) ) {
		fwrite( $out, gzread( $in, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( $in ) {
		gzclose( $in );
	}
	if ( $out ) {
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	wp_delete_file( $tmp );
	$ok = filesize( $path . '.tmp' ) > 1000000 && rename( $path . '.tmp', $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $ok ) {
		wp_delete_file( $path . '.tmp' );
	}
	update_option( 'bk_stats_geo_status', $ok ? array( 'state' => 'ok', 't' => time() ) : array( 'state' => 'error', 't' => time(), 'msg' => 'Archivo incompleto.' ), false );
	return $ok;
}
