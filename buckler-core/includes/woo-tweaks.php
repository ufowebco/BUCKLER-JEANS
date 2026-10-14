<?php
/**
 * Ajustes de textos de WooCommerce.
 *
 * SKU → REF (reemplaza el snippet "PALABRA - SKU POR REF").
 * Mejoras sobre el snippet original:
 *  - Usa `gettext_woocommerce` (filtro por dominio, WP 5.5+): no se ejecuta en los miles de textos de
 *    otros plugins, solo en los de WooCommerce.
 *  - Cubre todas las variantes ("SKU", "SKU:", "SKU %s", "Parent SKU", "Buscar por SKU"…), también las
 *    ya traducidas al español, y los plurales (`ngettext_woocommerce`).
 *  - No toca datos: el campo sigue siendo el SKU de WooCommerce (feeds de Meta/Google, schema y la
 *    API siguen usando "sku").
 *
 * Limitación conocida (igual que el snippet): los textos del editor de productos que WooCommerce pinta
 * con JavaScript (React) no pasan por gettext de PHP.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'gettext_woocommerce', 'bk_sku_to_ref', 10, 1 );
add_filter( 'ngettext_woocommerce', 'bk_sku_to_ref', 10, 1 );

/**
 * Cambia la palabra SKU por REF en un texto ya traducido.
 *
 * @param string $translation Texto traducido.
 * @return string
 */
function bk_sku_to_ref( $translation ) {
	if ( false === strpos( $translation, 'SKU' ) ) {
		return $translation; // Salida rápida: la gran mayoría de textos no contiene "SKU".
	}
	return preg_replace( '/\bSKU(s?)\b/', 'REF$1', $translation );
}
