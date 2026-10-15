<?php
/**
 * Escritorio de Mi cuenta (buckler-core): último pedido con su envío, accesos rápidos, ayuda y promoción.
 * Reemplaza myaccount/dashboard.php de WooCommerce solo en las páginas limpias. Mantiene los hooks nativos.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_user   = wp_get_current_user();
$bk_first  = trim( $bk_user->first_name ) ? $bk_user->first_name : $bk_user->display_name;
$bk_orders = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ) );
$bk_last   = $bk_orders ? $bk_orders[0] : null;
$bk_count  = (int) wc_get_customer_order_count( get_current_user_id() );
$bk_track  = $bk_last ? $bk_last->get_meta( '_bk_tracking' ) : null;
?>
<div class="bk-acc-dash">
	<div class="bk-acc-dash__hello">
		<?php /* translators: %s: nombre */ ?>
		<h2><?php echo esc_html( sprintf( __( 'Hola, %s', 'buckler-core' ), $bk_first ) ); ?></h2>
		<p><?php esc_html_e( 'Aquí ves tus pedidos, el envío y tus datos. ¿No eres tú?', 'buckler-core' ); ?> <a href="<?php echo esc_url( wc_logout_url() ); ?>"><?php esc_html_e( 'Cerrar sesión', 'buckler-core' ); ?></a></p>
	</div>

	<?php if ( $bk_last ) : ?>
		<section class="bk-acc-last" aria-labelledby="bk-acc-last-t">
			<div class="bk-acc-last__head">
				<h3 id="bk-acc-last-t"><?php esc_html_e( 'Tu último pedido', 'buckler-core' ); ?></h3>
				<span class="bk-status is-<?php echo esc_attr( $bk_last->get_status() ); ?>"><?php echo esc_html( wc_get_order_status_name( $bk_last->get_status() ) ); ?></span>
			</div>
			<dl class="bk-acc-last__data">
				<div><dt><?php esc_html_e( 'Pedido', 'buckler-core' ); ?></dt><dd>#<?php echo esc_html( $bk_last->get_order_number() ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Fecha', 'buckler-core' ); ?></dt><dd><?php echo esc_html( wc_format_datetime( $bk_last->get_date_created() ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Total', 'buckler-core' ); ?></dt><dd><?php echo wp_kses_post( $bk_last->get_formatted_order_total() ); ?></dd></div>
				<?php if ( is_array( $bk_track ) && ! empty( $bk_track['number'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Guía de envío', 'buckler-core' ); ?></dt><dd><?php echo esc_html( trim( ( $bk_track['carrier'] ?? '' ) . ' ' . $bk_track['number'] ) ); ?></dd></div>
				<?php endif; ?>
			</dl>
			<div class="bk-acc-last__thumbs">
				<?php foreach ( array_slice( $bk_last->get_items(), 0, 4 ) as $bk_item ) : ?>
					<?php $bk_p = $bk_item->get_product(); ?>
					<?php if ( $bk_p ) : ?>
						<?php echo $bk_p->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => $bk_item->get_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<a class="bk-btn" href="<?php echo esc_url( $bk_last->get_view_order_url() ); ?>"><?php esc_html_e( 'Ver detalle del pedido', 'buckler-core' ); ?></a>
		</section>
	<?php else : ?>
		<section class="bk-acc-last is-empty">
			<h3><?php esc_html_e( 'Aún no tienes pedidos', 'buckler-core' ); ?></h3>
			<p><?php esc_html_e( 'Encuentra tu jean ideal en nuestras dos líneas.', 'buckler-core' ); ?></p>
			<p class="bk-acc-last__lines">
				<?php foreach ( (array) bk_config( 'lines' ) as $bk_l ) : ?>
					<?php if ( ! empty( $bk_l['page'] ) ) : ?>
						<a class="bk-btn is-ghost" href="<?php echo esc_url( bk_url( $bk_l['page'] ) ); ?>"><?php echo esc_html( $bk_l['label'] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>
		</section>
	<?php endif; ?>

	<ul class="bk-acc-cards">
		<li><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php echo bk_icon_svg( 'lucide:package', 24 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Mis pedidos', 'buckler-core' ); ?></strong><?php /* translators: %d: pedidos */ ?><span><?php echo esc_html( sprintf( _n( '%d pedido', '%d pedidos', $bk_count, 'buckler-core' ), $bk_count ) ); ?></span></a></li>
		<li><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>"><?php echo bk_icon_svg( 'lucide:map-pin', 24 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Direcciones', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'Facturación y envío', 'buckler-core' ); ?></span></a></li>
		<li><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>"><?php echo bk_icon_svg( 'lucide:user', 24 ); // phpcs:ignore ?><strong><?php esc_html_e( 'Mis datos', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'Nombre, correo y contraseña', 'buckler-core' ); ?></span></a></li>
		<li><a href="<?php echo esc_url( bk_whatsapp_url( __( 'Hola, necesito ayuda con mi pedido en buckler.com.co', 'buckler-core' ) ) ); ?>" target="_blank" rel="noopener"><?php echo bk_icon_svg( 'simple-icons:whatsapp', 24 ); // phpcs:ignore ?><strong><?php esc_html_e( '¿Necesitas ayuda?', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'Escríbenos por WhatsApp', 'buckler-core' ); ?></span></a></li>
	</ul>

	<?php echo bk_review_promo( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
<?php
// Hooks nativos (otros plugins pueden añadir contenido aquí).
do_action( 'woocommerce_account_dashboard' );
do_action( 'woocommerce_before_my_account' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks
do_action( 'woocommerce_after_my_account' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks
