<?php
/** Administrador del menú en local: usuarios autorizados y no autorizados, plugin de IA simulado (idempotente). */
require '/tmp/claude-0/bk-local/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach ( array( 'sebas_ufoweb' => 'administrator', 'sebas_ufoweb.co' => 'administrator', 'otroadmin' => 'administrator', 'editora' => 'editor' ) as $login => $role ) {
	if ( ! get_user_by( 'login', $login ) ) {
		wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'Clave#2026', 'user_email' => str_replace( '.', '-', $login ) . '@ejemplo.com', 'role' => $role, 'display_name' => ucfirst( $login ) ) );
	}
}
activate_plugin( 'ai-editor-divi5/ai-editor-divi5.php' );
$m = (array) get_option( 'bk_modules', array() );
update_option( 'bk_modules', array_values( array_unique( array_merge( $m, array( 'adminmenu' ) ) ) ) );
delete_option( 'bk_am_settings' );
foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
	delete_user_meta( $id, 'bk_am_user' );
}
echo "ok\n";
