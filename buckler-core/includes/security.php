<?php
/**
 * Endurecimiento: no exponer nombres de usuario a visitantes sin sesión.
 *
 * En producción /wp-json/wp/v2/users era público y mostraba los nombres de usuario (p. ej. el del administrador),
 * lo que facilita los ataques de fuerza bruta. Para visitantes SIN sesión:
 * - REST /wp/v2/users y /wp/v2/users/{id} → 401 (con sesión sigue igual: el editor de bloques y los plugins
 *   que actúan como un usuario lo necesitan).
 * - Archivos de autor (/?author=N, /author/…) → 404 (la tienda no los usa).
 * - Mapa del sitio de usuarios (wp-sitemap-users-*.xml) desactivado.
 * - oEmbed sin nombre ni enlace del autor.
 * - Mensaje de acceso fallido genérico (no confirma si el usuario existe).
 * Desactivar todo: add_filter( 'bk_hide_users', '__return_false' ).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_pre_dispatch', 'bk_sec_rest_users', 10, 3 );
add_filter( 'wp_sitemaps_add_provider', 'bk_sec_sitemap_users', 10, 2 );
add_action( 'template_redirect', 'bk_sec_author_archives', 1 );
add_filter( 'oembed_response_data', 'bk_sec_oembed' );
add_filter( 'login_errors', 'bk_sec_login_errors' );

/**
 * ¿Aplicar la protección?
 *
 * @return bool
 */
function bk_sec_on() {
	return (bool) apply_filters( 'bk_hide_users', true );
}

/**
 * REST de usuarios solo con sesión.
 *
 * @param mixed           $result  Respuesta previa.
 * @param WP_REST_Server  $server  Servidor.
 * @param WP_REST_Request $request Petición.
 * @return mixed
 */
function bk_sec_rest_users( $result, $server, $request ) {
	if ( null !== $result || ! bk_sec_on() || is_user_logged_in() ) {
		return $result;
	}
	if ( preg_match( '#^/wp/v2/users(/|$)#', (string) $request->get_route() ) ) {
		return new WP_Error( 'rest_user_cannot_view', __( 'Debes iniciar sesión para ver usuarios.', 'buckler-core' ), array( 'status' => 401 ) );
	}
	return $result;
}

/**
 * Sin mapa del sitio de usuarios.
 *
 * @param WP_Sitemaps_Provider|false $provider Proveedor.
 * @param string                     $name     Nombre.
 * @return WP_Sitemaps_Provider|false
 */
function bk_sec_sitemap_users( $provider, $name ) {
	return 'users' === $name && bk_sec_on() ? false : $provider;
}

/**
 * Archivos de autor → 404 para visitantes (evita /?author=1 → /author/usuario/).
 */
function bk_sec_author_archives() {
	if ( ! bk_sec_on() || is_user_logged_in() || is_admin() ) {
		return;
	}
	if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}

/**
 * oEmbed sin datos del autor.
 *
 * @param array $data Datos.
 * @return array
 */
function bk_sec_oembed( $data ) {
	if ( bk_sec_on() ) {
		unset( $data['author_name'], $data['author_url'] );
	}
	return $data;
}

/**
 * Error de acceso genérico.
 *
 * @param string $error Mensaje.
 * @return string
 */
function bk_sec_login_errors( $error ) {
	if ( ! bk_sec_on() || empty( $GLOBALS['errors'] ) || ! is_wp_error( $GLOBALS['errors'] ) ) {
		return $error;
	}
	$codes = $GLOBALS['errors']->get_error_codes();
	if ( array_intersect( $codes, array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
		return __( '<strong>Error:</strong> el usuario o la contraseña no son correctos.', 'buckler-core' ) . ' <a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( '¿Olvidaste tu contraseña?', 'buckler-core' ) . '</a>';
	}
	return $error;
}
