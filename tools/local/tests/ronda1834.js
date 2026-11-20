// 0.18.35: en el detalle del pedido, "Pedidos de esta clienta": otros pedidos con la misma cédula, correo o celular.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const SHOT = process.env.SHOT || '/tmp/claude-0/clienta';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  require('fs').mkdirSync(SHOT, { recursive: true });
  php('foreach ( wc_get_orders( array( "limit" => -1, "status" => "any", "meta_key" => "_bk_test_rel", "meta_compare" => "EXISTS" ) ) as $o ) { $o->delete( true ); }');
  // [nombre, correo, celular, cédula, estado, días atrás]
  const mk = (n, email, phone, ced, st, days) => +php(`$o = wc_create_order(); $p = wc_get_products( array( "limit" => 1, "type" => "variable", "status" => "publish" ) ); $v = wc_get_product( $p[0]->get_children()[0] ); $o->add_product( $v, 1 ); $o->set_billing_first_name( "${n}" ); $o->set_billing_last_name( "Gómez" ); $o->set_billing_email( "${email}" ); $o->set_billing_phone( "${phone}" ); $o->set_billing_city( "Medellín" ); ${ced ? `$o->update_meta_data( "_billing_id", "${ced}" );` : ''} $o->update_meta_data( "_bk_test_rel", 1 ); $o->calculate_totals(); $o->set_date_created( time() - ${days} * DAY_IN_SECONDS ); $o->set_status( "${st}" ); $o->save(); echo $o->get_id();`);
  const main = mk('Laura', 'laura.rel@ejemplo.com', '3009998811', '1099888777', 'processing', 0);
  const byEmail = mk('Laura', 'Laura.Rel@ejemplo.com', '3119998877', '', 'completed', 40);
  const byPhone = mk('Lau', 'otro.correo@ejemplo.com', '+57 300 999 8811', '', 'completed', 90);
  const byCed = mk('L.', 'tercero@ejemplo.com', '3205554433', '1.099.888.777', 'cancelled', 200);
  const other = mk('Paula', 'paula.rel@ejemplo.com', '3150001122', '52333444', 'processing', 1);
  const first = mk('Nueva', 'nueva.rel@ejemplo.com', '3170001122', '1144000111', 'processing', 2);
  const num = (id) => php(`echo wc_get_order( ${id} )->get_order_number();`);

  const b = await chromium.launch();
  const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 } });
  const g = await ctx.newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);

  const api = (path) => g.evaluate(async (p) => (await fetch(bkG.api + p, { credentials: 'same-origin', headers: { 'X-WP-Nonce': bkG.nonce }, cache: 'no-store' })).json(), path);
  const d = await api(`orders/${main}/related`);
  const ids = d.orders.map((x) => x.id);
  ok(d.count === 4 && ids.includes(byEmail) && ids.includes(byPhone) && ids.includes(byCed) && !ids.includes(other), `encuentra los 3 pedidos de la misma clienta y no el de otra (${d.count} en total: ${JSON.stringify(d.orders.map((x) => [x.id, x.match]))} esperados ${[byEmail, byPhone, byCed]})`);
  ok(d.orders.find((x) => x.id === byEmail).match.includes('correo'), 'correo con mayúsculas distintas también coincide');
  ok(d.orders.find((x) => x.id === byPhone).match.includes('celular'), 'celular escrito "+57 300 111 2233" coincide con 3009998811');
  ok(d.orders.find((x) => x.id === byCed).match.includes('cedula'), 'cédula con puntos "1.099.888.777" coincide');
  ok(d.paid === 3 && ids[0] === byEmail, `pagado: 3 pedidos (el cancelado no cuenta); orden del más reciente al más viejo`);
  const anon = await g.evaluate(async (u) => (await fetch(u + `/wp-json/bk/v1/gestion/orders/1/related`)).status, B);
  ok(anon === 401 || anon === 403, `sin sesión: la ruta no responde datos (${anon})`);

  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector(`[data-order="${main}"]`);
  await g.locator(`[data-order="${main}"]`).first().click();
  await g.waitForSelector('#bk-g-rel .bk-g-relrow');
  const txt = await g.locator('#bk-g-rel').textContent();
  ok(/Pedidos de esta clienta \(4\)/.test(txt) && /pedidos en total/.test(txt) && /primera compra/.test(txt), 'detalle: sección "Pedidos de esta clienta (4)" con resumen');
  ok(await g.locator('#bk-g-rel .bk-g-relrow').count() === 3 && /Coincide por cédula/.test(txt), 'lista los 3 pedidos con el dato que coincide');
  await g.locator('#bk-g-rel').scrollIntoViewIfNeeded(); await g.waitForTimeout(300);
  await g.screenshot({ path: `${SHOT}/detalle-1440.png` });
  await g.locator(`#bk-g-rel [data-order="${byPhone}"]`).click();
  await g.waitForFunction((n) => document.querySelector('#bk-g-dtitle')?.textContent.includes(n), num(byPhone));
  ok(true, 'tocar un pedido de la lista abre ese pedido');
  await g.waitForSelector('#bk-g-rel .bk-g-relrow');
  ok(await g.locator('#bk-g-rel .bk-g-relrow').count() === 1 && await g.locator(`#bk-g-rel [data-order="${main}"]`).count() === 1, 'desde el otro pedido (solo comparte el celular) se ve el pedido con ese celular');

  await g.goto(B + '/gestion-tienda/'); await g.goto(B + '/gestion-tienda/#pedidos'); await g.reload(); await g.waitForSelector(`[data-order="${first}"]`);
  await g.locator(`[data-order="${first}"]`).first().click();
  await g.waitForFunction(() => /primera compra/.test(document.querySelector('#bk-g-rel')?.textContent || '') && !/Buscando/.test(document.querySelector('#bk-g-rel').textContent));
  ok(/Es la primera compra/.test(await g.locator('#bk-g-rel').innerText()), 'clienta nueva: "Es la primera compra de esta clienta"');

  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  await m.goto(B + '/gestion-tienda/#pedidos'); await m.waitForSelector(`[data-order="${main}"]`);
  await m.locator(`[data-order="${main}"]`).first().click();
  await m.waitForSelector('#bk-g-rel .bk-g-relrow');
  await m.locator('#bk-g-rel').scrollIntoViewIfNeeded(); await m.waitForTimeout(300);
  ok(await m.evaluate(() => { const p = document.querySelector('#bk-g-rel'); return p.scrollWidth <= p.clientWidth + 1; }), 'celular: la sección no se desborda');
  await m.screenshot({ path: `${SHOT}/detalle-390.png` });

  ok(errs.length === 0, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  console.log(fails ? `\n${fails} FAIL` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
