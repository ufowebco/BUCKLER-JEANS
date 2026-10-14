<?php
/**
 * Módulo "cards": tarjeta de producto con slider de galería.
 *
 * - Reemplaza la plantilla `content-product.php` de WooCommerce: cualquier grilla que use el loop de Woo
 *   (tienda, categorías, módulo de productos de Divi, relacionados, [products]) muestra la tarjeta nueva.
 * - Shortcode [bk_productos] para secciones como "Lo nuevo".
 * - Etiqueta "Nuevo" = producto destacado (★) — reemplaza el snippet "favorito → Nuevo".
 * - Datos pesados (galería, tallas en stock, descuento) cacheados por producto y limpiados al cambiar
 *   producto, variación o stock.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'bk_cards_assets', 25 );

/**
 * CSS y JS de las tarjetas (livianos; se cargan en todo el front porque hay grillas en muchas páginas).
 */
function bk_cards_assets() {
	wp_enqueue_style( 'bk-cards', BK_URL . 'assets/css/cards.css', array( 'bk-core' ), bk_asset_ver( 'assets/css/cards.css' ) );
	wp_enqueue_script( 'bk-cards', BK_URL . 'assets/js/cards.js', array(), bk_asset_ver( 'assets/js/cards.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
}

/* ---------------------------------------------------------------------------
 * Datos de la tarjeta (cacheados)
 * ------------------------------------------------------------------------- */

/**
 * Datos calculados de un producto para la tarjeta.
 *
 * @param WC_Product $product Producto.
 * @return array{images:int[],sizes:array,line:string,discount:int}
 */
function bk_card_data( $product ) {
	$key  = 'bk_card_' . $product->get_id();
	$data = get_transient( $key );
	if ( is_array( $data ) ) {
		return $data;
	}

	$images = array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) );
	$images = array_slice( array_values( array_unique( $images ) ), 0, 5 );

	$line = bk_product_line( $product->get_id() );
	$line = $line ? $line['label'] : '';

	$sizes    = array();
	$discount = 0;
	if ( $product->is_type( 'variable' ) ) {
		/** @var WC_Product_Variable $product */
		$attrs   = $product->get_variation_attributes();
		$size_tx = '';
		foreach ( array_keys( $attrs ) as $tx ) {
			if ( false !== stripos( $tx, 'talla' ) || false !== stripos( $tx, 'size' ) ) {
				$size_tx = $tx;
				break;
			}
		}
		$size_tx = $size_tx ? $size_tx : (string) array_key_first( $attrs );
		if ( $size_tx ) {
			$stock = array();
			$any   = false; // Variación "cualquier talla".
			$key   = sanitize_title( $size_tx );
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || 'publish' !== $v->get_status() || ! $v->is_in_stock() ) {
					continue;
				}
				$slug = (string) ( $v->get_attributes()[ $key ] ?? '' );
				if ( '' === $slug ) {
					$any = true;
				} else {
					$stock[ $slug ] = true;
				}
			}
			foreach ( (array) $attrs[ $size_tx ] as $slug ) {
				$term    = taxonomy_exists( $size_tx ) ? get_term_by( 'slug', $slug, $size_tx ) : null;
				$sizes[] = array(
					'label' => $term ? $term->name : $slug,
					'in'    => $any || ! empty( $stock[ $slug ] ),
				);
			}
		}
		$prices = $product->get_variation_prices( true );
		foreach ( $prices['regular_price'] as $vid => $regular ) {
			$sale = (float) ( $prices['sale_price'][ $vid ] ?? $regular );
			if ( (float) $regular > 0 && $sale < (float) $regular ) {
				$discount = max( $discount, (int) round( ( 1 - $sale / (float) $regular ) * 100 ) );
			}
		}
	} elseif ( $product->is_on_sale() && (float) $product->get_regular_price() > 0 ) {
		$discount = (int) round( ( 1 - (float) $product->get_sale_price() / (float) $product->get_regular_price() ) * 100 );
	}

	$data = array(
		'images'   => $images,
		'sizes'    => $sizes,
		'line'     => $line,
		'discount' => $discount,
	);
	set_transient( $key, $data, 12 * HOUR_IN_SECONDS );
	return $data;
}

/**
 * Limpia la caché de la tarjeta de un producto (o del padre si es una variación).
 *
 * @param int|WC_Product $product Producto o ID.
 */
function bk_card_flush( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( $product ) {
		delete_transient( 'bk_card_' . ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) );
	}
}
foreach ( array( 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $bk_hook ) {
	add_action( $bk_hook, 'bk_card_flush' );
}
unset( $bk_hook );

/* ---------------------------------------------------------------------------
 * HTML de la tarjeta
 * ------------------------------------------------------------------------- */

/**
 * Tarjeta de producto.
 *
 * @param WC_Product $product Producto.
 * @param array      $args    heading (h2|h3), eager (primera fila: carga inmediata de la 1.ª foto).
 * @return string
 */
function bk_product_card( $product, $args = array() ) {
	$args    = wp_parse_args( $args, array( 'heading' => 'h3', 'eager' => false ) );
	$d       = bk_card_data( $product );
	$url     = $product->get_permalink();
	$name    = $product->get_name();
	$in      = $product->is_in_stock();
	$heading = in_array( $args['heading'], array( 'h2', 'h3', 'h4' ), true ) ? $args['heading'] : 'h3';
	$total   = count( $d['images'] );
	$blank   = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 2 3%22/%3E';

	// Fotos: la primera normal; las demás con data-bk-src (se cargan al interactuar con la tarjeta).
	$imgs = '';
	foreach ( $d['images'] as $i => $id ) {
		/* translators: 1: producto, 2: posición, 3: total */
		$alt   = $total > 1 ? sprintf( __( '%1$s, foto %2$d de %3$d', 'buckler-core' ), $name, $i + 1, $total ) : $name;
		$attrs = array(
			'class'    => 'bk-card__img',
			'alt'      => $alt,
			'sizes'    => '(max-width: 767px) 50vw, (max-width: 1280px) 25vw, 300px',
			'loading'  => ( 0 === $i && $args['eager'] ) ? 'eager' : 'lazy',
			'decoding' => 'async',
		);
		if ( 0 === $i && $args['eager'] ) {
			$attrs['fetchpriority'] = 'high';
		}
		$html = wp_get_attachment_image( $id, 'woocommerce_thumbnail', false, $attrs );
		if ( $i > 0 ) {
			// Diferir: src → data-bk-src, srcset → data-bk-srcset.
			$html = preg_replace( '/\ssrc="([^"]+)"/', ' src="' . $blank . '" data-bk-src="$1"', $html, 1 );
			$html = preg_replace( '/\ssrcset="([^"]+)"/', ' data-bk-srcset="$1"', $html, 1 );
		}
		$imgs .= $html;
	}
	if ( ! $imgs ) {
		$imgs = wc_placeholder_img( 'woocommerce_thumbnail', array( 'class' => 'bk-card__img', 'alt' => $name ) );
	}

	// Etiquetas.
	$badges = '';
	if ( ! $in ) {
		$badges .= '<span class="bk-badge bk-badge--out">' . esc_html__( 'Agotado', 'buckler-core' ) . '</span>';
	} elseif ( $d['discount'] > 0 ) {
		/* translators: %d: porcentaje */
		$badges .= '<span class="bk-badge bk-badge--sale">' . esc_html( sprintf( __( '-%d %%', 'buckler-core' ), $d['discount'] ) ) . '</span>';
	}
	if ( $product->is_featured() && $in ) {
		$badges .= '<span class="bk-badge bk-badge--new">' . esc_html__( 'Nuevo', 'buckler-core' ) . '</span>';
	}

	$sizes_vis = '';
	$avail     = array();
	foreach ( $d['sizes'] as $s ) {
		$sizes_vis .= '<span class="' . ( $s['in'] ? '' : 'is-off' ) . '">' . esc_html( $s['label'] ) . '</span>';
		if ( $s['in'] ) {
			$avail[] = $s['label'];
		}
	}

	$addi  = '';
	$n     = (int) bk_config( 'addi_installments' );
	$price = $product->is_type( 'variable' ) ? (float) $product->get_variation_price( 'min', true ) : (float) wc_get_price_to_display( $product );
	if ( $n > 1 && $in && $price > 0 ) {
		/* translators: 1: cuotas, 2: valor */
		$addi = '<span class="bk-card__addi">' . esc_html( sprintf( __( 'o %1$d cuotas de %2$s con Addi', 'buckler-core' ), $n, html_entity_decode( wp_strip_all_tags( wc_price( ceil( $price / $n ) ) ) ) ) ) . '</span>';
	}

	ob_start();
	?>
	<article class="bk-card<?php echo $in ? '' : ' is-out'; ?>" data-bk-card>
		<div class="bk-card__media">
			<a class="bk-card__track" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true"><?php echo $imgs; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image. ?></a>
			<?php if ( $badges ) : ?><div class="bk-card__badges"><?php echo $badges; // phpcs:ignore ?></div><?php endif; ?>
			<?php if ( $total > 1 ) : ?>
				<button type="button" class="bk-card__arrow bk-card__prev" tabindex="-1" aria-hidden="true"><?php echo bk_icon_svg( 'lucide:chevron-left', 18 ); // phpcs:ignore ?></button>
				<button type="button" class="bk-card__arrow bk-card__next" tabindex="-1" aria-hidden="true"><?php echo bk_icon_svg( 'lucide:chevron-right', 18 ); // phpcs:ignore ?></button>
				<div class="bk-card__dots" aria-hidden="true"><?php echo str_repeat( '<i></i>', $total ); // phpcs:ignore ?></div>
			<?php endif; ?>
			<?php if ( $sizes_vis && $in ) : ?><div class="bk-card__sizes" aria-hidden="true"><?php echo $sizes_vis; // phpcs:ignore ?></div><?php endif; ?>
		</div>
		<div class="bk-card__body">
			<?php if ( $d['line'] ) : ?><span class="bk-card__line"><?php echo esc_html( $d['line'] ); ?></span><?php endif; ?>
			<<?php echo esc_html( $heading ); ?> class="bk-card__title"><a class="bk-card__link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a></<?php echo esc_html( $heading ); ?>>
			<span class="bk-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			<?php echo $addi; // phpcs:ignore ?>
			<?php if ( $avail ) : ?>
				<?php /* translators: %s: tallas */ ?>
				<span class="bk-sr-only"><?php echo esc_html( sprintf( __( 'Tallas disponibles: %s', 'buckler-core' ), implode( ', ', $avail ) ) ); ?></span>
			<?php endif; ?>
		</div>
	</article>
	<?php
	return trim( ob_get_clean() );
}

/* ---------------------------------------------------------------------------
 * Integración con el loop de WooCommerce y shortcode
 * ------------------------------------------------------------------------- */

add_filter( 'wc_get_template_part', 'bk_cards_template', 20, 3 );

/**
 * Usa la tarjeta propia en todas las grillas del loop de WooCommerce.
 *
 * @param string $template Ruta.
 * @param string $slug     Slug.
 * @param string $name     Nombre.
 * @return string
 */
function bk_cards_template( $template, $slug, $name ) {
	return ( 'content' === $slug && 'product' === $name ) ? BK_DIR . 'templates/content-product.php' : $template;
}

add_shortcode( 'bk_productos', 'bk_shortcode_productos' );

/**
 * [bk_productos categoria="linea-nueva" limite="8" orden="date" destacados="0" oferta="0" columnas="4" agotados="0" titulo="Lo nuevo" ver_todo="/tienda/"]
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shortcode_productos( $atts ) {
	$a = shortcode_atts(
		array(
			'categoria'  => '',
			'limite'     => 8,
			'orden'      => 'date',
			'destacados' => 0,
			'oferta'     => 0,
			'columnas'   => 4,
			'agotados'   => 0,
			'ids'        => '',
			'titulo'     => '',
			'ver_todo'   => '',
			'eager'      => 0,
		),
		$atts,
		'bk_productos'
	);

	$cache_key = 'bk_q_' . md5( wp_json_encode( $a ) );
	$ids       = get_transient( $cache_key );
	if ( ! is_array( $ids ) ) {
		$q = array(
			'status'  => 'publish',
			'limit'   => min( 48, max( 1, (int) $a['limite'] ) ),
			'return'  => 'ids',
			'orderby' => in_array( $a['orden'], array( 'date', 'popularity', 'price', 'rand', 'menu_order' ), true ) ? $a['orden'] : 'date',
			'order'   => 'price' === $a['orden'] ? 'ASC' : 'DESC',
		);
		if ( 'popularity' === $q['orderby'] ) {
			$q['orderby']  = 'meta_value_num';
			$q['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( $a['categoria'] ) {
			$q['category'] = array_map( 'sanitize_title', explode( ',', $a['categoria'] ) );
		}
		if ( $a['destacados'] ) {
			$q['featured'] = true;
		}
		if ( $a['oferta'] ) {
			$q['include'] = wc_get_product_ids_on_sale() ?: array( 0 );
		}
		if ( $a['ids'] ) {
			$q['include'] = array_map( 'absint', explode( ',', $a['ids'] ) );
		}
		if ( ! $a['agotados'] ) {
			$q['stock_status'] = 'instock';
		}
		$ids = wc_get_products( $q );
		set_transient( $cache_key, $ids, 15 * MINUTE_IN_SECONDS );
	}
	if ( ! $ids ) {
		return '';
	}

	$cols = min( 6, max( 2, (int) $a['columnas'] ) );
	$out  = '';
	foreach ( $ids as $i => $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() ) {
			$out .= '<li class="bk-grid__item">' . bk_product_card( $p, array( 'eager' => $a['eager'] && $i < $cols ) ) . '</li>';
		}
	}
	$head = '';
	if ( $a['titulo'] || $a['ver_todo'] ) {
		$head = '<div class="bk-section-head">' . ( $a['titulo'] ? '<h2 class="bk-section-title">' . esc_html( $a['titulo'] ) . '</h2>' : '' )
			. ( $a['ver_todo'] ? '<a class="bk-section-more" href="' . esc_url( bk_url( $a['ver_todo'] ) ) . '">' . esc_html__( 'Ver todo', 'buckler-core' ) . ' ' . bk_icon_svg( 'lucide:arrow-right', 16 ) . '</a>' : '' ) . '</div>';
	}
	return '<section class="bk-products">' . $head . '<ul class="bk-grid" style="--bk-cols:' . (int) $cols . '">' . $out . '</ul></section>';
}

add_action( 'woocommerce_update_product', 'bk_flush_query_cache' );
add_action( 'woocommerce_product_set_stock_status', 'bk_flush_query_cache' );
add_action( 'woocommerce_variation_set_stock_status', 'bk_flush_query_cache' );

/**
 * Limpia las consultas cacheadas de [bk_productos] (prefijo bk_q_).
 */
function bk_flush_query_cache() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bk\\_q\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bk\\_q\\_%'" ); // phpcs:ignore WordPress.DB
}
