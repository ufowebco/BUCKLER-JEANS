<?php
/**
 * Checkout (clásico) y página de gracias.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_received = function_exists( 'is_order_received_page' ) && is_order_received_page();
?>
<div class="bk-wrap bk-woo bk-woo--checkout<?php echo $bk_received ? ' is-received' : ''; ?>">
	<?php bk_route_crumbs(); ?>
	<h1 class="bk-page-title"><?php echo $bk_received ? esc_html__( '¡Gracias por tu compra!', 'buckler-core' ) : esc_html__( 'Finalizar compra', 'buckler-core' ); ?></h1>
	<?php if ( ! $bk_received ) : ?>
		<p class="bk-secure"><?php echo bk_icon_svg( 'lucide:shield-check', 18 ); // phpcs:ignore ?><?php esc_html_e( 'Pago seguro · Envío gratis a toda Colombia', 'buckler-core' ); ?></p>
	<?php endif; ?>
	<?php echo do_shortcode( '[woocommerce_checkout]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
