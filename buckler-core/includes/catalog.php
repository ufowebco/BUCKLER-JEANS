<?php
/**
 * Datos de catálogo compartidos (tarjeta de producto y tienda con filtros).
 *
 * Tallas en stock por producto, cacheadas en `bk_sizes_{id}` y limpiadas al cambiar producto,
 * variación o stock. No depende de las tablas de búsqueda de WooCommerce (pueden no estar regeneradas).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomía de talla de un producto variable (la primera cuyo nombre contenga "talla" o "size").
 *
 * @param WC_Product_Variable $product Producto.
 * @return string
 */
function bk_size_taxonomy( $product ) {
	$attrs = $product->get_variation_attributes();
	foreach ( array_keys( $attrs ) as $tx ) {
		if ( false !== stripos( $tx, 'talla' ) || false !== stripos( $tx, 'size' ) ) {
			return $tx;
		}
	}
	return (string) array_key_first( $attrs );
}

/**
 * Tallas de un producto en el orden definido, con su disponibilidad.
 *
 * @param WC_Product $product Producto.
 * @return array<int,array{slug:string,label:string,in:bool,tax:string}>
 */
function bk_product_sizes( $product ) {
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return array();
	}
	$key   = 'bk_sizes_' . $product->get_id();
	$sizes = get_transient( $key );
	if ( is_array( $sizes ) ) {
		return $sizes;
	}

	$sizes = array();
	$tx    = bk_size_taxonomy( $product );
	if ( $tx ) {
		$stock = array();
		$any   = false; // Variación "cualquier talla".
		$attr  = sanitize_title( $tx );
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || 'publish' !== $v->get_status() || ! $v->is_in_stock() ) {
				continue;
			}
			$slug = (string) ( $v->get_attributes()[ $attr ] ?? '' );
			if ( '' === $slug ) {
				$any = true;
			} else {
				$stock[ $slug ] = true;
			}
		}
		foreach ( (array) ( $product->get_variation_attributes()[ $tx ] ?? array() ) as $slug ) {
			$term    = taxonomy_exists( $tx ) ? get_term_by( 'slug', $slug, $tx ) : null;
			$sizes[] = array(
				'slug'  => (string) $slug,
				'label' => $term ? $term->name : (string) $slug,
				'in'    => $any || ! empty( $stock[ $slug ] ),
				'tax'   => $tx,
			);
		}
	}
	set_transient( $key, $sizes, 12 * HOUR_IN_SECONDS );
	return $sizes;
}

/**
 * Limpia cachés de catálogo de un producto (o de su padre).
 *
 * @param int|WC_Product $product Producto o ID.
 */
function bk_catalog_flush( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return;
	}
	$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	delete_transient( 'bk_sizes_' . $id );
	delete_transient( 'bk_card_' . $id );
	wp_cache_set( 'bk_catalog_v', microtime( true ), 'bk' );
	update_option( 'bk_catalog_v', (string) microtime( true ), false ); // Invalida el universo de la tienda.
}

foreach ( array( 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_delete_product', 'woocommerce_trash_product' ) as $bk_hook ) {
	add_action( $bk_hook, 'bk_catalog_flush' );
}
unset( $bk_hook );
