<?php
/**
 * Mi cuenta: acceso con beneficios (sin sesión) y panel propio (con sesión, ver bk_account_templates()).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_guest = ! is_user_logged_in();
?>
<div class="bk-wrap bk-woo bk-woo--account<?php echo $bk_guest ? ' is-guest' : ''; ?>">
	<?php bk_route_crumbs(); ?>
	<?php if ( $bk_guest ) : ?>
		<div class="bk-login">
			<div class="bk-login__main">
				<h1 class="bk-page-title"><?php echo is_wc_endpoint_url( 'lost-password' ) ? esc_html__( 'Recupera tu contraseña', 'buckler-core' ) : esc_html__( 'Ingresa a tu cuenta', 'buckler-core' ); ?></h1>
				<p class="bk-lead"><?php esc_html_e( 'Revisa tus pedidos, sigue tu envío y compra más rápido.', 'buckler-core' ); ?></p>
				<?php echo do_shortcode( '[woocommerce_my_account]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="bk-login__help">
					<?php echo bk_icon_svg( 'simple-icons:whatsapp', 16 ); // phpcs:ignore ?>
					<?php esc_html_e( '¿Problemas para ingresar?', 'buckler-core' ); ?>
					<a href="<?php echo esc_url( bk_whatsapp_url( __( 'Hola, necesito ayuda para ingresar a mi cuenta en buckler.com.co', 'buckler-core' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Escríbenos por WhatsApp', 'buckler-core' ); ?></a>
				</p>
			</div>
			<aside class="bk-login__aside" aria-labelledby="bk-login-why">
				<h2 id="bk-login-why"><?php esc_html_e( 'En tu cuenta puedes', 'buckler-core' ); ?></h2>
				<ul>
					<li><?php echo bk_icon_svg( 'lucide:package', 22 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Ver tus pedidos', 'buckler-core' ); ?></strong><?php esc_html_e( 'Estado de cada compra, productos, tallas y total.', 'buckler-core' ); ?></span></li>
					<li><?php echo bk_icon_svg( 'lucide:truck', 22 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Seguir tu envío', 'buckler-core' ); ?></strong><?php esc_html_e( 'Transportadora y número de guía apenas despachamos.', 'buckler-core' ); ?></span></li>
					<li><?php echo bk_icon_svg( 'lucide:map-pin', 22 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Guardar tus direcciones', 'buckler-core' ); ?></strong><?php esc_html_e( 'Tus datos de envío quedan listos para la próxima compra.', 'buckler-core' ); ?></span></li>
					<li><?php echo bk_icon_svg( 'lucide:rotate-ccw', 22 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Volver a pedir', 'buckler-core' ); ?></strong><?php esc_html_e( 'Repite una compra anterior en un clic.', 'buckler-core' ); ?></span></li>
					<li><?php echo bk_icon_svg( 'lucide:lock', 22 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Cuidar tus datos', 'buckler-core' ); ?></strong><?php esc_html_e( 'Cambia tu correo o contraseña cuando quieras.', 'buckler-core' ); ?></span></li>
				</ul>
				<?php if ( 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) && 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) : ?>
					<p class="bk-login__note"><?php esc_html_e( '¿Aún no tienes cuenta? Puedes crearla al finalizar tu compra.', 'buckler-core' ); ?></p>
				<?php endif; ?>
				<a class="bk-btn is-ghost" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Ir a la tienda', 'buckler-core' ); ?></a>
			</aside>
		</div>
	<?php else : ?>
		<h1 class="bk-page-title"><?php esc_html_e( 'Mi cuenta', 'buckler-core' ); ?></h1>
		<?php echo do_shortcode( '[woocommerce_my_account]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</div>
