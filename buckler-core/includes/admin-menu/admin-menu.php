<?php
/**
 * Administrador del menú de WordPress (módulo "adminmenu").
 *
 * - Diseño: menú lateral con los colores de marca (navy #1a3955 + lima #e4ec6a, leídos de los colores globales de
 *   Divi), 200 px en escritorio ≥ 961 px (nunca en tableta/celular ni con el menú contraído) y logo opcional.
 * - Organización: orden, elementos ocultos, submenús ocultos, separadores y encabezados; global y por usuario.
 *   Se aplica sobre el menú ya armado (admin_menu, prioridad máxima) y con el filtro nativo menu_order.
 * - Ocultar NO quita permisos: las páginas siguen registradas y WordPress sigue comprobando capacidades.
 * - Acceso a plugins de IA y a buckler-core: solo usuarios autorizados (IDs internos resueltos desde sus nombres de
 *   usuario). Esto SÍ es una restricción: se bloquea la URL directa (403) y las acciones de buckler-core.
 *
 * Prioridad: 1) obligatorios (Herramientas → Administrador del menú para quien lo usa; Perfil) 2) capacidades de
 * WordPress 3) ajustes del usuario 4) ajustes globales 5) menú original.
 *
 * Recuperación: define( 'BK_AM_SAFE_MODE', true ) en wp-config.php desactiva la restricción y la reorganización.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_AM_OPTION   = 'bk_am_settings';
const BK_AM_USERMETA = 'bk_am_user';
const BK_AM_PAGE     = 'bk-admin-menu';

add_action( 'admin_menu', 'bk_am_snapshot', PHP_INT_MAX - 10 );
add_action( 'admin_menu', 'bk_am_apply', PHP_INT_MAX );
add_filter( 'custom_menu_order', 'bk_am_custom_order_on' );
add_filter( 'menu_order', 'bk_am_menu_order', PHP_INT_MAX );
add_action( 'admin_init', 'bk_am_guard_pages', 1 );
add_action( 'admin_enqueue_scripts', 'bk_am_brand_assets' );
add_action( 'admin_head', 'bk_am_svg_colors', 20 );
add_action( 'adminmenu', 'bk_am_logo' );
add_action( 'admin_footer', 'bk_am_footer_js', 99 );
add_action( 'admin_notices', 'bk_am_notices' );

/* ---------------------------------------------------------------------------
 * Ajustes
 * ------------------------------------------------------------------------- */

/**
 * Ajustes del módulo con valores por defecto.
 *
 * @return array{global:array,logo:array,ai:array}
 */
function bk_am_settings() {
	$s = (array) get_option( BK_AM_OPTION, array() );
	return array(
		'global' => bk_am_clean_conf( (array) ( $s['global'] ?? array() ) ),
		'logo'   => wp_parse_args(
			(array) ( $s['logo'] ?? array() ),
			array(
				'on'    => false,
				'id'    => 0,
				'width' => 140,
				'url'   => home_url( '/' ),
			)
		),
		'ai'     => wp_parse_args(
			(array) ( $s['ai'] ?? array() ),
			array(
				'logins' => array( 'sebas_ufoweb', 'sebas_ufoweb.co' ),
				'users'  => null, // IDs internos (se resuelven la primera vez).
				'pages'  => null, // Slugs de páginas restringidas (se detectan la primera vez).
			)
		),
	);
}

/**
 * Guarda una parte de los ajustes.
 *
 * @param string $key   global|logo|ai.
 * @param array  $value Valor.
 */
function bk_am_save_part( $key, $value ) {
	$s         = (array) get_option( BK_AM_OPTION, array() );
	$s[ $key ] = $value;
	update_option( BK_AM_OPTION, $s, false );
}

/**
 * Normaliza una configuración de menú.
 *
 * @param array $c Configuración.
 * @return array{order:string[],hidden:string[],hidden_sub:string[],custom:array}
 */
function bk_am_clean_conf( $c ) {
	$ids    = static fn( $a ) => array_values( array_unique( array_filter( array_map( 'bk_am_clean_id', (array) $a ) ) ) );
	$custom = array();
	foreach ( (array) ( $c['custom'] ?? array() ) as $item ) {
		$id = bk_am_clean_id( $item['id'] ?? '' );
		if ( ! preg_match( '/^bk-(sep|head)-[a-z0-9]{4,12}$/', $id ) ) {
			continue;
		}
		$custom[ $id ] = array(
			'id'    => $id,
			'type'  => 0 === strpos( $id, 'bk-sep-' ) ? 'sep' : 'head',
			'label' => 0 === strpos( $id, 'bk-head-' ) ? mb_substr( sanitize_text_field( (string) ( $item['label'] ?? '' ) ), 0, 40 ) : '',
		);
	}
	return array(
		'order'      => $ids( $c['order'] ?? array() ),
		'hidden'     => $ids( $c['hidden'] ?? array() ),
		'hidden_sub' => $ids( $c['hidden_sub'] ?? array() ),
		'custom'     => array_values( $custom ),
	);
}

/**
 * Identificador de elemento del menú (slug de WordPress o "padre|hijo" para submenús).
 *
 * @param mixed $id Valor.
 * @return string
 */
function bk_am_clean_id( $id ) {
	$id = trim( wp_strip_all_tags( (string) $id ) );
	return strlen( $id ) > 250 ? '' : $id;
}

/**
 * Configuración que aplica a un usuario: la suya si existe; si no, la global.
 *
 * @param int $user_id Usuario.
 * @return array
 */
function bk_am_conf_for( $user_id ) {
	$own = get_user_meta( $user_id, BK_AM_USERMETA, true );
	if ( is_array( $own ) && ! empty( $own['custom_conf'] ) ) {
		return bk_am_clean_conf( $own );
	}
	return bk_am_settings()['global'];
}

/* ---------------------------------------------------------------------------
 * Usuarios autorizados (plugins de IA y buckler-core)
 * ------------------------------------------------------------------------- */

/**
 * IDs de los usuarios autorizados que existen.
 *
 * @return int[]
 */
function bk_am_ai_users() {
	$ai = bk_am_settings()['ai'];
	if ( null === $ai['users'] ) {
		// Primera vez: se buscan por nombre de usuario y se guardan sus IDs internos.
		$ids = array();
		foreach ( (array) $ai['logins'] as $login ) {
			$u = get_user_by( 'login', $login );
			if ( $u ) {
				$ids[] = (int) $u->ID;
			}
		}
		$ai['users'] = $ids;
		bk_am_save_part( 'ai', $ai );
	}
	return array_values( array_filter( array_map( 'intval', (array) $ai['users'] ), static fn( $id ) => $id > 0 && get_userdata( $id ) ) );
}

/**
 * ¿Está activa la restricción? (hay al menos un usuario autorizado existente y no está el modo seguro).
 *
 * @return bool
 */
function bk_am_restriction_on() {
	return ! ( defined( 'BK_AM_SAFE_MODE' ) && BK_AM_SAFE_MODE ) && (bool) bk_am_ai_users();
}

/**
 * ¿El usuario actual está autorizado para los plugins de IA y buckler-core?
 *
 * @return bool
 */
function bk_am_authorized() {
	return ! bk_am_restriction_on() || in_array( get_current_user_id(), bk_am_ai_users(), true );
}

/**
 * Páginas restringidas (slugs ?page=). Si nunca se configuraron, se detectan: las de buckler-core (menos las de la
 * tienda, como Carritos abandonados) y las de los plugins de IA conocidos, identificados por su carpeta.
 *
 * @return string[]
 */
function bk_am_restricted_pages() {
	$ai = bk_am_settings()['ai'];
	if ( is_array( $ai['pages'] ) ) {
		return $ai['pages'];
	}
	$pages = array();
	foreach ( bk_am_detect_plugin_pages() as $p ) {
		if ( bk_am_default_restricted( $p['plugin'], $p['slug'] ) ) {
			$pages[] = $p['slug'];
		}
	}
	if ( $pages && did_action( 'admin_menu' ) ) {
		$ai['pages'] = array_values( array_unique( $pages ) );
		bk_am_save_part( 'ai', $ai );
	}
	return array_values( array_unique( $pages ) );
}

/**
 * Restricción sugerida por defecto (el administrador la confirma en la herramienta).
 *
 * @param string $plugin Carpeta del plugin.
 * @param string $slug   Slug de la página.
 * @return bool
 */
function bk_am_default_restricted( $plugin, $slug ) {
	$ai_plugins = (array) apply_filters( 'bk_am_ai_plugin_dirs', array( 'ai-editor-divi5', 'ai-editor-divi5-pro', 'divi5-ai-editor', 'divi-5-ai-editor', 'divi-5-ai-editor-pro' ) );
	if ( in_array( $plugin, $ai_plugins, true ) ) {
		return true;
	}
	return 'buckler-core' === $plugin && ! in_array( $slug, (array) apply_filters( 'bk_am_store_pages', array( 'bk-abandoned' ) ), true );
}

/**
 * Páginas de administración propias de plugins (?page=), con la carpeta del plugin que las registra
 * (se averigua por el archivo de la función que pinta la página).
 *
 * @return array<int,array{slug:string,title:string,parent:string,plugin:string}>
 */
function bk_am_detect_plugin_pages() {
	global $_registered_pages, $wp_filter;
	$snap = $GLOBALS['bk_am_original'] ?? null;
	if ( ! $snap ) {
		return array();
	}
	$out  = array();
	$seen = array();
	$add  = static function ( $slug, $title, $parent ) use ( &$out, &$seen, $wp_filter ) {
		if ( isset( $seen[ $slug ] ) || preg_match( '/\.php/', $slug ) || 0 === strpos( $slug, 'bk-sep-' ) || 0 === strpos( $slug, 'bk-head-' ) || 0 === strpos( $slug, 'separator' ) ) {
			return;
		}
		$hook = get_plugin_page_hookname( $slug, $parent );
		$file = '';
		foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $cbs ) {
			foreach ( $cbs as $cb ) {
				try {
					$f    = $cb['function'];
					$ref  = is_array( $f ) ? new ReflectionMethod( $f[0], $f[1] ) : ( $f instanceof Closure || is_string( $f ) ? new ReflectionFunction( $f ) : null );
					$file = $ref ? (string) $ref->getFileName() : '';
				} catch ( ReflectionException $e ) {
					$file = '';
				}
				break 2;
			}
		}
		$plugin = bk_am_plugin_of_file( $file );
		$seen[ $slug ] = true;
		$out[]         = array( 'slug' => $slug, 'title' => wp_strip_all_tags( (string) $title ), 'parent' => $parent, 'plugin' => $plugin );
	};
	foreach ( $snap['menu'] as $m ) {
		$add( (string) $m[2], (string) $m[0], '' );
	}
	foreach ( $snap['submenu'] as $parent => $items ) {
		foreach ( (array) $items as $s ) {
			$add( (string) $s[2], (string) $s[0], (string) $parent );
		}
	}
	return $out;
}

/**
 * Carpeta del plugin al que pertenece un archivo (también si la carpeta es un enlace simbólico).
 *
 * @param string $file Archivo.
 * @return string Carpeta del plugin, "tema" o ''.
 */
function bk_am_plugin_of_file( $file ) {
	static $dirs = null;
	if ( ! $file ) {
		return '';
	}
	if ( null === $dirs ) {
		$dirs = array();
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $d ) {
			$real = realpath( $d );
			$dirs[ wp_normalize_path( $real ? $real : $d ) . '/' ] = basename( $d );
			$dirs[ wp_normalize_path( $d ) . '/' ]                 = basename( $d );
		}
	}
	$file = wp_normalize_path( (string) ( realpath( $file ) ? realpath( $file ) : $file ) );
	foreach ( $dirs as $path => $name ) {
		if ( 0 === strpos( $file, $path ) ) {
			return $name;
		}
	}
	return false !== strpos( $file, '/themes/' ) ? 'tema' : '';
}

/**
 * Bloquea la URL directa de las páginas restringidas y las acciones de buckler-core a quien no está autorizado.
 */
function bk_am_guard_pages() {
	if ( bk_am_authorized() ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $page && in_array( $page, bk_am_restricted_pages(), true ) ) {
		wp_die( esc_html__( 'Esta sección está reservada para los administradores técnicos del sitio.', 'buckler-core' ), esc_html__( 'Acceso restringido', 'buckler-core' ), array( 'response' => 403, 'back_link' => true ) );
	}
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $action && in_array( $action, (array) apply_filters( 'bk_am_protected_actions', array( 'bk_modules_save', 'bk_cache_purge', 'bk_am_save', 'bk_am_reset', 'bk_am_save_logo', 'bk_am_save_ai' ) ), true ) ) {
		wp_die( esc_html__( 'No tienes permiso para esta acción.', 'buckler-core' ), esc_html__( 'Acceso restringido', 'buckler-core' ), array( 'response' => 403 ) );
	}
}

/* ---------------------------------------------------------------------------
 * Aplicar al menú
 * ------------------------------------------------------------------------- */

/**
 * Copia del menú original (para la herramienta y la detección), antes de cambiar nada.
 */
function bk_am_snapshot() {
	global $menu, $submenu;
	$sorted = (array) $menu;
	// Mismo orden que WordPress al pintar: por posición numérica (las claves pueden ser "55.5").
	uksort( $sorted, static fn( $a, $b ) => (float) $a <=> (float) $b );
	if ( ! get_option( 'link_manager_enabled' ) ) {
		$sorted = array_filter( $sorted, static fn( $m ) => 'edit-tags.php?taxonomy=link_category' !== ( $m[2] ?? '' ) && 'link-manager.php' !== ( $m[2] ?? '' ) );
	}
	// Otros plugins (p. ej. WooCommerce) reordenan con el filtro nativo menu_order: se aplica igual que WordPress,
	// sin el filtro propio, para que "original" sea lo que el usuario ve hoy.
	remove_filter( 'menu_order', 'bk_am_menu_order', PHP_INT_MAX );
	remove_filter( 'custom_menu_order', 'bk_am_custom_order_on' );
	if ( apply_filters( 'custom_menu_order', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- filtro nativo.
		$slugs = array_values( array_map( static fn( $m ) => (string) $m[2], $sorted ) );
		$want  = array_flip( (array) apply_filters( 'menu_order', $slugs ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$def   = array_flip( $slugs );
		uasort(
			$sorted,
			static function ( $a, $b ) use ( $want, $def ) {
				$x = isset( $want[ $a[2] ] ) ? $want[ $a[2] ] : PHP_INT_MAX;
				$y = isset( $want[ $b[2] ] ) ? $want[ $b[2] ] : PHP_INT_MAX;
				return $x <=> $y ?: $def[ $a[2] ] <=> $def[ $b[2] ];
			}
		);
	}
	add_filter( 'custom_menu_order', 'bk_am_custom_order_on' );
	add_filter( 'menu_order', 'bk_am_menu_order', PHP_INT_MAX );
	$GLOBALS['bk_am_original'] = array( 'menu' => $sorted, 'submenu' => (array) $submenu );
}

/**
 * ¿Está activa la reorganización?
 *
 * @return bool
 */
function bk_am_active() {
	return ! ( defined( 'BK_AM_SAFE_MODE' ) && BK_AM_SAFE_MODE );
}

/**
 * Elementos que nunca se ocultan para el usuario actual.
 *
 * @return string[]
 */
function bk_am_mandatory() {
	$m = array( 'profile.php', 'users.php|profile.php' );
	if ( current_user_can( 'manage_options' ) && bk_am_authorized() ) {
		$m[] = 'tools.php';
		$m[] = 'tools.php|' . BK_AM_PAGE;
	}
	return $m;
}

/**
 * Aplica restricción, elementos personalizados y ocultos.
 */
function bk_am_apply() {
	global $menu, $submenu;
	if ( ! bk_am_active() ) {
		return;
	}
	// 1. Plugins de IA y buckler-core: fuera del menú para quien no está autorizado.
	if ( ! bk_am_authorized() ) {
		$restricted = bk_am_restricted_pages();
		foreach ( $menu as $k => $m ) {
			if ( in_array( $m[2], $restricted, true ) ) {
				unset( $menu[ $k ] );
			}
		}
		foreach ( $submenu as $parent => $items ) {
			foreach ( (array) $items as $k => $s ) {
				if ( in_array( $s[2], $restricted, true ) ) {
					unset( $submenu[ $parent ][ $k ] );
				}
			}
		}
	}
	if ( bk_am_restriction_on() ) {
		bk_am_restricted_pages(); // La primera vez guarda las páginas detectadas (la guarda de URL las usa en admin_init).
	}
	$conf      = bk_am_conf_for( get_current_user_id() );
	$mandatory = bk_am_mandatory();

	// 2. Separadores y encabezados propios (no son páginas: sin enlace real, capacidad "read").
	$key = 9000;
	foreach ( $conf['custom'] as $c ) {
		while ( isset( $menu[ $key ] ) ) {
			++$key;
		}
		if ( 'sep' === $c['type'] ) {
			$menu[ $key ] = array( '', 'read', $c['id'], '', 'wp-menu-separator bk-am-sep' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		} else {
			$menu[ $key ] = array( esc_html( $c['label'] ), 'read', '#' . $c['id'], '', 'bk-am-head', $c['id'], 'none' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	// 3. Ocultos (solo interfaz: la página sigue registrada y con sus permisos).
	foreach ( $menu as $k => $m ) {
		if ( in_array( $m[2], $conf['hidden'], true ) && ! in_array( $m[2], $mandatory, true ) ) {
			unset( $menu[ $k ] );
		}
	}
	foreach ( $submenu as $parent => $items ) {
		foreach ( (array) $items as $k => $s ) {
			$id = $parent . '|' . $s[2];
			if ( in_array( $id, $conf['hidden_sub'], true ) && ! in_array( $id, $mandatory, true ) ) {
				unset( $submenu[ $parent ][ $k ] );
			}
		}
	}
}

/**
 * Activa el orden personalizado nativo.
 *
 * @param bool $on Valor.
 * @return bool
 */
function bk_am_custom_order_on( $on ) {
	return bk_am_active() ? true : $on;
}

/**
 * Orden: el guardado; los elementos nuevos (p. ej. un plugin recién instalado) quedan detrás del elemento que
 * tenían antes en el menú original.
 *
 * @param array $order Slugs en el orden actual.
 * @return array
 */
function bk_am_menu_order( $order ) {
	if ( ! bk_am_active() || ! is_array( $order ) ) {
		return $order;
	}
	$saved = bk_am_conf_for( get_current_user_id() )['order'];
	if ( ! $saved ) {
		return $order;
	}
	$map   = static fn( $s ) => 0 === strpos( (string) $s, '#bk-head-' ) ? substr( (string) $s, 1 ) : (string) $s;
	$ids   = array_map( $map, $order );
	$final = array_values( array_intersect( $saved, $ids ) );
	foreach ( $ids as $i => $id ) {
		if ( in_array( $id, $final, true ) ) {
			continue;
		}
		$pos = false;
		for ( $j = $i - 1; $j >= 0 && false === $pos; $j-- ) {
			$pos = array_search( $ids[ $j ], $final, true );
		}
		array_splice( $final, false === $pos ? count( $final ) : $pos + 1, 0, array( $id ) );
	}
	return array_map( static fn( $id ) => 0 === strpos( $id, 'bk-head-' ) ? '#' . $id : $id, $final );
}

/* ---------------------------------------------------------------------------
 * Diseño y logo
 * ------------------------------------------------------------------------- */

/**
 * CSS del menú con la marca (solo selectores del menú lateral y de los márgenes que dependen de su ancho).
 */
function bk_am_brand_assets() {
	wp_enqueue_style( 'bk-admin-menu', BK_URL . 'assets/css/admin-menu.css', array(), bk_asset_ver( 'assets/css/admin-menu.css' ) );
	$logo = bk_am_settings()['logo'];
	if ( $logo['on'] && $logo['id'] ) {
		wp_add_inline_style( 'bk-admin-menu', '#adminmenu .bk-am-logo img{max-width:' . (int) $logo['width'] . 'px}' );
	}
}

/**
 * Colores de los iconos SVG de los plugins (WordPress los pinta con JS según el esquema de color).
 */
function bk_am_svg_colors() {
	echo '<script>window._wpColorScheme=window._wpColorScheme||{};window._wpColorScheme.icons={base:"#c9d4de",focus:"#e4ec6a",current:"#ffffff"};</script>' . "\n";
}

/**
 * Logo de marca arriba del menú (enlace al sitio en una pestaña nueva).
 */
function bk_am_logo() {
	$logo = bk_am_settings()['logo'];
	if ( ! $logo['on'] || ! $logo['id'] ) {
		return;
	}
	$img = wp_get_attachment_image( (int) $logo['id'], 'medium', false, array( 'class' => 'bk-am-logo__img', 'alt' => esc_attr( get_bloginfo( 'name' ) ), 'loading' => 'eager', 'decoding' => 'async' ) );
	if ( ! $img ) {
		return;
	}
	printf(
		'<li class="bk-am-logo"><a href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s">%3$s</a></li>',
		esc_url( $logo['url'] ? $logo['url'] : home_url( '/' ) ),
		esc_attr( sprintf( /* translators: %s: sitio */ __( 'Ver %s (se abre en una pestaña nueva)', 'buckler-core' ), get_bloginfo( 'name' ) ) ),
		$img // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image.
	);
}

/**
 * Encabezados propios: el enlace "#" se convierte en un texto (no navegable) con rol de encabezado.
 */
function bk_am_footer_js() {
	?>
	<script>
	document.querySelectorAll('#adminmenu li.bk-am-head').forEach(function (li) {
		var a = li.querySelector('a'), t = li.querySelector('.wp-menu-name');
		var s = document.createElement('span');
		s.className = 'bk-am-head__label'; s.setAttribute('role', 'heading'); s.setAttribute('aria-level', '2');
		s.textContent = t ? t.textContent : '';
		if (a) a.replaceWith(s);
		li.classList.remove('menu-top');
	});
	</script>
	<?php
}

/**
 * Avisos: modo seguro activo o ningún usuario autorizado encontrado.
 */
function bk_am_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( defined( 'BK_AM_SAFE_MODE' ) && BK_AM_SAFE_MODE ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Buckler Core: el modo seguro del Administrador del menú está activo (BK_AM_SAFE_MODE). El menú y las restricciones no se aplican.', 'buckler-core' ) . '</p></div>';
	} elseif ( ! bk_am_ai_users() ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Buckler Core: no se encontró ninguno de los usuarios autorizados para los plugins de IA. La restricción está desactivada hasta configurarlos en Herramientas → Administrador del menú.', 'buckler-core' ) . '</p></div>';
	}
}
