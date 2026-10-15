<?php
/**
 * Descuentos (módulo `discounts`; se administran en Gestión de tienda → Descuentos).
 *
 * 1. Campañas por porcentaje: X % a toda la tienda (o a categorías) entre dos fechas, EXCEPTO los productos de
 *    ¡Sale! (categoría sale_cat o con precio rebajado propio). Al terminar se apaga sola. Mientras está activa:
 *    precio tachado en tarjetas, ficha, carrito y checkout (filtros de precio de WooCommerce), etiqueta en las
 *    tarjetas, anuncio en la barra superior y sección destacada en el inicio.
 * 2. Descuento por cantidad: tramos ("desde 1 prenda 10 %, desde 2 el 20 %, desde 4 el 30 %") por fechas, a toda la
 *    tienda, a categorías o a productos; cuenta las prendas del mismo modelo o todas las de la promoción en el carrito.
 *    Se muestra en la ficha y en el carrito, y queda anotado en cada línea del pedido.
 *
 * Regla: los descuentos NO se suman; a cada prenda se le aplica el mayor (campaña o cantidad), siempre sobre el
 * precio normal. Las prendas de ¡Sale! se excluyen salvo que la regla diga lo contrario.
 *
 * Caché: el precio se calcula en cada petición (el checkout siempre cobra lo correcto); al empezar o terminar una
 * regla se programa un vaciado del catálogo y de la caché de páginas (Action Scheduler, `bk_disc_tick`).
 * Opción: `bk_discounts` = { campaigns: [...], qty: [...] }.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

const BK_DISC_OPTION = 'bk_discounts';

/* ---------------------------------------------------------------------------
 * Datos
 * ------------------------------------------------------------------------- */

/**
 * Reglas guardadas.
 *
 * @param bool $fresh Releer de la base de datos.
 * @return array{campaigns:array,qty:array}
 */
function bk_disc_data( $fresh = false ) {
	static $data = null;
	if ( null === $data || $fresh ) {
		$d    = get_option( BK_DISC_OPTION, array() );
		$data = array(
			'campaigns' => array_values( (array) ( $d['campaigns'] ?? array() ) ),
			'qty'       => array_values( (array) ( $d['qty'] ?? array() ) ),
		);
	}
	return $data;
}

/**
 * Hora actual (filtrable en pruebas).
 *
 * @return int
 */
function bk_disc_now() {
	return (int) apply_filters( 'bk_disc_now', time() );
}

/**
 * ¿La regla está vigente ahora?
 *
 * @param array $r Regla.
 * @return bool
 */
function bk_disc_live( $r ) {
	$now = bk_disc_now();
	return ! empty( $r['active'] ) && (int) ( $r['start_ts'] ?? 0 ) <= $now && $now < (int) ( $r['end_ts'] ?? 0 );
}

/**
 * Estado legible de una regla.
 *
 * @param array $r Regla.
 * @return string active|scheduled|ended|paused
 */
function bk_disc_state( $r ) {
	if ( empty( $r['active'] ) ) {
		return 'paused';
	}
	$now = bk_disc_now();
	if ( $now < (int) $r['start_ts'] ) {
		return 'scheduled';
	}
	return $now < (int) $r['end_ts'] ? 'active' : 'ended';
}

/**
 * Producto padre (las variaciones heredan categorías del padre).
 *
 * @param WC_Product $p Producto.
 * @return int
 */
function bk_disc_parent( $p ) {
	return $p->get_parent_id() ? (int) $p->get_parent_id() : (int) $p->get_id();
}

/**
 * IDs de la categoría ¡Sale! y sus hijas.
 *
 * @return int[]
 */
function bk_disc_sale_cats() {
	static $ids = null;
	if ( null === $ids ) {
		$ids  = array();
		$sale = get_term_by( 'slug', (string) bk_config( 'sale_cat' ), 'product_cat' );
		if ( $sale ) {
			$ids = array_merge( array( (int) $sale->term_id ), array_map( 'intval', (array) get_term_children( $sale->term_id, 'product_cat' ) ) );
		}
	}
	return $ids;
}

/**
 * ¿Prenda de ¡Sale!? (en la categoría o con precio rebajado propio, sin contar las campañas).
 *
 * @param WC_Product $p Producto o variación.
 * @return bool
 */
function bk_disc_is_sale( $p ) {
	static $memo = array();
	$parent = bk_disc_parent( $p );
	if ( isset( $memo[ $parent ] ) ) {
		return $memo[ $parent ];
	}
	// Se decide por modelo (padre): si cualquier talla tiene precio rebajado propio, todo el producto es de ¡Sale!.
	$is = (bool) array_intersect( wc_get_product_term_ids( $parent, 'product_cat' ), bk_disc_sale_cats() );
	$pp = $is ? null : ( $parent === (int) $p->get_id() ? $p : wc_get_product( $parent ) );
	if ( $pp ) {
		$own = (string) $pp->get_sale_price( 'edit' );
		$is  = '' !== $own && (float) $own < (float) $pp->get_regular_price( 'edit' );
		if ( ! $is && $pp->is_type( 'variable' ) ) {
			foreach ( $pp->get_children() as $cid ) {
				$c = wc_get_product( $cid );
				if ( $c && '' !== (string) $c->get_sale_price( 'edit' ) && (float) $c->get_sale_price( 'edit' ) < (float) $c->get_regular_price( 'edit' ) ) {
					$is = true;
					break;
				}
			}
		}
	}
	return $memo[ $parent ] = $is;
}

/**
 * ¿El producto entra en la regla?
 *
 * @param array      $r Regla.
 * @param WC_Product $p Producto.
 * @return bool
 */
function bk_disc_in_scope( $r, $p ) {
	if ( ( ! isset( $r['exclude_sale'] ) || $r['exclude_sale'] ) && bk_disc_is_sale( $p ) ) {
		return false;
	}
	$parent = bk_disc_parent( $p );
	$scope  = $r['scope'] ?? 'all';
	if ( 'products' === $scope ) {
		return in_array( $parent, array_map( 'intval', (array) ( $r['products'] ?? array() ) ), true );
	}
	if ( 'cats' === $scope ) {
		$want = array();
		foreach ( array_map( 'intval', (array) ( $r['cats'] ?? array() ) ) as $c ) {
			$want = array_merge( $want, array( $c ), array_map( 'intval', (array) get_term_children( $c, 'product_cat' ) ) );
		}
		return (bool) array_intersect( wc_get_product_term_ids( $parent, 'product_cat' ), $want );
	}
	return true;
}

/**
 * Campaña vigente con el mayor porcentaje para un producto.
 *
 * @param WC_Product $p Producto.
 * @return array|null Regla.
 */
function bk_disc_campaign_for( $p ) {
	$best = null;
	foreach ( bk_disc_data()['campaigns'] as $r ) {
		if ( bk_disc_live( $r ) && (float) $r['pct'] > 0 && bk_disc_in_scope( $r, $p ) && ( ! $best || (float) $r['pct'] > (float) $best['pct'] ) ) {
			$best = $r;
		}
	}
	return $best;
}

/**
 * Campaña vigente (la de mayor porcentaje) para anuncios y portada.
 *
 * @return array|null
 */
function bk_disc_live_campaign() {
	$best = null;
	foreach ( bk_disc_data()['campaigns'] as $r ) {
		if ( bk_disc_live( $r ) && (float) $r['pct'] > 0 && ( ! $best || (float) $r['pct'] > (float) $best['pct'] ) ) {
			$best = $r;
		}
	}
	return $best;
}

/**
 * Reglas por cantidad vigentes que aplican a un producto.
 *
 * @param WC_Product $p Producto.
 * @return array[]
 */
function bk_disc_qty_for( $p ) {
	return array_values( array_filter( bk_disc_data()['qty'], static fn( $r ) => bk_disc_live( $r ) && ! empty( $r['tiers'] ) && bk_disc_in_scope( $r, $p ) ) );
}

/**
 * Porcentaje del tramo para una cantidad.
 *
 * @param array $r     Regla.
 * @param int   $count Prendas.
 * @return float
 */
function bk_disc_tier_pct( $r, $count ) {
	$pct = 0.0;
	foreach ( (array) $r['tiers'] as $t ) {
		if ( $count >= (int) $t['min'] ) {
			$pct = max( $pct, (float) $t['pct'] );
		}
	}
	return $pct;
}

/**
 * Precio con descuento (pesos enteros).
 *
 * @param float $regular Precio normal.
 * @param float $pct     Porcentaje.
 * @return string
 */
function bk_disc_apply( $regular, $pct ) {
	return wc_format_decimal( round( (float) $regular * ( 100 - (float) $pct ) / 100, wc_get_price_decimals() ), wc_get_price_decimals() );
}

/**
 * Firma de las reglas vigentes (para las cachés de precios de WooCommerce).
 *
 * @return string
 */
function bk_disc_signature() {
	$live = array();
	foreach ( bk_disc_data()['campaigns'] as $r ) {
		if ( bk_disc_live( $r ) ) {
			$live[] = $r['id'] . ':' . $r['pct'] . ':' . ( $r['updated'] ?? 0 );
		}
	}
	return implode( ',', $live );
}

/* ---------------------------------------------------------------------------
 * Precios (campañas) — filtros de WooCommerce
 * ------------------------------------------------------------------------- */

if ( bk_on( 'discounts' ) ) {
	foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ) as $bk_hook ) {
		add_filter( $bk_hook, 'bk_disc_filter_price', 99, 2 );
	}
	unset( $bk_hook );
	add_filter( 'woocommerce_variation_prices_price', 'bk_disc_filter_variation_price', 99, 3 );
	add_filter( 'woocommerce_variation_prices_sale_price', 'bk_disc_filter_variation_price', 99, 3 );
	add_filter( 'woocommerce_get_variation_prices_hash', static fn( $hash ) => array_merge( (array) $hash, array( 'bk_disc' => empty( $GLOBALS['bk_disc_raw'] ) ? bk_disc_signature() : 'raw' ) ), 99 );
	add_action( 'woocommerce_before_calculate_totals', 'bk_disc_cart_qty', 20 );
	add_filter( 'woocommerce_get_item_data', 'bk_disc_cart_item_data', 20, 2 );
	add_action( 'woocommerce_checkout_create_order_line_item', 'bk_disc_order_line', 20, 3 );
	add_action( 'woocommerce_single_product_summary', 'bk_disc_pdp_box', 12 );
	add_action( 'woocommerce_before_cart_table', 'bk_disc_cart_hint', 5 );
	add_action( 'woocommerce_review_order_before_payment', 'bk_disc_cart_hint', 5 );
	add_filter( 'bk_site_config', 'bk_disc_announcement', 20 );
	add_action( 'bk_home_after_lines', 'bk_disc_home_section' );
}
add_action( 'bk_disc_tick', 'bk_disc_flush' );

/**
 * Precio / precio rebajado de un producto o talla en campaña (o fijado por el descuento por cantidad en el carrito).
 *
 * @param mixed      $price Valor.
 * @param WC_Product $p     Producto.
 * @return mixed
 */
function bk_disc_filter_price( $price, $p ) {
	if ( ! empty( $GLOBALS['bk_disc_raw'] ) || ! $p instanceof WC_Product || $p->is_type( array( 'variable', 'grouped' ) ) ) {
		return $price;
	}
	$fixed = bk_disc_fixed( $p );
	if ( $fixed ) {
		return $fixed['price']; // Precio por cantidad calculado en el carrito.
	}
	$c = bk_disc_campaign_for( $p );
	if ( ! $c ) {
		return $price;
	}
	$regular = (float) $p->get_regular_price( 'edit' );
	return $regular > 0 ? bk_disc_apply( $regular, $c['pct'] ) : $price;
}

/**
 * Precios de las tallas (rango "desde – hasta" de los productos variables).
 *
 * @param mixed      $price     Valor.
 * @param WC_Product $variation Talla.
 * @param WC_Product $product   Padre.
 * @return mixed
 */
function bk_disc_filter_variation_price( $price, $variation, $product ) {
	unset( $product );
	if ( ! empty( $GLOBALS['bk_disc_raw'] ) ) {
		return $price;
	}
	$c = bk_disc_campaign_for( $variation );
	if ( ! $c ) {
		return $price;
	}
	$regular = (float) $variation->get_regular_price( 'edit' );
	return $regular > 0 ? bk_disc_apply( $regular, $c['pct'] ) : $price;
}

/* ---------------------------------------------------------------------------
 * Carrito: descuento por cantidad
 * ------------------------------------------------------------------------- */

/**
 * Aplica el mayor descuento (campaña o cantidad) a cada línea del carrito.
 *
 * @param WC_Cart $cart Carrito.
 */
function bk_disc_cart_qty( $cart ) {
	if ( ! $cart instanceof WC_Cart || ( is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) ) {
		return;
	}
	$items = $cart->get_cart();
	// Conteos: por modelo (padre) y por regla.
	$by_parent = array();
	foreach ( $items as $it ) {
		$pid               = bk_disc_parent( $it['data'] );
		$by_parent[ $pid ] = ( $by_parent[ $pid ] ?? 0 ) + (int) $it['quantity'];
	}
	$by_rule = array();
	foreach ( bk_disc_data()['qty'] as $r ) {
		if ( ! bk_disc_live( $r ) ) {
			continue;
		}
		foreach ( $items as $it ) {
			if ( bk_disc_in_scope( $r, $it['data'] ) ) {
				$by_rule[ $r['id'] ] = ( $by_rule[ $r['id'] ] ?? 0 ) + (int) $it['quantity'];
			}
		}
	}
	foreach ( $items as $key => $it ) {
		$p = $it['data'];
		bk_disc_fixed( $p, false );
		$best  = 0.0;
		$label = '';
		$c     = bk_disc_campaign_for( $p );
		if ( $c ) {
			$best  = (float) $c['pct'];
			$label = sprintf( '%s %% · %s', bk_disc_num( $c['pct'] ), $c['name'] );
		}
		foreach ( bk_disc_qty_for( $p ) as $r ) {
			$count = 'cart' === ( $r['count'] ?? 'product' ) ? (int) ( $by_rule[ $r['id'] ] ?? 0 ) : (int) $by_parent[ bk_disc_parent( $p ) ];
			$pct   = bk_disc_tier_pct( $r, $count );
			if ( $pct > $best ) {
				$best  = $pct;
				$label = sprintf( '%s %% por cantidad (%d prendas) · %s', bk_disc_num( $pct ), $count, $r['name'] );
			}
		}
		if ( ! $label ) {
			continue;
		}
		$regular = (float) $p->get_regular_price( 'edit' );
		if ( $regular > 0 ) {
			bk_disc_fixed( $p, array( 'price' => bk_disc_apply( $regular, $best ), 'label' => $label ) );
		}
	}
}

/**
 * Precio fijado en el carrito para un objeto producto (sin propiedades dinámicas: PHP 8.2+).
 *
 * @param WC_Product       $p   Producto (objeto de la línea del carrito).
 * @param array|false|null $set null = leer; false = borrar; array = guardar {price,label}.
 * @return array|null
 */
function bk_disc_fixed( $p, $set = null ) {
	static $map = array();
	$id = spl_object_id( $p );
	if ( false === $set ) {
		unset( $map[ $id ] );
	} elseif ( is_array( $set ) ) {
		$map[ $id ] = $set;
	}
	return $map[ $id ] ?? null;
}

/**
 * Número sin decimales innecesarios ("20" / "12,5").
 *
 * @param float $n Número.
 * @return string
 */
function bk_disc_num( $n ) {
	return str_replace( '.', ',', rtrim( rtrim( number_format( (float) $n, 1, '.', '' ), '0' ), '.' ) );
}

/**
 * Línea "Descuento: 20 % por cantidad" en el carrito y el checkout.
 *
 * @param array $data Datos.
 * @param array $item Línea.
 * @return array
 */
function bk_disc_cart_item_data( $data, $item ) {
	$fixed = isset( $item['data'] ) ? bk_disc_fixed( $item['data'] ) : null;
	if ( $fixed ) {
		$data[] = array( 'key' => __( 'Descuento', 'buckler-core' ), 'value' => '−' . $fixed['label'] );
	}
	return $data;
}

/**
 * Deja el descuento anotado en la línea del pedido.
 *
 * @param WC_Order_Item_Product $item  Línea.
 * @param string                $key   Clave del carrito.
 * @param array                 $values Datos del carrito.
 */
function bk_disc_order_line( $item, $key, $values ) {
	unset( $key );
	$fixed = isset( $values['data'] ) ? bk_disc_fixed( $values['data'] ) : null;
	if ( $fixed ) {
		$item->add_meta_data( __( 'Descuento', 'buckler-core' ), '−' . $fixed['label'], true );
	}
}

/**
 * Mensaje en carrito / checkout: "Con 1 prenda más obtienes el 30 %".
 */
function bk_disc_cart_hint() {
	if ( ! WC()->cart ) {
		return;
	}
	$items = WC()->cart->get_cart();
	$msgs  = array();
	foreach ( bk_disc_data()['qty'] as $r ) {
		if ( ! bk_disc_live( $r ) ) {
			continue;
		}
		$counts = array();
		foreach ( $items as $it ) {
			if ( bk_disc_in_scope( $r, $it['data'] ) ) {
				$k            = 'cart' === ( $r['count'] ?? 'product' ) ? 0 : bk_disc_parent( $it['data'] );
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + (int) $it['quantity'];
			}
		}
		foreach ( $counts as $count ) {
			$next = null;
			$floor = 0.0;
			foreach ( $items as $it ) {
				$camp  = bk_disc_in_scope( $r, $it['data'] ) ? bk_disc_campaign_for( $it['data'] ) : null;
				$floor = max( $floor, $camp ? (float) $camp['pct'] : 0.0 );
			}
			foreach ( bk_disc_tiers_sorted( $r ) as $t ) {
				if ( (int) $t['min'] > $count && (float) $t['pct'] > max( $floor, bk_disc_tier_pct( $r, $count ) ) ) {
					$next = $t;
					break;
				}
			}
			if ( $next ) {
				$more   = (int) $next['min'] - $count;
				$msgs[] = sprintf(
					/* translators: 1: prendas, 2: porcentaje, 3: promoción */
					_n( 'Agrega %1$d prenda más y obtén el %2$s %% de descuento (%3$s).', 'Agrega %1$d prendas más y obtén el %2$s %% de descuento (%3$s).', $more, 'buckler-core' ),
					$more,
					bk_disc_num( $next['pct'] ),
					$r['name']
				);
				break;
			}
		}
	}
	foreach ( array_unique( $msgs ) as $m ) {
		echo '<p class="bk-disc-hint" role="status">' . bk_icon_svg( 'lucide:gift', 18 ) . ' ' . esc_html( $m ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/**
 * Tramos ordenados.
 *
 * @param array $r Regla.
 * @return array
 */
function bk_disc_tiers_sorted( $r ) {
	$t = array_values( (array) $r['tiers'] );
	usort( $t, static fn( $a, $b ) => (int) $a['min'] <=> (int) $b['min'] );
	return $t;
}

/**
 * Texto de los tramos: "1 prenda: −10 % · 2 a 3 prendas: −20 % · 4 o más: −30 %".
 *
 * @param array $r Regla.
 * @return array<int,array{label:string,pct:string}>
 */
function bk_disc_tier_labels( $r ) {
	$t   = bk_disc_tiers_sorted( $r );
	$out = array();
	foreach ( $t as $i => $row ) {
		$min  = (int) $row['min'];
		$next = isset( $t[ $i + 1 ] ) ? (int) $t[ $i + 1 ]['min'] - 1 : 0;
		if ( $next && $next > $min ) {
			$label = sprintf( '%d a %d prendas', $min, $next );
		} elseif ( $next && $next === $min ) {
			$label = sprintf( _n( '%d prenda', '%d prendas', $min, 'buckler-core' ), $min );
		} else {
			$label = sprintf( '%d o más prendas', $min );
		}
		$out[] = array( 'label' => $label, 'pct' => bk_disc_num( $row['pct'] ) );
	}
	return $out;
}

/**
 * Fecha corta en español ("31 de octubre").
 *
 * @param int $ts Timestamp.
 * @return string
 */
function bk_disc_date( $ts ) {
	return wp_date( 'j \d\e F', (int) $ts );
}

/* ---------------------------------------------------------------------------
 * Ficha, barra de anuncios e inicio
 * ------------------------------------------------------------------------- */

/**
 * Cuadro de la ficha: campaña vigente y tramos por cantidad.
 */
function bk_disc_pdp_box() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$c   = bk_disc_campaign_for( $product );
	$qty = bk_disc_qty_for( $product );
	if ( ! $c && ! $qty ) {
		return;
	}
	echo '<div class="bk-disc-box">';
	if ( $c ) {
		printf(
			'<p class="bk-disc-box__camp"><strong>%1$s</strong> <span>%2$s</span></p>',
			esc_html( sprintf( '−%s %% · %s', bk_disc_num( $c['pct'] ), $c['name'] ) ),
			/* translators: %s: fecha */
			esc_html( sprintf( __( 'Precio especial hasta el %s', 'buckler-core' ), bk_disc_date( (int) $c['end_ts'] - 1 ) ) )
		);
	}
	foreach ( $qty as $r ) {
		// Con campaña vigente solo se muestran los tramos que dan MÁS que la campaña (no se suman).
		$labels = array_values( array_filter( bk_disc_tier_labels( $r ), static fn( $t ) => ! $c || (float) str_replace( ',', '.', $t['pct'] ) > (float) $c['pct'] ) );
		if ( ! $labels ) {
			continue;
		}
		echo '<div class="bk-disc-box__qty"><p class="bk-disc-box__title">' . bk_icon_svg( 'lucide:gift', 18 ) . esc_html( $r['name'] ? $r['name'] : __( 'Compra más y ahorra', 'buckler-core' ) ) . '</p><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $labels as $t ) {
			echo '<li><span>' . esc_html( $t['label'] ) . '</span><b>−' . esc_html( $t['pct'] ) . ' %</b></li>';
		}
		$how = 'cart' === ( $r['count'] ?? 'product' ) ? __( 'Suma todas las prendas de la promoción en tu carrito', 'buckler-core' ) : __( 'Suma las prendas de este modelo (cualquier talla)', 'buckler-core' );
		/* translators: 1: cómo se cuenta, 2: fecha */
		echo '</ul><small>' . esc_html( sprintf( __( '%1$s · hasta el %2$s · no se suma a otras promociones.', 'buckler-core' ), $how, bk_disc_date( (int) $r['end_ts'] - 1 ) ) ) . '</small></div>';
	}
	echo '</div>';
}

/**
 * Anuncio de la campaña al inicio de la barra superior.
 *
 * @param array $config Configuración.
 * @return array
 */
function bk_disc_announcement( $config ) {
	$c = bk_disc_live_campaign();
	if ( $c && ( ! isset( $c['bar'] ) || $c['bar'] ) ) {
		// Corto y con el porcentaje primero: la barra muestra 3 anuncios a la vez y corta con "…" (la fecha va en la ficha y en el inicio).
		$where = 'all' === ( $c['scope'] ?? 'all' ) ? __( 'en toda la tienda', 'buckler-core' ) : __( 'en prendas seleccionadas', 'buckler-core' );
		/* translators: 1: nombre, 2: porcentaje, 3: dónde */
		array_unshift( $config['announcements'], sprintf( __( '−%2$s %% %3$s · %1$s', 'buckler-core' ), $c['name'], bk_disc_num( $c['pct'] ), $where ) );
	}
	return $config;
}

/**
 * Sección destacada en el inicio mientras hay campaña.
 */
function bk_disc_home_section() {
	$c = bk_disc_live_campaign();
	if ( ! $c || ( isset( $c['home'] ) && ! $c['home'] ) ) {
		return;
	}
	$ids = array_values( array_filter( array_map( 'intval', (array) ( $c['featured'] ?? array() ) ) ) );
	if ( ! $ids ) {
		$args = array( 'limit' => 40, 'status' => 'publish', 'stock_status' => 'instock', 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' );
		foreach ( wc_get_products( $args ) as $p ) {
			if ( bk_disc_in_scope( $c, $p ) ) {
				$ids[] = $p->get_id();
			}
			if ( count( $ids ) >= 8 ) {
				break;
			}
		}
	}
	if ( ! $ids ) {
		return;
	}
	$left = max( 0, (int) ceil( ( (int) $c['end_ts'] - bk_disc_now() ) / DAY_IN_SECONDS ) );
	?>
	<section class="bk-wrap bk-disc-home" aria-labelledby="bk-disc-t">
		<div class="bk-disc-home__head">
			<p class="bk-disc-home__pct">−<?php echo esc_html( bk_disc_num( $c['pct'] ) ); ?> %</p>
			<div>
				<h2 id="bk-disc-t" class="bk-section-title"><?php echo esc_html( $c['name'] ); ?></h2>
				<p>
					<?php
					/* translators: 1: dónde, 2: fecha, 3: días */
					echo esc_html( sprintf( _n( '%1$s hasta el %2$s · queda %3$d día', '%1$s hasta el %2$s · quedan %3$d días', $left, 'buckler-core' ), 'all' === ( $c['scope'] ?? 'all' ) ? __( 'En toda la tienda (excepto ¡Sale!)', 'buckler-core' ) : __( 'En prendas seleccionadas', 'buckler-core' ), bk_disc_date( (int) $c['end_ts'] - 1 ), $left ) );
					?>
				</p>
			</div>
			<a class="bk-btn" href="<?php echo esc_url( add_query_arg( 'oferta', '1', wc_get_page_permalink( 'shop' ) ) ); ?>"><?php esc_html_e( 'Ver todo en oferta', 'buckler-core' ); ?></a>
		</div>
		<?php echo do_shortcode( '[bk_productos ids="' . esc_attr( implode( ',', array_slice( $ids, 0, 8 ) ) ) . '" limite="8"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</section>
	<?php
}

/* ---------------------------------------------------------------------------
 * Cachés: al empezar / terminar una regla
 * ------------------------------------------------------------------------- */

/**
 * Vacía catálogo (universo de la tienda, índice de búsqueda, precios de tallas) y la caché de páginas.
 */
function bk_disc_flush() {
	update_option( 'bk_catalog_v', (string) microtime( true ), false );
	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'product', true );
	}
	if ( function_exists( 'bk_cache_purge' ) ) {
		bk_cache_purge();
	}
	do_action( 'bk_discounts_flushed' );
}

/**
 * Programa los vaciados en cada inicio y fin de regla.
 */
function bk_disc_schedule() {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}
	as_unschedule_all_actions( 'bk_disc_tick', array(), 'buckler-discounts' );
	$now = time();
	$ts  = array();
	foreach ( array_merge( bk_disc_data( true )['campaigns'], bk_disc_data()['qty'] ) as $r ) {
		foreach ( array( (int) ( $r['start_ts'] ?? 0 ), (int) ( $r['end_ts'] ?? 0 ) ) as $t ) {
			if ( $t > $now ) {
				$ts[ $t ] = true;
			}
		}
	}
	foreach ( array_keys( $ts ) as $t ) {
		as_schedule_single_action( $t + 5, 'bk_disc_tick', array( 't' => $t ), 'buckler-discounts' );
	}
}
