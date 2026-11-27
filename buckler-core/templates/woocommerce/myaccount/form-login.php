<?php
/**
 * Acceso a Mi cuenta: pestañas "Ingresar" y "Crear cuenta" (buckler-core, includes/accounts.php).
 * Usa los mismos campos, nonces y ganchos del formulario de WooCommerce (login / register).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo valida los nonces al procesar.
$bk_reg  = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
$bk_tab  = ( $bk_reg && ( isset( $_POST['register'] ) || isset( $_GET['crear'] ) ) ) ? 'register' : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bk_min  = function_exists( 'bk_acc_cfg' ) ? (int) bk_acc_cfg()['min'] : 8;
$bk_user = ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? wp_unslash( $_POST['username'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$bk_mail = ( ! empty( $_POST['email'] ) && is_string( $_POST['email'] ) ) ? wp_unslash( $_POST['email'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

do_action( 'woocommerce_before_customer_login_form' );
?>
<div class="bk-auth" data-bk-auth>
	<?php if ( $bk_reg ) : ?>
		<div class="bk-auth__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Acceso', 'buckler-core' ); ?>">
			<button type="button" role="tab" id="bk-tab-login" aria-controls="bk-pane-login" aria-selected="<?php echo 'login' === $bk_tab ? 'true' : 'false'; ?>" tabindex="<?php echo 'login' === $bk_tab ? '0' : '-1'; ?>"><?php esc_html_e( 'Ingresar', 'buckler-core' ); ?></button>
			<button type="button" role="tab" id="bk-tab-register" aria-controls="bk-pane-register" aria-selected="<?php echo 'register' === $bk_tab ? 'true' : 'false'; ?>" tabindex="<?php echo 'register' === $bk_tab ? '0' : '-1'; ?>"><?php esc_html_e( 'Crear cuenta', 'buckler-core' ); ?></button>
		</div>
	<?php endif; ?>

	<div class="bk-auth__pane" id="bk-pane-login" <?php echo $bk_reg ? 'role="tabpanel" aria-labelledby="bk-tab-login"' : ''; ?> <?php echo 'login' === $bk_tab ? '' : 'hidden'; ?>>
		<form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>
			<?php do_action( 'woocommerce_login_form_start' ); ?>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="username"><?php esc_html_e( 'Correo o usuario', 'buckler-core' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php echo esc_attr( $bk_tab === 'login' ? $bk_user : '' ); ?>" required aria-required="true" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="password"><?php esc_html_e( 'Contraseña', 'buckler-core' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required aria-required="true" />
			</p>
			<?php do_action( 'woocommerce_login_form' ); ?>
			<p class="form-row">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
					<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" /> <span><?php esc_html_e( 'Recordarme', 'buckler-core' ); ?></span>
				</label>
				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<button type="submit" class="woocommerce-button button woocommerce-form-login__submit" name="login" value="<?php esc_attr_e( 'Ingresar', 'buckler-core' ); ?>"><?php esc_html_e( 'Ingresar', 'buckler-core' ); ?></button>
			</p>
			<p class="woocommerce-LostPassword lost_password">
				<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( '¿Olvidaste tu contraseña?', 'buckler-core' ); ?></a>
			</p>
			<?php do_action( 'woocommerce_login_form_end' ); ?>
		</form>
		<?php if ( $bk_reg ) : ?>
			<p class="bk-auth__switch"><?php esc_html_e( '¿Aún no tienes cuenta?', 'buckler-core' ); ?> <button type="button" data-bk-auth-go="register"><?php esc_html_e( 'Créala aquí', 'buckler-core' ); ?></button></p>
		<?php endif; ?>
	</div>

	<?php if ( $bk_reg ) : ?>
		<div class="bk-auth__pane" id="bk-pane-register" role="tabpanel" aria-labelledby="bk-tab-register" <?php echo 'register' === $bk_tab ? '' : 'hidden'; ?>>
			<form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?> novalidate>
				<?php do_action( 'woocommerce_register_form_start' ); ?>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="reg_email"><?php esc_html_e( 'Correo', 'buckler-core' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo esc_attr( $bk_mail ); ?>" required aria-required="true" />
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="reg_password"><?php esc_html_e( 'Elige una contraseña', 'buckler-core' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" minlength="<?php echo esc_attr( $bk_min ); ?>" required aria-required="true" aria-describedby="bk-reg-hint" />
					<span class="bk-auth__hint" id="bk-reg-hint">
						<?php
						/* translators: %d: caracteres */
						echo esc_html( sprintf( __( 'Mínimo %d caracteres.', 'buckler-core' ), $bk_min ) );
						?>
					</span>
				</p>
				<?php do_action( 'woocommerce_register_form' ); ?>
				<p class="woocommerce-form-row form-row">
					<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
					<button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="register" value="<?php esc_attr_e( 'Crear cuenta', 'buckler-core' ); ?>"><?php esc_html_e( 'Crear mi cuenta', 'buckler-core' ); ?></button>
				</p>
				<p class="bk-auth__note"><?php esc_html_e( 'Si ya compraste con este correo, tus pedidos anteriores aparecerán en tu cuenta.', 'buckler-core' ); ?></p>
				<?php do_action( 'woocommerce_register_form_end' ); ?>
			</form>
			<p class="bk-auth__switch"><?php esc_html_e( '¿Ya tienes cuenta?', 'buckler-core' ); ?> <button type="button" data-bk-auth-go="login"><?php esc_html_e( 'Ingresa', 'buckler-core' ); ?></button></p>
		</div>
		<script>
		(() => {
			const root = document.querySelector('[data-bk-auth]'); if (!root) return;
			const tabs = [...root.querySelectorAll('[role="tab"]')];
			const go = (name, focus) => {
				tabs.forEach((t) => { const on = t.id === 'bk-tab-' + name; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; document.getElementById(t.getAttribute('aria-controls')).hidden = !on; if (on && focus) t.focus(); });
				const h = document.querySelector('.bk-login .bk-page-title'); if (h) h.textContent = name === 'register' ? 'Crea tu cuenta' : 'Ingresa a tu cuenta';
			};
			tabs.forEach((t, i) => {
				t.addEventListener('click', () => go(t.id.replace('bk-tab-', '')));
				t.addEventListener('keydown', (e) => { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); const n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; go(n.id.replace('bk-tab-', ''), true); } });
			});
			root.querySelectorAll('[data-bk-auth-go]').forEach((b) => b.addEventListener('click', () => go(b.dataset.bkAuthGo, true)));
			if (root.querySelector('#bk-tab-register[aria-selected="true"]')) go('register');
		})();
		</script>
	<?php endif; ?>
</div>
<?php
do_action( 'woocommerce_after_customer_login_form' );
