<?php
/**
 * Enrutador para `php -S` (imita el .htaccess de WordPress): archivos reales se sirven tal cual; el resto va a
 * index.php (así /llms.txt o /.well-known/ai-catalog.json llegan a WordPress como en producción).
 * Uso: php -S localhost:8899 -t site tools/local/router.php
 */
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ( '/' !== $path && is_file( $file ) ) {
	return false;
}
// Archivos estáticos que faltan (p. ej. JS sin compilar del WooCommerce de git): 404 simple, como el servidor.
if ( preg_match( '/\.(js|css|map|png|jpe?g|webp|gif|svg|woff2?|ttf|mp4|ico)$/i', $path ) ) {
	http_response_code( 404 );
	return true;
}
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
