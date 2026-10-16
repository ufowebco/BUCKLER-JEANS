<?php
/**
 * Guía de tallas y siluetas en texto (decisión del cliente 2026-10-16: reemplaza las dos imágenes ACF).
 *
 * Ficha de producto: dos botones independientes ("Guía de tallas" y "Siluetas"), cada uno con su <dialog>:
 * - Tallas: pestañas por línea (abre en la del producto), tabla cintura/cadera, "Encuentra tu talla" (pages.js),
 *   cómo medirse y nota del Índigo Stretch.
 * - Siluetas: 11 siluetas con dibujo de línea (SVG generado aquí, sin imágenes), filtros y "Este modelo"
 *   detectado por el nombre del producto (palabras clave de cada silueta).
 * Datos: opción `bk_size_guide` (editable en Gestión → Contenido → Guía de tallas) sobre los valores por defecto,
 * que son los de la guía de Buckler (imagen "Guía de Tallas Buckler" y "Siluetas jeans").
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valores por defecto (medidas en cm).
 *
 * @return array
 */
function bk_size_guide_defaults() {
	return array(
		'tables' => array(
			'linea-slim'  => array( array( '6', 64, 68, 92, 96 ), array( '8', 69, 73, 97, 101 ), array( '10', 74, 78, 102, 106 ), array( '12', 79, 83, 107, 111 ), array( '14', 84, 88, 112, 116 ) ),
			'linea-curvy' => array( array( '14', 84, 88, 112, 116 ), array( '16', 89, 94, 117, 122 ), array( '18', 95, 100, 123, 128 ), array( '20', 101, 106, 129, 134 ), array( '22', 107, 112, 135, 140 ), array( '24', 113, 118, 141, 146 ) ),
		),
		'note'   => 'Nuestras prendas son de Índigo Stretch, un denim con elasticidad: la tabla es una guía aproximada. Si estás entre dos tallas, guíate por la cadera.',
		'sil'    => array(
			array( 'k' => 'skinny', 'es' => 'Jeans ajustados', 'en' => 'Skinny', 'g' => 'ajustado', 'kw' => 'skinny, pegado, tubo, bota ajustada', 'd' => 'Muy ajustados en piernas y caderas, de la cintura al tobillo.' ),
			array( 'k' => 'straight', 'es' => 'Bota recta', 'en' => 'Straight', 'g' => 'recto', 'kw' => 'recta, recto, straight', 'd' => 'Ajustado en la cadera y recto desde el muslo hasta la bota. Tiro alto o medio.' ),
			array( 'k' => 'bootcut', 'es' => 'Bota ancha', 'en' => 'Bootcut · Oxford', 'g' => 'ancho', 'kw' => 'bootcut, boot cut, oxford', 'd' => 'Ajustado hasta la rodilla, más ancho y un poco acampanado en la parte baja de la pierna.' ),
			array( 'k' => 'flare', 'es' => 'Bota campana', 'en' => 'Flare', 'g' => 'ancho', 'kw' => 'flare, campana', 'd' => 'Muy ajustado en caderas y muslos, con la bota visiblemente más ancha.' ),
			array( 'k' => 'wideleg', 'es' => 'Bota ancha', 'en' => 'Wide leg', 'g' => 'ancho', 'kw' => 'wide leg, wideleg, wide-leg', 'd' => 'Pierna ancha desde el principio de la cadera hasta el final del pantalón (26 cm de ancho de bota).' ),
			array( 'k' => 'mom', 'es' => 'Jeans holgados', 'en' => 'Mom jeans', 'g' => 'holgado', 'kw' => 'mom', 'd' => 'Tiro alto o medio, amplio en la pierna y con bota recta. Al tobillo o de largo normal.' ),
			array( 'k' => 'slouchy', 'es' => 'Jeans holgados', 'en' => 'Slouchy', 'g' => 'holgado', 'kw' => 'slouchy, baggy', 'd' => 'Con volumen en la parte de arriba (baggy sin prenses) y bota que cae recta hasta el tobillo.' ),
			array( 'k' => 'palazzo', 'es' => 'Bota ancha', 'en' => 'Palazzo', 'g' => 'ancho', 'kw' => 'palazzo', 'd' => 'La pierna más ancha, desde el principio de la cadera hasta el final del pantalón (30 cm de ancho de bota).' ),
			array( 'k' => 'culotte', 'es' => 'Bota ancha', 'en' => 'Culotte', 'g' => 'ancho', 'kw' => 'culotte', 'd' => 'Pierna ancha y recta que llega al tobillo o un poco más arriba. Cómoda en cintura y caderas.' ),
			array( 'k' => 'parachute', 'es' => 'Prenda holgada', 'en' => 'Parachute', 'g' => 'holgado', 'kw' => 'parachute, paracaidista', 'd' => 'Amplia y relajada, inspirada en el pantalón de paracaidista: volumen en las piernas, pretina elástica y cordones o puños en los tobillos.' ),
			array( 'k' => 'barrel', 'es' => 'Jeans anchos', 'en' => 'Barrel · Balloon', 'g' => 'holgado', 'kw' => 'barrel, balloon, globo, barril', 'd' => 'Más amplio en la mitad de la pierna y se va cerrando hacia el tobillo, en forma de globo o barril.' ),
		),
	);
}

/**
 * Proporciones del dibujo de cada silueta (no editables: son el dibujo).
 *
 * @return array<string,array>
 */
function bk_size_guide_shapes() {
	return array(
		'skinny'    => array( 'hip' => 30, 'thigh' => 11, 'knee' => 8, 'hem' => 6.5, 'len' => 128 ),
		'straight'  => array( 'hip' => 30, 'thigh' => 12, 'knee' => 10.5, 'hem' => 10.5, 'len' => 128 ),
		'bootcut'   => array( 'hip' => 30, 'thigh' => 11.5, 'knee' => 9.5, 'hem' => 14, 'len' => 128 ),
		'flare'     => array( 'hip' => 30, 'thigh' => 11, 'knee' => 8.5, 'hem' => 19, 'len' => 128 ),
		'wideleg'   => array( 'hip' => 31, 'thigh' => 14, 'knee' => 15, 'hem' => 16.5, 'len' => 128 ),
		'mom'       => array( 'hip' => 32, 'thigh' => 14, 'knee' => 12, 'hem' => 10, 'len' => 118 ),
		'slouchy'   => array( 'hip' => 34, 'thigh' => 15.5, 'knee' => 12.5, 'hem' => 10.5, 'len' => 124 ),
		'palazzo'   => array( 'hip' => 31, 'thigh' => 15, 'knee' => 18, 'hem' => 21, 'len' => 128 ),
		'culotte'   => array( 'hip' => 31, 'thigh' => 14, 'knee' => 15.5, 'hem' => 17, 'len' => 100 ),
		'parachute' => array( 'hip' => 33, 'thigh' => 15, 'knee' => 15, 'hem' => 9, 'len' => 126, 'cuff' => true, 'cord' => true ),
		'barrel'    => array( 'hip' => 31, 'thigh' => 14, 'knee' => 19, 'hem' => 9.5, 'len' => 120, 'curve' => true ),
	);
}

/**
 * Datos vigentes (opción sobre los valores por defecto).
 *
 * @return array{tables:array,note:string,sil:array}
 */
function bk_size_guide_data() {
	$def = bk_size_guide_defaults();
	$opt = get_option( 'bk_size_guide' );
	if ( ! is_array( $opt ) ) {
		return $def;
	}
	$out           = $def;
	$out['tables'] = isset( $opt['tables'] ) && is_array( $opt['tables'] ) ? $opt['tables'] + $def['tables'] : $def['tables'];
	$out['note']   = isset( $opt['note'] ) ? (string) $opt['note'] : $def['note'];
	if ( isset( $opt['sil'] ) && is_array( $opt['sil'] ) ) {
		$base = array_column( $def['sil'], null, 'k' );
		$sil  = array();
		foreach ( $opt['sil'] as $s ) {
			if ( isset( $s['k'], $base[ $s['k'] ] ) ) {
				$sil[] = array_merge( $base[ $s['k'] ], array_intersect_key( $s, array_flip( array( 'es', 'en', 'd', 'kw', 'off' ) ) ) );
			}
		}
		$out['sil'] = $sil ? $sil : $def['sil'];
	}
	return $out;
}

/**
 * Texto sin tildes en minúsculas (para comparar palabras clave).
 *
 * @param string $t Texto.
 * @return string
 */
function bk_size_guide_norm( $t ) {
	return strtolower( remove_accents( wp_strip_all_tags( (string) $t ) ) );
}

/**
 * Silueta del producto por su nombre (o slug): la primera cuya palabra clave aparezca como palabra completa.
 *
 * @param WC_Product|null $product Producto.
 * @return string Clave de la silueta o ''.
 */
function bk_size_guide_detect( $product ) {
	if ( ! $product instanceof WC_Product || ! bk_size_guide_is_pants( $product ) ) {
		return ''; // "Falda recta" no es un jean de bota recta: solo jeans y pantalones.
	}
	$hay = ' ' . preg_replace( '/[^a-z0-9]+/', ' ', bk_size_guide_norm( $product->get_name() . ' ' . str_replace( '-', ' ', $product->get_slug() ) ) ) . ' ';
	foreach ( bk_size_guide_data()['sil'] as $s ) {
		if ( ! empty( $s['off'] ) ) {
			continue;
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $s['kw'] ) ) ) as $kw ) {
			$kw = trim( preg_replace( '/[^a-z0-9]+/', ' ', bk_size_guide_norm( $kw ) ) );
			if ( '' !== $kw && false !== strpos( $hay, ' ' . $kw . ' ' ) ) {
				return (string) $s['k'];
			}
		}
	}
	return '';
}

/**
 * ¿Es un jean o pantalón? (las siluetas solo aplican a esas prendas).
 *
 * @param WC_Product $product Producto.
 * @return bool
 */
function bk_size_guide_is_pants( $product ) {
	$id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$terms = get_the_terms( $id, 'product_cat' );
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		if ( preg_match( '/jean|pantal/', bk_size_guide_norm( $t->slug . ' ' . $t->name ) ) ) {
			return true;
		}
	}
	return (bool) preg_match( '/\\b(jean|jeans|pantalon)\\b/', bk_size_guide_norm( $product->get_name() ) );
}

/**
 * Curva suave (Catmull-Rom → Bézier) por los puntos dados, sin el punto inicial.
 *
 * @param array $p Puntos [x, y].
 * @return string
 */
function bk_size_guide_curve( $p ) {
	$d = '';
	$n = count( $p );
	for ( $i = 0; $i < $n - 1; $i++ ) {
		$p0 = $p[ $i - 1 ] ?? $p[ $i ];
		$p1 = $p[ $i ];
		$p2 = $p[ $i + 1 ];
		$p3 = $p[ $i + 2 ] ?? $p2;
		$d .= sprintf(
			' C%.1f %.1f %.1f %.1f %.1f %.1f',
			$p1[0] + ( $p2[0] - $p0[0] ) / 6,
			$p1[1] + ( $p2[1] - $p0[1] ) / 6,
			$p2[0] - ( $p3[0] - $p1[0] ) / 6,
			$p2[1] - ( $p3[1] - $p1[1] ) / 6,
			$p2[0],
			$p2[1]
		);
	}
	return $d;
}

/**
 * Dibujo de una silueta (pantalón de frente) en SVG.
 *
 * @param string $key Clave.
 * @return string
 */
function bk_size_guide_svg( $key ) {
	$s = bk_size_guide_shapes()[ $key ] ?? null;
	if ( ! $s ) {
		return '';
	}
	$w      = 50;
	$top    = 4;
	$crotch = 40;
	$knee_y = 40 + ( $s['len'] - 40 ) * 0.5 + ( empty( $s['curve'] ) ? 0 : 4 );
	$hem_y  = $s['len'];
	$center = static fn( $hw ) => min( $w - 13, $w - 1 - $hw );
	$kc     = $center( $s['knee'] );
	$hc     = $center( $s['hem'] );
	$tc     = $w - $s['thigh'];
	$outer  = array( array( $w - 21, $top ), array( $w - $s['hip'], 22 ), array( $tc - $s['thigh'], $crotch + 6 ), array( $kc - $s['knee'], $knee_y ), array( $hc - $s['hem'], $hem_y ) );
	$inner  = array( array( $hc + $s['hem'], $hem_y ), array( $kc + $s['knee'], $knee_y ), array( $w, $crotch ) );
	$mirror = static fn( $pts ) => array_map( static fn( $p ) => array( 2 * $w - $p[0], $p[1] ), $pts );
	$r_in   = array_reverse( $mirror( $inner ) );
	$r_out  = array_reverse( $mirror( $outer ) );

	$d  = sprintf( 'M%d %d L%.1f %.1f', $w, $top, $outer[0][0], $outer[0][1] ) . bk_size_guide_curve( $outer );
	$d .= sprintf( ' L%.1f %.1f', $inner[0][0], $inner[0][1] ) . bk_size_guide_curve( $inner );
	$d .= bk_size_guide_curve( $r_in );
	$d .= sprintf( ' L%.1f %.1f', $r_out[0][0], $r_out[0][1] ) . bk_size_guide_curve( $r_out ) . ' Z';

	$x  = sprintf( '<path d="M%d %d H%d"/><path d="M%d %d V%d"/>', $w - 20, $top + 6, $w + 20, $w, $top + 6, $crotch - 8 );
	if ( ! empty( $s['cuff'] ) ) {
		$a  = $hc - $s['hem'] - 1;
		$b  = $hc + $s['hem'] + 1;
		$x .= sprintf( '<path d="M%.1f %.1f H%.1f M%.1f %.1f H%.1f"/>', $a, $hem_y - 7, $b, 2 * $w - $b, $hem_y - 7, 2 * $w - $a );
	}
	if ( ! empty( $s['cord'] ) ) {
		$x .= sprintf( '<path d="M%d %d q-2 9 -5 12 M%d %d q2 9 5 12"/>', $w - 3, $top + 6, $w + 3, $top + 6 );
	}
	return '<svg class="bk-sil__svg" viewBox="-12 0 124 136" aria-hidden="true" focusable="false"><path class="bk-sil__fill" d="' . esc_attr( $d ) . '"/><g class="bk-sil__lines">' . $x . '</g></svg>';
}

/**
 * Figura de "cómo medirse" (cintura 1, cadera 2).
 *
 * @return string
 */
function bk_size_guide_body_svg() {
	return '<svg class="bk-sg__body" viewBox="0 0 120 220" aria-hidden="true" focusable="false">'
		. '<path class="bk-sil__fill" d="M60 10c9 0 15 7 15 16s-6 16-15 16-15-7-15-16 6-16 15-16Z"/>'
		. '<path class="bk-sil__fill" d="M38 52c6-6 14-8 22-8s16 2 22 8l4 30c1 8-2 14-6 18l6 22c3 11 3 20 0 28l-6 60h-12l-6-58-6 58H42l-6-60c-3-8-3-17 0-28l6-22c-4-4-7-10-6-18Z"/>'
		. '<path class="bk-sg__tape" d="M36 96 H84"/><path class="bk-sg__tape" d="M31 128 H89"/>'
		. '<circle class="bk-sg__dot" cx="100" cy="96" r="9"/><text x="100" y="100" text-anchor="middle">1</text>'
		. '<circle class="bk-sg__dot" cx="104" cy="128" r="9"/><text x="104" y="132" text-anchor="middle">2</text></svg>';
}

/**
 * Botones + ventanas de la ficha.
 *
 * @param WC_Product $product Producto.
 * @return array{buttons:string,dialogs:string}
 */
function bk_size_guide_html( $product ) {
	$data  = bk_size_guide_data();
	$lines = (array) bk_config( 'lines' );
	$own   = bk_product_line( $product->get_id() );
	$own   = $own ? $own['slug'] : (string) array_key_first( $lines );
	$here  = bk_size_guide_detect( $product );
	$x     = '<button type="button" class="bk-dialog__close" data-bk-close aria-label="' . esc_attr__( 'Cerrar', 'buckler-core' ) . '">' . bk_icon_svg( 'lucide:x', 22 ) . '</button>';
	$jean  = '<svg class="bk-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 2h12l1 6-3 14h-3l-1-11-1 11H8L5 8z"/></svg>';

	$tables = array_filter( $data['tables'], static fn( $rows, $slug ) => isset( $lines[ $slug ] ) && $rows, ARRAY_FILTER_USE_BOTH );
	$sils   = array_values( array_filter( $data['sil'], static fn( $s ) => empty( $s['off'] ) ) ); // En todos los productos (decisión del cliente 2026-10-16); "Este modelo" solo en jeans y pantalones.

	$b = '';
	if ( $tables ) {
		$b .= '<button type="button" class="bk-pdp__guide" data-bk-dialog="bk-size-guide">' . bk_icon_svg( 'lucide:ruler', 18 ) . esc_html__( 'Guía de tallas', 'buckler-core' ) . '</button>';
	}
	if ( $sils ) {
		$b .= '<button type="button" class="bk-pdp__guide bk-pdp__sil" data-bk-dialog="bk-silhouettes">' . $jean . esc_html__( 'Siluetas', 'buckler-core' ) . '</button>';
	}

	$d = '';
	if ( $tables ) {
		$own   = isset( $tables[ $own ] ) ? $own : (string) array_key_first( $tables );
		$tabs  = '';
		$pans  = '';
		$all   = array();
		foreach ( $tables as $slug => $rows ) {
			$id     = 'bk-sg-' . sanitize_html_class( $slug );
			$nums   = array_map( static fn( $r ) => $r[0], $rows );
			$range  = reset( $nums ) . ' a ' . end( $nums );
			$tabs  .= '<button type="button" role="tab" id="' . esc_attr( $id ) . '-tab" aria-controls="' . esc_attr( $id ) . '" aria-selected="' . ( $slug === $own ? 'true' : 'false' ) . '"' . ( $slug === $own ? '' : ' tabindex="-1"' ) . ' data-bk-sg-tab="' . esc_attr( $slug ) . '">' . esc_html( $lines[ $slug ]['label'] ) . ' <small>· ' . esc_html( $range ) . '</small></button>';
			$tr     = '';
			foreach ( $rows as $r ) {
				$tr   .= '<tr data-size="' . esc_attr( $r[0] ) . '"><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . esc_html( $r[1] . ' – ' . $r[2] ) . ' cm</td><td>' . esc_html( $r[3] . ' – ' . $r[4] ) . ' cm</td></tr>';
				$all[] = array( (string) $r[0], (float) $r[1], (float) $r[2], (float) $r[3], (float) $r[4], $slug );
			}
			$pans .= '<div class="bk-sg__table" role="tabpanel" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-tab"' . ( $slug === $own ? '' : ' hidden' ) . '><table><caption class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: línea */ __( 'Medidas en centímetros, %s', 'buckler-core' ), $lines[ $slug ]['label'] ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Talla', 'buckler-core' ) . '</th><th scope="col">' . esc_html__( 'Cintura', 'buckler-core' ) . '</th><th scope="col">' . esc_html__( 'Cadera', 'buckler-core' ) . '</th></tr></thead><tbody>' . $tr . '</tbody></table></div>';
		}
		$labels = array_map( static fn( $l ) => $l['label'], $lines );
		$d     .= '<dialog id="bk-size-guide" class="bk-dialog bk-sg" aria-labelledby="bk-size-guide-t"><div class="bk-dialog__box">'
			. '<header><h2 id="bk-size-guide-t">' . bk_icon_svg( 'lucide:ruler', 22 ) . esc_html__( 'Guía de tallas', 'buckler-core' ) . '</h2>' . $x . '</header>'
			. ( count( $tables ) > 1 ? '<div class="bk-sg__tabs" role="tablist" aria-label="' . esc_attr__( 'Línea', 'buckler-core' ) . '">' . $tabs . '</div>' : '' )
			. $pans
			. '<form class="bk-sg__finder" data-bk-sg-finder data-sizes="' . esc_attr( wp_json_encode( $all ) ) . '" data-lines="' . esc_attr( wp_json_encode( $labels ) ) . '" novalidate>'
			. '<h3>' . esc_html__( 'Encuentra tu talla', 'buckler-core' ) . '</h3><div class="bk-sg__row">'
			. '<label for="bk-sg-w">' . esc_html__( 'Cintura (cm)', 'buckler-core' ) . '<input id="bk-sg-w" name="w" inputmode="decimal" autocomplete="off" placeholder="' . esc_attr__( 'Ej.: 92', 'buckler-core' ) . '"></label>'
			. '<label for="bk-sg-h">' . esc_html__( 'Cadera (cm)', 'buckler-core' ) . '<input id="bk-sg-h" name="h" inputmode="decimal" autocomplete="off" placeholder="' . esc_attr__( 'Ej.: 120', 'buckler-core' ) . '"></label>'
			. '<p class="bk-sg__out" aria-live="polite" data-bk-sg-out>' . esc_html__( 'Escribe tus medidas', 'buckler-core' ) . '</p></div></form>'
			. '<h3>' . esc_html__( '¿Cómo medirte?', 'buckler-core' ) . '</h3><div class="bk-sg__measure">' . bk_size_guide_body_svg() . '<ol>'
			. '<li><span>1</span><p><b>' . esc_html__( 'Cintura', 'buckler-core' ) . '</b>' . esc_html__( 'Rodea con la cinta métrica la parte más angosta de tu cintura, sin apretar ni dejar holgura.', 'buckler-core' ) . '</p></li>'
			. '<li><span>2</span><p><b>' . esc_html__( 'Cadera', 'buckler-core' ) . '</b>' . esc_html__( 'Rodea el cuerpo por la zona más ancha de la cadera, con los pies juntos.', 'buckler-core' ) . '</p></li></ol></div>'
			. ( '' !== trim( $data['note'] ) ? '<p class="bk-sg__note">' . esc_html( $data['note'] ) . '</p>' : '' )
			. '</div></dialog>';
	}
	if ( $sils ) {
		$groups = array( 'all' => __( 'Todas', 'buckler-core' ), 'ajustado' => __( 'Ajustadas', 'buckler-core' ), 'recto' => __( 'Rectas', 'buckler-core' ), 'ancho' => __( 'Bota ancha', 'buckler-core' ), 'holgado' => __( 'Holgadas', 'buckler-core' ) );
		$used   = array_unique( array_column( $sils, 'g' ) );
		$f      = '';
		foreach ( $groups as $g => $l ) {
			if ( 'all' === $g || in_array( $g, $used, true ) ) {
				$f .= '<button type="button" data-bk-sil-g="' . esc_attr( $g ) . '" aria-pressed="' . ( 'all' === $g ? 'true' : 'false' ) . '">' . esc_html( $l ) . '</button>';
			}
		}
		usort( $sils, static fn( $a, $b2 ) => ( $b2['k'] === $here ) <=> ( $a['k'] === $here ) ); // "Este modelo" primero.
		$cards = '';
		foreach ( $sils as $s ) {
			$is     = $s['k'] === $here;
			$cards .= '<li class="bk-sil' . ( $is ? ' is-current' : '' ) . '" data-g="' . esc_attr( $s['g'] ) . '"><div class="bk-sil__art">' . bk_size_guide_svg( $s['k'] ) . '</div><div><h3>' . esc_html( $s['es'] ) . '</h3>'
				. ( '' !== $s['en'] ? '<span class="bk-sil__en">' . esc_html( $s['en'] ) . '</span>' : '' )
				. '<p>' . esc_html( $s['d'] ) . '</p>' . ( $is ? '<strong class="bk-sil__here">' . bk_icon_svg( 'lucide:check', 14 ) . esc_html__( 'Este modelo', 'buckler-core' ) . '</strong>' : '' ) . '</div></li>';
		}
		$d .= '<dialog id="bk-silhouettes" class="bk-dialog bk-sg" aria-labelledby="bk-silhouettes-t"><div class="bk-dialog__box">'
			. '<header><h2 id="bk-silhouettes-t">' . $jean . esc_html__( 'Siluetas', 'buckler-core' ) . '</h2>' . $x . '</header>'
			. '<div class="bk-sil__filters" role="group" aria-label="' . esc_attr__( 'Filtrar siluetas', 'buckler-core' ) . '">' . $f . '</div>'
			. '<ul class="bk-sil__grid">' . $cards . '</ul></div></dialog>';
	}
	return array( 'buttons' => $b, 'dialogs' => $d );
}
