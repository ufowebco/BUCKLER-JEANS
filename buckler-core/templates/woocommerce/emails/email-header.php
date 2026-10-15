<?php
/**
 * Encabezado de los correos de WooCommerce con el diseño de Buckler (módulo `emails` de buckler-core).
 * Misma línea gráfica que los correos de carrito abandonado y de guía de envío: barra navy, logo y título grande.
 * Conserva los contenedores de WooCommerce (#template_body, #body_content, #body_content_inner) para que el detalle
 * del pedido y los estilos de WooCommerce sigan funcionando igual.
 *
 * @package BucklerCore
 * @version 10.7.0
 */

defined( 'ABSPATH' ) || exit;

$bk_font  = "Manrope, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$bk_store = get_bloginfo( 'name', 'display' );
$bk_bar   = function_exists( 'bk_email_topline' ) ? bk_email_topline() : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<meta content="width=device-width, initial-scale=1.0" name="viewport">
		<meta name="color-scheme" content="light">
		<meta name="supported-color-schemes" content="light">
		<title><?php echo esc_html( $bk_store ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="margin:0;padding:0;background:#eef1f4;">
		<table width="100%" id="outer_wrapper" role="presentation" style="background:#eef1f4;">
			<tr>
				<td><!-- Vacío a propósito: mismo ancho en todos los clientes de correo. --></td>
				<td width="600">
					<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
						<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="inner_wrapper" role="presentation">
							<tr>
								<td align="center" valign="top">
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation" class="bk-mail">
										<?php if ( $bk_bar ) : ?>
										<tr>
											<td class="bk-mail__bar" style="background:#1a3955;padding:10px 32px;font:600 12px/1.4 <?php echo esc_attr( $bk_font ); ?>;color:#ffffff;text-align:center;"><?php echo esc_html( $bk_bar ); ?></td>
										</tr>
										<?php endif; ?>
										<tr>
											<td id="template_header_image" class="bk-mail__logo" style="padding:28px 32px 4px;">
												<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" style="display:inline-block;text-decoration:none;"><img src="<?php echo esc_url( BK_URL . 'assets/img/logo-email.png' ); ?>" width="150" alt="<?php echo esc_attr( bk_config( 'brand' ) ); ?>" style="display:block;width:150px;height:auto;border:0;"></a>
											</td>
										</tr>
										<tr>
											<td align="center" valign="top">
												<!-- Header -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation">
													<tr>
														<td id="header_wrapper">
															<h1><?php echo esc_html( $email_heading ); ?></h1>
														</td>
													</tr>
												</table>
												<!-- End Header -->
											</td>
										</tr>
										<tr>
											<td align="center" valign="top">
												<!-- Body -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation">
													<tr>
														<td valign="top" id="body_content">
															<!-- Content -->
															<table border="0" cellpadding="20" cellspacing="0" width="100%" role="presentation">
																<tr>
																	<td valign="top" id="body_content_inner_cell">
																		<div id="body_content_inner">
