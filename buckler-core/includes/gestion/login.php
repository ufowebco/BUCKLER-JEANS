<?php
/**
 * Acceso: URL propia /ingreso/ (en lugar de wp-login.php, que en producción pasa por un captcha del servidor) y
 * protección + registro de redirecciones ajenas en /gestion-tienda/ e /ingreso/.
 *
 * Hallazgo 2026-10-15: varios usuarios (GestorTienda, AdminDenimBlue, Gestor_ADS) volvían al inicio al entrar a la
 * gestión. El origen es otro plugin o snippet (no visible desde fuera). Aquí:
 * - Cualquier redirección ajena hecha con wp_redirect() en esas páginas, con sesión y permiso, se ignora y se pinta
 *   la gestión (si WordPress ya terminó de cargar).
 * - Toda redirección ajena (también con header() directo) queda en el registro de Ajustes → Buckler Core con el
 *   archivo del plugin/snippet que la hizo.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ruta del acceso propio ('' lo apaga). bk_config('login_slug'), filtro bk_login_slug.
 *
 * @return string
 */
function bk_login_slug() {
	$slug = function_exists( 'bk_config' ) ? bk_config( 'login_slug' ) : null;
	$slug = null === $slug ? 'ingreso' : (string) $slug;
	return trim( sanitize_title( (string) apply_filters( 'bk_login_slug', $slug ) ), '/' );
}

/**
 * URL del acceso propio.
 *
 * @param string $redirect Destino después de entrar.
 * @return string
 */
function bk_login_url( $redirect = '' ) {
	$url = home_url( '/' . bk_login_slug() . '/' );
	return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url ) : $url;
}

/**
 * Ruta pedida, sin la carpeta de la instalación ni barras.
 *
 * @return string
 */
function bk_g_request_path() {
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( $base && '/' !== $base && 0 === strpos( $path, $base ) ) {
		$path = substr( $path, strlen( $base ) );
	}
	return trim( $path, '/' );
}

/**
 * ¿La petición es la gestión o el acceso propio?
 *
 * @return string 'gestion' | 'ingreso' | ''
 */
function bk_g_own_page() {
	$p = bk_g_request_path();
	if ( BK_G_SLUG === $p ) {
		return 'gestion';
	}
	$slug = bk_login_slug();
	return ( $slug && $slug === $p ) ? 'ingreso' : '';
}

/**
 * Redirección propia (no se registra ni se bloquea).
 *
 * @param string $url    Destino.
 * @param int    $status Código.
 */
function bk_g_go( $url, $status = 302 ) {
	$GLOBALS['bk_g_own_redirect'] = true;
	wp_safe_redirect( $url, $status );
	exit;
}

/* ============================================================================
 * /ingreso/
 * ========================================================================== */

if ( bk_login_slug() ) {
	add_action( 'parse_request', 'bk_ingreso_route', 0 );
	add_filter( 'login_url', 'bk_ingreso_login_url', 20, 3 );
}

/**
 * Los enlaces de "iniciar sesión" de WordPress (wp-admin sin sesión, etc.) llevan a /ingreso/.
 *
 * @param string $url      URL de wp-login.php.
 * @param string $redirect Destino.
 * @param bool   $force    Volver a pedir la contraseña.
 * @return string
 */
function bk_ingreso_login_url( $url, $redirect, $force ) {
	if ( ! empty( $GLOBALS['bk_wp_login_url'] ) ) { // El enlace de respaldo "acceso de WordPress".
		return $url;
	}
	$to = bk_login_url( (string) $redirect );
	return $force ? add_query_arg( 'reauth', '1', $to ) : $to;
}

/**
 * wp-login.php real (respaldo cuando otro plugin pide captcha o 2FA).
 *
 * @param string $redirect Destino.
 * @return string
 */
function bk_wp_login_url( $redirect = '' ) {
	$GLOBALS['bk_wp_login_url'] = true;
	$url                        = wp_login_url( $redirect );
	unset( $GLOBALS['bk_wp_login_url'] );
	return $url;
}

/**
 * A dónde va cada usuario después de entrar por /ingreso/.
 *
 * @param WP_User $u Usuario.
 * @return string
 */
function bk_ingreso_destination( $u ) {
	if ( ! $u instanceof WP_User || ! $u->exists() ) {
		return home_url( '/' );
	}
	$req = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( (string) $_REQUEST['redirect_to'] ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	if ( function_exists( 'bk_g_is_seo_user' ) && bk_g_is_seo_user( $u ) && ! user_can( $u, 'manage_options' ) ) {
		return bk_g_url() . '#seo';
	}
	$admin = user_can( $u, 'edit_posts' ) || user_can( $u, 'manage_woocommerce' );
	if ( ! $admin && user_can( $u, 'bk_gestion' ) ) {
		return bk_g_url(); // Gestor de tienda: no entra a wp-admin.
	}
	if ( $req && ( $admin || false === strpos( $req, '/wp-admin' ) ) ) {
		return $req;
	}
	if ( $admin ) {
		return admin_url();
	}
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
}

/**
 * Pinta /ingreso/ (mismo formulario que la gestión).
 *
 * @param WP $wp Petición.
 */
function bk_ingreso_route( $wp ) {
	$slug = bk_login_slug();
	if ( ! $slug || $slug !== $wp->request ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache', true );
	header( 'X-Robots-Tag: noindex, nofollow', true );
	$GLOBALS['bk_g_login_ctx']    = 'ingreso';
	$GLOBALS['bk_g_login_action'] = bk_login_url( isset( $_REQUEST['redirect_to'] ) ? wp_unslash( (string) $_REQUEST['redirect_to'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	if ( ! empty( $_GET['reauth'] ) && is_user_logged_in() && empty( $_POST['bk_g_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		// wp-admin no aceptó la sesión: se pide la contraseña otra vez (sin esto, ida y vuelta sin fin).
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
	}
	if ( is_user_logged_in() ) {
		bk_g_go( bk_ingreso_destination( wp_get_current_user() ) );
	}
	bk_g_login_page( bk_g_handle_login() );
}

/**
 * Destino tras un acceso correcto (gestión o /ingreso/).
 *
 * @param WP_User|null $u Usuario.
 * @return string
 */
function bk_g_login_destination( $u = null ) {
	if ( 'ingreso' !== ( $GLOBALS['bk_g_login_ctx'] ?? '' ) ) {
		return bk_g_url();
	}
	$u = $u instanceof WP_User ? $u : ( $GLOBALS['bk_g_login_user'] ?? null );
	return bk_ingreso_destination( $u );
}
add_action(
	'wp_login',
	static function ( $login, $user ) {
		$GLOBALS['bk_g_login_user'] = $user;
	},
	-9999,
	2
);

/* ============================================================================
 * Protección y registro de redirecciones ajenas
 * ========================================================================== */

bk_g_guard();

/**
 * Solo en /gestion-tienda/ e /ingreso/.
 */
function bk_g_guard() {
	if ( ! bk_g_own_page() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	$GLOBALS['bk_g_hooks'] = array();
	add_action( 'all', 'bk_g_track_hook' );
	add_filter( 'wp_redirect', 'bk_g_guard_redirect', PHP_INT_MAX, 2 );
	header_register_callback( 'bk_g_guard_headers' );
}

/**
 * Últimos ganchos ejecutados (para saber dónde ocurrió una redirección con header() directo).
 */
function bk_g_track_hook() {
	$h = current_filter();
	if ( ! empty( $GLOBALS['bk_g_hooks_frozen'] ) ) {
		return;
	}
	if ( 'shutdown' === $h ) { // Lo que viene después es el cierre de la petición, no el origen.
		$GLOBALS['bk_g_hooks_frozen'] = true;
		return;
	}
	if ( ! $h || ! did_action( $h ) ) { // Solo acciones (los filtros son ruido).
		return;
	}
	$list = &$GLOBALS['bk_g_hooks'];
	if ( end( $list ) !== $h ) {
		$list[] = $h;
		if ( count( $list ) > 8 ) {
			array_shift( $list );
		}
	}
}

/**
 * ¿El destino es la propia gestión o el acceso?
 *
 * @param string $to URL.
 * @return bool
 */
function bk_g_is_own_target( $to ) {
	$path = trim( (string) wp_parse_url( $to, PHP_URL_PATH ), '/' );
	$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( $base && 0 === strpos( $path, $base ) ) {
		$path = trim( substr( $path, strlen( $base ) ), '/' );
	}
	return BK_G_SLUG === $path || ( bk_login_slug() && bk_login_slug() === $path );
}

/**
 * Plugin, tema o snippet que llamó (primer archivo fuera de WordPress y de buckler-core).
 *
 * @return string
 */
function bk_g_redirect_caller() {
	$out = array();
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $f ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		$file = isset( $f['file'] ) ? wp_normalize_path( $f['file'] ) : '';
		if ( ! $file || false !== strpos( $file, '/wp-includes/' ) || 0 === strpos( $file, wp_normalize_path( BK_DIR ) ) || false !== strpos( $file, '/wp-admin/includes/' ) ) {
			continue;
		}
		$rel   = str_replace( wp_normalize_path( ABSPATH ), '', $file );
		$out[] = $rel . ':' . ( $f['line'] ?? '?' ) . ( ! empty( $f['function'] ) ? ' (' . $f['function'] . ')' : '' );
		if ( count( $out ) >= 3 ) {
			break;
		}
	}
	return $out ? implode( ' ← ', $out ) : '';
}

/**
 * Guarda una redirección ajena (últimas 20, opción bk_g_redirect_log).
 *
 * @param string $to  Destino.
 * @param string $how wp_redirect | header.
 * @param string $by  Quién.
 * @param bool   $fixed Se evitó y se mostró la gestión.
 */
function bk_g_redirect_log( $to, $how, $by = '', $fixed = false ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done                         = true;
	$hooks                        = array_slice( (array) ( $GLOBALS['bk_g_hooks'] ?? array() ), -5 );
	$GLOBALS['bk_g_hooks_frozen'] = true;
	try {
		$u   = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$log = get_option( 'bk_g_redirect_log', array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't'     => time(),
				'user'  => ( $u && $u->exists() ) ? $u->user_login : '',
				'page'  => '/' . bk_g_request_path() . '/',
				'post'  => 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ),
				'to'    => substr( (string) $to, 0, 200 ),
				'how'   => $how,
				'by'    => substr( (string) $by, 0, 400 ),
				'hooks' => implode( ' › ', $hooks ),
				'fixed' => $fixed,
			)
		);
		update_option( 'bk_g_redirect_log', array_slice( $log, 0, 20 ), false );
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		// Nunca romper la petición por el registro.
	}
}

/**
 * wp_redirect() ajeno en la gestión/acceso.
 *
 * @param string $to     Destino.
 * @param int    $status Código.
 * @return string
 */
function bk_g_guard_redirect( $to, $status ) {
	if ( ! $to || ! empty( $GLOBALS['bk_g_own_redirect'] ) || ! empty( $GLOBALS['bk_g_signing_in'] ) || bk_g_is_own_target( $to ) ) {
		return $to;
	}
	$GLOBALS['bk_g_logged_redirect'] = true;
	$page  = bk_g_own_page();
	$ready = did_action( 'wp_loaded' ) || ( doing_action( 'init' ) && isset( $GLOBALS['wp_filter']['init'] ) && $GLOBALS['wp_filter']['init']->current_priority() >= 10 );
	$can   = 'gestion' === $page && $ready && is_user_logged_in() && function_exists( 'bk_g_can_seo' ) && bk_g_can_seo();
	bk_g_redirect_log( $to, 'wp_redirect', bk_g_redirect_caller(), $can );
	if ( $can ) {
		remove_filter( 'wp_redirect', 'bk_g_guard_redirect', PHP_INT_MAX );
		bk_g_render( true ); // Termina la petición con la gestión.
	}
	return $to;
}

/**
 * header('Location: …') directo (sin wp_redirect): solo se registra.
 */
function bk_g_guard_headers() {
	if ( ! empty( $GLOBALS['bk_g_own_redirect'] ) || ! empty( $GLOBALS['bk_g_logged_redirect'] ) ) {
		return;
	}
	foreach ( headers_list() as $h ) {
		if ( 0 === stripos( $h, 'location:' ) ) {
			$to = trim( substr( $h, 9 ) );
			if ( ! bk_g_is_own_target( $to ) ) {
				bk_g_redirect_log( $to, 'header', bk_g_redirect_caller() );
			}
			return;
		}
	}
}

/* ============================================================================
 * Ajustes → Buckler Core: registro
 * ========================================================================== */

add_action( 'bk_modules_page_after', 'bk_g_redirect_log_box' );
add_action( 'admin_post_bk_g_redirect_log_clear', 'bk_g_redirect_log_clear' );

/**
 * Tabla del registro.
 */
function bk_g_redirect_log_box() {
	$log = get_option( 'bk_g_redirect_log', array() );
	?>
	<h2 style="margin-top:32px">Acceso a la gestión</h2>
	<p>Acceso propio: <a href="<?php echo esc_url( bk_login_url() ); ?>"><code><?php echo esc_html( bk_login_url() ); ?></code></a> (también es el destino de "iniciar sesión" de WordPress). La gestión: <a href="<?php echo esc_url( bk_g_url() ); ?>"><code><?php echo esc_html( bk_g_url() ); ?></code></a>.</p>
	<h3>Redirecciones de otros plugins en la gestión</h3>
	<?php if ( ! $log ) : ?>
		<p class="description">Sin registros: ningún otro plugin ha intentado sacar a alguien de la gestión o del acceso.</p>
	<?php else : ?>
		<p class="description">Otro plugin, el tema o un snippet intentó mandar a estos usuarios a otra página. "Origen" es el archivo que lo hizo: desactívalo o corrige ese snippet.</p>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th>Fecha</th><th>Usuario</th><th>Página</th><th>Destino</th><th>Origen</th><th>Resultado</th></tr></thead>
			<tbody>
			<?php foreach ( $log as $r ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $r['t'] ) ); ?></td>
					<td><?php echo esc_html( $r['user'] ? $r['user'] : '(sin sesión)' ); ?><?php echo ! empty( $r['post'] ) ? '<br><small>al enviar el formulario</small>' : ''; ?></td>
					<td><code><?php echo esc_html( $r['page'] ); ?></code></td>
					<td><code><?php echo esc_html( $r['to'] ); ?></code></td>
					<td><code style="white-space:normal"><?php echo esc_html( $r['by'] ? $r['by'] : 'header() directo' ); ?></code><?php echo $r['hooks'] ? '<br><small>Ganchos: ' . esc_html( $r['hooks'] ) . '</small>' : ''; ?></td>
					<td><?php echo $r['fixed'] ? 'Evitado: se mostró la gestión' : 'No se pudo evitar'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
			<?php wp_nonce_field( 'bk_g_redirect_log_clear' ); ?>
			<input type="hidden" name="action" value="bk_g_redirect_log_clear">
			<?php submit_button( 'Borrar registro', 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * Borra el registro.
 */
function bk_g_redirect_log_clear() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'bk_g_redirect_log_clear' ) ) {
		wp_die( 'Sin permiso.' );
	}
	delete_option( 'bk_g_redirect_log' );
	wp_safe_redirect( admin_url( 'options-general.php?page=bk-modules&bk_msg=' . rawurlencode( 'Registro borrado.' ) ) );
	exit;
}
