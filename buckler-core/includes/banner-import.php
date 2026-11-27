<?php
/**
 * Importador de una sola vez de los banners del inicio entregados por el cliente (2026-11-27, banners.rar:
 * "marcados por orden"). Sube las imágenes (WebP, assets/banners/2026-11) a la Mediateca y reemplaza el repeater ACF
 * banners_pagina_inicio con el mismo guardado de Gestión → Contenido → Banners (bk_gc_banners_save).
 *
 * Corre en segundo plano (Action Scheduler) una sola vez por lote: opción bk_banner_import_{lote} = queued | done | error.
 * Antes de reemplazar guarda las filas anteriores en bk_banners_prev_{lote} para poder volver atrás.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lote a importar (orden del cliente). alt = el texto que va dentro de la imagen.
 *
 * @return array{id:string,dir:string,slides:array}
 */
function bk_bi_batch() {
	return array(
		'id'     => '2026_11',
		'dir'    => BK_DIR . 'assets/banners/2026-11/',
		'slides' => array(
			array( 'pc' => 'banner-principal-01-desktop.webp', 'mo' => 'banner-principal-01-mobile.webp', 'link' => '/tienda/?nuevo=1', 'alt' => 'Hoy es un buen día para renovar tu clóset. Más moda, menor precio.' ),
			array( 'pc' => 'banner-principal-02-desktop.webp', 'mo' => 'banner-principal-02-mobile.webp', 'link' => '/tienda/', 'alt' => '¡No lo pienses tanto, aprovecha el 25 %!' ),
			array( 'pc' => 'banner-principal-03-desktop.webp', 'mo' => 'banner-principal-03-mobile.webp', 'link' => '/tienda/', 'alt' => 'Tus favoritos tienen descuento. Tiempo limitado.' ),
		),
	);
}

add_action(
	'init',
	static function () {
		$b = bk_bi_batch();
		if ( get_option( 'bk_banner_import_' . $b['id'] ) || ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'bk_gc_banners_save' ) ) {
			return;
		}
		update_option( 'bk_banner_import_' . $b['id'], 'queued', false );
		as_enqueue_async_action( 'bk_banner_import', array( $b['id'] ), 'buckler-banners' );
	},
	40
);

add_action( 'bk_banner_import', 'bk_bi_run' );

/**
 * Sube las imágenes y guarda los banners.
 *
 * @param string $id Lote.
 */
function bk_bi_run( $id ) {
	$b = bk_bi_batch();
	if ( $id !== $b['id'] || 'done' === get_option( 'bk_banner_import_' . $b['id'] ) || ! function_exists( 'bk_gc_banners_save' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$ids    = (array) get_option( 'bk_banner_import_ids_' . $b['id'], array() ); // Reanudable: no sube dos veces.
	$slides = array();
	foreach ( $b['slides'] as $i => $s ) {
		$row = array( 'link' => home_url( $s['link'] ), 'alt' => $s['alt'] );
		foreach ( array( 'pc', 'mo' ) as $k ) {
			$file = $s[ $k ];
			if ( empty( $ids[ $file ] ) || ! get_post( $ids[ $file ] ) ) {
				$tmp = wp_tempnam( $file );
				copy( $b['dir'] . $file, $tmp );
				/* translators: 1: número, 2: computador/celular */
				$title = sprintf( 'Banner inicio %1$d · %2$s', $i + 1, 'pc' === $k ? 'computador' : 'celular' );
				$att   = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0, $title );
				if ( is_wp_error( $att ) ) {
					wp_delete_file( $tmp );
					update_option( 'bk_banner_import_' . $b['id'], 'error: ' . $att->get_error_message(), false );
					return;
				}
				update_post_meta( $att, '_wp_attachment_image_alt', $s['alt'] );
				$ids[ $file ] = (int) $att;
				update_option( 'bk_banner_import_ids_' . $b['id'], $ids, false );
			}
			$row[ $k ] = (int) $ids[ $file ];
		}
		$slides[] = $row;
	}
	if ( ! get_option( 'bk_banners_prev_' . $b['id'] ) && function_exists( 'bk_gc_banner_rows_raw' ) ) {
		update_option( 'bk_banners_prev_' . $b['id'], bk_gc_banner_rows_raw(), false ); // Para volver a los banners anteriores.
	}
	$req = new WP_REST_Request( 'POST' );
	$req->set_param( 'slides', $slides );
	$res = bk_gc_banners_save( $req );
	if ( is_wp_error( $res ) ) {
		update_option( 'bk_banner_import_' . $b['id'], 'error: ' . $res->get_error_message(), false );
		return;
	}
	update_option( 'bk_banner_import_' . $b['id'], 'done', false );
}
