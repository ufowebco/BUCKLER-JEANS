<?php
/**
 * Gestión de tienda: página independiente en /gestion-tienda/.
 *
 * - No carga el tema ni Divi: solo su CSS/JS (rápida en computador y celular).
 * - Requiere sesión iniciada y permiso (rol "Gestor de tienda" o gestor de WooCommerce).
 *   Sin sesión → login de WordPress y vuelve aquí. Sin permiso → 403.
 * - noindex y sin caché.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_G_SLUG = 'gestion-tienda';

add_action( 'init', 'bk_g_rewrite' );
add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'bk_gestion' ) ) );
add_action( 'template_redirect', 'bk_g_render', 0 );
add_action( 'bk_modules_saved', 'bk_g_activate' );

/**
 * Ruta /gestion-tienda/.
 */
function bk_g_rewrite() {
	add_rewrite_rule( '^' . BK_G_SLUG . '/?$', 'index.php?bk_gestion=1', 'top' );
}

/**
 * Al guardar módulos: crea el rol y refresca las URL (una vez).
 *
 * @param array $on Módulos encendidos.
 */
function bk_g_activate( $on ) {
	if ( ! in_array( 'gestion', (array) $on, true ) ) {
		return;
	}
	if ( ! get_role( 'bk_gestor' ) ) {
		add_role( 'bk_gestor', 'Gestor de tienda', array( 'read' => true, 'bk_gestion' => true ) );
	}
	foreach ( array( 'administrator', 'shop_manager' ) as $role ) {
		$r = get_role( $role );
		if ( $r ) {
			$r->add_cap( 'bk_gestion' );
		}
	}
	bk_g_rewrite();
	flush_rewrite_rules( false );
}

/**
 * El rol "Gestor de tienda" no entra a wp-admin: va directo al dashboard.
 */
add_action(
	'admin_init',
	static function () {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		$u = wp_get_current_user();
		if ( in_array( 'bk_gestor', (array) $u->roles, true ) && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_safe_redirect( home_url( '/' . BK_G_SLUG . '/' ) );
			exit;
		}
	},
	1 // Antes que WooCommerce (que la mandaría a Mi cuenta).
);
add_filter(
	'login_redirect',
	static function ( $to, $requested, $user ) {
		return ( $user instanceof WP_User && in_array( 'bk_gestor', (array) $user->roles, true ) ) ? home_url( '/' . BK_G_SLUG . '/' ) : $to;
	},
	10,
	3
);

/**
 * Pinta la aplicación.
 */
function bk_g_render() {
	if ( ! get_query_var( 'bk_gestion' ) ) {
		return;
	}
	// Nunca en caché: el sitio usa LiteSpeed Cache y otra capa propia (X-Ufocache, revisión 2026-10-14).
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // Convención que respetan LiteSpeed, WP Rocket, W3TC y la mayoría.
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache', true );
	header( 'X-Robots-Tag: noindex, nofollow', true );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( '/' . BK_G_SLUG . '/' ) ) );
		exit;
	}
	if ( ! bk_g_can() ) {
		wp_die( esc_html__( 'No tienes permiso para ver la gestión de la tienda.', 'buckler-core' ), esc_html__( 'Sin permiso', 'buckler-core' ), array( 'response' => 403 ) );
	}
	// Hoja de impresión de uno o varios pedidos: /gestion-tienda/?imprimir=123[,124].
	if ( isset( $_GET['imprimir'] ) && function_exists( 'bk_g_print_render' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		bk_g_print_render( explode( ',', sanitize_text_field( wp_unslash( $_GET['imprimir'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	$ver = static fn( $f ) => esc_url( BK_URL . $f . '?v=' . bk_asset_ver( $f ) );
	// El resumen va dentro de la página si ya está calculado (cero esperas al abrir).
	$summary = get_transient( 'bk_g_summary' );

	$cfg = array(
		'api'      => esc_url_raw( rest_url( 'bk/v1/gestion/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'statuses' => array_combine(
			array_map( static fn( $k ) => substr( $k, 3 ), array_keys( wc_get_order_statuses() ) ),
			array_values( wc_get_order_statuses() )
		),
		'lines'    => array_map( static fn( $l ) => $l['label'], (array) bk_config( 'lines' ) ),
		'carriers' => array_map( static fn( $c ) => $c['name'], (array) bk_config( 'carriers' ) ),
		'isAdmin'  => current_user_can( 'manage_options' ),
		'hasDispatch' => (bool) bk_g_fields()['despachado'],
		'user'     => wp_get_current_user()->display_name,
		'logout'   => wp_logout_url( home_url( '/' . BK_G_SLUG . '/' ) ),
		'site'     => home_url( '/' ),
		'printUrl' => esc_url_raw( home_url( '/' . BK_G_SLUG . '/' ) ) . '?imprimir=',
		'js'       => array(
			'product' => html_entity_decode( $ver( 'assets/js/gestion-product.js' ) ),
			'extra'   => html_entity_decode( $ver( 'assets/js/gestion-extra.js' ) ),
		),
		'boot'     => array( 'summary' => is_array( $summary ) ? $summary : null ),
		'icons'    => array_map( static fn( $n ) => bk_icon_svg( $n, 20 ), array(
			'home'    => 'lucide:package',
			'orders'  => 'lucide:shopping-bag',
			'prod'    => 'lucide:star',
			'search'  => 'lucide:search',
			'x'       => 'lucide:x',
			'check'   => 'lucide:check',
			'ext'     => 'lucide:arrow-right',
			'truck'   => 'lucide:truck',
			'mail'    => 'lucide:mail',
			'plus'    => 'lucide:plus',
			'image'   => 'lucide:image-plus',
			'video'   => 'lucide:video',
			'edit'    => 'lucide:pencil',
			'back'    => 'lucide:arrow-left',
			'copy'    => 'lucide:copy',
			'left'    => 'lucide:chevron-left',
			'right'   => 'lucide:chevron-right',
			'trash'   => 'lucide:trash-2',
			'open'    => 'lucide:external-link',
			'print'   => 'lucide:printer',
			'cart'    => 'lucide:shopping-cart',
			'mail'    => 'lucide:mail',
			'chart'   => 'lucide:bar-chart-3',
			'refresh' => 'lucide:refresh-cw',
			'send'    => 'lucide:send',
			'eye'     => 'lucide:eye',
		) ),
	);
	?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e( 'Gestión de tienda · Buckler', 'buckler-core' ); ?></title>
<link rel="stylesheet" href="<?php echo $ver( 'assets/css/gestion.css' ); // phpcs:ignore ?>">
</head>
<body class="bk-g">
<a class="bk-g-skip" href="#bk-g-main"><?php esc_html_e( 'Saltar al contenido', 'buckler-core' ); ?></a>
<div id="bk-g-app" class="bk-g-app"><p class="bk-g-boot"><?php esc_html_e( 'Cargando…', 'buckler-core' ); ?></p></div>
<script>window.bkG = <?php echo wp_json_encode( $cfg ); ?>;</script>
<script src="<?php echo $ver( 'assets/js/gestion.js' ); // phpcs:ignore ?>" defer></script>
</body>
</html>
	<?php
	exit;
}
