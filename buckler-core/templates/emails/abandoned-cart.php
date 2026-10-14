<?php
/**
 * Correo de carrito abandonado (HTML compatible con Gmail, Outlook y Apple Mail).
 * Tablas + estilos en línea, 600 px, botón "a prueba de balas", modo oscuro tolerante.
 *
 * Variables: $vars (row, step, copy, coupon, low, recover, unsub, logo, whatsapp, company, city).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$row    = $vars['row'];
$copy   = $vars['copy'];
$coupon = $vars['coupon'];
$navy   = '#1a3955';
$lime   = '#e4ec6a';
$ink    = '#10202f';
$mute   = '#5b6773';
$font   = "Manrope, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
?>
<!doctype html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( $copy['title'] ); ?></title>
<style>
	@media (max-width: 620px) {
		.bk-w { width: 100% !important; }
		.bk-p { padding-left: 20px !important; padding-right: 20px !important; }
		.bk-h1 { font-size: 24px !important; }
		.bk-img { width: 72px !important; height: 108px !important; }
	}
	a { color: <?php echo esc_attr( $navy ); ?>; }
</style>
</head>
<body style="margin:0;padding:0;background:#eef1f4;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html( $copy['pre'] ); ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f4;">
<tr><td align="center" style="padding:24px 12px;">
	<table role="presentation" class="bk-w" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">
		<tr>
			<td style="background:<?php echo esc_attr( $navy ); ?>;padding:10px 32px;font:600 12px/1.4 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-align:center;" class="bk-p">
				Envíos gratis a todo el país · Paga con Addi en 3 cuotas con 0 % de interés
			</td>
		</tr>
		<tr>
			<td style="padding:28px 32px 8px;" class="bk-p">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( $vars['logo'] ); ?>" width="150" alt="Buckler" style="display:block;width:150px;height:auto;border:0;"></a>
			</td>
		</tr>
		<tr>
			<td style="padding:16px 32px 8px;" class="bk-p">
				<h1 class="bk-h1" style="margin:0 0 12px;font:800 28px/1.2 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $copy['title'] ); ?></h1>
				<p style="margin:0;font:400 16px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;"><?php echo esc_html( $copy['body'] ); ?></p>
			</td>
		</tr>

		<?php if ( $coupon ) : ?>
		<tr>
			<td style="padding:16px 32px 0;" class="bk-p">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $lime ); ?>;border-radius:10px;">
					<tr><td align="center" style="padding:16px;font:700 13px/1.4 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $navy ); ?>;">
						TU CÓDIGO<br><span style="font:800 26px/1.4 <?php echo esc_attr( $font ); ?>;letter-spacing:2px;"><?php echo esc_html( $coupon['code'] ); ?></span><br>
						<?php echo esc_html( sprintf( '%d %% de descuento · válido hasta el %s', $coupon['pct'], wp_date( 'j \d\e F', $coupon['expires'] ) ) ); ?>
					</td></tr>
				</table>
			</td>
		</tr>
		<?php endif; ?>

		<tr>
			<td style="padding:20px 32px 4px;" class="bk-p">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
				<?php foreach ( $row->items as $item ) : ?>
					<tr>
						<td width="96" valign="top" style="padding:12px 0;border-top:1px solid #e3e7ea;">
							<?php if ( ! empty( $item['image'] ) ) : ?>
								<img class="bk-img" src="<?php echo esc_url( $item['image'] ); ?>" width="84" height="126" alt="" style="display:block;width:84px;height:126px;object-fit:cover;border-radius:8px;border:0;">
							<?php endif; ?>
						</td>
						<td valign="top" style="padding:12px 0 12px 14px;border-top:1px solid #e3e7ea;font:400 14px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;">
							<strong style="display:block;font-weight:700;"><?php echo esc_html( $item['name'] ); ?></strong>
							<?php foreach ( (array) $item['variation'] as $v ) : ?>
								<span style="color:<?php echo esc_attr( $mute ); ?>;"><?php echo esc_html( ( false !== stripos( $v['label'], 'talla' ) ? 'Talla' : ucfirst( mb_strtolower( wp_strip_all_tags( $v['label'] ) ) ) ) . ': ' . $v['value'] ); ?></span><br>
							<?php endforeach; ?>
							<span style="color:<?php echo esc_attr( $mute ); ?>;"><?php echo esc_html( 'Cantidad: ' . (int) $item['quantity'] ); ?></span>
						</td>
						<td valign="top" align="right" style="padding:12px 0;border-top:1px solid #e3e7ea;font:800 14px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $navy ); ?>;white-space:nowrap;">
							<?php echo esc_html( bk_ac_price( (float) $item['line_total'] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
					<tr>
						<td colspan="2" style="padding:12px 0;border-top:2px solid <?php echo esc_attr( $ink ); ?>;font:700 15px/1.4 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;">Total</td>
						<td align="right" style="padding:12px 0;border-top:2px solid <?php echo esc_attr( $ink ); ?>;font:800 16px/1.4 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $navy ); ?>;"><?php echo esc_html( bk_ac_price( (float) $row->cart_total ) ); ?></td>
					</tr>
				</table>
			</td>
		</tr>

		<?php if ( $vars['low'] ) : ?>
		<tr>
			<td style="padding:4px 32px 0;" class="bk-p">
				<p style="margin:0;padding:10px 14px;border-radius:8px;background:#fff4dc;font:600 13px/1.5 <?php echo esc_attr( $font ); ?>;color:#6b4100;">
					<?php echo esc_html( 'Quedan pocas unidades de: ' . implode( ', ', $vars['low'] ) . '.' ); ?>
				</p>
			</td>
		</tr>
		<?php endif; ?>

		<tr>
			<td align="center" style="padding:24px 32px 8px;" class="bk-p">
				<table role="presentation" cellpadding="0" cellspacing="0"><tr>
					<td align="center" bgcolor="<?php echo esc_attr( $navy ); ?>" style="border-radius:99px;">
						<a href="<?php echo esc_url( $vars['recover'] ); ?>" style="display:inline-block;padding:16px 36px;font:800 16px/1 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-decoration:none;border-radius:99px;"><?php echo esc_html( $copy['cta'] ); ?> &rarr;</a>
					</td>
				</tr></table>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:8px 32px 28px;font:400 14px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;" class="bk-p">
				¿Tienes dudas? <a href="<?php echo esc_url( $vars['whatsapp'] ); ?>" style="color:<?php echo esc_attr( $navy ); ?>;font-weight:700;">Escríbenos por WhatsApp</a>
			</td>
		</tr>
		<tr>
			<td style="background:#f2f4f6;padding:20px 32px;font:400 12px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;" class="bk-p">
				Recibes este correo porque nos autorizaste a escribirte si tu compra en <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?> quedaba sin terminar.
				<a href="<?php echo esc_url( $vars['unsub'] ); ?>" style="color:<?php echo esc_attr( $mute ); ?>;">No quiero más recordatorios</a>.<br>
				<?php echo esc_html( $vars['company'] . ' · ' . $vars['city'] ); ?>
			</td>
		</tr>
	</table>
</td></tr>
</table>
</body>
</html>
