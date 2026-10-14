<?php
/**
 * Página no encontrada (404 real: no redirige a la portada).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bk-wrap bk-404">
	<h1 class="bk-page-title"><?php esc_html_e( 'No encontramos esta página', 'buckler-core' ); ?></h1>
	<p class="bk-lead"><?php esc_html_e( 'Puede que el enlace haya cambiado. Busca lo que necesitas o mira lo nuevo.', 'buckler-core' ); ?></p>
	<?php get_product_search_form(); ?>
	<p class="bk-404__links"><a class="bk-btn" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Ir a la tienda', 'buckler-core' ); ?></a></p>
</div>
