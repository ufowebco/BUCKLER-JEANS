<?php
/**
 * Gestión de tienda: crear y editar productos (ficha completa) desde /gestion-tienda/.
 *
 * Reproduce la estructura real del catálogo para que un producto creado aquí sea igual a uno hecho a mano:
 *   - Producto variable con el atributo de tallas de su línea (bk_config('lines')[...]['attribute']),
 *     una variación por talla con stock gestionado.
 *   - Categorías: la línea (padre) + prenda (hija de la línea).
 *     "Nuevo"  = destacado + `linea-nueva` y su hija de la línea (slim / curvy).
 *     Oferta   = `sale` y su hija equivalente a la prenda (jeans → jeans-sale…), solo si hay precio rebajado.
 *   - REF (SKU) en el producto padre; las tallas la heredan.
 *   - Fotos: la primera es la principal; alt automático con el nombre si está vacío.
 *   - Video: meta `_bk_video_id` (módulo video).
 *
 * Rutas (permiso bk_g_can):
 *   GET  gestion/product-form                 líneas, tallas y prendas
 *   GET  gestion/products/{id}/form           ficha para editar (?twin=1 → base para la otra línea)
 *   POST gestion/products                     crear
 *   POST gestion/products/{id}/form           guardar
 *   POST gestion/media                        subir foto (jpg/png/webp) o video (mp4)
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'bk_gp_routes' );

/**
 * Rutas.
 */
function bk_gp_routes() {
	$ns   = 'bk/v1';
	$perm = 'bk_g_can';
	register_rest_route( $ns, '/gestion/product-form', array( 'methods' => 'GET', 'callback' => 'bk_gp_form_meta', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/gestion/products', array( 'methods' => 'POST', 'callback' => 'bk_gp_create', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/gestion/products/(?P<id>\d+)/form',
		array(
			array( 'methods' => 'GET', 'callback' => 'bk_gp_get', 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => 'bk_gp_update', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/gestion/media', array( 'methods' => 'POST', 'callback' => 'bk_gp_upload', 'permission_callback' => $perm ) );
}

/* ---------------------------------------------------------------------------
 * Taxonomías
 * ------------------------------------------------------------------------- */

/**
 * Término por slug (o null).
 *
 * @param string $slug Slug.
 * @param string $tax  Taxonomía.
 * @return WP_Term|null
 */
function bk_gp_term( $slug, $tax = 'product_cat' ) {
	$t = $slug ? get_term_by( 'slug', $slug, $tax ) : false;
	return $t instanceof WP_Term ? $t : null;
}

/**
 * Hijas directas de una categoría (incluye vacías: una prenda nueva puede no tener productos aún).
 *
 * @param string $parent_slug Slug del padre.
 * @return WP_Term[]
 */
function bk_gp_children( $parent_slug ) {
	$parent = bk_gp_term( $parent_slug );
	if ( ! $parent ) {
		return array();
	}
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent->term_id, 'hide_empty' => false, 'orderby' => 'menu_order' ) );
	return is_array( $terms ) ? $terms : array();
}

/**
 * "SETS Y OVEROL" y "SET Y OVEROL" → "set y overol" (para emparejar prendas entre árboles).
 *
 * @param string $name Nombre.
 * @return string
 */
function bk_gp_norm( $name ) {
	$n = remove_accents( mb_strtolower( wp_specialchars_decode( $name ) ) );
	$n = preg_replace( '/\bplus( size)?\b/', '', $n );
	$n = preg_replace( '/(\w)s\b/', '$1', $n );
	return trim( preg_replace( '/\s+/', ' ', $n ) );
}

/**
 * Hija de $parent_slug equivalente a la prenda $term (mismo nombre normalizado).
 *
 * @param WP_Term $term        Prenda.
 * @param string  $parent_slug Árbol destino.
 * @return WP_Term|null
 */
function bk_gp_match( $term, $parent_slug ) {
	foreach ( bk_gp_children( $parent_slug ) as $c ) {
		if ( bk_gp_norm( $c->name ) === bk_gp_norm( $term->name ) ) {
			return $c;
		}
	}
	return null;
}

/**
 * Tallas de una línea (términos del atributo, orden numérico).
 *
 * @param string $line Slug de la línea.
 * @return WP_Term[]
 */
function bk_gp_sizes( $line ) {
	$tax = bk_config( 'lines' )[ $line ]['attribute'] ?? '';
	if ( ! $tax || ! taxonomy_exists( $tax ) ) {
		return array();
	}
	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
	$terms = is_array( $terms ) ? $terms : array();
	usort( $terms, static fn( $a, $b ) => ( (float) $a->name <=> (float) $b->name ) ?: strcmp( $a->name, $b->name ) );
	return $terms;
}

/**
 * Líneas, tallas y prendas para el formulario.
 *
 * @return WP_REST_Response
 */
function bk_gp_form_meta() {
	$lines = array();
	foreach ( (array) bk_config( 'lines' ) as $slug => $l ) {
		$lt             = bk_gp_term( $slug );
		$lines[ $slug ] = array(
			'term'   => $lt ? $lt->term_id : 0,
			'label'  => $l['label'],
			'note'   => $l['sizes'],
			'suffix' => $l['sku_suffix'] ?? '',
			'sizes'  => array_map( static fn( $t ) => array( 'slug' => $t->slug, 'name' => $t->name ), bk_gp_sizes( $slug ) ),
			'cats'   => array_map( static fn( $t ) => array( 'id' => $t->term_id, 'name' => bk_nice_label( $t->name ) ), bk_gp_children( $slug ) ),
		);
	}
	return rest_ensure_response(
		array(
			'lines'    => $lines,
			'allCats'  => bk_gp_cat_tree(),
			'maxImage' => bk_gp_max_size( 'image' ),
			'maxVideo' => bk_gp_max_size( 'video' ),
		)
	);
}

/**
 * Todas las categorías de producto en árbol (padre seguido de sus hijas), para el editor.
 *
 * @return array<int,array{id:int,name:string,depth:int,parent:int,count:int}>
 */
function bk_gp_cat_tree() {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
	$terms = is_array( $terms ) ? $terms : array();
	$by    = array();
	foreach ( $terms as $t ) {
		$by[ (int) $t->parent ][] = $t;
	}
	$out  = array();
	$walk = static function ( $parent, $depth ) use ( &$walk, &$out, $by ) {
		foreach ( $by[ $parent ] ?? array() as $t ) {
			$out[] = array( 'id' => (int) $t->term_id, 'name' => bk_nice_label( $t->name ), 'depth' => $depth, 'parent' => (int) $t->parent, 'count' => (int) $t->count );
			$walk( (int) $t->term_id, $depth + 1 );
		}
	};
	$walk( 0, 0 );
	return $out;
}

/**
 * Descripción para el editor: si es texto con saltos de línea (sin párrafos HTML), se convierte en
 * párrafos y <br> como lo hace WordPress al mostrarla (wpautop). Así el editor muestra los saltos.
 *
 * @param string $html Descripción guardada.
 * @return string
 */
function bk_gp_desc_for_editor( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) || preg_match( '#<(p|ul|ol|h[1-6]|div|table|blockquote)[\s>]#i', $html ) ) {
		return $html;
	}
	return wpautop( $html );
}

/**
 * Línea de un producto (slug) o ''.
 *
 * @param WC_Product $p Producto.
 * @return string
 */
function bk_gp_line_of( $p ) {
	$l = bk_product_line( $p->get_id() );
	return $l ? $l['slug'] : '';
}

/**
 * Meta del video propio (módulo video).
 *
 * @return string
 */
function bk_gp_video_key() {
	return defined( 'BK_VIDEO_ID_META' ) ? BK_VIDEO_ID_META : '_bk_video_id';
}

/* ---------------------------------------------------------------------------
 * Leer
 * ------------------------------------------------------------------------- */

/**
 * Ficha completa para el formulario.
 *
 * @param WC_Product $p Producto.
 * @return array
 */
function bk_gp_data( $p ) {
	$line  = bk_gp_line_of( $p );
	$tax   = bk_config( 'lines' )[ $line ]['attribute'] ?? '';
	$cats  = array();
	$lterm = bk_gp_term( $line );
	foreach ( $p->get_category_ids() as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t instanceof WP_Term && $lterm && (int) $t->parent === (int) $lterm->term_id ) {
			$cats[] = $t->term_id;
		}
	}
	$sizes  = array();
	$prices = array();
	foreach ( $p->is_type( 'variable' ) ? $p->get_children() : array() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) {
			continue;
		}
		$slug = $tax ? (string) ( $v->get_attributes()[ $tax ] ?? '' ) : '';
		if ( '' === $slug ) {
			continue;
		}
		$sizes[ $slug ] = array(
			'id'      => $v->get_id(),
			'enabled' => 'private' !== $v->get_status( 'edit' ),
			'stock'   => $v->managing_stock() ? (int) $v->get_stock_quantity() : null,
		);
		$prices[]       = $v->get_regular_price( 'edit' ) . '|' . $v->get_sale_price( 'edit' );
	}
	$first = $prices ? explode( '|', $prices[0] ) : array( '', '' );
	$img   = static function ( $id ) {
		$type = (string) get_post_mime_type( $id );
		return array(
			'id'  => (int) $id,
			'url' => (string) ( 0 === strpos( $type, 'video/' ) ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, 'medium' ) ),
		);
	};
	// Contexto "edit": la galería en bruto (con el MP4 que guarda BodyCommerce).
	$all    = array_values( array_filter( array_merge( array( $p->get_image_id( 'edit' ) ), $p->get_gallery_image_ids( 'edit' ) ) ) );
	$images = array_values( array_filter( $all, 'wp_attachment_is_image' ) );
	$video  = (int) $p->get_meta( bk_gp_video_key() );
	foreach ( $video ? array() : $all as $aid ) {
		if ( 0 === strpos( (string) get_post_mime_type( $aid ), 'video/' ) ) {
			$video = (int) $aid;
			break;
		}
	}
	return array(
		'id'          => $p->get_id(),
		'name'        => $p->get_name( 'edit' ),
		'sku'         => $p->get_sku( 'edit' ),
		'line'        => $line,
		'cats'        => $cats,
		'is_new'      => $p->is_featured(),
		'status'      => $p->get_status( 'edit' ),
		'short'       => $p->get_short_description( 'edit' ),
		'description' => bk_gp_desc_for_editor( $p->get_description( 'edit' ) ),
		'all_cats'    => array_map( 'intval', $p->get_category_ids( 'edit' ) ),
		'regular'     => $first[0],
		'sale'        => $first[1] ?? '',
		'mixed'       => count( array_unique( $prices ) ) > 1,
		'sizes'       => (object) $sizes,
		'images'      => array_map( $img, $images ),
		'video'       => $video && get_post( $video ) ? $img( $video ) : null,
		'url'         => $p->get_permalink(),
		'edit_url'    => get_edit_post_link( $p->get_id(), 'raw' ),
	);
}

/**
 * GET ficha. Con ?twin=1 devuelve una base para crear el mismo modelo en la otra línea
 * (textos, precio, prenda equivalente, REF con/sin " PLUS"; sin fotos ni stock: son otras fotos y otras tallas).
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gp_get( WP_REST_Request $r ) {
	$p = wc_get_product( (int) $r['id'] );
	if ( ! $p || $p->is_type( 'variation' ) ) {
		return new WP_Error( 'bk_not_found', 'Producto no encontrado.', array( 'status' => 404 ) );
	}
	$d = bk_gp_data( $p );
	if ( ! rest_sanitize_boolean( $r['twin'] ) ) {
		return rest_ensure_response( $d );
	}
	$lines = (array) bk_config( 'lines' );
	$other = array_values( array_diff( array_keys( $lines ), array( $d['line'] ) ) )[0] ?? '';
	$cats  = array();
	foreach ( $d['cats'] as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		$m = $t instanceof WP_Term ? bk_gp_match( $t, $other ) : null;
		if ( $m ) {
			$cats[] = $m->term_id;
		}
	}
	$base = trim( preg_replace( '/\s*plus$/i', '', $d['sku'] ) );
	return rest_ensure_response(
		array_merge(
			$d,
			array(
				'id'     => 0,
				'from'   => $d['id'],
				'line'   => $other,
				'cats'   => $cats,
				'all_cats' => array_values( array_filter( array_merge( array( bk_gp_term( $other ) ? bk_gp_term( $other )->term_id : 0 ), $cats ) ) ),
				'sku'    => $base . ( $lines[ $other ]['sku_suffix'] ?? '' ),
				'status' => 'draft',
				'sizes'  => (object) array(),
				'images' => array(),
				'video'  => null,
				'mixed'  => false,
				'url'    => '',
			)
		)
	);
}

/* ---------------------------------------------------------------------------
 * Guardar
 * ------------------------------------------------------------------------- */

/**
 * POST crear.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gp_create( WP_REST_Request $r ) {
	return bk_gp_save( null, $r );
}

/**
 * POST guardar.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gp_update( WP_REST_Request $r ) {
	$p = wc_get_product( (int) $r['id'] );
	if ( ! $p || ! $p->is_type( 'variable' ) ) {
		return new WP_Error( 'bk_not_found', 'Este producto no se puede editar aquí (no es variable). Ábrelo en WooCommerce.', array( 'status' => 404 ) );
	}
	return bk_gp_save( $p, $r );
}

/**
 * Error 400 con el campo que lo causa (el formulario lleva el foco ahí).
 *
 * @param string $field Campo.
 * @param string $msg   Mensaje.
 * @return WP_Error
 */
function bk_gp_err( $field, $msg ) {
	return new WP_Error( 'bk_invalid', $msg, array( 'status' => 400, 'field' => $field ) );
}

/**
 * Precio COP: "129.900" → "129900".
 *
 * @param mixed $x Valor.
 * @return string
 */
function bk_gp_price( $x ) {
	$d = preg_replace( '/\D/', '', (string) $x );
	return '' === $d ? '' : (string) (int) $d;
}

/**
 * Crea o guarda la ficha.
 *
 * @param WC_Product_Variable|null $p Producto (null = nuevo).
 * @param WP_REST_Request          $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gp_save( $p, WP_REST_Request $r ) {
	$lines  = (array) bk_config( 'lines' );
	$is_new = ! $p;
	$line   = $is_new ? sanitize_key( (string) $r['line'] ) : bk_gp_line_of( $p );
	$name   = trim( sanitize_text_field( (string) $r['name'] ) );
	$sku    = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $r['sku'] ) ) );
	$status = in_array( $r['status'], array( 'publish', 'draft' ), true ) ? $r['status'] : 'draft';
	$reg    = bk_gp_price( $r['regular'] );
	$sale   = bk_gp_price( $r['sale'] );
	$images = array_values( array_unique( array_filter( array_map( 'absint', (array) $r['images'] ) ) ) );
	$sizes  = is_array( $r['sizes'] ) ? $r['sizes'] : array();
	$prices = $is_new || rest_sanitize_boolean( $r['prices'] );

	// Validación (mismo orden que el formulario).
	if ( mb_strlen( $name ) < 3 ) {
		return bk_gp_err( 'name', 'Escribe el nombre del producto.' );
	}
	if ( '' === $sku ) {
		return bk_gp_err( 'sku', 'Escribe la REF del producto.' );
	}
	$dup = wc_get_product_id_by_sku( $sku );
	if ( $dup && ( $is_new || $dup !== $p->get_id() ) ) {
		return bk_gp_err( 'sku', sprintf( 'La REF %s ya la usa otro producto.', $sku ) );
	}
	if ( ! isset( $lines[ $line ] ) || ! bk_gp_term( $line ) ) {
		return bk_gp_err( 'line', $is_new ? 'Elige la línea (Slim o Curvy).' : 'El producto no está en una línea (Slim o Curvy). Ábrelo en WooCommerce.' );
	}
	$valid_sizes = wp_list_pluck( bk_gp_sizes( $line ), 'slug' );
	$on_sizes    = array();
	foreach ( $sizes as $slug => $s ) {
		$slug = (string) $slug; // "6" llega como clave entera desde JSON.
		if ( in_array( $slug, $valid_sizes, true ) && ! empty( $s['on'] ) ) {
			$on_sizes[ $slug ] = max( 0, (int) ( $s['stock'] ?? 0 ) );
		}
	}
	if ( ! $on_sizes ) {
		return bk_gp_err( 'sizes', 'Activa al menos una talla.' );
	}
	if ( $prices && ( '' === $reg || (int) $reg <= 0 ) ) {
		return bk_gp_err( 'regular', 'Escribe el precio normal.' );
	}
	if ( $prices && '' !== $sale && (int) $sale >= (int) $reg ) {
		return bk_gp_err( 'sale', 'El precio rebajado debe ser menor que el precio normal.' );
	}
	foreach ( $images as $id ) {
		if ( 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return bk_gp_err( 'images', 'Una de las fotos ya no existe. Vuelve a subirla.' );
		}
	}
	if ( 'publish' === $status && ! $images ) {
		return bk_gp_err( 'images', 'Sube al menos una foto para publicar (o guárdalo como borrador).' );
	}

	// Categorías: línea + prendas de la línea + Nuevo; Oferta se sincroniza después de las tallas.
	$lterm = bk_gp_term( $line );
	$keep  = array();
	if ( ! $is_new ) {
		// Conserva categorías que el formulario no gestiona (p. ej. LÍNEA BASIC fuera de la línea, otras).
		$managed = array_merge( array( $lterm->term_id ), wp_list_pluck( bk_gp_children( $line ), 'term_id' ) );
		$keep    = array_diff( $p->get_category_ids(), $managed );
	}
	$cats = array( $lterm->term_id );
	foreach ( array_map( 'absint', (array) $r['cats'] ) as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t instanceof WP_Term && (int) $t->parent === (int) $lterm->term_id ) {
			$cats[] = $t->term_id;
		}
	}

	// Todas las categorías (sección "Todas las categorías" del editor): manda lo que se marcó ahí.
	if ( is_array( $r['all_cats'] ) ) {
		$keep = array();
		foreach ( array_map( 'absint', $r['all_cats'] ) as $cid ) {
			$t = $cid ? get_term( $cid, 'product_cat' ) : null;
			if ( $t instanceof WP_Term ) {
				$keep[] = $t->term_id;
			}
		}
	}

	$was_new = $p ? $p->is_featured() : false;
	$p       = $p ? $p : new WC_Product_Variable();
	try {
		$p->set_sku( $sku );
	} catch ( WC_Data_Exception $e ) {
		return bk_gp_err( 'sku', $e->getMessage() );
	}
	$p->set_name( $name );
	$p->set_status( $status );
	$p->set_short_description( wp_kses_post( (string) $r['short'] ) );
	$p->set_description( bk_gp_clean_html( (string) $r['description'] ) );
	$p->set_category_ids( array_values( array_unique( array_merge( $keep, $cats ) ) ) );
	// El video también va al final de la galería: es el formato que lee BodyCommerce mientras siga activo
	// (buckler-core lo quita de las fotos y lo pinta como video cuando BodyCommerce se apaga).
	$video = absint( $r['video'] );
	$video = $video && 0 === strpos( (string) get_post_mime_type( $video ), 'video/' ) ? $video : 0;
	$p->set_image_id( $images[0] ?? 0 );
	$p->set_gallery_image_ids( array_merge( array_slice( $images, 1 ), $video ? array( $video ) : array() ) );
	if ( $is_new ) {
		$p->set_catalog_visibility( 'visible' );
		$p->set_manage_stock( false ); // El stock vive en cada talla, como en el resto del catálogo.
	}

	// Atributo de tallas: conserva las tallas que ya tenían variación (aunque se desactiven).
	$tax      = $lines[ $line ]['attribute'];
	$existing = array();
	foreach ( $is_new ? array() : $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v ) {
			$slug = (string) ( $v->get_attributes()[ $tax ] ?? '' );
			if ( '' !== $slug ) {
				$existing[ $slug ] = $v;
			}
		}
	}
	// strval: PHP convierte las claves "6", "8"… en enteros.
	$attr_slugs = array_map( 'strval', array_unique( array_merge( array_keys( $existing ), array_keys( $on_sizes ) ) ) );
	$term_ids   = array();
	foreach ( bk_gp_sizes( $line ) as $t ) {
		if ( in_array( $t->slug, $attr_slugs, true ) ) {
			$term_ids[] = $t->term_id;
		}
	}
	$attrs = $p->get_attributes();
	$a     = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
	$a->set_name( $tax );
	$a->set_options( $term_ids );
	$a->set_position( 0 );
	$a->set_visible( true );
	$a->set_variation( true );
	$attrs[ $tax ] = $a;
	$p->set_attributes( $attrs );
	$p->save();
	$pid = $p->get_id();

	// Tallas.
	foreach ( bk_gp_sizes( $line ) as $menu => $t ) {
		$v = $existing[ $t->slug ] ?? null;
		$on = isset( $on_sizes[ $t->slug ] );
		if ( ! $v && ! $on ) {
			continue;
		}
		if ( ! $v ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( array( $tax => $t->slug ) );
		}
		$v->set_menu_order( $menu );
		$v->set_status( $on ? 'publish' : 'private' );
		if ( $on ) {
			$v->set_manage_stock( true );
			$v->set_stock_quantity( $on_sizes[ $t->slug ] );
		}
		if ( $prices || ! $v->get_id() ) {
			$v->set_regular_price( $reg );
			$v->set_sale_price( $sale );
		}
		$v->save();
	}
	WC_Product_Variable::sync( $pid );

	// Nuevo y Oferta sobre el producto recién leído de la BD (tras save() el objeto en memoria de Woo
	// puede traer category_ids desactualizados) y un solo guardado al final.
	// Solo se tocan si cambian (crear, interruptor Nuevo o precio): guardar sin cambios no altera categorías.
	$p     = wc_get_product( $pid );
	$on    = rest_sanitize_boolean( $r['is_new'] );
	$final = $p->get_category_ids();
	if ( $is_new || $on !== $was_new ) {
		$final = bk_gp_new_cats( $final, $on, $line );
	}
	if ( $prices ) {
		$final = bk_gp_sale_cats( $final, $p, $line );
	}
	$p->set_featured( $on );
	$p->set_category_ids( $final );

	// Fotos: alt con el nombre si está vacío (accesibilidad + SEO de imágenes) y adjuntas al producto.
	foreach ( $images as $i => $id ) {
		if ( '' === (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $i ? sprintf( '%s – foto %d', $name, $i + 1 ) : $name );
		}
		if ( ! wp_get_post_parent_id( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'post_parent' => $pid ) );
		}
	}

	// Video.
	if ( $video && ! wp_get_post_parent_id( $video ) ) {
		wp_update_post( array( 'ID' => $video, 'post_parent' => $pid ) );
	}
	if ( $video ) {
		$p->update_meta_data( bk_gp_video_key(), $video );
	} else {
		$p->delete_meta_data( bk_gp_video_key() );
	}
	$p->save();

	delete_transient( 'bk_g_summary' );
	if ( function_exists( 'bk_catalog_flush' ) ) {
		bk_catalog_flush( $p );
	}
	return rest_ensure_response( bk_gp_data( wc_get_product( $pid ) ) );
}

/**
 * Categorías de "Línea nueva" de la línea (padre + hija slim/curvy).
 *
 * @param string $line Slug de la línea.
 * @return int[]
 */
function bk_gp_new_ids( $line ) {
	$parent = bk_gp_term( (string) bk_config( 'new_cat' ) );
	$child  = $parent ? bk_gp_term( bk_config( 'lines' )[ $line ]['new_child'] ?? '' ) : null;
	return array_values( array_filter( array( $parent ? $parent->term_id : 0, $child && (int) $child->parent === (int) $parent->term_id ? $child->term_id : 0 ) ) );
}

/**
 * Categorías con o sin "Línea nueva".
 *
 * @param int[]  $cats Categorías actuales.
 * @param bool   $on   Nuevo.
 * @param string $line Línea.
 * @return int[]
 */
function bk_gp_new_cats( $cats, $on, $line ) {
	$ids  = bk_gp_new_ids( $line );
	$cats = array_diff( array_map( 'intval', $cats ), $ids );
	return array_values( array_unique( $on ? array_merge( $cats, $ids ) : $cats ) );
}

/**
 * "Nuevo" = destacado (lo que pinta la etiqueta) + categorías de Línea nueva. Lo usa la estrella de la lista.
 *
 * @param WC_Product $p  Producto.
 * @param bool       $on Activar.
 */
function bk_gp_set_new( $p, $on ) {
	$p->set_featured( $on );
	$p->set_category_ids( bk_gp_new_cats( $p->get_category_ids(), $on, bk_gp_line_of( $p ) ) );
	$p->save();
}

/**
 * Oferta: con precio rebajado en alguna talla activa → `sale` + la hija equivalente a cada prenda; si no → fuera.
 *
 * @param int[]      $cats Categorías.
 * @param WC_Product $p    Producto (para leer las tallas).
 * @param string     $line Línea.
 * @return int[]
 */
function bk_gp_sale_cats( $cats, $p, $line ) {
	$sale_slug = (string) bk_config( 'sale_cat' );
	$parent    = bk_gp_term( $sale_slug );
	if ( ! $parent ) {
		return array_values( $cats );
	}
	$tree = array_merge( array( $parent->term_id ), wp_list_pluck( bk_gp_children( $sale_slug ), 'term_id' ) );
	$out  = array_diff( array_map( 'intval', $cats ), $tree );
	$on   = false;
	foreach ( $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v && 'private' !== $v->get_status( 'edit' ) && '' !== $v->get_sale_price( 'edit' ) ) {
			$on = true;
			break;
		}
	}
	$lterm = bk_gp_term( $line );
	if ( $on && $lterm ) {
		$out[] = $parent->term_id;
		foreach ( $cats as $cid ) {
			$t = get_term( (int) $cid, 'product_cat' );
			$m = $t instanceof WP_Term && (int) $t->parent === (int) $lterm->term_id ? bk_gp_match( $t, $sale_slug ) : null;
			if ( $m ) {
				$out[] = $m->term_id;
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Descripción del editor: solo formato básico (negrita, cursiva, listas, párrafos, saltos, enlaces).
 *
 * @param string $html HTML.
 * @return string
 */
function bk_gp_clean_html( $html ) {
	$html = wp_kses(
		$html,
		array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h3'     => array(),
			'a'      => array( 'href' => true ),
			'div'    => array(),
		)
	);
	// contenteditable usa <div> por línea en algunos navegadores → párrafos.
	$html = preg_replace( array( '#<div>\s*(<br\s*/?>)?\s*</div>#i', '#<div>#i', '#</div>#i' ), array( '', '<p>', '</p>' ), $html );
	$html = preg_replace( array( '#<(/?)b>#i', '#<(/?)i>#i' ), array( '<$1strong>', '<$1em>' ), $html );
	$html = preg_replace( '#<p>\s*</p>#', '', $html );
	return trim( $html );
}

/* ---------------------------------------------------------------------------
 * Subida de fotos y video
 * ------------------------------------------------------------------------- */

/**
 * Límite de subida (bytes): el menor entre el del servidor y el nuestro.
 *
 * @param string $kind image|video.
 * @return int
 */
function bk_gp_max_size( $kind ) {
	$own = 'video' === $kind ? 80 * MB_IN_BYTES : 15 * MB_IN_BYTES;
	return (int) min( $own, wp_max_upload_size() );
}

/**
 * Sube una foto (jpg/png/webp) o un video (mp4) a la Mediateca.
 * Las fotos JPG/PNG generan sus tamaños en WebP (más livianos) sin afectar otras subidas del sitio.
 *
 * @param WP_REST_Request $r Petición.
 * @return WP_REST_Response|WP_Error
 */
function bk_gp_upload( WP_REST_Request $r ) {
	$files = $r->get_file_params();
	if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
		return new WP_Error( 'bk_upload', 'No llegó el archivo. Si es muy pesado, redúcelo e inténtalo de nuevo.', array( 'status' => 400 ) );
	}
	$f     = $files['file'];
	$mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'mp4|m4v'      => 'video/mp4',
	);
	$check = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], $mimes );
	if ( empty( $check['type'] ) ) {
		return new WP_Error( 'bk_upload', 'Formato no permitido. Usa JPG, PNG o WebP para fotos y MP4 para video.', array( 'status' => 400 ) );
	}
	$kind = 0 === strpos( $check['type'], 'video/' ) ? 'video' : 'image';
	if ( (int) $f['size'] > bk_gp_max_size( $kind ) ) {
		return new WP_Error( 'bk_upload', sprintf( 'El archivo pesa más de %s.', size_format( bk_gp_max_size( $kind ) ) ), array( 'status' => 400 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$webp = static fn( $formats ) => array_merge( (array) $formats, array( 'image/jpeg' => 'image/webp', 'image/png' => 'image/webp' ) );
	if ( 'image' === $kind && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		add_filter( 'image_editor_output_format', $webp );
	}
	$_FILES = array( 'file' => $f ); // phpcs:ignore WordPress.Security.NonceVerification -- REST con nonce wp_rest.
	$id     = media_handle_upload( 'file', 0, array(), array( 'test_form' => false, 'mimes' => $mimes ) );
	remove_filter( 'image_editor_output_format', $webp );

	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'bk_upload', $id->get_error_message(), array( 'status' => 400 ) );
	}
	return rest_ensure_response(
		array(
			'id'   => $id,
			'kind' => $kind,
			'url'  => (string) ( 'video' === $kind ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, 'medium' ) ),
		)
	);
}
