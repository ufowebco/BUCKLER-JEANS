<?php
/**
 * Carga condicional de CSS/JS según los módulos encendidos.
 *
 * - core.css / core.js: tokens, utilidades, protección de clics y carrusel (si hay algún módulo de front).
 * - layout.css / layout.js: header y footer (módulo "layout").
 * - product-video.js: solo en la ficha y con el módulo "video".
 * Los módulos "cards" y "sidecart" encolan sus propios archivos.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'bk_enqueue_assets', 20 );

/**
 * Versión de un archivo: versión del plugin + fecha de modificación (rompe la caché al actualizar).
 *
 * @param string $rel Ruta relativa al plugin.
 * @return string
 */
function bk_asset_ver( $rel ) {
	return BK_VERSION . '.' . (int) @filemtime( BK_DIR . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/**
 * Encola los recursos del front.
 */
function bk_enqueue_assets() {
	$front = array_filter( array( 'layout', 'clicks', 'banners', 'cards', 'sidecart', 'video', 'pages' ), 'bk_on' );
	if ( ! $front ) {
		return; // Plugin instalado con todo apagado: no carga nada en el front.
	}

	wp_enqueue_style( 'bk-core', BK_URL . 'assets/css/core.css', array(), bk_asset_ver( 'assets/css/core.css' ) );
	wp_enqueue_script( 'bk-core', BK_URL . 'assets/js/core.js', array(), bk_asset_ver( 'assets/js/core.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
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

	if ( bk_on( 'layout' ) ) {
		wp_enqueue_style( 'bk-layout', BK_URL . 'assets/css/layout.css', array( 'bk-core' ), bk_asset_ver( 'assets/css/layout.css' ) );
		wp_enqueue_script( 'bk-layout', BK_URL . 'assets/js/layout.js', array( 'bk-core' ), bk_asset_ver( 'assets/js/layout.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		if ( function_exists( 'bk_search_index_url' ) ) {
			wp_localize_script(
				'bk-layout',
				'bkSearch',
				array(
					'index' => esc_url_raw( bk_search_index_url() ),
					'all'   => esc_url_raw( add_query_arg( 'post_type', 'product', home_url( '/' ) ) ),
					'i18n'  => array(
						'results' => __( '%d productos encontrados', 'buckler-core' ),
						'one'     => __( '1 producto encontrado', 'buckler-core' ),
						'none'    => __( 'No encontramos productos con esa búsqueda', 'buckler-core' ),
						'seeAll'  => __( 'Ver todos los resultados', 'buckler-core' ),
						'loading' => __( 'Buscando…', 'buckler-core' ),
					),
				)
			);
		}
	}

	if ( bk_on( 'video' ) && function_exists( 'is_product' ) && is_product() ) {
		wp_enqueue_script( 'bk-product-video', BK_URL . 'assets/js/product-video.js', array(), bk_asset_ver( 'assets/js/product-video.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}
}
