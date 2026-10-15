<?php
/**
 * SEO de las páginas limpias (módulo pages): corrige en el HTML final lo que hoy sale mal desde SEOPress,
 * sin depender de su configuración. Revisión del 2026-10-15 (Gestión → SEO y ADS → SEO técnico):
 *
 *  1. Ficha sin datos estructurados de producto (solo BreadcrumbList) → JSON-LD Product propio
 *     (solo si la página no trae ya uno: nunca se duplica).
 *  2. Tienda con título "Tienda -" y sin descripción → títulos y descripciones propios para la tienda,
 *     las líneas, ¡Sale!, Lo nuevo, categorías y búsqueda.
 *  3. Ficha: "REF: 9912 - CHAQUETA DAMA - Buckler Jenas by Denimblue" → "Chaqueta dama · Línea Curvy · REF 9912 |
 *     Buckler Jeans"; descripciones de ≤ 155 caracteres.
 *  4. Inicio sin imagen para redes (og:image) → primer banner del inicio (o el logo).
 *
 * Respeta lo que se escribió a mano: si un producto, página o categoría tiene título o descripción propios en
 * SEOPress, se conservan (la descripción solo se recorta). Se apaga con add_filter( 'bk_seo_enabled', '__return_false' ).
 * Filtros: bk_seo_title, bk_seo_description, bk_seo_product_schema.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_SEO_MAX_DESC = 155;

/**
 * Nombre de la marca para los títulos (no el del sitio: hoy tiene la errata "Jenas").
 *
 * @return string
 */
function bk_seo_brand() {
	return (string) bk_config( 'brand' );
}

/**
 * Recorta en una palabra completa, sin cortar a mitad.
 *
 * @param string $text Texto.
 * @param int    $max  Máximo de caracteres.
 * @return string
 */
function bk_seo_trim( $text, $max = BK_SEO_MAX_DESC ) {
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 1 );
	$sp  = mb_strrpos( $cut, ' ' );
	$cut = false !== $sp && $sp > $max * 0.6 ? mb_substr( $cut, 0, $sp ) : $cut;
	return rtrim( $cut, " ,.;:-–·" ) . '…';
}

/**
 * Descripción que termina limpia: la frase principal + el mensaje de compra más largo que quepa.
 *
 * @param string $main Frase principal.
 * @return string
 */
function bk_seo_desc( $main ) {
	$main = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $main ) ) );
	foreach ( array( 'Envíos gratis a todo el país y paga en 3 cuotas sin interés con Addi.', 'Envíos gratis a todo el país.', '' ) as $perk ) {
		$text = trim( $main . ' ' . $perk );
		if ( mb_strlen( $text ) <= BK_SEO_MAX_DESC ) {
			return $text;
		}
	}
	return bk_seo_trim( $main );
}

/**
 * Título / descripción escritos a mano en SEOPress para un post o término.
 *
 * @param string          $what  title|desc.
 * @param int|WP_Term|null $obj  Post ID o término.
 * @return string
 */
function bk_seo_manual( $what, $obj ) {
	$key = 'title' === $what ? '_seopress_titles_title' : '_seopress_titles_desc';
	if ( $obj instanceof WP_Term ) {
		return trim( (string) get_term_meta( $obj->term_id, $key, true ) );
	}
	return $obj ? trim( (string) get_post_meta( (int) $obj, $key, true ) ) : '';
}

/**
 * Título y descripción de la ruta actual. Vacío = no se toca.
 *
 * @param string $route Ruta de buckler-core.
 * @return array{title:string,desc:string,image:string,keep_title:bool,keep_desc:bool}
 */
function bk_seo_meta( $route ) {
	$brand = bk_seo_brand();
	$lines = (array) bk_config( 'lines' );
	$out   = array( 'title' => '', 'desc' => '', 'image' => '', 'keep_title' => false, 'keep_desc' => false );

	if ( 'product' === $route && is_singular( 'product' ) ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( ! $p ) {
			return $out;
		}
		$line  = bk_product_line( $p->get_id() );
		$name  = bk_nice_label( $p->get_name() );
		$sku   = trim( (string) $p->get_sku() );
		$parts = array_filter( array( $name, $line ? $line['label'] : '' ) );
		$title = implode( ' · ', $parts );
		if ( $sku && mb_strlen( $title . ' · REF ' . $sku . ' | ' . $brand ) <= 70 ) {
			$title .= ' · REF ' . $sku;
		}
		$out['title']      = $title . ' | ' . $brand;
		$out['keep_title'] = '' !== bk_seo_manual( 'title', $p->get_id() );
		$manual            = bk_seo_manual( 'desc', $p->get_id() );
		if ( '' !== $manual ) {
			$out['desc'] = bk_seo_trim( $manual );
		} else {
			$sizes = $line ? ( $lines[ $line['slug'] ]['sizes'] ?? '' ) : '';
			$intro = $name . ( $line ? ' de la ' . $line['label'] : '' ) . ( $sizes ? ', ' . mb_strtolower( $sizes ) : '' ) . '. ';
			$body  = $p->get_short_description() ? $p->get_short_description() : $p->get_description();
			$body        = trim( wp_strip_all_tags( $body ) );
			$out['desc'] = '' !== $body ? bk_seo_trim( $intro . $body ) : bk_seo_desc( $intro );
		}
		return $out;
	}

	if ( 'shop' === $route ) {
		$prenda = isset( $_GET['prenda'] ) ? sanitize_title( wp_unslash( $_GET['prenda'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_search() ) {
			/* translators: %s: búsqueda */
			$out['title'] = sprintf( __( 'Resultados para "%s"', 'buckler-core' ), get_search_query( false ) ) . ' | ' . $brand;
			$out['desc']  = bk_seo_desc( sprintf( 'Prendas en denim para mujer que coinciden con "%s".', get_search_query( false ) ) );
			return $out;
		}
		if ( is_product_category() ) {
			$t     = get_queried_object();
			$anc   = get_ancestors( $t->term_id, 'product_cat' );
			$root  = $anc ? get_term( (int) end( $anc ), 'product_cat' ) : $t;
			$line  = $root && isset( $lines[ $root->slug ] ) ? $lines[ $root->slug ] : null;
			$label = isset( $lines[ $t->slug ] ) ? $lines[ $t->slug ]['label'] : bk_nice_label( $t->name );
			$out['title']      = $label . ( $line && $t->parent ? ' · ' . $line['label'] : '' ) . ' para mujer | ' . $brand;
			$out['keep_title'] = '' !== bk_seo_manual( 'title', $t );
			$manual            = bk_seo_manual( 'desc', $t );
			$term_desc         = trim( wp_strip_all_tags( term_description( $t ) ) );
			$out['desc']       = $manual || $term_desc ? bk_seo_trim( $manual ? $manual : $term_desc ) : bk_seo_desc( sprintf( '%s para mujer%s, en denim colombiano.', $label, $line && $t->parent ? ' de la ' . $line['label'] . ' (' . mb_strtolower( $line['sizes'] ) . ')' : '' ) );
			return $out;
		}
		if ( ! empty( $_GET['nuevo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$out['title'] = 'Lo nuevo en jeans y denim para mujer | ' . $brand;
			$out['desc']  = bk_seo_desc( 'Lo último de Buckler Jeans: jeans, faldas y chaquetas en denim para mujer, en Línea Slim y Curvy.' );
			return $out;
		}
		$page_id = is_page() ? get_queried_object_id() : (int) wc_get_page_id( 'shop' );
		$path    = $page_id ? trailingslashit( (string) wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ) ) : '';
		$out['keep_title'] = '' !== bk_seo_manual( 'title', $page_id );
		$manual            = bk_seo_manual( 'desc', $page_id );
		foreach ( $lines as $slug => $l ) {
			if ( empty( $l['page'] ) || trailingslashit( $l['page'] ) !== $path ) {
				continue;
			}
			$curvy = false !== strpos( $slug, 'curvy' );
			$name  = '';
			if ( $prenda ) {
				$root = get_term_by( 'slug', $slug, 'product_cat' );
				foreach ( $root ? (array) get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $root->term_id, 'hide_empty' => false ) ) : array() as $c ) {
					if ( bk_prenda_key( $c->name ) === $prenda ) {
						$name = bk_nice_label( $c->name );
						break;
					}
				}
			}
			$what          = $name ? $name : ( $curvy ? 'Jeans plus size' : 'Jeans y denim' );
			$out['title']  = $l['label'] . ': ' . mb_strtolower( $what ) . ' para mujer, ' . mb_strtolower( $l['sizes'] ) . ' | ' . $brand;
			if ( mb_strlen( $out['title'] ) > 70 ) {
				$out['title'] = $l['label'] . ': ' . mb_strtolower( $what ) . ', ' . mb_strtolower( $l['sizes'] ) . ' | ' . $brand;
			}
			$out['desc']   = $manual ? bk_seo_trim( $manual ) : bk_seo_desc(
				sprintf(
					'%s: %s para mujer, %s.',
					$l['label'],
					$name ? mb_strtolower( $name ) : ( $curvy ? 'jeans, faldas y chaquetas plus size' : 'jeans, faldas y chaquetas en denim' ),
					mb_strtolower( $l['sizes'] )
				)
			);
			return $out;
		}
		$sale = (string) bk_config( 'sale_page' );
		if ( $sale && trailingslashit( $sale ) === $path ) {
			$out['title'] = '¡Sale! Jeans y denim para mujer en oferta | ' . $brand;
			$out['desc']  = $manual ? bk_seo_trim( $manual ) : bk_seo_desc( 'Jeans, faldas y chaquetas en denim para mujer con descuento, en Línea Slim (6 a 12) y Curvy (14 a 24).' );
			return $out;
		}
		$out['title'] = 'Tienda de jeans y ropa en denim para mujer | ' . $brand;
		$out['desc']  = $manual ? bk_seo_trim( $manual ) : bk_seo_desc( 'Jeans, faldas, sets y chaquetas en denim para mujer: Línea Slim (tallas 6 a 12) y Línea Curvy (14 a 24).' );
		return $out;
	}

	if ( 'home' === $route ) {
		$out['image'] = bk_seo_home_image();
	}
	return $out;
}

/**
 * Imagen para redes del inicio: primer banner (versión PC) → banner de línea → logo.
 *
 * @return string
 */
function bk_seo_home_image() {
	$rows = function_exists( 'bk_option_field' ) ? bk_option_field( 'banners_pagina_inicio' ) : null;
	$id   = 0;
	if ( is_array( $rows ) && $rows ) {
		$map = bk_hero_subfield_map();
		$row = reset( $rows );
		$id  = bk_image_id( $row[ $map['pc'] ] ?? ( $row[ $map['mo'] ] ?? null ) );
	}
	if ( ! $id && function_exists( 'bk_option_field' ) ) {
		$id = bk_image_id( bk_option_field( 'linea_slim_pc' ) );
	}
	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( $src ) {
			return (string) $src[0];
		}
	}
	$logo = (string) bk_config( 'logo_dark' );
	return $logo ? home_url( $logo ) : '';
}

/**
 * JSON-LD Product de la ficha (precio, REF, disponibilidad, marca, fotos, envío gratis).
 * Sin calificaciones: las reseñas de Google son de la tienda, no del producto (Google no permite mezclarlas).
 *
 * @param WC_Product $p Producto.
 * @return array
 */
function bk_seo_product_schema( $p ) {
	$brand = bk_seo_brand();
	$line  = bk_product_line( $p->get_id() );
	$imgs  = array();
	foreach ( array_slice( array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ), 'wp_attachment_is_image' ), 0, 6 ) as $id ) {
		$u = wp_get_attachment_image_url( $id, 'woocommerce_single' );
		if ( $u ) {
			$imgs[] = $u;
		}
	}
	$currency = get_woocommerce_currency();
	$prices   = array();
	$in       = false;
	$until    = '';
	$children = $p->is_type( 'variable' ) ? $p->get_visible_children() : array( $p->get_id() );
	foreach ( $children as $cid ) {
		$v = $cid === $p->get_id() ? $p : wc_get_product( $cid );
		if ( ! $v || '' === (string) $v->get_price() ) {
			continue;
		}
		$prices[] = (float) $v->get_price();
		$in       = $in || $v->is_in_stock();
		if ( $v->is_on_sale() && $v->get_date_on_sale_to() ) {
			$until = $v->get_date_on_sale_to()->date( 'Y-m-d' );
		}
	}
	$avail    = $in ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
	$shipping = array(
		'@type'               => 'OfferShippingDetails',
		'shippingRate'        => array( '@type' => 'MonetaryAmount', 'value' => 0, 'currency' => $currency ),
		'shippingDestination' => array( '@type' => 'DefinedRegion', 'addressCountry' => 'CO' ),
	);
	$seller   = array( '@type' => 'Organization', 'name' => $brand, 'url' => home_url( '/' ) );
	if ( ! $prices ) {
		$offers = null;
	} elseif ( min( $prices ) === max( $prices ) ) {
		$offers = array_filter(
			array(
				'@type'           => 'Offer',
				'url'             => $p->get_permalink(),
				'price'           => wc_format_decimal( min( $prices ), wc_get_price_decimals() ),
				'priceCurrency'   => $currency,
				'availability'    => $avail,
				'itemCondition'   => 'https://schema.org/NewCondition',
				'priceValidUntil' => $until,
				'shippingDetails' => $shipping,
				'seller'          => $seller,
			)
		);
	} else {
		$offers = array(
			'@type'         => 'AggregateOffer',
			'url'           => $p->get_permalink(),
			'lowPrice'      => wc_format_decimal( min( $prices ), wc_get_price_decimals() ),
			'highPrice'     => wc_format_decimal( max( $prices ), wc_get_price_decimals() ),
			'offerCount'    => count( $prices ),
			'priceCurrency' => $currency,
			'availability'  => $avail,
			'seller'        => $seller,
		);
	}
	$desc = wp_strip_all_tags( $p->get_short_description() ? $p->get_short_description() : $p->get_description() );
	$data = array_filter(
		array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'@id'         => $p->get_permalink() . '#product',
			'name'        => bk_nice_label( $p->get_name() ) . ( $line ? ' · ' . $line['label'] : '' ),
			'url'         => $p->get_permalink(),
			'sku'         => (string) $p->get_sku(),
			'mpn'         => (string) $p->get_sku(),
			'image'       => $imgs,
			'description' => bk_seo_trim( $desc, 500 ),
			'brand'       => array( '@type' => 'Brand', 'name' => $brand ),
			'category'    => $line ? 'Ropa de mujer > ' . $line['label'] : 'Ropa de mujer',
			'audience'    => array( '@type' => 'PeopleAudience', 'suggestedGender' => 'female' ),
			'offers'      => $offers,
		)
	);
	return (array) apply_filters( 'bk_seo_product_schema', $data, $p );
}

/**
 * Reemplaza o inserta una etiqueta <meta> en el <head>.
 *
 * @param string $head  HTML del head.
 * @param string $attr  name|property.
 * @param string $key   Nombre.
 * @param string $value Contenido.
 * @param bool   $only_missing Solo si no existe.
 * @return string
 */
function bk_seo_set_meta( $head, $attr, $key, $value, $only_missing = false ) {
	$tag = '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( $value ) . '">';
	$re  = '#<meta\b[^>]*\b' . $attr . '=(["\'])' . preg_quote( $key, '#' ) . '\1[^>]*>\s*#i';
	if ( preg_match( $re, $head ) ) {
		return $only_missing ? $head : (string) preg_replace_callback( $re, static fn() => $tag . "\n", $head, 1 );
	}
	return (string) preg_replace_callback( '#</title>#i', static fn() => "</title>\n" . $tag, $head, 1 );
}

/**
 * Aplica el SEO al documento completo.
 *
 * @param string $html  Documento.
 * @param string $route Ruta.
 * @return string
 */
function bk_seo_apply( $html, $route ) {
	if ( ! apply_filters( 'bk_seo_enabled', true ) || ! $html || ! preg_match( '#^(.*?</head>)#is', $html, $hm ) ) {
		return $html;
	}
	$head = $hm[1];
	$orig = $head;
	$m    = bk_seo_meta( $route );
	$m['title'] = (string) apply_filters( 'bk_seo_title', $m['title'], $route );
	$m['desc']  = (string) apply_filters( 'bk_seo_description', $m['desc'], $route );

	// Errata del nombre del sitio ("Buckler Jenas") en títulos y og:site_name mientras se corrige en Ajustes → Generales.
	$head = str_replace( array( 'Buckler Jenas', 'Buckler jenas' ), 'Buckler Jeans', $head );

	if ( $m['title'] && ! $m['keep_title'] ) {
		$t    = esc_html( $m['title'] );
		$head = preg_match( '#<title\b[^>]*>.*?</title>#is', $head )
			? (string) preg_replace_callback( '#<title\b[^>]*>.*?</title>#is', static fn() => '<title>' . $t . '</title>', $head, 1 )
			: (string) preg_replace_callback( '#</head>#i', static fn() => '<title>' . $t . "</title>\n</head>", $head, 1 );
		$head = bk_seo_set_meta( $head, 'property', 'og:title', $m['title'] );
		$head = bk_seo_set_meta( $head, 'name', 'twitter:title', $m['title'], true );
	}
	if ( $m['desc'] ) {
		$head = bk_seo_set_meta( $head, 'name', 'description', $m['desc'] );
		$head = bk_seo_set_meta( $head, 'property', 'og:description', $m['desc'] );
	}
	if ( $m['image'] && ! preg_match( '#property=(["\'])og:image\1#i', $head ) ) {
		$head = bk_seo_set_meta( $head, 'property', 'og:image', $m['image'] );
		$head = bk_seo_set_meta( $head, 'name', 'twitter:card', 'summary_large_image', true );
	}
	if ( 'product' === $route && is_singular( 'product' ) && ! preg_match( '#"@type"\s*:\s*"Product"#', $html ) ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			$json = wp_json_encode( bk_seo_product_schema( $p ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
			$head = (string) preg_replace_callback( '#</head>#i', static fn() => '<script type="application/ld+json" id="bk-product-schema">' . $json . "</script>\n</head>", $head, 1 );
		}
	}
	return $head === $orig ? $html : $head . substr( $html, strlen( $orig ) );
}
