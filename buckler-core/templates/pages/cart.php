<?php
/**
 * Carrito (clásico).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bk-wrap bk-woo bk-woo--cart">
	<?php bk_route_crumbs(); ?>
	<h1 class="bk-page-title"><?php esc_html_e( 'Tu carrito', 'buckler-core' ); ?></h1>
	<?php echo do_shortcode( '[woocommerce_cart]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
