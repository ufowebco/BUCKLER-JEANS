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

/**
 * Checkout: el correo va de primero (lo primero que se pide es lo que permite recuperar la compra)
 * y la casilla de autorización de carritos abandonados justo debajo. Prioridad 20: después de otros
 * plugins/snippets que reordenan campos. address-i18n.js de Woo reordena por data-priority, así que se
 * mantiene también al cambiar de país. Desactivar: add_filter( 'bk_checkout_email_first', '__return_false' ).
 */
add_filter(
	'woocommerce_checkout_fields',
	static function ( $fields ) {
		if ( ! isset( $fields['billing']['billing_email'] ) || ! apply_filters( 'bk_checkout_email_first', true ) ) {
			return $fields;
		}
		$fields['billing']['billing_email']['priority'] = 1;
		$fields['billing']['billing_email']['class']    = array_values( array_diff( (array) ( $fields['billing']['billing_email']['class'] ?? array() ), array( 'form-row-first', 'form-row-last' ) ) );
		if ( ! in_array( 'form-row-wide', $fields['billing']['billing_email']['class'], true ) ) {
			$fields['billing']['billing_email']['class'][] = 'form-row-wide';
		}
		if ( isset( $fields['billing']['bk_ac_consent'] ) ) {
			$fields['billing']['bk_ac_consent']['priority'] = 2;
		}
		return $fields;
	},
	20
);

/**
 * Checkout: sin "Código postal" (decisión del cliente 2026-10-15; en Colombia el envío va con departamento + ciudad +
 * dirección). La ciudad se ocultó en 0.17.6 y volvió en 0.18.13: Addi la exige para aprobar el crédito y las
 * transportadoras la necesitan. Se quitan del formulario (facturación y envío) y del idioma de direcciones de
 * Colombia, para que el script de Woo no los vuelva a mostrar ni exigir al cambiar de país.
 * Volver a mostrarlos: add_filter( 'bk_checkout_hidden_fields', '__return_empty_array' ).
 */
function bk_checkout_hidden_fields() {
	return (array) apply_filters( 'bk_checkout_hidden_fields', array( 'postcode' ) );
}

add_filter(
	'woocommerce_checkout_fields',
	static function ( $fields ) {
		foreach ( bk_checkout_hidden_fields() as $f ) {
			unset( $fields['billing'][ 'billing_' . $f ], $fields['shipping'][ 'shipping_' . $f ] );
		}
		return $fields;
	},
	30
);

add_filter(
	'woocommerce_get_country_locale',
	static function ( $locale ) {
		foreach ( bk_checkout_hidden_fields() as $f ) {
			$locale['CO'][ $f ] = array_merge( (array) ( $locale['CO'][ $f ] ?? array() ), array( 'required' => false, 'hidden' => true ) );
		}
		// Ciudad visible y obligatoria, con el nombre que se usa en Colombia (Woo en español dice "Población").
		if ( ! in_array( 'city', bk_checkout_hidden_fields(), true ) ) {
			$locale['CO']['city'] = array_merge( (array) ( $locale['CO']['city'] ?? array() ), array( 'label' => __( 'Ciudad o municipio', 'buckler-core' ), 'required' => true, 'hidden' => false ) );
		}
		return $locale;
	},
	30
);
