<?php
/**
 * Módulo "sidecart": carrito flotante con sugerencias por talla (reemplaza WooCommerce Side Cart Premium).
 *
 * - El panel se pinta vacío en el footer y se llena con la Store API de WooCommerce: es compatible con
 *   caché de página (nada personal queda en el HTML).
 * - "Añadir al carrito" en la ficha se hace sin recargar (Store API). Si algo falla, el formulario se
 *   envía de la forma normal: nunca se pierde una venta.
 * - Sugerencias: `GET /wp-json/bk/v1/suggest` lee el carrito de la sesión, toma la talla y la línea del
 *   último producto añadido y devuelve prendas de esa línea con ESA talla en stock.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'bk_sc_assets', 25 );
add_action( 'wp_footer', 'bk_sc_markup', 5 );
add_action( 'rest_api_init', 'bk_sc_routes' );

/**
 * ¿Se usa el carrito flotante en esta página? (en carrito y checkout no hace falta).
 *
 * @return bool
 */
function bk_sc_enabled_here() {
	return ! ( is_cart() || is_checkout() || is_admin() );
}

/**
 * CSS/JS del carrito flotante.
 */
function bk_sc_assets() {
	if ( ! bk_sc_enabled_here() ) {
		return;
	}
	wp_enqueue_style( 'bk-sidecart', BK_URL . 'assets/css/sidecart.css', array( 'bk-core' ), bk_asset_ver( 'assets/css/sidecart.css' ) );
	wp_enqueue_script( 'bk-sidecart', BK_URL . 'assets/js/sidecart.js', array( 'bk-core' ), bk_asset_ver( 'assets/js/sidecart.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'bk-sidecart',
		'bkSc',
		array(
			'store'    => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'suggest'  => esc_url_raw( rest_url( 'bk/v1/suggest' ) ),
			'cart'     => wc_get_cart_url(),
			'checkout' => wc_get_checkout_url(),
			'lines'    => array_map(
				static fn( $l ) => array( 'label' => $l['label'], 'url' => bk_url( $l['page'] ) ),
				array_values( (array) bk_config( 'lines' ) )
			),
			'addi'     => (int) bk_config( 'addi_installments' ),
			'icons'    => array(
				'trash' => bk_icon_svg( 'lucide:trash-2', 18 ),
				'truck' => bk_icon_svg( 'lucide:truck', 20 ),
			),
			'i18n'     => array(
				'title'     => __( 'Tu carrito', 'buckler-core' ),
				'empty'     => __( 'Tu carrito está vacío', 'buckler-core' ),
				'emptyText' => __( 'Encuentra tu próximo jean favorito:', 'buckler-core' ),
				'shipping'  => __( 'Tu envío es gratis a toda Colombia', 'buckler-core' ),
				'subtotal'  => __( 'Subtotal', 'buckler-core' ),
				'discount'  => __( 'Descuento', 'buckler-core' ),
				'total'     => __( 'Total', 'buckler-core' ),
				'checkout'  => __( 'Finalizar compra', 'buckler-core' ),
				'viewCart'  => __( 'Ver carrito', 'buckler-core' ),
				'remove'    => __( 'Quitar', 'buckler-core' ),
				'less'      => __( 'Quitar una unidad de', 'buckler-core' ),
				'more'      => __( 'Añadir una unidad de', 'buckler-core' ),
				'qty'       => __( 'Cantidad', 'buckler-core' ),
				'suggest'   => __( 'Combínalo en tu talla', 'buckler-core' ),
				'add'       => __( 'Añadir talla', 'buckler-core' ),
				'added'     => __( 'Añadido al carrito', 'buckler-core' ),
				'adding'    => __( 'Añadiendo a tu carrito…', 'buckler-core' ),
				'removed'   => __( 'Producto quitado del carrito', 'buckler-core' ),
				'error'     => __( 'No pudimos actualizar tu carrito. Intenta de nuevo.', 'buckler-core' ),
				'addi'      => __( 'o %1$d cuotas de %2$s con Addi', 'buckler-core' ),
			),
		)
	);
}

/**
 * Contenedor del panel (vacío; lo llena JS). Igual para todas las visitas → cacheable.
 */
function bk_sc_markup() {
	if ( ! bk_sc_enabled_here() ) {
		return;
	}
	?>
	<div class="bk-sc" id="bk-sc" hidden>
		<div class="bk-sc__scrim" data-bk-sc-close></div>
		<div class="bk-sc__panel" role="dialog" aria-modal="true" aria-labelledby="bk-sc-title">
			<div class="bk-sc__head">
				<h2 class="bk-sc__title" id="bk-sc-title"><?php esc_html_e( 'Tu carrito', 'buckler-core' ); ?> <span data-bk-sc-count></span></h2>
				<button type="button" class="bk-icon-btn bk-sc__close" data-bk-sc-close aria-label="<?php esc_attr_e( 'Cerrar carrito', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:x', 24 ); // phpcs:ignore ?></button>
			</div>
			<div class="bk-sc__body" data-bk-sc-body aria-live="polite" aria-busy="true">
				<div class="bk-sc__loading"><span class="bk-sc__spinner"></span></div>
			</div>
			<div class="bk-sc__foot" data-bk-sc-foot hidden></div>
		</div>
		<div class="bk-sr-only" role="status" aria-live="polite" data-bk-sc-live></div>
	</div>
	<?php
}

/**
 * Ruta de sugerencias.
 */
function bk_sc_routes() {
	register_rest_route(
		'bk/v1',
		'/suggest',
		array(
			'methods'             => 'GET',
			'callback'            => 'bk_sc_suggest',
			'permission_callback' => '__return_true', // Público: solo lee el carrito de la propia sesión.
		)
	);
}

/**
 * Sugerencias para el carrito de la sesión actual.
 *
 * @return WP_REST_Response
 */
function bk_sc_suggest() {
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}
	$items = WC()->cart ? array_reverse( WC()->cart->get_cart() ) : array();

	// Talla y línea del último producto añadido que tenga talla.
	$size_key  = '';
	$size_slug = '';
	$line      = null;
	$in_cart   = array();
	foreach ( $items as $item ) {
		$in_cart[] = (int) $item['product_id'];
		if ( $size_key ) {
			continue;
		}
		foreach ( (array) ( $item['variation'] ?? array() ) as $key => $slug ) {
			if ( false !== stripos( $key, 'talla' ) && '' !== $slug ) {
				$size_key  = $key;
				$size_slug = $slug;
				$line      = bk_product_line( (int) $item['product_id'] );
				break;
			}
		}
	}

	$response = array(
		'size'  => '',
		'line'  => $line ? $line['label'] : '',
		'items' => array(),
	);
	if ( ! $size_key || ! $line ) {
		return rest_ensure_response( $response );
	}

	$tax              = str_replace( 'attribute_', '', $size_key );
	$term             = get_term_by( 'slug', $size_slug, $tax );
	$response['size'] = $term ? $term->name : $size_slug;

	// Candidatos cacheados por línea + talla (se recalculan cada 30 min o al cambiar stock).
	$cache_key  = 'bk_sc_' . md5( $line['slug'] . '|' . $size_key . '|' . $size_slug );
	$candidates = get_transient( $cache_key );
	if ( ! is_array( $candidates ) ) {
		$candidates = array();
		$ids        = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'type'         => 'variable',
				'category'     => array( $line['slug'] ),
				'limit'        => 40,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'ids',
			)
		);
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			foreach ( $p ? $p->get_children() : array() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v && $v->is_purchasable() && $v->is_in_stock() && (string) ( $v->get_attributes()[ $tax ] ?? '' ) === $size_slug ) {
					$candidates[] = array( (int) $pid, (int) $vid );
					break;
				}
			}
			if ( count( $candidates ) >= 12 ) {
				break;
			}
		}
		set_transient( $cache_key, $candidates, 30 * MINUTE_IN_SECONDS );
	}

	foreach ( $candidates as list( $pid, $vid ) ) {
		if ( in_array( $pid, $in_cart, true ) ) {
			continue;
		}
		$p = wc_get_product( $pid );
		$v = wc_get_product( $vid );
		if ( ! $p || ! $v || ! $v->is_in_stock() ) {
			continue;
		}
		$response['items'][] = array(
			'id'        => $vid,
			'parent'    => $pid,
			'name'      => $p->get_name(),
			'url'       => $p->get_permalink(),
			'image'     => (string) wp_get_attachment_image_url( $v->get_image_id() ? $v->get_image_id() : $p->get_image_id(), 'woocommerce_thumbnail' ),
			'price'     => html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $v ) ) ), ENT_QUOTES, 'UTF-8' ),
			'variation' => array(
				array(
					'attribute' => $tax,
					'value'     => $size_slug,
				),
			),
		);
		if ( count( $response['items'] ) >= 6 ) {
			break;
		}
	}

	$res = rest_ensure_response( $response );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_update_product' ) as $bk_hook ) {
	add_action(
		$bk_hook,
		static function () {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bk\\_sc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bk\\_sc\\_%'" ); // phpcs:ignore WordPress.DB
		}
	);
}
unset( $bk_hook );
