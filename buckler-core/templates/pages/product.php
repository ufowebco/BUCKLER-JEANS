<?php
/**
 * Ficha de producto: plantilla nativa de WooCommerce reordenada (ver bk_pdp_hooks()).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bk-wrap bk-pdp">
	<?php
	bk_pdp_crumbs();
	while ( have_posts() ) {
		the_post();
		wc_get_template_part( 'content', 'single-product' );
	}
	?>
</div>
