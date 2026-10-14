<?php
/**
 * Registro y carga condicional de CSS/JS.
 *
 * core.css / core.js: pequeños y globales (tokens, protección de clics, carrusel).
 * product-video.js: solo en la ficha de producto.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'bk_enqueue_assets', 20 );

/**
 * Encola los recursos del front.
 */
function bk_enqueue_assets() {
	$css_ver = BK_VERSION . '.' . filemtime( BK_DIR . 'assets/css/core.css' );
	$js_ver  = BK_VERSION . '.' . filemtime( BK_DIR . 'assets/js/core.js' );

	wp_enqueue_style( 'bk-core', BK_URL . 'assets/css/core.css', array(), $css_ver );
	wp_enqueue_style( 'bk-layout', BK_URL . 'assets/css/layout.css', array( 'bk-core' ), BK_VERSION . '.' . filemtime( BK_DIR . 'assets/css/layout.css' ) );

	wp_enqueue_script( 'bk-core', BK_URL . 'assets/js/core.js', array(), $js_ver, array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'bk-core',
		'bkCore',
		array(
			'cartApi' => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
			'i18n'    => array(
				'added'       => __( 'Añadido al carrito', 'buckler-core' ),
				'chooseSize'  => __( 'Elige tu talla para continuar.', 'buckler-core' ),
				'unavailable' => __( 'Esta talla está agotada. Elige otra.', 'buckler-core' ),
				'pause'       => __( 'Pausar banners', 'buckler-core' ),
				'play'        => __( 'Reproducir banners', 'buckler-core' ),
			),
		)
	);

	wp_enqueue_script( 'bk-layout', BK_URL . 'assets/js/layout.js', array( 'bk-core' ), BK_VERSION . '.' . filemtime( BK_DIR . 'assets/js/layout.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );

	if ( function_exists( 'is_product' ) && is_product() ) {
		wp_enqueue_script(
			'bk-product-video',
			BK_URL . 'assets/js/product-video.js',
			array(),
			BK_VERSION . '.' . filemtime( BK_DIR . 'assets/js/product-video.js' ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}
}
