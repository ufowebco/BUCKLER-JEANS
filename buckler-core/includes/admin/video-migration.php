<?php
/**
 * Herramientas → Buckler: videos.
 *
 * 1. Detecta en qué meta guardaba el plugin anterior (BodyCommerce) el video de cada producto.
 * 2. Permite usar esa clave como respaldo inmediato (los videos siguen saliendo sin migrar).
 * 3. Migra los videos a la meta propia `_bk_video_id` / `_bk_video_url` por lotes.
 *
 * Solo lectura en la base de datos salvo en la migración. Requiere `manage_woocommerce`.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'bk_video_admin_menu' );
add_action( 'admin_post_bk_video_use_key', 'bk_video_handle_use_key' );
add_action( 'admin_post_bk_video_migrate', 'bk_video_handle_migrate' );

/**
 * Busca claves de meta de productos que contienen videos.
 *
 * @return array<int,array{meta_key:string,products:int,sample:int,kind:string}>
 */
function bk_video_detect_candidates() {
	global $wpdb;

	$own   = array( BK_VIDEO_ID_META, BK_VIDEO_URL_META );
	$found = array();

	// a) Valores con URL de video (.mp4, .webm, .mov, .m4v), incluso serializados o en HTML.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS products, MIN(pm.post_id) AS sample
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
		 WHERE ( pm.meta_value LIKE '%.mp4%' OR pm.meta_value LIKE '%.webm%' OR pm.meta_value LIKE '%.mov%' OR pm.meta_value LIKE '%.m4v%' )
		 GROUP BY pm.meta_key
		 ORDER BY products DESC
		 LIMIT 20",
		ARRAY_A
	);
	foreach ( (array) $rows as $r ) {
		$r['kind']                 = 'url';
		$found[ $r['meta_key'] ] = $r;
	}

	// b) Valores numéricos que apuntan a un adjunto de tipo video.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS products, MIN(pm.post_id) AS sample
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
		 INNER JOIN {$wpdb->posts} a ON a.ID = pm.meta_value AND a.post_type = 'attachment' AND a.post_mime_type LIKE 'video/%'
		 WHERE pm.meta_value REGEXP '^[0-9]+$'
		   AND pm.meta_key REGEXP 'video|vid|mp4|clip|media'
		 GROUP BY pm.meta_key
		 ORDER BY products DESC
		 LIMIT 20",
		ARRAY_A
	);
	foreach ( (array) $rows as $r ) {
		if ( empty( $found[ $r['meta_key'] ] ) ) {
			$r['kind']                 = 'adjunto';
			$found[ $r['meta_key'] ] = $r;
		}
	}

	// Un número cualquiera (contador de visitas, SKU, ID de galería) puede coincidir con el ID de un video:
	// por eso los numéricos solo cuentan si la clave habla de video (arriba) y se excluyen claves conocidas.
	// En producción (2026-10-14) daban falsos positivos `_conteo_visitas_woo`, `_sku` y `_product_image_gallery`.
	foreach ( array_merge( $own, array( '_sku', '_product_image_gallery', '_thumbnail_id' ) ) as $k ) {
		unset( $found[ $k ] );
	}
	uasort(
		$found,
		static function ( $a, $b ) {
			return (int) $b['products'] - (int) $a['products'];
		}
	);
	return array_values( $found );
}

/**
 * En la activación: si hay una sola clave candidata clara, la usa como respaldo.
 */
function bk_video_autodetect_legacy_key() {
	if ( get_option( 'bk_legacy_video_key' ) ) {
		return;
	}
	if ( ! defined( 'BK_VIDEO_ID_META' ) ) {
		require_once BK_DIR . 'includes/product-video.php';
	}
	$candidates = bk_video_detect_candidates();
	if ( 1 === count( $candidates ) ) {
		update_option( 'bk_legacy_video_key', sanitize_text_field( $candidates[0]['meta_key'] ), false );
	}
}

/**
 * Menú en Herramientas.
 */
function bk_video_admin_menu() {
	add_management_page( __( 'Buckler: videos de producto', 'buckler-core' ), __( 'Buckler: videos', 'buckler-core' ), 'manage_woocommerce', 'bk-videos', 'bk_video_admin_page' );
}

/**
 * Productos con un video adjunto en la galería (formato de BodyCommerce; se usa directamente, sin migrar).
 *
 * @return int
 */
function bk_video_count_gallery() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'video/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $ids ) {
		return 0;
	}
	$n = 0;
	foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" ) as $g ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n += array_intersect( explode( ',', $g ), $ids ) ? 1 : 0;
	}
	return $n;
}

/**
 * Cuenta productos que ya tienen video propio.
 *
 * @return int
 */
function bk_video_count_migrated() {
	global $wpdb;
	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value <> ''",
			BK_VIDEO_ID_META,
			BK_VIDEO_URL_META
		)
	);
}

/**
 * Pantalla de la herramienta.
 */
function bk_video_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$current    = (string) get_option( 'bk_legacy_video_key', '' );
	$candidates = bk_video_detect_candidates();
	$migrated   = bk_video_count_migrated();
	$notice     = isset( $_GET['bk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bk_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Videos de producto', 'buckler-core' ); ?></h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Esta herramienta encuentra los videos que cargó el plugin anterior y los pasa a Buckler Core. Mientras haya una clave de respaldo elegida, los videos se ven en la tienda aunque no hayas migrado.', 'buckler-core' ); ?></p>
		<p>
			<strong><?php esc_html_e( 'Clave de respaldo actual:', 'buckler-core' ); ?></strong>
			<code><?php echo $current ? esc_html( $current ) : esc_html__( 'ninguna', 'buckler-core' ); ?></code>
			&nbsp;·&nbsp;
			<strong><?php esc_html_e( 'Productos con video propio:', 'buckler-core' ); ?></strong> <?php echo (int) $migrated; ?>
			&nbsp;·&nbsp;
			<strong><?php esc_html_e( 'Productos con MP4 en la galería:', 'buckler-core' ); ?></strong> <?php echo (int) bk_video_count_gallery(); ?>
			&nbsp;·&nbsp;
			<strong><?php esc_html_e( 'BodyCommerce:', 'buckler-core' ); ?></strong>
			<?php echo bk_plugin_folder_active( 'divi-bodycommerce' ) ? esc_html__( 'activo (el video propio no se pinta para no duplicar)', 'buckler-core' ) : esc_html__( 'inactivo', 'buckler-core' ); ?>
		</p>

		<?php if ( bk_video_count_gallery() ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Los videos de tus productos están dentro de la galería (así los guarda BodyCommerce). Buckler Core los usa directamente: no necesitas elegir una clave de respaldo ni migrar.', 'buckler-core' ); ?></p></div>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Otras claves encontradas (avanzado)', 'buckler-core' ); ?></h2>
		<?php if ( ! $candidates ) : ?>
			<p><?php esc_html_e( 'No se encontraron videos en los datos de los productos.', 'buckler-core' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:960px">
				<thead><tr><th><?php esc_html_e( 'Clave', 'buckler-core' ); ?></th><th><?php esc_html_e( 'Tipo', 'buckler-core' ); ?></th><th><?php esc_html_e( 'Productos', 'buckler-core' ); ?></th><th><?php esc_html_e( 'Ejemplo', 'buckler-core' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				foreach ( $candidates as $c ) :
					$sample = bk_video_parse_value( get_post_meta( (int) $c['sample'], $c['meta_key'], true ) );
					?>
					<tr>
						<td><code><?php echo esc_html( $c['meta_key'] ); ?></code></td>
						<td><?php echo esc_html( $c['kind'] ); ?></td>
						<td><?php echo (int) $c['products']; ?></td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $c['sample'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $c['sample'] ) ); ?></a><br>
							<small><?php echo esc_html( $sample['url'] ? $sample['url'] : __( '(no se pudo leer la URL)', 'buckler-core' ) ); ?></small>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'bk_video_use_key' ); ?>
								<input type="hidden" name="action" value="bk_video_use_key">
								<input type="hidden" name="meta_key" value="<?php echo esc_attr( $c['meta_key'] ); ?>">
								<button class="button" <?php disabled( $current, $c['meta_key'] ); ?>><?php esc_html_e( 'Usar como respaldo', 'buckler-core' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $current ) : ?>
			<h2><?php esc_html_e( 'Migrar', 'buckler-core' ); ?></h2>
			<p><?php esc_html_e( 'Copia el video de cada producto a Buckler Core. No borra nada del plugin anterior y se puede repetir sin duplicar.', 'buckler-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bk_video_migrate' ); ?>
				<input type="hidden" name="action" value="bk_video_migrate">
				<button class="button button-primary"><?php esc_html_e( 'Migrar videos ahora', 'buckler-core' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Guarda la clave de respaldo.
 */
function bk_video_handle_use_key() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'No tienes permiso.', 'buckler-core' ), 403 );
	}
	check_admin_referer( 'bk_video_use_key' );
	$key = isset( $_POST['meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_key'] ) ) : '';
	update_option( 'bk_legacy_video_key', $key, false );
	bk_video_redirect( __( 'Clave de respaldo guardada.', 'buckler-core' ) );
}

/**
 * Migra todos los productos con video en la clave de respaldo (por lotes de 200).
 */
function bk_video_handle_migrate() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'No tienes permiso.', 'buckler-core' ), 403 );
	}
	check_admin_referer( 'bk_video_migrate' );

	global $wpdb;
	$key = (string) get_option( 'bk_legacy_video_key', '' );
	if ( ! $key ) {
		bk_video_redirect( __( 'Primero elige una clave de respaldo.', 'buckler-core' ) );
	}

	$done   = 0;
	$failed = 0;
	$offset = 0;
	do {
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
				 WHERE pm.meta_key = %s AND pm.meta_value <> ''
				 ORDER BY pm.post_id LIMIT 200 OFFSET %d",
				$key,
				$offset
			)
		);
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			if ( get_post_meta( $pid, BK_VIDEO_ID_META, true ) || get_post_meta( $pid, BK_VIDEO_URL_META, true ) ) {
				continue; // Ya tiene video propio: no se pisa.
			}
			$video = bk_video_parse_value( get_post_meta( $pid, $key, true ) );
			if ( $video['id'] ) {
				update_post_meta( $pid, BK_VIDEO_ID_META, $video['id'] );
				++$done;
			} elseif ( $video['url'] ) {
				update_post_meta( $pid, BK_VIDEO_URL_META, $video['url'] );
				++$done;
			} else {
				++$failed;
			}
		}
		$offset += 200;
	} while ( count( $ids ) === 200 );

	/* translators: 1: migrados, 2: sin URL legible */
	bk_video_redirect( sprintf( __( 'Migración terminada: %1$d productos migrados, %2$d sin video legible.', 'buckler-core' ), $done, $failed ) );
}

/**
 * Vuelve a la pantalla con un mensaje.
 *
 * @param string $msg Mensaje.
 */
function bk_video_redirect( $msg ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'bk-videos', 'bk_msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
}
