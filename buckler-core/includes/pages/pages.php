<?php
/**
 * Módulo "Páginas limpias": buckler-core pinta las páginas completas con su propio documento HTML
 * (wp_head + [bk_header] + contenido + [bk_footer] + wp_footer), sin plantillas ni CSS/JS de Divi.
 *
 * - Rutas: inicio, tienda/categorías/líneas/¡Sale!/búsqueda, ficha de producto, carrito, checkout (y gracias),
 *   mi cuenta, preguntas frecuentes, políticas (CPT politica-empresa) y 404.
 * - El tema (Divi + tema hijo) sigue activo: sus snippets de functions.php siguen funcionando y el resto de
 *   páginas (si las hubiera) usan Divi como hoy. Apagar el módulo devuelve todo a Divi.
 * - Carrito y checkout usan los shortcodes clásicos: así funcionan hoy las pasarelas (Bold, Wompi, Rapyd, Addi),
 *   el campo de cédula de los snippets y la captura de carritos abandonados.
 * - Ficha: plantilla nativa de WooCommerce reordenada con hooks (los plugins que usan esos hooks siguen
 *   funcionando: precios dinámicos, píxel, etc.), con galería propia (fotos + video) y tallas accesibles.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/speed.php';
require_once __DIR__ . '/seo.php';

add_filter( 'template_include', 'bk_pages_template', 100000 );
add_action( 'wp_enqueue_scripts', 'bk_pages_assets', 30 );
add_action( 'wp_enqueue_scripts', 'bk_pages_diet', 9999 );
add_action( 'wp_print_footer_scripts', 'bk_pages_diet', 1 );
add_action( 'wp_enqueue_scripts', 'bk_pages_diet_route', 10000 );
add_action( 'wp_print_footer_scripts', 'bk_pages_diet_route', 2 );
add_action( 'wp_head', 'bk_pages_preload', 1 );
add_filter( 'wp_redirect', 'bk_pages_no_soft404', 999 );
// Prioridad 10 en template_redirect: después de las redirecciones de WordPress (slug antiguo, URL canónica) y
// antes del código del sitio que manda a la portada con header() (va después de la cabecera Link de la REST, 11).
add_action( 'template_redirect', 'bk_pages_render_404', 10 );
add_action( 'template_redirect', 'bk_pages_llms_txt', 0 );

/**
 * 404 real: el sitio redirigía toda dirección inexistente a la portada (con 200). Eso confunde a Google y a los
 * agentes de IA (p. ej. /.well-known/ai-catalog.json devolvía la portada como "JSON inválido"). Si una dirección
 * no existe y algo intenta mandarla al inicio, se responde 404 con la página "No encontramos esta página".
 * Las redirecciones a otras direcciones (p. ej. la adivinanza de WordPress o las de categorías) no se tocan.
 *
 * @param string $location Destino.
 * @return string
 */
function bk_pages_no_soft404( $location ) {
	if ( is_admin() || ! is_404() || '404' !== bk_pages_route() || ! bk_pages_404_is_file() ) {
		return $location; // Páginas: la redirección a la portada es lo que pidió el cliente (2026-10-15).
	}
	$to = untrailingslashit( (string) strtok( (string) $location, '?#' ) );
	if ( untrailingslashit( home_url() ) !== $to && untrailingslashit( home_url( '/' ) ) !== $to ) {
		return $location;
	}
	status_header( 404 );
	nocache_headers();
	include BK_DIR . 'templates/pages/document.php';
	exit;
}

/**
 * ¿La dirección pedida es un archivo o una ruta técnica? (404 real en vez de ir a la portada).
 *
 * @return bool
 */
function bk_pages_404_is_file() {
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return (bool) preg_match( '#^/(\.well-known|wp-content|wp-includes|wp-json|xmlrpc)|\.[a-z0-9]{2,5}$#i', $path );
}

/**
 * Pinta la página 404 propia (con estado 404) y termina, antes de que otro código redirija a la portada.
 */
function bk_pages_render_404() {
	if ( ! is_404() || '404' !== bk_pages_route() ) {
		return;
	}
	// Decisión del cliente (2026-10-15): una dirección que no existe lleva a la portada (301).
	// Excepción: archivos y rutas técnicas (/.well-known/, .json, .xml, imágenes…) responden 404 real, para que
	// agentes de IA, Google Merchant y validadores no reciban la portada como si fuera el archivo pedido.
	if ( 'home' === bk_config( 'redirect_404' ) && ! bk_pages_404_is_file() ) {
		wp_safe_redirect( home_url( '/' ), 301, 'buckler-core' );
		exit;
	}
	status_header( 404 );
	nocache_headers();
	include BK_DIR . 'templates/pages/document.php';
	exit;
}

/**
 * /llms.txt: resumen de la tienda en Markdown para asistentes y agentes de IA (estándar llms.txt).
 * Solo si no existe un archivo llms.txt real en la raíz (ese lo sirve el servidor directamente).
 */
function bk_pages_llms_txt() {
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '/llms.txt' !== $path ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo bk_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- texto plano.
	exit;
}

/**
 * Contenido de /llms.txt (datos de bk_config(), páginas y categorías reales).
 *
 * @return string
 */
function bk_llms_txt() {
	$lines = (array) bk_config( 'lines' );
	$out   = '# ' . bk_config( 'brand' ) . "\n\n";
	$out  .= '> Tienda online de jeans y prendas en denim para mujer, hechas en ' . bk_config( 'city' ) . '. Dos líneas: ';
	$parts = array();
	foreach ( $lines as $l ) {
		$parts[] = $l['label'] . ' (' . ( $l['sizes'] ?? '' ) . ')';
	}
	$out .= implode( ' y ', $parts ) . ". Envíos a toda Colombia.\n\n";
	$out .= "## Tienda\n\n";
	$out .= '- [Toda la tienda](' . wc_get_page_permalink( 'shop' ) . "): catálogo completo con filtros por línea, prenda, talla y precio\n";
	$out .= '- [Lo nuevo](' . bk_new_url() . "): últimos lanzamientos\n";
	foreach ( $lines as $slug => $l ) {
		if ( empty( $l['page'] ) ) {
			continue;
		}
		$out .= '- [' . $l['label'] . '](' . bk_url( $l['page'] ) . '): ' . ( $l['sizes'] ?? '' ) . "\n";
		foreach ( bk_child_cats( $slug ) as $c ) {
			$out .= '  - [' . $c['name'] . ' · ' . $l['label'] . '](' . $c['url'] . ")\n";
		}
	}
	if ( bk_config( 'sale_page' ) ) {
		$out .= '- [¡Sale!](' . bk_url( (string) bk_config( 'sale_page' ) ) . "): prendas con descuento\n";
	}
	$out .= "\n## Ayuda\n\n";
	if ( bk_config( 'faq_page' ) ) {
		$out .= '- [Preguntas frecuentes](' . bk_url( (string) bk_config( 'faq_page' ) ) . "): envíos, pagos, tallas y cambios\n";
	}
	foreach ( function_exists( 'bk_policies' ) ? bk_policies() : array() as $p ) {
		$out .= '- [' . $p['title'] . '](' . $p['url'] . ")\n";
	}
	$out .= '- [Mi cuenta](' . wc_get_page_permalink( 'myaccount' ) . "): pedidos y guía de envío\n";
	$out .= "\n## Contacto\n\n";
	$out .= '- WhatsApp: ' . bk_whatsapp_display() . ' (' . bk_whatsapp_url() . ")\n";
	foreach ( (array) bk_config( 'hours' ) as $h ) {
		$out .= '- Horario: ' . $h[0] . ', ' . $h[1] . "\n";
	}
	$out .= '- Medios de pago: ' . implode( ', ', wp_list_pluck( (array) bk_config( 'payments' ), 1 ) ) . "\n";
	return (string) apply_filters( 'bk_llms_txt', $out );
}

/**
 * Precarga de la fuente propia (evita el salto de texto al cargar).
 */
function bk_pages_preload() {
	if ( bk_pages_route() ) {
		echo '<link rel="preload" href="' . esc_url( BK_URL . 'assets/fonts/manrope-latin-wght-normal.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	}
}

/**
 * Ruta de la petición actual ('' = no la pinta buckler-core).
 *
 * @return string
 */
function bk_pages_route() {
	static $route = null;
	if ( null !== $route ) {
		return $route;
	}
	$route = '';
	if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || isset( $_GET['et_fb'] ) || ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $route; // Nunca dentro del editor visual de Divi.
	}
	if ( ! bk_on( 'layout' ) ) {
		return $route; // El documento usa [bk_header] y [bk_footer].
	}
	$faq = trailingslashit( (string) bk_config( 'faq_page' ) );
	$uri = trailingslashit( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( is_front_page() ) {
		$route = 'home';
	} elseif ( function_exists( 'is_product' ) && is_product() ) {
		$route = 'product';
	} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
		$route = 'cart';
	} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$route = 'checkout';
	} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$route = 'account';
	} elseif ( is_singular( 'politica-empresa' ) ) {
		$route = 'policy';
	} elseif ( is_page() && '/' !== $faq && $faq === $uri ) {
		$route = 'faq';
	} elseif ( function_exists( 'bk_shop_takeover_shortcode' ) && bk_shop_takeover_shortcode() ) {
		$route = 'shop';
	} elseif ( is_404() ) {
		$route = '404';
	}
	$allowed = (array) apply_filters( 'bk_pages_routes', array( 'home', 'shop', 'product', 'cart', 'checkout', 'account', 'policy', 'faq', '404' ) );
	$route   = in_array( $route, $allowed, true ) ? $route : '';
	return $route;
}

/**
 * Plantilla: documento propio para las rutas de buckler-core.
 *
 * @param string $template Plantilla del tema.
 * @return string
 */
function bk_pages_template( $template ) {
	$route = bk_pages_route();
	if ( ! $route ) {
		return $template;
	}
	// Emojis de WordPress: script y estilos que estas páginas no usan.
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	// Visor PhotoSwipe de WooCommerce: su HTML quedaba al final de la página sin estilos (franja bajo el footer).
	remove_action( 'wp_footer', 'woocommerce_photoswipe' );
	if ( 'product' === $route ) {
		bk_pdp_hooks();
	}
	if ( 'cart' === $route ) {
		bk_cart_hooks();
	}
	if ( 'account' === $route ) {
		add_filter( 'wc_get_template', 'bk_account_templates', 10, 2 );
		add_filter( 'woocommerce_account_menu_items', 'bk_account_menu_items', 20 );
	}
	return BK_DIR . 'templates/pages/document.php';
}

/**
 * CSS/JS propios de las páginas.
 */
function bk_pages_assets() {
	$route = bk_pages_route();
	if ( ! $route ) {
		return;
	}
	wp_enqueue_style( 'bk-pages', BK_URL . 'assets/css/pages.css', array( 'bk-core' ), bk_asset_ver( 'assets/css/pages.css' ) );
	wp_enqueue_script( 'bk-pages', BK_URL . 'assets/js/pages.js', array(), bk_asset_ver( 'assets/js/pages.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'bk-pages',
		'bkPages',
		array(
			'i18n' => array(
				'size'      => __( 'Talla', 'buckler-core' ),
				'soldOut'   => __( 'agotada', 'buckler-core' ),
				'chooseSize' => __( 'Elige tu talla', 'buckler-core' ),
				'prev'      => __( 'Foto anterior', 'buckler-core' ),
				'next'      => __( 'Foto siguiente', 'buckler-core' ),
				'less'      => __( 'Quitar una unidad', 'buckler-core' ),
				'more'      => __( 'Añadir una unidad', 'buckler-core' ),
			),
		)
	);
	if ( 'shop' === $route && function_exists( 'bk_shop_assets' ) ) {
		bk_shop_assets();
	}
}

/**
 * Dieta: en las páginas de buckler-core se quitan CSS/JS de Divi y de sus plugins (BodyCommerce, Divi Engine,
 * Divi Essential…), del buscador FiboSearch (el header trae su propio buscador) y de los bloques de Gutenberg
 * (estas páginas no los usan). Se conservan WooCommerce, pasarelas, analítica y lo propio.
 */
function bk_pages_diet() {
	if ( ! bk_pages_route() ) {
		return;
	}
	$re = (string) apply_filters( 'bk_pages_diet_regex', '/^(divi|et[-_]|et_|dnxte|bc-|daf-|divi-bodyshop|bodycommerce|pac[-_]?da|spotlight|effect_card|wpf|themify|dgwt-wcas|jquery-dgwt|bold-elementor|de[-_]|dmach|divi-machine|divi-ajax|da[-_]|ddch|dipl|wp-block-library|global-styles|classic-theme-styles|wc-blocks|xoo-)/i' );
	foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
		foreach ( (array) $deps->queue as $handle ) {
			if ( preg_match( $re, $handle ) && ! in_array( $handle, array( 'dashicons', 'admin-bar' ), true ) ) {
				$deps->dequeue( $handle );
			}
		}
	}
}

/**
 * Dieta por ruta: cada página carga solo lo que usa. Reglas [patrón de handle o URL, rutas donde SÍ se carga].
 * Pasarelas solo en el pago; formularios de país/dirección (selectWoo) en carrito, pago y cuenta; CSS general
 * de WooCommerce solo donde hay formularios de Woo; galería de Woo (photoswipe/flexslider/zoom), fragmentos del
 * carrito y fuentes de Google en ninguna (hay galería, carrito y fuente propios). Filtro: bk_pages_diet_rules.
 */
function bk_pages_diet_route() {
	$route = bk_pages_route();
	if ( ! $route ) {
		return;
	}
	$forms = array( 'cart', 'checkout', 'account' );
	$rules = (array) apply_filters(
		'bk_pages_diet_rules',
		array(
			// Pasarelas de pago y Addi (la línea de cuotas de las tarjetas y la ficha es propia).
			array( '#/plugins/(bold-pagos-en-linea|rapyd-payments-plugin-for-woocommerce|buy-now-pay-later-addi|wompi[^/]*)/#', array( 'checkout' ) ),
			// Precios dinámicos: solo donde se compra.
			array( '#/plugins/yaypricing-pro/#', array( 'product', 'cart', 'checkout' ) ),
			// Formularios de dirección.
			array( '#^(selectWoo|select2|wc-country-select|wc-address-i18n|wc-checkout|wc-custom-place-order-button)$#', $forms ),
			array( '#^(woocommerce-general|woocommerce-layout|woocommerce-smallscreen)$#', $forms ),
			array( '#^(jquery-blockui|wc-add-to-cart|woocommerce|wc-js-cookie|js-cookie)$#', array( 'product', 'cart', 'checkout', 'account' ) ),
			// Galería, pestañas y comentarios de Woo: la ficha tiene los suyos.
			array( '#^(photoswipe|photoswipe-ui-default|photoswipe-default-skin|flexslider|zoom|wc-zoom|wc-flexslider|wc-photoswipe|wc-photoswipe-ui-default|wc-single-product|comment-reply)$#', array() ),
			// Fragmentos del carrito (el contador usa la Store API) y medidor de contraseñas (zxcvbn, 800 KB).
			array( '#^(wc-cart-fragments|wc-password-strength-meter|password-strength-meter|zxcvbn-async)$#', array() ),
			// Fuentes de Google (la tienda usa Manrope local), Divi y el tema hijo (sus snippets PHP siguen activos).
			array( '#fonts\.googleapis\.com|/themes/Divi/|/themes/buckler/#', array() ),
		)
	);
	foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
		foreach ( (array) $deps->queue as $handle ) {
			$src = isset( $deps->registered[ $handle ] ) ? (string) $deps->registered[ $handle ]->src : '';
			foreach ( $rules as $r ) {
				if ( ( preg_match( $r[0], $handle ) || ( '' !== $src && preg_match( $r[0], $src ) ) ) && ! in_array( $route, (array) $r[1], true ) ) {
					$deps->dequeue( $handle );
					break;
				}
			}
		}
	}
	// WooCommerce engancha el HTML del visor PhotoSwipe al cargar sus scripts (después de template_include); sin su
	// CSS quedaba como una franja al final de la página.
	remove_action( 'wp_footer', 'woocommerce_photoswipe' );
	// Dashicons solo con la barra de administración.
	if ( ! is_admin_bar_showing() ) {
		wp_dequeue_style( 'dashicons' );
	}
}

/**
 * Quita del <head> los estilos en línea de Divi que no pasan por wp_enqueue (CSS crítico, personalizador).
 *
 * @param string $html Salida de wp_head().
 * @return string
 */
function bk_pages_clean_head( $html ) {
	$ids  = '(et-|et_|divi|dnxte|bc-|daf-|dmach)';
	$html = preg_replace( '#<style[^>]+id=["\']' . $ids . '[^"\']*["\'][^>]*>.*?</style>#is', '', $html );
	$html = preg_replace( '#<link[^>]+id=["\']' . $ids . '[^"\']*["\'][^>]*>#i', '', $html );
	return (string) $html;
}

/* ---------------------------------------------------------------------------
 * Ficha de producto
 * ------------------------------------------------------------------------- */

/**
 * Reordena la ficha nativa de WooCommerce.
 */
function bk_pdp_hooks() {
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
	add_action( 'woocommerce_before_single_product_summary', 'bk_pdp_gallery', 20 );

	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_addi', 11 );
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_size_tools', 25 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 ); // "Share on" de plugins.
	add_action( 'woocommerce_single_product_summary', 'bk_pdp_benefits', 40 );

	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
	add_action( 'woocommerce_after_single_product_summary', 'bk_pdp_related', 20 );

	// Sin pestañas: solo la descripción (sin comentarios ni pestañas de otros plugins, como la guía de tallas,
	// que ya está en el botón junto a las tallas) y, debajo, reseñas de Google.
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
	add_action( 'woocommerce_after_single_product_summary', 'bk_pdp_description', 10 );
	add_action( 'woocommerce_after_single_product_summary', 'bk_pdp_reviews', 15 );
	add_filter( 'comments_open', 'bk_pdp_no_comments', 20, 2 );
}

/**
 * Comentarios cerrados en la ficha (no se muestran ni se aceptan desde la página).
 *
 * @param bool $open    Abierto.
 * @param int  $post_id Post.
 * @return bool
 */
function bk_pdp_no_comments( $open, $post_id ) {
	return 'product' === get_post_type( $post_id ) ? false : $open;
}

/**
 * Descripción del producto (sin pestañas).
 */
function bk_pdp_description() {
	$content = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	if ( '' === trim( wp_strip_all_tags( (string) $content ) ) ) {
		return;
	}
	echo '<section class="bk-pdp__desc" aria-labelledby="bk-desc-title"><h2 id="bk-desc-title" class="bk-section-title">' . esc_html__( 'Descripción', 'buckler-core' ) . '</h2><div class="bk-prose">' . $content . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Reseñas de Google y promoción en la ficha.
 */
function bk_pdp_reviews() {
	echo bk_reviews_block( array( 'context' => 'pdp', 'limite' => 10 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Migas de pan con datos estructurados BreadcrumbList.
 *
 * @param array $items Lista de [texto, url]; el último es la página actual (su url puede ir vacía).
 */
function bk_crumbs( $items ) {
	$items = array_values( array_filter( (array) $items, static fn( $i ) => is_array( $i ) && '' !== trim( (string) $i[0] ) ) );
	if ( count( $items ) < 2 ) {
		return;
	}
	$last   = count( $items ) - 1;
	$schema = array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array() );
	echo '<nav class="bk-crumbs" aria-label="' . esc_attr__( 'Ruta de navegación', 'buckler-core' ) . '"><ol>';
	foreach ( $items as $i => $it ) {
		$url = $i === $last ? '' : (string) ( $it[1] ?? '' );
		if ( $i === $last ) {
			echo '<li aria-current="page">' . esc_html( $it[0] ) . '</li>';
		} else {
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $it[0] ) . '</a></li>';
		}
		$el = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags( $it[0] ) );
		if ( $url || ( $i === $last && ! empty( $it[1] ) ) ) {
			$el['item'] = $url ? $url : (string) $it[1];
		}
		$schema['itemListElement'][] = $el;
	}
	echo '</ol></nav>';
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
}

/**
 * Migas de la ruta actual (tienda, carrito, checkout, cuenta, FAQ, políticas, 404).
 */
function bk_route_crumbs() {
	$home  = array( __( 'Inicio', 'buckler-core' ), home_url( '/' ) );
	$shop  = array( __( 'Tienda', 'buckler-core' ), wc_get_page_permalink( 'shop' ) );
	$here  = static fn( $label ) => array( $label, '' );
	$items = array();
	switch ( bk_pages_route() ) {
		case 'shop':
			$items = bk_shop_crumbs( $home, $shop );
			break;
		case 'cart':
			$items = array( $home, $here( __( 'Carrito', 'buckler-core' ) ) );
			break;
		case 'checkout':
			if ( ! is_order_received_page() ) {
				$items = array( $home, array( __( 'Carrito', 'buckler-core' ), wc_get_cart_url() ), $here( __( 'Finalizar compra', 'buckler-core' ) ) );
			}
			break;
		case 'account':
			$items = array( $home, $here( __( 'Mi cuenta', 'buckler-core' ) ) );
			break;
		case 'faq':
			$items = array( $home, $here( __( 'Preguntas frecuentes', 'buckler-core' ) ) );
			break;
		case 'policy':
			$items = array( $home, $here( trim( wp_strip_all_tags( get_the_title( get_queried_object_id() ) ) ) ) );
			break;
		case '404':
			$items = array( $home, $here( __( 'Página no encontrada', 'buckler-core' ) ) );
			break;
	}
	bk_crumbs( (array) apply_filters( 'bk_crumbs', $items, bk_pages_route() ) );
}

/**
 * Migas de la tienda: Tienda, línea (y prenda filtrada), ¡Sale!, Lo nuevo, categorías y búsqueda.
 *
 * @param array $home Miga de inicio.
 * @param array $shop Miga de la tienda.
 * @return array
 */
function bk_shop_crumbs( $home, $shop ) {
	$lines  = (array) bk_config( 'lines' );
	$prenda = isset( $_GET['prenda'] ) ? sanitize_title( wp_unslash( $_GET['prenda'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( is_search() ) {
		/* translators: %s: búsqueda */
		return array( $home, $shop, array( sprintf( __( 'Búsqueda: %s', 'buckler-core' ), get_search_query( false ) ), '' ) );
	}
	if ( is_product_category() ) {
		$t     = get_queried_object();
		$items = array( $home );
		foreach ( array_reverse( get_ancestors( $t->term_id, 'product_cat' ) ) as $aid ) {
			$a       = get_term( $aid, 'product_cat' );
			$items[] = array( isset( $lines[ $a->slug ] ) ? $lines[ $a->slug ]['label'] : bk_nice_label( $a->name ), isset( $lines[ $a->slug ]['page'] ) ? bk_url( $lines[ $a->slug ]['page'] ) : get_term_link( $a ) );
		}
		$items[] = array( isset( $lines[ $t->slug ] ) ? $lines[ $t->slug ]['label'] : bk_nice_label( $t->name ), '' );
		return $items;
	}
	if ( is_page() ) {
		$path = trailingslashit( (string) wp_parse_url( get_permalink(), PHP_URL_PATH ) );
		foreach ( $lines as $slug => $l ) {
			if ( empty( $l['page'] ) || trailingslashit( $l['page'] ) !== $path ) {
				continue;
			}
			$root = get_term_by( 'slug', $slug, 'product_cat' );
			$name = '';
			if ( $prenda && $root ) {
				foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $root->term_id, 'hide_empty' => false ) ) as $c ) {
					if ( bk_prenda_key( $c->name ) === $prenda ) {
						$name = bk_nice_label( $c->name );
						break;
					}
				}
			}
			return $name ? array( $home, array( $l['label'], get_permalink() ), array( $name, '' ) ) : array( $home, array( $l['label'], get_permalink() ) );
		}
		return array( $home, array( trim( wp_strip_all_tags( get_the_title() ) ), get_permalink() ) );
	}
	if ( ! empty( $_GET['nuevo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return array( $home, $shop, array( __( 'Lo nuevo', 'buckler-core' ), '' ) );
	}
	return array( $home, array( __( 'Tienda', 'buckler-core' ), $shop[1] ) );
}

/**
 * Migas de pan de la ficha: Inicio / Línea / Prenda / Producto (la primera categoría de Woo suele ser
 * "Línea nueva" o "¡Sale!", que no ayudan a ubicarse). Enlaces que funcionan con el redirect de categorías.
 */
function bk_pdp_crumbs() {
	$p = wc_get_product( get_the_ID() );
	if ( ! $p ) {
		return;
	}
	$line  = bk_product_line( $p->get_id() );
	$lines = (array) bk_config( 'lines' );
	$items = array( array( __( 'Inicio', 'buckler-core' ), home_url( '/' ) ) );
	if ( $line && ! empty( $lines[ $line['slug'] ]['page'] ) ) {
		$page    = bk_url( $lines[ $line['slug'] ]['page'] );
		$items[] = array( $line['label'], $page );
		$root    = get_term_by( 'slug', $line['slug'], 'product_cat' );
		foreach ( $root ? $p->get_category_ids() : array() as $cid ) {
			$t = get_term( $cid, 'product_cat' );
			if ( $t instanceof WP_Term && (int) $t->parent === (int) $root->term_id ) {
				$items[] = array( bk_nice_label( $t->name ), add_query_arg( 'prenda', bk_prenda_key( $t->name ), $page ) );
				break;
			}
		}
	} else {
		$items[] = array( __( 'Tienda', 'buckler-core' ), wc_get_page_permalink( 'shop' ) );
	}
	$items[] = array( $p->get_name(), $p->get_permalink() );
	bk_crumbs( $items );
}

/* ---------------------------------------------------------------------------
 * Mi cuenta
 * ------------------------------------------------------------------------- */

/**
 * Plantillas propias del menú y del escritorio de Mi cuenta.
 *
 * @param string $located       Ruta.
 * @param string $template_name Plantilla de Woo.
 * @return string
 */
function bk_account_templates( $located, $template_name ) {
	$own = array( 'myaccount/navigation.php', 'myaccount/dashboard.php' );
	return in_array( $template_name, $own, true ) ? BK_DIR . 'templates/woocommerce/' . $template_name : $located;
}

/**
 * Menú de la cuenta: sin "Descargas" (la tienda no vende archivos) y con nombres claros.
 *
 * @param array $items Enlaces.
 * @return array
 */
function bk_account_menu_items( $items ) {
	unset( $items['downloads'] );
	$names = array(
		'dashboard'       => __( 'Resumen', 'buckler-core' ),
		'orders'          => __( 'Mis pedidos', 'buckler-core' ),
		'edit-address'    => __( 'Direcciones', 'buckler-core' ),
		'edit-account'    => __( 'Mis datos', 'buckler-core' ),
		'customer-logout' => __( 'Cerrar sesión', 'buckler-core' ),
	);
	foreach ( $names as $k => $v ) {
		if ( isset( $items[ $k ] ) ) {
			$items[ $k ] = $v;
		}
	}
	return $items;
}

/* ---------------------------------------------------------------------------
 * Carrito
 * ------------------------------------------------------------------------- */

/**
 * Carrito clásico reordenado: sugerencias propias en lugar de las ventas cruzadas de Woo, confianza bajo el
 * total y enlace para seguir comprando. Las pasarelas, cupones y snippets siguen usando los hooks nativos.
 */
function bk_cart_hooks() {
	remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cross_sell_display' );
	add_action( 'woocommerce_after_cart_table', 'bk_cart_continue' );
	add_action( 'woocommerce_after_cart_totals', 'bk_cart_trust' );
	// "Combínalo con" y reseñas van FUERA del bloque de WooCommerce (ver templates/pages/cart.php): dentro, el
	// resumen fijo (sticky) bajaba por encima de ellos.
	add_action( 'woocommerce_cart_is_empty', 'bk_cart_empty_extra', 20 );
}

/**
 * "Seguir comprando" bajo la lista de productos.
 */
function bk_cart_continue() {
	echo '<p class="bk-cart-continue"><a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . bk_icon_svg( 'lucide:arrow-left', 16 ) . esc_html__( 'Seguir comprando', 'buckler-core' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Confianza bajo el botón de pagar: envío, pago seguro, cambios y medios de pago.
 */
function bk_cart_trust() {
	?>
	<ul class="bk-cart-trust">
		<li><?php echo bk_icon_svg( 'lucide:truck', 18 ); // phpcs:ignore ?><span><?php esc_html_e( 'Envío gratis a toda Colombia', 'buckler-core' ); ?></span></li>
		<li><?php echo bk_icon_svg( 'lucide:shield-check', 18 ); // phpcs:ignore ?><span><?php esc_html_e( 'Pago 100 % seguro', 'buckler-core' ); ?></span></li>
		<li><?php echo bk_icon_svg( 'lucide:credit-card', 18 ); // phpcs:ignore ?><span><?php esc_html_e( 'Paga con Addi, Bold, Wompi o tarjeta', 'buckler-core' ); ?></span></li>
	</ul>
	<?php if ( bk_config( 'payments' ) ) : ?>
		<ul class="bk-pay bk-cart-pay" aria-label="<?php esc_attr_e( 'Medios de pago', 'buckler-core' ); ?>">
			<?php foreach ( (array) bk_config( 'payments' ) as $p ) : ?>
				<li><?php echo bk_upload_img( $p[0], $p[1], array( 'width' => 800, 'height' => 500, 'sizes' => '64px' ) ); // phpcs:ignore ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php
}

/**
 * IDs sugeridos: ventas cruzadas de los productos del carrito y, si no hay, lo más nuevo de la misma línea.
 *
 * @param int $limit Cantidad.
 * @return int[]
 */
function bk_cart_suggest_ids( $limit = 4 ) {
	$in_cart = array();
	$cross   = array();
	$line    = '';
	foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
		$pid       = (int) $item['product_id'];
		$in_cart[] = $pid;
		$p         = wc_get_product( $pid );
		if ( $p ) {
			$cross = array_merge( $cross, $p->get_cross_sell_ids() );
		}
		$l    = bk_product_line( $pid );
		$line = $l ? $l['slug'] : $line;
	}
	$ids = array();
	foreach ( array_unique( array_map( 'intval', $cross ) ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p && ! in_array( $id, $in_cart, true ) && $p->is_visible() && $p->is_in_stock() ) {
			$ids[] = $id;
		}
	}
	if ( count( $ids ) < $limit ) {
		$q = array( 'status' => 'publish', 'limit' => $limit + count( $in_cart ) + count( $ids ), 'return' => 'ids', 'stock_status' => 'instock', 'orderby' => 'date', 'order' => 'DESC' );
		if ( $line ) {
			$q['category'] = array( $line );
		}
		$ids = array_merge( $ids, array_diff( array_map( 'intval', wc_get_products( $q ) ), $in_cart, $ids ) );
	}
	return array_slice( array_values( array_unique( $ids ) ), 0, $limit );
}

/**
 * Pinta una rejilla de tarjetas de producto.
 *
 * @param int[]  $ids   Productos.
 * @param string $title Título (h2).
 */
function bk_cart_grid( $ids, $title ) {
	if ( ! $ids || ! function_exists( 'bk_product_card' ) ) {
		return;
	}
	echo '<section class="bk-products bk-cart-suggest"><div class="bk-section-head"><h2 class="bk-section-title">' . esc_html( $title ) . '</h2></div><ul class="bk-grid" style="--bk-cols:4">';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			echo '<li class="bk-grid__item">' . bk_product_card( $p ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '</ul></section>';
}

/**
 * "Combínalo con" bajo el carrito.
 */
function bk_cart_suggestions() {
	bk_cart_grid( bk_cart_suggest_ids( 4 ), __( 'Combínalo con', 'buckler-core' ) );
}

/**
 * Reseñas de Google y promoción bajo el carrito.
 */
function bk_cart_reviews() {
	echo bk_reviews_block( array( 'context' => 'cart', 'limite' => 8 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Carrito vacío: accesos a las líneas y lo nuevo.
 */
function bk_cart_empty_extra() {
	$links = array();
	foreach ( (array) bk_config( 'lines' ) as $l ) {
		if ( ! empty( $l['page'] ) ) {
			$links[] = '<a class="bk-btn is-ghost" href="' . esc_url( bk_url( $l['page'] ) ) . '">' . esc_html( $l['label'] ) . '</a>';
		}
	}
	if ( $links ) {
		echo '<p class="bk-cart-empty-links">' . implode( '', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	bk_cart_grid( bk_cart_suggest_ids( 4 ), __( 'Lo más nuevo', 'buckler-core' ) );
}

/**
 * Imagen diferida de la galería: src/srcset pasan a data-bk-* (los carga pages.js) con <noscript> de respaldo.
 *
 * @param string $html <img> de wp_get_attachment_image().
 * @return string
 */
function bk_pdp_defer_img( $html ) {
	if ( ! $html ) {
		return '';
	}
	$blank = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 2 3%22/%3E';
	$lazy  = preg_replace( array( '/\s(src|srcset)="/', '/\sloading="lazy"/' ), array( ' data-bk-$1="', '' ), $html, -1 );
	$lazy  = preg_replace( '/<img /', '<img src="' . $blank . '" ', (string) $lazy, 1 );
	return $lazy . '<noscript>' . $html . '</noscript>';
}

/**
 * Galería propia: fotos (deslizables, miniaturas) + video del producto. Sin jQuery ni plugins.
 */
function bk_pdp_gallery() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$name   = $product->get_name();
	$ids    = array_values( array_unique( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) ), 'wp_attachment_is_image' ) ) );
	$video  = function_exists( 'bk_get_product_video' ) ? bk_get_product_video( $product->get_id() ) : null;
	$poster = $ids ? (string) wp_get_attachment_image_url( $ids[0], 'woocommerce_single' ) : '';
	$badge  = '';
	if ( $product->is_featured() ) {
		$badge = '<span class="bk-pg__badge">' . esc_html__( 'Nuevo', 'buckler-core' ) . '</span>';
	} elseif ( $product->is_on_sale() ) {
		$badge = '<span class="bk-pg__badge is-sale">' . esc_html__( 'Oferta', 'buckler-core' ) . '</span>';
	}
	$total = count( $ids ) + ( $video ? 1 : 0 );
	?>
	<div class="bk-pg" data-bk-pg>
		<?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="bk-pg__track" tabindex="0" role="region" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: producto */ __( 'Fotos de %s', 'buckler-core' ), $name ) ); ?>">
			<?php foreach ( $ids as $i => $id ) : ?>
				<figure class="bk-pg__slide" id="bk-pg-<?php echo (int) $i; ?>">
					<?php
					$bk_img = bk_img_add_srcset(
						wp_get_attachment_image(
							$id,
							'woocommerce_single',
							false,
							array(
								'alt'           => bk_image_alt( $id, $i ? sprintf( '%s – foto %d', $name, $i + 1 ) : $name ),
								'loading'       => $i ? 'lazy' : 'eager',
								'fetchpriority' => $i ? 'auto' : 'high',
								'sizes'         => '(max-width: 900px) 100vw, 50vw',
							)
						),
						$id,
						'woocommerce_single',
						'(max-width: 900px) 100vw, 50vw'
					);
					// Fotos 2..n: se descargan solo cuando la persona se acerca a ellas (pages.js). El navegador
					// trataría la carga diferida nativa como "cerca" en un carrusel horizontal y las bajaría todas.
					echo $i ? bk_pdp_defer_img( $bk_img ) : $bk_img; // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</figure>
			<?php endforeach; ?>
			<?php if ( $video ) : ?>
				<figure class="bk-pg__slide is-video" id="bk-pg-<?php echo count( $ids ); ?>">
					<video muted playsinline loop preload="none" data-poster="<?php echo esc_url( $poster ); ?>" tabindex="0" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: producto */ __( 'Video de %s. Se reproduce solo; toca para pausar.', 'buckler-core' ), $name ) ); ?>">
						<source src="<?php echo esc_url( $video['url'] ); ?>" type="<?php echo esc_attr( $video['mime'] ); ?>">
					</video>
				</figure>
			<?php endif; ?>
		</div>
		<?php if ( $total > 1 ) : ?>
			<button type="button" class="bk-pg__nav is-prev" data-dir="-1" aria-label="<?php esc_attr_e( 'Foto anterior', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:chevron-left', 22 ); // phpcs:ignore ?></button>
			<button type="button" class="bk-pg__nav is-next" data-dir="1" aria-label="<?php esc_attr_e( 'Foto siguiente', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:chevron-right', 22 ); // phpcs:ignore ?></button>
			<div class="bk-pg__thumbs">
				<?php foreach ( $ids as $i => $id ) : ?>
					<button type="button" class="bk-pg__thumb" data-go="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: número */ __( 'Ver foto %d', 'buckler-core' ), $i + 1 ) ); ?>" <?php echo $i ? '' : 'aria-current="true"'; ?>>
						<?php echo wp_get_attachment_image( $id, 'woocommerce_gallery_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ); ?>
					</button>
				<?php endforeach; ?>
				<?php if ( $video ) : ?>
					<button type="button" class="bk-pg__thumb is-video" data-go="<?php echo count( $ids ); ?>" aria-label="<?php esc_attr_e( 'Ver video', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:play', 22 ); // phpcs:ignore ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Título: H1 con la línea (diferencia los modelos repetidos en Slim y Curvy) y REF.
 */
function bk_pdp_title() {
	global $product;
	$line = bk_product_line( $product->get_id() );
	echo '<h1 class="product_title entry-title bk-pdp__title">' . esc_html( $product->get_name() );
	if ( $line ) {
		echo ' <span class="bk-pdp__line">· ' . esc_html( $line['label'] ) . '</span>';
	}
	echo '</h1>';
	if ( $product->get_sku() ) {
		echo '<p class="bk-pdp__ref">REF ' . esc_html( $product->get_sku() ) . '</p>';
	}
}

/**
 * Cuotas Addi bajo el precio.
 */
function bk_pdp_addi() {
	global $product;
	$n     = (int) bk_config( 'addi_installments' );
	$price = (float) ( $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : wc_get_price_to_display( $product ) );
	if ( $n > 1 && $price > 0 ) {
		/* translators: 1: cuotas, 2: valor */
		echo '<p class="bk-pdp__addi">' . esc_html( sprintf( __( 'o %1$d cuotas de %2$s sin interés con Addi', 'buckler-core' ), $n, html_entity_decode( wp_strip_all_tags( wc_price( ceil( $price / $n ) ) ) ) ) ) . '</p>';
	}
}

/**
 * Guía de tallas (imágenes ACF) y enlace al mismo modelo en la otra línea.
 */
function bk_pdp_size_tools() {
	global $product;
	$guide = bk_image_id( bk_option_field( 'imagen_guia_de_tallas' ) );
	$shape = bk_image_id( bk_option_field( 'imagen_siluetas' ) );
	$twin  = bk_pdp_twin( $product );
	if ( ! $guide && ! $shape && ! $twin ) {
		return;
	}
	echo '<div class="bk-pdp__tools">';
	if ( $guide || $shape ) {
		echo '<button type="button" class="bk-pdp__guide" data-bk-dialog="bk-size-guide">' . bk_icon_svg( 'lucide:ruler', 18 ) . esc_html__( 'Guía de tallas', 'buckler-core' ) . '</button>'; // phpcs:ignore
	}
	if ( $twin ) {
		$lines = (array) bk_config( 'lines' );
		$label = $lines[ $twin['line'] ]['label'] ?? '';
		$sizes = $lines[ $twin['line'] ]['sizes'] ?? '';
		/* translators: 1: tallas, 2: línea */
		echo '<a class="bk-pdp__twin" href="' . esc_url( $twin['url'] ) . '">' . esc_html( sprintf( __( '¿Buscas %1$s? Ver este modelo en %2$s', 'buckler-core' ), mb_strtolower( $sizes ), $label ) ) . ' →</a>';
	}
	echo '</div>';
	if ( $guide || $shape ) {
		echo '<dialog id="bk-size-guide" class="bk-dialog" aria-labelledby="bk-size-guide-t"><div class="bk-dialog__box"><header><h2 id="bk-size-guide-t">' . esc_html__( 'Guía de tallas y siluetas', 'buckler-core' ) . '</h2><button type="button" class="bk-dialog__close" data-bk-close aria-label="' . esc_attr__( 'Cerrar', 'buckler-core' ) . '">' . bk_icon_svg( 'lucide:x', 22 ) . '</button></header>'; // phpcs:ignore
		foreach ( array_filter( array( $guide, $shape ) ) as $img ) {
			echo wp_get_attachment_image( $img, 'large', false, array( 'loading' => 'lazy', 'alt' => bk_image_alt( $img, __( 'Guía de tallas Buckler', 'buckler-core' ) ) ) );
		}
		echo '</div></dialog>';
	}
}

/**
 * Mismo modelo (mismo nombre) en la otra línea, publicado y con stock.
 *
 * @param WC_Product $product Producto.
 * @return array{url:string,line:string}|null
 */
function bk_pdp_twin( $product ) {
	$key  = 'bk_twin_' . $product->get_id() . '_' . (int) get_option( 'bk_catalog_v', 0 );
	$twin = get_transient( $key );
	if ( false === $twin ) {
		$twin = array();
		$mine = bk_product_line( $product->get_id() );
		$q    = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'title'          => $product->get_name(),
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array( $product->get_id() ),
			)
		);
		foreach ( $q->posts as $id ) {
			$other = bk_product_line( $id );
			$p     = wc_get_product( $id );
			if ( $other && $mine && $other['slug'] !== $mine['slug'] && $p && $p->is_in_stock() ) {
				$twin = array( 'url' => get_permalink( $id ), 'line' => $other['slug'] );
				break;
			}
		}
		set_transient( $key, $twin, DAY_IN_SECONDS );
	}
	return $twin ? $twin : null;
}

/**
 * Beneficios bajo el botón de compra.
 */
function bk_pdp_benefits() {
	$ship = get_page_by_path( 'politica-de-envio', OBJECT, 'politica-empresa' );
	?>
	<ul class="bk-pdp__benefits">
		<li><?php echo bk_icon_svg( 'lucide:truck', 20 ); // phpcs:ignore ?><span><?php esc_html_e( 'Envío gratis a toda Colombia', 'buckler-core' ); ?><?php if ( $ship ) : ?> · <a href="<?php echo esc_url( get_permalink( $ship ) ); ?>"><?php esc_html_e( 'Ver política de envío', 'buckler-core' ); ?></a><?php endif; ?></span></li>
		<li><?php echo bk_icon_svg( 'lucide:credit-card', 20 ); // phpcs:ignore ?><span><?php esc_html_e( 'Paga con Addi, Bold, Wompi o tarjeta', 'buckler-core' ); ?></span></li>
		<li><?php echo bk_icon_svg( 'simple-icons:whatsapp', 20 ); // phpcs:ignore ?><span><a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '¿Dudas con tu talla? Escríbenos por WhatsApp', 'buckler-core' ); ?></a></span></li>
	</ul>
	<?php
}

/**
 * Relacionados y venta cruzada con la tarjeta propia (misma línea primero).
 */
function bk_pdp_related() {
	global $product;
	if ( ! function_exists( 'bk_shortcode_productos' ) ) {
		woocommerce_output_related_products();
		return;
	}
	$ids = array_merge( $product->get_upsell_ids(), $product->get_cross_sell_ids(), wc_get_related_products( $product->get_id(), 12 ) );
	$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 8 );
	if ( $ids ) {
		echo '<section class="bk-pdp__related">' . bk_shortcode_productos( array( 'ids' => implode( ',', $ids ), 'titulo' => __( 'Combínalo con', 'buckler-core' ), 'limite' => 8 ) ) . '</section>'; // phpcs:ignore
	}
}

/* ---------------------------------------------------------------------------
 * Preguntas frecuentes y políticas
 * ------------------------------------------------------------------------- */

/**
 * Preguntas frecuentes agrupadas por categoría (CPT pregunta-frecuente + taxonomía caregoria-pf).
 *
 * @return array<int,array{name:string,slug:string,items:array<int,array{q:string,a:string}>}>
 */
function bk_faq_groups() {
	$groups = array();
	$terms  = get_terms( array( 'taxonomy' => 'caregoria-pf', 'hide_empty' => true ) );
	$terms  = is_array( $terms ) ? $terms : array();
	$terms[] = null; // Sin categoría.
	foreach ( $terms as $t ) {
		$args = array( 'post_type' => 'pregunta-frecuente', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => array( 'menu_order' => 'ASC', 'date' => 'ASC' ), 'no_found_rows' => true );
		if ( $t ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'caregoria-pf', 'terms' => $t->term_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} else {
			$args['tax_query'] = array( array( 'taxonomy' => 'caregoria-pf', 'operator' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$items = array();
		foreach ( get_posts( $args ) as $p ) {
			$a = function_exists( 'get_field' ) ? (string) get_field( 'respuesta_pregunta_frecuente', $p->ID ) : '';
			$a = '' !== trim( wp_strip_all_tags( $a ) ) ? $a : (string) $p->post_content;
			if ( '' !== trim( wp_strip_all_tags( $a ) ) ) {
				$items[] = array( 'q' => get_the_title( $p ), 'a' => wpautop( wp_kses_post( $a ) ) );
			}
		}
		if ( $items ) {
			$groups[] = array( 'name' => $t ? $t->name : __( 'Otras preguntas', 'buckler-core' ), 'slug' => $t ? $t->slug : 'otras', 'items' => $items );
		}
	}
	return $groups;
}

/**
 * Texto de una política: campo ACF del CPT; si está vacío, la opción equivalente de "Listado de Políticas".
 *
 * @param WP_Post $post Política.
 * @return string
 */
function bk_policy_text( $post ) {
	$text = function_exists( 'get_field' ) ? (string) get_field( 'texto_politica', $post->ID ) : '';
	if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
		$map  = array(
			'politica-de-envio'                  => 'politica_de_envio_',
			'politica-de-privacidad'             => 'politica_de_privacidad',
			'aviso-legal'                        => 'aviso_legal',
			'terminos-y-condiciones-del-servicio' => 'terminos_y_condiciones_del_servicio',
			'politica-de-garantias'              => 'politica_de_garantias',
		);
		$text = isset( $map[ $post->post_name ] ) ? (string) bk_option_field( $map[ $post->post_name ] ) : '';
	}
	if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
		$text = (string) $post->post_content;
	}
	return wpautop( wp_kses_post( $text ) );
}
