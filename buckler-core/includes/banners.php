<?php
/**
 * Banners de la home a ancho completo, alimentados por ACF (página de opciones "Banners Páginas").
 *
 * [bk_hero]   Carrusel automático con el repeater `banners_pagina_inicio` (imagen PC + imagen móvil por fila).
 * [bk_lineas] Banners de Línea Slim y Línea Curvy (`linea_slim_pc/mo`, `linea_curvy_pc/mo`).
 *
 * Cada banner usa <picture>: el navegador descarga SOLO la versión que necesita (PC 16:9 o móvil 1:1).
 * Uso en Divi 5: módulo Texto o Código de shortcode dentro de una sección de ancho completo sin padding.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'bk_hero', 'bk_shortcode_hero' );
add_shortcode( 'bk_lineas', 'bk_shortcode_lineas' );

/** Punto de corte entre imagen móvil e imagen PC. */
const BK_BANNER_BREAKPOINT = 767;

/**
 * Identifica qué subcampos del repeater son la imagen PC, la imagen móvil y el enlace,
 * leyendo la definición real del campo en ACF (nombre o etiqueta). Así no dependemos de
 * adivinar los nombres internos de los subcampos.
 *
 * @return array{pc:string,mo:string,link:string}
 */
function bk_hero_subfield_map() {
	$map = array(
		'pc'   => '',
		'mo'   => '',
		'link' => '',
	);
	if ( ! function_exists( 'acf_get_field' ) ) {
		return $map;
	}
	$field = acf_get_field( 'banners_pagina_inicio' );
	if ( empty( $field['sub_fields'] ) ) {
		return $map;
	}
	foreach ( $field['sub_fields'] as $sub ) {
		$haystack = strtolower( remove_accents( $sub['name'] . ' ' . $sub['label'] ) );
		if ( 'image' === $sub['type'] ) {
			if ( ! $map['mo'] && preg_match( '/\b(mo|movil|moviles|mobile|celular)\b|_mo\b|movil/', $haystack ) ) {
				$map['mo'] = $sub['name'];
			} elseif ( ! $map['pc'] && preg_match( '/\b(pc|desktop|escritorio)\b|_pc\b/', $haystack ) ) {
				$map['pc'] = $sub['name'];
			}
		} elseif ( ! $map['link'] && in_array( $sub['type'], array( 'link', 'url', 'page_link', 'post_object' ), true ) ) {
			$map['link'] = $sub['name'];
		}
	}
	// Respaldo: si las etiquetas no lo dejan claro, primera imagen = PC, segunda = móvil.
	if ( ! $map['pc'] || ! $map['mo'] ) {
		$images = wp_list_pluck( wp_list_filter( $field['sub_fields'], array( 'type' => 'image' ) ), 'name' );
		$images = array_values( array_diff( $images, array_filter( array( $map['pc'], $map['mo'] ) ) ) );
		$map['pc'] = $map['pc'] ? $map['pc'] : ( array_shift( $images ) ?? '' );
		$map['mo'] = $map['mo'] ? $map['mo'] : ( array_shift( $images ) ?? '' );
	}
	return $map;
}

/**
 * Normaliza el valor de un subcampo de enlace ACF a URL.
 *
 * @param mixed $value Valor ACF (array link, URL, ID o WP_Post).
 * @return string
 */
function bk_link_url( $value ) {
	if ( is_array( $value ) ) {
		return (string) ( $value['url'] ?? '' );
	}
	if ( $value instanceof WP_Post ) {
		return (string) get_permalink( $value );
	}
	if ( is_numeric( $value ) ) {
		return (string) get_permalink( (int) $value );
	}
	return is_string( $value ) ? $value : '';
}

/**
 * Pinta un <picture> con fuente móvil y PC.
 *
 * @param int    $pc_id    Adjunto PC.
 * @param int    $mo_id    Adjunto móvil (opcional; si falta se usa el de PC).
 * @param bool   $priority Primera imagen visible (LCP): carga inmediata y prioridad alta.
 * @param string $alt      Texto alternativo.
 * @return string
 */
function bk_picture( $pc_id, $mo_id, $priority, $alt ) {
	$mo_id = $mo_id ? $mo_id : $pc_id;
	$mo    = wp_get_attachment_image_src( $mo_id, 'full' );
	$pc    = wp_get_attachment_image_src( $pc_id, 'full' );
	if ( ! $pc ) {
		return '';
	}

	$source = '';
	if ( $mo ) {
		$source = sprintf(
			'<source media="(max-width: %1$dpx)" srcset="%2$s" sizes="100vw" width="%3$d" height="%4$d">',
			BK_BANNER_BREAKPOINT,
			esc_attr( (string) wp_get_attachment_image_srcset( $mo_id, 'full' ) ?: $mo[0] ),
			(int) $mo[1],
			(int) $mo[2]
		);
	}

	$img = wp_get_attachment_image(
		$pc_id,
		'full',
		false,
		array(
			'class'         => 'bk-banner__img',
			'alt'           => $alt,
			'sizes'         => '100vw',
			'loading'       => $priority ? 'eager' : 'lazy',
			'fetchpriority' => $priority ? 'high' : 'auto',
			'decoding'      => $priority ? 'sync' : 'async',
		)
	);

	return '<picture class="bk-banner__picture">' . $source . $img . '</picture>';
}

/**
 * [bk_hero autoplay="6" link_default=""]
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shortcode_hero( $atts ) {
	if ( ! bk_on( 'banners' ) && ! bk_on( 'pages' ) ) {
		return '';
	}
	$atts = shortcode_atts(
		array(
			'autoplay' => 6, // Segundos por banner; 0 = sin rotación automática.
			'label'    => __( 'Novedades Buckler', 'buckler-core' ),
		),
		$atts,
		'bk_hero'
	);

	$rows = bk_option_field( 'banners_pagina_inicio' );
	if ( empty( $rows ) || ! is_array( $rows ) ) {
		return current_user_can( 'edit_theme_options' ) ? '<p class="bk-admin-note">[bk_hero] No hay banners en Opciones → Banners Páginas.</p>' : '';
	}

	$map    = bk_hero_subfield_map();
	$slides = array();
	foreach ( $rows as $row ) {
		$pc_id = bk_image_id( $row[ $map['pc'] ] ?? null );
		$mo_id = bk_image_id( $row[ $map['mo'] ] ?? null );
		if ( ! $pc_id && $mo_id ) {
			$pc_id = $mo_id;
		}
		if ( ! $pc_id ) {
			continue;
		}
		$slides[] = array(
			'pc'   => $pc_id,
			'mo'   => $mo_id,
			'link' => $map['link'] ? bk_link_url( $row[ $map['link'] ] ?? '' ) : '',
		);
	}
	if ( ! $slides ) {
		return '';
	}

	$total = count( $slides );
	$id    = 'bk-hero-' . wp_unique_id();
	ob_start();
	?>
	<section id="<?php echo esc_attr( $id ); ?>" class="bk-hero bk-carousel" aria-roledescription="carrusel" aria-label="<?php echo esc_attr( $atts['label'] ); ?>" data-autoplay="<?php echo esc_attr( (int) $atts['autoplay'] ); ?>">
		<div class="bk-carousel__track" tabindex="-1">
			<?php
			foreach ( $slides as $i => $s ) :
				/* translators: 1: posición, 2: total */
				$pos = sprintf( __( '%1$d de %2$d', 'buckler-core' ), $i + 1, $total );
				/* translators: %d: número de banner */
				$alt     = bk_image_alt( $s['pc'], $s['link'] ? sprintf( __( 'Ver promoción %d', 'buckler-core' ), $i + 1 ) : '' );
				$picture = bk_picture( $s['pc'], $s['mo'], 0 === $i, $s['link'] ? '' : $alt );
				?>
				<div class="bk-carousel__slide" role="group" aria-roledescription="diapositiva" aria-label="<?php echo esc_attr( $pos ); ?>" <?php echo 0 === $i ? '' : 'aria-hidden="true"'; ?>>
					<?php if ( $s['link'] ) : ?>
						<a class="bk-banner__link" href="<?php echo esc_url( $s['link'] ); ?>" aria-label="<?php echo esc_attr( $alt ); ?>" <?php echo 0 === $i ? '' : 'tabindex="-1"'; ?>><?php echo $picture; // phpcs:ignore WordPress.Security.EscapeOutput -- generado por wp_get_attachment_image. ?></a>
					<?php else : ?>
						<?php echo $picture; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( $total > 1 ) : ?>
			<div class="bk-carousel__controls">
				<button type="button" class="bk-carousel__btn bk-carousel__prev" aria-label="<?php esc_attr_e( 'Banner anterior', 'buckler-core' ); ?>" aria-controls="<?php echo esc_attr( $id ); ?>"><?php echo bk_icon_svg( 'lucide:chevron-left' ); // phpcs:ignore ?></button>
				<div class="bk-carousel__dots">
					<?php for ( $i = 0; $i < $total; $i++ ) : ?>
						<?php /* translators: %d: número de banner */ ?>
						<button type="button" class="bk-carousel__dot" aria-label="<?php echo esc_attr( sprintf( __( 'Ver banner %d', 'buckler-core' ), $i + 1 ) ); ?>" <?php echo 0 === $i ? 'aria-current="true"' : ''; ?>></button>
					<?php endfor; ?>
				</div>
				<button type="button" class="bk-carousel__btn bk-carousel__next" aria-label="<?php esc_attr_e( 'Banner siguiente', 'buckler-core' ); ?>" aria-controls="<?php echo esc_attr( $id ); ?>"><?php echo bk_icon_svg( 'lucide:chevron-right' ); // phpcs:ignore ?></button>
			</div>
		<?php endif; ?>
	</section>
	<?php
	return trim( ob_get_clean() );
}

/**
 * [bk_lineas slim_url="/linea-slim/" curvy_url="/linea-curvy/"]
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shortcode_lineas( $atts ) {
	if ( ! bk_on( 'banners' ) && ! bk_on( 'pages' ) ) {
		return '';
	}
	$atts = shortcode_atts(
		array(
			'slim_url'  => home_url( '/linea-slim/' ),
			'curvy_url' => home_url( '/linea-curvy/' ),
		),
		$atts,
		'bk_lineas'
	);

	$lines = array(
		'slim'  => array( __( 'Ver Línea Slim, tallas 6 a 12', 'buckler-core' ), $atts['slim_url'] ),
		'curvy' => array( __( 'Ver Línea Curvy, tallas 14 a 24', 'buckler-core' ), $atts['curvy_url'] ),
	);

	$out = '';
	foreach ( $lines as $key => $line ) {
		$pc_id = bk_image_id( bk_option_field( "linea_{$key}_pc" ) );
		$mo_id = bk_image_id( bk_option_field( "linea_{$key}_mo" ) );
		if ( ! $pc_id && ! $mo_id ) {
			continue;
		}
		$out .= sprintf(
			'<a class="bk-linea bk-linea--%1$s" href="%2$s" aria-label="%3$s">%4$s</a>',
			esc_attr( $key ),
			esc_url( $line[1] ),
			esc_attr( $line[0] ),
			bk_picture( $pc_id ? $pc_id : $mo_id, $mo_id, false, '' )
		);
	}
	return $out ? '<nav class="bk-lineas" aria-label="' . esc_attr__( 'Líneas', 'buckler-core' ) . '">' . $out . '</nav>' : '';
}
