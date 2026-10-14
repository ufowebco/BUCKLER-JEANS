<?php
/**
 * Tienda, categorías, líneas, ¡Sale! y búsqueda.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bk-shop-page">
	<?php bk_route_crumbs(); ?>
	<?php echo do_shortcode( bk_shop_takeover_shortcode() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
