<?php
/**
 * API rápida: instala un mu-plugin que, SOLO en las lecturas del panel /gestion-tienda/
 * (GET /wp-json/bk/v1/gestion/…) y en el contador de visitas (POST /wp-json/bk/v1/hit),
 * carga únicamente WooCommerce, buckler-core y los plugins de números/estados de pedido.
 *
 * Por qué: en producción cada petición REST tarda ~2,5 s porque WordPress carga los ~40 plugins
 * del sitio (constructores, píxeles, pasarelas…) aunque el panel no use ninguno.
 * Las escrituras (cambiar estado, enviar guía, correos, SEO) cargan TODO, para no saltarse
 * los efectos de otros plugins (facturas, píxeles de compra, correos).
 *
 * Se instala al guardar módulos (gestion o stats encendido) o al actualizar el plugin;
 * se quita al apagar esos módulos o desactivar buckler-core. Apagado manual:
 * define( 'BK_FAST_REST', false ); en wp-config.php.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_FAST_REST_FILE = 'bk-fast-rest.php';

add_action( 'bk_modules_saved', 'bk_fast_rest_sync' );
add_action(
	'admin_init',
	static function () {
		if ( get_option( 'bk_fast_rest_ver' ) !== BK_VERSION ) {
			bk_fast_rest_sync( (array) get_option( 'bk_modules', array() ) );
		}
	}
);
register_deactivation_hook( BK_FILE, 'bk_fast_rest_remove' );

/**
 * Ruta del mu-plugin.
 *
 * @return string
 */
function bk_fast_rest_path() {
	return ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/' . BK_FAST_REST_FILE;
}

/**
 * Instala o quita según los módulos.
 *
 * @param array $on Módulos encendidos.
 */
function bk_fast_rest_sync( $on ) {
	update_option( 'bk_fast_rest_ver', BK_VERSION, false );
	$want = ( ! defined( 'BK_FAST_REST' ) || BK_FAST_REST ) && array_intersect( array( 'gestion', 'stats' ), (array) $on );
	if ( $want ) {
		bk_fast_rest_install();
	} else {
		bk_fast_rest_remove();
	}
}

/**
 * Plugins que se conservan (expresión regular sobre "carpeta/archivo.php").
 * WooCommerce y buckler-core siempre; además números de pedido secuenciales y estados personalizados
 * (cambian lo que se ve en la lista) y ACF.
 *
 * @return string
 */
function bk_fast_rest_keep() {
	return (string) apply_filters( 'bk_fast_rest_keep', '#^(woocommerce/|' . preg_quote( dirname( plugin_basename( BK_FILE ) ), '#' ) . '/|advanced-custom-fields|[^/]*(sequential|order-number|ordernumber|order-status|orderstatus)[^/]*/)#i' );
}

/**
 * Escribe el mu-plugin (solo si cambió).
 *
 * @return bool
 */
function bk_fast_rest_install() {
	$path = bk_fast_rest_path();
	$code = "<?php\n/**\n * Plugin Name: Buckler Core – API rápida\n * Description: Instalado por buckler-core. En las lecturas del panel /gestion-tienda/ y en el contador de visitas carga solo WooCommerce y buckler-core. Se borra solo al apagar los módulos. Desactivar: define( 'BK_FAST_REST', false ).\n */\n"
		. "defined( 'ABSPATH' ) || exit;\n"
		. "if ( ( defined( 'BK_FAST_REST' ) && ! BK_FAST_REST ) || ! is_dir( WP_PLUGIN_DIR . '/" . dirname( plugin_basename( BK_FILE ) ) . "' ) ) { return; }\n"
		. "\$bk_uri = isset( \$_SERVER['REQUEST_URI'] ) ? (string) \$_SERVER['REQUEST_URI'] : '';\n"
		. "\$bk_m   = isset( \$_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) \$_SERVER['REQUEST_METHOD'] ) : 'GET';\n"
		. "\$bk_r   = isset( \$_GET['rest_route'] ) ? (string) \$_GET['rest_route'] : ( preg_match( '#/wp-json(/[^?]*)#', \$bk_uri, \$bk_x ) ? \$bk_x[1] : '' );\n"
		. "if ( ! ( ( 'GET' === \$bk_m && preg_match( '#^/bk/v1/gestion/(summary|orders|products|product-form|abandoned|seo/visits)#', \$bk_r ) ) || ( 'POST' === \$bk_m && '/bk/v1/hit' === rtrim( \$bk_r, '/' ) ) ) ) { return; }\n"
		. "define( 'BK_FAST_REST_ON', true );\n"
		. "add_filter( 'option_active_plugins', static function ( \$plugins ) { return array_values( array_filter( (array) \$plugins, static fn( \$p ) => (bool) preg_match( " . var_export( bk_fast_rest_keep(), true ) . ", (string) \$p ) ) ); }, 1 );\n"
		. "add_filter( 'site_option_active_sitewide_plugins', static function ( \$plugins ) { return array_filter( (array) \$plugins, static fn( \$v, \$p ) => (bool) preg_match( " . var_export( bk_fast_rest_keep(), true ) . ", (string) \$p ), ARRAY_FILTER_USE_BOTH ); }, 1 );\n";
	if ( is_readable( $path ) && file_get_contents( $path ) === $code ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}
	if ( ! wp_mkdir_p( dirname( $path ) ) ) {
		return false;
	}
	return false !== file_put_contents( $path, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Quita el mu-plugin.
 */
function bk_fast_rest_remove() {
	$path = bk_fast_rest_path();
	if ( is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * ¿Está instalado?
 *
 * @return bool
 */
function bk_fast_rest_active() {
	return is_file( bk_fast_rest_path() ) && ( ! defined( 'BK_FAST_REST' ) || BK_FAST_REST );
}
