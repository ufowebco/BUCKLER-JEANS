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
 * El rol "Gestor de tienda" y los usuarios de solo SEO y ADS no entran a wp-admin: van directo al dashboard.
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
		if ( bk_g_is_seo_user( $u ) && ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( home_url( '/' . BK_G_SLUG . '/#seo' ) );
			exit;
		}
	},
	1 // Antes que WooCommerce (que la mandaría a Mi cuenta).
);
add_filter(
	'login_redirect',
	static function ( $to, $requested, $user ) {
		if ( $user instanceof WP_User && bk_g_is_seo_user( $user ) ) {
			return home_url( '/' . BK_G_SLUG . '/#seo' );
		}
		return ( $user instanceof WP_User && in_array( 'bk_gestor', (array) $user->roles, true ) ) ? home_url( '/' . BK_G_SLUG . '/' ) : $to;
	},
	10,
	3
);

/**
 * URL de la gestión.
 *
 * @param array $args Parámetros.
 * @return string
 */
function bk_g_url( $args = array() ) {
	return add_query_arg( $args, home_url( '/' . BK_G_SLUG . '/' ) );
}

/**
 * IP para limitar intentos de acceso.
 *
 * @return string
 */
function bk_g_ip() {
	if ( class_exists( 'WC_Geolocation' ) ) {
		return (string) WC_Geolocation::get_ip_address();
	}
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Procesa el formulario de acceso propio. Devuelve el mensaje de error (o termina con la redirección).
 *
 * @return string
 */
function bk_g_handle_login() {
	if ( empty( $_POST['bk_g_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return '';
	}
	if ( ! isset( $_POST['_bk_g_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bk_g_nonce'] ) ), 'bk_g_login' ) ) {
		return __( 'La página estuvo abierta mucho tiempo. Escribe tus datos otra vez.', 'buckler-core' );
	}
	// Máximo 6 intentos fallidos cada 15 minutos por conexión.
	$key   = 'bk_g_fail_' . md5( bk_g_ip() );
	$fails = (int) get_transient( $key );
	if ( $fails >= 6 ) {
		return __( 'Demasiados intentos. Espera 15 minutos o recupera tu contraseña.', 'buckler-core' );
	}
	$user = wp_signon(
		array(
			'user_login'    => sanitize_user( wp_unslash( (string) ( $_POST['log'] ?? '' ) ) ),
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- contraseña: no se sanea.
			'remember'      => ! empty( $_POST['rememberme'] ),
		),
		is_ssl()
	);
	if ( is_wp_error( $user ) ) {
		$generic = array( 'invalid_username', 'incorrect_password', 'invalid_email', 'empty_username', 'empty_password', 'authentication_failed' );
		set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );
		if ( array_intersect( $user->get_error_codes(), $generic ) || ! $user->get_error_codes() ) {
			return __( 'Usuario o contraseña incorrectos.', 'buckler-core' );
		}
		// Otro plugin de seguridad (captcha, 2FA…) pidió algo que este formulario no tiene.
		return 'alt';
	}
	delete_transient( $key );
	wp_safe_redirect( bk_g_url(), 303 );
	exit;
}

/**
 * Cierra la sesión y vuelve al acceso de la gestión (no a otra página).
 */
function bk_g_handle_logout() {
	if ( empty( $_GET['salir'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( is_user_logged_in() && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bk_g_logout' ) ) {
		wp_logout();
	}
	wp_safe_redirect( bk_g_url( array( 'adios' => 1 ) ), 303 );
	exit;
}

/**
 * Pantalla de acceso propia (también para usuarios sin permiso).
 *
 * @param string $error  Mensaje de error.
 * @param bool   $denied Hay sesión pero sin permiso.
 */
function bk_g_login_page( $error = '', $denied = false ) {
	$ver   = static fn( $f ) => esc_url( BK_URL . $f . '?v=' . bk_asset_ver( $f ) );
	$logo  = (string) bk_config( 'logo_dark' );
	$alt   = 'alt' === $error;
	$bye   = ! empty( $_GET['adios'] ) && ! $error; // phpcs:ignore WordPress.Security.NonceVerification
	$login = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( (string) $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	status_header( $denied ? 403 : 200 );
	?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e( 'Entrar · Gestión de tienda · Buckler', 'buckler-core' ); ?></title>
<link rel="stylesheet" href="<?php echo $ver( 'assets/css/gestion.css' ); // phpcs:ignore ?>">
</head>
<body class="bk-g bk-g-auth">
<main class="bk-g-login" id="bk-g-main">
	<div class="bk-g-login__brand">
		<?php if ( $logo ) : ?><img src="<?php echo esc_url( home_url( $logo ) ); ?>" alt="<?php echo esc_attr( bk_config( 'brand' ) ); ?>" width="180" height="48"><?php endif; ?>
		<p><?php esc_html_e( 'Gestión de tienda', 'buckler-core' ); ?></p>
	</div>
	<section class="bk-g-login__card" aria-labelledby="bk-g-login-t">
		<?php if ( $denied ) : ?>
			<h1 id="bk-g-login-t"><?php esc_html_e( 'Tu usuario no tiene acceso', 'buckler-core' ); ?></h1>
			<p><?php echo esc_html( sprintf( /* translators: %s: usuario */ __( 'Entraste como %s, que no puede usar la gestión de la tienda. Pídele acceso a un administrador o entra con otro usuario.', 'buckler-core' ), wp_get_current_user()->display_name ) ); ?></p>
			<a class="bk-g-btn is-block" href="<?php echo esc_url( bk_g_url( array( 'salir' => 1, '_wpnonce' => wp_create_nonce( 'bk_g_logout' ) ) ) ); ?>"><?php esc_html_e( 'Entrar con otro usuario', 'buckler-core' ); ?></a>
		<?php else : ?>
			<h1 id="bk-g-login-t"><?php esc_html_e( 'Entrar', 'buckler-core' ); ?></h1>
			<?php if ( $bye ) : ?><p class="bk-g-login__ok" role="status"><?php esc_html_e( 'Cerraste sesión. ¡Hasta pronto!', 'buckler-core' ); ?></p><?php endif; ?>
			<?php if ( $error && ! $alt ) : ?><p class="bk-g-login__err" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
			<?php if ( $alt ) : ?>
				<p class="bk-g-login__err" role="alert"><?php esc_html_e( 'Tu sitio pide una verificación adicional para entrar.', 'buckler-core' ); ?> <a href="<?php echo esc_url( wp_login_url( bk_g_url() ) ); ?>"><?php esc_html_e( 'Entra aquí con el acceso de WordPress', 'buckler-core' ); ?></a>.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( bk_g_url() ); ?>" class="bk-g-login__form" novalidate>
				<?php wp_nonce_field( 'bk_g_login', '_bk_g_nonce' ); ?>
				<input type="hidden" name="bk_g_login" value="1">
				<label><span><?php esc_html_e( 'Usuario o correo', 'buckler-core' ); ?></span>
					<input type="text" name="log" value="<?php echo esc_attr( $login ); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required <?php echo $login ? '' : 'autofocus'; ?>></label>
				<label><span><?php esc_html_e( 'Contraseña', 'buckler-core' ); ?></span>
					<span class="bk-g-login__pass"><input type="password" name="pwd" autocomplete="current-password" required <?php echo $login ? 'autofocus' : ''; ?>>
					<button type="button" class="bk-g-login__eye" aria-pressed="false" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:eye', 20 ); // phpcs:ignore ?></button></span></label>
				<label class="bk-g-switch"><input type="checkbox" name="rememberme" value="1" checked><span><?php esc_html_e( 'Mantener la sesión abierta en este equipo', 'buckler-core' ); ?></span></label>
				<button type="submit" class="bk-g-btn is-block"><?php esc_html_e( 'Entrar', 'buckler-core' ); ?></button>
			</form>
			<p class="bk-g-login__links"><a href="<?php echo esc_url( wp_lostpassword_url( bk_g_url() ) ); ?>"><?php esc_html_e( '¿Olvidaste tu contraseña?', 'buckler-core' ); ?></a></p>
		<?php endif; ?>
	</section>
	<p class="bk-g-login__back"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">← <?php esc_html_e( 'Volver a la tienda', 'buckler-core' ); ?></a></p>
</main>
<script>
document.querySelector('.bk-g-login__eye')?.addEventListener('click', (e) => {
	const b = e.currentTarget, i = b.previousElementSibling, on = i.type === 'password';
	i.type = on ? 'text' : 'password';
	b.setAttribute('aria-pressed', String(on));
	b.setAttribute('aria-label', on ? 'Ocultar contraseña' : 'Mostrar contraseña');
});
document.querySelector('.bk-g-login__form')?.addEventListener('submit', (e) => {
	const f = e.currentTarget;
	if (!f.log.value.trim() || !f.pwd.value) { e.preventDefault(); (f.log.value.trim() ? f.pwd : f.log).focus(); return; }
	f.querySelector('[type="submit"]').disabled = true;
});
</script>
</body>
</html>
	<?php
	exit;
}

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

	bk_g_handle_logout();
	if ( ! is_user_logged_in() ) {
		bk_g_login_page( bk_g_handle_login() ); // Acceso propio (antes: wp-login.php).
	}
	if ( ! bk_g_can_seo() ) {
		bk_g_login_page( '', true );
	}
	$seo_only = ! bk_g_can();
	// Hoja de impresión de uno o varios pedidos: /gestion-tienda/?imprimir=123[,124].
	if ( ! $seo_only && isset( $_GET['imprimir'] ) && function_exists( 'bk_g_print_render' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		bk_g_print_render( explode( ',', sanitize_text_field( wp_unslash( $_GET['imprimir'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	$ver = static fn( $f ) => esc_url( BK_URL . $f . '?v=' . bk_asset_ver( $f ) );
	// El resumen va dentro de la página si ya está calculado (cero esperas al abrir).
	$summary = $seo_only ? false : get_transient( 'bk_g_summary' );

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
		'logout'   => bk_g_url( array( 'salir' => 1, '_wpnonce' => wp_create_nonce( 'bk_g_logout' ) ) ),
		'scope'    => $seo_only ? 'seo' : 'all',
		'site'     => home_url( '/' ),
		'printUrl' => esc_url_raw( home_url( '/' . BK_G_SLUG . '/' ) ) . '?imprimir=',
		'js'       => array(
			'product' => html_entity_decode( $ver( 'assets/js/gestion-product.js' ) ),
			'extra'   => html_entity_decode( $ver( 'assets/js/gestion-extra.js' ) ),
			'more'    => html_entity_decode( $ver( 'assets/js/gestion-more.js' ) ),
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
			'percent' => 'lucide:percent',
			'layout'  => 'lucide:image',
			'help'    => 'lucide:circle-help',
			'gift'    => 'lucide:gift',
			'info'    => 'lucide:info',
			'up'      => 'lucide:chevron-up',
			'down'    => 'lucide:chevron-down',
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
