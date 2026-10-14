<?php
/**
 * Iconos de Iconify servidos localmente como SVG en línea (sin peticiones externas).
 *
 * Los iconos disponibles están en assets/icons.json, generado con tools/icons.py
 * desde los paquetes @iconify-json/*. Para añadir uno: agrégalo a tools/icons.txt y regenera.
 *
 * Shortcode: [bk_icon name="lucide:heart" size="24" label="Favoritos"]
 *  - label vacío = decorativo (aria-hidden). Con label = role="img" + <title>.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'bk_icon', 'bk_shortcode_icon' );

/**
 * Carga (una vez por petición) el mapa de iconos.
 *
 * @return array<string,array{body:string,width:int,height:int}>
 */
function bk_icons() {
	static $icons = null;
	if ( null === $icons ) {
		$file  = BK_DIR . 'assets/icons.json';
		$icons = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $icons;
}

/**
 * Devuelve el SVG de un icono.
 *
 * @param string $name  "set:icono", p. ej. "lucide:heart".
 * @param int    $size  Tamaño en px (ancho y alto).
 * @param string $label Texto accesible; vacío = decorativo.
 * @param string $class Clases extra.
 * @return string
 */
function bk_icon_svg( $name, $size = 24, $label = '', $class = '' ) {
	$icons = bk_icons();
	if ( empty( $icons[ $name ] ) ) {
		return '';
	}
	$icon  = $icons[ $name ];
	$a11y  = '' === $label
		? 'aria-hidden="true" focusable="false"'
		: 'role="img" aria-label="' . esc_attr( $label ) . '"';
	$title = '' === $label ? '' : '<title>' . esc_html( $label ) . '</title>';

	return sprintf(
		'<svg class="bk-icon %1$s" xmlns="http://www.w3.org/2000/svg" width="%2$d" height="%2$d" viewBox="0 0 %3$d %4$d" %5$s>%6$s%7$s</svg>',
		esc_attr( trim( 'bk-icon--' . sanitize_html_class( str_replace( ':', '-', $name ) ) . ' ' . $class ) ),
		(int) $size,
		(int) $icon['width'],
		(int) $icon['height'],
		$a11y,
		$title,
		$icon['body'] // Origen confiable: generado desde los paquetes oficiales de Iconify en el build.
	);
}

/**
 * Shortcode [bk_icon].
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shortcode_icon( $atts ) {
	$atts = shortcode_atts(
		array(
			'name'  => '',
			'size'  => 24,
			'label' => '',
			'class' => '',
		),
		$atts,
		'bk_icon'
	);
	return bk_icon_svg( sanitize_text_field( $atts['name'] ), absint( $atts['size'] ), sanitize_text_field( $atts['label'] ), sanitize_text_field( $atts['class'] ) );
}
