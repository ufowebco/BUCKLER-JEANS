<?php
/**
 * Correo "Tu cuenta está lista": enlace para crear la contraseña (72 h, un solo uso). Misma línea que el recordatorio
 * de pago: tablas + estilos en línea, 600 px, botón a prueba de balas.
 *
 * Variables: $vars (copy{subject,pre,title,body,cta}, url, email, hours, logo, whatsapp, company, city).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$navy = '#1a3955';
$lime = '#e4ec6a';
$ink  = '#10202f';
$mute = '#5b6773';
$font = "Manrope, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$copy = $vars['copy'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( $copy['subject'] ); ?></title>
<style>
	@media (max-width: 620px) {
		.bk-w { width: 100% !important; }
		.bk-p { padding-left: 20px !important; padding-right: 20px !important; }
		.bk-h1 { font-size: 24px !important; }
	}
</style>
</head>
<body style="margin:0;padding:0;background:#eef1f4;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html( $copy['pre'] ); ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f4;">
<tr><td align="center" style="padding:24px 12px;">
	<table role="presentation" class="bk-w" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">
		<tr>
			<td style="background:<?php echo esc_attr( $navy ); ?>;padding:10px 32px;font:600 12px/1.4 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-align:center;" class="bk-p">
				Tu cuenta en Buckler Jeans · Envío gratis a toda Colombia
			</td>
		</tr>
		<tr>
			<td style="padding:28px 32px 8px;" class="bk-p">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( $vars['logo'] ); ?>" width="150" alt="Buckler" style="display:block;width:150px;height:auto;border:0;"></a>
			</td>
		</tr>
		<tr>
			<td style="padding:16px 32px 4px;" class="bk-p">
				<h1 class="bk-h1" style="margin:0 0 12px;font:800 28px/1.2 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $copy['title'] ); ?></h1>
				<p style="margin:0;font:400 16px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;"><?php echo wp_kses( $copy['body'], array( 'strong' => array() ) ); ?></p>
			</td>
		</tr>
		<tr>
			<td style="padding:20px 32px 0;" class="bk-p">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f6;border-radius:12px;">
					<tr><td style="padding:14px 16px;font:400 14px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;">
						<span style="color:<?php echo esc_attr( $mute ); ?>;">Tu usuario es tu correo:</span><br>
						<strong style="font-size:16px;"><?php echo esc_html( $vars['email'] ); ?></strong>
					</td></tr>
				</table>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:24px 32px 6px;" class="bk-p">
				<table role="presentation" cellpadding="0" cellspacing="0"><tr>
					<td align="center" bgcolor="<?php echo esc_attr( $navy ); ?>" style="border-radius:99px;">
						<a href="<?php echo esc_url( $vars['url'] ); ?>" style="display:inline-block;padding:16px 36px;font:800 16px/1 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-decoration:none;border-radius:99px;"><?php echo esc_html( $copy['cta'] ); ?> &rarr;</a>
					</td>
				</tr></table>
				<p style="margin:10px 0 0;font:400 13px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;">El enlace sirve una sola vez y vence en <?php echo (int) $vars['hours']; ?> horas. Por tu seguridad, nunca te pediremos tu contraseña.</p>
			</td>
		</tr>
		<tr>
			<td style="padding:24px 32px 4px;" class="bk-p">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $lime ); ?>;border-radius:12px;">
					<tr><td style="padding:16px 18px;font:400 14px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $navy ); ?>;">
						<strong style="display:block;font-size:15px;margin-bottom:4px;">En tu cuenta puedes</strong>
						✓ Ver tus pedidos y su estado<br>
						✓ Seguir tu envío con el número de guía<br>
						✓ Guardar tus datos para comprar más rápido
					</td></tr>
				</table>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:24px 32px 28px;font:400 14px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;" class="bk-p">
				¿El botón no funciona o el enlace venció? <a href="<?php echo esc_url( $vars['whatsapp'] ); ?>" style="color:<?php echo esc_attr( $navy ); ?>;font-weight:700;">Escríbenos por WhatsApp</a> y te ayudamos.
			</td>
		</tr>
		<tr>
			<td style="background:#f2f4f6;padding:20px 32px;font:400 12px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;" class="bk-p">
				Recibes este correo porque compraste en <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>. Si no quieres usar tu cuenta, ignora este mensaje: no pasa nada.<br>
				<?php echo esc_html( $vars['company'] . ' · ' . $vars['city'] ); ?>
			</td>
		</tr>
	</table>
</td></tr>
</table>
</body>
</html>
