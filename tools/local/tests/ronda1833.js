// 0.18.33: (1) el interruptor "Nuevo" del editor de productos marca/desmarca al instante "LÍNEA NUEVA" y "NUEVO – línea"
// en "Todas las categorías" (y al revés), y al guardar quedan quitadas/puestas. (2) "Cambiar prendas" de un pedido:
// quitar/cambiar talla/agregar, preguntar si vuelve al inventario, aviso si cambia el total, sin correo a la clienta.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  // Como en producción: LÍNEA NUEVA con hijas "slim" (NUEVO – SLIM) y "curvy" (NUEVO – CURVY).
  const ids = JSON.parse(php('$p = get_term_by( "slug", "linea-nueva", "product_cat" ); $o = array( "parent" => $p->term_id ); foreach ( array( "slim" => "NUEVO – SLIM", "curvy" => "NUEVO – CURVY" ) as $s => $n ) { $t = get_term_by( "slug", $s, "product_cat" ); if ( ! $t ) { $r = wp_insert_term( $n, "product_cat", array( "slug" => $s, "parent" => $p->term_id ) ); $o[ $s ] = $r["term_id"]; } else { $o[ $s ] = $t->term_id; } } echo json_encode( $o );'));
  const pid = +php('$q = wc_get_products( array( "sku" => "9897", "limit" => 1 ) ); $p = $q[0]; $c = $p->get_category_ids(); $c[] = ' + ids.slim + '; $c[] = ' + ids.parent + '; $p->set_category_ids( array_unique( $c ) ); $p->set_featured( true ); $p->save(); echo $p->get_id();');
  const cats = () => JSON.parse(php(`$p = wc_get_product( ${pid} ); echo json_encode( array( "c" => array_map( "intval", $p->get_category_ids() ), "f" => $p->is_featured() ) );`));
  ok(cats().c.includes(ids.slim) && cats().f, 'producto de prueba (Slim) arranca como Nuevo con LÍNEA NUEVA + NUEVO – SLIM');

  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const open = async () => { await g.goto(B + '/gestion-tienda/'); await g.goto(B + `/gestion-tienda/#productos/editar/${pid}`); await g.reload(); await g.waitForSelector('[name="is_new"]', { state: 'attached' }); };
  const tree = (id) => g.locator(`[name="all_cats"][value="${id}"]`).isChecked();
  const save = async () => { await g.locator('#bk-gp button[type=submit]').first().click(); await g.waitForFunction(() => /guardados|publicado/i.test(document.querySelector('#bk-g-toast')?.textContent || ''), null, { timeout: 20000 }).catch(async () => console.log('TOAST:', await g.locator('#bk-g-toast').textContent(), '| ERR:', await g.locator('.bk-g-err, [role=alert]').allInnerTexts())); await g.waitForTimeout(400); };

  await open();
  ok(await tree(ids.parent) && await tree(ids.slim), 'al abrir: LÍNEA NUEVA y NUEVO – SLIM marcadas en "Todas las categorías"');
  await g.locator('[name="is_new"]').uncheck();
  ok(!(await tree(ids.parent)) && !(await tree(ids.slim)), 'apagar Nuevo desmarca al instante LÍNEA NUEVA y NUEVO – SLIM');
  ok(/(\d+) marcadas/.test(await g.locator('.bk-gp-tree summary small').innerText()), 'el contador de categorías se actualiza');
  await save();
  let c = cats();
  ok(!c.c.includes(ids.parent) && !c.c.includes(ids.slim) && !c.f, 'guardado: sin LÍNEA NUEVA, sin NUEVO – SLIM y sin etiqueta Nuevo');
  ok(c.c.length > 0, 'las demás categorías se conservan');

  await open();
  await g.locator('[name="is_new"]').check();
  ok(await tree(ids.parent) && await tree(ids.slim) && !(await tree(ids.curvy)), 'encender Nuevo marca LÍNEA NUEVA + NUEVO – SLIM (no la de Curvy)');
  await save();
  c = cats();
  ok(c.c.includes(ids.parent) && c.c.includes(ids.slim) && !c.c.includes(ids.curvy) && c.f, 'guardado: vuelve a Nuevo con sus dos categorías');

  await open();
  await g.locator('.bk-gp-tree summary').click();
  await g.locator(`[name="all_cats"][value="${ids.slim}"]`).uncheck();
  ok(!(await g.locator('[name="is_new"]').isChecked()) && !(await tree(ids.parent)), 'desmarcar NUEVO – SLIM en la lista apaga el interruptor Nuevo');
  await g.locator(`[name="all_cats"][value="${ids.parent}"]`).check();
  ok(await g.locator('[name="is_new"]').isChecked() && await tree(ids.slim), 'marcar LÍNEA NUEVA en la lista enciende Nuevo y marca NUEVO – SLIM');

  // ---- 2. Cambiar prendas de un pedido ----
  const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
  const fs = require('fs');
  const mails = () => (fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter((f) => f.includes('cambioejemplo-com')).length : 0);
  php('foreach ( wc_get_orders( array( "billing_email" => "cambio@ejemplo.com", "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); }');
  // Tallas del 9897 (mismo precio) y un producto de otro precio (9907), con 10 unidades cada talla.
  const V = JSON.parse(php('$o = array(); foreach ( array( "9897", "9907" ) as $sku ) { $q = wc_get_products( array( "sku" => $sku, "limit" => 1 ) ); foreach ( $q[0]->get_children() as $vid ) { $v = wc_get_product( $vid ); $v->set_status( "publish" ); $v->set_manage_stock( true ); $v->set_stock_quantity( 10 ); $v->set_stock_status( "instock" ); $v->save(); $o[ $sku ][] = array( "id" => $vid, "price" => (float) $v->get_price(), "size" => wc_get_formatted_variation( $v, true, false, false ) ); } } echo json_encode( $o );'));
  const [A, Bv, Cv] = V['9897']; const D = V['9907'][0];
  const oid = +php(`$o = wc_create_order(); $o->add_product( wc_get_product( ${A.id} ), 1 ); $o->add_product( wc_get_product( ${Bv.id} ), 2 ); $o->set_billing_first_name( "Clara" ); $o->set_billing_email( "cambio@ejemplo.com" ); $o->set_billing_phone( "3001112233" ); $o->calculate_totals(); $o->set_status( "processing" ); $o->save(); wc_maybe_reduce_stock_levels( $o->get_id() ); echo $o->get_id();`);
  const stock = (id) => +php(`echo wc_get_product( ${id} )->get_stock_quantity();`);
  const ord = () => JSON.parse(php(`$o = wc_get_order( ${oid} ); $l = array(); foreach ( $o->get_items() as $i ) { $l[] = array( "v" => $i->get_variation_id(), "q" => $i->get_quantity(), "r" => (int) $i->get_meta( "_reduced_stock" ), "t" => (float) $i->get_total() ); } echo json_encode( array( "l" => $l, "total" => (float) $o->get_total(), "h" => wp_list_pluck( bk_g_history( $o ), "label" ), "n" => implode( " | ", wp_list_pluck( wc_get_order_notes( array( "order_id" => ${oid} ) ), "content" ) ) ) );`));
  ok(stock(A.id) === 9 && stock(Bv.id) === 8, 'pedido de prueba con inventario descontado (talla A 1, talla B 2)');
  const t0 = ord().total; const m0 = mails();

  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector(`[data-order="${oid}"]`);
  await g.locator(`[data-order="${oid}"]`).first().click();
  await g.waitForSelector('[data-items-edit]');
  ok(await g.locator('[data-items-edit]').isVisible(), 'detalle del pedido: botón "Cambiar prendas"');
  await g.click('[data-items-edit]');
  await g.waitForSelector('#bk-gm.is-edit .bk-gm-line');
  await g.waitForSelector('#bk-g-drawer', { state: 'hidden' }).catch(() => {});
  ok(await g.locator('#bk-gm .bk-gm-lines:not(.bk-gm-out) .bk-gm-line').count() === 2 && await g.locator('#bk-g-drawer').isHidden(), 'pantalla de cambio con las 2 prendas del pedido (panel cerrado)');

  // Cambio de talla: A sale, entra C (mismo precio).
  await g.locator('.bk-gm-line', { hasText: `Talla ${A.size}` }).locator('[data-swap]').click();
  await g.waitForSelector(`[data-add="${Cv.id}"]`);
  ok(await g.locator('.bk-gm-out .bk-gm-line').count() === 1, '"Cambiar talla o prenda": la talla vieja pasa a "Sale del pedido" y busca las otras tallas');
  await g.click(`[data-add="${Cv.id}"]`);
  await g.click('#bk-gm-save');
  await g.waitForSelector('#bk-gm-dlg[open]');
  const dlg1 = await g.locator('#bk-gm-dlg').innerText();
  ok(/Sale del pedido/.test(dlg1) && /Entra al pedido/.test(dlg1) && await g.locator('#bk-gm-dlg [name=restock]').isChecked(), 'resumen: sale / entra y "Vuelve al inventario" marcado');
  ok(await g.locator('.bk-gm-alert').count() === 0 && /El total sigue igual/.test(dlg1), 'mismo precio: sin alerta de valor');
  await g.screenshot({ path: '/tmp/claude-0/cambio-1440.png' });
  await g.click('[data-dlg-ok]');
  await g.waitForSelector('[data-items-edit]', { timeout: 15000 });
  let o = ord();
  ok(stock(A.id) === 10 && stock(Cv.id) === 9 && stock(Bv.id) === 8, 'inventario: talla vieja vuelve (+1), talla nueva se descuenta (−1)');
  ok(o.l.length === 2 && o.l.some((l) => l.v === Cv.id && l.q === 1 && l.r === 1) && !o.l.some((l) => l.v === A.id), 'pedido: sin la talla vieja, con la nueva marcada como descontada');
  ok(Math.abs(o.total - t0) < 1, 'total igual');
  ok(o.h.some((h) => /Cambio de prendas/.test(h)) && /vuelve al inventario/.test(o.n), 'historial "Cambio de prendas" + nota');

  // Segundo cambio: B baja de 2 a 1 sin devolver al inventario y entra otra prenda más cara → alerta.
  await g.goto(B + `/gestion-tienda/#pedidos/prendas/${oid}`); await g.waitForSelector('#bk-gm.is-edit .bk-gm-line');
  await g.locator('.bk-gm-line', { hasText: `Talla ${Bv.size}` }).locator('[data-d="-1"]').click();
  await g.fill('#bk-gm-q', '9907'); await g.waitForSelector(`[data-add="${D.id}"]`);
  await g.click(`[data-add="${D.id}"]`);
  ok(/→/.test(await g.locator('#bk-gm-total').innerText()), 'la barra muestra total actual → nuevo');
  await g.click('#bk-gm-save'); await g.waitForSelector('#bk-gm-dlg[open]');
  const diff = D.price - Bv.price;
  const al = await g.locator('.bk-gm-alert').innerText().catch(() => '');
  ok(/va a cambiar/.test(al) && (diff > 0 ? /debe pagar/.test(al) : /devolverle/.test(al)), `precio distinto: alerta "${al.replace(/\s+/g, ' ').trim()}"`);
  await g.locator('#bk-gm-dlg [name=restock]').uncheck();
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, storageState: await g.context().storageState() })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  await g.click('[data-dlg-ok]');
  await g.waitForSelector('[data-items-edit]', { timeout: 15000 });
  o = ord();
  ok(stock(Bv.id) === 8 && stock(D.id) === 9, 'sin devolver: la talla B no vuelve; la prenda nueva se descuenta');
  ok(o.l.some((l) => l.v === Bv.id && l.q === 1 && l.r === 1), 'la línea B queda con 1 y su marca de descontado en 1');
  ok(Math.abs(o.total - (t0 + diff)) < 1, `total actualizado (${t0} → ${o.total})`);
  ok(mails() === m0, 'no se envió correo a la clienta');

  // Celular.
  await m.goto(B + `/gestion-tienda/#pedidos/prendas/${oid}`); await m.waitForSelector('#bk-gm.is-edit .bk-gm-line');
  await m.locator('.bk-gm-line').first().locator('.bk-gm-del').click();
  await m.click('#bk-gm-save'); await m.waitForSelector('#bk-gm-dlg[open]');
  ok(await m.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'celular: sin desbordes');
  await m.screenshot({ path: '/tmp/claude-0/cambio-390.png' });
  await m.click('[data-dlg-close]');

  // Cancelar después del cambio devuelve solo lo que sigue en el pedido.
  php(`wc_get_order( ${oid} )->update_status( "cancelled" );`);
  ok(stock(A.id) === 10 && stock(Bv.id) === 9 && stock(Cv.id) === 10 && stock(D.id) === 10, 'al cancelar vuelven solo las prendas que siguen en el pedido (sin duplicar)');
  await g.goto(B + '/gestion-tienda/'); await g.goto(B + '/gestion-tienda/#pedidos'); await g.reload(); await g.waitForSelector(`[data-order="${oid}"]`);
  await g.locator(`[data-order="${oid}"]`).first().click(); await g.waitForSelector('#bk-g-osave');
  ok(await g.locator('[data-items-edit]').count() === 0, 'pedido cancelado: sin "Cambiar prendas"');

  // Pendiente sin inventario descontado: lo que sale no "vuelve".
  const pid2 = +php(`$o = wc_create_order(); $o->add_product( wc_get_product( ${A.id} ), 1 ); $o->add_product( wc_get_product( ${Bv.id} ), 1 ); $o->set_billing_email( "cambio@ejemplo.com" ); $o->calculate_totals(); $o->save(); echo $o->get_id();`);
  await g.goto(B + `/gestion-tienda/#pedidos/prendas/${pid2}`); await g.waitForSelector('#bk-gm.is-edit .bk-gm-line');
  await g.locator('.bk-gm-line').first().locator('.bk-gm-del').click();
  await g.click('#bk-gm-save'); await g.waitForSelector('#bk-gm-dlg[open]');
  ok(/no se había descontado/.test(await g.locator('#bk-gm-dlg').innerText()) && await g.locator('#bk-gm-dlg [name=restock]').count() === 0, 'pedido sin inventario descontado: avisa que no hay nada que devolver');
  await g.click('[data-dlg-close]');
  ok(errs.length === 0, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  console.log(fails ? `\n${fails} FAIL` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
