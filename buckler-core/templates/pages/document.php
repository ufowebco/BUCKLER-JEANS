<?php
/**
 * Documento HTML propio de buckler-core: sin plantillas ni estilos de Divi.
 * El contenido de cada ruta está en templates/pages/{ruta}.php.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_route = bk_pages_route();
ob_start();
wp_head();
$bk_head = bk_pages_clean_head( (string) ob_get_clean() );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php echo $bk_head; // phpcs:ignore WordPress.Security.EscapeOutput -- salida de wp_head(). ?>
</head>
<body <?php body_class( array( 'bk-doc', 'bk-doc--' . $bk_route ) ); ?>>
<?php wp_body_open(); ?>
<?php echo do_shortcode( '[bk_header]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<main id="main-content" class="bk-main bk-main--<?php echo esc_attr( $bk_route ); ?>" tabindex="-1">
	<?php include BK_DIR . 'templates/pages/' . $bk_route . '.php'; ?>
</main>
<?php echo do_shortcode( '[bk_footer]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php
ob_start();
wp_footer();
echo bk_pages_clean_head( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- salida de wp_footer().
?>
</body>
</html>
