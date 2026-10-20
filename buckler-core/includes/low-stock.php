<?php
/**
 * "¡Últimas unidades!" en las prendas de ¡Sale! (decisión del cliente 2026-10-20: casi todas quedan con 1 prenda en
 * alguna talla). Solo se muestra cuando es verdad: alguna talla disponible tiene existencias gestionadas entre 1 y
 * bk_config( 'low_stock' )['max'] (por defecto 2).
 *
 * - Ficha: recuadro junto al precio con las tallas ("Quedan pocas en talla 8 y 12") y, al elegir una de esas tallas,
 *   "¡Solo queda 1!" debajo de los botones (pages.js, con max_qty de las variaciones de Woo).
 * - Tarjetas: etiqueta "Últimas unidades" solo con bk_config( 'low_stock' )['cards'] = true (apagada por defecto:
 *   el cliente la vio muy invasiva en el grid, 2026-10-20).
 *
 * Config: bk_config( 'low_stock' ) = { max, only_sale, cards, label }; 'max' 0 lo apaga. Filtro bk_low_stock_sizes.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes.
 *
 * @return array{max:int,only_sale:bool,label:string}
 */
function bk_low_stock_cfg() {
	$c = (array) bk_config( 'low_stock' );
	return array(
		'max'       => max( 0, (int) ( $c['max'] ?? 2 ) ),
		'only_sale' => (bool) ( $c['only_sale'] ?? true ),
		'label'     => (string) ( $c['label'] ?? __( '¡Últimas unidades!', 'buckler-core' ) ),
	);
}

/**
 * Tallas con pocas existencias: [ ['label' => '8', 'qty' => 1], … ] en orden de talla. Vacío si no aplica.
 *
 * @param WC_Product $product Producto (variable).
 * @return array<int,array{label:string,qty:int}>
 */
function bk_low_stock_sizes( $product ) {
	$cfg = bk_low_stock_cfg();
	if ( ! $product instanceof WC_Product || ! $cfg['max'] || ! $product->is_type( 'variable' ) || ! $product->is_in_stock() ) {
		return array();
	}
	if ( $cfg['only_sale'] && ! ( function_exists( 'bk_disc_is_sale' ) && bk_disc_is_sale( $product ) ) ) {
		return array();
	}
	$out = array();
	foreach ( $product->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || 'publish' !== $v->get_status() || ! $v->is_in_stock() || ! $v->managing_stock() ) {
			continue;
		}
		$qty = (int) $v->get_stock_quantity();
		if ( $qty < 1 || $qty > $cfg['max'] ) {
			continue;
		}
		$attrs = array_filter( (array) $v->get_attributes() );
		$label = '';
		foreach ( $attrs as $tx => $slug ) {
			$term  = taxonomy_exists( $tx ) ? get_term_by( 'slug', $slug, $tx ) : null;
			$label = $term ? $term->name : (string) $slug;
			break;
		}
		if ( '' !== $label ) {
			$out[] = array( 'label' => $label, 'qty' => $qty );
		}
	}
	usort( $out, static fn( $a, $b ) => ( is_numeric( $a['label'] ) && is_numeric( $b['label'] ) ) ? ( (float) $a['label'] <=> (float) $b['label'] ) : strnatcasecmp( $a['label'], $b['label'] ) );
	return (array) apply_filters( 'bk_low_stock_sizes', $out, $product );
}

/**
 * "talla 8", "tallas 8 y 12", "tallas 6, 8 y 12".
 *
 * @param array $sizes Tallas.
 * @return string
 */
function bk_low_stock_list( $sizes ) {
	$l = array_column( $sizes, 'label' );
	if ( count( $l ) < 2 ) {
		return sprintf( __( 'talla %s', 'buckler-core' ), $l[0] ?? '' );
	}
	$last = array_pop( $l );
	return sprintf( __( 'tallas %1$s y %2$s', 'buckler-core' ), implode( ', ', $l ), $last );
}

/**
 * Ficha: recuadro junto al precio.
 */
function bk_low_stock_pdp() {
	global $product;
	$sizes = $product instanceof WC_Product ? bk_low_stock_sizes( $product ) : array();
	if ( ! $sizes ) {
		return;
	}
	$ones = count( array_filter( $sizes, static fn( $z ) => 1 === $z['qty'] ) ) === count( $sizes );
	if ( $ones && 1 === count( $sizes ) ) {
		$txt = sprintf( __( 'Solo queda 1 en %s.', 'buckler-core' ), bk_low_stock_list( $sizes ) );
	} elseif ( $ones ) {
		$txt = sprintf( __( 'Solo queda 1 prenda en cada una: %s.', 'buckler-core' ), bk_low_stock_list( $sizes ) );
	} else {
		$txt = sprintf( __( 'Quedan pocas en %s.', 'buckler-core' ), bk_low_stock_list( $sizes ) );
	}
	echo '<p class="bk-low-stock" role="note">' . bk_icon_svg( 'lucide:flame', 18 ) . '<span><strong>' . esc_html( bk_low_stock_cfg()['label'] ) . '</strong> ' . esc_html( $txt ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'woocommerce_single_product_summary', 'bk_low_stock_pdp', 11 );

// BodyCommerce borra lo enganchado a woocommerce_single_product_summary (ver bk_pdp_ensure_extras): se vuelve a poner.
add_action(
	'woocommerce_before_single_product',
	static function () {
		if ( false === has_action( 'woocommerce_single_product_summary', 'bk_low_stock_pdp' ) ) {
			add_action( 'woocommerce_single_product_summary', 'bk_low_stock_pdp', 11 );
		}
	},
	2
);

/**
 * Tope para el aviso por talla de la ficha (pages.js): 0 = no avisar en esta página.
 *
 * @return int
 */
function bk_low_stock_js_max() {
	$cfg = bk_low_stock_cfg();
	if ( ! $cfg['max'] || ! is_product() ) {
		return 0;
	}
	$p = wc_get_product( get_queried_object_id() );
	if ( ! $p || ( $cfg['only_sale'] && ! ( function_exists( 'bk_disc_is_sale' ) && bk_disc_is_sale( $p ) ) ) ) {
		return 0;
	}
	return $cfg['max'];
}

// Textos del aviso por talla en pages.js.
add_filter(
	'bk_pages_i18n',
	static function ( $t ) {
		$t['lowMax']  = bk_low_stock_js_max();
		$t['lowOne']  = __( '¡Solo queda 1 en esta talla!', 'buckler-core' );
		$t['lowMany'] = __( '¡Solo quedan %d en esta talla!', 'buckler-core' );
		return $t;
	}
);
