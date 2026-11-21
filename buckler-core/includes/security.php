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
 * - (2026-11-21) Reseñas y comentarios por la REST: nombre corto ("Diana N.") y sin avatar de Gravatar (su dirección
 *   lleva un código sacado del correo de la clienta). Páginas, productos y fotos por la REST sin el número del usuario
 *   que las creó.
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
add_filter( 'rest_post_dispatch', 'bk_sec_rest_people', 10, 3 );
add_action( 'rest_api_init', 'bk_sec_rest_authors', 99 );

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

/**
 * Nombre corto de una persona: "Diana Navarro" → "Diana N.".
 *
 * @param string $name Nombre.
 * @return string
 */
function bk_sec_short_name( $name ) {
	$parts = preg_split( '/\s+/u', trim( wp_strip_all_tags( (string) $name ) ) );
	$parts = array_values( array_filter( (array) $parts, 'strlen' ) );
	if ( ! $parts ) {
		return __( 'Clienta', 'buckler-core' );
	}
	$first = mb_convert_case( mb_strtolower( $parts[0] ), MB_CASE_TITLE );
	return isset( $parts[1] ) ? $first . ' ' . mb_strtoupper( mb_substr( $parts[1], 0, 1 ) ) . '.' : $first;
}

/**
 * Reseñas (Store API de Woo) y comentarios (REST de WordPress) para visitantes sin sesión: sin nombre completo, sin
 * enlace y sin avatar de Gravatar.
 *
 * @param WP_REST_Response $response Respuesta.
 * @param WP_REST_Server   $server   Servidor.
 * @param WP_REST_Request  $request  Petición.
 * @return WP_REST_Response
 */
function bk_sec_rest_people( $response, $server, $request ) {
	if ( ! bk_sec_on() || is_user_logged_in() || ! $response instanceof WP_REST_Response ) {
		return $response;
	}
	$route = (string) $request->get_route();
	$store = (bool) preg_match( '#^/wc/store(/v\d+)?/products/reviews#', $route );
	$wpcom = (bool) preg_match( '#^/wp/v2/comments#', $route );
	if ( ! $store && ! $wpcom ) {
		return $response;
	}
	$data  = $response->get_data();
	$fix   = static function ( $item ) use ( $store ) {
		if ( ! is_array( $item ) ) {
			return $item;
		}
		if ( $store ) {
			if ( isset( $item['reviewer'] ) ) {
				$item['reviewer'] = bk_sec_short_name( $item['reviewer'] );
			}
			if ( isset( $item['reviewer_avatar_urls'] ) ) {
				$item['reviewer_avatar_urls'] = (object) array();
			}
		} else {
			if ( isset( $item['author_name'] ) ) {
				$item['author_name'] = bk_sec_short_name( $item['author_name'] );
			}
			foreach ( array( 'author_url' => '', 'author_avatar_urls' => (object) array(), 'author' => 0 ) as $k => $v ) {
				if ( array_key_exists( $k, $item ) ) {
					$item[ $k ] = $v;
				}
			}
			unset( $item['_links']['author'] );
		}
		return $item;
	};
	$list = is_array( $data ) && array_is_list( $data );
	$response->set_data( $list ? array_map( $fix, $data ) : $fix( $data ) );
	return $response;
}

/**
 * Páginas, productos, fotos y demás contenidos por la REST: sin el número del usuario que los creó (ni el enlace a
 * /wp/v2/users/N) para visitantes sin sesión.
 */
function bk_sec_rest_authors() {
	if ( ! bk_sec_on() ) {
		return;
	}
	$strip = static function ( $response ) {
		if ( is_user_logged_in() || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && array_key_exists( 'author', $data ) ) {
			unset( $data['author'] );
			$response->set_data( $data );
		}
		$response->remove_link( 'author' );
		return $response;
	};
	foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
		add_filter( "rest_prepare_{$type}", $strip, 99 );
	}
}

