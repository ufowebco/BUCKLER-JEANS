const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const php = (a) => execSync(`php /tmp/claude-0/bk-local/t/run.php ${a} 2>/dev/null`).toString().trim();
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
(async () => {
  php('reset');
  require('child_process').execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; update_option("bk_test_ac_checkbox", 1);'`);
  const b = await chromium.launch(); const errors = [];
  // --- Clienta en su celular: añade al carrito y escribe su correo ---
  const c1 = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await c1.newPage();
  p.on('pageerror', e => errors.push(e.message));
  await p.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16&quantity=1`);
  await p.goto(`${B}/checkout/`, { waitUntil: 'load' });
  ok(await p.isVisible('#bk_ac_consent'), 'casilla de autorización visible en el checkout');
  const order = await p.evaluate(() => [...document.querySelectorAll('#customer_details .form-row')].map(r => r.id).filter(Boolean).slice(0, 12).join(','));
  ok(order.indexOf('bk_ac_consent') === order.indexOf('billing_email_field') + 'billing_email_field,'.length || /billing_email_field,bk_ac_consent/.test(order), 'casilla justo después del correo: ' + order);
  await p.fill('#billing_first_name', 'Laura');
  await p.fill('#billing_email', 'laura@ejemplo.com');
  await p.locator('#billing_email').blur(); await p.waitForTimeout(1200);
  let r0 = JSON.parse(php('rows'));
  ok(r0.length === 1 && +r0[0].consent === 0 && JSON.parse(php('actions')).length === 0, 'sin autorización se guarda para la tienda, pero sin correos programados');
  await p.check('#bk_ac_consent'); await p.waitForTimeout(1500);
  let rows = JSON.parse(php('rows'));
  ok(rows.length === 1 && +rows[0].consent === 1 && rows[0].email === 'laura@ejemplo.com' && rows[0].first_name === 'Laura', 'con autorización se guarda el carrito: ' + JSON.stringify(rows[0]));
  const acts = JSON.parse(php('actions'));
  ok(acts.length === 3, 'se programan 3 correos: ' + JSON.stringify(acts));
  // Cambia el correo: no se duplica
  await p.fill('#billing_email', 'laura.g@ejemplo.com'); await p.locator('#billing_email').blur(); await p.waitForTimeout(1500);
  rows = JSON.parse(php('rows'));
  ok(rows.length === 1 && rows[0].email === 'laura.g@ejemplo.com', 'cambio de correo actualiza el mismo carrito');
  ok(JSON.parse(php('actions')).length === 3, 'sigue con 3 correos programados (no se duplican)');
  const id = rows[0].id;
  // --- Pasan 2 horas: correo 1 ---
  ok(php(`send ${id} 1`) === 'true', 'correo 1 enviado');
  ok(php(`send ${id} 1`) === 'false', 'el correo 1 no se repite');
  // --- Desde el computador (otra sesión, sin cookies): abre el enlace ---
  const c2 = await b.newContext({ viewport: { width: 1440, height: 900 } }); const d = await c2.newPage();
  d.on('pageerror', e => errors.push(e.message));
  await d.goto(php(`recover ${id} 1`), { waitUntil: 'load' });
  ok(/\/checkout\/\?utm_source=email&utm_medium=carrito-abandonado&utm_campaign=recordatorio-1/.test(d.url()), 'el enlace lleva al checkout con UTM: ' + d.url());
  ok((await d.textContent('.woocommerce-checkout-review-order-table')).includes('FLARE'), 'carrito restaurado en otro dispositivo');
  ok(await d.inputValue('#billing_email') === 'laura.g@ejemplo.com', 'correo prellenado');
  // --- Compra (pago contra entrega de prueba) ---
  await d.fill('#billing_last_name', 'Gómez'); await d.fill('#billing_address_1', 'Cra 43A # 1-50'); if (await d.locator('#billing_city').count()) await d.fill('#billing_city', 'Medellín');
  await d.fill('#billing_phone', '3000000000');
  await d.evaluate(() => { const s = document.querySelector('#billing_state'); s.value = s.tagName === 'SELECT' ? [...s.options].find(o => /Antioquia/.test(o.text)).value : 'Antioquia'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await d.click('#place_order'); await d.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
  ok(/order-received/.test(d.url()), 'pedido creado: ' + d.url().replace(B, '') + ' ' + ((await d.locator('.woocommerce-error, .woocommerce-NoticeGroup').allTextContents()).join(' ').replace(/\s+/g,' ').slice(0,300)));
  rows = JSON.parse(php('rows'));
  ok(rows[0].status === 'recovered' && rows[0].order_id > 0, 'carrito marcado como RECUPERADO con su pedido: ' + JSON.stringify(rows[0]));
  ok(JSON.parse(php('actions')).length === 0, 'correos 2 y 3 cancelados');
  ok(php(`send ${id} 2`) === 'false', 'el correo 2 ya no se envía');
  // --- Baja ---
  php('reset');
  const c3 = await b.newContext(); const q = await c3.newPage();
  await q.goto(`${B}/?add-to-cart=22&variation_id=26&attribute_pa_tallas-plus-size=20`); await q.goto(`${B}/checkout/`);
  await q.fill('#billing_email', 'ana@ejemplo.com'); await q.check('#bk_ac_consent'); await q.waitForTimeout(1500);
  const id2 = JSON.parse(php('rows'))[0].id;
  await q.goto(php(`unsub ${id2}`));
  rows = JSON.parse(php('rows'));
  ok(rows[0].status === 'unsubscribed' && JSON.parse(php('actions')).length === 0, 'baja: estado y correos cancelados');
  ok((await q.textContent('.bk-toast')).includes('No te enviaremos más recordatorios'), 'mensaje de confirmación de baja');
  // Vuelve a intentar: ya no se captura
  await q.goto(`${B}/checkout/`); await q.fill('#billing_email', 'ana@ejemplo.com'); await q.locator('#billing_email').blur(); await q.waitForTimeout(1200);
  const r2 = JSON.parse(php('rows'));
  ok(r2.length === 2 && +r2[1].consent === 0 && JSON.parse(php('actions')).length === 0, 'un correo dado de baja se captura igual para la tienda, pero sin correos');
  // Vacía el carrito: se cancelan
  php('reset');
  const c4 = await b.newContext(); const r = await c4.newPage();
  await r.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16`); await r.goto(`${B}/checkout/`);
  await r.fill('#billing_email', 'sofi@ejemplo.com'); await r.check('#bk_ac_consent'); await r.waitForTimeout(1500);
  await r.goto(`${B}/cart/`); await r.click('a.remove'); await r.waitForTimeout(2500);
  rows = JSON.parse(php('rows'));
  ok(rows[0].status === 'emptied' && JSON.parse(php('actions')).length === 0, 'si vacía el carrito no se le escribe: ' + rows[0].status);
  require('child_process').execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; delete_option("bk_test_ac_checkbox");'`);
  ok(errors.length === 0, 'sin errores JS ' + errors.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
