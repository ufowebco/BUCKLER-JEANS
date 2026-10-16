// 0.18.1: recordatorios de pago (1 h y 24 h) y cancelación a las 48 h de pedidos pendientes de pago; envío a mano,
// detener y reanudar desde la gestión; carrito abandonado "Pendiente de pago" → Compró / cancelado sin pagar.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const lastMail = (re) => { const f = fs.readdirSync(MAILS).filter((x) => x.endsWith('.json')).sort().reverse(); for (const x of f) { const j = JSON.parse(fs.readFileSync(`${MAILS}/${x}`, 'utf8')); if (re.test(j.subject || '')) return { ...j, html: fs.readFileSync(`${MAILS}/${x.replace(/\.json$/, '.html')}`, 'utf8') }; } return null; };
const sched = (id) => JSON.parse(php(`echo json_encode(array_map(fn($a)=>$a->get_hook()."|".implode(",", $a->get_args()), array_values(as_get_scheduled_actions(["group"=>"buckler-pp","status"=>"pending","per_page"=>50])))) ;`)).filter((x) => x.endsWith(`|${id}`) || x.includes(`|${id},`));
const back = (id, h) => php(`$o=wc_get_order(${id}); $o->set_date_created(time()-${h}*3600); $o->save();`);

(async () => {
  php('update_option("bk_test_pending_gw", 1); foreach(wc_get_product(22)->get_children() as $c){ $v=wc_get_product($c); $v->set_stock_quantity(10); $v->set_stock_status("instock"); $v->save(); }');
  // Restos de corridas anteriores: pedidos de prueba pendientes (reservan unidades) y la reserva de stock.
  php('foreach(["paula.pendiente@ejemplo.com","reciente@ejemplo.com","cod@ejemplo.com"] as $e){ foreach(wc_get_orders(["billing_email"=>$e,"limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); } } global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}wc_reserved_stock");');
  execSync('php /tmp/claude-0/bk-local/t/run.php reset');
  const b = await chromium.launch();
  const errs = [];

  // 1. Compra real que queda pendiente (la clienta no termina en la pasarela).
  const checkout = async (email) => {
    const c = await (await b.newContext()).newPage();
    c.on('pageerror', (e) => errs.push(e.message));
    await c.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16&quantity=1`);
    await c.goto(`${B}/checkout/`);
    if (!(await c.locator('#billing_email').count())) { console.log('checkout vacío:', c.url(), (await c.locator('.woocommerce-error, .woocommerce-info').allTextContents()).join(' ').replace(/\s+/g, ' ').slice(0, 200)); }
    await c.fill('#billing_email', email); await c.fill('#billing_first_name', 'Paula'); await c.fill('#billing_last_name', 'Pendiente');
    await c.fill('#billing_address_1', 'Cra 43A # 1-50'); await c.fill('#billing_phone', '3001234567');
    if (await c.locator('#billing_id').count()) await c.fill('#billing_id', '1037000111');
    await c.evaluate(() => { const s = document.querySelector('#billing_state'); s.value = s.tagName === 'SELECT' ? [...s.options].find((o) => /Antioquia/.test(o.text)).value : 'Antioquia'; s.dispatchEvent(new Event('change', { bubbles: true })); });
    await c.locator('#billing_email').blur(); await c.waitForTimeout(1800);
    await c.check('#payment_method_bk_test_pending', { force: true });
    await c.click('#place_order'); await c.waitForURL(/order-received/, { timeout: 30000 });
    const id = +(c.url().match(/order-received\/(\d+)/) || [])[1];
    await c.context().close();
    return id;
  };
  const stock0 = +php('echo wc_get_product(24)->get_stock_quantity();');
  const id1 = await checkout('paula.pendiente@ejemplo.com');
  ok(id1 && php(`echo wc_get_order(${id1})->get_status();`) === 'pending', `pedido creado en el checkout queda "Pendiente de pago" (#${id1})`);
  const s1 = sched(id1);
  ok(s1.includes(`bk_pp_send|${id1},1`) && s1.includes(`bk_pp_send|${id1},2`) && s1.includes(`bk_pp_cancel|${id1}`), `programados: recordatorio 1 h, 24 h y cancelación 48 h (${s1.length})`);
  const rows = JSON.parse(execSync('php /tmp/claude-0/bk-local/t/run.php rows').toString());
  const row1 = rows.find((r) => r.email === 'paula.pendiente@ejemplo.com');
  ok(row1 && row1.status === 'awaiting' && +row1.order_id === id1, `carrito abandonado: pasa a "Pendiente de pago" con el pedido (${row1 && row1.status})`);

  // 2. WooCommerce no lo cancela al vencer "Mantener inventario" (60 min): lo maneja buckler-core.
  back(id1, 2);
  php('wc_cancel_unpaid_orders();');
  ok(php(`echo wc_get_order(${id1})->get_status();`) === 'pending', 'a las 2 h WooCommerce no lo cancela (los recordatorios siguen)');

  // 3. Recordatorio 1 (lo que hace el programador a la hora).
  php(`bk_pp_send(${id1}, 1);`);
  const m1 = lastMail(/está a un paso/);
  ok(m1 && m1.to.includes('paula.pendiente@ejemplo.com') && /order-pay/.test(m1.html) && /key=wc_order_/.test(m1.html) && /Terminar mi pago/.test(m1.html) && /Pendiente|CHAQUETA|JEAN|BOTA|FLARE/i.test(m1.html), `recordatorio 1: "${m1 && m1.subject}" con prendas, total y enlace para pagar ese pedido`);
  ok(!/cup[oó]n/i.test(m1.html), 'sin cupón de descuento');
  php(`bk_pp_send(${id1}, 1);`);
  ok(JSON.parse(php(`echo json_encode(array_keys((array) wc_get_order(${id1})->get_meta("_bk_pp_sent")));`)).length === 1, 'no se repite el mismo recordatorio');

  // 4. Gestión: estado, envío a mano con vista previa, detener y reanudar.
  const g = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/gestion-tienda/'); await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const openOrder = async (id) => { await g.goto('about:blank'); await g.goto(`${B}/gestion-tienda/#pedidos`); await g.waitForSelector('.bk-g-orders'); await g.fill('#bk-g-of [name=q]', String(id)); await g.waitForTimeout(1800); await g.click(`[data-order="${id}"]`); await g.waitForSelector('#bk-g-payrem'); };
  await openOrder(id1);
  const tl = await g.textContent('#bk-g-payrem');
  ok(/Pago pendiente/.test(tl) && /Recordatorio automático 1/.test(tl) && /Recordatorio automático 2/.test(tl) && /Cancelación si no paga/.test(tl), 'detalle: "Pago pendiente" con lo enviado y lo que sigue (24 h y cancelación)');
  ok(await g.locator('.bk-g-ship').count() === 0, 'mientras no pague no se muestra el envío de guía');
  await g.click('[data-pp="preview"]'); await g.waitForSelector('.bk-g-pppv iframe');
  ok(/Último aviso/.test(await g.textContent('.bk-g-pppv')), 'vista previa antes de enviar (como ya recibió uno, va el último aviso con la fecha de cancelación)');
  await g.click('[data-pp="send"]'); await g.waitForTimeout(1500);
  ok(/Recordatorio de pago enviado/.test(await g.textContent('#bk-g-toast')) && /Enviado a mano por/.test(await g.textContent('#bk-g-payrem')), 'enviado a mano: aviso y queda en la línea de tiempo con quién lo envió');
  ok(/Recordatorio de pago enviado/.test(await g.textContent('.bk-g-hist')) && lastMail(/Último aviso/), 'queda en el historial del pedido y el correo sale');
  await g.click('[data-pp="stop"]'); await g.waitForTimeout(1500);
  ok(/detenidos/.test(await g.textContent('#bk-g-payrem')) && sched(id1).length === 0 && await g.locator('[data-pp="resume"]').count() === 1, '"Detener automáticos": se quitan el 2.º recordatorio y la cancelación');
  await g.click('[data-pp="resume"]'); await g.waitForTimeout(1500);
  ok(sched(id1).some((x) => x.startsWith('bk_pp_cancel')) && await g.locator('[data-pp="stop"]').count() === 1, '"Reanudar automáticos" los vuelve a programar');
  const copied = await g.getAttribute('[data-pp="copy"]', 'data-url');
  ok(/order-pay\/\d+\/\?pay_for_order=true&(amp;)?key=wc_order_.*bk_pp=/.test(copied), '"Copiar enlace de pago" firmado (para enviarlo por WhatsApp)');

  // Pedido recién creado: no deja enviar (puede estar pagando en ese momento).
  const id2 = await checkout('reciente@ejemplo.com');
  await openOrder(id2);
  ok(await g.locator('[data-pp="preview"][disabled]').count() === 1 && /Podrás enviar el recordatorio en \d+ min/.test(await g.textContent('#bk-g-payrem')), 'pedido de hace menos de 30 min: el botón espera (la clienta puede estar pagando)');
  await g.screenshot({ path: '/tmp/claude-0/bk-local/r181-pago.png' });

  // 5. La clienta paga desde el enlace del correo.
  const pay = await (await b.newContext()).newPage();
  pay.on('pageerror', (e) => errs.push(e.message));
  await pay.goto(copied.replace(/&amp;/g, '&'));
  ok(await pay.locator('#payment_method_cod').count() === 1 && await pay.locator('#place_order').count() === 1, 'el enlace abre "Pagar pedido" con los medios de pago, sin pedir verificar el correo (pedido de hace 2 h)');
  const plain = await (await b.newContext()).newPage();
  await plain.goto(copied.replace(/&amp;/g, '&').replace(/&bk_pp=[^&]+/, ''));
  ok(await plain.locator('#payment_method_cod').count() === 0, 'sin la firma, WooCommerce sigue pidiendo verificar el correo (protección intacta)');
  await plain.context().close();
  await pay.check('#payment_method_cod', { force: true });
  await Promise.all([pay.waitForURL(/order-received/, { timeout: 30000 }), pay.click('#place_order')]);
  ok(['processing', 'on-hold'].includes(php(`echo wc_get_order(${id1})->get_status();`)) && sched(id1).length === 0, 'pagó: el pedido avanza y se quitan los recordatorios y la cancelación');
  const row1b = JSON.parse(execSync('php /tmp/claude-0/bk-local/t/run.php rows').toString()).find((r) => +r.order_id === id1);
  ok(row1b && row1b.status === 'converted', `carrito: de "Pendiente de pago" a "Compró" (${row1b && row1b.status})`);

  // 6. A las 48 h sin pagar: se cancela solo y las unidades vuelven.
  const stockBefore = +php('echo wc_get_product(24)->get_stock_quantity();');
  back(id2, 49);
  php(`bk_pp_cancel(${id2});`);
  ok(php(`echo wc_get_order(${id2})->get_status();`) === 'cancelled', 'a las 48 h sin pagar: pedido cancelado automáticamente');
  ok(/Cancelado automáticamente/.test(php(`echo implode(" | ", wp_list_pluck(wc_get_order_notes(["order_id"=>${id2}]), "content"));`)), 'nota en el pedido explicando la cancelación');
  const row2 = JSON.parse(execSync('php /tmp/claude-0/bk-local/t/run.php rows').toString()).find((r) => +r.order_id === id2);
  ok(row2 && row2.status === 'unpaid', `carrito: "Pedido cancelado sin pagar" (${row2 && row2.status})`);
  const stockAfter = +php('echo wc_get_product(24)->get_stock_quantity();');
  ok(stockAfter >= stockBefore, `stock: no se pierden unidades (${stock0} → ${stockBefore} → ${stockAfter})`);
  await openOrder(id2);
  ok(/Cancelado automáticamente el/.test(await g.textContent('#bk-g-payrem')), 'el detalle dice cuándo se canceló');

  // 7. Carritos y Correos en la gestión.
  await g.goto('about:blank'); await g.goto(`${B}/gestion-tienda/#carritos`); await g.waitForSelector('.bk-g-carts');
  const ct = await g.textContent('.bk-g-main');
  ok(/Pendientes de pago/.test(ct) && /Compró/.test(ct) && /Pedido cancelado sin pagar/.test(ct), 'Carritos: indicador "Pendientes de pago" y estados nuevos');
  ok(await g.locator('#bk-g-af option[value="awaiting"]').count() === 1, 'filtro por "Pendiente de pago" en Carritos');
  await g.goto('about:blank'); await g.goto(`${B}/gestion-tienda/#correos`); await g.waitForSelector('.bk-g-mail__list');
  const ml = await g.textContent('.bk-g-mail__list');
  ok(/Recordatorio de pago 1/.test(ml) && /Recordatorio de pago 2/.test(ml) && /se cancela solo/.test(ml), 'Correos: los 2 recordatorios de pago con cuándo se envían');
  const pv = await g.evaluate(async () => (await fetch(window.bkG.api + 'emails/preview', { method: 'POST', headers: { 'X-WP-Nonce': window.bkG.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'bk:pp2' }) })).json());
  ok(/Último aviso/.test(pv.subject || ''), 'vista previa del recordatorio 2 en Correos');

  // 8. Un pedido pagado con contra entrega no programa nada.
  const id3 = +php('$o=wc_create_order(["created_via"=>"checkout"]); $o->add_product(wc_get_product(24),1); $o->set_billing_email("cod@ejemplo.com"); $o->calculate_totals(); $o->set_status("processing"); $o->save(); bk_pp_track($o); echo $o->get_id();');
  ok(sched(id3).length === 0, 'pedido pagado (o contra entrega): no recibe recordatorios');

  php(`foreach([${id1},${id2},${id3}] as $i){ $o=wc_get_order($i); if($o){ $o->delete(true);} } delete_option("bk_test_pending_gw");`);
  execSync('php /tmp/claude-0/bk-local/t/run.php reset');
  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
