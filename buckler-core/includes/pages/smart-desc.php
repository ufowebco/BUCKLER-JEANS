<?php
/**
 * Descripción larga "inteligente" de la ficha: lee el texto que la tienda ya escribe (✅ detalles, 📏 modelos en
 * fotos, 📌 nota, 🚚 envíos) y lo pinta con íconos según lo que dice cada línea (tiro, tela, color, botones,
 * bolsillos, ruedo, silueta, comodidad, origen…). No cambia el texto guardado: si la descripción no tiene ese
 * formato (menos de 3 detalles), se muestra igual que antes.
 * Apagar: add_filter( 'bk_smart_desc', '__return_false' ).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Íconos por palabra clave (el primero que coincide gana; el orden importa).
 *
 * @return array<int,array{0:string,1:string}>
 */
function bk_desc_icon_rules() {
	return (array) apply_filters(
		'bk_desc_icon_rules',
		array(
			array( '/\b(origen|hecho en|fabricad)/u', 'flag:co-4x3' ),
			array( '/\btiro\b/u', 'lucide:ruler' ),
			array( '/\b(lavado|color|tono|negro|azul|blanco|gris|beige|caf[eé]|crudo)\b/u', 'lucide:palette' ),
			array( '/\b(tela|licra|licrad|denim|velvet|algod|material|stretch|r[ií]gid|jacquard|gamuza|lino|dril)/u', 'lucide:layers' ),
			array( '/\b(bot[oó]n|botones|botonera|abotona|cierre|cremallera|broche)/u', 'lucide:circle-dot' ),
			array( '/\b(set|conjunto|dos piezas|camiseta|chaleco)\b/u', 'lucide:shirt' ),
			array( '/\b(costura|pretina|tache|pedrer|estampad|bordad|destroy|roto|rotos|brillo|apliqu)/u', 'lucide:sparkles' ),
			array( '/\b(proceso|vintage|dirty|matiz|desgast)/u', 'lucide:palette' ),
			array( '/\b(peso|gramos)\b/u', 'lucide:feather' ),
			array( '/\bbolsillo/u', 'lucide:wallet' ),
			array( '/\b(ruedo|bota|desflec|dobladillo|tobillo|largo)/u', 'lucide:scissors' ),
			array( '/\b(silueta|ajust|figura|estiliz|realz|favorec|cintura)/u', 'lucide:sparkles' ),
			array( '/\b(comod|libertad|movimiento|el[aá]stic|suave|fresc|liviano)/u', 'lucide:feather' ),
			array( '/\b(combin|vers[aá]til|look|ideal|outfit|cuello|manga|pu[ñn]o)/u', 'lucide:shirt' ),
		)
	);
}

/**
 * Ícono para una línea de detalle.
 *
 * @param string $text Texto.
 * @return string Nombre del ícono.
 */
function bk_desc_icon_for( $text ) {
	$t = mb_strtolower( $text );
	foreach ( bk_desc_icon_rules() as $r ) {
		if ( preg_match( $r[0], $t ) ) {
			return $r[1];
		}
	}
	return 'lucide:check';
}

/**
 * Quita emojis, viñetas y espacios sobrantes.
 *
 * @param string $s Línea.
 * @return string
 */
function bk_desc_clean( $s ) {
	$s = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{1F1E6}-\x{1F1FF}]/u', '', $s );
	$s = preg_replace( '/^\s*[•\-–—*·✓✔]+\s*/u', '', (string) $s );
	return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
}

/**
 * Detalle con el inicio en negrita ("Tiro alto" que define…), si el corte queda natural.
 *
 * @param string $text Texto.
 * @return string HTML.
 */
function bk_desc_lead( $text ) {
	$cut = null;
	foreach ( array( ': ', ' que ', ' con ', ', ', ' para ' ) as $sep ) {
		$p = mb_strpos( $text, $sep );
		if ( false !== $p && $p >= 3 && $p <= 42 && ( null === $cut || $p < $cut[0] ) ) {
			$cut = array( $p, $sep );
		}
	}
	if ( ! $cut ) {
		return esc_html( $text );
	}
	$head = mb_substr( $text, 0, $cut[0] ) . ( ': ' === $cut[1] ? ':' : '' );
	$rest = mb_substr( $text, $cut[0] + ( ': ' === $cut[1] ? 2 : 0 ) );
	return '<strong>' . esc_html( $head ) . '</strong>' . ( ': ' === $cut[1] ? ' ' : '' ) . esc_html( $rest );
}

/**
 * Convierte la descripción. Devuelve '' si no tiene el formato esperado.
 *
 * @param string $html Descripción (ya con the_content).
 * @return string
 */
function bk_desc_smart( $html ) {
	if ( ! apply_filters( 'bk_smart_desc', true ) ) {
		return '';
	}
	$t     = preg_replace( '#<br\b[^>]*>#i', "\n", (string) $html );
	$t     = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", $t );
	$t     = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' );
	$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ), 'strlen' ) );

	$intro  = array();
	$title  = '';
	$feat   = array();
	$models = array();
	$notes  = array();
	$ship   = array();
	$outro  = array();
	$mode   = 'intro';
	foreach ( $lines as $raw ) {
		$bullet = (bool) preg_match( '/^\s*(✅|✔|✓|•|-|–|—|\*)/u', $raw );
		$line   = bk_desc_clean( $raw );
		if ( '' === $line ) {
			continue;
		}
		if ( preg_match( '/^modelos?\b.*:$/iu', $line ) ) {
			$mode = 'models';
			continue;
		}
		if ( preg_match( '/^nota\s*:\s*(.*)$/iu', $line, $m ) ) {
			$notes[] = $m[1];
			$mode    = 'outro';
			continue;
		}
		if ( preg_match( '/env[ií]os?\b/iu', $line ) && ( false !== strpos( $raw, '🚚' ) || preg_match( '/^¡?env[ií]os/iu', $line ) ) ) {
			$ship[] = $line;
			$mode   = 'outro';
			continue;
		}
		if ( ! $bullet && mb_strlen( $line ) <= 60 && ':' === mb_substr( $line, -1 ) && ! $feat ) {
			$title = rtrim( $line, ':' );
			$mode  = 'feat';
			continue;
		}
		if ( 'models' === $mode && preg_match( '/^(.+?):\s*mide\s+([\d.,]+)\s*m\b.*?talla\s+([\w-]+)/iu', $line, $m ) ) {
			$models[] = array( trim( $m[1] ), str_replace( '.', ',', $m[2] ) . ' m', $m[3] );
			continue;
		}
		if ( $bullet || 'feat' === $mode && false !== strpos( $raw, '✅' ) ) {
			$feat[] = $line;
			$mode   = 'feat';
			continue;
		}
		if ( 'intro' === $mode ) {
			$intro[] = $line;
		} else {
			$outro[] = $line;
		}
	}
	if ( count( $feat ) < 3 ) {
		return ''; // No es el formato de la tienda: se muestra tal cual.
	}

	$out = '<div class="bk-desc">';
	foreach ( $intro as $p ) {
		$out .= '<p class="bk-desc__intro">' . esc_html( $p ) . '</p>';
	}
	$out .= '<h3 class="bk-desc__title">' . esc_html( $title ? $title : __( 'Detalles', 'buckler-core' ) ) . '</h3><ul class="bk-desc__feat">';
	foreach ( $feat as $f ) {
		$icon = bk_desc_icon_for( $f );
		$out .= '<li class="bk-desc__item' . ( 'flag:co-4x3' === $icon ? ' is-origin' : '' ) . '"><span class="bk-desc__ico">' . bk_icon_svg( $icon, 20 ) . '</span><span>' . bk_desc_lead( $f ) . '</span></li>';
	}
	$out .= '</ul>';
	if ( $models ) {
		$out .= '<div class="bk-desc__models"><h3 class="bk-desc__title">' . esc_html__( 'Modelos en fotos', 'buckler-core' ) . '</h3><ul>';
		foreach ( $models as $m ) {
			$out .= '<li><span class="bk-desc__ico">' . bk_icon_svg( 'lucide:ruler', 18 ) . '</span><span><strong>' . esc_html( $m[0] ) . '</strong> ' . esc_html( sprintf( /* translators: 1: estatura, 2: talla */ __( 'Mide %1$s · usa talla %2$s', 'buckler-core' ), $m[1], $m[2] ) ) . '</span></li>';
		}
		$out .= '</ul></div>';
	}
	foreach ( $outro as $p ) {
		$out .= '<p>' . esc_html( $p ) . '</p>';
	}
	foreach ( $notes as $n ) {
		$out .= '<p class="bk-desc__note">' . bk_icon_svg( 'lucide:info', 18 ) . '<span><strong>' . esc_html__( 'Nota:', 'buckler-core' ) . '</strong> ' . esc_html( $n ) . '</span></p>';
	}
	foreach ( $ship as $s ) {
		$lead = preg_match( '/^(¡[^!]{3,60}!)\s*(.*)$/u', $s, $m ) ? '<strong>' . esc_html( $m[1] ) . '</strong> ' . esc_html( $m[2] ) : bk_desc_lead( $s );
		$out .= '<p class="bk-desc__ship">' . bk_icon_svg( 'lucide:truck', 18 ) . '<span>' . $lead . '</span></p>';
	}
	return $out . '</div>';
}
