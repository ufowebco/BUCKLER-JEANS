<?php
/**
 * Video de producto en la galería de WooCommerce (reemplaza la función de Divi BodyCommerce).
 *
 * Fuente del video, en orden:
 *   1. Meta propia `_bk_video_id` (adjunto de la Mediateca) o `_bk_video_url`.
 *   2. Meta del plugin anterior (clave detectada y guardada en la opción `bk_legacy_video_key`).
 *      Así los videos ya cargados siguen funcionando aunque se desactive BodyCommerce,
 *      incluso antes de ejecutar la migración.
 *
 * Se inserta como una diapositiva más de la galería nativa (módulo "Imágenes del producto" de Divi 5
 * o plantilla estándar de Woo) mediante la acción `woocommerce_product_thumbnails`.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_VIDEO_ID_META  = '_bk_video_id';
const BK_VIDEO_URL_META = '_bk_video_url';

/**
 * Extrae un video (ID de adjunto o URL) de cualquier valor guardado por otro plugin:
 * ID, URL, array, valor serializado o HTML/shortcode con un .mp4 dentro.
 *
 * @param mixed $value Valor en bruto.
 * @return array{id:int,url:string}
 */
function bk_video_parse_value( $value ) {
	$empty = array(
		'id'  => 0,
		'url' => '',
	);
	$value = maybe_unserialize( $value );

	if ( is_array( $value ) ) {
		foreach ( array( 'ID', 'id', 'url', 'src', 'video', 'file' ) as $k ) {
			if ( ! empty( $value[ $k ] ) ) {
				$found = bk_video_parse_value( $value[ $k ] );
				if ( $found['id'] || $found['url'] ) {
					return $found;
				}
			}
		}
		foreach ( $value as $v ) {
			$found = bk_video_parse_value( $v );
			if ( $found['id'] || $found['url'] ) {
				return $found;
			}
		}
		return $empty;
	}

	if ( is_numeric( $value ) ) {
		$id = (int) $value;
		return ( $id && 0 === strpos( (string) get_post_mime_type( $id ), 'video/' ) )
			? array( 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) )
			: $empty;
	}

	if ( is_string( $value ) && preg_match( '#https?://[^\s"\'<>\]]+\.(?:mp4|webm|mov|m4v)(?:\?[^\s"\'<>\]]*)?#i', $value, $m ) ) {
		$url = esc_url_raw( $m[0] );
		return array(
			'id'  => (int) attachment_url_to_postid( $url ),
			'url' => $url,
		);
	}

	return $empty;
}

/**
 * Video de un producto.
 *
 * @param int $product_id ID del producto.
 * @return array{id:int,url:string,mime:string,source:string}|null
 */
function bk_get_product_video( $product_id ) {
	$id  = (int) get_post_meta( $product_id, BK_VIDEO_ID_META, true );
	$url = (string) get_post_meta( $product_id, BK_VIDEO_URL_META, true );
	$src = 'buckler';

	if ( $id ) {
		$url = (string) wp_get_attachment_url( $id );
	}

	if ( ! $url ) {
		$legacy_key = (string) get_option( 'bk_legacy_video_key', '' );
		if ( $legacy_key ) {
			$found = bk_video_parse_value( get_post_meta( $product_id, $legacy_key, true ) );
			$id    = $found['id'];
			$url   = $found['url'];
			$src   = 'legacy';
		}
	}

	if ( ! $url ) {
		return null;
	}

	$type = wp_check_filetype( wp_parse_url( $url, PHP_URL_PATH ) );
	return array(
		'id'     => $id,
		'url'    => $url,
		'mime'   => $type['type'] ? $type['type'] : 'video/mp4',
		'source' => $src,
	);
}

add_action( 'woocommerce_product_thumbnails', 'bk_render_gallery_video', 30 );

/**
 * Añade el video como última diapositiva de la galería.
 * Si BodyCommerce sigue activo no hace nada (evita el video duplicado durante la transición).
 */
function bk_render_gallery_video() {
	global $product;
	if ( ! $product instanceof WC_Product || bk_plugin_folder_active( 'divi-bodycommerce' ) ) {
		return;
	}
	$video = bk_get_product_video( $product->get_id() );
	if ( ! $video ) {
		return;
	}

	$poster = (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_single' );
	$thumb  = BK_URL . 'assets/img/video-thumb.svg';
	/* translators: %s: nombre del producto */
	$label = sprintf( __( 'Video de %s', 'buckler-core' ), $product->get_name() );
	?>
	<div class="woocommerce-product-gallery__image bk-gallery-video" data-thumb="<?php echo esc_url( $thumb ); ?>" data-thumb-alt="<?php echo esc_attr( $label ); ?>">
		<video class="bk-gallery-video__media" playsinline muted loop preload="none" poster="<?php echo esc_url( $poster ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
			<source src="<?php echo esc_url( $video['url'] ); ?>" type="<?php echo esc_attr( $video['mime'] ); ?>">
		</video>
		<button type="button" class="bk-gallery-video__toggle" aria-pressed="false" aria-label="<?php esc_attr_e( 'Reproducir video', 'buckler-core' ); ?>">
			<?php echo bk_icon_svg( 'lucide:play', 28 ) . bk_icon_svg( 'lucide:pause', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</button>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Caja en la edición del producto para asignar o cambiar el video.
 * ------------------------------------------------------------------------- */

add_action( 'add_meta_boxes_product', 'bk_video_metabox' );
add_action( 'save_post_product', 'bk_video_metabox_save', 10, 2 );
add_action( 'admin_enqueue_scripts', 'bk_video_metabox_assets' );

/**
 * Registra la caja lateral "Video del producto".
 */
function bk_video_metabox() {
	add_meta_box( 'bk-product-video', __( 'Video del producto', 'buckler-core' ), 'bk_video_metabox_html', 'product', 'side', 'low' );
}

/**
 * HTML de la caja.
 *
 * @param WP_Post $post Producto.
 */
function bk_video_metabox_html( $post ) {
	wp_nonce_field( 'bk_video_save', 'bk_video_nonce' );
	$video = bk_get_product_video( $post->ID );
	$id    = $video ? (int) $video['id'] : 0;
	$url   = $video ? $video['url'] : '';
	?>
	<p>
		<input type="hidden" id="bk_video_id" name="bk_video_id" value="<?php echo esc_attr( $id ? $id : '' ); ?>">
		<label for="bk_video_url" class="screen-reader-text"><?php esc_html_e( 'URL del video', 'buckler-core' ); ?></label>
		<input type="url" id="bk_video_url" name="bk_video_url" class="widefat" placeholder="https://…/video.mp4" value="<?php echo esc_attr( $url ); ?>">
	</p>
	<p>
		<button type="button" class="button" id="bk_video_pick"><?php esc_html_e( 'Elegir de la Mediateca', 'buckler-core' ); ?></button>
		<button type="button" class="button-link button-link-delete" id="bk_video_clear"><?php esc_html_e( 'Quitar', 'buckler-core' ); ?></button>
	</p>
	<?php if ( $video && 'legacy' === $video['source'] ) : ?>
		<p class="description"><?php esc_html_e( 'Este video viene del plugin anterior. Se muestra igual; al guardar el producto queda copiado aquí.', 'buckler-core' ); ?></p>
	<?php endif; ?>
	<?php if ( $video ) : ?>
		<video src="<?php echo esc_url( $video['url'] ); ?>" controls muted preload="metadata" style="width:100%;margin-top:6px;border-radius:4px"></video>
		<?php
	endif;
}

/**
 * Guarda el video del producto. Si el campo trae el video heredado, lo copia a la meta propia.
 *
 * @param int     $post_id ID.
 * @param WP_Post $post    Post.
 */
function bk_video_metabox_save( $post_id, $post ) {
	if ( ! isset( $_POST['bk_video_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bk_video_nonce'] ), 'bk_video_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || 'product' !== $post->post_type ) {
		return;
	}

	$id  = isset( $_POST['bk_video_id'] ) ? absint( $_POST['bk_video_id'] ) : 0;
	$url = isset( $_POST['bk_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['bk_video_url'] ) ) : '';

	if ( $id && 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) ) {
		$id = 0;
	}
	if ( ! $id && $url ) {
		$id = (int) attachment_url_to_postid( $url );
	}

	if ( $id ) {
		update_post_meta( $post_id, BK_VIDEO_ID_META, $id );
		delete_post_meta( $post_id, BK_VIDEO_URL_META );
	} elseif ( $url ) {
		update_post_meta( $post_id, BK_VIDEO_URL_META, $url );
		delete_post_meta( $post_id, BK_VIDEO_ID_META );
	} else {
		delete_post_meta( $post_id, BK_VIDEO_ID_META );
		delete_post_meta( $post_id, BK_VIDEO_URL_META );
	}
}

/**
 * Selector de medios en la edición de productos.
 *
 * @param string $hook Pantalla actual.
 */
function bk_video_metabox_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'bk-admin-video', BK_URL . 'assets/js/admin-video.js', array( 'jquery' ), BK_VERSION, true );
}
