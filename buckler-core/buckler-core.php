<?php
/**
 * Plugin Name:       Buckler Core
 * Description:       Funciones propias de buckler.com.co: banners ACF, video de producto, iconos, protección de clics y utilidades de WooCommerce. Reemplaza plugins pesados.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            UFO Web
 * Text Domain:       buckler-core
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

define( 'BK_VERSION', '0.1.0' );
define( 'BK_FILE', __FILE__ );
define( 'BK_DIR', plugin_dir_path( __FILE__ ) );
define( 'BK_URL', plugin_dir_url( __FILE__ ) );

// Compatibilidad declarada con HPOS (tablas de pedidos personalizadas) y bloques de carrito/checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BK_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BK_FILE, true );
		}
	}
);

require_once BK_DIR . 'includes/helpers.php';
require_once BK_DIR . 'includes/assets.php';
require_once BK_DIR . 'includes/icons.php';
require_once BK_DIR . 'includes/banners.php';
require_once BK_DIR . 'includes/product-video.php';

if ( is_admin() ) {
	require_once BK_DIR . 'includes/admin/video-migration.php';
}

register_activation_hook( BK_FILE, 'bk_activate' );

/**
 * Al activar: detecta automáticamente la clave donde el plugin anterior guardaba el video
 * para que los videos sigan funcionando desde el primer momento.
 */
function bk_activate() {
	require_once BK_DIR . 'includes/admin/video-migration.php';
	bk_video_autodetect_legacy_key();
}
