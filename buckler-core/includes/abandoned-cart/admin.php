<?php
/**
 * WooCommerce → Carritos abandonados: resumen, lista, ajustes y vista previa del correo.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'bk_ac_admin_menu', 60 );
add_action( 'admin_post_bk_ac_save', 'bk_ac_admin_save' );
add_action( 'admin_post_bk_ac_test', 'bk_ac_admin_test' );
add_action( 'admin_post_bk_ac_delete', 'bk_ac_admin_delete' );
add_action( 'wp_ajax_bk_ac_preview', 'bk_ac_admin_preview' );

/**
 * Menú.
 */
function bk_ac_admin_menu() {
	add_submenu_page( 'woocommerce', __( 'Carritos abandonados', 'buckler-core' ), __( 'Carritos abandonados', 'buckler-core' ), 'manage_woocommerce', 'bk-abandoned', 'bk_ac_admin_page' );
}

/**
 * Etiquetas de estado.
 *
 * @return array
 */
function bk_ac_status_labels() {
	return array(
		'active'       => array( 'Pendiente', '#1a3955', '#e3eefb' ),
		'recovered'    => array( 'Recuperado', '#1f7a4d', '#e6f4ec' ),
		'converted'    => array( 'Compró', '#1f7a4d', '#eef7f1' ),
		'emptied'      => array( 'Vació el carrito', '#5b6773', '#f0f2f4' ),
		'expired'      => array( 'Vencido', '#5b6773', '#f0f2f4' ),
		'unsubscribed' => array( 'Se dio de baja', '#8a1c14', '#fdecea' ),
	);
}

/**
 * Carrito de ejemplo para la vista previa (productos reales de la tienda).
 *
 * @return object
 */
function bk_ac_sample_row() {
	global $wpdb;
	$table = bk_ac_table();
	$row   = $wpdb->get_var( "SELECT id FROM {$table} WHERE cart <> '[]' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
	if ( $row ) {
		return bk_ac_get( (int) $row );
	}
	$items = array();
	foreach ( wc_get_products( array( 'limit' => 2, 'status' => 'publish', 'stock_status' => 'instock', 'type' => 'variable' ) ) as $p ) {
		$v     = null;
		$attrs = array();
		foreach ( $p->get_children() as $child ) {
			$c = wc_get_product( $child );
			if ( $c && $c->is_in_stock() ) {
				$v = $c;
				break;
			}
		}
		if ( $v ) {
			foreach ( $v->get_attributes() as $tax => $slug ) {
				$term                         = get_term_by( 'slug', $slug, $tax );
				$attrs[ 'attribute_' . $tax ] = array( 'label' => wc_attribute_label( $tax ), 'value' => $term ? $term->name : $slug, 'raw' => $slug );
			}
		}
		$items[] = array(
			'product_id'   => $p->get_id(),
			'variation_id' => $v ? $v->get_id() : 0,
			'quantity'     => 1,
			'variation'    => $attrs,
			'name'         => $p->get_name(),
			'line_total'   => (float) ( $v ? $v->get_price() : $p->get_price() ),
			'image'        => (string) wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ),
		);
	}
	return (object) array(
		'id'          => 0,
		'token'       => 'vista-previa',
		'email'       => 'clienta@ejemplo.com',
		'first_name'  => 'Laura',
		'items'       => $items,
		'cart_total'  => array_sum( wp_list_pluck( $items, 'line_total' ) ),
		'coupon_code' => '',
	);
}

/**
 * Vista previa (iframe). No crea cupones: muestra uno de ejemplo.
 */
function bk_ac_admin_preview() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( '', 403 );
	}
	check_admin_referer( 'bk_ac_preview' );
	$step   = isset( $_GET['step'] ) ? min( 3, max( 1, absint( $_GET['step'] ) ) ) : 1;
	$pct    = (int) ( bk_ac_settings()['coupon_pct'][ $step - 1 ] ?? 0 );
	$coupon = $pct > 0 ? array( 'code' => 'VUELVE-EJEMPLO', 'pct' => $pct, 'expires' => time() + 3 * DAY_IN_SECONDS ) : null;
	$mail   = bk_ac_build_email( bk_ac_sample_row(), $step, $coupon );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo $mail['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- plantilla escapada.
	exit;
}

/**
 * Página principal.
 */
function bk_ac_admin_page() {
	global $wpdb;
	$table = bk_ac_table();
	$tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
	$base  = admin_url( 'admin.php?page=bk-abandoned' );
	$s     = bk_ac_settings();
	$msg   = isset( $_GET['bk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bk_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	// Resumen de los últimos 30 días.
	$kpi = $wpdb->get_row( // phpcs:ignore WordPress.DB
		"SELECT COUNT(*) AS captured,
			SUM(status = 'active') AS pending,
			SUM(status = 'recovered') AS recovered,
			SUM(CASE WHEN status = 'recovered' THEN cart_total ELSE 0 END) AS recovered_total,
			SUM(emails_sent) AS emails
		 FROM {$table} WHERE created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"
	);
	?>
	<div class="wrap bk-ac">
		<h1><?php esc_html_e( 'Carritos abandonados', 'buckler-core' ); ?></h1>
		<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<style>
			.bk-ac .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0}
			.bk-ac .kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}
			.bk-ac .kpi b{display:block;font-size:24px;line-height:1.2;font-variant-numeric:tabular-nums}
			.bk-ac .pill{display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:600}
			.bk-ac .bk-ac-preview{display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;align-items:start;max-width:1100px}
			.bk-ac iframe{width:100%;height:900px;border:1px solid #dcdcde;border-radius:8px;background:#eef1f4}
			.bk-ac .steps a{display:block;padding:10px 12px;border-radius:6px;text-decoration:none;margin-bottom:4px}
			.bk-ac .steps a.on{background:#1a3955;color:#fff}
			@media (max-width:900px){.bk-ac .bk-ac-preview{grid-template-columns:1fr}}
		</style>

		<div class="kpis">
			<div class="kpi"><b><?php echo (int) $kpi->captured; ?></b><?php esc_html_e( 'Carritos con correo (30 días)', 'buckler-core' ); ?></div>
			<div class="kpi"><b><?php echo (int) $kpi->pending; ?></b><?php esc_html_e( 'Pendientes', 'buckler-core' ); ?></div>
			<div class="kpi"><b><?php echo (int) $kpi->emails; ?></b><?php esc_html_e( 'Correos enviados', 'buckler-core' ); ?></div>
			<div class="kpi"><b><?php echo (int) $kpi->recovered; ?></b><?php esc_html_e( 'Recuperados', 'buckler-core' ); ?></div>
			<div class="kpi"><b><?php echo wp_kses_post( wc_price( (float) $kpi->recovered_total ) ); ?></b><?php esc_html_e( 'Ventas recuperadas', 'buckler-core' ); ?></div>
		</div>

		<nav class="nav-tab-wrapper">
			<?php foreach ( array( 'list' => 'Carritos', 'preview' => 'Vista previa de correos', 'settings' => 'Ajustes' ) as $k => $label ) : ?>
				<a class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $k, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		if ( 'settings' === $tab ) {
			bk_ac_admin_settings( $s );
		} elseif ( 'preview' === $tab ) {
			bk_ac_admin_preview_tab();
		} else {
			bk_ac_admin_list();
		}
		?>
	</div>
	<?php
}

/**
 * Lista paginada.
 */
function bk_ac_admin_list() {
	global $wpdb;
	$table  = bk_ac_table();
	$labels = bk_ac_status_labels();
	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
	$per    = 30;
	$where  = $status && isset( $labels[ $status ] ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) ); // phpcs:ignore WordPress.DB
	$base   = admin_url( 'admin.php?page=bk-abandoned' );
	?>
	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( $base ); ?>" class="<?php echo $status ? '' : 'current'; ?>">Todos</a> |</li>
		<?php foreach ( $labels as $k => $l ) : ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', $k, $base ) ); ?>" class="<?php echo $status === $k ? 'current' : ''; ?>"><?php echo esc_html( $l[0] ); ?></a> |</li>
		<?php endforeach; ?>
	</ul>
	<table class="widefat striped" style="margin-top:8px">
		<thead><tr><th>Fecha</th><th>Clienta</th><th>Productos</th><th>Total</th><th>Correos</th><th>Estado</th><th></th></tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'Aún no hay carritos. Aparecen cuando una clienta escribe su correo o su celular en Finalizar compra y no termina la compra.', 'buckler-core' ); ?></td></tr>
		<?php endif; ?>
		<?php
		foreach ( $rows as $r ) :
			$items = json_decode( $r->cart, true ) ?: array();
			$l     = $labels[ $r->status ] ?? array( $r->status, '#333', '#eee' );
			?>
			<tr>
				<td><?php echo esc_html( wp_date( 'j M Y, g:i a', strtotime( $r->created_at . ' UTC' ) ) ); ?></td>
				<?php $x = bk_ac_extra( $r ); ?>
				<td><strong><?php echo esc_html( trim( $r->first_name . ' ' . ( $x['last_name'] ?? '' ) ) ); ?></strong><?php echo $r->email ? '<br><a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a>' : ''; ?><?php echo $r->phone ? '<br>' . esc_html( $r->phone ) : ''; ?><?php echo ! empty( $x['id_number'] ) ? '<br>Cédula ' . esc_html( $x['id_number'] ) : ''; ?><?php echo ! empty( $x['city'] ) ? '<br>' . esc_html( $x['city'] ) : ''; ?></td>
				<td>
					<?php
					foreach ( $items as $i ) {
						$size = implode( ', ', wp_list_pluck( (array) $i['variation'], 'value' ) );
						echo esc_html( $i['quantity'] . ' × ' . $i['name'] . ( $size ? " ({$size})" : '' ) ) . '<br>';
					}
					?>
				</td>
				<td><?php echo wp_kses_post( wc_price( (float) $r->cart_total ) ); ?></td>
				<td><?php echo esc_html( (int) $r->emails_sent . ' de 3' ); ?><?php echo $r->clicked_at ? '<br><small>Abrió el enlace</small>' : ''; ?></td>
				<td><span class="pill" style="color:<?php echo esc_attr( $l[1] ); ?>;background:<?php echo esc_attr( $l[2] ); ?>"><?php echo esc_html( $l[0] ); ?></span>
					<?php if ( 'active' === $r->status && ! (int) $r->consent ) : ?><br><small>Sin autorización: no recibe correos</small><?php endif; ?>
					<?php if ( $r->order_id ) : ?><br><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r->order_id ) ); ?>">Pedido #<?php echo (int) $r->order_id; ?></a><?php endif; ?>
				</td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'bk_ac_delete' ); ?>
						<input type="hidden" name="action" value="bk_ac_delete"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
						<button class="button-link button-link-delete"><?php esc_html_e( 'Borrar', 'buckler-core' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$pages = (int) ceil( $total / $per );
	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
	}
}

/**
 * Pestaña de vista previa.
 */
function bk_ac_admin_preview_tab() {
	$step = isset( $_GET['step'] ) ? min( 3, max( 1, absint( $_GET['step'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$d    = bk_ac_settings()['delays'];
	$src  = wp_nonce_url( admin_url( 'admin-ajax.php?action=bk_ac_preview&step=' . $step ), 'bk_ac_preview' );
	?>
	<div class="bk-ac-preview" style="margin-top:16px">
		<div>
			<div class="steps">
				<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
					<a class="<?php echo $i === $step ? 'on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'preview', 'step' => $i ), admin_url( 'admin.php?page=bk-abandoned' ) ) ); ?>">
						<strong><?php echo esc_html( "Correo {$i}" ); ?></strong><br><small><?php echo esc_html( sprintf( 'a las %s h', $d[ $i - 1 ] ) ); ?></small>
					</a>
				<?php endfor; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<?php wp_nonce_field( 'bk_ac_test' ); ?>
				<input type="hidden" name="action" value="bk_ac_test"><input type="hidden" name="step" value="<?php echo (int) $step; ?>">
				<label for="bk-ac-to"><?php esc_html_e( 'Enviar prueba a:', 'buckler-core' ); ?></label>
				<input id="bk-ac-to" type="email" name="to" class="widefat" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
				<button class="button" style="margin-top:6px"><?php esc_html_e( 'Enviarme este correo', 'buckler-core' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'La vista previa usa el carrito más reciente o productos de la tienda. El cupón que aparece es de ejemplo.', 'buckler-core' ); ?></p>
		</div>
		<iframe title="<?php esc_attr_e( 'Vista previa del correo', 'buckler-core' ); ?>" src="<?php echo esc_url( $src ); ?>"></iframe>
	</div>
	<?php
}

/**
 * Pestaña de ajustes.
 *
 * @param array $s Ajustes.
 */
function bk_ac_admin_settings( $s ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'bk_ac_save' ); ?>
		<input type="hidden" name="action" value="bk_ac_save">
		<table class="form-table" role="presentation">
			<tr><th scope="row">Recordatorios</th><td><label><input type="checkbox" name="enabled" value="yes" <?php checked( $s['enabled'], 'yes' ); ?>> Activar los correos de carrito abandonado</label></td></tr>
			<tr><th scope="row">Autorización (Ley 1581 de 2012)</th><td>
				<label><input type="radio" name="consent_mode" value="checkbox" <?php checked( $s['consent_mode'], 'checkbox' ); ?>> Casilla de autorización bajo el correo (recomendado)</label><br>
				<label><input type="radio" name="consent_mode" value="notice" <?php checked( $s['consent_mode'], 'notice' ); ?>> Solo aviso informativo (validar con tu asesor legal)</label>
				<p><label for="bk-ac-ct">Texto de la casilla</label><br><input id="bk-ac-ct" type="text" class="large-text" name="consent_text" value="<?php echo esc_attr( $s['consent_text'] ); ?>"></p>
			</td></tr>
			<?php for ( $i = 0; $i < 3; $i++ ) : ?>
			<tr><th scope="row"><?php echo esc_html( 'Correo ' . ( $i + 1 ) ); ?></th><td>
				<label>Enviar a las <input type="number" min="1" max="720" step="1" name="delays[]" value="<?php echo esc_attr( $s['delays'][ $i ] ); ?>" style="width:80px"> horas</label>
				&nbsp; <label>Cupón <input type="number" min="0" max="50" name="coupon_pct[]" value="<?php echo esc_attr( $s['coupon_pct'][ $i ] ); ?>" style="width:70px"> % (0 = sin cupón)</label>
				<p><label>Asunto <input type="text" class="large-text" name="subjects[]" value="<?php echo esc_attr( $s['subjects'][ $i ] ); ?>"></label></p>
			</td></tr>
			<?php endfor; ?>
			<tr><th scope="row">Vigencia del cupón</th><td><input type="number" min="12" max="720" name="coupon_hours" value="<?php echo esc_attr( $s['coupon_hours'] ); ?>" style="width:80px"> horas</td></tr>
			<tr><th scope="row">Conservar datos</th><td><input type="number" min="7" max="365" name="retention" value="<?php echo esc_attr( $s['retention'] ); ?>" style="width:80px"> días <p class="description">Luego se borran automáticamente.</p></td></tr>
		</table>
		<?php submit_button( __( 'Guardar ajustes', 'buckler-core' ) ); ?>
	</form>
	<?php
}

/**
 * Guarda los ajustes.
 */
function bk_ac_admin_save() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( '', 403 );
	}
	check_admin_referer( 'bk_ac_save' );
	$p = wp_unslash( $_POST );
	// Cada correo = [horas, asunto, % cupón]. Se ordenan juntos por horas para que no se mezclen.
	$steps = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$steps[] = array(
			max( 1, absint( $p['delays'][ $i ] ?? 48 ) ),
			sanitize_text_field( $p['subjects'][ $i ] ?? '' ),
			min( 50, absint( $p['coupon_pct'][ $i ] ?? 0 ) ),
		);
	}
	usort( $steps, fn( $a, $b ) => $a[0] <=> $b[0] );
	update_option(
		'bk_ac_settings',
		array(
			'enabled'      => isset( $p['enabled'] ) ? 'yes' : 'no',
			'consent_mode' => in_array( $p['consent_mode'] ?? '', array( 'checkbox', 'notice' ), true ) ? $p['consent_mode'] : 'checkbox',
			'consent_text' => sanitize_text_field( $p['consent_text'] ?? '' ),
			'delays'       => array_column( $steps, 0 ),
			'subjects'     => array_column( $steps, 1 ),
			'coupon_pct'   => array_column( $steps, 2 ),
			'coupon_hours' => max( 12, absint( $p['coupon_hours'] ?? 72 ) ),
			'retention'    => max( 7, absint( $p['retention'] ?? 60 ) ),
		),
		false
	);
	wp_safe_redirect( admin_url( 'admin.php?page=bk-abandoned&tab=settings&bk_msg=' . rawurlencode( 'Ajustes guardados.' ) ) );
	exit;
}

/**
 * Envía un correo de prueba.
 */
function bk_ac_admin_test() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( '', 403 );
	}
	check_admin_referer( 'bk_ac_test' );
	$to   = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
	$step = min( 3, max( 1, absint( $_POST['step'] ?? 1 ) ) );
	$pct  = (int) ( bk_ac_settings()['coupon_pct'][ $step - 1 ] ?? 0 );
	$mail = bk_ac_build_email( bk_ac_sample_row(), $step, $pct ? array( 'code' => 'VUELVE-EJEMPLO', 'pct' => $pct, 'expires' => time() + 3 * DAY_IN_SECONDS ) : null );
	$ok   = is_email( $to ) && bk_ac_mail( $to, '[Prueba] ' . $mail['subject'], $mail['html'] );
	wp_safe_redirect( admin_url( 'admin.php?page=bk-abandoned&tab=preview&step=' . $step . '&bk_msg=' . rawurlencode( $ok ? "Correo de prueba enviado a {$to}." : 'No se pudo enviar. Revisa el correo o la configuración SMTP.' ) ) );
	exit;
}

/**
 * Borra un carrito desde la lista.
 */
function bk_ac_admin_delete() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( '', 403 );
	}
	check_admin_referer( 'bk_ac_delete' );
	$id = absint( $_POST['id'] ?? 0 );
	bk_ac_unschedule( $id );
	bk_ac_delete( $id );
	wp_safe_redirect( admin_url( 'admin.php?page=bk-abandoned&bk_msg=' . rawurlencode( 'Carrito borrado.' ) ) );
	exit;
}
