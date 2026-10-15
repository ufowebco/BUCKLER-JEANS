<?php
/**
 * Menú de Mi cuenta (buckler-core): datos de la clienta + enlaces con iconos.
 * Reemplaza myaccount/navigation.php de WooCommerce solo en las páginas limpias.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_user  = wp_get_current_user();
$bk_name  = trim( $bk_user->first_name ) ? $bk_user->first_name . ' ' . $bk_user->last_name : $bk_user->display_name;
$bk_icons = array(
	'dashboard'       => 'lucide:layout-dashboard',
	'orders'          => 'lucide:package',
	'downloads'       => 'lucide:receipt',
	'edit-address'    => 'lucide:map-pin',
	'payment-methods' => 'lucide:credit-card',
	'edit-account'    => 'lucide:user',
	'customer-logout' => 'lucide:log-out',
);

do_action( 'woocommerce_before_account_navigation' );
?>
<nav class="woocommerce-MyAccount-navigation bk-acc-nav" aria-label="<?php esc_attr_e( 'Mi cuenta', 'buckler-core' ); ?>">
	<div class="bk-acc-nav__user">
		<span class="bk-acc-nav__avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( trim( $bk_name ), 0, 1 ) ) ); ?></span>
		<div>
			<p class="bk-acc-nav__name"><?php echo esc_html( trim( $bk_name ) ); ?></p>
			<p class="bk-acc-nav__mail"><?php echo esc_html( $bk_user->user_email ); ?></p>
		</div>
	</div>
	<ul>
		<?php foreach ( wc_get_account_menu_items() as $endpoint => $label ) : ?>
			<li class="<?php echo wc_get_account_menu_item_classes( $endpoint ); // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo wc_is_current_account_menu_item( $endpoint ) ? 'aria-current="page"' : ''; ?>>
					<?php echo bk_icon_svg( $bk_icons[ $endpoint ] ?? 'lucide:circle-help', 20 ); // phpcs:ignore ?>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
<?php do_action( 'woocommerce_after_account_navigation' ); ?>
