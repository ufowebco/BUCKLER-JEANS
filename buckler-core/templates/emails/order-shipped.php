<?php
/**
 * Correo "Tu pedido ha sido enviado" (HTML para Gmail, Outlook y Apple Mail).
 * Tablas + estilos en línea, 600 px, botón a prueba de balas.
 *
 * Variables: $vars (name, number, carrier, guide, track, has_link, items, address, account, logo,
 * whatsapp, company, city).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$navy = '#1a3955';
$lime = '#e4ec6a';
$ink  = '#10202f';
$mute = '#5b6773';
$font = "Manrope, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$hi   = $vars['name'] ? $vars['name'] . ', tu' : 'Tu';
$local = ! empty( $vars['local'] ); // Entrega local: sin número de guía ni rastreo.
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( 'Tu pedido #' . $vars['number'] . ' ha sido enviado' ); ?></title>
<style>
	@media (max-width: 620px) {
		.bk-w { width: 100% !important; }
		.bk-p { padding-left: 20px !important; padding-right: 20px !important; }
		.bk-h1 { font-size: 24px !important; }
		.bk-guide { font-size: 22px !important; }
	}
</style>
</head>
<body style="margin:0;padding:0;background:#eef1f4;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html( $local ? 'Tu pedido llega hoy a tu dirección con nuestro domiciliario. ¡Quédate pendiente!' : 'Va con ' . $vars['carrier'] . ', guía ' . $vars['guide'] . '. Aquí tienes cómo rastrearlo.' ); ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f4;">
<tr><td align="center" style="padding:24px 12px;">
	<table role="presentation" class="bk-w" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">
		<tr>
			<td style="background:<?php echo esc_attr( $navy ); ?>;padding:10px 32px;font:600 12px/1.4 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-align:center;" class="bk-p">
				Pedido #<?php echo esc_html( $vars['number'] ); ?> · Envíos a toda Colombia
			</td>
		</tr>
		<tr>
			<td style="padding:28px 32px 8px;" class="bk-p">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( $vars['logo'] ); ?>" width="150" alt="Buckler" style="display:block;width:150px;height:auto;border:0;"></a>
			</td>
		</tr>
		<tr>
			<td style="padding:16px 32px 4px;" class="bk-p">
				<h1 class="bk-h1" style="margin:0 0 12px;font:800 28px/1.2 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $hi . ( $local ? ' pedido llega hoy' : ' pedido ya va en camino' ) ); ?></h1>
				<?php if ( $local ) : ?>
				<p style="margin:0 0 12px;font:400 16px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;">
					¡Qué alegría contarte que tu pedido #<?php echo esc_html( $vars['number'] ); ?> ya salió hacia ti! Lo entregamos <strong style="color:<?php echo esc_attr( $ink ); ?>;">hoy mismo</strong> con nuestro servicio de domicilios en Medellín y el área metropolitana.
				</p>
				<p style="margin:0;font:400 16px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;">
					Como es una entrega local directa, no tiene número de guía: el domiciliario llevará tu paquete hasta tu puerta en el transcurso del día.
				</p>
				<?php else : ?>
				<p style="margin:0;font:400 16px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;">
					Entregamos tu pedido #<?php echo esc_html( $vars['number'] ); ?> a <strong style="color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $vars['carrier'] ); ?></strong>. Con este número de guía puedes seguirlo hasta tu puerta.
				</p>
				<?php endif; ?>
			</td>
		</tr>

		<?php if ( $local ) : ?>
		<tr>
			<td style="padding:20px 32px 0;" class="bk-p">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $lime ); ?>;border-radius:12px;">
					<tr><td align="center" style="padding:20px 18px;font:700 13px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $navy ); ?>;">
						ENTREGA LOCAL · HOY<br>
						<span class="bk-guide" style="font:800 22px/1.35 <?php echo esc_attr( $font ); ?>;">Quédate muy pendiente para recibirlo</span><br>
						<span style="font:400 14px/1.6 <?php echo esc_attr( $font ); ?>;">El domiciliario puede llegar en cualquier momento del día. Si no vas a estar, deja a alguien encargado o avísanos.</span>
					</td></tr>
				</table>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:22px 32px 6px;" class="bk-p">
				<table role="presentation" cellpadding="0" cellspacing="0"><tr>
					<td align="center" bgcolor="<?php echo esc_attr( $navy ); ?>" style="border-radius:99px;">
						<a href="<?php echo esc_url( $vars['whatsapp'] ); ?>" style="display:inline-block;padding:16px 36px;font:800 16px/1 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-decoration:none;border-radius:99px;">¿No vas a estar? Escríbenos &rarr;</a>
					</td>
				</tr></table>
			</td>
		</tr>
		<?php else : ?>
		<tr>
			<td style="padding:20px 32px 0;" class="bk-p">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $lime ); ?>;border-radius:12px;">
					<tr><td align="center" style="padding:20px 16px;font:700 13px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $navy ); ?>;">
						<?php echo esc_html( strtoupper( $vars['carrier'] ) ); ?> · NÚMERO DE GUÍA<br>
						<span class="bk-guide" style="font:800 28px/1.4 <?php echo esc_attr( $font ); ?>;letter-spacing:2px;"><?php echo esc_html( $vars['guide'] ); ?></span>
					</td></tr>
				</table>
			</td>
		</tr>

		<tr>
			<td align="center" style="padding:22px 32px 6px;" class="bk-p">
				<table role="presentation" cellpadding="0" cellspacing="0"><tr>
					<td align="center" bgcolor="<?php echo esc_attr( $navy ); ?>" style="border-radius:99px;">
						<a href="<?php echo esc_url( $vars['track'] ); ?>" style="display:inline-block;padding:16px 36px;font:800 16px/1 <?php echo esc_attr( $font ); ?>;color:#ffffff;text-decoration:none;border-radius:99px;">Rastrear mi envío &rarr;</a>
					</td>
				</tr></table>
				<?php if ( ! $vars['has_link'] ) : ?>
					<p style="margin:10px 0 0;font:400 13px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;">En la página de <?php echo esc_html( $vars['carrier'] ); ?> busca la opción de rastreo y escribe tu número de guía.</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php endif; ?>

		<tr>
			<td style="padding:24px 32px 4px;" class="bk-p">
				<p style="margin:0 0 8px;font:800 13px/1.4 <?php echo esc_attr( $font ); ?>;letter-spacing:1px;color:<?php echo esc_attr( $mute ); ?>;">LO QUE VA EN TU PAQUETE</p>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
				<?php foreach ( $vars['items'] as $item ) : ?>
					<tr>
						<td width="76" valign="top" style="padding:10px 0;border-top:1px solid #e3e7ea;">
							<?php if ( $item['image'] ) : ?>
								<img src="<?php echo esc_url( $item['image'] ); ?>" width="64" height="96" alt="" style="display:block;width:64px;height:96px;object-fit:cover;border-radius:8px;border:0;">
							<?php endif; ?>
						</td>
						<td valign="top" style="padding:10px 0 10px 12px;border-top:1px solid #e3e7ea;font:400 14px/1.5 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;">
							<strong style="display:block;"><?php echo esc_html( $item['name'] ); ?></strong>
							<span style="color:<?php echo esc_attr( $mute ); ?>;"><?php echo esc_html( trim( $item['meta'] . ' · Cantidad: ' . $item['qty'], ' ·' ) ); ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</table>
			</td>
		</tr>

		<?php if ( $vars['address'] ) : ?>
		<tr>
			<td style="padding:16px 32px 4px;" class="bk-p">
				<p style="margin:0 0 6px;font:800 13px/1.4 <?php echo esc_attr( $font ); ?>;letter-spacing:1px;color:<?php echo esc_attr( $mute ); ?>;">DIRECCIÓN DE ENTREGA</p>
				<p style="margin:0;font:400 14px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $ink ); ?>;"><?php echo nl2br( esc_html( $vars['address'] ) ); // phpcs:ignore ?></p>
			</td>
		</tr>
		<?php endif; ?>

		<tr>
			<td align="center" style="padding:24px 32px 28px;font:400 14px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;" class="bk-p">
				<?php if ( $local ) : ?><strong style="color:<?php echo esc_attr( $ink ); ?>;">¡Muchas gracias por tu compra!</strong> Esperamos que disfrutes mucho tus prendas.<br><?php endif; ?>
				¿Alguna duda con tu envío? <a href="<?php echo esc_url( $vars['whatsapp'] ); ?>" style="color:<?php echo esc_attr( $navy ); ?>;font-weight:700;">Escríbenos por WhatsApp</a>
				<?php if ( $vars['account'] ) : ?><br><a href="<?php echo esc_url( $vars['account'] ); ?>" style="color:<?php echo esc_attr( $navy ); ?>;">Ver mi pedido</a><?php endif; ?>
			</td>
		</tr>
		<tr>
			<td style="background:#f2f4f6;padding:20px 32px;font:400 12px/1.6 <?php echo esc_attr( $font ); ?>;color:<?php echo esc_attr( $mute ); ?>;" class="bk-p">
				Recibes este correo porque hiciste un pedido en <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>.<br>
				<?php echo esc_html( $vars['company'] . ' · ' . $vars['city'] ); ?>
			</td>
		</tr>
	</table>
</td></tr>
</table>
</body>
</html>
