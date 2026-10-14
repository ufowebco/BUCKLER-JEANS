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
