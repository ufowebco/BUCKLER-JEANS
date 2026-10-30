// 0.18.26: recordatorios de pago a la 1 h y 12 h, cancelación a las 24 h; los pedidos que ya esperaban pago se reprograman.
const { execSync } = require('child_process');
const WP = '/tmp/claude-0/bk-local/site/wp-load.php';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "${WP}"; ${code}'`, { encoding: 'utf8' }).trim();
// Horas entre la creación del pedido y cada acción programada.
const hours = (id) => JSON.parse(php(`
  $o = wc_get_order(${id}); $c = $o->get_date_created()->getTimestamp(); $r = array();
  foreach ( array( array( "bk_pp_send", array( ${id}, 1 ), "r1" ), array( "bk_pp_send", array( ${id}, 2 ), "r2" ), array( "bk_pp_cancel", array( ${id} ), "c" ) ) as $a ) {
    $t = as_next_scheduled_action( $a[0], $a[1], "buckler-pp" ); $r[ $a[2] ] = $t ? round( ( $t - $c ) / 3600, 2 ) : null; }
  echo wp_json_encode( $r );`));
const mk = (ago) => +php(`$o = wc_create_order( array( "created_via" => "checkout" ) ); $o->add_product( wc_get_product( 24 ), 1 ); $o->set_billing_email( "pp@ejemplo.com" ); $o->calculate_totals(); $o->set_date_created( time() - ${ago} * 3600 ); $o->save(); echo $o->get_id();`);

const cfg = JSON.parse(php('echo wp_json_encode( bk_pp_cfg() );'));
ok(cfg.remind.join(',') === '1,12' && cfg.cancel === 24, 'tiempos: recordatorios 1 h y 12 h, cancelación 24 h ' + JSON.stringify(cfg));

// Pedido nuevo.
const a = mk(0);
php(`bk_pp_track( wc_get_order( ${a} ) );`);
const ha = hours(a);
ok(ha.r1 === 1 && ha.r2 === 12 && ha.c === 24, 'pedido nuevo: 1 h, 12 h y cancelación a las 24 h ' + JSON.stringify(ha));
ok(php(`echo apply_filters( "woocommerce_cancel_unpaid_order", true, wc_get_order( ${a} ) ) ? "si" : "no";`) === 'no', 'WooCommerce no lo cancela a los 60 min mientras corre lo propio');

// Pedido que ya esperaba con los tiempos viejos (1/24/48), creado hace 3 h.
const b = mk(3);
php(`$o = wc_get_order( ${b} ); $c = $o->get_date_created()->getTimestamp();
  as_schedule_single_action( $c + 3600, "bk_pp_send", array( ${b}, 1 ), "buckler-pp" );
  as_schedule_single_action( $c + 24 * 3600, "bk_pp_send", array( ${b}, 2 ), "buckler-pp" );
  as_schedule_single_action( $c + 48 * 3600, "bk_pp_cancel", array( ${b} ), "buckler-pp" );
  $o->update_meta_data( "_bk_pp_sched", time() - 3 * 3600 ); $o->update_meta_data( "_bk_pp_sent", array( 1 => array( "t" => time() - 7200, "step" => 1, "by" => "" ) ) ); $o->save();
  update_option( "bk_pp_timing", "1-24/48" );`);
// Pedido viejo con los automáticos detenidos: no se toca.
const d = mk(3);
php(`$o = wc_get_order( ${d} ); as_schedule_single_action( time() + 99 * 3600, "bk_pp_cancel", array( ${d} ), "buckler-pp" ); $o->update_meta_data( "_bk_pp_sched", time() ); $o->update_meta_data( "_bk_pp_stopped", "yes" ); $o->save();`);
php('');   // carga WordPress: init reprograma una vez
const hb = hours(b);
ok(hb.r2 === 12 && hb.c === 24, 'pedido en curso reprogramado: 2.º recordatorio a las 12 h y cancelación a las 24 h ' + JSON.stringify(hb));
ok(php('echo get_option( "bk_pp_timing" );') === '1-12/24', 'la reprogramación queda marcada (no se repite en cada carga)');
ok(php(`echo count( (array) wc_get_order( ${b} )->get_meta( "_bk_pp_sent" ) );`) === '1', 'el recordatorio ya enviado no se borra (no se repite)');
ok(hours(d).c > 90, 'pedido con automáticos detenidos: no se reprograma');

// Cancelación real a las 24 h: el pedido de hace 25 h se cancela y las prendas vuelven.
const e = mk(25);
php(`wc_reduce_stock_levels( ${e} );`);
const s0 = +php('echo wc_get_product(24)->get_stock_quantity();');
php(`bk_pp_track( wc_get_order( ${e} ) ); do_action( "bk_pp_cancel", ${e} );`);
ok(php(`echo wc_get_order( ${e} )->get_status();`) === 'cancelled', 'a las 24 h sin pagar: se cancela');
ok(+php('echo wc_get_product(24)->get_stock_quantity();') === s0 + 1, 'las prendas apartadas vuelven al inventario');

// Snippet de producción: descuenta al pasar a Pendiente con wc_reduce_stock_levels() (sin la marca del pedido).
const st = () => +php('echo wc_get_product(24)->get_stock_quantity();');
const snip = (id) => php(`wc_reduce_stock_levels( ${id} );`);
const f = mk(0); const f0 = st(); snip(f);
ok(st() === f0 - 1, 'snippet: el pedido pendiente aparta la prenda');
php(`wc_get_order( ${f} )->update_status( "cancelled" );`);
ok(st() === f0, 'cancelado a mano / por WooCommerce: la prenda vuelve (antes se perdía)');
php(`wc_get_order( ${f} )->update_status( "cancelled" );`);
ok(st() === f0, 'cancelar otra vez no devuelve dos veces');
const g = mk(0); const g0 = st(); snip(g);
php(`wc_get_order( ${g} )->update_status( "failed" );`);
ok(st() === g0, 'pago fallido: la prenda vuelve');
const h = mk(0); const h0 = st(); snip(h);
php(`wc_get_order( ${h} )->update_status( "processing" );`);
ok(st() === h0 - 1, 'pagado: se descuenta una sola vez (no doble)');
php(`wc_get_order( ${h} )->update_status( "cancelled" );`);
ok(st() === h0, 'pagado y luego cancelado: vuelve una sola vez');

php(`foreach ( array( ${f}, ${g}, ${h} ) as $i ) { $o = wc_get_order( $i ); if ( $o ) { $o->delete( true ); } }`);
php(`foreach ( array( ${a}, ${b}, ${d}, ${e} ) as $i ) { bk_pp_unschedule( $i ); $o = wc_get_order( $i ); if ( $o ) { $o->delete( true ); } }`);
console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
process.exit(fails ? 1 : 0);
