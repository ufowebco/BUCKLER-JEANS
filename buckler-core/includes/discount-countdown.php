<?php
/**
 * Cuenta regresiva de campañas (pedido del cliente 2026-11-24: Black Friday "más impactante", con contador de cuánto
 * falta para que empiece y visible en toda la web).
 *
 * Una campaña de Descuentos con "Cuenta regresiva" activada se anuncia `pre_days` días antes de empezar ("Empieza en")
 * y, mientras dura, cuenta lo que falta para terminar ("Termina en"). Se pinta en:
 * - franja negra bajo el menú en todas las páginas (menos inicio, finalizar compra y la página de ¡Sale!),
 * - bloque grande en el inicio, debajo de los banners,
 * - banner en la tienda, las líneas, los filtros y la búsqueda (no en ¡Sale!: la campaña no aplica ahí),
 * - recuadro en la ficha del producto (antes de empezar: "este producto quedará en $X"; no en prendas de ¡Sale!).
 *
 * Las páginas se guardan en caché hasta 10 h, así que el contador y el cambio de fase (antes → durante → terminada) se
 * calculan en el navegador con las horas de inicio y fin; el servidor vacía la caché al empezar el anuncio, la campaña
 * y al terminar (bk_disc_schedule).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		if ( ! function_exists( 'bk_on' ) || ! bk_on( 'discounts' ) ) {
			return;
		}
		add_action( 'bk_after_header', 'bk_cd_strip' );
		add_action( 'bk_home_after_hero', 'bk_cd_home' );
		add_action( 'bk_shop_before_bar', 'bk_cd_shop' );
		add_action( 'woocommerce_single_product_summary', 'bk_cd_pdp', 11 );
		add_action( 'woocommerce_before_single_product', static function () {
			// BodyCommerce borra lo enganchado al resumen de la ficha (ver bk_pdp_ensure_extras): se vuelve a poner.
			if ( ! has_action( 'woocommerce_single_product_summary', 'bk_cd_pdp' ) ) {
				add_action( 'woocommerce_single_product_summary', 'bk_cd_pdp', 11 );
			}
		}, 2 );
		add_action( 'wp_footer', 'bk_cd_script', 30 );
	}
);

/**
 * Campaña con cuenta regresiva para mostrar ahora (anuncio previo o vigente). La de mayor porcentaje.
 *
 * @return array|null Campaña + 'phase' (pre|live) + 'show_ts'.
 */
function bk_cd_campaign() {
	static $memo = false;
	if ( false !== $memo ) {
		return $memo;
	}
	$memo = null;
	if ( ! function_exists( 'bk_disc_data' ) ) {
		return null;
	}
	$now = bk_disc_now();
	foreach ( bk_disc_data()['campaigns'] as $c ) {
		if ( empty( $c['active'] ) || empty( $c['countdown'] ) || (float) ( $c['pct'] ?? 0 ) <= 0 ) {
			continue;
		}
		$show = bk_cd_show_ts( $c );
		if ( $now < $show || $now >= (int) $c['end_ts'] ) {
			continue;
		}
		if ( ! $memo || (float) $c['pct'] > (float) $memo['pct'] ) {
			$memo = $c + array(
				'phase'   => $now < (int) $c['start_ts'] ? 'pre' : 'live',
				'show_ts' => $show,
			);
		}
	}
	return $memo;
}

/**
 * Desde cuándo se anuncia la campaña.
 *
 * @param array $c Campaña.
 * @return int
 */
function bk_cd_show_ts( $c ) {
	return (int) $c['start_ts'] - max( 0, min( 14, (int) ( $c['pre_days'] ?? 3 ) ) ) * DAY_IN_SECONDS;
}

/**
 * Textos de la campaña.
 *
 * @param array $c Campaña.
 * @return array{name:string,pct:string,where:string,dates:string}
 */
function bk_cd_texts( $c ) {
	$s = (int) $c['start_ts'];
	$e = (int) $c['end_ts'] - 1;
	$same_month = wp_date( 'm', $s ) === wp_date( 'm', $e );
	return array(
		'name'  => (string) $c['name'],
		'pct'   => bk_disc_num( $c['pct'] ),
		'where' => 'all' === ( $c['scope'] ?? 'all' ) ? __( 'en toda la tienda', 'buckler-core' ) : __( 'en prendas seleccionadas', 'buckler-core' ),
		/* translators: 1: día inicio, 2: día fin con mes */
		'dates' => $same_month ? sprintf( __( 'Del %1$s al %2$s', 'buckler-core' ), wp_date( 'j', $s ), wp_date( 'j \d\e F', $e ) ) : sprintf( __( 'Del %1$s al %2$s', 'buckler-core' ), wp_date( 'j \d\e F', $s ), wp_date( 'j \d\e F', $e ) ),
	);
}

/**
 * Atributos comunes para el contador (el navegador decide la fase con estas horas).
 *
 * @param array $c Campaña.
 * @return string
 */
function bk_cd_attrs( $c ) {
	return sprintf(
		' data-bk-cd data-start="%1$d" data-end="%2$d" data-phase="%3$s"',
		(int) $c['start_ts'] * 1000,
		(int) $c['end_ts'] * 1000,
		esc_attr( $c['phase'] )
	);
}

/**
 * Contador (días · horas · min · seg). Los números se llenan en el navegador; el texto oculto da la fecha completa.
 *
 * @param array  $c    Campaña.
 * @param string $size big|small.
 * @return string
 */
function bk_cd_clock( $c, $size = 'big' ) {
	$units = array(
		'd' => __( 'días', 'buckler-core' ),
		'h' => __( 'horas', 'buckler-core' ),
		'm' => __( 'min', 'buckler-core' ),
		's' => __( 'seg', 'buckler-core' ),
	);
	$out = '<span class="bk-cd__clock is-' . esc_attr( $size ) . '" aria-hidden="true">';
	foreach ( $units as $k => $label ) {
		$out .= '<span class="bk-cd__unit"><b data-u="' . $k . '">00</b><small>' . esc_html( $label ) . '</small></span>';
	}
	$out .= '</span>';
	/* translators: 1: fecha y hora de inicio, 2: fecha y hora de fin */
	$out .= '<span class="screen-reader-text">' . esc_html( sprintf( __( 'Desde el %1$s hasta el %2$s.', 'buckler-core' ), wp_date( 'j \d\e F, g:i a', (int) $c['start_ts'] ), wp_date( 'j \d\e F, g:i a', (int) $c['end_ts'] ) ) ) . '</span>';
	return $out;
}

/**
 * Etiqueta de la fase ("Empieza en" / "Termina en"); el navegador la cambia sola.
 *
 * @param array $c Campaña.
 * @return string
 */
function bk_cd_label( $c ) {
	return '<span class="bk-cd__label" data-pre="' . esc_attr__( 'Empieza en', 'buckler-core' ) . '" data-live="' . esc_attr__( 'Termina en', 'buckler-core' ) . '">' . esc_html( 'pre' === $c['phase'] ? __( 'Empieza en', 'buckler-core' ) : __( 'Termina en', 'buckler-core' ) ) . '</span>';
}

/**
 * Enlace a la tienda con las prendas en descuento.
 *
 * @return string
 */
function bk_cd_shop_url() {
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' );
}

/**
 * ¿Estamos en la página de ¡Sale!? (la campaña no aplica a esas prendas).
 *
 * @return bool
 */
function bk_cd_is_sale_page() {
	$sale = trim( (string) wp_parse_url( (string) bk_config( 'sale_page' ), PHP_URL_PATH ), '/' );
	$here = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return '' !== $sale && $sale === $here;
}

/**
 * Franja bajo el menú.
 *
 * @param string $route Ruta de la página limpia.
 */
function bk_cd_strip( $route = '' ) {
	$c = bk_cd_campaign();
	if ( ! $c || in_array( $route, array( 'home', 'checkout' ), true ) || bk_cd_is_sale_page() ) {
		return;
	}
	$t = bk_cd_texts( $c );
	printf(
		'<div class="bk-cd bk-cd--strip"%1$s><a class="bk-cd__in" href="%2$s"><strong class="bk-cd__name">%3$s</strong><span class="bk-cd__offer">−%4$s %% %5$s</span>%6$s%7$s<span class="bk-cd__go" aria-hidden="true">%8$s</span></a></div>',
		bk_cd_attrs( $c ), // phpcs:ignore WordPress.Security.EscapeOutput
		esc_url( bk_cd_shop_url() ),
		esc_html( $t['name'] ),
		esc_html( $t['pct'] ),
		esc_html( $t['where'] ),
		bk_cd_label( $c ), // phpcs:ignore WordPress.Security.EscapeOutput
		bk_cd_clock( $c, 'small' ), // phpcs:ignore WordPress.Security.EscapeOutput
		bk_icon_svg( 'lucide:arrow-right', 18 ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/**
 * Bloque grande del inicio.
 */
function bk_cd_home() {
	$c = bk_cd_campaign();
	if ( ! $c ) {
		return;
	}
	$t = bk_cd_texts( $c );
	?>
	<section class="bk-cd bk-cd--hero"<?php echo bk_cd_attrs( $c ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-labelledby="bk-cd-hero-t">
		<div class="bk-cd__wrap">
			<div class="bk-cd__copy">
				<p class="bk-cd__kicker"><?php echo esc_html( $t['dates'] ); ?></p>
				<h2 id="bk-cd-hero-t" class="bk-cd__title"><?php echo esc_html( $t['name'] ); ?></h2>
				<p class="bk-cd__big">−<?php echo esc_html( $t['pct'] ); ?> %<span><?php echo esc_html( $t['where'] ); ?></span></p>
				<p class="bk-cd__note"><?php esc_html_e( 'Excepto prendas de ¡Sale! · Envío gratis a toda Colombia', 'buckler-core' ); ?></p>
			</div>
			<div class="bk-cd__side">
				<?php echo bk_cd_label( $c ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo bk_cd_clock( $c, 'big' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<a class="bk-cd__btn" href="<?php echo esc_url( bk_cd_shop_url() ); ?>" data-pre="<?php esc_attr_e( 'Elige tus favoritas', 'buckler-core' ); ?>" data-live="<?php esc_attr_e( 'Comprar con descuento', 'buckler-core' ); ?>"><?php echo esc_html( 'pre' === $c['phase'] ? __( 'Elige tus favoritas', 'buckler-core' ) : __( 'Comprar con descuento', 'buckler-core' ) ); ?></a>
			</div>
		</div>
	</section>
	<?php
}

/**
 * Banner de la tienda (y sus filtros).
 *
 * @param array $ctx Contexto de la tienda.
 */
function bk_cd_shop( $ctx = array() ) {
	$c = bk_cd_campaign();
	if ( ! $c || ! empty( $ctx['sale'] ) || bk_cd_is_sale_page() ) {
		return;
	}
	$t = bk_cd_texts( $c );
	printf(
		'<div class="bk-cd bk-cd--banner"%1$s><div class="bk-cd__copy"><strong class="bk-cd__name">%2$s</strong><span class="bk-cd__offer">−%3$s %% %4$s</span><small>%5$s</small></div><div class="bk-cd__side">%6$s%7$s</div></div>',
		bk_cd_attrs( $c ), // phpcs:ignore WordPress.Security.EscapeOutput
		esc_html( $t['name'] ),
		esc_html( $t['pct'] ),
		esc_html( $t['where'] ),
		esc_html( $t['dates'] . ' · ' . __( 'excepto ¡Sale!', 'buckler-core' ) ),
		bk_cd_label( $c ), // phpcs:ignore WordPress.Security.EscapeOutput
		bk_cd_clock( $c, 'small' ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/**
 * Recuadro de la ficha. Antes de empezar dice cuánto quedará el producto; durante, cuánto falta para terminar.
 */
function bk_cd_pdp() {
	global $product;
	$c = bk_cd_campaign();
	if ( ! $c || ! $product instanceof WC_Product || ! bk_disc_in_scope( $c, $product ) ) {
		return;
	}
	static $done = array();
	if ( isset( $done[ $product->get_id() ] ) ) {
		return; // Evita pintarlo dos veces si se volvió a enganchar.
	}
	$done[ $product->get_id() ] = true;
	$t     = bk_cd_texts( $c );
	$price = '';
	if ( 'pre' === $c['phase'] ) {
		$reg = $product->is_type( 'variable' ) ? (float) $product->get_variation_regular_price( 'min' ) : (float) $product->get_regular_price();
		if ( $reg > 0 ) {
			/* translators: %s: precio con descuento */
			$price = sprintf( __( 'Este producto quedará desde %s', 'buckler-core' ), wp_strip_all_tags( wc_price( bk_disc_apply( $reg, (float) $c['pct'] ) ) ) );
		}
	}
	printf(
		'<div class="bk-cd bk-cd--pdp"%1$s><p class="bk-cd__head"><strong class="bk-cd__name">%2$s</strong> <span class="bk-cd__offer">−%3$s %%</span></p>%4$s<div class="bk-cd__row">%5$s%6$s</div></div>',
		bk_cd_attrs( $c ), // phpcs:ignore WordPress.Security.EscapeOutput
		esc_html( $t['name'] ),
		esc_html( $t['pct'] ),
		$price ? '<p class="bk-cd__price" data-pre-only>' . esc_html( $price ) . ' · ' . esc_html( $t['dates'] ) . '</p>' : '',
		bk_cd_label( $c ), // phpcs:ignore WordPress.Security.EscapeOutput
		bk_cd_clock( $c, 'small' ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/**
 * Contador en el navegador (solo si hay algo que contar en la página). Sin dependencias, 1 temporizador.
 */
function bk_cd_script() {
	if ( ! bk_cd_campaign() ) {
		return;
	}
	?>
	<script id="bk-cd-js">
	(() => {
		const els = [...document.querySelectorAll('[data-bk-cd]')];
		if (!els.length) return;
		const pad = (n) => String(n).padStart(2, '0');
		const tick = () => {
			const now = Date.now();
			els.forEach((el) => {
				const start = +el.dataset.start, end = +el.dataset.end;
				if (now >= end) { el.hidden = true; return; }
				const phase = now < start ? 'pre' : 'live';
				if (el.dataset.phase !== phase) {
					el.dataset.phase = phase;
					el.querySelectorAll('[data-pre][data-live]').forEach((x) => { x.textContent = x.dataset[phase]; });
					if (phase === 'live') el.querySelectorAll('[data-pre-only]').forEach((x) => x.remove());
				}
				let left = Math.max(0, Math.floor(((phase === 'pre' ? start : end) - now) / 1000));
				const v = { d: Math.floor(left / 86400), h: Math.floor(left % 86400 / 3600), m: Math.floor(left % 3600 / 60), s: left % 60 };
				el.querySelectorAll('[data-u]').forEach((b) => { const t = pad(v[b.dataset.u]); if (b.textContent !== t) b.textContent = t; });
			});
		};
		tick();
		setInterval(tick, 1000);
	})();
	</script>
	<?php
}
