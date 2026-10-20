<?php
/**
 * Plugin Name:       Buckler Core
 * Description:       Funciones propias de buckler.com.co: banners ACF, video de producto, iconos, protección de clics y utilidades de WooCommerce. Reemplaza plugins pesados.
 * Version:           0.18.21
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

define( 'BK_VERSION', '0.18.21' );
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
require_once BK_DIR . 'includes/modules.php';
require_once BK_DIR . 'includes/config.php';
require_once BK_DIR . 'includes/assets.php';
require_once BK_DIR . 'includes/icons.php';
require_once BK_DIR . 'includes/banners.php';
require_once BK_DIR . 'includes/product-video.php';
require_once BK_DIR . 'includes/header.php';
require_once BK_DIR . 'includes/footer.php';
require_once BK_DIR . 'includes/reviews.php';
require_once BK_DIR . 'includes/size-guide.php';
require_once BK_DIR . 'includes/security.php';
require_once BK_DIR . 'includes/fast-rest.php';
if ( bk_on( 'stats' ) ) {
	require_once BK_DIR . 'includes/stats.php';
}
if ( is_admin() && bk_on( 'adminmenu' ) ) {
	require_once BK_DIR . 'includes/admin-menu/admin-menu.php';
	require_once BK_DIR . 'includes/admin-menu/tool.php';
}
require_once BK_DIR . 'includes/woo-tweaks.php';
require_once BK_DIR . 'includes/abandoned-cart/store.php';

// Módulos que dependen de WooCommerce.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( bk_on( 'abandoned' ) ) {
			require_once BK_DIR . 'includes/abandoned-cart/capture.php';
			require_once BK_DIR . 'includes/abandoned-cart/mailer.php';
			if ( is_admin() ) {
				require_once BK_DIR . 'includes/abandoned-cart/admin.php';
			}
		}
		require_once BK_DIR . 'includes/catalog.php';
		require_once BK_DIR . 'includes/discounts.php';
		require_once BK_DIR . 'includes/sale-notice.php';
		require_once BK_DIR . 'includes/low-stock.php';
		require_once BK_DIR . 'includes/emails.php';
		if ( bk_on( 'cards' ) ) {
			require_once BK_DIR . 'includes/product-card.php';
		}
		if ( bk_on( 'sidecart' ) ) {
			require_once BK_DIR . 'includes/side-cart.php';
		}
		if ( bk_on( 'layout' ) ) {
			require_once BK_DIR . 'includes/search.php';
		}
		if ( bk_on( 'shop' ) ) {
			require_once BK_DIR . 'includes/shop.php';
		}
		if ( bk_on( 'pages' ) ) {
			require_once BK_DIR . 'includes/pages/pages.php';
			require_once BK_DIR . 'includes/pages/datalayer.php';
			require_once BK_DIR . 'includes/pages/smart-desc.php';
			if ( bk_on( 'cache' ) ) {
				require_once BK_DIR . 'includes/page-cache.php';
			}
		}
		if ( bk_on( 'gestion' ) ) {
			require_once BK_DIR . 'includes/gestion/api.php';
			require_once BK_DIR . 'includes/gestion/order-source.php';
			require_once BK_DIR . 'includes/gestion/page.php';
			require_once BK_DIR . 'includes/gestion/login.php';
			require_once BK_DIR . 'includes/gestion/admin-link.php';
			require_once BK_DIR . 'includes/gestion/pending-pay.php';
			require_once BK_DIR . 'includes/gestion/shipping.php';
			require_once BK_DIR . 'includes/gestion/product-editor.php';
			require_once BK_DIR . 'includes/gestion/orders-extra.php';
			require_once BK_DIR . 'includes/gestion/emails.php';
			require_once BK_DIR . 'includes/gestion/seo-ads.php';
			require_once BK_DIR . 'includes/gestion/content.php';
			require_once BK_DIR . 'includes/gestion/discounts-api.php';
		}
	},
	20
);

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
	bk_ac_install();
}
