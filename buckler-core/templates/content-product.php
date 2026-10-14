<?php
/**
 * Tarjeta de producto en el loop de WooCommerce (módulo "cards" de Buckler Core).
 * Sustituye a woocommerce/templates/content-product.php.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}

// Primera fila de la grilla: carga inmediata de la foto principal (mejora el LCP).
$bk_index = (int) wc_get_loop_prop( 'loop', 0 );
$bk_cols  = max( 1, (int) wc_get_loop_prop( 'columns', 4 ) );
?>
<li <?php wc_product_class( 'bk-grid__item', $product ); ?>>
	<?php echo bk_product_card( $product, array( 'eager' => $bk_index <= $bk_cols && 1 === (int) wc_get_loop_prop( 'current_page', 1 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</li>
