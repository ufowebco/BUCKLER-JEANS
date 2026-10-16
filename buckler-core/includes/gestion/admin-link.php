<?php
/**
 * Accesos a /gestion-tienda/ desde WordPress: primer ítem del menú lateral, botón lima en la barra superior
 * (también en la tienda con sesión) y tarjeta en el Escritorio. Solo para quien puede usar la gestión.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'bk_gl_menu', 1 );
add_action( 'admin_bar_menu', 'bk_gl_bar', 31 );
add_action( 'wp_dashboard_setup', 'bk_gl_widget' );
add_action( 'admin_head', 'bk_gl_style' );
add_action( 'wp_head', 'bk_gl_style' );

/**
 * ¿Puede entrar a la gestión (completa o solo SEO y ADS)?
 *
 * @return bool
 */
function bk_gl_can() {
	return is_user_logged_in() && ( current_user_can( 'bk_gestion' ) || current_user_can( 'manage_woocommerce' ) || ( function_exists( 'bk_g_can_seo' ) && bk_g_can_seo() ) );
}

/**
 * Ítem del menú lateral (enlace directo, arriba del todo).
 */
function bk_gl_menu() {
	if ( bk_gl_can() ) {
		add_menu_page( __( 'Gestión tienda', 'buckler-core' ), __( 'Gestión tienda', 'buckler-core' ), 'read', bk_g_url(), '', 'dashicons-store', 1 );
	}
}

/**
 * Botón en la barra superior.
 *
 * @param WP_Admin_Bar $bar Barra.
 */
function bk_gl_bar( $bar ) {
	if ( ! bk_gl_can() ) {
		return;
	}
	$bar->add_node(
		array(
			'id'    => 'bk-gestion',
			'title' => '<span class="ab-icon dashicons dashicons-store" aria-hidden="true"></span>' . esc_html__( 'Ir a Gestión tienda', 'buckler-core' ),
			'href'  => bk_g_url(),
		)
	);
}

/**
 * Tarjeta en el Escritorio.
 */
function bk_gl_widget() {
	if ( ! bk_gl_can() ) {
		return;
	}
	wp_add_dashboard_widget(
		'bk_gestion_widget',
		__( 'Gestión de tienda Buckler', 'buckler-core' ),
		static function () {
			echo '<p>' . esc_html__( 'Pedidos, productos, descuentos, carritos, correos, contenido y SEO y ADS en un solo lugar, más rápido que wp-admin.', 'buckler-core' ) . '</p>';
			echo '<p><a class="button button-primary button-hero bk-gl-hero" href="' . esc_url( bk_g_url() ) . '">' . esc_html__( 'Ir a Gestión tienda', 'buckler-core' ) . ' →</a></p>';
		}
	);
	// Arriba del todo en la primera columna.
	global $wp_meta_boxes;
	$normal = $wp_meta_boxes['dashboard']['normal']['core'] ?? array();
	if ( isset( $normal['bk_gestion_widget'] ) ) {
		$wp_meta_boxes['dashboard']['normal']['core'] = array( 'bk_gestion_widget' => $normal['bk_gestion_widget'] ) + $normal; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
}

/**
 * Colores de la marca para el botón de la barra y la tarjeta.
 */
function bk_gl_style() {
	if ( ! is_admin_bar_showing() && ! is_admin() ) {
		return;
	}
	if ( ! bk_gl_can() ) {
		return;
	}
	echo '<style id="bk-gl">#wpadminbar #wp-admin-bar-bk-gestion>.ab-item{background:#e4ec6a;color:#1a3955;font-weight:700}#wpadminbar #wp-admin-bar-bk-gestion>.ab-item .ab-icon:before{color:#1a3955;top:2px}#wpadminbar #wp-admin-bar-bk-gestion>.ab-item:hover,#wpadminbar #wp-admin-bar-bk-gestion>.ab-item:focus{background:#d6de52;color:#1a3955}.bk-gl-hero.button-primary{background:#1a3955;border-color:#1a3955}.bk-gl-hero.button-primary:hover,.bk-gl-hero.button-primary:focus{background:#122a40;border-color:#122a40}</style>' . "\n";
}
