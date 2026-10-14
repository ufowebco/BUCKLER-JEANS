<?php
/**
 * Footer propio: [bk_footer whatsapp="1"]
 *
 * Beneficios · marca y redes · tienda por línea · ayuda · contacto y horarios · legales · medios de pago.
 * Todos los enlaces son <a> reales (en el footer anterior varias filas no eran enlaces: dead clicks).
 * Las políticas se leen del CPT `politica-empresa`, así que un cambio de URL no rompe el footer.
 *
 * Uso: Theme Builder → Footer global: un módulo Texto con [bk_footer] en una sección sin padding.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'bk_footer', 'bk_shortcode_footer' );
add_shortcode( 'bk_whatsapp', 'bk_whatsapp_button' );

/**
 * Políticas publicadas (CPT politica-empresa), cacheadas.
 *
 * @return array<int,array{title:string,url:string}>
 */
function bk_policies() {
	$cache = get_transient( 'bk_policies' );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$out   = array();
	$posts = get_posts(
		array(
			'post_type'      => 'politica-empresa',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'no_found_rows'  => true,
		)
	);
	foreach ( $posts as $p ) {
		$out[] = array(
			'title' => trim( preg_replace( '/\s+/u', ' ', html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ) ),
			'url'   => (string) get_permalink( $p ),
		);
	}
	set_transient( 'bk_policies', $out, DAY_IN_SECONDS );
	return $out;
}

add_action(
	'save_post_politica-empresa',
	static function () {
		delete_transient( 'bk_policies' );
	}
);

/**
 * Enlace externo accesible.
 *
 * @param string $url   URL.
 * @param string $label Texto (ya escapado o plano).
 * @param string $icon  Icono opcional.
 * @return string
 */
function bk_ext_link( $url, $label, $icon = '' ) {
	return sprintf(
		'<a href="%1$s" target="_blank" rel="noopener">%2$s%3$s<span class="bk-sr-only"> %4$s</span></a>',
		esc_url( $url ),
		$icon ? bk_icon_svg( $icon, 18 ) : '',
		esc_html( $label ),
		esc_html__( '(abre en una pestaña nueva)', 'buckler-core' )
	);
}

/**
 * Botón flotante de WhatsApp [bk_whatsapp].
 *
 * @return string
 */
function bk_whatsapp_button() {
	if ( ! bk_on( 'layout' ) ) {
		return '';
	}
	return sprintf(
		'<a class="bk-wa" href="%1$s" target="_blank" rel="noopener" aria-label="%2$s">%3$s</a>',
		esc_url( bk_whatsapp_url() ),
		esc_attr__( 'Escríbenos por WhatsApp (abre en una pestaña nueva)', 'buckler-core' ),
		bk_icon_svg( 'simple-icons:whatsapp', 28 )
	);
}

/**
 * Shortcode [bk_footer].
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shortcode_footer( $atts ) {
	if ( ! bk_on( 'layout' ) ) {
		return '';
	}
	$atts = shortcode_atts( array( 'whatsapp' => 1 ), $atts, 'bk_footer' );
	$ig   = (string) bk_config( 'instagram' );
	$acct = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : bk_url( '/mi-cuenta/' );

	ob_start();
	?>
	<div class="bk-footer">
		<ul class="bk-perks" aria-label="<?php esc_attr_e( 'Beneficios', 'buckler-core' ); ?>">
			<li><?php echo bk_icon_svg( 'lucide:truck', 28 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Envíos gratis', 'buckler-core' ); ?></strong><?php esc_html_e( 'a todo el país', 'buckler-core' ); ?></span></li>
			<li><?php echo bk_icon_svg( 'lucide:credit-card', 28 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Paga con Addi', 'buckler-core' ); ?></strong><?php esc_html_e( '3 cuotas con 0 % de interés', 'buckler-core' ); ?></span></li>
			<li><?php echo bk_icon_svg( 'lucide:shield-check', 28 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Pago seguro', 'buckler-core' ); ?></strong><?php esc_html_e( 'Wompi, Bold y Rapyd', 'buckler-core' ); ?></span></li>
			<li><?php echo bk_icon_svg( 'lucide:map-pin', 28 ); // phpcs:ignore ?><span><strong><?php esc_html_e( 'Hecho en Medellín', 'buckler-core' ); ?></strong><?php esc_html_e( 'desde 1986', 'buckler-core' ); ?></span></li>
		</ul>

		<div class="bk-footer__main">
			<div class="bk-footer__brand">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bk-footer__logo" aria-label="<?php echo esc_attr( bk_config( 'brand' ) . ' – ' . __( 'Inicio', 'buckler-core' ) ); ?>">
					<?php echo bk_upload_img( (string) bk_config( 'logo_light' ), '', array( 'width' => 1920, 'height' => 355, 'sizes' => '180px' ) ); // phpcs:ignore ?>
				</a>
				<p><?php esc_html_e( 'Jeans y prendas en denim para mujer, en tallas 6 a 24. Somos tienda online y enviamos a toda Colombia.', 'buckler-core' ); ?></p>
				<ul class="bk-social">
					<li><a href="<?php echo esc_url( 'https://www.instagram.com/' . $ig . '/' ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( sprintf( 'Instagram @%s (abre en una pestaña nueva)', $ig ) ); ?>"><?php echo bk_icon_svg( 'simple-icons:instagram', 20 ); // phpcs:ignore ?></a></li>
					<li><a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'WhatsApp (abre en una pestaña nueva)', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'simple-icons:whatsapp', 20 ); // phpcs:ignore ?></a></li>
				</ul>
			</div>

			<?php foreach ( (array) bk_config( 'lines' ) as $slug => $line ) : ?>
				<nav class="bk-footer__col" aria-labelledby="bk-ft-<?php echo esc_attr( $slug ); ?>">
					<h2 class="bk-footer__title" id="bk-ft-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $line['label'] ); ?></h2>
					<ul>
						<?php foreach ( bk_child_cats( $slug ) as $c ) : ?>
							<li><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></li>
						<?php endforeach; ?>
						<?php /* translators: %s: línea */ ?>
						<li><a href="<?php echo esc_url( bk_url( $line['page'] ) ); ?>"><strong><?php echo esc_html( sprintf( __( 'Ver todo %s', 'buckler-core' ), $line['label'] ) ); ?></strong></a></li>
					</ul>
				</nav>
			<?php endforeach; ?>

			<nav class="bk-footer__col" aria-labelledby="bk-ft-help">
				<h2 class="bk-footer__title" id="bk-ft-help"><?php esc_html_e( 'Ayuda', 'buckler-core' ); ?></h2>
				<ul>
					<li><a href="<?php echo esc_url( bk_url( (string) bk_config( 'faq_page' ) ) ); ?>"><?php esc_html_e( 'Preguntas frecuentes', 'buckler-core' ); ?></a></li>
					<li><a href="<?php echo esc_url( $acct ); ?>"><?php esc_html_e( 'Mi cuenta y pedidos', 'buckler-core' ); ?></a></li>
					<li><a class="is-sale" href="<?php echo esc_url( bk_url( (string) bk_config( 'sale_page' ) ) ); ?>"><?php esc_html_e( '¡Sale!', 'buckler-core' ); ?></a></li>
					<li><?php echo bk_ext_link( (string) bk_config( 'addi_url' ), __( 'Solicitar crédito Addi', 'buckler-core' ) ); // phpcs:ignore ?></li>
					<li><?php echo bk_ext_link( (string) bk_config( 'review_url' ), __( 'Califícanos en Google', 'buckler-core' ) ); // phpcs:ignore ?></li>
				</ul>
			</nav>

			<div class="bk-footer__col">
				<h2 class="bk-footer__title"><?php esc_html_e( 'Contacto', 'buckler-core' ); ?></h2>
				<ul class="bk-contact">
					<li><?php echo bk_ext_link( bk_whatsapp_url(), bk_whatsapp_display(), 'simple-icons:whatsapp' ); // phpcs:ignore ?></li>
					<li><?php echo bk_ext_link( 'https://www.instagram.com/' . $ig . '/', '@' . $ig, 'simple-icons:instagram' ); // phpcs:ignore ?></li>
					<li><?php echo bk_ext_link( (string) bk_config( 'maps_url' ), (string) bk_config( 'city' ), 'lucide:map-pin' ); // phpcs:ignore ?></li>
				</ul>
				<p class="bk-footer__subtitle"><?php echo bk_icon_svg( 'lucide:clock', 18 ); // phpcs:ignore ?><?php esc_html_e( 'Horario de atención', 'buckler-core' ); ?></p>
				<dl class="bk-hours">
					<?php foreach ( (array) bk_config( 'hours' ) as $h ) : ?>
						<div><dt><?php echo esc_html( $h[0] ); ?></dt><dd><?php echo esc_html( $h[1] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			</div>
		</div>

		<div class="bk-footer__pay">
			<p class="bk-footer__subtitle"><?php esc_html_e( 'Medios de pago', 'buckler-core' ); ?></p>
			<ul class="bk-pay">
				<?php foreach ( (array) bk_config( 'payments' ) as $p ) : ?>
					<li><?php echo bk_upload_img( $p[0], $p[1], array( 'width' => 800, 'height' => 500, 'sizes' => '72px' ) ); // phpcs:ignore ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="bk-footer__bottom">
			<nav aria-label="<?php esc_attr_e( 'Legal', 'buckler-core' ); ?>">
				<ul class="bk-legal">
					<?php foreach ( bk_policies() as $p ) : ?>
						<li><a href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['title'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<p class="bk-copy">
				© <?php echo esc_html( bk_config( 'since' ) . '–' . wp_date( 'Y' ) ); ?> <?php echo esc_html( (string) bk_config( 'company' ) ); ?> · <?php esc_html_e( 'Todos los derechos reservados.', 'buckler-core' ); ?>
				<span class="bk-credit"><?php echo bk_ext_link( (string) bk_config( 'credit_url' ), 'UfoWeb.co' ); // phpcs:ignore ?></span>
			</p>
		</div>
	</div>
	<?php
	if ( $atts['whatsapp'] ) {
		echo bk_whatsapp_button(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return trim( ob_get_clean() );
}
