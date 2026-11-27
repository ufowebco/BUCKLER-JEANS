<?php
/**
 * Cuentas de clientas (pedido del cliente 2026-11-27).
 *
 * 1. Registro después de pagar: en "¡Gracias por tu compra!" se ofrece crear la cuenta solo con una contraseña (los
 *    datos salen del pedido). El pedido y los anteriores con el mismo correo quedan en su cuenta.
 * 2. "Crear cuenta" en Mi cuenta (formulario propio con pestañas Ingresar / Crear cuenta).
 * 3. Enlace para crear la contraseña (un solo uso, vence a las 72 h) en /crear-contrasena/. Lo usa la gestión para
 *    dar acceso a clientas antiguas (includes/gestion/customer-access.php).
 * 4. Al iniciar sesión o registrarse se le asignan los pedidos hechos como invitada con el mismo correo.
 *
 * Nunca se envían contraseñas por correo: la clienta siempre escribe la suya.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes (filtro bk_accounts_config).
 *
 * @return array{on:bool,hours:int,slug:string,min:int}
 */
function bk_acc_cfg() {
	return (array) apply_filters(
		'bk_accounts_config',
		array(
			'on'    => true,
			'hours' => 72,                 // Vigencia del enlace para crear la contraseña.
			'slug'  => 'crear-contrasena', // Página del enlace.
			'min'   => 8,                  // Largo mínimo de la contraseña.
		)
	);
}

if ( ! bk_acc_cfg()['on'] ) {
	return;
}

/* ---------------------------------------------------------------------------
 * Registro en Mi cuenta: la clienta elige su contraseña; el usuario se arma del correo.
 * ------------------------------------------------------------------------- */

add_filter( 'pre_option_woocommerce_enable_myaccount_registration', static fn() => 'yes' );
add_filter( 'pre_option_woocommerce_registration_generate_password', static fn() => 'no' );
add_filter( 'pre_option_woocommerce_registration_generate_username', static fn() => 'yes' );

// Formulario propio de acceso (pestañas Ingresar / Crear cuenta).
add_filter(
	'wc_get_template',
	static function ( $located, $name ) {
		return 'myaccount/form-login.php' === $name ? BK_DIR . 'templates/woocommerce/myaccount/form-login.php' : $located;
	},
	20,
	2
);

// Contraseña mínima también en el registro de Mi cuenta.
add_filter(
	'woocommerce_process_registration_errors',
	static function ( $errors, $username, $password ) {
		if ( is_string( $password ) && '' !== $password && strlen( $password ) < bk_acc_cfg()['min'] ) {
			/* translators: %d: caracteres */
			$errors->add( 'bk_short', sprintf( __( 'La contraseña debe tener al menos %d caracteres.', 'buckler-core' ), bk_acc_cfg()['min'] ) );
		}
		return $errors;
	},
	10,
	3
);

/* ---------------------------------------------------------------------------
 * Pedidos de invitada → cuenta (mismo correo)
 * ------------------------------------------------------------------------- */

/**
 * Asigna a la cuenta los pedidos hechos como invitada con su correo (función nativa de WooCommerce).
 *
 * @param int $user_id Usuario.
 * @return int Pedidos asignados.
 */
function bk_acc_link_orders( $user_id ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u || ! function_exists( 'wc_update_new_customer_past_orders' ) || user_can( $u, 'edit_posts' ) || user_can( $u, 'bk_gestion' ) ) {
		return 0; // Nunca a cuentas del equipo.
	}
	return (int) wc_update_new_customer_past_orders( $u->ID );
}

add_action( 'woocommerce_created_customer', 'bk_acc_link_orders', 20 );
add_action(
	'wp_login',
	static function ( $login, $user ) {
		if ( $user instanceof WP_User && in_array( 'customer', (array) $user->roles, true ) ) {
			bk_acc_link_orders( $user->ID );
			update_user_meta( $user->ID, '_bk_acc_active', time() );
		}
	},
	20,
	2
);

/* ---------------------------------------------------------------------------
 * Crear la cuenta desde la página de gracias
 * ------------------------------------------------------------------------- */

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'bk/v1',
			'/account/signup',
			array(
				'methods'             => 'POST',
				'callback'            => 'bk_acc_signup',
				'permission_callback' => '__return_true', // La clave del pedido es la prueba (solo la tiene quien compró).
			)
		);
	}
);

/**
 * Pedido de la página de gracias, si se puede ofrecer la cuenta.
 *
 * @param int    $id  Pedido.
 * @param string $key Clave del pedido (wc_order_…).
 * @return WC_Order|null
 */
function bk_acc_thanks_order( $id, $key ) {
	$o = wc_get_order( (int) $id );
	if ( ! $o || 'shop_order' !== $o->get_type() || ! $key || ! hash_equals( (string) $o->get_order_key(), (string) $key ) ) {
		return null;
	}
	$created = $o->get_date_created();
	if ( $created && $created->getTimestamp() < time() - 7 * DAY_IN_SECONDS ) {
		return null; // Solo pedidos recientes.
	}
	return $o;
}

/**
 * Límite de intentos por IP (10 por hora).
 *
 * @return bool true si puede seguir.
 */
function bk_acc_rate_ok( $fail = false ) {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'bk_acc_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $fail ) { // Solo cuentan los intentos fallidos (clave equivocada, correo ya registrado…).
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	}
	return $n < 10;
}

/**
 * Crea la cuenta con los datos del pedido y la contraseña elegida; deja la sesión iniciada.
 *
 * @param WP_REST_Request $r order, key, password.
 * @return WP_REST_Response|WP_Error
 */
function bk_acc_signup( WP_REST_Request $r ) {
	if ( ! bk_acc_rate_ok() ) {
		return new WP_Error( 'bk_rate', 'Hiciste muchos intentos. Espera un rato y vuelve a intentarlo.', array( 'status' => 429 ) );
	}
	$o = bk_acc_thanks_order( (int) $r['order'], (string) $r['key'] );
	if ( ! $o ) {
		bk_acc_rate_ok( true );
		return new WP_Error( 'bk_order', 'No pudimos verificar tu pedido. Crea tu cuenta desde Mi cuenta.', array( 'status' => 403 ) );
	}
	if ( $o->get_customer_id() || is_user_logged_in() ) {
		return new WP_Error( 'bk_has', 'Este pedido ya está en una cuenta.', array( 'status' => 409 ) );
	}
	$email = strtolower( trim( (string) $o->get_billing_email() ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'bk_email', 'El pedido no tiene un correo válido.', array( 'status' => 400 ) );
	}
	if ( email_exists( $email ) ) {
		bk_acc_rate_ok( true );
		return new WP_Error( 'bk_exists', 'Ya tienes una cuenta con este correo. Inicia sesión para ver tus pedidos.', array( 'status' => 409, 'field' => 'exists' ) );
	}
	$pass = (string) $r['password'];
	if ( strlen( $pass ) < bk_acc_cfg()['min'] ) {
		/* translators: %d: caracteres */
		return new WP_Error( 'bk_short', sprintf( 'La contraseña debe tener al menos %d caracteres.', bk_acc_cfg()['min'] ), array( 'status' => 400, 'field' => 'password' ) );
	}
	$id = bk_acc_create_user( $o, $pass );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_user_meta( $id, '_bk_acc_active', time() );
	update_user_meta( $id, '_bk_acc_source', 'gracias' );
	wp_set_current_user( $id );
	wc_set_customer_auth_cookie( $id );
	$o->add_order_note( 'La clienta creó su cuenta desde la página de gracias.', false, false );
	return rest_ensure_response(
		array(
			'ok'      => true,
			'account' => wc_get_page_permalink( 'myaccount' ),
			'name'    => (string) $o->get_billing_first_name(),
		)
	);
}

/**
 * Crea la cuenta de cliente con los datos del pedido (sin el correo de "cuenta nueva" de Woo: la gestión manda el suyo
 * y en la página de gracias la clienta ya está viendo la confirmación).
 *
 * @param WC_Order $o    Pedido.
 * @param string   $pass Contraseña ('' = una al azar que nadie conoce; la clienta crea la suya con el enlace).
 * @return int|WP_Error
 */
function bk_acc_create_user( $o, $pass = '' ) {
	$email = strtolower( trim( (string) $o->get_billing_email() ) );
	$first = (string) $o->get_billing_first_name();
	$last  = (string) $o->get_billing_last_name();
	$login = function_exists( 'wc_create_new_customer_username' ) ? wc_create_new_customer_username( $email, array( 'first_name' => $first, 'last_name' => $last ) ) : sanitize_user( current( explode( '@', $email ) ), true );
	$id    = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => '' !== $pass ? $pass : wp_generate_password( 32, true, true ),
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $login,
			'role'         => 'customer',
		)
	);
	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'bk_user', 'No pudimos crear la cuenta. Intenta de nuevo o escríbenos por WhatsApp.', array( 'status' => 500 ) );
	}
	// Direcciones del pedido para que la próxima compra salga rellena.
	$c = new WC_Customer( $id );
	foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ) as $f ) {
		$get = 'get_billing_' . $f;
		$set = 'set_billing_' . $f;
		if ( is_callable( array( $o, $get ) ) && is_callable( array( $c, $set ) ) ) {
			$c->$set( $o->$get() );
		}
	}
	foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $f ) {
		$get = 'get_shipping_' . $f;
		$set = 'set_shipping_' . $f;
		$val = is_callable( array( $o, $get ) ) ? $o->$get() : '';
		$c->$set( '' !== (string) $val ? $val : ( is_callable( array( $o, 'get_billing_' . $f ) ) ? $o->{'get_billing_' . $f}() : '' ) );
	}
	$c->save();
	foreach ( (array) ( function_exists( 'bk_g_fields' ) ? bk_g_fields()['cedula'] : array( '_billing_id' ) ) as $k ) { // Cédula.
		$v = (string) $o->get_meta( $k );
		if ( '' !== $v ) {
			update_user_meta( $id, ltrim( $k, '_' ), $v );
			break;
		}
	}
	bk_acc_link_orders( $id );
	do_action( 'bk_account_created', $id, $o );
	return (int) $id;
}

/**
 * Recuadro + ventana "Crea tu cuenta" en la página de gracias.
 *
 * @return string
 */
function bk_acc_thanks_html() {
	if ( is_user_logged_in() ) {
		return '';
	}
	global $wp;
	$id  = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
	$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$o   = bk_acc_thanks_order( $id, $key );
	if ( ! $o || $o->get_customer_id() || ! is_email( $o->get_billing_email() ) || $o->has_status( array( 'failed', 'cancelled' ) ) ) {
		return '';
	}
	$name    = trim( (string) $o->get_billing_first_name() );
	$email   = (string) $o->get_billing_email();
	$account = wc_get_page_permalink( 'myaccount' );
	if ( email_exists( strtolower( $email ) ) ) {
		return '<aside class="bk-acc-card is-exists"><span class="bk-acc-card__icon">' . bk_icon_svg( 'lucide:user', 26 ) . '</span><div><p class="bk-acc-card__title">' . esc_html__( 'Ya tienes una cuenta con este correo', 'buckler-core' ) . '</p><p>' . esc_html__( 'Inicia sesión y verás este pedido junto con los anteriores.', 'buckler-core' ) . '</p></div><a class="bk-btn" href="' . esc_url( $account ) . '">' . esc_html__( 'Iniciar sesión', 'buckler-core' ) . '</a></aside>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	$min = bk_acc_cfg()['min'];
	ob_start();
	?>
	<aside class="bk-acc-card" data-bk-acc>
		<span class="bk-acc-card__icon"><?php echo bk_icon_svg( 'lucide:user', 26 ); // phpcs:ignore ?></span>
		<div>
			<p class="bk-acc-card__title"><?php esc_html_e( '¿Quieres crear tu cuenta?', 'buckler-core' ); ?></p>
			<p><?php esc_html_e( 'Sigue tu envío, mira tus pedidos y compra más rápido la próxima vez. Ya tenemos tus datos: solo elige una contraseña.', 'buckler-core' ); ?></p>
		</div>
		<button type="button" class="bk-btn" data-bk-acc-open><?php esc_html_e( 'Crear mi cuenta', 'buckler-core' ); ?></button>
	</aside>
	<dialog class="bk-acc-pop" id="bk-acc-pop" aria-labelledby="bk-acc-pop-title" data-order="<?php echo esc_attr( $o->get_id() ); ?>" data-key="<?php echo esc_attr( $key ); ?>" data-api="<?php echo esc_url( rest_url( 'bk/v1/account/signup' ) ); ?>">
		<button type="button" class="bk-acc-pop__x" data-bk-acc-close aria-label="<?php esc_attr_e( 'Cerrar', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:x', 20 ); // phpcs:ignore ?></button>
		<div class="bk-acc-pop__body" data-step="form">
			<span class="bk-acc-pop__badge"><?php echo bk_icon_svg( 'lucide:check', 18 ); // phpcs:ignore ?><?php esc_html_e( 'Pedido recibido', 'buckler-core' ); ?></span>
			<h2 id="bk-acc-pop-title">
				<?php
				/* translators: %s: nombre */
				echo esc_html( $name ? sprintf( __( '%s, crea tu cuenta en un paso', 'buckler-core' ), $name ) : __( 'Crea tu cuenta en un paso', 'buckler-core' ) );
				?>
			</h2>
			<p><?php esc_html_e( 'Así podrás seguir tu envío y ver tus pedidos cuando quieras.', 'buckler-core' ); ?></p>
			<form class="bk-acc-form" novalidate data-bk-allow-resubmit>
				<p class="bk-acc-form__email"><?php echo bk_icon_svg( 'lucide:mail', 16 ); // phpcs:ignore ?><span><?php esc_html_e( 'Tu correo:', 'buckler-core' ); ?> <b><?php echo esc_html( $email ); ?></b></span></p>
				<label for="bk-acc-pass"><?php esc_html_e( 'Elige una contraseña', 'buckler-core' ); ?></label>
				<div class="bk-acc-pass">
					<input type="password" id="bk-acc-pass" name="password" autocomplete="new-password" minlength="<?php echo esc_attr( $min ); ?>" required aria-describedby="bk-acc-hint bk-acc-err">
					<button type="button" class="bk-acc-eye" data-bk-acc-eye aria-pressed="false" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:eye', 18 ); // phpcs:ignore ?></button>
				</div>
				<p class="bk-acc-hint" id="bk-acc-hint">
					<?php
					/* translators: %d: caracteres */
					echo esc_html( sprintf( __( 'Mínimo %d caracteres.', 'buckler-core' ), $min ) );
					?>
				</p>
				<p class="bk-acc-err" id="bk-acc-err" role="alert" hidden></p>
				<button type="submit" class="bk-btn bk-acc-submit" data-bk-multi><?php esc_html_e( 'Crear mi cuenta', 'buckler-core' ); ?></button>
				<button type="button" class="bk-acc-later" data-bk-acc-close><?php esc_html_e( 'Ahora no', 'buckler-core' ); ?></button>
			</form>
		</div>
		<div class="bk-acc-pop__body" data-step="done" hidden>
			<span class="bk-acc-pop__ok"><?php echo bk_icon_svg( 'lucide:check', 34 ); // phpcs:ignore ?></span>
			<h2><?php esc_html_e( '¡Listo, ya tienes cuenta!', 'buckler-core' ); ?></h2>
			<p><?php esc_html_e( 'Este pedido ya aparece en tu cuenta. Desde ahí puedes seguir tu envío.', 'buckler-core' ); ?></p>
			<a class="bk-btn" href="<?php echo esc_url( $account ); ?>"><?php esc_html_e( 'Ver mis pedidos', 'buckler-core' ); ?></a>
		</div>
	</dialog>
	<script>
	(() => {
		const d = document.getElementById('bk-acc-pop'); const card = document.querySelector('[data-bk-acc]');
		if (!d || !d.showModal) return;
		const k = 'bk_acc_pop_' + d.dataset.order; let seen = false;
		try { seen = sessionStorage.getItem(k) === '1'; } catch (e) {}
		const open = () => { if (!d.open) d.showModal(); try { sessionStorage.setItem(k, '1'); } catch (e) {} setTimeout(() => d.querySelector('input').focus(), 60); };
		if (!seen) setTimeout(open, 1500);
		card && card.querySelector('[data-bk-acc-open]').addEventListener('click', open);
		d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-bk-acc-close]')) d.close(); });
		d.querySelector('[data-bk-acc-eye]').addEventListener('click', (e) => {
			const b = e.currentTarget; const i = d.querySelector('#bk-acc-pass'); const on = i.type === 'password';
			i.type = on ? 'text' : 'password'; b.setAttribute('aria-pressed', String(on)); b.setAttribute('aria-label', on ? 'Ocultar contraseña' : 'Mostrar contraseña');
		});
		const err = d.querySelector('#bk-acc-err');
		d.querySelector('form').addEventListener('submit', async (e) => {
			e.preventDefault();
			const f = e.currentTarget; const btn = f.querySelector('.bk-acc-submit'); const pass = f.password.value;
			const show = (m) => { err.textContent = m; err.hidden = false; f.password.setAttribute('aria-invalid', 'true'); f.password.focus(); };
			if (pass.length < <?php echo (int) $min; ?>) return show('La contraseña debe tener al menos <?php echo (int) $min; ?> caracteres.');
			btn.disabled = true; btn.setAttribute('aria-busy', 'true'); err.hidden = true;
			try {
				const r = await fetch(d.dataset.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order: Number(d.dataset.order), key: d.dataset.key, password: pass }) });
				const j = await r.json().catch(() => ({}));
				if (!r.ok) throw new Error(j.message || 'No pudimos crear la cuenta. Intenta de nuevo.');
				d.querySelector('[data-step="form"]').hidden = true; d.querySelector('[data-step="done"]').hidden = false;
				if (card) card.remove();
				(window.dataLayer = window.dataLayer || []).push({ event: 'sign_up', method: 'thank_you' });
				d.querySelector('[data-step="done"] a').focus();
			} catch (x) { show(x.message); } finally { btn.disabled = false; btn.removeAttribute('aria-busy'); }
		});
	})();
	</script>
	<?php
	return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * Enlace para crear la contraseña (72 h, un solo uso)
 * ------------------------------------------------------------------------- */

/**
 * Genera el enlace (guarda solo el resumen del código, nunca el código).
 *
 * @param int $user_id Usuario.
 * @return array{url:string,expires:int}
 */
function bk_acc_make_link( $user_id ) {
	$token   = wp_generate_password( 40, false, false );
	$expires = time() + (int) bk_acc_cfg()['hours'] * HOUR_IN_SECONDS;
	update_user_meta( $user_id, '_bk_acc_tok', hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) );
	update_user_meta( $user_id, '_bk_acc_exp', $expires );
	$url = add_query_arg( array( 'u' => (int) $user_id, 'k' => $token ), home_url( '/' . bk_acc_cfg()['slug'] . '/' ) );
	return array( 'url' => $url, 'expires' => $expires );
}

/**
 * ¿El enlace es válido?
 *
 * @param int    $user_id Usuario.
 * @param string $token   Código.
 * @return WP_User|null
 */
function bk_acc_check_link( $user_id, $token ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u || '' === $token || user_can( $u, 'edit_posts' ) || user_can( $u, 'bk_gestion' ) ) {
		return null;
	}
	$hash = (string) get_user_meta( $u->ID, '_bk_acc_tok', true );
	$exp  = (int) get_user_meta( $u->ID, '_bk_acc_exp', true );
	if ( '' === $hash || $exp < time() || ! hash_equals( $hash, hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) ) ) ) {
		return null;
	}
	return $u;
}

add_action( 'parse_request', 'bk_acc_route', 0 );

/**
 * /crear-contrasena/: formulario para elegir la contraseña con el enlace del correo.
 *
 * @param WP $wp Petición.
 */
function bk_acc_route( $wp ) {
	$path = trim( (string) ( $wp->request ?? '' ), '/' );
	if ( '' === $path ) {
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
	if ( bk_acc_cfg()['slug'] !== $path ) {
		return;
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	$uid   = isset( $_REQUEST['u'] ) ? absint( $_REQUEST['u'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$token = isset( $_REQUEST['k'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$u     = bk_acc_check_link( $uid, $token );
	$error = '';
	if ( $u && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$pass  = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_POST['_bknonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bknonce'] ) ), 'bk_acc_set_' . $u->ID ) ) {
			$error = __( 'La página se venció. Vuelve a escribir tu contraseña.', 'buckler-core' );
		} elseif ( strlen( $pass ) < bk_acc_cfg()['min'] ) {
			/* translators: %d: caracteres */
			$error = sprintf( __( 'La contraseña debe tener al menos %d caracteres.', 'buckler-core' ), bk_acc_cfg()['min'] );
		} elseif ( $pass !== $pass2 ) {
			$error = __( 'Las contraseñas no coinciden.', 'buckler-core' );
		} else {
			wp_set_password( $pass, $u->ID );
			delete_user_meta( $u->ID, '_bk_acc_tok' );
			delete_user_meta( $u->ID, '_bk_acc_exp' );
			update_user_meta( $u->ID, '_bk_acc_active', time() );
			bk_acc_link_orders( $u->ID );
			wp_set_current_user( $u->ID );
			wp_set_auth_cookie( $u->ID, true );
			do_action( 'bk_account_password_set', $u->ID );
			wp_safe_redirect( add_query_arg( 'bk_bienvenida', '1', wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
	}
	bk_acc_page( $u, $uid, $token, $error );
	exit;
}

/**
 * Página del enlace (independiente del tema, como /ingreso/).
 *
 * @param WP_User|null $u     Usuario (null = enlace inválido o vencido).
 * @param int          $uid   ID.
 * @param string       $token Código.
 * @param string       $error Error.
 */
function bk_acc_page( $u, $uid, $token, $error ) {
	$logo = function_exists( 'bk_site_logo_url' ) ? bk_site_logo_url() : BK_URL . 'assets/img/logo-email.png';
	$min  = (int) bk_acc_cfg()['min'];
	$wa   = function_exists( 'bk_whatsapp_url' ) ? bk_whatsapp_url( __( 'Hola, mi enlace para crear la contraseña de mi cuenta en buckler.com.co ya no funciona. ¿Me ayudan?', 'buckler-core' ) ) : '';
	$name = $u ? trim( (string) $u->first_name ) : '';
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!doctype html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $u ? __( 'Crea tu contraseña', 'buckler-core' ) : __( 'Enlace vencido', 'buckler-core' ) ); ?> · Buckler Jeans</title>
<?php if ( function_exists( 'get_site_icon_url' ) && get_site_icon_url() ) : ?><link rel="icon" href="<?php echo esc_url( get_site_icon_url( 64 ) ); ?>"><?php endif; ?>
<style>
@font-face{font-family:Manrope;src:url(<?php echo esc_url( BK_URL . 'assets/fonts/manrope-latin-wght-normal.woff2' ); ?>) format('woff2');font-weight:200 800;font-display:swap}
*{box-sizing:border-box}html,body{margin:0}body{min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:#eef1f4;font:400 16px/1.55 Manrope,system-ui,sans-serif;color:#10202f}
.bx{width:100%;max-width:440px;background:#fff;border-radius:18px;box-shadow:0 10px 40px rgb(16 32 47/.1);overflow:hidden}
.bx__top{background:#1a3955;padding:22px 28px}.bx__top img{display:block;height:30px;width:auto;filter:brightness(0) invert(1)}
.bx__in{padding:26px 28px 30px}h1{margin:0 0 8px;font-size:26px;line-height:1.2;font-weight:800}p{margin:0 0 16px;color:#4b5866}
label{display:block;margin:14px 0 6px;font-weight:700;font-size:14px}.pw{position:relative}
input{width:100%;height:50px;padding:0 48px 0 14px;border:1.5px solid #c9d1d8;border-radius:12px;font:inherit;color:inherit}
input:focus{outline:3px solid #e4ec6a;outline-offset:1px;border-color:#1a3955}
.eye{position:absolute;right:4px;top:4px;width:42px;height:42px;border:0;background:none;border-radius:10px;cursor:pointer;color:#1a3955}
.eye:focus-visible{outline:3px solid #e4ec6a}
.hint{margin:6px 0 0;font-size:13px}.err{margin:14px 0 0;padding:10px 12px;border-radius:10px;background:#fdecea;color:#8a1c14;font-weight:600;font-size:14px}
.btn{display:block;width:100%;margin-top:20px;height:52px;border:0;border-radius:999px;background:#1a3955;color:#fff;font:800 16px Manrope,sans-serif;cursor:pointer;text-align:center;line-height:52px;text-decoration:none}
.btn:hover{background:#0f2a42}.btn:focus-visible{outline:3px solid #e4ec6a;outline-offset:2px}.btn.ghost{background:#fff;color:#1a3955;border:1.5px solid #1a3955;line-height:49px}
.mail{display:flex;gap:8px;align-items:center;padding:10px 12px;border-radius:10px;background:#f2f4f6;font-size:14px;word-break:break-all}
</style>
</head>
<body>
<main class="bx">
	<div class="bx__top"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( $logo ); ?>" alt="Buckler Jeans"></a></div>
	<div class="bx__in">
	<?php if ( $u ) : ?>
		<h1><?php echo esc_html( $name ? sprintf( /* translators: %s: nombre */ __( 'Hola, %s', 'buckler-core' ), $name ) : __( 'Crea tu contraseña', 'buckler-core' ) ); ?></h1>
		<p><?php esc_html_e( 'Elige la contraseña de tu cuenta. Con ella verás tus pedidos, seguirás tus envíos y comprarás más rápido.', 'buckler-core' ); ?></p>
		<p class="mail"><span aria-hidden="true">✉</span><span><?php esc_html_e( 'Tu usuario es tu correo:', 'buckler-core' ); ?> <b><?php echo esc_html( $u->user_email ); ?></b></span></p>
		<form method="post" action="<?php echo esc_url( add_query_arg( array( 'u' => $uid, 'k' => $token ), home_url( '/' . bk_acc_cfg()['slug'] . '/' ) ) ); ?>" novalidate>
			<?php wp_nonce_field( 'bk_acc_set_' . $u->ID, '_bknonce' ); ?>
			<label for="pass1"><?php esc_html_e( 'Nueva contraseña', 'buckler-core' ); ?></label>
			<div class="pw"><input type="password" id="pass1" name="pass1" autocomplete="new-password" minlength="<?php echo esc_attr( $min ); ?>" required aria-describedby="hint<?php echo $error ? ' err' : ''; ?>" <?php echo $error ? 'aria-invalid="true"' : ''; ?> autofocus><button type="button" class="eye" aria-pressed="false" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'buckler-core' ); ?>" data-for="pass1"><?php echo bk_icon_svg( 'lucide:eye', 20 ); // phpcs:ignore ?></button></div>
			<p class="hint" id="hint">
				<?php
				/* translators: %d: caracteres */
				echo esc_html( sprintf( __( 'Mínimo %d caracteres.', 'buckler-core' ), $min ) );
				?>
			</p>
			<label for="pass2"><?php esc_html_e( 'Repite la contraseña', 'buckler-core' ); ?></label>
			<div class="pw"><input type="password" id="pass2" name="pass2" autocomplete="new-password" required><button type="button" class="eye" aria-pressed="false" aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'buckler-core' ); ?>" data-for="pass2"><?php echo bk_icon_svg( 'lucide:eye', 20 ); // phpcs:ignore ?></button></div>
			<?php if ( $error ) : ?><p class="err" id="err" role="alert"><?php echo esc_html( $error ); ?></p><?php endif; ?>
			<button type="submit" class="btn"><?php esc_html_e( 'Guardar y entrar a mi cuenta', 'buckler-core' ); ?></button>
		</form>
		<script>document.querySelectorAll('.eye').forEach((b)=>b.addEventListener('click',()=>{const i=document.getElementById(b.dataset.for);const on=i.type==='password';i.type=on?'text':'password';b.setAttribute('aria-pressed',String(on));b.setAttribute('aria-label',on?'Ocultar contraseña':'Mostrar contraseña');}));</script>
	<?php else : ?>
		<h1><?php esc_html_e( 'Este enlace ya no funciona', 'buckler-core' ); ?></h1>
		<p>
			<?php
			/* translators: %d: horas */
			echo esc_html( sprintf( __( 'Los enlaces para crear la contraseña sirven una sola vez y vencen a las %d horas. Pide uno nuevo desde Mi cuenta con "¿Olvidaste tu contraseña?" o escríbenos.', 'buckler-core' ), (int) bk_acc_cfg()['hours'] ) );
			?>
		</p>
		<a class="btn" href="<?php echo esc_url( wc_lostpassword_url() ); ?>"><?php esc_html_e( 'Recuperar mi contraseña', 'buckler-core' ); ?></a>
		<?php if ( $wa ) : ?><a class="btn ghost" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Escribir por WhatsApp', 'buckler-core' ); ?></a><?php endif; ?>
	<?php endif; ?>
	</div>
</main>
</body>
</html>
	<?php
}

/**
 * Mensaje de bienvenida en Mi cuenta después de crear la contraseña.
 */
add_action(
	'woocommerce_account_content',
	static function () {
		if ( isset( $_GET['bk_bienvenida'] ) && is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="woocommerce-message" role="status">' . esc_html__( '¡Listo! Tu contraseña quedó guardada. Aquí están tus pedidos.', 'buckler-core' ) . '</div>';
		}
	},
	1
);
