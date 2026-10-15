<?php
/**
 * Velocidad de las páginas limpias: optimización del HTML final (document.php lo tiene completo en un búfer).
 *
 * 1. Quita los snippets antiguos del tema/snippets que solo servían al diseño de Divi (grilla AJAX, flechas,
 *    filtros Themify, video de la galería anterior, etiquetas YayPricing, botones +/−, slider de iconos, botón de
 *    WhatsApp por clase) y, sobre todo, el que ocultaba la página entera (opacity 0) hasta cargar todo.
 *    Siguen activos en wp-admin y en cualquier página que no pinte buckler-core. Lista: bk_legacy_snippets.
 * 2. Quita el "jQuery diferido" de Divi (stub jqueryParams): en estas páginas jQuery carga normal.
 * 3. Quita scripts externos de pasarelas (Rapyd, Bold) y select2 fuera de carrito/pago/cuenta.
 * 4. Retrasa analítica y publicidad (Google Tag Manager, gtag, píxeles, AdsAgent…) hasta la primera interacción
 *    (mover el mouse, tocar, desplazar o una tecla). Conservan su orden. Filtro bk_delay_patterns; se desactiva
 *    con bk_config( 'delay_trackers' ) = false.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Firmas de los snippets antiguos (texto que aparece dentro del <script> en línea).
 *
 * @return string[]
 */
function bk_legacy_snippets() {
	return (array) apply_filters(
		'bk_legacy_snippets',
		array(
			"document.documentElement.style.opacity = '0'", // Ocultaba la página hasta el evento load.
			'function cargarGrillaAjax(',                    // Paginación AJAX de la grilla de Divi.
			".woo-prev, .woo-next",                          // Flechas de la galería anterior.
			'wpf_ajax_success',                              // Themify Product Filter.
			'function autoPlayVideoGaleria(',                // Video de la galería de BodyCommerce.
			'function agregarEtiquetasDescuento(',           // Etiquetas de descuento sobre la grilla de Divi.
			"$('.quantity').prepend('<span class=\"minus\">", // Botones +/− (los del carrito son de buckler-core).
			'.listoiconosslider',                            // Slider de iconos de Divi.
			"$('.botonproducto').attr('href'",               // Botón de WhatsApp de la ficha anterior.
		)
	);
}

/**
 * Patrones de scripts que se retrasan hasta la primera interacción (src externo o texto en línea).
 *
 * @return string[] Expresiones regulares.
 */
function bk_delay_patterns() {
	return (array) apply_filters(
		'bk_delay_patterns',
		array(
			'#googletagmanager\.com#',
			'#gtm\.start#',
			'#\bgtag\(#',
			'#connect\.facebook\.net|\bfbq\(#',
			'#/plugins/(facebook-for-woocommerce|official-facebook-pixel|google-listings-and-ads)/#',
			'#woocommerce-analytics|wcAnalytics#',
			'#adsagent|frontendInjection\.js#',
			'#analytics\.tiktok\.com|\bttq\.#',
			'#clarity\.ms|hotjar\.com#',
		)
	);
}

/**
 * Optimiza el HTML de una página de buckler-core.
 *
 * @param string $html  Documento.
 * @param string $route Ruta (home, shop, product…).
 * @return string
 */
function bk_pages_optimize_html( $html, $route ) {
	if ( ! $html || ! $route ) {
		return $html;
	}
	$legacy   = bk_legacy_snippets();
	$delay    = false !== bk_config( 'delay_trackers' ) && ! in_array( $route, array( 'checkout' ), true ) ? bk_delay_patterns() : array();
	$forms    = in_array( $route, array( 'cart', 'checkout', 'account' ), true );
	$delayed  = 0;
	$html     = preg_replace_callback(
		'#<script\b([^>]*)>(.*?)</script>#is',
		static function ( $m ) use ( $legacy, $delay, $forms, $route, &$delayed ) {
			$attrs = $m[1];
			$body  = $m[2];
			$src   = preg_match( '#\ssrc=(["\'])([^"\']+)\1#i', $attrs, $s ) ? $s[2] : '';
			// Datos (JSON-LD, reglas de precarga, plantillas): no se tocan.
			if ( preg_match( '#type=(["\'])(application/(ld\+)?json|speculationrules|text/template|text/html)\1#i', $attrs ) ) {
				return $m[0];
			}
			if ( '' === $src ) {
				foreach ( $legacy as $sig ) {
					if ( false !== strpos( $body, $sig ) ) {
						return '';
					}
				}
				// jQuery diferido de Divi (stub + reproducción tras cargar jQuery).
				if ( 0 === strpos( ltrim( $body ), 'let jqueryParams=[]' ) || 0 === strpos( ltrim( $body ), 'jqueryParams.length&&' ) ) {
					return '';
				}
			} elseif ( 'checkout' !== $route && preg_match( '#checkouttoolkit\.rapyd\.net|checkout\.bold\.co|/plugins/(bold-pagos-en-linea|rapyd-payments-plugin-for-woocommerce)/#', $src ) ) {
				return '';
			} elseif ( ! $forms && preg_match( '#/select2/|/selectWoo/#', $src ) ) {
				return '';
			}
			foreach ( $delay as $re ) {
				if ( preg_match( $re, $src ? $src : $body ) ) {
					++$delayed;
					$attrs = preg_replace( '#\stype=(["\'])[^"\']*\1#i', '', $attrs );
					if ( $src ) {
						$attrs = preg_replace( '#\ssrc=(["\'])([^"\']+)\1#i', ' data-bk-src="$2"', $attrs );
					}
					return '<script type="text/bk-delay"' . $attrs . '>' . $body . '</script>';
				}
			}
			return $m[0];
		},
		$html
	);
	$html = bk_pages_maybe_drop_jquery( (string) $html, $route );
	if ( $delayed ) {
		$html = str_replace( '</body>', bk_delay_loader() . "\n</body>", (string) $html );
	}
	return (string) $html;
}

/**
 * Quita jQuery (≈100 KB) en inicio, tienda, FAQ, políticas y 404 cuando ningún script que queda lo usa:
 * todos los externos son de una lista conocida sin jQuery y ningún script en línea lo menciona.
 *
 * @param string $html  Documento ya optimizado.
 * @param string $route Ruta.
 * @return string
 */
function bk_pages_maybe_drop_jquery( $html, $route ) {
	if ( ! in_array( $route, (array) apply_filters( 'bk_nojquery_routes', array( 'home', 'shop', 'faq', 'policy', '404' ) ), true ) ) {
		return $html;
	}
	$vanilla = (string) apply_filters( 'bk_vanilla_scripts', '#/plugins/buckler-core/|/sourcebuster/|order-attribution|wp-emoji|/js/dist/(hooks|i18n)|googletagmanager\.com|frontendInjection\.js|/jquery/jquery(-migrate)?(\.min)?\.js#' );
	preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $all, PREG_SET_ORDER );
	foreach ( $all as $m ) {
		if ( preg_match( '#type=(["\'])(application/(ld\+)?json|speculationrules)\1#i', $m[1] ) || false !== strpos( $m[1], 'id="bk-delay"' ) ) {
			continue;
		}
		$src = preg_match( '#\s(?:data-bk-)?src=(["\'])([^"\']+)\1#i', $m[1], $s ) ? $s[2] : '';
		if ( $src ? ! preg_match( $vanilla, $src ) : preg_match( '#jQuery|\$\(|\$\.#', $m[2] ) ) {
			return $html; // Algo puede necesitar jQuery: se deja.
		}
	}
	return (string) preg_replace( '#<script\b[^>]*src=(["\'])[^"\']*/jquery/jquery(-migrate)?(\.min)?\.js[^"\']*\1[^>]*></script>\s*#i', '', $html );
}

/**
 * Cargador de los scripts retrasados: en la primera interacción los ejecuta en su orden original.
 *
 * @return string
 */
function bk_delay_loader() {
	return '<script id="bk-delay">(()=>{const E=["pointerdown","pointermove","keydown","touchstart","scroll","wheel"];let go=0;const run=async()=>{if(go)return;go=1;E.forEach(e=>removeEventListener(e,run,{passive:!0}));for(const o of document.querySelectorAll(\'script[type="text/bk-delay"]\')){await new Promise(r=>{const n=document.createElement("script");for(const a of o.attributes)if(a.name!=="type"&&a.name!=="data-bk-src")n.setAttribute(a.name,a.value);if(o.dataset.bkSrc){n.src=o.dataset.bkSrc;n.onload=n.onerror=r}else{n.text=o.textContent}o.replaceWith(n);if(!o.dataset.bkSrc)r()})}};E.forEach(e=>addEventListener(e,run,{passive:!0}))})();</script>';
}
