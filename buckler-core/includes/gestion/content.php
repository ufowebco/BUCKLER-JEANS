<?php
/**
 * Gestión de tienda → Contenido: lo que hoy se edita en wp-admin con ACF, sin entrar a wp-admin.
 *
 *   Banners       (opciones ACF "Banners Páginas", admin.php?page=banners): repeater `banners_pagina_inicio`
 *                 (imagen PC, imagen móvil, enlace) + imágenes de las líneas `linea_{slim|curvy}_{pc|mo}`.
 *   Guía de tallas(opciones ACF, admin.php?page=guia-de-tallas): `imagen_guia_de_tallas`, `imagen_siluetas`.
 *   Políticas     (CPT politica-empresa): título + ACF `texto_politica`.
 *   Preguntas     (CPT pregunta-frecuente): pregunta (título), ACF `respuesta_pregunta_frecuente`, categoría
 *                 (taxonomía caregoria-pf) y orden.
 *
 * Se guarda con el MISMO formato de almacenamiento de ACF (opciones `options_{campo}` + `_options_{campo}` = clave
 * del campo; repeater `options_{rep}` = número de filas y `options_{rep}_{i}_{sub}`), así wp-admin lo sigue
 * mostrando igual. Los subcampos que el panel no maneja se conservan al reordenar o editar.
 * Después de guardar se vacía la caché de páginas.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_gc_routes' );

/**
 * Rutas.
 */
function bk_gc_routes() {
	$ns   = 'bk/v1';
	$perm = 'bk_g_can';
	register_rest_route(
		$ns,
		'/gestion/content/banners',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_gc_banners_get', 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => 'bk_gc_banners_save', 'permission_callback' => $perm ),
		)
	);
	register_rest_route(
		$ns,
		'/gestion/content/guide',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_gc_guide_get', 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => 'bk_gc_guide_save', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/gestion/content/posts/(?P<type>policy|faq)', array( 'methods' => 'GET', 'callback' => 'bk_gc_posts_get', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/gestion/content/posts/(?P<type>policy|faq)/save', array( 'methods' => 'POST', 'callback' => 'bk_gc_post_save', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/gestion/content/posts/(?P<type>policy|faq)/delete', array( 'methods' => 'POST', 'callback' => 'bk_gc_post_delete', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/gestion/content/posts/faq/order', array( 'methods' => 'POST', 'callback' => 'bk_gc_faq_order', 'permission_callback' => $perm ) );
}

/* ---------------------------------------------------------------------------
 * Almacenamiento ACF (opciones)
 * ------------------------------------------------------------------------- */

/**
 * Definición ACF de un campo (o null sin ACF).
 *
 * @param string $name Campo.
 * @return array|null
 */
function bk_gc_def( $name ) {
	$d = function_exists( 'acf_get_field' ) ? acf_get_field( $name ) : false;
	return is_array( $d ) ? $d : null;
}

/**
 * Guarda un campo simple de opciones con su referencia ACF.
 *
 * @param string $name  Campo.
 * @param mixed  $value Valor.
 */
function bk_gc_set_option( $name, $value ) {
	update_option( 'options_' . $name, $value, false );
	$def = bk_gc_def( $name );
	if ( $def && ! empty( $def['key'] ) ) {
		update_option( '_options_' . $name, $def['key'], false );
	}
}

/**
 * Imagen para el panel.
 *
 * @param mixed $v Valor ACF.
 * @return array|null
 */
function bk_gc_img( $v ) {
	$id = bk_image_id( $v );
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		return null;
	}
	$src = wp_get_attachment_image_src( $id, 'medium_large' );
	$meta = wp_get_attachment_metadata( $id );
	return array(
		'id'  => $id,
		'url' => $src ? $src[0] : '',
		'w'   => (int) ( $meta['width'] ?? 0 ),
		'h'   => (int) ( $meta['height'] ?? 0 ),
		'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
	);
}

/**
 * Valida un ID de imagen de la Mediateca.
 *
 * @param mixed $v Valor.
 * @return int
 */
function bk_gc_img_id( $v ) {
	$id = absint( is_array( $v ) ? ( $v['id'] ?? 0 ) : $v );
	return $id && wp_attachment_is_image( $id ) ? $id : 0;
}

/**
 * Guarda el texto alternativo de una imagen (los banners llevan texto dentro: el alt debe repetirlo).
 *
 * @param int    $id  Imagen.
 * @param string $alt Texto.
 */
function bk_gc_set_alt( $id, $alt ) {
	if ( $id && null !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
	}
}

/**
 * Filas crudas del repeater de banners (todas las columnas, también las que el panel no maneja).
 *
 * @return array<int,array<string,mixed>>
 */
function bk_gc_banner_rows_raw() {
	global $wpdb;
	$n    = (int) get_option( 'options_banners_pagina_inicio', 0 );
	$rows = array();
	if ( $n <= 0 ) {
		return $rows;
	}
	$like = $wpdb->esc_like( 'options_banners_pagina_inicio_' ) . '%';
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $o ) { // phpcs:ignore WordPress.DB
		if ( preg_match( '/^options_banners_pagina_inicio_(\d+)_(.+)$/', $o->option_name, $m ) && (int) $m[1] < $n ) {
			$rows[ (int) $m[1] ][ $m[2] ] = maybe_unserialize( $o->option_value );
		}
	}
	ksort( $rows );
	return array_values( $rows + array_fill( 0, $n, array() ) );
}

/**
 * Valor del enlace según el tipo de campo ACF.
 *
 * @param string $type Tipo ACF.
 * @param string $url  URL.
 * @return mixed
 */
function bk_gc_link_value( $type, $url ) {
	$url = esc_url_raw( trim( (string) $url ) );
	if ( '' === $url ) {
		return '';
	}
	if ( 'link' === $type ) {
		return array( 'title' => '', 'url' => $url, 'target' => '' );
	}
	if ( in_array( $type, array( 'page_link', 'post_object' ), true ) ) {
		$id = url_to_postid( $url );
		return $id ? $id : $url;
	}
	return $url;
}

/* ---------------------------------------------------------------------------
 * Banners
 * ------------------------------------------------------------------------- */

/**
 * Banners del inicio + imágenes de las líneas.
 *
 * @return WP_REST_Response
 */
function bk_gc_banners_get() {
	$map    = bk_hero_subfield_map();
	$slides = array();
	$raw    = bk_gc_banner_rows_raw();
	if ( ! $raw && is_array( bk_option_field( 'banners_pagina_inicio' ) ) ) {
		$raw = array_values( (array) bk_option_field( 'banners_pagina_inicio' ) ); // Respaldo: lo que devuelve ACF.
	}
	foreach ( $raw as $i => $row ) {
		$link     = $map['link'] ? ( $row[ $map['link'] ] ?? '' ) : '';
		$slides[] = array(
			'from' => $i, // Fila original: al reordenar se conservan los subcampos que el panel no maneja.
			'pc'   => $map['pc'] ? bk_gc_img( $row[ $map['pc'] ] ?? null ) : null,
			'mo'   => $map['mo'] ? bk_gc_img( $row[ $map['mo'] ] ?? null ) : null,
			'link' => $map['link'] ? bk_link_url( $link ) : '',
		);
	}
	$lines = array();
	foreach ( array( 'linea_slim_pc', 'linea_slim_mo', 'linea_curvy_pc', 'linea_curvy_mo' ) as $f ) {
		$lines[ $f ] = bk_gc_img( get_option( 'options_' . $f, null ) ?? bk_option_field( $f ) );
	}
	return rest_ensure_response(
		array(
			'slides'  => $slides,
			'lines'   => $lines,
			'hasLink' => (bool) $map['link'],
			'ready'   => (bool) ( $map['pc'] || $map['mo'] ),
			'sizes'   => array( 'pc' => '1920 × 1080 px (16:9)', 'mo' => '1200 × 1200 px (1:1)' ),
			'admin'   => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=banners' ) : '',
		)
	);
}

/**
 * Guarda banners (orden, imágenes, enlace, alt) e imágenes de líneas.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gc_banners_save( WP_REST_Request $r ) {
	$map = bk_hero_subfield_map();
	if ( ! $map['pc'] && ! $map['mo'] ) {
		return new WP_Error( 'bk_acf', 'No se encontraron los campos de los banners (ACF). Revísalo en wp-admin → Banners.', array( 'status' => 400 ) );
	}
	$def  = bk_gc_def( 'banners_pagina_inicio' );
	$subs = array();
	foreach ( (array) ( $def['sub_fields'] ?? array() ) as $sf ) {
		$subs[ $sf['name'] ] = $sf;
	}
	$old  = bk_gc_banner_rows_raw();
	$in   = is_array( $r['slides'] ) ? array_slice( $r['slides'], 0, 12 ) : array();
	$rows = array();
	foreach ( $in as $s ) {
		$from = isset( $s['from'] ) && isset( $old[ (int) $s['from'] ] ) ? $old[ (int) $s['from'] ] : array(); // Conserva los demás subcampos.
		$pc   = bk_gc_img_id( $s['pc'] ?? 0 );
		$mo   = bk_gc_img_id( $s['mo'] ?? 0 );
		if ( ! $pc && ! $mo ) {
			continue;
		}
		$row = $from;
		if ( $map['pc'] ) {
			$row[ $map['pc'] ] = $pc ? $pc : '';
		}
		if ( $map['mo'] ) {
			$row[ $map['mo'] ] = $mo ? $mo : '';
		}
		if ( $map['link'] && array_key_exists( 'link', $s ) ) {
			$row[ $map['link'] ] = bk_gc_link_value( $subs[ $map['link'] ]['type'] ?? 'url', (string) $s['link'] );
		}
		bk_gc_set_alt( $pc, $s['alt'] ?? null );
		bk_gc_set_alt( $mo, $s['alt'] ?? null );
		$rows[] = $row;
	}
	// Borra las filas viejas y escribe las nuevas (formato ACF).
	foreach ( $old as $i => $row ) {
		foreach ( array_unique( array_merge( array_keys( $row ), array_keys( $subs ) ) ) as $sub ) {
			delete_option( "options_banners_pagina_inicio_{$i}_{$sub}" );
			delete_option( "_options_banners_pagina_inicio_{$i}_{$sub}" );
		}
	}
	foreach ( $rows as $i => $row ) {
		foreach ( $row as $sub => $v ) {
			update_option( "options_banners_pagina_inicio_{$i}_{$sub}", $v, false );
			if ( ! empty( $subs[ $sub ]['key'] ) ) {
				update_option( "_options_banners_pagina_inicio_{$i}_{$sub}", $subs[ $sub ]['key'], false );
			}
		}
	}
	bk_gc_set_option( 'banners_pagina_inicio', count( $rows ) );

	foreach ( array( 'linea_slim_pc', 'linea_slim_mo', 'linea_curvy_pc', 'linea_curvy_mo' ) as $f ) {
		if ( is_array( $r['lines'] ) && array_key_exists( $f, $r['lines'] ) ) {
			$id = bk_gc_img_id( $r['lines'][ $f ] );
			bk_gc_set_option( $f, $id ? $id : '' );
		}
	}
	bk_gc_changed( 'banners' );
	return bk_gc_banners_get();
}

/* ---------------------------------------------------------------------------
 * Guía de tallas
 * ------------------------------------------------------------------------- */

/**
 * Guía de tallas y siluetas en texto (includes/size-guide.php).
 *
 * @return WP_REST_Response
 */
function bk_gc_guide_get() {
	$data  = function_exists( 'bk_size_guide_data' ) ? bk_size_guide_data() : array( 'tables' => array(), 'note' => '', 'sil' => array() );
	$lines = array_map( static fn( $l ) => $l['label'], (array) bk_config( 'lines' ) );
	return rest_ensure_response(
		array(
			'lines'  => $lines,
			'tables' => array_intersect_key( $data['tables'], $lines ),
			'note'   => $data['note'],
			'sil'    => array_map( static fn( $s ) => $s + array( 'svg' => function_exists( 'bk_size_guide_svg' ) ? bk_size_guide_svg( $s['k'] ) : '', 'off' => false ), $data['sil'] ),
		)
	);
}

/**
 * Guarda la guía (o restaura los valores originales con reset=1).
 *
 * @param WP_REST_Request $r Petición (tables, note, sil | reset).
 * @return WP_REST_Response|WP_Error
 */
function bk_gc_guide_save( WP_REST_Request $r ) {
	if ( rest_sanitize_boolean( $r['reset'] ) ) {
		delete_option( 'bk_size_guide' );
		bk_gc_changed( 'guide' );
		return bk_gc_guide_get();
	}
	$lines  = (array) bk_config( 'lines' );
	$tables = array();
	foreach ( (array) $r['tables'] as $slug => $rows ) {
		if ( ! isset( $lines[ $slug ] ) ) {
			continue;
		}
		$clean = array();
		foreach ( (array) $rows as $row ) {
			$row  = array_values( (array) $row );
			$size = sanitize_text_field( (string) ( $row[0] ?? '' ) );
			$nums = array_map( static fn( $v ) => round( (float) str_replace( ',', '.', (string) $v ), 1 ), array_slice( array_pad( $row, 5, 0 ), 1, 4 ) );
			if ( '' === $size ) {
				continue;
			}
			if ( $nums[0] <= 0 || $nums[1] < $nums[0] || $nums[2] <= 0 || $nums[3] < $nums[2] ) {
				/* translators: 1: talla, 2: línea */
				return new WP_Error( 'bk_guide', sprintf( 'Revisa la talla %1$s de %2$s: cada medida necesita "desde" y "hasta", y "hasta" no puede ser menor.', $size, $lines[ $slug ]['label'] ), array( 'status' => 400 ) );
			}
			$clean[] = array_merge( array( $size ), $nums );
		}
		$tables[ $slug ] = $clean;
	}
	$base = function_exists( 'bk_size_guide_defaults' ) ? array_column( bk_size_guide_defaults()['sil'], null, 'k' ) : array();
	$sil  = array();
	foreach ( (array) $r['sil'] as $s ) {
		$k = sanitize_key( (string) ( $s['k'] ?? '' ) );
		if ( ! isset( $base[ $k ] ) ) {
			continue;
		}
		$sil[] = array(
			'k'   => $k,
			'es'  => sanitize_text_field( (string) ( $s['es'] ?? '' ) ),
			'en'  => sanitize_text_field( (string) ( $s['en'] ?? '' ) ),
			'd'   => sanitize_textarea_field( (string) ( $s['d'] ?? '' ) ),
			'kw'  => sanitize_text_field( (string) ( $s['kw'] ?? '' ) ),
			'off' => ! empty( $s['off'] ),
		);
	}
	update_option( 'bk_size_guide', array( 'tables' => $tables, 'note' => sanitize_textarea_field( (string) $r['note'] ), 'sil' => $sil ), false );
	bk_gc_changed( 'guide' );
	return bk_gc_guide_get();
}

/* ---------------------------------------------------------------------------
 * Políticas y preguntas frecuentes
 * ------------------------------------------------------------------------- */

/**
 * Tipo de post y campo ACF.
 *
 * @param string $type policy|faq.
 * @return array{0:string,1:string}
 */
function bk_gc_type( $type ) {
	return 'faq' === $type ? array( 'pregunta-frecuente', 'respuesta_pregunta_frecuente' ) : array( 'politica-empresa', 'texto_politica' );
}

/**
 * HTML permitido en los textos (formato básico, enlaces y títulos).
 *
 * @param string $html HTML.
 * @return string
 */
function bk_gc_clean( $html ) {
	$html = wp_kses(
		(string) $html,
		array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h2'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
			'div'    => array(),
		)
	);
	$html = preg_replace( array( '#<div>\s*(<br\s*/?>)?\s*</div>#i', '#<div>#i', '#</div>#i' ), array( '', '<p>', '</p>' ), $html );
	$html = preg_replace( array( '#<(/?)b>#i', '#<(/?)i>#i' ), array( '<$1strong>', '<$1em>' ), $html );
	return trim( preg_replace( '#<p>\s*</p>#', '', $html ) );
}

/**
 * Texto para el editor (párrafos si viene como texto plano).
 *
 * @param string $html Texto guardado.
 * @return string
 */
function bk_gc_for_editor( $html ) {
	$html = (string) $html;
	return '' !== trim( $html ) && ! preg_match( '#<(p|ul|ol|h[1-6]|div)[\s>]#i', $html ) ? wpautop( $html ) : $html;
}

/**
 * Lista de políticas o preguntas.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gc_posts_get( WP_REST_Request $r ) {
	list( $pt, $field ) = bk_gc_type( $r['type'] );
	if ( ! post_type_exists( $pt ) ) {
		return rest_ensure_response( array( 'items' => array(), 'missing' => true ) );
	}
	$posts = get_posts(
		array(
			'post_type'      => $pt,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 200,
			'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
		)
	);
	$items = array();
	foreach ( $posts as $p ) {
		$text = (string) get_post_meta( $p->ID, $field, true );
		if ( 'policy' === $r['type'] && '' === trim( wp_strip_all_tags( $text ) ) && function_exists( 'bk_policy_text' ) ) {
			$text = bk_policy_text( $p ); // Hoy puede venir de "Listado de Políticas": al guardar queda en la política.
		}
		$terms   = 'faq' === $r['type'] ? wp_get_object_terms( $p->ID, 'caregoria-pf', array( 'fields' => 'ids' ) ) : array();
		$items[] = array(
			'id'     => $p->ID,
			'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'text'   => bk_gc_for_editor( $text ),
			'status' => $p->post_status,
			'url'    => get_permalink( $p ),
			'cat'    => is_array( $terms ) && $terms ? (int) $terms[0] : 0,
			'order'  => (int) $p->menu_order,
		);
	}
	$cats = array();
	if ( 'faq' === $r['type'] && taxonomy_exists( 'caregoria-pf' ) ) {
		foreach ( (array) get_terms( array( 'taxonomy' => 'caregoria-pf', 'hide_empty' => false ) ) as $t ) {
			$cats[] = array( 'id' => (int) $t->term_id, 'name' => $t->name );
		}
	}
	return rest_ensure_response( array( 'items' => $items, 'cats' => $cats, 'page' => 'faq' === $r['type'] ? home_url( (string) bk_config( 'faq_page' ) ) : '' ) );
}

/**
 * Crea o guarda una política / pregunta.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gc_post_save( WP_REST_Request $r ) {
	list( $pt, $field ) = bk_gc_type( $r['type'] );
	$id    = absint( $r['id'] );
	$title = trim( sanitize_text_field( (string) $r['title'] ) );
	$text  = bk_gc_clean( (string) $r['text'] );
	if ( mb_strlen( $title ) < 3 ) {
		return new WP_Error( 'bk_invalid', 'faq' === $r['type'] ? 'Escribe la pregunta.' : 'Escribe el título.', array( 'status' => 400, 'field' => 'title' ) );
	}
	if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
		return new WP_Error( 'bk_invalid', 'faq' === $r['type'] ? 'Escribe la respuesta.' : 'Escribe el texto.', array( 'status' => 400, 'field' => 'text' ) );
	}
	if ( $id && get_post_type( $id ) !== $pt ) {
		return new WP_Error( 'bk_not_found', 'No encontrado.', array( 'status' => 404 ) );
	}
	$status = in_array( $r['status'], array( 'publish', 'draft' ), true ) ? $r['status'] : 'publish';
	$data   = array( 'post_type' => $pt, 'post_title' => $title, 'post_status' => $status );
	if ( $id ) {
		$data['ID'] = $id;
		$res        = wp_update_post( wp_slash( $data ), true );
	} else {
		$data['menu_order'] = 999;
		$res                = wp_insert_post( wp_slash( $data ), true );
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'bk_save', $res->get_error_message(), array( 'status' => 500 ) );
	}
	$id = (int) $res;
	update_post_meta( $id, $field, wp_slash( $text ) );
	$def = bk_gc_def( $field );
	if ( $def && ! empty( $def['key'] ) ) {
		update_post_meta( $id, '_' . $field, $def['key'] );
	}
	if ( 'faq' === $r['type'] && taxonomy_exists( 'caregoria-pf' ) ) {
		$cat = absint( $r['cat'] );
		wp_set_object_terms( $id, $cat ? array( $cat ) : array(), 'caregoria-pf' );
	}
	bk_gc_changed( $r['type'] );
	$r->set_param( 'saved', $id );
	return bk_gc_posts_get( $r );
}

/**
 * Envía a la papelera (se puede recuperar en wp-admin).
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gc_post_delete( WP_REST_Request $r ) {
	list( $pt ) = bk_gc_type( $r['type'] );
	$id         = absint( $r['id'] );
	if ( ! $id || get_post_type( $id ) !== $pt ) {
		return new WP_Error( 'bk_not_found', 'No encontrado.', array( 'status' => 404 ) );
	}
	wp_trash_post( $id );
	bk_gc_changed( $r['type'] );
	return bk_gc_posts_get( $r );
}

/**
 * Orden de las preguntas (menu_order).
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response
 */
function bk_gc_faq_order( WP_REST_Request $r ) {
	foreach ( array_values( array_map( 'absint', (array) $r['ids'] ) ) as $i => $id ) {
		if ( 'pregunta-frecuente' === get_post_type( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'menu_order' => $i ) );
		}
	}
	bk_gc_changed( 'faq' );
	$r->set_param( 'type', 'faq' );
	return bk_gc_posts_get( $r );
}

/**
 * Después de guardar: caché de páginas y registro.
 *
 * @param string $what Qué cambió.
 */
function bk_gc_changed( $what ) {
	if ( function_exists( 'bk_cache_purge' ) ) {
		bk_cache_purge();
	}
	update_option( 'bk_gc_last', array( 'what' => $what, 't' => time(), 'by' => wp_get_current_user()->display_name ), false );
	do_action( 'bk_content_changed', $what );
}
