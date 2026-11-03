// 0.18.30: pedido manual desde Gestión → Pedidos (clienta especial / WhatsApp): buscar prendas por nombre o REF,
// elegir tallas con lo que queda, precio especial, datos de la clienta, pago y estado. Reserva el inventario al guardar
// y lo devuelve al cancelar.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const mails = (slug) => (fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter((f) => f.endsWith('.json') && f.includes(slug)).length : 0);

(async () => {
  php('foreach ( wc_get_orders( array( "billing_email" => array( "manual@ejemplo.com", "manual2@ejemplo.com" ), "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); } delete_transient( "bk_g_summary" );');
  // Producto variable con al menos 2 tallas gestionadas: una con 3 unidades y otra agotada.
  const P = JSON.parse(php(`
    foreach ( wc_get_products( array( "type" => "variable", "status" => "publish", "limit" => 40 ) ) as $p ) {
      $kids = $p->get_children(); if ( count( $kids ) < 2 || ! $p->get_sku() ) { continue; }
      $a = wc_get_product( $kids[0] ); $b = wc_get_product( $kids[1] );
      $keep = array( $a->get_id() => array( $a->get_manage_stock(), $a->get_stock_quantity() ), $b->get_id() => array( $b->get_manage_stock(), $b->get_stock_quantity() ) );
      update_option( "bk_test_manual_keep", $keep );
      $a->set_manage_stock( true ); $a->set_stock_quantity( 3 ); $a->save();
      $b->set_manage_stock( true ); $b->set_stock_quantity( 0 ); $b->save();
      echo wp_json_encode( array( "id" => $p->get_id(), "name" => $p->get_name(), "sku" => $p->get_sku(), "a" => $a->get_id(), "b" => $b->get_id(), "price" => (float) $a->get_price(), "asize" => wc_get_formatted_variation( $a, true, false, false ) ) ); break;
    }`));
  const stock = (id) => +php(`echo (int) wc_get_product( ${id} )->get_stock_quantity();`);

  const b = await chromium.launch();
  const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const g = await ctx.newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos');
  await g.waitForSelector('a[href="#pedidos/nuevo"]');
  ok(true, 'Pedidos: botón "Nuevo pedido"');
  await g.click('a[href="#pedidos/nuevo"]');
  await g.waitForSelector('#bk-gm');
  ok(await g.locator('h1').innerText() === 'Nuevo pedido manual', 'abre el formulario de pedido manual');

  // Buscar por REF.
  await g.fill('#bk-gm-q', P.sku.slice(0, 6));
  await g.waitForSelector(`.bk-gm-size[data-add="${P.a}"]`, { timeout: 8000 });
  const sz = await g.evaluate((p) => ({ a: document.querySelector(`[data-add="${p.a}"]`)?.textContent, aOff: document.querySelector(`[data-add="${p.a}"]`)?.disabled, bOff: document.querySelector(`[data-add="${p.b}"]`)?.disabled, bTxt: document.querySelector(`[data-add="${p.b}"]`)?.textContent }), P);
  ok(/quedan 3/.test(sz.a) && !sz.aOff, `buscar por REF: talla con "quedan 3" (${sz.a})`);
  ok(sz.bOff && /agotada/.test(sz.bTxt), 'talla agotada: no se puede elegir');
  // Buscar por nombre también encuentra.
  await g.fill('#bk-gm-q', P.name.split(' ').slice(0, 2).join(' '));
  await g.waitForSelector(`.bk-gm-size[data-add="${P.a}"]`, { timeout: 8000 });
  ok(true, 'buscar por nombre');

  // Agregar 2, intentar pasar del stock.
  await g.click(`.bk-gm-size[data-add="${P.a}"]`);
  await g.click('[data-qty="0"][data-d="1"]');
  await g.click('[data-qty="0"][data-d="1"]');
  const q = await g.evaluate(() => ({ n: document.querySelector('.bk-gm-qty output').textContent, plus: document.querySelector('[data-qty="0"][data-d="1"]').disabled, chip: document.querySelector('.bk-gm-size:not([disabled])') === null }));
  ok(q.n === '3' && q.plus, `cantidad no pasa de lo que queda (3; botón + desactivado)`);
  await g.click('[data-qty="0"][data-d="-1"]');
  ok(await g.locator('.bk-gm-qty output').innerText() === '2', 'botón − baja la cantidad');
  // Precio especial.
  const special = Math.round(P.price * 0.8 / 100) * 100;
  await g.fill('[data-price="0"]', String(special));
  await g.locator('[data-price="0"]').blur();
  ok(new RegExp(`Total \\$${(special * 2).toLocaleString('es-CO').replace(/\./g, '\\.')}`).test(await g.locator('#bk-gm-total').innerText()), `precio especial: total ${await g.locator('#bk-gm-total b').innerText()}`);
  await g.fill('[name=shipping]', '12000');
  ok(/Envío \$12\.000/.test(await g.locator('#bk-gm-total').innerText()), 'costo de envío suma al total');

  // Validaciones en línea.
  await g.click('#bk-gm-save');
  ok(/nombre de la clienta/.test(await g.locator('#bk-gm-err').innerText()) && await g.evaluate(() => document.activeElement?.name) === 'first_name', 'sin nombre: mensaje y foco en el campo');
  await g.fill('[name=first_name]', 'Clienta'); await g.fill('[name=last_name]', 'Especial'); await g.fill('[name=phone]', '300 12');
  await g.click('#bk-gm-save');
  ok(/10 dígitos/.test(await g.locator('#bk-gm-err').innerText()), 'celular incompleto: mensaje');
  await g.fill('[name=phone]', '+57 300 123 4567'); await g.fill('[name=email]', 'manual@ejemplo.com'); await g.fill('[name=id_number]', '1037654321');
  await g.selectOption('[name=state]', 'CO-ANT'); await g.fill('[name=city]', 'Medellín'); await g.fill('[name=address_1]', 'Calle 10 # 20-30');
  await g.selectOption('[name=payment]', 'transferencia');
  ok(await g.locator('[name=notify]').isDisabled(), 'correo a la clienta desactivado mientras quede "Reservado, sin pagar"');
  await g.fill('[name=note]', 'Recoge en bodega el viernes');
  const s0 = stock(P.a);
  const [resp] = await Promise.all([g.waitForResponse((r) => /orders\/manual/.test(r.url())), g.click('#bk-gm-save')]);
  const d = await resp.json();
  ok(resp.status() === 200 && d.id, `pedido creado #${d.number} (${d.total})`);
  await g.waitForFunction(() => location.hash.startsWith('#pedidos') && !location.hash.includes('nuevo'));
  await g.waitForSelector('.bk-g-drawer, [role=dialog]', { timeout: 8000 }).catch(() => {});
  ok(/Pedido #\d+ creado/.test(await g.locator('#bk-g-toast').innerText().catch(() => '')), 'aviso "Pedido creado · prendas reservadas" y abre el detalle');

  const O = JSON.parse(php(`$o = wc_get_order( ${d.id} ); $it = current( $o->get_items() );
    echo wp_json_encode( array( "status" => $o->get_status(), "via" => $o->get_created_via(), "flag" => (bool) $o->get_data_store()->get_stock_reduced( $o->get_id() ), "qty" => $it->get_quantity(), "line" => (float) $it->get_total(), "ship" => (float) $o->get_shipping_total(), "total" => (float) $o->get_total(), "pay" => $o->get_payment_method_title(), "phone" => $o->get_billing_phone(), "ced" => $o->get_meta( "_billing_id" ), "city" => $o->get_billing_city(), "src" => function_exists( "bk_g_source" ) ? bk_g_source( $o )["key"] : "", "pp" => (bool) as_next_scheduled_action( "bk_pp_cancel", array( $o->get_id() ), "buckler-pp" ), "notes" => implode( " | ", wp_list_pluck( wc_get_order_notes( array( "order_id" => $o->get_id() ) ), "content" ) ), "hist" => wp_list_pluck( (array) $o->get_meta( "_bk_history" ), "ev" ) ) );`));
  ok(O.status === 'pending' && O.via === 'admin', `estado "Pendiente de pago" y creado a mano (${O.status}, ${O.via})`);
  ok(stock(P.a) === s0 - 2 && O.flag, `inventario reservado: ${s0} → ${stock(P.a)} (marca de descuento puesta)`);
  ok(O.qty === 2 && O.line === special * 2 && O.ship === 12000 && O.total === special * 2 + 12000, `líneas con precio especial y envío (total ${O.total})`);
  ok(O.pay === 'Transferencia bancaria' && O.phone === '3001234567' && O.ced === '1037654321' && O.city === 'Medellín', 'medio de pago, celular sin +57, cédula y ciudad guardados');
  ok(O.src === 'manual', 'origen de la venta: "Creado a mano"');
  ok(!O.pp, 'no recibe recordatorios ni cancelación automática de pago');
  ok(/creado a mano/i.test(O.notes) && /Recoge en bodega/.test(O.notes) && O.hist.includes('manual'), 'nota interna + historial "Pedido creado a mano"');

  // Cancelar devuelve las prendas.
  php(`wc_get_order( ${d.id} )->update_status( "cancelled" );`);
  ok(stock(P.a) === s0, `al cancelar, las prendas vuelven (${stock(P.a)})`);

  // Pagado, con y sin correo a la clienta (por la API, desde la sesión de la gestión).
  const call = (body) => g.evaluate(async (bd) => { const r = await fetch(window.bkG.api + 'orders/manual', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.bkG.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(bd) }); return { s: r.status, j: await r.json() }; }, body);
  const base = (email, extra = {}) => ({ customer: { first_name: 'Pagada', phone: '3009998877', email }, items: [{ id: P.a, qty: 1 }], payment: 'efectivo', ...extra });
  const m0 = mails('manual2ejemplo-com');
  const r1 = await call(base('manual2@ejemplo.com', { status: 'processing', notify: false }));
  const st1 = php(`echo wc_get_order( ${r1.j.id} )->get_status();`);
  ok(r1.s === 200 && st1 === 'processing' && stock(P.a) === s0 - 1, `pagado: queda "En proceso" y descuenta una sola vez (${stock(P.a)})`);
  ok(mails('manual2ejemplo-com') === m0, 'sin marcar "enviar correo": la clienta no recibe correo');
  const r2 = await call(base('manual2@ejemplo.com', { status: 'processing', notify: true }));
  ok(r2.s === 200 && mails('manual2ejemplo-com') > m0, 'con "enviar correo": la clienta recibe la confirmación');
  const r3 = await call(base('manual2@ejemplo.com', { items: [{ id: P.a, qty: 9 }] }));
  ok(r3.s === 400 && /solo quedan/.test(r3.j.message), `más de lo que hay: "${r3.j.message}"`);
  const r4 = await call(base('manual2@ejemplo.com', { items: [{ id: P.b, qty: 1 }] }));
  ok(r4.s === 400 && /agotada/.test(r4.j.message), `talla agotada por la API: "${r4.j.message}"`);
  const r5 = await call({ customer: { first_name: 'X', phone: '3001112233' }, items: [] });
  ok(r5.s === 400 && /al menos una prenda/.test(r5.j.message), 'sin prendas: no crea nada');

  // Celular.
  await g.setViewportSize({ width: 390, height: 844 });
  await g.goto(B + '/gestion-tienda/#pedidos/nuevo'); await g.waitForSelector('#bk-gm');
  await g.fill('#bk-gm-q', P.sku.slice(0, 6));
  await g.waitForSelector(`.bk-gm-size[data-add="${P.a}"]`, { timeout: 8000 });
  await g.click(`.bk-gm-size[data-add="${P.a}"]`);
  const mob = await g.evaluate(() => ({ sx: document.documentElement.scrollWidth > innerWidth, line: document.querySelector('.bk-gm-line').getBoundingClientRect().width <= innerWidth, foot: getComputedStyle(document.querySelector('.bk-gm-foot')).position }));
  ok(!mob.sx && mob.line && mob.foot === 'sticky', 'celular: sin scroll horizontal, la línea cabe y el botón de guardar queda abajo fijo');
  await g.evaluate(() => window.scrollTo(0, document.querySelector('#bk-gm-h2').getBoundingClientRect().top + scrollY - 100));
  await g.waitForTimeout(300);
  const lay = await g.evaluate(() => { const f = document.querySelector('.bk-gm-foot').getBoundingClientRect(); const t = document.querySelector('.bk-g-tabs').getBoundingClientRect(); return { fb: Math.round(f.bottom), tt: Math.round(t.top) }; });
  ok(lay.fb <= lay.tt + 1, `celular: el botón de guardar no queda tapado por las pestañas (${lay.fb} ≤ ${lay.tt})`);
  await g.screenshot({ path: '/tmp/claude-0/manual-390-view.png' });
  await g.locator('.bk-gm-line').screenshot({ path: '/tmp/claude-0/manual-390-line.png' });
  await g.screenshot({ path: '/tmp/claude-0/manual-390.png', fullPage: true });

  // Usuario de solo SEO: sin permiso.
  ok(php('$u = get_user_by( "login", "Gestor_ADS" ); wp_set_current_user( $u ? $u->ID : 0 ); echo bk_g_can() ? "si" : "no";') === 'no', 'usuario de solo SEO y ADS: no puede crear pedidos');

  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  php(`foreach ( wc_get_orders( array( "billing_email" => array( "manual@ejemplo.com", "manual2@ejemplo.com" ), "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); }
    foreach ( (array) get_option( "bk_test_manual_keep" ) as $vid => $k ) { $v = wc_get_product( $vid ); $v->set_manage_stock( $k[0] ); $v->set_stock_quantity( $k[1] ); $v->save(); } delete_option( "bk_test_manual_keep" );`);
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
