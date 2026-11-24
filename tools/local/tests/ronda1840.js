// 0.18.40: cuenta regresiva de campañas (Black Friday) en inicio, franja, tienda/filtros y ficha; fases antes/durante/fin.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  php('update_option( "bk_test_disc_backup", get_option( "bk_discounts" ) );');
  php('$m = (array) get_option( "bk_modules", array() ); if ( ! in_array( "discounts", $m, true ) ) { $m[] = "discounts"; update_option( "bk_modules", $m ); }');
  php(`$s = strtotime( "2026-11-26 00:00:00 America/Bogota" ); $e = strtotime( "2026-11-30 23:59:00 America/Bogota" );
    update_option( "bk_discounts", array( "campaigns" => array( array( "id" => "bf", "name" => "Black Friday", "pct" => 25, "start" => "2026-11-26T00:00", "end" => "2026-11-30T23:59", "start_ts" => $s, "end_ts" => $e, "scope" => "all", "exclude_sale" => true, "badge" => "BLACK FRIDAY", "bar" => true, "home" => true, "featured" => array(), "active" => true, "countdown" => true, "pre_days" => 3 ) ), "qty" => array() ) );`);
  const normal = php('$q = wc_get_products( array( "limit" => 20, "type" => "variable", "status" => "publish", "stock_status" => "instock" ) ); $sale = wc_get_product_ids_on_sale(); foreach ( $q as $p ) { if ( ! in_array( $p->get_id(), $sale, true ) && ! bk_disc_is_sale( $p ) ) { echo get_permalink( $p->get_id() ); break; } }');
  const setNow = (t) => php(t ? `update_option( "bk_test_now", strtotime( "${t} America/Bogota" ) );` : 'delete_option( "bk_test_now" );');

  const b = await chromium.launch();
  const page = async (url, t, w = 1440) => {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    await p.clock.install({ time: new Date(t.replace(' ', 'T') + '-05:00') });
    await p.goto(B + url, { waitUntil: 'domcontentloaded' });
    return p;
  };
  const errs = [];

  // Antes (3 días de anuncio): 24 de noviembre.
  setNow('2026-11-24 10:00:00');
  let p = await page('/', '2026-11-24 10:00:00');
  p.on('pageerror', (e) => errs.push(e.message));
  ok(await p.locator('.bk-cd--hero').count() === 1, 'inicio: bloque grande de cuenta regresiva');
  ok(await p.locator('.bk-cd--strip').count() === 0, 'inicio: sin franja (ya tiene el bloque)');
  ok(/Empieza en/i.test(await p.locator('.bk-cd--hero').textContent()), 'antes: "Empieza en"');
  const t1 = await p.locator('.bk-cd--hero').textContent();
  await p.clock.runFor(2000);
  ok(t1 !== await p.locator('.bk-cd--hero').textContent(), 'el contador avanza cada segundo');
  await p.close();

  p = await page('/shop/', '2026-11-24 10:00:00');
  ok(await p.locator('.bk-cd--banner').count() === 1 && await p.locator('.bk-cd--strip').count() === 1, 'tienda: banner + franja');
  await p.close();
  p = await page('/shop/?talla=8', '2026-11-24 10:00:00');
  ok(await p.locator('.bk-cd--banner').count() === 1, 'tienda con filtros: banner');
  await p.close();

  const path = normal.replace(B, '');
  p = await page(path, '2026-11-24 10:00:00');
  const pdp = await p.locator('.bk-cd--pdp').textContent();
  ok(/quedará desde \$/.test(pdp), `ficha antes: "Este producto quedará desde $…" (${pdp.replace(/\s+/g, ' ').slice(0, 90)})`);
  ok(/11:59 pm/.test(pdp), 'texto para lectores de pantalla termina a las 11:59 pm');
  ok(await p.locator('del .amount, .price del').count() === 0, 'antes: sin precio tachado todavía');
  await p.close();

  const vid = php('$q = wc_get_products( array( "limit" => 1, "type" => "variable", "status" => "publish", "stock_status" => "instock" ) ); foreach ( wc_get_product( $q[0] )->get_available_variations() as $v ) { if ( $v["is_in_stock"] ) { echo $v["variation_id"]; break; } }');
  p = await page('/?add-to-cart=' + vid + '&quantity=1', '2026-11-24 10:00:00');
  await p.goto(B + '/checkout/', { waitUntil: 'domcontentloaded' });
  ok(/checkout/.test(p.url()) && await p.locator('.bk-cd').count() === 0, 'checkout: sin cuenta regresiva (' + p.url() + ')');
  await p.goto(B + '/cart/', { waitUntil: 'domcontentloaded' });
  ok(await p.locator('.bk-cd--strip').count() === 1, 'carrito: franja con el contador');
  await p.close();

  // Antes del anuncio (20 de noviembre): nada.
  setNow('2026-11-20 10:00:00');
  p = await page('/', '2026-11-20 10:00:00');
  ok(await p.locator('.bk-cd').count() === 0, 'antes de los 3 días de anuncio: no se muestra');
  await p.close();

  // Durante: 27 de noviembre.
  setNow('2026-11-27 10:00:00');
  p = await page(path, '2026-11-27 10:00:00');
  ok(/Termina en/i.test(await p.locator('.bk-cd--pdp').textContent()), 'durante: "Termina en"');
  ok(await p.locator('.price del').count() >= 1, 'durante: precio tachado con descuento');
  ok(await p.locator('.bk-disc-box__camp').count() === 0, 'durante: sin el recuadro lima duplicado de la campaña');
  await p.close();
  p = await page('/', '2026-11-27 10:00:00', 390);
  ok(/Comprar con descuento/.test(await p.locator('.bk-cd--hero').textContent()), 'inicio durante: botón "Comprar con descuento"');
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= 390), '390 px: sin desborde horizontal');
  await p.close();

  // El navegador oculta todo al llegar la hora de fin aunque la página venga de caché.
  p = await page('/shop/', '2026-11-30 23:58:58');
  await p.clock.runFor(4000);
  ok(await p.locator('.bk-cd:visible').count() === 0, 'a las 11:59 pm del 30 se oculta sola (página en caché)');
  await p.close();

  // Después: 1 de diciembre.
  setNow('2026-12-01 00:01:00');
  p = await page(path, '2026-12-01 00:01:00');
  ok(await p.locator('.bk-cd').count() === 0 && await p.locator('.price del').count() === 0, 'después: sin contador y precio normal');
  await p.close();

  ok(errs.length === 0, 'sin errores de JS ' + errs.join(' | '));
  await b.close();
  setNow(null);
  php('update_option( "bk_discounts", get_option( "bk_test_disc_backup" ) ); delete_option( "bk_test_disc_backup" );');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
