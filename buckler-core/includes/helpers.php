<?php
/**
 * Utilidades compartidas.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convierte el valor de un campo de imagen ACF (array, ID o URL) en ID de adjunto.
 *
 * @param mixed $value Valor devuelto por ACF.
 * @return int ID del adjunto o 0.
 */
function bk_image_id( $value ) {
	if ( is_array( $value ) ) {
		return (int) ( $value['ID'] ?? $value['id'] ?? 0 );
	}
	if ( is_numeric( $value ) ) {
		return (int) $value;
	}
	if ( is_string( $value ) && '' !== $value ) {
		return (int) attachment_url_to_postid( $value );
	}
	return 0;
}

/**
 * Texto alternativo de un adjunto (campo "Texto alternativo" de la Mediateca).
 * No se usa el título del archivo como respaldo: suele ser un nombre técnico ("Banner-01-desktop")
 * que un lector de pantalla leería en voz alta. Los banners llevan texto dentro de la imagen:
 * su alt debe repetir ese texto.
 *
 * @param int    $id       ID del adjunto.
 * @param string $fallback Texto si el alt está vacío.
 * @return string
 */
function bk_image_alt( $id, $fallback = '' ) {
	$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	return '' === $alt ? $fallback : $alt;
}

/**
 * Lee un campo ACF de la página de opciones sin romper si ACF está desactivado.
 *
 * @param string $name Nombre del campo.
 * @return mixed
 */
function bk_option_field( $name ) {
	return function_exists( 'get_field' ) ? get_field( $name, 'option' ) : null;
}

/**
 * ¿Está activo un plugin por su carpeta? (sin cargar wp-admin/includes/plugin.php).
 *
 * @param string $folder Carpeta del plugin, p. ej. "divi-bodycommerce".
 * @return bool
 */
function bk_plugin_folder_active( $folder ) {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( $active as $plugin ) {
		if ( 0 === strpos( $plugin, $folder . '/' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * srcset con los tamaños ya generados que tienen la misma proporción que la imagen mostrada (p. ej. 400, 600, 768,
 * 800 px para fotos 2:3). Respaldo para cuando WordPress no lo genera (en producción algo lo quita en las fotos de
 * producto). La proporción se toma de la imagen que realmente se pinta (ancho/alto del <img>), nunca de un tamaño
 * con nombre: en fotos editadas algunos tamaños son recortes de Divi (16:9) y se verían ampliados.
 *
 * @param int   $id    Adjunto.
 * @param float $ratio Proporción ancho/alto de la imagen mostrada.
 * @param int   $max   Ancho máximo a incluir.
 * @return string
 */
function bk_ratio_srcset( $id, $ratio, $max = 1300 ) {
	$meta = wp_get_attachment_metadata( $id );
	if ( empty( $meta['sizes'] ) || $ratio <= 0 ) {
		return '';
	}
	$dir   = trailingslashit( dirname( (string) wp_get_attachment_url( $id ) ) );
	$set   = array();
	$sizes = $meta['sizes'];
	if ( ! empty( $meta['width'] ) && ! empty( $meta['file'] ) ) {
		$sizes['full'] = array( 'width' => $meta['width'], 'height' => $meta['height'], 'file' => wp_basename( $meta['file'] ) );
	}
	foreach ( $sizes as $s ) {
		if ( empty( $s['width'] ) || empty( $s['height'] ) || empty( $s['file'] ) || $s['width'] > $max || $s['width'] < 200 ) {
			continue;
		}
		if ( abs( $s['width'] / $s['height'] - $ratio ) < 0.02 ) {
			$set[ (int) $s['width'] ] = $dir . $s['file'] . ' ' . (int) $s['width'] . 'w';
		}
	}
	ksort( $set );
	return count( $set ) > 1 ? implode( ', ', $set ) : '';
}

/**
 * Pone a un <img> un srcset propio con la misma proporción que su ancho/alto, reemplazando el que traiga: en
 * producción Divi mete en el srcset sus recortes 16:9 (980×551, 480×270) y el navegador los elegía para fotos
 * verticales, que se veían ampliadas. Si no hay al menos dos tamaños válidos, se deja solo el src.
 *
 * @param string $html  <img>.
 * @param int    $id    Adjunto.
 * @param string $size  Tamaño base (sin uso; se mantiene por compatibilidad).
 * @param string $sizes Atributo sizes.
 * @return string
 */
function bk_img_add_srcset( $html, $id, $size, $sizes ) {
	unset( $size );
	if ( ! $html ) {
		return $html;
	}
	if ( ! preg_match( '/\swidth="(\d+)"/', $html, $w ) || ! preg_match( '/\sheight="(\d+)"/', $html, $h ) || ! (int) $h[1] ) {
		return $html;
	}
	$ratio = (int) $w[1] / (int) $h[1];
	$set   = bk_ratio_srcset( $id, $ratio );
	// Del srcset original se conservan solo los tamaños con la misma proporción (el nombre trae -ANCHOxALTO).
	$keep = array();
	if ( preg_match( '/\ssrcset="([^"]*)"/', $html, $orig ) ) {
		foreach ( explode( ',', $orig[1] ) as $cand ) {
			$cand = trim( $cand );
			if ( preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+\s+(\d+)w$/i', $cand, $d ) && (int) $d[2] && abs( (int) $d[1] / (int) $d[2] - $ratio ) < 0.02 ) {
				$keep[ (int) $d[3] ] = $cand;
			}
		}
	}
	foreach ( $set ? explode( ', ', $set ) : array() as $cand ) {
		if ( preg_match( '/\s(\d+)w$/', $cand, $d ) ) {
			$keep[ (int) $d[1] ] = $cand;
		}
	}
	ksort( $keep );
	$set  = count( $keep ) > 1 ? implode( ', ', $keep ) : '';
	$html = preg_replace( '/\ssrcset="[^"]*"/', '', $html );
	if ( ! $set ) {
		return $html;
	}
	$html = preg_replace( '/\ssizes="[^"]*"/', '', $html );
	return preg_replace( '/<img /', '<img srcset="' . esc_attr( $set ) . '" sizes="' . esc_attr( $sizes ) . '" ', $html, 1 );
}

/**
 * Difiere un <picture>/<img>: src y srcset pasan a data-bk-src / data-bk-srcset (los carga el JS cuando hace
 * falta), con <noscript> de respaldo. Evita que el navegador baje imágenes de carruseles que no se ven.
 *
 * @param string $html HTML con <img> y opcionalmente <source>.
 * @return string
 */
function bk_defer_media( $html ) {
	if ( ! $html || false !== strpos( $html, 'data-bk-src' ) ) {
		return $html;
	}
	$blank = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 9%22/%3E';
	$out   = preg_replace( array( '/(<(?:img|source)\b[^>]*?)\ssrcset="/i', '/(<img\b[^>]*?)\ssrc="/i', '/\sloading="lazy"/i' ), array( '$1 data-bk-srcset="', '$1 src="' . $blank . '" data-bk-src="', '' ), $html );
	return $out . '<noscript>' . $html . '</noscript>';
}
