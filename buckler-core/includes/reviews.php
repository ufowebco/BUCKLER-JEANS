<?php
/**
 * Reseñas de Google y promoción "Califícanos".
 *
 * - Las reseñas las sincroniza el plugin WP Social Ninja (tabla {prefix}wpsr_reviews); aquí solo se leen y se
 *   pintan con el diseño de la tienda, sin el CSS/JS del plugin. Si la tabla no existe o está vacía se usa su
 *   shortcode como respaldo ([wp_social_ninja id="…" platform="reviews"]).
 * - Shortcode [bk_resenas titulo="" limite="12" promo="1"].
 * - Promoción: bk_config( 'review_promo' ). Con 'incentive' => false solo invita a calificar (sin descuento).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'bk_resenas', 'bk_reviews_shortcode' );

/**
 * ¿Está activa la promoción de reseñas?
 *
 * @return bool
 */
function bk_review_promo_on() {
	$p = (array) bk_config( 'review_promo' );
	return ! empty( $p['on'] ) && '' !== (string) bk_config( 'review_url' );
}

/**
 * ¿La promoción ofrece descuento?
 *
 * @return bool
 */
function bk_review_promo_incentive() {
	$p = (array) bk_config( 'review_promo' );
	return ! empty( $p['incentive'] );
}

/**
 * Título de la promoción.
 *
 * @return string
 */
function bk_review_promo_title() {
	$p = (array) bk_config( 'review_promo' );
	/* translators: %s: descuento, p. ej. 10 % */
	return bk_review_promo_incentive() ? sprintf( __( 'Califícanos en Google y gana %s de descuento', 'buckler-core' ), $p['discount'] ?? '10 %' ) : __( '¿Te gustó tu compra? Califícanos en Google', 'buckler-core' );
}

/**
 * Tarjeta de la promoción.
 *
 * @param string $context Dónde se muestra (clase CSS: pdp, cart, thanks, account, reviews).
 * @return string
 */
function bk_review_promo( $context = '' ) {
	if ( ! bk_review_promo_on() ) {
		return '';
	}
	$p    = (array) bk_config( 'review_promo' );
	$inc  = bk_review_promo_incentive();
	$text = $inc
		/* translators: %s: descuento */
		? sprintf( __( 'Deja tu reseña, envíanos la captura por WhatsApp y te damos un cupón del %s para tu próxima compra.', 'buckler-core' ), $p['discount'] ?? '10 %' )
		: __( 'Tu opinión ayuda a otras mujeres a encontrar su talla y su jean ideal.', 'buckler-core' );
	$wa   = bk_whatsapp_url( __( 'Hola, ya califiqué a Buckler en Google. Les envío la captura para mi descuento.', 'buckler-core' ) );
	ob_start();
	?>
	<aside class="bk-promo<?php echo $context ? ' bk-promo--' . esc_attr( $context ) : ''; ?>" aria-label="<?php esc_attr_e( 'Califícanos en Google', 'buckler-core' ); ?>">
		<span class="bk-promo__icon"><?php echo bk_icon_svg( $inc ? 'lucide:gift' : 'lucide:star', 26 ); // phpcs:ignore ?></span>
		<div class="bk-promo__body">
			<p class="bk-promo__title"><?php echo esc_html( bk_review_promo_title() ); ?></p>
			<p class="bk-promo__text"><?php echo esc_html( $text ); ?></p>
		</div>
		<div class="bk-promo__actions">
			<a class="bk-btn" href="<?php echo esc_url( (string) bk_config( 'review_url' ) ); ?>" target="_blank" rel="noopener"><?php echo bk_icon_svg( 'simple-icons:google', 16 ); // phpcs:ignore ?><?php esc_html_e( 'Calificar en Google', 'buckler-core' ); ?></a>
			<?php if ( $inc ) : ?>
				<a class="bk-btn is-ghost" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener"><?php echo bk_icon_svg( 'simple-icons:whatsapp', 16 ); // phpcs:ignore ?><?php esc_html_e( 'Enviar captura', 'buckler-core' ); ?></a>
			<?php endif; ?>
		</div>
	</aside>
	<?php
	return (string) ob_get_clean();
}

/**
 * Reseñas de Google guardadas por WP Social Ninja (cacheadas 1 h; se renuevan al sincronizar el plugin).
 *
 * @param int $limit Máximo de reseñas.
 * @return array{items:array,avg:float,total:int}|null Null si el plugin no tiene tabla o reseñas.
 */
function bk_google_reviews( $limit = 12 ) {
	$key  = 'bk_greviews2_' . (int) $limit;
	$data = get_transient( $key );
	if ( is_array( $data ) ) {
		return $data['items'] ? $data : null;
	}
	global $wpdb;
	$t    = $wpdb->prefix . 'wpsr_reviews';
	$data = array( 'items' => array(), 'avg' => 0.0, 'total' => 0 );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`" ); // phpcs:ignore WordPress.DB
		$has   = static fn( $c ) => in_array( $c, $cols, true );
		$where = array( '1=1' );
		if ( $has( 'platform_name' ) ) {
			$where[] = "platform_name = 'google'";
		}
		if ( $has( 'review_approved' ) ) {
			$where[] = 'review_approved = 1';
		}
		if ( $has( 'reviewer_name' ) && $has( 'reviewer_text' ) && $has( 'rating' ) ) {
			$w     = implode( ' AND ', $where );
			$order = $has( 'review_time' ) ? 'review_time DESC' : 'id DESC';
			$time  = $has( 'review_time' ) ? 'review_time' : "''";
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT reviewer_name, reviewer_text, rating, {$time} AS t FROM `{$t}` WHERE {$w} AND rating >= 4 AND reviewer_text <> '' ORDER BY {$order} LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$sum   = $wpdb->get_row( "SELECT AVG(rating) AS a, COUNT(*) AS n FROM `{$t}` WHERE {$w}", ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				$data['items'][] = array(
					'name'   => wp_strip_all_tags( (string) $r['reviewer_name'] ),
					'text'   => bk_review_original_text( (string) $r['reviewer_text'] ),
					'rating' => max( 1, min( 5, (int) $r['rating'] ) ),
					'time'   => $r['t'] ? (int) strtotime( (string) $r['t'] ) : 0,
				);
			}
			$data['avg']   = round( (float) ( $sum['a'] ?? 0 ), 1 );
			$data['total'] = (int) ( $sum['n'] ?? 0 );
		}
	}
	// Promedio y total reales del perfil de Google, si el plugin los guardó. Puede haber varios perfiles/lugares
	// (uno viejo con menos reseñas): se toma el de más reseñas, y nunca menos de las que hay en la tabla.
	$best = null;
	foreach ( (array) get_option( 'wpsr_reviews_google_business_info', array() ) as $info ) {
		if ( is_array( $info ) && ! empty( $info['total_rating'] ) && ( ! $best || (int) $info['total_rating'] > (int) $best['total_rating'] ) ) {
			$best = $info;
		}
	}
	if ( $best && (int) $best['total_rating'] >= $data['total'] ) {
		$data['avg']   = round( (float) ( $best['average_rating'] ?? $data['avg'] ), 1 );
		$data['total'] = (int) $best['total_rating'];
	}
	set_transient( $key, $data, HOUR_IN_SECONDS ); // 1 h: WP Social Ninja sincroniza por su cuenta.
	return $data['items'] ? $data : null;
}

/**
 * Texto original de la reseña, sin la traducción automática que Google añade:
 * "Texto (Translated by Google) Translation" o "(Translated by Google) Translation (Original) Texto".
 *
 * @param string $text Texto guardado por el plugin.
 * @return string
 */
function bk_review_original_text( $text ) {
	$text = trim( wp_strip_all_tags( $text ) );
	if ( preg_match( '/\(Original\)\s*(.+)$/su', $text, $m ) ) {
		$text = $m[1];
	} elseif ( preg_match( '/^(.*?)\s*\((Translated by Google|Traducido por Google)\)/su', $text, $m ) && '' !== trim( $m[1] ) ) {
		$text = $m[1];
	}
	return trim( $text );
}

/**
 * Estrellas (accesibles).
 *
 * @param float $rating Calificación.
 * @param int   $size   Tamaño.
 * @return string
 */
function bk_stars( $rating, $size = 16 ) {
	$full = (int) round( $rating );
	$out  = '<span class="bk-stars" role="img" aria-label="' . esc_attr( sprintf( /* translators: %s: calificación */ __( '%s de 5 estrellas', 'buckler-core' ), number_format_i18n( $rating, floor( $rating ) == $rating ? 0 : 1 ) ) ) . '">'; // phpcs:ignore Universal.Operators.StrictComparisons
	for ( $i = 1; $i <= 5; $i++ ) {
		$out .= '<span class="' . ( $i <= $full ? 'is-on' : '' ) . '">' . bk_icon_svg( 'lucide:star', $size ) . '</span>';
	}
	return $out . '</span>';
}

/**
 * Bloque de reseñas.
 *
 * @param array $args titulo, limite, promo, heading (h2|h3), context.
 * @return string
 */
function bk_reviews_block( $args = array() ) {
	$a    = wp_parse_args( $args, array( 'titulo' => __( 'Lo que dicen nuestras clientas', 'buckler-core' ), 'limite' => 12, 'promo' => 1, 'heading' => 'h2', 'context' => '' ) );
	$h    = in_array( $a['heading'], array( 'h2', 'h3' ), true ) ? $a['heading'] : 'h2';
	$data = bk_google_reviews( (int) $a['limite'] );
	$tpl  = (int) bk_config( 'reviews_template' );
	if ( ! $data && ! ( $tpl && shortcode_exists( 'wp_social_ninja' ) ) ) {
		return $a['promo'] ? bk_review_promo( $a['context'] ) : '';
	}
	ob_start();
	?>
	<section class="bk-reviews<?php echo $a['context'] ? ' bk-reviews--' . esc_attr( $a['context'] ) : ''; ?>" aria-labelledby="bk-reviews-title">
		<div class="bk-reviews__head">
			<div>
				<<?php echo $h; // phpcs:ignore ?> id="bk-reviews-title" class="bk-section-title"><?php echo esc_html( $a['titulo'] ); ?></<?php echo $h; // phpcs:ignore ?>>
				<?php if ( $data && $data['avg'] > 0 ) : ?>
					<p class="bk-reviews__score">
						<?php echo bk_icon_svg( 'simple-icons:google', 18 ); // phpcs:ignore ?>
						<strong><?php echo esc_html( number_format_i18n( $data['avg'], 1 ) ); ?></strong>
						<?php echo bk_stars( $data['avg'], 18 ); // phpcs:ignore ?>
						<?php if ( bk_config( 'reviews_show_total' ) && $data['total'] ) : ?>
							<?php /* translators: %s: número de reseñas */ ?>
							<span><?php echo esc_html( sprintf( _n( '%s reseña en Google', '%s reseñas en Google', $data['total'], 'buckler-core' ), number_format_i18n( $data['total'] ) ) ); ?></span>
						<?php else : ?>
							<?php // El total que guarda WP Social Ninja no se actualiza solo (decisión del cliente 2026-10-15): solo la calificación. ?>
							<span><?php esc_html_e( 'en Google', 'buckler-core' ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
			<?php if ( bk_config( 'maps_url' ) ) : ?>
				<a class="bk-section-more" href="<?php echo esc_url( (string) bk_config( 'maps_url' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver todas en Google', 'buckler-core' ); ?> <?php echo bk_icon_svg( 'lucide:external-link', 14 ); // phpcs:ignore ?></a>
			<?php endif; ?>
		</div>
		<?php if ( $data ) : ?>
			<ul class="bk-reviews__list" tabindex="0" aria-label="<?php esc_attr_e( 'Reseñas', 'buckler-core' ); ?>">
				<?php foreach ( $data['items'] as $r ) : ?>
					<li class="bk-review">
						<div class="bk-review__head">
							<span class="bk-review__avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $r['name'], 0, 1 ) ) ); ?></span>
							<p class="bk-review__name"><?php echo esc_html( $r['name'] ); ?></p>
						</div>
						<?php echo bk_stars( $r['rating'], 15 ); // phpcs:ignore ?>
						<p class="bk-review__text"><?php echo esc_html( $r['text'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<div class="bk-reviews__plugin"><?php echo do_shortcode( '[wp_social_ninja id="' . $tpl . '" platform="reviews"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<?php endif; ?>
		<?php echo $a['promo'] ? bk_review_promo( $a['context'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * [bk_resenas titulo="" limite="12" promo="1"].
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_reviews_shortcode( $atts ) {
	return bk_reviews_block( shortcode_atts( array( 'titulo' => __( 'Lo que dicen nuestras clientas', 'buckler-core' ), 'limite' => 12, 'promo' => 1 ), $atts, 'bk_resenas' ) );
}

// Cuando WP Social Ninja actualiza el perfil de Google (total y promedio), se renueva el bloque de reseñas y la caché.
foreach ( array( 'update_option_wpsr_reviews_google_business_info', 'add_option_wpsr_reviews_google_business_info' ) as $bk_hook ) {
	add_action( $bk_hook, 'bk_google_reviews_flush' );
}
unset( $bk_hook );

/**
 * Borra las copias del bloque de reseñas y purga la caché de páginas.
 */
function bk_google_reviews_flush() {
	foreach ( array( 3, 4, 6, 8, 10, 12, 20 ) as $n ) {
		delete_transient( 'bk_greviews2_' . $n );
	}
	if ( function_exists( 'bk_cache_purge' ) ) {
		bk_cache_purge();
	}
}
