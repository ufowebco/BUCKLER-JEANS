<?php
/**
 * Módulos de Buckler Core: cada función se enciende o apaga desde Ajustes → Buckler Core.
 *
 * Todos vienen APAGADOS al instalar: el plugin se puede subir a producción sin cambiar nada visible y
 * encender un módulo a la vez (y apagarlo con un clic si algo no se ve bien).
 * Cada módulo activo añade la clase `bk-m-{clave}` al <body> para que su CSS/JS solo actúe entonces.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo de módulos.
 *
 * @return array<string,array{label:string,desc:string,replaces:string,woo:bool}>
 */
function bk_modules() {
	return array(
		'layout'    => array(
			'label'    => 'Header y footer',
			'desc'     => 'Shortcodes [bk_header] y [bk_footer], enlace "Saltar al contenido", header fijo y foco visible accesible.',
			'replaces' => 'Header y footer actuales del Theme Builder',
			'woo'      => false,
		),
		'clicks'    => array(
			'label'    => 'Protección de clics',
			'desc'     => 'Evita rage clicks y dead clicks: botones con estado de carga, sin doble envío, tarjeta completa clicable y aviso de talla sin alert().',
			'replaces' => '—',
			'woo'      => false,
		),
		'banners'   => array(
			'label'    => 'Banners ACF',
			'desc'     => 'Shortcodes [bk_hero] y [bk_lineas] a ancho completo con imagen PC y móvil.',
			'replaces' => 'Módulos de código del inicio',
			'woo'      => false,
		),
		'cards'     => array(
			'label'    => 'Tarjeta de producto con slider',
			'desc'     => 'Galería deslizable en cada producto de la grilla, tallas disponibles, línea, etiqueta Nuevo (producto destacado), descuento y agotado. Shortcode [bk_productos].',
			'replaces' => 'Divi Essential (slider de la grilla), snippet favorito → Nuevo',
			'woo'      => true,
		),
		'shop'      => array(
			'label'    => 'Tienda con filtros',
			'desc'     => 'Shortcode [bk_tienda] para tienda, categorías, líneas, ¡Sale! y búsqueda: filtros por línea, prenda, talla, precio y oferta, orden y "Ver más". Rápido y sin recargar.',
			'replaces' => 'Divi Machine, Divi Ajax Filter, Themify Product Filter, filtrado-productos-ufo',
			'woo'      => true,
		),
		'sidecart'  => array(
			'label'    => 'Carrito flotante',
			'desc'     => 'Carrito lateral con cambio de cantidades, sugerencias por talla y línea, y añadir al carrito sin recargar la ficha.',
			'replaces' => 'WooCommerce Side Cart Premium (Xootix)',
			'woo'      => true,
		),
		'gestion'   => array(
			'label'    => 'Gestión de tienda (/gestion-tienda/)',
			'desc'     => 'Dashboard propio para pedidos (estado, impreso, consecutivo, cédula, adjuntos, notas) y productos (precio, oferta, stock por talla, Nuevo). Crea el rol "Gestor de tienda".',
			'replaces' => 'Pantallas lentas de wp-admin para el día a día',
			'woo'      => true,
		),
		'pages'     => array(
			'label'    => 'Páginas limpias',
			'desc'     => 'buckler-core pinta inicio, tienda, ficha de producto, carrito, checkout, mi cuenta, preguntas frecuentes y políticas con su propio HTML, sin CSS ni JS de Divi. Requiere Header y footer, Tarjeta de producto y Tienda con filtros.',
			'replaces' => 'Plantillas del Theme Builder, BodyCommerce (ficha), Divi Essential, FiboSearch en esas páginas',
			'woo'      => true,
		),
		'video'     => array(
			'label'    => 'Video de producto',
			'desc'     => 'Video en la galería de la ficha y caja para asignarlo. Usa como respaldo el video que ya cargaste con BodyCommerce.',
			'replaces' => 'Divi BodyCommerce (video)',
			'woo'      => true,
		),
		'sku_ref'   => array(
			'label'    => 'SKU → REF',
			'desc'     => 'Cambia la palabra SKU por REF en los textos de WooCommerce.',
			'replaces' => 'Snippet "PALABRA - SKU POR REF"',
			'woo'      => true,
		),
		'cache'     => array(
			'label'    => 'Caché de páginas (LiteSpeed)',
			'desc'     => 'Guarda en el servidor las páginas de buckler-core (inicio, tienda, ficha, FAQ y políticas) para visitantes sin sesión ni carrito: se entregan al instante. Quita las cookies que impedían la caché y la vacía sola al cambiar productos, stock, páginas o banners. Botón "Vaciar caché" abajo. Requiere Páginas limpias.',
			'replaces' => 'LiteSpeed Cache / capa Ufocache',
			'woo'      => true,
		),
		'abandoned' => array(
			'label'    => 'Carritos abandonados',
			'desc'     => 'Captura el correo en el checkout (con autorización) y envía 3 recordatorios. Menú: WooCommerce → Carritos abandonados.',
			'replaces' => 'Save Abandoned Carts',
			'woo'      => true,
		),
	);
}

/**
 * ¿Está encendido un módulo?
 *
 * La constante BK_MODULES_ALL (wp-config) enciende todo; útil en staging o local.
 *
 * @param string $key Clave.
 * @return bool
 */
function bk_on( $key ) {
	static $active = null;
	if ( null === $active ) {
		$active = defined( 'BK_MODULES_ALL' ) && BK_MODULES_ALL ? array_keys( bk_modules() ) : (array) get_option( 'bk_modules', array() );
	}
	return in_array( $key, $active, true );
}

add_filter(
	'body_class',
	static function ( $classes ) {
		foreach ( array_keys( bk_modules() ) as $key ) {
			if ( bk_on( $key ) ) {
				$classes[] = 'bk-m-' . $key;
			}
		}
		return $classes;
	}
);

/* ---------------------------------------------------------------------------
 * Ajustes → Buckler Core
 * ------------------------------------------------------------------------- */

add_action(
	'admin_menu',
	static function () {
		add_options_page( 'Buckler Core', 'Buckler Core', 'manage_options', 'bk-modules', 'bk_modules_page' );
	}
);
add_action( 'admin_post_bk_modules_save', 'bk_modules_save' );

/**
 * Pantalla de módulos.
 */
function bk_modules_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$woo = class_exists( 'WooCommerce' );
	$msg = isset( $_GET['bk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bk_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap">
		<h1>Buckler Core <small style="font-weight:400;color:#646970">v<?php echo esc_html( BK_VERSION ); ?></small></h1>
		<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<?php if ( defined( 'BK_MODULES_ALL' ) && BK_MODULES_ALL ) : ?>
			<div class="notice notice-warning"><p><code>BK_MODULES_ALL</code> está activo en wp-config: todos los módulos están encendidos.</p></div>
		<?php endif; ?>
		<p>Enciende un módulo a la vez y revisa el sitio en computador y celular. Si algo no se ve bien, apágalo: todo vuelve a como estaba. Después de cada cambio, limpia la caché.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'bk_modules_save' ); ?>
			<input type="hidden" name="action" value="bk_modules_save">
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th style="width:60px">Activo</th><th>Módulo</th><th>Reemplaza</th></tr></thead>
				<tbody>
				<?php foreach ( bk_modules() as $key => $m ) : ?>
					<?php $disabled = $m['woo'] && ! $woo; ?>
					<tr>
						<td><input type="checkbox" id="bk-m-<?php echo esc_attr( $key ); ?>" name="modules[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( bk_on( $key ) ); ?> <?php disabled( $disabled ); ?>></td>
						<td><label for="bk-m-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $m['label'] ); ?></strong></label><br><span class="description"><?php echo esc_html( $m['desc'] ); ?></span><?php echo $disabled ? '<br><em>Requiere WooCommerce.</em>' : ''; ?></td>
						<td><?php echo esc_html( $m['replaces'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Guardar módulos' ); ?>
		</form>
		<?php if ( bk_on( 'cache' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<?php wp_nonce_field( 'bk_cache_purge' ); ?>
				<input type="hidden" name="action" value="bk_cache_purge">
				<?php submit_button( 'Vaciar caché de páginas', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Guarda los módulos encendidos.
 */
function bk_modules_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '', 403 );
	}
	check_admin_referer( 'bk_modules_save' );
	$valid = array_keys( bk_modules() );
	$on    = array_values( array_intersect( $valid, array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ?? array() ) ) ) );
	update_option( 'bk_modules', $on );
	if ( in_array( 'gestion', $on, true ) && class_exists( 'WooCommerce' ) ) {
		// Recién encendido: sus archivos aún no se cargaron en esta petición (rol y URL /gestion-tienda/).
		require_once BK_DIR . 'includes/gestion/api.php';
		require_once BK_DIR . 'includes/gestion/page.php';
	}
	do_action( 'bk_modules_saved', $on );
	wp_safe_redirect( admin_url( 'options-general.php?page=bk-modules&bk_msg=' . rawurlencode( 'Módulos guardados. Recuerda limpiar la caché.' ) ) );
	exit;
}
