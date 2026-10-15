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

if ( bk_on( 'sku_ref' ) ) {
	add_filter( 'gettext_woocommerce', 'bk_sku_to_ref', 10, 1 );
	add_filter( 'ngettext_woocommerce', 'bk_sku_to_ref', 10, 1 );
}

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

/**
 * Departamentos de Colombia: "San Andrés y Providencia" → "San Andrés" (no hay envíos a Providencia;
 * decisión del cliente 2026-10-15). Se cambia solo el nombre: el código CO-SAP de los pedidos no cambia.
 * Nombre configurable con el filtro bk_state_names.
 */
add_filter(
	'woocommerce_states',
	static function ( $states ) {
		$names = (array) apply_filters( 'bk_state_names', array( 'CO' => array( 'CO-SAP' => 'San Andrés' ) ) );
		foreach ( $names as $country => $map ) {
			foreach ( (array) $map as $code => $name ) {
				if ( isset( $states[ $country ][ $code ] ) ) {
					$states[ $country ][ $code ] = $name;
				}
			}
		}
		return $states;
	},
	99
);
