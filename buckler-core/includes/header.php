<?php
/**
 * Header propio: [bk_header]
 *
 * Barra de anuncios + logo + menú (con menú desplegable de la tienda) + buscador + cuenta + carrito.
 * En móvil: menú lateral accesible (diálogo con foco controlado).
 *
 * Uso: en Divi 5 → Theme Builder → Header global: un único módulo Texto con [bk_header]
 * dentro de una sección sin padding. Divi ya envuelve el layout en <header>, por eso aquí se usa <div>.
 *
 * Caché: el HTML es igual para todas las visitas. El número del carrito se pide por JS a la
 * Store API de WooCommerce solo si la cookie del carrito existe (compatible con caché de página).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'bk_header', 'bk_shortcode_header' );
add_action( 'wp_body_open', 'bk_skip_link', 1 );

/**
 * Enlace "Saltar al contenido" (accesibilidad de teclado).
 */
function bk_skip_link() {
	echo '<a class="bk-skip" href="#main-content">' . esc_html__( 'Saltar al contenido', 'buckler-core' ) . '</a>';
}

/**
 * Estructura del menú principal. Filtrable con `bk_header_nav`.
 *
 * @return array<int,array{label:string,url?:string,mega?:bool,class?:string}>
 */
function bk_header_nav() {
	$new = get_term_by( 'slug', (string) bk_config( 'new_cat' ), 'product_cat' );
	$nav = array(
		array(
			'label' => __( 'Tienda', 'buckler-core' ),
			'mega'  => true,
		),
		array(
			'label' => __( 'Lo nuevo', 'buckler-core' ),
			'url'   => $new ? (string) get_term_link( $new ) : bk_url( '/tienda/' ),
		),
	);
	foreach ( (array) bk_config( 'lines' ) as $line ) {
		$nav[] = array(
			'label' => $line['label'],
			'url'   => bk_url( $line['page'] ),
		);
	}
	$nav[] = array(
		'label' => __( '¡Sale!', 'buckler-core' ),
		'url'   => bk_url( (string) bk_config( 'sale_page' ) ),
		'class' => 'is-sale',
	);
	return apply_filters( 'bk_header_nav', $nav );
}

/**
 * Columnas del menú desplegable de la tienda (líneas con sus prendas).
 *
 * @return string
 */
function bk_mega_columns() {
	$html = '';
	foreach ( (array) bk_config( 'lines' ) as $slug => $line ) {
		$html .= '<div class="bk-mega__col"><p class="bk-mega__title"><a href="' . esc_url( bk_url( $line['page'] ) ) . '">' . esc_html( $line['label'] ) . '</a> <span>' . esc_html( $line['sizes'] ) . '</span></p><ul>';
		foreach ( bk_child_cats( $slug ) as $c ) {
			$html .= '<li><a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['name'] ) . '</a></li>';
		}
		/* translators: %s: nombre de la línea */
		$html .= '<li><a class="bk-mega__all" href="' . esc_url( bk_url( $line['page'] ) ) . '">' . esc_html( sprintf( __( 'Ver todo %s', 'buckler-core' ), $line['label'] ) ) . '</a></li></ul></div>';
	}
	return $html;
}

/**
 * Formulario de búsqueda de productos (búsqueda nativa de WooCommerce).
 *
 * @param string $id Prefijo de ID único.
 * @return string
 */
function bk_search_form( $id ) {
	return sprintf(
		'<form role="search" method="get" class="bk-search" action="%1$s">
			<label class="bk-sr-only" for="%2$s">%3$s</label>
			<input id="%2$s" type="search" name="s" placeholder="%4$s" autocomplete="off" enterkeyhint="search" value="%5$s">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" aria-label="%6$s">%7$s</button>
		</form>',
		esc_url( home_url( '/' ) ),
		esc_attr( $id ),
		esc_html__( 'Buscar productos', 'buckler-core' ),
		esc_attr__( 'Buscar jeans, faldas, sets…', 'buckler-core' ),
		esc_attr( get_search_query() ),
		esc_attr__( 'Buscar', 'buckler-core' ),
		bk_icon_svg( 'lucide:search', 20 )
	);
}

/**
 * Shortcode [bk_header announcements="1" sticky="1"].
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shortcode_header( $atts ) {
	$atts  = shortcode_atts(
		array(
			'announcements' => 1,
			'sticky'        => 1,
		),
		$atts,
		'bk_header'
	);
	$uid   = wp_unique_id( 'bk-h' );
	$nav   = bk_header_nav();
	$cart  = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : bk_url( '/carrito/' );
	$acct  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : bk_url( '/mi-cuenta/' );
	$msgs  = (array) bk_config( 'announcements' );
	$logo  = bk_upload_img(
		(string) bk_config( 'logo_dark' ),
		(string) bk_config( 'brand' ),
		array(
			'class'         => 'bk-logo__img',
			'loading'       => 'eager',
			'fetchpriority' => 'high',
			'width'         => 1920,
			'height'        => 355,
			'sizes'         => '180px',
		)
	);
	$is_home = is_front_page();

	ob_start();
	?>
	<div class="bk-header<?php echo $atts['sticky'] ? ' is-sticky' : ''; ?>" data-bk-header>
		<?php if ( $atts['announcements'] && $msgs ) : ?>
			<div class="bk-announce" aria-label="<?php esc_attr_e( 'Anuncios', 'buckler-core' ); ?>" role="region">
				<ul class="bk-announce__list">
					<?php foreach ( $msgs as $i => $m ) : ?>
						<li class="bk-announce__item<?php echo 0 === $i ? ' is-active' : ''; ?>"><?php echo esc_html( $m ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $msgs ) > 1 ) : ?>
					<button type="button" class="bk-announce__toggle" aria-label="<?php esc_attr_e( 'Pausar anuncios', 'buckler-core' ); ?>" data-label-play="<?php esc_attr_e( 'Reanudar anuncios', 'buckler-core' ); ?>" data-label-pause="<?php esc_attr_e( 'Pausar anuncios', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:pause', 14 ) . bk_icon_svg( 'lucide:play', 14 ); // phpcs:ignore ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="bk-bar">
			<div class="bk-bar__inner">
				<button type="button" class="bk-icon-btn bk-burger" aria-controls="<?php echo esc_attr( $uid ); ?>-drawer" aria-expanded="false" aria-label="<?php esc_attr_e( 'Abrir menú', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:menu', 24 ); // phpcs:ignore ?></button>

				<?php echo $is_home ? '<p class="bk-logo">' : '<div class="bk-logo">'; ?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( bk_config( 'brand' ) . ' – ' . __( 'Inicio', 'buckler-core' ) ); ?>"><?php echo $logo; // phpcs:ignore ?></a>
				<?php echo $is_home ? '</p>' : '</div>'; ?>

				<nav class="bk-nav" aria-label="<?php esc_attr_e( 'Principal', 'buckler-core' ); ?>">
					<ul class="bk-nav__list">
						<?php foreach ( $nav as $item ) : ?>
							<?php if ( ! empty( $item['mega'] ) ) : ?>
								<li class="bk-nav__item has-mega">
									<button type="button" class="bk-nav__link" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-mega"><?php echo esc_html( $item['label'] ); ?><?php echo bk_icon_svg( 'lucide:chevron-down', 16 ); // phpcs:ignore ?></button>
									<div class="bk-mega" id="<?php echo esc_attr( $uid ); ?>-mega" hidden>
										<div class="bk-mega__inner">
											<?php echo bk_mega_columns(); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado dentro. ?>
											<div class="bk-mega__col bk-mega__col--feat">
												<a class="bk-mega__card" href="<?php echo esc_url( bk_url( '/tienda/' ) ); ?>"><strong><?php esc_html_e( 'Toda la tienda', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'Jeans y prendas en denim, tallas 6 a 24', 'buckler-core' ); ?></span></a>
												<a class="bk-mega__card is-sale" href="<?php echo esc_url( bk_url( (string) bk_config( 'sale_page' ) ) ); ?>"><strong><?php esc_html_e( '¡Sale!', 'buckler-core' ); ?></strong><span><?php esc_html_e( 'Prendas con descuento', 'buckler-core' ); ?></span></a>
											</div>
										</div>
									</div>
								</li>
							<?php else : ?>
								<li class="bk-nav__item"><a class="bk-nav__link <?php echo esc_attr( $item['class'] ?? '' ); ?>" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				</nav>

				<div class="bk-bar__search"><?php echo bk_search_form( $uid . '-s' ); // phpcs:ignore ?></div>

				<div class="bk-actions">
					<button type="button" class="bk-icon-btn bk-search-toggle" aria-controls="<?php echo esc_attr( $uid ); ?>-msearch" aria-expanded="false" aria-label="<?php esc_attr_e( 'Buscar', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:search', 22 ); // phpcs:ignore ?></button>
					<a class="bk-icon-btn" href="<?php echo esc_url( $acct ); ?>" aria-label="<?php esc_attr_e( 'Mi cuenta', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:user', 22 ); // phpcs:ignore ?></a>
					<a class="bk-icon-btn bk-cart-link" href="<?php echo esc_url( $cart ); ?>" data-bk-cart>
						<?php echo bk_icon_svg( 'lucide:shopping-bag', 22 ); // phpcs:ignore ?>
						<span class="bk-sr-only"><?php esc_html_e( 'Carrito', 'buckler-core' ); ?></span>
						<span class="bk-cart-count" data-bk-cart-count hidden>0</span>
					</a>
				</div>
			</div>
			<div class="bk-msearch" id="<?php echo esc_attr( $uid ); ?>-msearch" hidden><?php echo bk_search_form( $uid . '-ms' ); // phpcs:ignore ?></div>
		</div>

		<div class="bk-drawer" id="<?php echo esc_attr( $uid ); ?>-drawer" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Menú', 'buckler-core' ); ?>" hidden>
			<div class="bk-drawer__panel">
				<div class="bk-drawer__head">
					<span class="bk-drawer__title"><?php esc_html_e( 'Menú', 'buckler-core' ); ?></span>
					<button type="button" class="bk-icon-btn bk-drawer__close" aria-label="<?php esc_attr_e( 'Cerrar menú', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:x', 24 ); // phpcs:ignore ?></button>
				</div>
				<nav aria-label="<?php esc_attr_e( 'Menú móvil', 'buckler-core' ); ?>">
					<ul class="bk-drawer__list">
						<?php foreach ( (array) bk_config( 'lines' ) as $slug => $line ) : ?>
							<li>
								<details class="bk-drawer__group">
									<summary><span><?php echo esc_html( $line['label'] ); ?> <small><?php echo esc_html( $line['sizes'] ); ?></small></span><?php echo bk_icon_svg( 'lucide:chevron-down', 18 ); // phpcs:ignore ?></summary>
									<ul>
										<?php foreach ( bk_child_cats( $slug ) as $c ) : ?>
											<li><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a></li>
										<?php endforeach; ?>
										<?php /* translators: %s: línea */ ?>
										<li><a class="bk-mega__all" href="<?php echo esc_url( bk_url( $line['page'] ) ); ?>"><?php echo esc_html( sprintf( __( 'Ver todo %s', 'buckler-core' ), $line['label'] ) ); ?></a></li>
									</ul>
								</details>
							</li>
						<?php endforeach; ?>
						<?php foreach ( $nav as $item ) : ?>
							<?php
							if ( ! empty( $item['mega'] ) || in_array( $item['url'] ?? '', array_map( 'bk_url', wp_list_pluck( (array) bk_config( 'lines' ), 'page' ) ), true ) ) {
								continue;
							}
							?>
							<li><a class="bk-drawer__link <?php echo esc_attr( $item['class'] ?? '' ); ?>" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
						<?php endforeach; ?>
						<li><a class="bk-drawer__link" href="<?php echo esc_url( bk_url( '/tienda/' ) ); ?>"><?php esc_html_e( 'Toda la tienda', 'buckler-core' ); ?></a></li>
					</ul>
				</nav>
				<ul class="bk-drawer__foot">
					<li><a href="<?php echo esc_url( $acct ); ?>"><?php echo bk_icon_svg( 'lucide:user', 20 ); // phpcs:ignore ?><?php esc_html_e( 'Mi cuenta', 'buckler-core' ); ?></a></li>
					<li><a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" rel="noopener"><?php echo bk_icon_svg( 'simple-icons:whatsapp', 20 ); // phpcs:ignore ?><?php esc_html_e( 'Escríbenos por WhatsApp', 'buckler-core' ); ?><span class="bk-sr-only"> <?php esc_html_e( '(abre en una pestaña nueva)', 'buckler-core' ); ?></span></a></li>
				</ul>
			</div>
		</div>
	</div>
	<?php
	return trim( ob_get_clean() );
}
