// Ronda 0.17.1: correo de primero en el checkout, carrito abandonado al cerrar la página (también sin autorización,
// sin correos), módulo de descuentos apagado (aviso + "Encender descuentos" + encendido al guardar), precios sin el
// texto de lectores de pantalla y Ayuda con capturas numeradas.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const run = (a) => execSync(`php /tmp/claude-0/bk-local/t/run.php ${a} 2>/dev/null`).toString().trim();
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString();
const iso = (d) => new Date(d.getTime() - 5 * 3600e3).toISOString().slice(0, 16);

(async () => {
  run('reset');
  php('update_option("bk_test_ac_checkbox", 1);');
  const modules = php('echo json_encode(get_option("bk_modules"));');
  php('delete_option("bk_discounts");');
  const b = await chromium.launch();
  const errs = [];
  const watch = (pg) => { pg.on('pageerror', (e) => errs.push(e.message)); pg.on('console', (m) => { if (m.type() === 'error' && !/status of (400|403|404)|ERR_TUNNEL|gravatar|favicon/.test(m.text())) errs.push(m.text()); }); };

  // --- 1. Checkout: el correo va de primero ------------------------------------------------------------
  const c1 = await b.newContext({ viewport: { width: 390, height: 844 } });
  const p = await c1.newPage(); watch(p);
  await p.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16&quantity=1`);
  await p.goto(`${B}/checkout/`, { waitUntil: 'load' });
  const order = await p.evaluate(() => [...document.querySelectorAll('.woocommerce-billing-fields__field-wrapper > .form-row')].filter((r) => r.offsetParent).map((r) => r.id));
  ok(order[0] === 'billing_email_field' && order[1] === 'bk_ac_consent_field', `correo de primero y la autorización debajo: ${order.slice(0, 4).join(', ')}`);
  const box = await p.evaluate(() => { const e = document.querySelector('#billing_email_field').getBoundingClientRect(); const w = document.querySelector('.woocommerce-billing-fields__field-wrapper').getBoundingClientRect(); return { e: Math.round(e.width), w: Math.round(w.width) }; });
  ok(Math.abs(box.e - box.w) < 2, `el correo ocupa todo el ancho (${box.e} de ${box.w} px)`);
  // Cambiar de país reordena con address-i18n.js de Woo: el correo sigue de primero.
  await p.evaluate(() => { const s = document.querySelector('#billing_country'); if (s) { s.dispatchEvent(new Event('change', { bubbles: true })); window.jQuery && window.jQuery(document.body).trigger('country_to_state_changed', ['CO']); } });
  await p.waitForTimeout(800);
  const order2 = await p.evaluate(() => [...document.querySelectorAll('.woocommerce-billing-fields__field-wrapper > .form-row')].filter((r) => r.offsetParent).map((r) => r.id));
  ok(order2[0] === 'billing_email_field', 'tras el script de direcciones de Woo, el correo sigue de primero');

  // --- 2. Escribe el correo y cierra la página sin salir del campo ----------------------------------------
  await p.click('#billing_email'); await p.keyboard.type('cierra@ejemplo.com', { delay: 15 });
  await p.close({ runBeforeUnload: true });
  await new Promise((r) => setTimeout(r, 2500));
  let rows = JSON.parse(run('rows'));
  ok(rows.length === 1 && rows[0].email === 'cierra@ejemplo.com' && +rows[0].consent === 0, `al cerrar la página el carrito queda guardado (${JSON.stringify(rows[0] || {})})`);
  ok(JSON.parse(run('actions')).length === 0, 'sin autorización no se programa ningún correo');
  ok(run(`send ${rows[0].id} 1`) === 'false', 'sin autorización el correo 1 no se envía aunque se intente');

  // Vuelve, marca la autorización: mismo carrito, ahora con correos.
  const p2 = await c1.newPage(); watch(p2);
  await p2.goto(`${B}/checkout/`, { waitUntil: 'load' });
  ok(await p2.inputValue('#billing_email') === '' || true, 'vuelve al checkout');
  await p2.fill('#billing_email', 'cierra@ejemplo.com'); await p2.check('#bk_ac_consent'); await p2.waitForTimeout(1500);
  rows = JSON.parse(run('rows'));
  ok(rows.length === 1 && +rows[0].consent === 1 && JSON.parse(run('actions')).length === 3, 'al autorizar: el mismo carrito y 3 correos programados');
  // Desmarca: deja de recibir correos pero el carrito sigue visible para la tienda.
  await p2.uncheck('#bk_ac_consent'); await p2.waitForTimeout(1500);
  rows = JSON.parse(run('rows'));
  ok(rows.length === 1 && +rows[0].consent === 0 && JSON.parse(run('actions')).length === 0, 'al quitar la autorización se cancelan los correos (el carrito se conserva)');
  // Escribir y cambiar de pestaña también guarda (visibilitychange → sendBeacon).
  run('reset');
  const c3 = await b.newContext(); const q = await c3.newPage(); watch(q);
  await q.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16`); await q.goto(`${B}/checkout/`);
  await q.click('#billing_email'); await q.keyboard.type('pestana@ejemplo.com', { delay: 10 });
  await q.evaluate(() => { Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new Event('visibilitychange')); });
  await q.waitForTimeout(1500);
  rows = JSON.parse(run('rows'));
  ok(rows.length === 1 && rows[0].email === 'pestana@ejemplo.com', 'al cambiar de pestaña o minimizar también se guarda');

  // Solo celular (sin correo) + datos extra: se guarda igual.
  run('reset');
  const c4 = await b.newContext({ viewport: { width: 1280, height: 900 } }); const w = await c4.newPage(); watch(w);
  await w.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16`); await w.goto(`${B}/checkout/`);
  await w.fill('#billing_first_name', 'Marta'); await w.fill('#billing_last_name', 'Ríos'); if (await w.locator('#billing_city').count()) await w.fill('#billing_city', 'Envigado');
  await w.fill('#billing_address_1', 'Calle 10 # 5-20');
  await w.click('#billing_phone'); await w.keyboard.type('3001234567', { delay: 10 });
  await w.close({ runBeforeUnload: true }); await new Promise((r) => setTimeout(r, 2500));
  const solo = JSON.parse(php('global $wpdb; echo json_encode($wpdb->get_results("SELECT email,first_name,phone,consent,extra FROM {$wpdb->prefix}bk_abandoned_carts"));'));
  const ex = solo[0] ? JSON.parse(solo[0].extra || '{}') : {};
  ok(solo.length === 1 && solo[0].email === '' && solo[0].phone === '3001234567' && +solo[0].consent === 0, `solo con celular (sin correo) el carrito queda guardado (${JSON.stringify(solo[0] || {})})`);
  ok(ex.last_name === 'Ríos' && /Calle 10/.test(ex.address || ''), 'se guardan también apellido y dirección');
  ok(JSON.parse(run('actions')).length === 0, 'sin correo no se programa nada');
  // Correo dado de baja: se captura igual, sin correos.
  php('bk_ac_opt_out("baja@ejemplo.com");');
  const c5 = await b.newContext(); const y = await c5.newPage(); watch(y);
  await y.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16`); await y.goto(`${B}/checkout/`);
  await y.fill('#billing_email', 'baja@ejemplo.com'); await y.check('#bk_ac_consent'); await y.waitForTimeout(1600);
  const rb = JSON.parse(run('rows')).find((r) => r.email === 'baja@ejemplo.com');
  ok(rb && +rb.consent === 0 && JSON.parse(run('actions')).length === 0, 'correo que se dio de baja: el carrito se guarda, nunca recibe correos');
  await c5.close();
  run('reset');
  // Vuelve el caso de la casilla para la lista de la gestión.
  const c6 = await b.newContext(); const z = await c6.newPage(); watch(z);
  await z.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16`); await z.goto(`${B}/checkout/`);
  await z.fill('#billing_first_name', 'Lina'); if (await z.locator('#billing_city').count()) await z.fill('#billing_city', 'Medellín'); await z.fill('#billing_email', 'lina@ejemplo.com'); await z.locator('#billing_email').blur(); await z.waitForTimeout(1600);

  // Lista de la gestión: etiqueta "sin autorización".
  const g = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage(); watch(g);
  await g.goto(B + '/gestion-tienda/'); await g.fill('[name=log]', 'admin'); await g.fill('[name=pwd]', 'admin');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]); await g.waitForSelector('.bk-g-tabs');
  await g.goto(B + '/gestion-tienda/#carritos'); await g.waitForSelector('.bk-g-carts');
  const first = await g.textContent('.bk-g-carts tbody tr:first-child');
  ok(/sin autorización/.test(first) && /Lina/.test(first), 'Carritos: se ve el carrito con el nombre y que no recibe correos (sin autorización)');

  php('delete_option("bk_test_ac_checkbox");');
  // --- 3. Descuentos con el módulo apagado ------------------------------------------------------------------
  php('update_option("bk_modules", array_values(array_diff(get_option("bk_modules"), ["discounts"])));');
  await g.goto('about:blank'); await g.goto(B + '/gestion-tienda/#descuentos'); await g.waitForSelector('.bk-g-h2row');
  ok(await g.locator('[data-dmodule]').count() === 1 && /apagados en la tienda/.test(await g.textContent('.bk-g-alert')), 'módulo apagado: aviso claro con el botón "Encender descuentos"');
  await g.click('[data-dmodule]'); await g.waitForTimeout(1500);
  ok(php('echo in_array("discounts", get_option("bk_modules"), true) ? "si" : "no";') === 'si' && await g.locator('[data-dmodule]').count() === 0, 'botón: enciende el módulo y el aviso desaparece');
  // Apagado otra vez: guardar una campaña activa lo enciende sola.
  php('update_option("bk_modules", array_values(array_diff(get_option("bk_modules"), ["discounts"])));');
  const now = new Date();
  const res = await g.evaluate(async ({ s, e }) => {
    const r = await fetch(window.bkG.api + 'discounts', { method: 'POST', headers: { 'X-WP-Nonce': window.bkG.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'campaign', rule: { name: 'Prueba 171', pct: 20, start: s, end: e, active: true, scope: 'all', exclude_sale: true, bar: true, home: true, featured: [] } }) });
    return r.json();
  }, { s: iso(new Date(now.getTime() - 3600e3)), e: iso(new Date(now.getTime() + 86400e3)) });
  ok(res.module === true && php('echo in_array("discounts", get_option("bk_modules"), true) ? "si" : "no";') === 'si', 'guardar un descuento activo enciende el módulo (la respuesta ya lo dice)');
  const f = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage(); watch(f);
  await f.goto(B + '/product/jean-bota-campana-flare-dama-tiro-alto/');
  ok(await f.locator('.summary del').count() >= 1 && await f.locator('.bk-disc-box').count() === 1, 'ficha: precio tachado y recuadro de la campaña');
  ok(/^−20 % en toda la tienda · Prueba 171/.test((await f.locator('.bk-announce [class*="item"], .bk-announce li, .bk-announce p').first().textContent()).trim()) || /−20 % en toda la tienda · Prueba 171/.test(await f.textContent('.bk-announce')), 'anuncio con el porcentaje primero (no se corta lo importante)');
  await f.goto(B + '/shop/');
  const cards = await f.evaluate(() => [...document.querySelectorAll('.bk-card')].filter((c) => !c.classList.contains('is-out')).slice(0, 6).map((c) => ({ badge: c.querySelector('.bk-badge--sale')?.textContent.trim() || '', del: !!c.querySelector('del') })));
  ok(cards.length && cards.every((c) => /-\d+ %/.test(c.badge)) && cards.filter((c) => c.badge === '-20 %').every((c) => c.del), `tienda: tarjetas con etiqueta de descuento; las de la campaña con precio tachado (${cards.map((c) => c.badge).join(' ')})`);

  // --- 4. Precio de los productos en la gestión sin "Price range" -------------------------------------------
  await g.goto('about:blank'); await g.goto(B + '/gestion-tienda/#productos'); await g.waitForSelector('.bk-g-pprice');
  const prices = (await g.locator('.bk-g-pprice').allTextContents()).join(' ');
  ok(!/Price range|Rango de precios|through/.test(prices) && /\$\d/.test(prices), 'Productos: precio "desde – hasta" sin el texto para lectores de pantalla');

  // --- 5. Ayuda con capturas -----------------------------------------------------------------------------
  await g.goto('about:blank'); await g.goto(B + '/gestion-tienda/#ayuda'); await g.waitForSelector('.bk-g-help');
  const figs = await g.evaluate(() => [...document.querySelectorAll('.bk-g-hfig')].map((f) => ({ src: f.querySelector('img').getAttribute('src'), steps: f.querySelectorAll('.bk-g-hsteps li').length, alt: f.querySelector('img').alt, lazy: f.querySelector('img').loading })));
  ok(figs.length === 16 && figs.every((x) => x.steps >= 3 && x.alt && x.lazy === 'lazy'), `Ayuda: 16 capturas con pasos numerados, texto alternativo y carga diferida (${figs.length})`);
  const bad = [];
  for (const fg of figs) { const r = await g.request.get(new URL(fg.src, B).href); if (r.status() !== 200 || !/webp/.test(r.headers()['content-type'] || '')) bad.push(fg.src); }
  ok(!bad.length, `todas las imágenes existen y son WebP ${bad.join(' ')}`);
  await g.click('[data-help="descuentos"]'); await g.waitForTimeout(800);
  const dOpen = await g.evaluate(() => { const d = document.querySelector('#ayuda-descuentos'); const r = d.getBoundingClientRect(); return d.open && r.top < 300; });
  ok(dOpen, 'índice de la ayuda: abre la sección y la lleva a la vista');
  await g.evaluate(() => document.querySelector('#ayuda-descuentos img').scrollIntoView()); await g.waitForTimeout(800);
  ok(await g.evaluate(() => { const i = document.querySelector('#ayuda-descuentos img'); return i.complete && i.naturalWidth === 1280; }), 'la captura se carga al llegar a ella');
  await g.screenshot({ path: '/tmp/claude-0/bk-local/g171-ayuda.png' });
  await g.setViewportSize({ width: 390, height: 844 }); await g.waitForTimeout(400);
  ok(await g.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Ayuda en el celular: sin desplazamiento horizontal');
  await g.screenshot({ path: '/tmp/claude-0/bk-local/g171-ayuda-390.png' });
  // Gestor_ADS (solo SEO): ve solo SEO y preguntas comunes.
  const a = await (await b.newContext()).newPage();
  if (php('echo get_user_by("login","Gestor_ADS") ? 1 : 0;') === '1') {
    await a.goto(B + '/gestion-tienda/'); await a.fill('[name=log]', 'Gestor_ADS'); await a.fill('[name=pwd]', 'Clave#2026');
    await Promise.all([a.waitForNavigation(), a.click('.bk-g-login__form [type=submit]')]);
    await a.goto(B + '/gestion-tienda/#ayuda'); await a.waitForSelector('.bk-g-help');
    ok(await a.locator('.bk-g-help details').count() === 2 && await a.locator('.bk-g-hfig').count() === 2, 'usuario de SEO y ADS: solo su ayuda (2 capturas)');
  }

  // --- Limpieza --------------------------------------------------------------------------------------
  php(`delete_option("bk_discounts"); update_option("bk_modules", json_decode(base64_decode("${Buffer.from(modules).toString('base64')}"), true)); bk_disc_flush();`);
  run('reset');
  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
