<?php
/**
 * Página de tienda de buckler-core (tienda, categorías, búsqueda, líneas y ¡Sale!) con el header/footer del tema.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="main-content" class="bk-shop-page">
	<?php echo do_shortcode( (string) ( $GLOBALS['bk_shop_shortcode'] ?? '[bk_tienda]' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- salida del shortcode propio. ?>
</div>
<?php
get_footer();
