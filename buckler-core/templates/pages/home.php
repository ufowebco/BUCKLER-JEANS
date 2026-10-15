<?php
/**
 * Inicio: banners ACF a ancho completo, intro con el único H1, líneas, Lo nuevo (pestañas Slim/Curvy),
 * ofertas y beneficios. Todo sale de datos existentes (ACF, categorías y productos).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_lines = (array) bk_config( 'lines' );
$bk_tabs  = array();
foreach ( $bk_lines as $bk_slug => $bk_l ) {
	$bk_child = (string) ( $bk_l['new_child'] ?? '' );
	if ( $bk_child && get_term_by( 'slug', $bk_child, 'product_cat' ) ) {
		$bk_tabs[ $bk_child ] = array(
			'label' => $bk_l['label'],
			'more'  => add_query_arg( array( 'nuevo' => 1, 'linea' => str_replace( 'linea-', '', $bk_slug ) ), wc_get_page_permalink( 'shop' ) ),
		);
	}
}
$bk_sale = function_exists( 'wc_get_product_ids_on_sale' ) ? wc_get_product_ids_on_sale() : array();
?>
<?php echo do_shortcode( '[bk_hero autoplay="6"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

<section class="bk-wrap bk-home-intro">
	<h1><?php esc_html_e( 'Jeans y moda en denim para mujer', 'buckler-core' ); ?></h1>
	<p>
		<?php
		/* translators: 1: tallas Slim, 2: tallas Curvy */
		echo esc_html( sprintf( __( 'Línea Slim (%1$s) y Línea Curvy (%2$s). Encuentra tu talla y tu silueta.', 'buckler-core' ), mb_strtolower( $bk_lines['linea-slim']['sizes'] ?? '' ), mb_strtolower( $bk_lines['linea-curvy']['sizes'] ?? '' ) ) );
		?>
	</p>
</section>

<?php echo do_shortcode( '[bk_lineas]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

<?php do_action( 'bk_home_after_lines' ); // Campaña de descuentos vigente (includes/discounts.php). ?>

<?php if ( $bk_tabs ) : ?>
	<section class="bk-wrap bk-home-new" aria-labelledby="bk-new-t">
		<div class="bk-section-head">
			<h2 id="bk-new-t" class="bk-section-title"><?php esc_html_e( 'Lo nuevo', 'buckler-core' ); ?></h2>
			<?php if ( count( $bk_tabs ) > 1 ) : ?>
				<div class="bk-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Línea', 'buckler-core' ); ?>">
					<?php $bk_i = 0; foreach ( $bk_tabs as $bk_cat => $bk_t ) : ?>
						<button type="button" role="tab" id="bk-tab-<?php echo esc_attr( $bk_cat ); ?>" aria-controls="bk-panel-<?php echo esc_attr( $bk_cat ); ?>" aria-selected="<?php echo $bk_i ? 'false' : 'true'; ?>" tabindex="<?php echo $bk_i ? '-1' : '0'; ?>"><?php echo esc_html( $bk_t['label'] ); ?></button>
					<?php ++$bk_i; endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php $bk_i = 0; foreach ( $bk_tabs as $bk_cat => $bk_t ) : ?>
			<div role="tabpanel" id="bk-panel-<?php echo esc_attr( $bk_cat ); ?>" aria-labelledby="bk-tab-<?php echo esc_attr( $bk_cat ); ?>" <?php echo $bk_i ? 'hidden' : ''; ?>>
				<?php echo do_shortcode( '[bk_productos categoria="' . esc_attr( $bk_cat ) . '" limite="8" orden="date" eager="' . ( $bk_i ? 0 : 2 ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="bk-more"><a class="bk-btn is-ghost" href="<?php echo esc_url( $bk_t['more'] ); ?>"><?php /* translators: %s: línea */ echo esc_html( sprintf( __( 'Ver todo lo nuevo de %s', 'buckler-core' ), $bk_t['label'] ) ); ?></a></p>
			</div>
		<?php ++$bk_i; endforeach; ?>
	</section>
<?php endif; ?>

<?php if ( $bk_sale ) : ?>
	<section class="bk-wrap bk-home-sale">
		<?php echo do_shortcode( '[bk_productos oferta="1" limite="4" orden="rand" titulo="' . esc_attr__( '¡Sale! Prendas con descuento', 'buckler-core' ) . '" ver_todo="' . esc_attr( (string) bk_config( 'sale_page' ) ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</section>
<?php endif; ?>

<section class="bk-wrap bk-home-benefits" aria-label="<?php esc_attr_e( 'Por qué comprar en Buckler', 'buckler-core' ); ?>">
	<ul>
		<li><?php echo bk_icon_svg( 'lucide:truck', 28 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Envío gratis', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'A toda Colombia', 'buckler-core' ); ?></span></li>
		<li><?php echo bk_icon_svg( 'lucide:credit-card', 28 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Paga como quieras', 'buckler-core' ); ?></strong><span><?php /* translators: %d: cuotas */ echo esc_html( sprintf( __( 'Addi en %d cuotas, Bold, Wompi y tarjetas', 'buckler-core' ), (int) bk_config( 'addi_installments' ) ) ); ?></span></li>
		<li><?php echo bk_icon_svg( 'lucide:ruler', 28 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Tu talla, tu silueta', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'Slim 6 a 12 · Curvy 14 a 24', 'buckler-core' ); ?></span></li>
		<li><?php echo bk_icon_svg( 'simple-icons:whatsapp', 28 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Te asesoramos', 'buckler-core' ); ?></strong><span><a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Escríbenos por WhatsApp', 'buckler-core' ); ?></a></span></li>
	</ul>
</section>
