<?php
/**
 * Modo Navidad (aprobado por el cliente 2026-11-27 con capturas): adornos sin cambiar el diseño.
 *
 * - Gorro de Navidad sobre la "B" del logo y en el botón flotante del carrito (CSS, SVG en data URI).
 * - Franja de anuncios en rojo navidad con "🎄 ¡Felices fiestas!" primero.
 * - Guirnalda de luces bajo el menú, nieve suave (CSS; se apaga con "reducir movimiento").
 * - Bloque "Esta Navidad, regala el jean perfecto" debajo de los banners del inicio.
 * - Nieve en el borde de las fotos de la tienda, recuadro "¿Es un regalo?" en la ficha, nieve y saludo en el pie.
 *
 * Fechas: del 1 de diciembre (12:00 a. m.) al 6 de enero (11:59 p. m.), hora del sitio. Interruptor en Gestión →
 * Contenido → Temporada (opción bk_xmas_mode: auto | on | off). Al empezar y terminar se vacía la caché de páginas.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fechas de este año de temporada (diciembre → enero siguiente).
 *
 * @param int|null $now Momento.
 * @return array{start:int,end:int}
 */
function bk_xmas_window( $now = null ) {
	$now = $now ?? bk_xmas_now();
	$tz  = wp_timezone();
	$y   = (int) wp_date( 'Y', $now );
	$m   = (int) wp_date( 'n', $now );
	$y0  = $m <= 6 ? $y - 1 : $y; // En enero la temporada empezó el diciembre anterior.
	$cfg = (array) apply_filters( 'bk_xmas_dates', array( 'start' => '12-01 00:00:00', 'end' => '01-07 00:00:00' ) );
	$s   = new DateTimeImmutable( $y0 . '-' . $cfg['start'], $tz );
	$e   = new DateTimeImmutable( ( $y0 + 1 ) . '-' . $cfg['end'], $tz );
	return array( 'start' => $s->getTimestamp(), 'end' => $e->getTimestamp() );
}

/**
 * Hora actual (filtro bk_xmas_now para pruebas).
 *
 * @return int
 */
function bk_xmas_now() {
	return (int) apply_filters( 'bk_xmas_now', time() );
}

/**
 * Modo guardado: auto | on | off.
 *
 * @return string
 */
function bk_xmas_mode() {
	$m = (string) get_option( 'bk_xmas_mode', 'auto' );
	return in_array( $m, array( 'auto', 'on', 'off' ), true ) ? $m : 'auto';
}

/**
 * ¿Está activo ahora?
 *
 * @return bool
 */
function bk_xmas_on() {
	static $on = null;
	if ( null === $on ) {
		$mode = bk_xmas_mode();
		$w    = bk_xmas_window();
		$now  = bk_xmas_now();
		$on   = 'on' === $mode || ( 'auto' === $mode && $now >= $w['start'] && $now < $w['end'] );
		$on   = (bool) apply_filters( 'bk_xmas_on', $on );
	}
	return $on;
}

/* ---------------------------------------------------------------------------
 * Vaciar la caché al empezar y al terminar (Action Scheduler)
 * ------------------------------------------------------------------------- */

add_action( 'init', 'bk_xmas_schedule', 30 );

/**
 * Programa el vaciado de caché en el próximo inicio o fin de la temporada.
 */
function bk_xmas_schedule() {
	if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}
	$w   = bk_xmas_window();
	$now = time();
	$at  = $now < $w['start'] ? $w['start'] : ( $now < $w['end'] ? $w['end'] : bk_xmas_window( $now + 200 * DAY_IN_SECONDS )['start'] );
	if ( (int) get_option( 'bk_xmas_next' ) === $at && as_next_scheduled_action( 'bk_xmas_flush', array(), 'buckler-xmas' ) ) {
		return;
	}
	as_unschedule_all_actions( 'bk_xmas_flush', array(), 'buckler-xmas' );
	as_schedule_single_action( $at + 5, 'bk_xmas_flush', array(), 'buckler-xmas' );
	update_option( 'bk_xmas_next', $at, false );
}

add_action(
	'bk_xmas_flush',
	static function () {
		if ( function_exists( 'bk_cache_purge' ) ) {
			bk_cache_purge();
		}
		delete_option( 'bk_xmas_next' ); // El próximo init programa la siguiente fecha.
	}
);

/* ---------------------------------------------------------------------------
 * Front
 * ------------------------------------------------------------------------- */

add_action(
	'wp',
	static function () {
		if ( is_admin() || ! function_exists( 'bk_pages_route' ) || ! bk_pages_route() || ! bk_xmas_on() ) {
			return;
		}
		add_filter( 'body_class', static fn( $c ) => array_merge( $c, array( 'bk-xmas' ) ) );
		add_action( 'wp_enqueue_scripts', static fn() => wp_enqueue_style( 'bk-xmas', BK_URL . 'assets/css/xmas.css', array( 'bk-pages' ), bk_asset_ver( 'assets/css/xmas.css' ) ), 30 );
		add_action( 'bk_home_after_hero', 'bk_xmas_home', 20 );
		add_action( 'bk_footer_top', 'bk_xmas_footer' );
		add_action( 'woocommerce_single_product_summary', 'bk_xmas_gift', 12 );
		add_action(
			'woocommerce_before_single_product',
			static function () { // BodyCommerce borra lo enganchado al resumen: se vuelve a poner.
				if ( false === has_action( 'woocommerce_single_product_summary', 'bk_xmas_gift' ) ) {
					add_action( 'woocommerce_single_product_summary', 'bk_xmas_gift', 12 );
				}
			},
			3
		);
	},
	20
);

/**
 * Bloque navideño del inicio (no sale mientras haya una campaña con cuenta regresiva en el inicio).
 */
function bk_xmas_home() {
	if ( function_exists( 'bk_cd_campaign' ) && bk_cd_campaign() ) {
		return;
	}
	$lines = (array) bk_config( 'lines' );
	$tree  = static fn( $c, $cls ) => '<svg class="bk-xmas-home__tree ' . $cls . '" viewBox="0 0 60 90" aria-hidden="true" focusable="false"><path d="M30 2 52 34H40l18 26H42l18 22H0l18-22H2l18-26H8Z" fill="' . $c . '"/><rect x="25" y="82" width="10" height="8" fill="#6b4226"/><circle cx="30" cy="6" r="4" fill="#f2c14e"/><circle cx="22" cy="44" r="2.6" fill="#c8102e"/><circle cx="38" cy="58" r="2.6" fill="#e4ec6a"/><circle cx="26" cy="72" r="2.6" fill="#f2c14e"/><circle cx="40" cy="38" r="2.4" fill="#fff"/></svg>';
	$slim  = mb_strtolower( (string) ( $lines['linea-slim']['sizes'] ?? '6–12' ) );
	$curvy = mb_strtolower( (string) ( $lines['linea-curvy']['sizes'] ?? '14–24' ) );
	?>
	<section class="bk-xmas-home" aria-labelledby="bk-xmas-title">
		<p class="bk-xmas-home__kicker"><?php esc_html_e( 'Temporada de regalos', 'buckler-core' ); ?></p>
		<h2 id="bk-xmas-title"><?php esc_html_e( 'Esta Navidad, regala el jean perfecto', 'buckler-core' ); ?></h2>
		<p class="bk-xmas-home__lead">
			<?php
			/* translators: 1: tallas Slim, 2: tallas Curvy */
			echo esc_html( sprintf( __( 'Línea Slim (%1$s) y Línea Curvy (%2$s). Si la talla no le queda, la cambiamos.', 'buckler-core' ), $slim, $curvy ) );
			?>
		</p>
		<ul class="bk-xmas-home__chips">
			<li><?php echo bk_icon_svg( 'lucide:truck', 16 ); // phpcs:ignore ?><?php esc_html_e( 'Envío gratis a toda Colombia', 'buckler-core' ); ?></li>
			<li><?php echo bk_icon_svg( 'lucide:repeat', 16 ); // phpcs:ignore ?><?php esc_html_e( 'Cambio de talla', 'buckler-core' ); ?></li>
			<li><?php echo bk_icon_svg( 'lucide:credit-card', 16 ); // phpcs:ignore ?><?php esc_html_e( 'Addi: 3 cuotas sin interés', 'buckler-core' ); ?></li>
		</ul>
		<a class="bk-btn bk-xmas-home__cta" href="<?php echo esc_url( add_query_arg( 'nuevo', 1, wc_get_page_permalink( 'shop' ) ) ); ?>"><?php esc_html_e( 'Ver regalos', 'buckler-core' ); ?></a>
		<?php echo $tree( '#1f6f43', 'is-left' ) . $tree( '#2b8a57', 'is-right' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</section>
	<?php
}

/**
 * "¿Es un regalo?" en la ficha.
 */
function bk_xmas_gift() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	echo '<div class="bk-xmas-gift">' . bk_icon_svg( 'lucide:gift', 26 ) . '<p><strong>' . esc_html__( '¿Es un regalo?', 'buckler-core' ) . '</strong><span>' . esc_html__( 'Si la talla no le queda, la cambiamos (excepto prendas en SALE). Envío gratis a toda Colombia.', 'buckler-core' ) . '</span></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Saludo del pie de página.
 */
function bk_xmas_footer() {
	echo '<p class="bk-xmas-ft"><span aria-hidden="true">✨</span> ' . wp_kses( __( 'Felices fiestas te desea <em>Buckler Jeans</em>', 'buckler-core' ), array( 'em' => array() ) ) . ' <span aria-hidden="true">✨</span></p>';
}

/* ---------------------------------------------------------------------------
 * Gestión: interruptor (Contenido → Temporada)
 * ------------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	static function () {
		if ( ! function_exists( 'bk_g_can' ) ) {
			return;
		}
		register_rest_route(
			'bk/v1',
			'/gestion/season',
			array(
				array( 'methods' => 'GET', 'callback' => 'bk_xmas_rest', 'permission_callback' => 'bk_g_can' ),
				array(
					'methods'             => 'POST',
					'permission_callback' => 'bk_g_can',
					'callback'            => static function ( WP_REST_Request $r ) {
						$m = (string) $r['mode'];
						if ( ! in_array( $m, array( 'auto', 'on', 'off' ), true ) ) {
							return new WP_Error( 'bk_mode', 'Opción no válida.', array( 'status' => 400 ) );
						}
						update_option( 'bk_xmas_mode', $m, false );
						if ( function_exists( 'bk_cache_purge' ) ) {
							bk_cache_purge();
						}
						return bk_xmas_rest();
					},
				),
			)
		);
	}
);

/**
 * Estado del modo Navidad para la gestión.
 *
 * @return WP_REST_Response
 */
function bk_xmas_rest() {
	$w    = bk_xmas_window();
	$mode = bk_xmas_mode();
	$now  = bk_xmas_now();
	$on   = 'on' === $mode || ( 'auto' === $mode && $now >= $w['start'] && $now < $w['end'] );
	return rest_ensure_response(
		array(
			'mode'  => $mode,
			'on'    => $on,
			'start' => wp_date( 'j \d\e F \d\e Y', $w['start'] ),
			'end'   => wp_date( 'j \d\e F \d\e Y', $w['end'] - 1 ),
		)
	);
}
