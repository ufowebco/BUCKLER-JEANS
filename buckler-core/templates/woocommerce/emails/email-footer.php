<?php
/**
 * Pie de los correos de WooCommerce con el diseño de Buckler (módulo `emails` de buckler-core):
 * ayuda por WhatsApp, enlaces útiles y datos de la empresa sobre fondo navy.
 *
 * @package BucklerCore
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

$email   = $email ?? null;
$bk_font = "Manrope, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$bk_text = get_option( 'woocommerce_email_footer_text' );
/** This filter is documented in WooCommerce templates/emails/email-footer.php */
$bk_text = apply_filters( 'woocommerce_email_footer_text', $bk_text, $email );
$bk_acc  = wc_get_page_permalink( 'myaccount' );
?>
																		</div>
																	</td>
																</tr>
															</table>
															<!-- End Content -->
														</td>
													</tr>
												</table>
												<!-- End Body -->
											</td>
										</tr>
										<tr>
											<td class="bk-mail__help" style="padding:0 32px 28px;">
												<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f7;border-radius:10px;">
													<tr><td style="padding:18px 20px;font:400 14px/1.5 <?php echo esc_attr( $bk_font ); ?>;color:#10202f;">
														<strong><?php esc_html_e( '¿Tienes alguna pregunta?', 'buckler-core' ); ?></strong><br>
														<?php esc_html_e( 'Escríbenos por WhatsApp y te ayudamos con tu pedido, tallas o cambios.', 'buckler-core' ); ?>
														<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;"><tr><td style="background:#e4ec6a;border-radius:99px;">
															<a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" style="display:inline-block;padding:10px 20px;font:700 14px/1 <?php echo esc_attr( $bk_font ); ?>;color:#1a3955;text-decoration:none;"><?php esc_html_e( 'Escribir por WhatsApp', 'buckler-core' ); ?></a>
														</td></tr></table>
													</td></tr>
												</table>
											</td>
										</tr>
										<tr>
											<td align="center" valign="top" id="template_footer" style="background:#1a3955;padding:24px 32px;">
												<p style="margin:0 0 8px;font:700 13px/1.5 <?php echo esc_attr( $bk_font ); ?>;color:#ffffff;">
													<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#e4ec6a;text-decoration:none;"><?php esc_html_e( 'Tienda', 'buckler-core' ); ?></a>
													&nbsp;·&nbsp; <a href="<?php echo esc_url( $bk_acc ); ?>" style="color:#e4ec6a;text-decoration:none;"><?php esc_html_e( 'Mi cuenta', 'buckler-core' ); ?></a>
													&nbsp;·&nbsp; <a href="<?php echo esc_url( home_url( (string) bk_config( 'faq_page' ) ) ); ?>" style="color:#e4ec6a;text-decoration:none;"><?php esc_html_e( 'Preguntas frecuentes', 'buckler-core' ); ?></a>
												</p>
												<p style="margin:0;font:400 12px/1.6 <?php echo esc_attr( $bk_font ); ?>;color:#c9d3dd;">
													<?php echo esc_html( bk_config( 'company' ) . ' · ' . bk_config( 'city' ) ); ?>
												</p>
												<?php if ( $bk_text ) : ?>
												<div id="credit" style="margin-top:8px;font:400 12px/1.6 <?php echo esc_attr( $bk_font ); ?>;color:#c9d3dd;"><?php echo wp_kses_post( wpautop( wptexturize( $bk_text ) ) ); ?></div>
												<?php endif; ?>
											</td>
										</tr>
									</table>
								</td>
							</tr>
						</table>
					</div>
				</td>
				<td><!-- Vacío a propósito: mismo ancho en todos los clientes de correo. --></td>
			</tr>
		</table>
	</body>
</html>
