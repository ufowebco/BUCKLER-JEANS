<?php
/**
 * Correos de WooCommerce con el diseño de Buckler (módulo `emails`): encabezado, pie y colores iguales a los de
 * carrito abandonado y guía de envío. Se apaga desde Ajustes → Buckler Core y vuelve el diseño de WooCommerce.
 *
 * - Plantillas propias SOLO para emails/email-header.php y emails/email-footer.php (el contenido de cada correo y el
 *   detalle del pedido siguen siendo los de WooCommerce y de los plugins, con sus textos y traducciones).
 * - Colores de WooCommerce (base, fondo, texto, enlaces) forzados a los de la marca + CSS extra con
 *   woocommerce_email_styles (WooCommerce lo pasa a estilos en línea).
 * - Si el sitio usa el editor de correos por bloques de WooCommerce, esos correos no usan estas plantillas.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

if ( bk_on( 'emails' ) ) {
	add_filter( 'woocommerce_locate_template', 'bk_email_templates', 20, 2 );
	add_filter( 'woocommerce_email_styles', 'bk_email_styles', 20 );
	foreach (
		array(
			'woocommerce_email_base_color'            => '#1a3955',
			'woocommerce_email_background_color'      => '#eef1f4',
			'woocommerce_email_body_background_color' => '#ffffff',
			'woocommerce_email_text_color'            => '#10202f',
			'woocommerce_email_footer_text_color'     => '#5b6773',
			'woocommerce_email_header_alignment'      => 'left',
		) as $bk_opt => $bk_val
	) {
		add_filter( 'pre_option_' . $bk_opt, static fn() => $bk_val );
	}
	unset( $bk_opt, $bk_val );
}

/**
 * Encabezado y pie propios.
 *
 * @param string $located       Ruta encontrada.
 * @param string $template_name Plantilla.
 * @return string
 */
function bk_email_templates( $located, $template_name ) {
	if ( in_array( $template_name, array( 'emails/email-header.php', 'emails/email-footer.php' ), true ) ) {
		return BK_DIR . 'templates/woocommerce/' . $template_name;
	}
	return $located;
}

/**
 * Línea superior (los dos primeros anuncios de la barra del sitio).
 *
 * @return string
 */
function bk_email_topline() {
	$a = array_slice( array_values( array_filter( (array) bk_config( 'announcements' ), static fn( $t ) => ! preg_match( '/descuento|sale|−|%/iu', $t ) ) ), 0, 2 );
	return implode( ' · ', $a );
}

/**
 * Estilos de marca (se suman al final de los de WooCommerce).
 *
 * @param string $css CSS.
 * @return string
 */
function bk_email_styles( $css ) {
	$font = "Manrope, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
	return $css . "
#wrapper { padding: 24px 12px !important; max-width: 600px; }
#template_container { background: #ffffff !important; border: 0 !important; border-radius: 14px !important; overflow: hidden; box-shadow: none !important; }
#template_header { background: #ffffff !important; color: #10202f !important; border-radius: 0 !important; }
#header_wrapper { padding: 12px 32px 0 !important; }
#header_wrapper h1, #template_header h1 { color: #10202f !important; font-family: {$font}; font-size: 26px !important; font-weight: 800 !important; line-height: 1.25 !important; text-align: left !important; text-shadow: none !important; margin: 0 !important; }
#body_content table td { padding: 16px 32px 28px; }
#body_content_inner { color: #10202f !important; font-family: {$font}; font-size: 15px !important; line-height: 1.6 !important; }
#body_content_inner h2, #body_content_inner h3 { color: #1a3955 !important; font-family: {$font}; font-weight: 800 !important; }
#body_content_inner a { color: #1a3955 !important; font-weight: 700; }
#template_footer a, #template_footer #credit a { color: #e4ec6a !important; text-decoration: none; }
#template_footer #credit { border: 0 !important; padding: 0 !important; color: #c9d3dd !important; text-align: center; }
#template_footer #credit p { margin: 0 !important; color: #c9d3dd !important; }
#body_content_inner .td, #body_content_inner th, #body_content_inner td { border-color: #e3e7ea !important; color: #10202f !important; font-family: {$font}; }
#body_content_inner .address { border: 1px solid #e3e7ea !important; border-radius: 10px; padding: 12px !important; color: #10202f !important; }
.button, a.button, #body_content_inner a.button { background: #1a3955 !important; color: #ffffff !important; border-radius: 99px !important; padding: 12px 22px !important; text-decoration: none !important; }
@media (max-width: 620px) { #header_wrapper { padding: 8px 20px 0 !important; } #body_content table td { padding: 12px 20px 24px; } #header_wrapper h1 { font-size: 22px !important; } }
";
}
