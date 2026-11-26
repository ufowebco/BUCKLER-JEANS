// 0.18.41: cupones (10 % por reseña de Google y cualquier otro) no aplican en ¡SALE! ni con una campaña vigente (Black Friday).
const { execSync } = require('child_process');
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

// Prepara: cupón RESENA10 (10 %), campaña BF con fecha simulada, una prenda normal y una de ¡Sale!.
php(`update_option( "bk_test_disc_backup", get_option( "bk_discounts" ) );
  $m = (array) get_option( "bk_modules", array() ); if ( ! in_array( "discounts", $m, true ) ) { $m[] = "discounts"; update_option( "bk_modules", $m ); }
  $id = wc_get_coupon_id_by_code( "resena10" ); if ( ! $id ) { $c = new WC_Coupon(); $c->set_code( "resena10" ); $c->set_discount_type( "percent" ); $c->set_amount( 10 ); $c->save(); }
  $id = wc_get_coupon_id_by_code( "fijo5000" ); if ( ! $id ) { $c = new WC_Coupon(); $c->set_code( "fijo5000" ); $c->set_discount_type( "fixed_cart" ); $c->set_amount( 5000 ); $c->save(); }
  $s = strtotime( "2026-11-26 00:00:00 America/Bogota" ); $e = strtotime( "2026-11-30 23:59:00 America/Bogota" );
  update_option( "bk_discounts", array( "campaigns" => array( array( "id" => "bf", "name" => "Black Friday", "pct" => 25, "start_ts" => $s, "end_ts" => $e, "start" => "2026-11-26T00:00", "end" => "2026-11-30T23:59", "scope" => "all", "exclude_sale" => true, "badge" => "BLACK FRIDAY", "bar" => true, "home" => true, "featured" => array(), "active" => true, "countdown" => true, "pre_days" => 3 ) ), "qty" => array() ) );`);

// Escenario: [fecha simulada, prendas ("n" normal, "s" sale), cupón] → [aplicado, descuento, aviso]
const run = (now, kinds, code) => JSON.parse(php(`
  ${now ? `update_option( "bk_test_now", strtotime( "${now} America/Bogota" ) );` : 'delete_option( "bk_test_now" );'}
  wp_set_current_user( 0 ); WC()->frontend_includes(); WC()->session = new WC_Session_Handler(); WC()->session->init(); WC()->customer = new WC_Customer( 0, true ); WC()->cart = new WC_Cart(); WC()->cart->empty_cart();
  $sale = wc_get_product_ids_on_sale(); $pick = array( "n" => 0, "s" => 0 );
  foreach ( wc_get_products( array( "limit" => 60, "type" => "variable", "status" => "publish", "stock_status" => "instock" ) ) as $p ) {
    $k = bk_disc_is_sale( $p ) ? "s" : "n"; if ( $pick[ $k ] ) continue;
    foreach ( $p->get_available_variations() as $v ) { if ( $v["is_in_stock"] ) { $pick[ $k ] = array( $p->get_id(), $v["variation_id"] ); break; } }
  }
  foreach ( str_split( "${kinds}" ) as $k ) { WC()->cart->add_to_cart( $pick[ $k ][0], 1, $pick[ $k ][1] ); }
  wc_clear_notices(); $r = WC()->cart->apply_coupon( "${code}" ); WC()->cart->calculate_totals();
  $err = wc_get_notices( "error" );
  echo wp_json_encode( array( "applied" => WC()->cart->has_discount( "${code}" ), "disc" => (float) WC()->cart->get_discount_total(), "subtotal" => (float) WC()->cart->get_subtotal(), "msg" => $err ? wp_strip_all_tags( $err[0]["notice"] ) : "" ) );`));

let r = run('2026-11-20 10:00:00', 'n', 'resena10');
ok(r.applied && Math.round(r.disc) === Math.round(r.subtotal * 0.1), `sin campaña: el 10 % aplica a una prenda normal (${r.disc} de ${r.subtotal})`);
r = run('2026-11-20 10:00:00', 's', 'resena10');
ok(!r.applied && /SALE/.test(r.msg), `prenda en SALE: rechazado ("${r.msg}")`);
r = run('2026-11-20 10:00:00', 'ns', 'resena10');
r2 = run('2026-11-20 10:00:00', 'n', 'resena10');
ok(r.applied && Math.abs(r.disc - r2.disc) < 1, `normal + SALE: el 10 % solo cuenta la prenda normal (${r.disc} = ${r2.disc})`);
r = run('2026-11-27 10:00:00', 'n', 'resena10');
ok(!r.applied && /Black Friday/.test(r.msg), `durante Black Friday: rechazado ("${r.msg}")`);
r = run('2026-11-27 10:00:00', 'ns', 'resena10');
ok(!r.applied && /SALE ni con Black Friday/.test(r.msg), `Black Friday + SALE: rechazado ("${r.msg}")`);
r = run('2026-11-27 10:00:00', 'n', 'fijo5000');
ok(!r.applied, `cupón de valor fijo al carrito: tampoco se suma a Black Friday ("${r.msg}")`);
r = run('2026-11-20 10:00:00', 'ns', 'fijo5000');
ok(r.applied && r.disc === 5000, 'cupón de valor fijo al carrito: aplica sobre la prenda normal');
r = run('2026-12-01 00:01:00', 'n', 'resena10');
ok(r.applied, 'al terminar Black Friday el cupón vuelve a funcionar');

const promo = php('echo bk_review_promo( "account" ); update_option( "bk_test_now", strtotime( "2026-11-27 10:00:00 America/Bogota" ) ); echo bk_review_menu_card( "x" );');
ok(/No aplica en productos en SALE ni con/.test(promo), 'la promo de reseñas avisa que no aplica en SALE ni con la campaña');

php('delete_option( "bk_test_now" ); update_option( "bk_discounts", get_option( "bk_test_disc_backup" ) ); delete_option( "bk_test_disc_backup" );');
console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
process.exit(fails ? 1 : 0);
