<?php
/**
 * Módulo "Páginas limpias": buckler-core pinta las páginas completas con su propio documento HTML
 * (wp_head + [bk_header] + contenido + [bk_footer] + wp_footer), sin plantillas ni CSS/JS de Divi.
 *
 * - Rutas: inicio, tienda/categorías/líneas/¡Sale!/búsqueda, ficha de producto, carrito, checkout (y gracias),
 *   mi cuenta, preguntas frecuentes, políticas (CPT politica-empresa) y 404.
 * - El tema (Divi + tema hijo) sigue activo: sus snippets de functions.php siguen funcionando y el resto de
 *   páginas (si las hubiera) usan Divi como hoy. Apagar el módulo devuelve todo a Divi.
 * - Carrito y checkout usan los shortcodes clásicos: así funcionan hoy las pasarelas (Bold, Wompi, Rapyd, Addi),
 *   el campo de cédula de los snippets y la captura de carritos abandonados.
 * - Ficha: plantilla nativa de WooCommerce reordenada con hooks (los plugins que usan esos hooks siguen
 *   funcionando: precios dinámicos, píxel, etc.), con galería propia (fotos + video) y tallas accesibles.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'template_include', 'bk_pages_template', 100000 );
add_action( 'wp_enqueue_scripts', 'bk_pages_assets', 30 );
add_action( 'wp_enqueue_scripts', 'bk_pages_diet', 9999 );
add_action( 'wp_print_footer_scripts', 'bk_pages_diet', 1 );
add_action( 'wp_head', 'bk_pages_preload', 1 );

/**
 * Precarga de la fuente propia (evita el salto de texto al cargar).
 */
function bk_pages_preload() {
	if ( bk_pages_route() ) {
		echo '<link rel="preload" href="' . esc_url( BK_URL . 'assets/fonts/manrope-latin-wght-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	}
}

/**
 * Ruta de la petición actual ('' = no la pinta buckler-core).
 *
 * @return string
 */
function bk_pages_route() {
	static $route = null;
	if ( null !== $route ) {
		return $route;
	}
	$route = '';
	if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || isset( $_GET['et_fb'] ) || ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $route; // Nunca dentro del editor visual de Divi.
	}
	if ( ! bk_on( 'layout' ) ) {
		return $route; // El documento usa [bk_header] y [bk_footer].
	}
	$faq = trailingslashit( (string) bk_config( 'faq_page' ) );
	$uri = trailingslashit( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( is_front_page() ) {
		$route = 'home';
	} elseif ( function_exists( 'is_product' ) && is_product() ) {
		$route = 'product';
	} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
		$route = 'cart';
	} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$route = 'checkout';
	} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$route = 'account';
	} elseif ( is_singular( 'politica-empresa' ) ) {
		$route = 'policy';
	} elseif ( is_page() && '/' !== $faq && $faq === $uri ) {
		$route = 'faq';
	} elseif ( function_exists( 'bk_shop_takeover_shortcode' ) && bk_shop_takeover_shortcode() ) {
		$route = 'shop';
	} elseif ( is_404() ) {
		$route = '404';
	}
	$allowed = (array) apply_filters( 'bk_pages_routes', array( 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'policy', 'faq', '404' ) );
	$route   = in_array( $route, $allowed, true ) ? $route : '';
	return $route;
}

/**
 * Plantilla: documento propio para las rutas de buckler-core.
 *
 * @param string $template Plantilla del tema.
 * @return string
 */
function bk_pages_template( $template ) {
	$route = bk_pages_route();
	if ( ! $route ) {
		return $template;
	}
	if ( 'product' === $route ) {
		bk_pdp_hooks();
	}
	return BK_DIR . 'templates/pages/document.php';
}

/**
 * CSS/JS propios de las páginas.
 */
function bk_pages_assets() {
	$route = bk_pages_route();
	if ( ! $route ) {
		return;
	}
	wp_enqueue_style( 'bk-pages', BK_URL . 'assets/css/pages.css', array( 'bk-core' ), bk_asset_ver( 'assets/css/pages.css' ) );
	wp_enqueue_script( 'bk-pages', BK_URL . 'assets/js/pages.js', array(), bk_asset_ver( 'assets/js/pages.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'bk-pages',
		'bkPages',
		array(
			'i18n' => array(
				'size'      => __( 'Talla', 'buckler-core' ),
				'soldOut'   => __( 'agotada', 'buckler-core' ),
				'chooseSize' => __( 'Elige tu talla', 'buckler-core' ),
				'prev'      => __( 'Foto anterior', 'buckler-core' ),
				'next'      => __( 'Foto siguiente', 'buckler-core' ),
			),
		)
	);
	if ( 'shop' === $route && function_exists( 'bk_shop_assets' ) ) {
		bk_shop_assets();
	}
}

/**
 * Dieta: en las páginas de buckler-core se quitan CSS/JS de Divi y de sus plugins (BodyCommerce, Divi Engine,
 * Divi Essential…), del buscador FiboSearch (el header trae su propio buscador) y de los bloques de Gutenberg
 * (estas páginas no los usan). Se conservan WooCommerce, pasarelas, analítica y lo propio.
 */
function bk_pages_diet() {
	if ( ! bk_pages_route() ) {
		return;
	}
	$re = (string) apply_filters( 'bk_pages_diet_regex', '/^(divi|et[-_]|et_|dnxte|bc-|divi-bodyshop|bodycommerce|pac[-_]?da|spotlight|effect_card|wpf|themify|dgwt-wcas|bold-elementor|de[-_]|dmach|divi-machine|divi-ajax|da[-_]|ddch|dipl|wp-block-library|global-styles|classic-theme-styles|wc-blocks|xoo-)/i' );
	foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
		foreach ( (array) $deps->queue as $handle ) {
			if ( preg_match( $re, $handle ) && ! in_array( $handle, array( 'dashicons', 'admin-bar' ), true ) ) {
				$deps->dequeue( $handle );
			}
		}
	}
}

/**
 * Quita del <head> los estilos en línea de Divi que no pasan por wp_enqueue (CSS crítico, personalizador).
 *
 * @param string $html Salida de wp_head().
 * @return string
 */
function bk_pages_clean_head( $html ) {
	$html = preg_replace( '#<style[^>]+id=["\'](et-|et_|divi|dnxte|bc-)[^"\']*["\'][^>]*>.*?</style>#is', '', $html );
	$html = preg_replace( '#<link[^>]+id=["\'](et-|et_|divi|dnxte)[^"\']*["\'][^>]*>#i', '', $html );
	return (string) $html;
}

/* ---------------------------------------------------------------------------
 * Ficha de producto
 * ------------------------------------------------------------------------- */

/**
 * Reordena la ficha nativa de WooCommerce.
 */
function bk_pdp_hooks() {
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
	add_action( 'woocommerce_before_single_product_summary', 'bk_pdp_gallery', 20 );

	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_addi', 11 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_size_tools', 25 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_benefits', 40 );

	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
	add_action( 'woocommerce_after_single_product_summary', 'bk_pdp_related', 20 );

	add_filter( 'woocommerce_product_tabs', 'bk_pdp_tabs', 98 );
}

/**
 * Migas de pan de la ficha: Inicio / Línea / Prenda / Producto (la primera categoría de Woo suele ser
 * "Línea nueva" o "¡Sale!", que no ayudan a ubicarse). Enlaces que funcionan con el redirect de categorías.
 */
function bk_pdp_crumbs() {
	$p = wc_get_product( get_the_ID() );
	if ( ! $p ) {
		return;
	}
	$line  = bk_product_line( $p->get_id() );
	$lines = (array) bk_config( 'lines' );
	$items = array( array( __( 'Inicio', 'buckler-core' ), home_url( '/' ) ) );
	if ( $line && ! empty( $lines[ $line['slug'] ]['page'] ) ) {
		$page    = bk_url( $lines[ $line['slug'] ]['page'] );
		$items[] = array( $line['label'], $page );
		$root    = get_term_by( 'slug', $line['slug'], 'product_cat' );
		foreach ( $root ? $p->get_category_ids() : array() as $cid ) {
			$t = get_term( $cid, 'product_cat' );
			if ( $t instanceof WP_Term && (int) $t->parent === (int) $root->term_id ) {
				$items[] = array( bk_nice_label( $t->name ), add_query_arg( 'prenda', bk_prenda_key( $t->name ), $page ) );
				break;
			}
		}
	} else {
		$items[] = array( __( 'Tienda', 'buckler-core' ), wc_get_page_permalink( 'shop' ) );
	}
	echo '<nav class="bk-crumbs" aria-label="' . esc_attr__( 'Ruta de navegación', 'buckler-core' ) . '"><ol>';
	foreach ( $items as $it ) {
		echo '<li><a href="' . esc_url( $it[1] ) . '">' . esc_html( $it[0] ) . '</a></li>';
	}
	echo '<li aria-current="page">' . esc_html( $p->get_name() ) . '</li></ol></nav>';
}

/**
 * Galería propia: fotos (deslizables, miniaturas) + video del producto. Sin jQuery ni plugins.
 */
function bk_pdp_gallery() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$name   = $product->get_name();
	$ids    = array_values( array_unique( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) ), 'wp_attachment_is_image' ) ) );
	$video  = function_exists( 'bk_get_product_video' ) ? bk_get_product_video( $product->get_id() ) : null;
	$poster = $ids ? (string) wp_get_attachment_image_url( $ids[0], 'woocommerce_single' ) : '';
	$badge  = '';
	if ( $product->is_featured() ) {
		$badge = '<span class="bk-pg__badge">' . esc_html__( 'Nuevo', 'buckler-core' ) . '</span>';
	} elseif ( $product->is_on_sale() ) {
		$badge = '<span class="bk-pg__badge is-sale">' . esc_html__( 'Oferta', 'buckler-core' ) . '</span>';
	}
	$total = count( $ids ) + ( $video ? 1 : 0 );
	?>
	<div class="bk-pg" data-bk-pg>
		<?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="bk-pg__track" tabindex="0" role="region" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: producto */ __( 'Fotos de %s', 'buckler-core' ), $name ) ); ?>">
			<?php foreach ( $ids as $i => $id ) : ?>
				<figure class="bk-pg__slide" id="bk-pg-<?php echo (int) $i; ?>">
					<?php
					echo wp_get_attachment_image(
						$id,
						'woocommerce_single',
						false,
						array(
							'alt'           => bk_image_alt( $id, $i ? sprintf( '%s – foto %d', $name, $i + 1 ) : $name ),
							'loading'       => $i ? 'lazy' : 'eager',
							'fetchpriority' => $i ? 'auto' : 'high',
							'sizes'         => '(max-width: 900px) 100vw, 50vw',
						)
					);
					?>
				</figure>
			<?php endforeach; ?>
			<?php if ( $video ) : ?>
				<figure class="bk-pg__slide is-video" id="bk-pg-<?php echo count( $ids ); ?>">
					<video muted playsinline loop controls preload="none" poster="<?php echo esc_url( $poster ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: producto */ __( 'Video de %s', 'buckler-core' ), $name ) ); ?>">
						<source src="<?php echo esc_url( $video['url'] ); ?>" type="<?php echo esc_attr( $video['mime'] ); ?>">
					</video>
				</figure>
			<?php endif; ?>
		</div>
		<?php if ( $total > 1 ) : ?>
			<button type="button" class="bk-pg__nav is-prev" data-dir="-1" aria-label="<?php esc_attr_e( 'Foto anterior', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:chevron-left', 22 ); // phpcs:ignore ?></button>
			<button type="button" class="bk-pg__nav is-next" data-dir="1" aria-label="<?php esc_attr_e( 'Foto siguiente', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:chevron-right', 22 ); // phpcs:ignore ?></button>
			<div class="bk-pg__thumbs">
				<?php foreach ( $ids as $i => $id ) : ?>
					<button type="button" class="bk-pg__thumb" data-go="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: número */ __( 'Ver foto %d', 'buckler-core' ), $i + 1 ) ); ?>" <?php echo $i ? '' : 'aria-current="true"'; ?>>
						<?php echo wp_get_attachment_image( $id, 'woocommerce_gallery_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ); ?>
					</button>
				<?php endforeach; ?>
				<?php if ( $video ) : ?>
					<button type="button" class="bk-pg__thumb is-video" data-go="<?php echo count( $ids ); ?>" aria-label="<?php esc_attr_e( 'Ver video', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:play', 22 ); // phpcs:ignore ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Título: H1 con la línea (diferencia los modelos repetidos en Slim y Curvy) y REF.
 */
function bk_pdp_title() {
	global $product;
	$line = bk_product_line( $product->get_id() );
	echo '<h1 class="product_title entry-title bk-pdp__title">' . esc_html( $product->get_name() );
	if ( $line ) {
		echo ' <span class="bk-pdp__line">· ' . esc_html( $line['label'] ) . '</span>';
	}
	echo '</h1>';
	if ( $product->get_sku() ) {
		echo '<p class="bk-pdp__ref">REF ' . esc_html( $product->get_sku() ) . '</p>';
	}
}

/**
 * Cuotas Addi bajo el precio.
 */
function bk_pdp_addi() {
	global $product;
	$n     = (int) bk_config( 'addi_installments' );
	$price = (float) ( $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : wc_get_price_to_display( $product ) );
	if ( $n > 1 && $price > 0 ) {
		/* translators: 1: cuotas, 2: valor */
		echo '<p class="bk-pdp__addi">' . esc_html( sprintf( __( 'o %1$d cuotas de %2$s sin interés con Addi', 'buckler-core' ), $n, html_entity_decode( wp_strip_all_tags( wc_price( ceil( $price / $n ) ) ) ) ) ) . '</p>';
	}
}

/**
 * Guía de tallas (imágenes ACF) y enlace al mismo modelo en la otra línea.
 */
function bk_pdp_size_tools() {
	global $product;
	$guide = bk_image_id( bk_option_field( 'imagen_guia_de_tallas' ) );
	$shape = bk_image_id( bk_option_field( 'imagen_siluetas' ) );
	$twin  = bk_pdp_twin( $product );
	if ( ! $guide && ! $shape && ! $twin ) {
		return;
	}
	echo '<div class="bk-pdp__tools">';
	if ( $guide || $shape ) {
		echo '<button type="button" class="bk-pdp__guide" data-bk-dialog="bk-size-guide">' . bk_icon_svg( 'lucide:ruler', 18 ) . esc_html__( 'Guía de tallas', 'buckler-core' ) . '</button>'; // phpcs:ignore
	}
	if ( $twin ) {
		$lines = (array) bk_config( 'lines' );
		$label = $lines[ $twin['line'] ]['label'] ?? '';
		$sizes = $lines[ $twin['line'] ]['sizes'] ?? '';
		/* translators: 1: tallas, 2: línea */
		echo '<a class="bk-pdp__twin" href="' . esc_url( $twin['url'] ) . '">' . esc_html( sprintf( __( '¿Buscas %1$s? Ver este modelo en %2$s', 'buckler-core' ), mb_strtolower( $sizes ), $label ) ) . ' →</a>';
	}
	echo '</div>';
	if ( $guide || $shape ) {
		echo '<dialog id="bk-size-guide" class="bk-dialog" aria-labelledby="bk-size-guide-t"><div class="bk-dialog__box"><header><h2 id="bk-size-guide-t">' . esc_html__( 'Guía de tallas y siluetas', 'buckler-core' ) . '</h2><button type="button" class="bk-dialog__close" data-bk-close aria-label="' . esc_attr__( 'Cerrar', 'buckler-core' ) . '">' . bk_icon_svg( 'lucide:x', 22 ) . '</button></header>'; // phpcs:ignore
		foreach ( array_filter( array( $guide, $shape ) ) as $img ) {
			echo wp_get_attachment_image( $img, 'large', false, array( 'loading' => 'lazy', 'alt' => bk_image_alt( $img, __( 'Guía de tallas Buckler', 'buckler-core' ) ) ) );
		}
		echo '</div></dialog>';
	}
}

/**
 * Mismo modelo (mismo nombre) en la otra línea, publicado y con stock.
 *
 * @param WC_Product $product Producto.
 * @return array{url:string,line:string}|null
 */
function bk_pdp_twin( $product ) {
	$key  = 'bk_twin_' . $product->get_id() . '_' . (int) get_option( 'bk_catalog_v', 0 );
	$twin = get_transient( $key );
	if ( false === $twin ) {
		$twin = array();
		$mine = bk_product_line( $product->get_id() );
		$q    = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'title'          => $product->get_name(),
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array( $product->get_id() ),
			)
		);
		foreach ( $q->posts as $id ) {
			$other = bk_product_line( $id );
			$p     = wc_get_product( $id );
			if ( $other && $mine && $other['slug'] !== $mine['slug'] && $p && $p->is_in_stock() ) {
				$twin = array( 'url' => get_permalink( $id ), 'line' => $other['slug'] );
				break;
			}
		}
		set_transient( $key, $twin, DAY_IN_SECONDS );
	}
	return $twin ? $twin : null;
}

/**
 * Beneficios bajo el botón de compra.
 */
function bk_pdp_benefits() {
	$ship = get_page_by_path( 'politica-de-envio', OBJECT, 'politica-empresa' );
	?>
	<ul class="bk-pdp__benefits">
		<li><?php echo bk_icon_svg( 'lucide:truck', 20 ); // phpcs:ignore ?><span><?php esc_html_e( 'Envío gratis a toda Colombia', 'buckler-core' ); ?><?php if ( $ship ) : ?> · <a href="<?php echo esc_url( get_permalink( $ship ) ); ?>"><?php esc_html_e( 'Ver política de envío', 'buckler-core' ); ?></a><?php endif; ?></span></li>
		<li><?php echo bk_icon_svg( 'lucide:credit-card', 20 ); // phpcs:ignore ?><span><?php esc_html_e( 'Paga con Addi, Bold, Wompi o tarjeta', 'buckler-core' ); ?></span></li>
		<li><?php echo bk_icon_svg( 'simple-icons:whatsapp', 20 ); // phpcs:ignore ?><span><a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '¿Dudas con tu talla? Escríbenos por WhatsApp', 'buckler-core' ); ?></a></span></li>
	</ul>
	<?php
}

/**
 * Pestañas: descripción y reseñas (la "información adicional" solo repetía las tallas).
 *
 * @param array $tabs Pestañas.
 * @return array
 */
function bk_pdp_tabs( $tabs ) {
	unset( $tabs['additional_information'] );
	return $tabs;
}

/**
 * Relacionados y venta cruzada con la tarjeta propia (misma línea primero).
 */
function bk_pdp_related() {
	global $product;
	if ( ! function_exists( 'bk_shortcode_productos' ) ) {
		woocommerce_output_related_products();
		return;
	}
	$ids = array_merge( $product->get_upsell_ids(), $product->get_cross_sell_ids(), wc_get_related_products( $product->get_id(), 12 ) );
	$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 8 );
	if ( $ids ) {
		echo '<section class="bk-pdp__related">' . bk_shortcode_productos( array( 'ids' => implode( ',', $ids ), 'titulo' => __( 'Combínalo con', 'buckler-core' ), 'limite' => 8 ) ) . '</section>'; // phpcs:ignore
	}
}

/* ---------------------------------------------------------------------------
 * Preguntas frecuentes y políticas
 * ------------------------------------------------------------------------- */

/**
 * Preguntas frecuentes agrupadas por categoría (CPT pregunta-frecuente + taxonomía caregoria-pf).
 *
 * @return array<int,array{name:string,slug:string,items:array<int,array{q:string,a:string}>}>
 */
function bk_faq_groups() {
	$groups = array();
	$terms  = get_terms( array( 'taxonomy' => 'caregoria-pf', 'hide_empty' => true ) );
	$terms  = is_array( $terms ) ? $terms : array();
	$terms[] = null; // Sin categoría.
	foreach ( $terms as $t ) {
		$args = array( 'post_type' => 'pregunta-frecuente', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => array( 'menu_order' => 'ASC', 'date' => 'ASC' ), 'no_found_rows' => true );
		if ( $t ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'caregoria-pf', 'terms' => $t->term_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} else {
			$args['tax_query'] = array( array( 'taxonomy' => 'caregoria-pf', 'operator' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$items = array();
		foreach ( get_posts( $args ) as $p ) {
			$a = function_exists( 'get_field' ) ? (string) get_field( 'respuesta_pregunta_frecuente', $p->ID ) : '';
			$a = '' !== trim( wp_strip_all_tags( $a ) ) ? $a : (string) $p->post_content;
			if ( '' !== trim( wp_strip_all_tags( $a ) ) ) {
				$items[] = array( 'q' => get_the_title( $p ), 'a' => wpautop( wp_kses_post( $a ) ) );
			}
		}
		if ( $items ) {
			$groups[] = array( 'name' => $t ? $t->name : __( 'Otras preguntas', 'buckler-core' ), 'slug' => $t ? $t->slug : 'otras', 'items' => $items );
		}
	}
	return $groups;
}

/**
 * Texto de una política: campo ACF del CPT; si está vacío, la opción equivalente de "Listado de Políticas".
 *
 * @param WP_Post $post Política.
 * @return string
 */
function bk_policy_text( $post ) {
	$text = function_exists( 'get_field' ) ? (string) get_field( 'texto_politica', $post->ID ) : '';
	if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
		$map  = array(
			'politica-de-envio'                  => 'politica_de_envio_',
			'politica-de-privacidad'             => 'politica_de_privacidad',
			'aviso-legal'                        => 'aviso_legal',
			'terminos-y-condiciones-del-servicio' => 'terminos_y_condiciones_del_servicio',
			'politica-de-garantias'              => 'politica_de_garantias',
		);
		$text = isset( $map[ $post->post_name ] ) ? (string) bk_option_field( $map[ $post->post_name ] ) : '';
	}
	if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
		$text = (string) $post->post_content;
	}
	return wpautop( wp_kses_post( $text ) );
}
