<?php
/**
 * Mi cuenta.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bk-wrap bk-woo bk-woo--account">
	<h1 class="bk-page-title"><?php esc_html_e( 'Mi cuenta', 'buckler-core' ); ?></h1>
	<?php echo do_shortcode( '[woocommerce_my_account]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
