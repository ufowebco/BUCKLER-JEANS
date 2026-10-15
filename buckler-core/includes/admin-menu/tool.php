<?php
/**
 * Herramientas → Administrador del menú: orden (arrastrar y soltar), visibilidad, separadores, encabezados,
 * ámbito global o por usuario, logo y acceso a los plugins de IA.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'bk_am_tool_menu' );
add_action( 'admin_post_bk_am_save', 'bk_am_handle_save' );
add_action( 'admin_post_bk_am_reset', 'bk_am_handle_reset' );
add_action( 'admin_post_bk_am_save_logo', 'bk_am_handle_logo' );
add_action( 'admin_post_bk_am_save_ai', 'bk_am_handle_ai' );

/**
 * Registra la página en Herramientas.
 */
function bk_am_tool_menu() {
	$hook = add_management_page( __( 'Administrador del menú', 'buckler-core' ), __( 'Administrador del menú', 'buckler-core' ), 'manage_options', BK_AM_PAGE, 'bk_am_tool_page' );
	add_action( 'load-' . $hook, 'bk_am_tool_assets_hook' );
}

/**
 * Encola los recursos solo en la herramienta.
 */
function bk_am_tool_assets_hook() {
	add_action(
		'admin_enqueue_scripts',
		static function () {
			wp_enqueue_media();
			wp_enqueue_style( 'bk-am-tool', BK_URL . 'assets/css/admin-menu-tool.css', array( 'dashicons' ), bk_asset_ver( 'assets/css/admin-menu-tool.css' ) );
			wp_enqueue_script( 'bk-am-tool', BK_URL . 'assets/js/admin-menu-tool.js', array( 'jquery', 'jquery-ui-sortable' ), bk_asset_ver( 'assets/js/admin-menu-tool.js' ), true );
			wp_localize_script(
				'bk-am-tool',
				'bkAm',
				array(
					'i18n' => array(
						'heading'      => __( 'Nuevo encabezado', 'buckler-core' ),
						'separator'    => __( 'Separador', 'buckler-core' ),
						'remove'       => __( 'Eliminar', 'buckler-core' ),
						'confirmReset' => __( '¿Restablecer este menú a su configuración predeterminada? Se perderán el orden, los ocultos, los encabezados y los separadores de este ámbito.', 'buckler-core' ),
						'confirmDel'   => __( '¿Eliminar este elemento personalizado?', 'buckler-core' ),
						'chooseLogo'   => __( 'Elegir logo', 'buckler-core' ),
						'useLogo'      => __( 'Usar este logo', 'buckler-core' ),
						'unsaved'      => __( 'Hay cambios sin guardar.', 'buckler-core' ),
						'moved'        => __( 'Elemento movido a la posición %d.', 'buckler-core' ),
					),
				)
			);
		}
	);
}

/**
 * Usuario elegido en el selector (por su "nicename", para no mostrar IDs internos en la interfaz).
 *
 * @return WP_User|null
 */
function bk_am_tool_target_user() {
	$nice = isset( $_GET['usuario'] ) ? sanitize_title( wp_unslash( $_GET['usuario'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $nice ) {
		return null;
	}
	$u = get_user_by( 'slug', $nice );
	return $u instanceof WP_User ? $u : null;
}

/**
 * Título legible de un elemento (sin contadores ni HTML).
 *
 * @param string $title Título del menú.
 * @return string
 */
function bk_am_title( $title ) {
	return trim( wp_strip_all_tags( (string) preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) $title ) ) );
}

/**
 * Elementos del menú original en el orden de una configuración, con sus submenús.
 *
 * @param array        $conf Configuración.
 * @param WP_User|null $user Usuario (para marcar lo que no puede ver por permisos).
 * @return array
 */
function bk_am_tool_items( $conf, $user ) {
	$snap  = $GLOBALS['bk_am_original'] ?? array( 'menu' => array(), 'submenu' => array() );
	$items = array();
	foreach ( $snap['menu'] as $m ) {
		$id  = (string) $m[2];
		$sep = false !== strpos( (string) ( $m[4] ?? '' ), 'wp-menu-separator' );
		$sub = array();
		foreach ( (array) ( $snap['submenu'][ $id ] ?? array() ) as $s ) {
			$sub[] = array(
				'id'      => $id . '|' . $s[2],
				'title'   => bk_am_title( $s[0] ),
				'allowed' => ! $user || user_can( $user, $s[1] ),
			);
		}
		$items[ $id ] = array(
			'id'      => $id,
			'type'    => $sep ? 'wpsep' : 'item',
			'title'   => $sep ? __( 'Separador de WordPress', 'buckler-core' ) : bk_am_title( $m[0] ),
			'icon'    => (string) ( $m[6] ?? '' ),
			'cap'     => (string) $m[1],
			'allowed' => ! $user || user_can( $user, $m[1] ),
			'sub'     => $sub,
		);
	}
	foreach ( $conf['custom'] as $c ) {
		$items[ $c['id'] ] = array( 'id' => $c['id'], 'type' => $c['type'], 'title' => $c['label'], 'icon' => '', 'cap' => 'read', 'allowed' => true, 'sub' => array() );
	}
	// Orden: el guardado + los que no estaban (en su posición original).
	$ids = array_keys( $items );
	if ( $conf['order'] ) {
		$GLOBALS['bk_am_tool_order'] = $conf['order'];
		$final                       = array_values( array_intersect( $conf['order'], $ids ) );
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
		$ids = $final;
	}
	return array_map( static fn( $id ) => $items[ $id ], $ids );
}

/**
 * Icono del elemento para la lista y la vista previa.
 *
 * @param string $icon Icono del menú (dashicons-*, data:image/svg o URL).
 * @return string
 */
function bk_am_icon_html( $icon ) {
	if ( 0 === strpos( $icon, 'dashicons-' ) ) {
		return '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
	}
	if ( 0 === strpos( $icon, 'data:image/svg' ) || 0 === strpos( $icon, 'http' ) ) {
		return '<img class="bk-am-ico" src="' . esc_attr( $icon ) . '" alt="" width="20" height="20">';
	}
	return '<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>';
}

/**
 * Pantalla.
 */
function bk_am_tool_page() {
	if ( ! current_user_can( 'manage_options' ) || ! bk_am_authorized() ) {
		wp_die( esc_html__( 'No tienes permiso para esta sección.', 'buckler-core' ), 403 );
	}
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'menu'; // phpcs:ignore WordPress.Security.NonceVerification
	$msg  = isset( $_GET['bk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bk_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$err  = isset( $_GET['bk_err'] ) ? sanitize_text_field( wp_unslash( $_GET['bk_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$base = admin_url( 'tools.php?page=' . BK_AM_PAGE );
	?>
	<div class="wrap bk-am-tool">
		<h1><?php esc_html_e( 'Administrador del menú', 'buckler-core' ); ?></h1>
		<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<?php if ( $err ) : ?><div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>
		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secciones', 'buckler-core' ); ?>">
			<?php foreach ( array( 'menu' => __( 'Menú', 'buckler-core' ), 'logo' => __( 'Logo', 'buckler-core' ), 'ia' => __( 'Acceso a plugins de IA', 'buckler-core' ) ) as $k => $label ) : ?>
				<a class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $k, $base ) ); ?>" <?php echo $tab === $k ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'logo' === $tab ) {
			bk_am_tool_logo();
		} elseif ( 'ia' === $tab ) {
			bk_am_tool_ai();
		} else {
			bk_am_tool_menu_tab();
		}
		?>
	</div>
	<?php
}

/**
 * Pestaña Menú.
 */
function bk_am_tool_menu_tab() {
	$user  = bk_am_tool_target_user();
	$own   = $user ? get_user_meta( $user->ID, BK_AM_USERMETA, true ) : null;
	$own   = is_array( $own ) && ! empty( $own['custom_conf'] );
	$conf  = $user && $own ? bk_am_clean_conf( (array) get_user_meta( $user->ID, BK_AM_USERMETA, true ) ) : bk_am_settings()['global'];
	$items = bk_am_tool_items( $conf, $user );
	$mand  = array( 'profile.php', 'users.php|profile.php', 'tools.php', 'tools.php|' . BK_AM_PAGE );
	$known = array();
	foreach ( $items as $it ) {
		$known[] = $it['id'];
		foreach ( $it['sub'] as $s ) {
			$known[] = $s['id'];
		}
	}
	$stale = count( array_diff( array_merge( $conf['order'], $conf['hidden'], $conf['hidden_sub'] ), $known ) );
	$users = get_users( array( 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_login', 'user_nicename' ) ) );
	?>
	<form method="get" class="bk-am-scope" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( BK_AM_PAGE ); ?>">
		<fieldset>
			<legend><?php esc_html_e( 'Ámbito', 'buckler-core' ); ?></legend>
			<label><input type="radio" name="ambito" value="global" <?php checked( ! $user ); ?> data-bk-scope> <?php esc_html_e( 'Configuración global (todos los usuarios sin ajuste propio)', 'buckler-core' ); ?></label>
			<label><input type="radio" name="ambito" value="usuario" <?php checked( (bool) $user ); ?> data-bk-scope> <?php esc_html_e( 'Usuario específico', 'buckler-core' ); ?></label>
			<label class="bk-am-user"><span class="screen-reader-text"><?php esc_html_e( 'Usuario', 'buckler-core' ); ?></span>
				<select name="usuario" data-bk-user>
					<option value=""><?php esc_html_e( '— Elige un usuario —', 'buckler-core' ); ?></option>
					<?php foreach ( $users as $u ) : ?>
						<?php $roles = implode( ', ', array_map( 'translate_user_role', array_map( static fn( $r ) => wp_roles()->roles[ $r ]['name'] ?? $r, get_userdata( $u->ID )->roles ) ) ); ?>
						<option value="<?php echo esc_attr( $u->user_nicename ); ?>" <?php selected( $user && (int) $user->ID === (int) $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ( $roles ? ' · ' . $roles : '' ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button class="button"><?php esc_html_e( 'Cargar', 'buckler-core' ); ?></button>
		</fieldset>
	</form>

	<div class="bk-am-help">
		<p><strong><?php esc_html_e( 'Ocultar no es restringir.', 'buckler-core' ); ?></strong> <?php esc_html_e( 'Ocultar quita el enlace del menú, pero la página sigue existiendo y quien tenga permiso puede abrirla por su dirección. Los permisos reales los da el rol de WordPress: un elemento marcado como "Sin permiso" no se verá aunque esté visible aquí.', 'buckler-core' ); ?></p>
		<?php if ( $user ) : ?>
			<p><?php echo $own ? esc_html__( 'Este usuario tiene una organización propia (tiene prioridad sobre la global).', 'buckler-core' ) : esc_html__( 'Este usuario usa la configuración global. Al guardar, se le crea una organización propia.', 'buckler-core' ); ?></p>
		<?php endif; ?>
		<?php if ( $stale ) : ?>
			<?php /* translators: %d: número de ajustes */ ?>
			<p><?php echo esc_html( sprintf( _n( 'Hay %d ajuste de un elemento que ya no existe (p. ej. un plugin desactivado o que cambió de nombre). Se ignora y se limpia al guardar.', 'Hay %d ajustes de elementos que ya no existen (p. ej. plugins desactivados o que cambiaron de nombre). Se ignoran y se limpian al guardar.', $stale, 'buckler-core' ), $stale ) ); ?></p>
		<?php endif; ?>
	</div>

	<div class="bk-am-cols">
		<div class="bk-am-editor">
			<div class="bk-am-toolbar">
				<button type="button" class="button" data-bk-add="head"><span class="dashicons dashicons-heading" aria-hidden="true"></span> <?php esc_html_e( 'Añadir encabezado', 'buckler-core' ); ?></button>
				<button type="button" class="button" data-bk-add="sep"><span class="dashicons dashicons-minus" aria-hidden="true"></span> <?php esc_html_e( 'Añadir separador', 'buckler-core' ); ?></button>
			</div>
			<p class="description" id="bk-am-dnd-help"><?php esc_html_e( 'Arrastra por el asa para reordenar, o enfoca el asa y usa las flechas arriba/abajo del teclado.', 'buckler-core' ); ?></p>
			<ul id="bk-am-list" class="bk-am-list" aria-describedby="bk-am-dnd-help">
				<?php foreach ( $items as $it ) : ?>
					<?php
					$is_mand = in_array( $it['id'], $mand, true );
					$hidden  = in_array( $it['id'], $conf['hidden'], true ) && ! $is_mand;
					?>
					<li class="bk-am-row is-<?php echo esc_attr( $it['type'] ); ?><?php echo $hidden ? ' is-hidden' : ''; ?>" data-id="<?php echo esc_attr( $it['id'] ); ?>" data-type="<?php echo esc_attr( $it['type'] ); ?>" data-icon="<?php echo esc_attr( 'item' === $it['type'] ? $it['icon'] : '' ); ?>">
						<button type="button" class="bk-am-handle" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: elemento */ __( 'Mover %s', 'buckler-core' ), $it['title'] ? $it['title'] : __( 'separador', 'buckler-core' ) ) ); ?>"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
						<?php if ( 'head' === $it['type'] ) : ?>
							<label class="bk-am-title"><span class="screen-reader-text"><?php esc_html_e( 'Texto del encabezado', 'buckler-core' ); ?></span><input type="text" class="bk-am-head-input" value="<?php echo esc_attr( $it['title'] ); ?>" maxlength="40"></label>
							<span class="bk-am-badge is-custom"><?php esc_html_e( 'Encabezado', 'buckler-core' ); ?></span>
							<button type="button" class="button-link bk-am-del"><?php esc_html_e( 'Eliminar', 'buckler-core' ); ?></button>
						<?php elseif ( 'sep' === $it['type'] ) : ?>
							<span class="bk-am-title bk-am-sep-line"><?php esc_html_e( 'Separador', 'buckler-core' ); ?></span>
							<span class="bk-am-badge is-custom"><?php esc_html_e( 'Propio', 'buckler-core' ); ?></span>
							<button type="button" class="button-link bk-am-del"><?php esc_html_e( 'Eliminar', 'buckler-core' ); ?></button>
						<?php else : ?>
							<span class="bk-am-icon"><?php echo 'item' === $it['type'] ? bk_am_icon_html( $it['icon'] ) : ''; // phpcs:ignore ?></span>
							<span class="bk-am-title"><?php echo esc_html( $it['title'] ); ?> <code><?php echo esc_html( $it['id'] ); ?></code></span>
							<?php if ( $is_mand ) : ?><span class="bk-am-badge is-lock"><?php esc_html_e( 'Obligatorio', 'buckler-core' ); ?></span><?php endif; ?>
							<?php if ( ! $it['allowed'] ) : ?><span class="bk-am-badge is-nocap"><?php esc_html_e( 'Sin permiso', 'buckler-core' ); ?></span><?php endif; ?>
							<label class="bk-am-vis"><input type="checkbox" class="bk-am-visible" <?php checked( ! $hidden ); ?> <?php disabled( $is_mand ); ?>> <?php esc_html_e( 'Visible', 'buckler-core' ); ?></label>
							<?php if ( $it['sub'] ) : ?>
								<details class="bk-am-subs">
									<?php /* translators: %d: número de submenús */ ?>
									<summary><?php echo esc_html( sprintf( _n( '%d submenú', '%d submenús', count( $it['sub'] ), 'buckler-core' ), count( $it['sub'] ) ) ); ?></summary>
									<ul>
										<?php foreach ( $it['sub'] as $s ) : ?>
											<?php $s_mand = in_array( $s['id'], $mand, true ); ?>
											<li><label><input type="checkbox" class="bk-am-sub" data-id="<?php echo esc_attr( $s['id'] ); ?>" <?php checked( $s_mand || ! in_array( $s['id'], $conf['hidden_sub'], true ) ); ?> <?php disabled( $s_mand ); ?>> <?php echo esc_html( $s['title'] ); ?></label>
												<?php if ( ! $s['allowed'] ) : ?><span class="bk-am-badge is-nocap"><?php esc_html_e( 'Sin permiso', 'buckler-core' ); ?></span><?php endif; ?>
												<?php if ( $s_mand ) : ?><span class="bk-am-badge is-lock"><?php esc_html_e( 'Obligatorio', 'buckler-core' ); ?></span><?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="bk-am-live screen-reader-text" role="status" aria-live="polite" data-bk-live></div>
			<div class="bk-am-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bk-save>
					<?php wp_nonce_field( 'bk_am_save' ); ?>
					<input type="hidden" name="action" value="bk_am_save">
					<input type="hidden" name="usuario" value="<?php echo esc_attr( $user ? $user->user_nicename : '' ); ?>">
					<input type="hidden" name="data" value="" data-bk-data>
					<?php submit_button( __( 'Guardar cambios', 'buckler-core' ), 'primary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bk-reset>
					<?php wp_nonce_field( 'bk_am_reset' ); ?>
					<input type="hidden" name="action" value="bk_am_reset">
					<input type="hidden" name="usuario" value="<?php echo esc_attr( $user ? $user->user_nicename : '' ); ?>">
					<?php submit_button( $user ? __( 'Restablecer este usuario (usará la global)', 'buckler-core' ) : __( 'Restablecer menú global', 'buckler-core' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<aside class="bk-am-preview-wrap" aria-labelledby="bk-am-prev-t">
			<h2 id="bk-am-prev-t"><?php esc_html_e( 'Vista previa', 'buckler-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Así se verá el menú (sin contar los permisos de cada rol).', 'buckler-core' ); ?></p>
			<ul class="bk-am-preview" data-bk-preview aria-hidden="true"></ul>
		</aside>
	</div>
	<?php
}

/**
 * Pestaña Logo.
 */
function bk_am_tool_logo() {
	$logo = bk_am_settings()['logo'];
	$src  = $logo['id'] ? wp_get_attachment_image_url( (int) $logo['id'], 'medium' ) : '';
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bk-am-form">
		<?php wp_nonce_field( 'bk_am_save_logo' ); ?>
		<input type="hidden" name="action" value="bk_am_save_logo">
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Mostrar logo', 'buckler-core' ); ?></th><td><label><input type="checkbox" name="on" value="1" <?php checked( $logo['on'] ); ?>> <?php esc_html_e( 'Mostrar el logo arriba del menú lateral', 'buckler-core' ); ?></label></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Imagen', 'buckler-core' ); ?></th><td>
				<div class="bk-am-logo-prev" data-bk-logo-prev><?php echo $src ? '<img src="' . esc_url( $src ) . '" alt="">' : ''; ?></div>
				<input type="hidden" name="id" value="<?php echo (int) $logo['id']; ?>" data-bk-logo-id>
				<button type="button" class="button" data-bk-logo-pick><?php esc_html_e( 'Elegir de la Mediateca', 'buckler-core' ); ?></button>
				<button type="button" class="button-link" data-bk-logo-clear <?php echo $src ? '' : 'hidden'; ?>><?php esc_html_e( 'Quitar', 'buckler-core' ); ?></button>
				<p class="description"><?php esc_html_e( 'Recomendado: logo horizontal claro (para fondo azul), PNG, WebP o SVG. Se ajusta sin deformarse.', 'buckler-core' ); ?></p>
			</td></tr>
			<tr><th scope="row"><label for="bk-am-w"><?php esc_html_e( 'Ancho máximo', 'buckler-core' ); ?></label></th><td><input id="bk-am-w" type="number" name="width" min="60" max="176" step="2" value="<?php echo (int) $logo['width']; ?>" class="small-text"> px <p class="description"><?php esc_html_e( 'Entre 60 y 176 px. Con el menú contraído se reduce solo.', 'buckler-core' ); ?></p></td></tr>
			<tr><th scope="row"><label for="bk-am-url"><?php esc_html_e( 'Enlace', 'buckler-core' ); ?></label></th><td><input id="bk-am-url" type="url" name="url" value="<?php echo esc_attr( $logo['url'] ); ?>" class="regular-text"><p class="description"><?php esc_html_e( 'Se abre en una pestaña nueva (con rel="noopener noreferrer"). Por defecto, el sitio público.', 'buckler-core' ); ?></p></td></tr>
		</table>
		<?php submit_button( __( 'Guardar logo', 'buckler-core' ) ); ?>
	</form>
	<?php
}

/**
 * Pestaña Acceso a plugins de IA.
 */
function bk_am_tool_ai() {
	$ai      = bk_am_settings()['ai'];
	$ids     = bk_am_ai_users();
	$pages   = bk_am_restricted_pages();
	$by      = array();
	foreach ( bk_am_detect_plugin_pages() as $p ) {
		$by[ $p['plugin'] ? $p['plugin'] : __( '(sin identificar)', 'buckler-core' ) ][] = $p;
	}
	ksort( $by );
	?>
	<div class="bk-am-help">
		<p><strong><?php esc_html_e( 'Esto sí restringe el acceso.', 'buckler-core' ); ?></strong> <?php esc_html_e( 'Las páginas marcadas solo aparecen en el menú de los usuarios autorizados, y cualquier otra persona recibe "Acceso restringido" aunque escriba la dirección. Las acciones de buckler-core (guardar módulos, vaciar caché, este administrador) también se bloquean. Las capacidades de WordPress no se modifican.', 'buckler-core' ); ?></p>
		<p><?php esc_html_e( 'Si te quedas sin acceso, añade define( \'BK_AM_SAFE_MODE\', true ); en wp-config.php para desactivar la restricción temporalmente.', 'buckler-core' ); ?></p>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bk-am-form">
		<?php wp_nonce_field( 'bk_am_save_ai' ); ?>
		<input type="hidden" name="action" value="bk_am_save_ai">
		<h2><?php esc_html_e( 'Usuarios autorizados', 'buckler-core' ); ?></h2>
		<p><label for="bk-am-logins"><?php esc_html_e( 'Nombres de usuario (separados por coma). Se guardan los usuarios internos que correspondan; si cambian el nombre, siguen autorizados.', 'buckler-core' ); ?></label><br>
			<input id="bk-am-logins" type="text" name="logins" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) $ai['logins'] ) ); ?>"></p>
		<ul class="bk-am-users">
			<?php foreach ( (array) $ai['logins'] as $login ) : ?>
				<?php $u = get_user_by( 'login', $login ); ?>
				<li><?php echo $u && in_array( (int) $u->ID, $ids, true ) ? '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html( $login . ' — ' . $u->display_name ) . ' <em>' . esc_html__( 'autorizado', 'buckler-core' ) . '</em>' : '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( $login ) . ' <em>' . esc_html__( 'no existe en este sitio', 'buckler-core' ) . '</em>'; ?></li>
			<?php endforeach; ?>
		</ul>
		<h2><?php esc_html_e( 'Páginas solo para usuarios autorizados', 'buckler-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Agrupadas por el plugin que registra cada página (se identifica por su carpeta, no por palabras del título). Se marcaron de entrada las de buckler-core (menos las de la tienda) y las del editor con IA de Divi.', 'buckler-core' ); ?></p>
		<?php foreach ( $by as $plugin => $list ) : ?>
			<fieldset class="bk-am-plugin">
				<legend><?php echo esc_html( $plugin ); ?></legend>
				<?php foreach ( $list as $p ) : ?>
					<label><input type="checkbox" name="pages[]" value="<?php echo esc_attr( $p['slug'] ); ?>" <?php checked( in_array( $p['slug'], $pages, true ) ); ?>> <?php echo esc_html( $p['title'] ); ?> <code><?php echo esc_html( $p['slug'] ); ?></code></label>
				<?php endforeach; ?>
			</fieldset>
		<?php endforeach; ?>
		<?php submit_button( __( 'Guardar acceso', 'buckler-core' ) ); ?>
	</form>
	<?php
}

/* ---------------------------------------------------------------------------
 * Guardado
 * ------------------------------------------------------------------------- */

/**
 * Comprobaciones comunes: permiso, autorización y nonce.
 *
 * @param string $nonce Acción del nonce.
 */
function bk_am_check( $nonce ) {
	if ( ! current_user_can( 'manage_options' ) || ! bk_am_authorized() ) {
		wp_die( esc_html__( 'No tienes permiso para esta acción.', 'buckler-core' ), 403 );
	}
	check_admin_referer( $nonce );
}

/**
 * Redirige de vuelta con un mensaje.
 *
 * @param array $args Parámetros (tab, usuario, bk_msg | bk_err).
 */
function bk_am_back( $args ) {
	wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', array_filter( $args ) ), admin_url( 'tools.php?page=' . BK_AM_PAGE ) ) );
	exit;
}

/**
 * Usuario del formulario (por nicename).
 *
 * @return WP_User|null|false Null = global; false = usuario inválido.
 */
function bk_am_post_user() {
	$nice = isset( $_POST['usuario'] ) ? sanitize_title( wp_unslash( $_POST['usuario'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $nice ) {
		return null;
	}
	$u = get_user_by( 'slug', $nice );
	return $u instanceof WP_User ? $u : false;
}

/**
 * Guarda el menú (global o de un usuario).
 */
function bk_am_handle_save() {
	bk_am_check( 'bk_am_save' );
	$user = bk_am_post_user();
	if ( false === $user ) {
		bk_am_back( array( 'bk_err' => __( 'El usuario no existe.', 'buckler-core' ) ) );
	}
	$data = json_decode( (string) wp_unslash( $_POST['data'] ?? '' ), true ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- se limpia en bk_am_clean_conf().
	if ( ! is_array( $data ) ) {
		bk_am_back( array( 'usuario' => $user ? $user->user_nicename : '', 'bk_err' => __( 'No se recibieron los cambios. Inténtalo de nuevo.', 'buckler-core' ) ) );
	}
	$conf = bk_am_clean_conf( $data );
	if ( $user ) {
		update_user_meta( $user->ID, BK_AM_USERMETA, array_merge( $conf, array( 'custom_conf' => 1 ) ) );
	} else {
		bk_am_save_part( 'global', $conf );
	}
	bk_am_back( array( 'usuario' => $user ? $user->user_nicename : '', 'bk_msg' => __( 'Menú guardado.', 'buckler-core' ) ) );
}

/**
 * Restablece el menú global o el de un usuario.
 */
function bk_am_handle_reset() {
	bk_am_check( 'bk_am_reset' );
	$user = bk_am_post_user();
	if ( $user ) {
		delete_user_meta( $user->ID, BK_AM_USERMETA );
		bk_am_back( array( 'usuario' => $user->user_nicename, 'bk_msg' => __( 'Este usuario vuelve a usar la configuración global.', 'buckler-core' ) ) );
	}
	bk_am_save_part( 'global', bk_am_clean_conf( array() ) );
	bk_am_back( array( 'bk_msg' => __( 'Menú global restablecido al orden original de WordPress.', 'buckler-core' ) ) );
}

/**
 * Guarda el logo.
 */
function bk_am_handle_logo() {
	bk_am_check( 'bk_am_save_logo' );
	$id  = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	bk_am_save_part(
		'logo',
		array(
			'on'    => ! empty( $_POST['on'] ) && $id && wp_attachment_is_image( $id ), // phpcs:ignore WordPress.Security.NonceVerification
			'id'    => $id && wp_attachment_is_image( $id ) ? $id : 0,
			'width' => min( 176, max( 60, absint( $_POST['width'] ?? 140 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'url'   => wp_http_validate_url( $url ) ? $url : home_url( '/' ),
		)
	);
	bk_am_back( array( 'tab' => 'logo', 'bk_msg' => __( 'Logo guardado.', 'buckler-core' ) ) );
}

/**
 * Guarda los usuarios autorizados y las páginas restringidas.
 */
function bk_am_handle_ai() {
	bk_am_check( 'bk_am_save_ai' );
	$logins = array_values( array_unique( array_filter( array_map( 'sanitize_user', array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['logins'] ?? '' ) ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$ids    = array();
	foreach ( $logins as $l ) {
		$u = get_user_by( 'login', $l );
		if ( $u ) {
			$ids[] = (int) $u->ID;
		}
	}
	// Quien guarda debe seguir autorizado (evita quedarse fuera).
	if ( bk_am_restriction_on() && ! in_array( get_current_user_id(), $ids, true ) ) {
		bk_am_back( array( 'tab' => 'ia', 'bk_err' => __( 'Tu propio usuario debe estar en la lista de autorizados.', 'buckler-core' ) ) );
	}
	$pages = array_values( array_unique( array_filter( array_map( 'bk_am_clean_id', (array) wp_unslash( $_POST['pages'] ?? array() ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$pages = array_values( array_diff( $pages, array( BK_AM_PAGE ) ) ); // El administrador del menú siempre lo ve quien está autorizado (obligatorio).
	$pages[] = BK_AM_PAGE;
	bk_am_save_part( 'ai', array( 'logins' => $logins, 'users' => $ids, 'pages' => $pages ) );
	bk_am_back( array( 'tab' => 'ia', 'bk_msg' => $ids ? __( 'Acceso guardado.', 'buckler-core' ) : __( 'Guardado, pero ningún usuario existe: la restricción queda desactivada.', 'buckler-core' ) ) );
}
