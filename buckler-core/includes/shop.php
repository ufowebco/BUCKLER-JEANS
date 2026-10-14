<?php
/**
 * Módulo "shop": tienda con filtros propios — [bk_tienda].
 *
 * Reemplaza Divi Machine, Divi Ajax Filter, Themify Product Filter y filtrado-productos-ufo.
 *
 * Uso (Theme Builder, módulo Texto en el cuerpo de cada plantilla):
 *   Tienda:            [bk_tienda]
 *   Categorías:        [bk_tienda]                       (toma la categoría actual)
 *   Línea Slim/Curvy:  [bk_tienda categoria="linea-slim"]
 *   ¡Sale!:            [bk_tienda oferta="1" titulo="¡Sale!"]
 *   Búsqueda:          [bk_tienda]                       (toma el término buscado)
 *
 * Cómo funciona:
 * - "Universo": los productos visibles y con stock del contexto, con línea, prenda, tallas en stock, precio,
 *   fecha, ventas y oferta. Se calcula una vez y se cachea (se invalida al cambiar productos o stock).
 * - Filtrar, contar opciones (facetas), ordenar y paginar se hace en PHP sobre ese universo: muy rápido.
 * - Los filtros son un formulario GET normal (funciona sin JS); el JS solo evita recargar la página.
 * - Las URL con filtros llevan noindex,follow (no generan contenido duplicado para Google).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'bk_tienda', 'bk_shop_shortcode' );
add_action( 'wp_enqueue_scripts', 'bk_shop_assets', 25 );
add_filter( 'wp_robots', 'bk_shop_robots' );

const BK_SHOP_PARAMS = array( 'linea', 'prenda', 'talla', 'precio', 'oferta', 'orden', 'pg' );
const BK_SHOP_PER    = 24;

/**
 * CSS/JS de la tienda.
 */
function bk_shop_assets() {
	if ( ! ( is_shop() || is_product_taxonomy() || is_search() || is_page() ) ) {
		return;
	}
	wp_enqueue_style( 'bk-shop', BK_URL . 'assets/css/shop.css', array( 'bk-core' ), bk_asset_ver( 'assets/css/shop.css' ) );
	wp_enqueue_script( 'bk-shop', BK_URL . 'assets/js/shop.js', array( 'bk-core' ), bk_asset_ver( 'assets/js/shop.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
}

/**
 * noindex,follow en URL con filtros u orden (la paginación sí se indexa).
 *
 * @param array $robots Directivas.
 * @return array
 */
function bk_shop_robots( $robots ) {
	foreach ( array( 'linea', 'prenda', 'talla', 'precio', 'oferta', 'orden' ) as $p ) {
		if ( isset( $_GET[ $p ] ) && '' !== $_GET[ $p ] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
			break;
		}
	}
	return $robots;
}

/**
 * Lee un parámetro GET como lista de slugs.
 *
 * @param string $key Parámetro.
 * @return string[]
 */
function bk_shop_param( $key ) {
	$v = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$v = is_array( $v ) ? $v : explode( ',', (string) $v );
	return array_values( array_filter( array_map( 'sanitize_title', $v ) ) );
}

/**
 * Rangos de precio (configurables con el filtro `bk_shop_price_buckets`).
 *
 * @return array<string,array{min:float,max:float,label:string}>
 */
function bk_shop_price_buckets() {
	$fmt     = static fn( $n ) => html_entity_decode( wp_strip_all_tags( wc_price( $n ) ), ENT_QUOTES, 'UTF-8' );
	$buckets = apply_filters( 'bk_shop_price_buckets', array( 120000, 150000 ) );
	$out     = array();
	$prev    = 0;
	foreach ( $buckets as $b ) {
		/* translators: %s: precio */
		$out[ $prev . '-' . $b ] = array( 'min' => $prev, 'max' => $b, 'label' => $prev ? $fmt( $prev ) . ' a ' . $fmt( $b ) : sprintf( __( 'Hasta %s', 'buckler-core' ), $fmt( $b ) ) );
		$prev                    = $b;
	}
	/* translators: %s: precio */
	$out[ $prev . '-' ] = array( 'min' => $prev, 'max' => 0, 'label' => sprintf( __( 'Más de %s', 'buckler-core' ), $fmt( $prev ) ) );
	return $out;
}

/**
 * Contexto de la tienda según la página y los atributos del shortcode.
 *
 * @param array $atts Atributos.
 * @return array
 */
function bk_shop_context( $atts ) {
	$ctx = array(
		'cats'     => array(),
		'search'   => '',
		'sale'     => (bool) $atts['oferta'],
		'line'     => '',
		'title'    => $atts['titulo'],
		'desc'     => '',
		'base_url' => '',
	);
	$lines = (array) bk_config( 'lines' );

	if ( $atts['categoria'] ) {
		foreach ( explode( ',', $atts['categoria'] ) as $slug ) {
			$t = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
			if ( $t ) {
				$ctx['cats'][] = (int) $t->term_id;
				if ( isset( $lines[ $t->slug ] ) ) {
					$ctx['line']  = $t->slug;
					$ctx['title'] = $ctx['title'] ? $ctx['title'] : $lines[ $t->slug ]['label'];
				}
			}
		}
		$ctx['base_url'] = get_permalink();
	} elseif ( is_product_category() ) {
		$t                = get_queried_object();
		$ctx['cats']      = array( (int) $t->term_id );
		$ctx['title']     = $ctx['title'] ? $ctx['title'] : bk_nice_label( $t->name );
		$ctx['desc']      = term_description( $t );
		$ctx['base_url']  = get_term_link( $t );
		$ancestors        = get_ancestors( $t->term_id, 'product_cat' );
		$root             = $ancestors ? get_term( (int) end( $ancestors ), 'product_cat' ) : $t;
		$ctx['line']      = ( $root && isset( $lines[ $root->slug ] ) ) ? $root->slug : '';
		if ( $t->parent && $ctx['line'] ) {
			$ctx['title'] = bk_nice_label( $t->name ) . ' · ' . $lines[ $ctx['line'] ]['label'];
		}
	} elseif ( is_search() ) {
		$ctx['search'] = get_search_query( false );
		/* translators: %s: búsqueda */
		$ctx['title']    = sprintf( __( 'Resultados para "%s"', 'buckler-core' ), $ctx['search'] );
		$ctx['base_url'] = add_query_arg( array( 's' => rawurlencode( $ctx['search'] ), 'post_type' => 'product' ), home_url( '/' ) );
	} else {
		$ctx['title']    = $ctx['title'] ? $ctx['title'] : __( 'Tienda', 'buckler-core' );
		$ctx['base_url'] = is_page() ? get_permalink() : wc_get_page_permalink( 'shop' );
	}
	return $ctx;
}

/**
 * Normaliza el nombre de una prenda para agrupar las categorías repetidas por línea
 * ("SET Y OVEROL" en Slim y "SETS Y OVEROL" en Curvy → misma opción).
 *
 * @param string $name Nombre.
 * @return string
 */
function bk_shop_prenda_key( $name ) {
	return preg_replace( '/^sets-/', 'set-', sanitize_title( remove_accents( $name ) ) );
}

/**
 * Universo de productos del contexto (cacheado).
 *
 * @param array $ctx Contexto.
 * @return array{items:array,prendas:array,sizes:array}
 */
function bk_shop_universe( $ctx ) {
	global $wpdb;
	$ver = (string) get_option( 'bk_catalog_v', '1' );
	$key = 'bk_shop_' . md5( wp_json_encode( array( $ctx['cats'], $ctx['search'], $ctx['sale'], $ver ) ) );
	$u   = get_transient( $key );
	if ( is_array( $u ) ) {
		return $u;
	}

	// Candidatos por SQL (tipo, estado, categoría, búsqueda, visibilidad). Precio, oferta y stock se
	// leen del producto: la tabla wc_product_meta_lookup no es fiable para variables (min_price 0, onsale 0).
	$where = array( "p.post_type = 'product'", "p.post_status = 'publish'" );
	if ( $ctx['cats'] ) {
		$cats = array();
		foreach ( $ctx['cats'] as $c ) {
			$cats = array_merge( $cats, array( $c ), get_term_children( $c, 'product_cat' ) );
		}
		$tt      = array_map( 'intval', wp_list_pluck( array_filter( array_map( static fn( $id ) => get_term( $id, 'product_cat' ), $cats ) ), 'term_taxonomy_id' ) );
		$where[] = "p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', $tt ?: array( 0 ) ) . '))';
	}
	$on_sale = array_map( 'intval', wc_get_product_ids_on_sale() ); // Incluye padres de variaciones en oferta.
	if ( $ctx['sale'] ) {
		$where[] = 'p.ID IN (' . implode( ',', $on_sale ?: array( 0 ) ) . ')';
	}
	if ( '' !== $ctx['search'] ) {
		$like    = '%' . $wpdb->esc_like( $ctx['search'] ) . '%';
		$where[] = $wpdb->prepare( "(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s))", $like, $like, $like );
	}
	$hidden = get_term_by( 'slug', '' !== $ctx['search'] ? 'exclude-from-search' : 'exclude-from-catalog', 'product_visibility' );
	if ( $hidden ) {
		$where[] = "p.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = " . (int) $hidden->term_taxonomy_id . ')';
	}
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
		"SELECT p.ID AS id, UNIX_TIMESTAMP(p.post_date_gmt) AS ts FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ),
		ARRAY_A
	);
	$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );

	// Categorías de todos los productos en una sola consulta.
	$lines     = (array) bk_config( 'lines' );
	$line_ids  = array();
	foreach ( array_keys( $lines ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			$line_ids[ (int) $t->term_id ] = $slug;
		}
	}
	$by_product = array();
	$prendas    = array();
	if ( $ids ) {
		foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'object_ids' => $ids, 'fields' => 'all_with_object_id', 'hide_empty' => false ) ) as $t ) {
			$oid = (int) $t->object_id;
			if ( isset( $line_ids[ $t->term_id ] ) ) {
				$by_product[ $oid ]['line'] = $line_ids[ $t->term_id ];
			} elseif ( isset( $line_ids[ $t->parent ] ) ) {
				$k                              = bk_shop_prenda_key( $t->name );
				$by_product[ $oid ]['prenda'][] = $k;
				$prendas[ $k ]                  = $prendas[ $k ] ?? bk_nice_label( $t->name );
			}
		}
	}

	// Tallas en stock (precarga los productos en un solo lote).
	$products = $ids ? wc_get_products( array( 'include' => $ids, 'limit' => -1, 'status' => 'publish' ) ) : array();
	$sizes    = array();
	$psizes   = array();
	foreach ( $products as $p ) {
		foreach ( bk_product_sizes( $p ) as $z ) {
			if ( $z['in'] ) {
				$psizes[ $p->get_id() ][] = $z['label'];
				$sizes[ $z['label'] ]     = true;
			}
		}
	}
	$sizes = array_keys( $sizes );
	usort( $sizes, static fn( $a, $b ) => is_numeric( $a ) && is_numeric( $b ) ? (float) $a <=> (float) $b : strnatcasecmp( $a, $b ) );

	$items = array();
	$byid  = array();
	foreach ( $products as $p ) {
		$byid[ $p->get_id() ] = $p;
	}
	foreach ( $rows as $r ) {
		$id = (int) $r['id'];
		$p  = $byid[ $id ] ?? null;
		if ( ! $p || ! $p->is_in_stock() || ! $p->is_visible() ) {
			continue; // Agotados fuera de la tienda.
		}
		$items[] = array(
			'id'     => $id,
			'price'  => (float) ( $p->is_type( 'variable' ) ? $p->get_variation_price( 'min', true ) : wc_get_price_to_display( $p ) ),
			'sale'   => in_array( $id, $on_sale, true ),
			'sales'  => (int) $p->get_total_sales(),
			'ts'     => (int) $r['ts'],
			'line'   => $by_product[ $id ]['line'] ?? '',
			'prenda' => array_values( array_unique( $by_product[ $id ]['prenda'] ?? array() ) ),
			'sizes'  => array_map( 'strval', $psizes[ $id ] ?? array() ),
		);
	}
	$u = array(
		'items'   => $items,
		'prendas' => $prendas,
		'sizes'   => array_map( 'strval', $sizes ),
	);
	set_transient( $key, $u, 30 * MINUTE_IN_SECONDS );
	return $u;
}

/**
 * ¿Un producto cumple los filtros? ($skip = faceta que no se aplica, para contar sus opciones).
 *
 * @param array  $it   Producto del universo.
 * @param array  $f    Filtros.
 * @param string $skip Faceta a ignorar.
 * @return bool
 */
function bk_shop_match( $it, $f, $skip = '' ) {
	if ( 'linea' !== $skip && $f['linea'] && $it['line'] !== $f['linea'] ) {
		return false;
	}
	if ( 'prenda' !== $skip && $f['prenda'] && ! array_intersect( $f['prenda'], $it['prenda'] ) ) {
		return false;
	}
	if ( 'talla' !== $skip && $f['talla'] && ! array_intersect( $f['talla'], $it['sizes'] ) ) {
		return false;
	}
	if ( 'precio' !== $skip && $f['precio'] ) {
		if ( $it['price'] < $f['precio']['min'] || ( $f['precio']['max'] && $it['price'] >= $f['precio']['max'] ) ) {
			return false;
		}
	}
	if ( 'oferta' !== $skip && $f['oferta'] && ! $it['sale'] ) {
		return false;
	}
	return true;
}

/**
 * URL de la tienda con parámetros (sin vacíos).
 *
 * @param string $base   Base.
 * @param array  $params Parámetros.
 * @return string
 */
function bk_shop_url( $base, $params ) {
	$params = array_filter(
		$params,
		static fn( $v ) => is_array( $v ) ? (bool) $v : ( '' !== (string) $v && '0' !== (string) $v )
	);
	foreach ( $params as $k => $v ) {
		if ( is_array( $v ) ) {
			$params[ $k ] = implode( ',', $v );
		}
	}
	return add_query_arg( array_map( 'rawurlencode', $params ), $base );
}

/**
 * Shortcode [bk_tienda].
 *
 * @param array $atts Atributos.
 * @return string
 */
function bk_shop_shortcode( $atts ) {
	$atts    = shortcode_atts(
		array(
			'categoria' => '',
			'oferta'    => 0,
			'titulo'    => '',
			'h1'        => 1,
			'columnas'  => 4,
		),
		$atts,
		'bk_tienda'
	);
	$ctx     = bk_shop_context( $atts );
	$u       = bk_shop_universe( $ctx );
	$buckets = bk_shop_price_buckets();
	$lines   = (array) bk_config( 'lines' );

	// Filtros pedidos (validados contra las opciones reales).
	$linea = bk_shop_param( 'linea' );
	$f     = array(
		'linea'  => $ctx['line'] ? '' : ( isset( $lines[ 'linea-' . ( $linea[0] ?? '' ) ] ) ? 'linea-' . $linea[0] : '' ),
		'prenda' => array_values( array_intersect( bk_shop_param( 'prenda' ), array_keys( $u['prendas'] ) ) ),
		'talla'  => array_values( array_intersect( bk_shop_param( 'talla' ), $u['sizes'] ) ),
		'precio' => null,
		'oferta' => ! $ctx['sale'] && ! empty( $_GET['oferta'] ), // phpcs:ignore WordPress.Security.NonceVerification
	);
	$precio_key = sanitize_text_field( wp_unslash( $_GET['precio'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( isset( $buckets[ $precio_key ] ) ) {
		$f['precio'] = $buckets[ $precio_key ];
	}
	$orden = sanitize_key( $_GET['orden'] ?? 'nuevo' ); // phpcs:ignore WordPress.Security.NonceVerification
	$orden = in_array( $orden, array( 'nuevo', 'popular', 'precio-asc', 'precio-desc' ), true ) ? $orden : 'nuevo';
	$pg    = max( 1, absint( $_GET['pg'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification

	// Resultados.
	$hits = array_values( array_filter( $u['items'], static fn( $it ) => bk_shop_match( $it, $f ) ) );
	usort(
		$hits,
		static function ( $a, $b ) use ( $orden ) {
			switch ( $orden ) {
				case 'popular':
					return $b['sales'] <=> $a['sales'] ?: $b['ts'] <=> $a['ts'];
				case 'precio-asc':
					return $a['price'] <=> $b['price'];
				case 'precio-desc':
					return $b['price'] <=> $a['price'];
				default:
					return $b['ts'] <=> $a['ts'];
			}
		}
	);
	$total = count( $hits );
	$shown = min( $total, $pg * BK_SHOP_PER );
	$page  = array_slice( $hits, ( $pg - 1 ) * BK_SHOP_PER, BK_SHOP_PER );

	// Conteos por opción (facetas).
	$count = static function ( $facet, $test ) use ( $u, $f ) {
		$n = 0;
		foreach ( $u['items'] as $it ) {
			if ( bk_shop_match( $it, $f, $facet ) && $test( $it ) ) {
				++$n;
			}
		}
		return $n;
	};

	$state  = array(
		'linea'  => $f['linea'] ? str_replace( 'linea-', '', $f['linea'] ) : '',
		'prenda' => $f['prenda'],
		'talla'  => $f['talla'],
		'precio' => $f['precio'] ? $precio_key : '',
		'oferta' => $f['oferta'] ? '1' : '',
		'orden'  => 'nuevo' === $orden ? '' : $orden,
	);
	$base   = $ctx['base_url'];
	$active = array();
	if ( $state['linea'] ) {
		$active[] = array( $lines[ $f['linea'] ]['label'], bk_shop_url( $base, array_merge( $state, array( 'linea' => '' ) ) ) );
	}
	foreach ( $f['prenda'] as $pr ) {
		$active[] = array( $u['prendas'][ $pr ], bk_shop_url( $base, array_merge( $state, array( 'prenda' => array_values( array_diff( $f['prenda'], array( $pr ) ) ) ) ) ) );
	}
	foreach ( $f['talla'] as $tl ) {
		/* translators: %s: talla */
		$active[] = array( sprintf( __( 'Talla %s', 'buckler-core' ), $tl ), bk_shop_url( $base, array_merge( $state, array( 'talla' => array_values( array_diff( $f['talla'], array( $tl ) ) ) ) ) ) );
	}
	if ( $f['precio'] ) {
		$active[] = array( $f['precio']['label'], bk_shop_url( $base, array_merge( $state, array( 'precio' => '' ) ) ) );
	}
	if ( $f['oferta'] ) {
		$active[] = array( __( 'En descuento', 'buckler-core' ), bk_shop_url( $base, array_merge( $state, array( 'oferta' => '' ) ) ) );
	}
	$n_active = count( $active );
	$uid      = 'bk-shop';

	ob_start();
	?>
	<div class="bk-shop" data-bk-shop data-total="<?php echo (int) $total; ?>">
		<div class="bk-shop__head">
			<?php if ( $atts['h1'] ) : ?>
				<h1 class="bk-shop__title"><?php echo esc_html( $ctx['title'] ); ?></h1>
			<?php elseif ( $ctx['title'] ) : ?>
				<h2 class="bk-shop__title"><?php echo esc_html( $ctx['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( $ctx['desc'] ) : ?><div class="bk-shop__desc"><?php echo wp_kses_post( $ctx['desc'] ); ?></div><?php endif; ?>
		</div>

		<div class="bk-shop__bar">
			<button type="button" class="bk-shop__filter-btn" aria-controls="<?php echo esc_attr( $uid ); ?>-filters" aria-expanded="false">
				<?php echo bk_icon_svg( 'lucide:sliders-horizontal', 18 ); // phpcs:ignore ?><?php esc_html_e( 'Filtrar', 'buckler-core' ); ?><?php echo $n_active ? ' <span class="bk-shop__n">' . (int) $n_active . '</span>' : ''; ?>
			</button>
			<p class="bk-shop__count" role="status">
				<?php /* translators: %d: número de productos */ echo esc_html( sprintf( _n( '%d prenda', '%d prendas', $total, 'buckler-core' ), $total ) ); ?>
			</p>
			<form class="bk-shop__sort" method="get" action="<?php echo esc_url( $base ); ?>">
				<?php foreach ( array_merge( $state, array( 'orden' => '' ) ) as $k => $v ) : ?>
					<?php if ( $v ) : ?><input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( is_array( $v ) ? implode( ',', $v ) : $v ); ?>"><?php endif; ?>
				<?php endforeach; ?>
				<?php if ( '' !== $ctx['search'] ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $ctx['search'] ); ?>"><input type="hidden" name="post_type" value="product"><?php endif; ?>
				<label for="<?php echo esc_attr( $uid ); ?>-orden"><?php esc_html_e( 'Ordenar por', 'buckler-core' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-orden" name="orden" data-bk-autosubmit>
					<?php foreach ( array( '' => __( 'Lo más nuevo', 'buckler-core' ), 'popular' => __( 'Más vendidos', 'buckler-core' ), 'precio-asc' => __( 'Menor precio', 'buckler-core' ), 'precio-desc' => __( 'Mayor precio', 'buckler-core' ) ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $state['orden'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit"><?php esc_html_e( 'Aplicar', 'buckler-core' ); ?></button></noscript>
			</form>
		</div>

		<?php if ( $active ) : ?>
			<ul class="bk-shop__chips" aria-label="<?php esc_attr_e( 'Filtros activos', 'buckler-core' ); ?>">
				<?php foreach ( $active as $a ) : ?>
					<?php /* translators: %s: filtro */ ?>
					<li><a href="<?php echo esc_url( $a[1] ); ?>" data-bk-shop-link aria-label="<?php echo esc_attr( sprintf( __( 'Quitar filtro %s', 'buckler-core' ), $a[0] ) ); ?>"><?php echo esc_html( $a[0] ); ?> <?php echo bk_icon_svg( 'lucide:x', 14 ); // phpcs:ignore ?></a></li>
				<?php endforeach; ?>
				<li><a class="bk-shop__clear" href="<?php echo esc_url( $base ); ?>" data-bk-shop-link><?php esc_html_e( 'Limpiar todo', 'buckler-core' ); ?></a></li>
			</ul>
		<?php endif; ?>

		<div class="bk-shop__layout">
			<div class="bk-shop__filters" id="<?php echo esc_attr( $uid ); ?>-filters" role="dialog" aria-modal="false" aria-label="<?php esc_attr_e( 'Filtros', 'buckler-core' ); ?>">
				<div class="bk-shop__fhead">
					<span class="bk-shop__ftitle"><?php esc_html_e( 'Filtros', 'buckler-core' ); ?></span>
					<button type="button" class="bk-icon-btn bk-shop__fclose" aria-label="<?php esc_attr_e( 'Cerrar filtros', 'buckler-core' ); ?>"><?php echo bk_icon_svg( 'lucide:x', 22 ); // phpcs:ignore ?></button>
				</div>
				<form class="bk-shop__form" method="get" action="<?php echo esc_url( $base ); ?>">
					<?php if ( '' !== $ctx['search'] ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $ctx['search'] ); ?>"><input type="hidden" name="post_type" value="product"><?php endif; ?>
					<?php if ( $state['orden'] ) : ?><input type="hidden" name="orden" value="<?php echo esc_attr( $state['orden'] ); ?>"><?php endif; ?>

					<?php if ( ! $ctx['line'] ) : ?>
						<fieldset class="bk-f">
							<legend><?php esc_html_e( 'Línea', 'buckler-core' ); ?></legend>
							<div class="bk-f__opts">
								<?php foreach ( $lines as $slug => $l ) : ?>
									<?php
									$short = str_replace( 'linea-', '', $slug );
									$n     = $count( 'linea', static fn( $it ) => $it['line'] === $slug );
									?>
									<label class="bk-f__opt<?php echo $n ? '' : ' is-empty'; ?>" for="bk-f-linea-<?php echo esc_attr( $short ); ?>">
										<input type="radio" id="bk-f-linea-<?php echo esc_attr( $short ); ?>" name="linea" value="<?php echo esc_attr( $short ); ?>" <?php checked( $state['linea'], $short ); ?> <?php disabled( ! $n && $state['linea'] !== $short ); ?>>
										<span><?php echo esc_html( $l['label'] ); ?> <small><?php echo esc_html( $l['sizes'] ); ?></small></span><em><?php echo (int) $n; ?></em>
									</label>
								<?php endforeach; ?>
								<label class="bk-f__opt" for="bk-f-linea-all"><input type="radio" id="bk-f-linea-all" name="linea" value="" <?php checked( $state['linea'], '' ); ?>><span><?php esc_html_e( 'Ambas', 'buckler-core' ); ?></span></label>
							</div>
						</fieldset>
					<?php endif; ?>

					<?php if ( $u['prendas'] ) : ?>
						<fieldset class="bk-f">
							<legend><?php esc_html_e( 'Prenda', 'buckler-core' ); ?></legend>
							<div class="bk-f__opts">
								<?php foreach ( $u['prendas'] as $k => $label ) : ?>
									<?php $n = $count( 'prenda', static fn( $it ) => in_array( $k, $it['prenda'], true ) ); ?>
									<label class="bk-f__opt<?php echo $n ? '' : ' is-empty'; ?>" for="bk-f-prenda-<?php echo esc_attr( $k ); ?>">
										<input type="checkbox" id="bk-f-prenda-<?php echo esc_attr( $k ); ?>" name="prenda[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $f['prenda'], true ) ); ?> <?php disabled( ! $n && ! in_array( $k, $f['prenda'], true ) ); ?>>
										<span><?php echo esc_html( $label ); ?></span><em><?php echo (int) $n; ?></em>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endif; ?>

					<?php if ( $u['sizes'] ) : ?>
						<fieldset class="bk-f">
							<legend><?php esc_html_e( 'Talla', 'buckler-core' ); ?></legend>
							<div class="bk-f__sizes">
								<?php foreach ( $u['sizes'] as $s ) : ?>
									<?php $n = $count( 'talla', static fn( $it ) => in_array( $s, $it['sizes'], true ) ); ?>
									<?php if ( ! $n && ! in_array( $s, $f['talla'], true ) ) { continue; } ?>
									<?php /* translators: 1: talla, 2: cantidad */ ?>
									<label class="bk-f__size" for="bk-f-talla-<?php echo esc_attr( sanitize_title( $s ) ); ?>" title="<?php echo esc_attr( sprintf( __( 'Talla %1$s (%2$d)', 'buckler-core' ), $s, $n ) ); ?>">
										<input type="checkbox" id="bk-f-talla-<?php echo esc_attr( sanitize_title( $s ) ); ?>" name="talla[]" value="<?php echo esc_attr( $s ); ?>" <?php checked( in_array( $s, $f['talla'], true ) ); ?>>
										<span><?php echo esc_html( $s ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endif; ?>

					<fieldset class="bk-f">
						<legend><?php esc_html_e( 'Precio', 'buckler-core' ); ?></legend>
						<div class="bk-f__opts">
							<?php foreach ( $buckets as $k => $b ) : ?>
								<?php $n = $count( 'precio', static fn( $it ) => $it['price'] >= $b['min'] && ( ! $b['max'] || $it['price'] < $b['max'] ) ); ?>
								<label class="bk-f__opt<?php echo $n ? '' : ' is-empty'; ?>" for="bk-f-precio-<?php echo esc_attr( sanitize_title( $k ) ); ?>">
									<input type="radio" id="bk-f-precio-<?php echo esc_attr( sanitize_title( $k ) ); ?>" name="precio" value="<?php echo esc_attr( $k ); ?>" <?php checked( $state['precio'], $k ); ?> <?php disabled( ! $n && $state['precio'] !== $k ); ?>>
									<span><?php echo esc_html( $b['label'] ); ?></span><em><?php echo (int) $n; ?></em>
								</label>
							<?php endforeach; ?>
							<label class="bk-f__opt" for="bk-f-precio-all"><input type="radio" id="bk-f-precio-all" name="precio" value="" <?php checked( $state['precio'], '' ); ?>><span><?php esc_html_e( 'Cualquier precio', 'buckler-core' ); ?></span></label>
						</div>
					</fieldset>

					<?php if ( ! $ctx['sale'] ) : ?>
						<?php $n = $count( 'oferta', static fn( $it ) => $it['sale'] ); ?>
						<?php if ( $n ) : ?>
							<fieldset class="bk-f">
								<legend class="bk-sr-only"><?php esc_html_e( 'Descuento', 'buckler-core' ); ?></legend>
								<label class="bk-f__opt bk-f__switch" for="bk-f-oferta">
									<input type="checkbox" id="bk-f-oferta" name="oferta" value="1" <?php checked( $f['oferta'] ); ?>>
									<span><?php esc_html_e( 'Solo prendas en descuento', 'buckler-core' ); ?></span><em><?php echo (int) $n; ?></em>
								</label>
							</fieldset>
						<?php endif; ?>
					<?php endif; ?>

					<div class="bk-shop__factions">
						<?php /* translators: %d: resultados */ ?>
						<button type="submit" class="bk-shop__apply"><?php echo esc_html( sprintf( _n( 'Ver %d prenda', 'Ver %d prendas', $total, 'buckler-core' ), $total ) ); ?></button>
						<?php if ( $active ) : ?><a class="bk-shop__clear" href="<?php echo esc_url( $base ); ?>" data-bk-shop-link><?php esc_html_e( 'Limpiar filtros', 'buckler-core' ); ?></a><?php endif; ?>
					</div>
				</form>
			</div>

			<div class="bk-shop__results">
				<?php if ( ! $page ) : ?>
					<div class="bk-shop__empty">
						<p class="bk-shop__empty-title"><?php esc_html_e( 'No encontramos prendas con esos filtros', 'buckler-core' ); ?></p>
						<p><?php esc_html_e( 'Prueba quitando una talla o el rango de precio.', 'buckler-core' ); ?></p>
						<?php if ( $active ) : ?><a class="bk-shop__more" href="<?php echo esc_url( $base ); ?>" data-bk-shop-link><?php esc_html_e( 'Ver todas las prendas', 'buckler-core' ); ?></a><?php endif; ?>
					</div>
				<?php else : ?>
					<?php
					wc_setup_loop(
						array(
							'columns'      => max( 2, min( 5, (int) $atts['columnas'] ) ),
							'is_paginated' => false,
							'current_page' => $pg,
							'total'        => $total,
						)
					);
					woocommerce_product_loop_start();
					foreach ( $page as $it ) {
						$GLOBALS['post'] = get_post( $it['id'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
						setup_postdata( $GLOBALS['post'] );
						wc_get_template_part( 'content', 'product' );
					}
					woocommerce_product_loop_end();
					wp_reset_postdata();
					wc_reset_loop();
					?>
					<div class="bk-shop__pager">
						<?php /* translators: 1: mostradas, 2: total */ ?>
						<p class="bk-shop__progress"><?php echo esc_html( sprintf( __( 'Viendo %1$d de %2$d prendas', 'buckler-core' ), $shown, $total ) ); ?></p>
						<?php if ( $shown < $total ) : ?>
							<a class="bk-shop__more" data-bk-shop-more href="<?php echo esc_url( bk_shop_url( $base, array_merge( $state, array( 'pg' => $pg + 1 ) ) ) ); ?>"><?php esc_html_e( 'Ver más prendas', 'buckler-core' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return trim( ob_get_clean() );
}
