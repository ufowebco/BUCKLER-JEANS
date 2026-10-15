// Guía de envío + correo "Tu pedido ha sido enviado" (vista previa, confirmación, prueba desde Ajustes).
const { chromium } = require('playwright');
const fs = require('fs');
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const B = 'http://localhost:8899';
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
const OUT = '/tmp/claude-0/bk-local';
const mails = () => fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter(f => f.endsWith('.json')) : [];
const newMails = (before) => mails().filter(f => !before.includes(f)).map(f => ({ f, ...JSON.parse(fs.readFileSync(`${MAILS}/${f}`, 'utf8')) }));
const login = async (p, u, pw) => { await p.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/')); await p.fill('[name=log]', u); await p.fill('[name=pwd]', pw); await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]); };

(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/status of 400/.test(m.text())) errs.push(m.text()); });
  await login(p, 'gestora', 'gestora');

  // Pedido en proceso sin guía
  await p.click('[data-tab="pedidos"]'); await p.selectOption('select[name="status"]', 'processing'); await p.waitForTimeout(1200);
  const row = p.locator('.bk-g-orders tbody tr').first();
  const id = await row.locator('[data-order]').first().getAttribute('data-order');
  await row.locator('[data-order]').first().click(); await p.waitForSelector('#bk-g-shipform');
  ok(await p.locator('#bk-g-shipform select[name="carrier"] option').count() === 10, 'desplegable con las 9 transportadoras');

  // Validación en el navegador
  await p.click('#bk-g-shipform button[type="submit"]');
  ok(/transportadora/.test(await p.textContent('#bk-g-shipform .bk-g-formerr')), 'valida transportadora');
  await p.selectOption('#bk-g-shipform select[name="carrier"]', 'servientrega');
  await p.fill('#bk-g-shipform input[name="number"]', '12');
  await p.click('#bk-g-shipform button[type="submit"]');
  ok(/guía/.test(await p.textContent('#bk-g-shipform .bk-g-formerr')), 'valida número de guía');
  // Validación en el servidor
  const nonce = await p.evaluate(() => window.bkG.nonce);
  const bad = await p.request.post(`${B}/wp-json/bk/v1/gestion/orders/${id}/shipment`, { headers: { 'X-WP-Nonce': nonce }, data: { carrier: 'fedex', number: '123456' } });
  ok(bad.status() === 400, 'API rechaza transportadora desconocida');

  // Vista previa (no envía nada)
  const before = mails();
  await p.fill('#bk-g-shipform input[name="number"]', ' 2401-abc-889 ');
  await p.click('#bk-g-shipform button[type="submit"]');
  await p.waitForSelector('#bk-g-preview:not([hidden]) .bk-g-pvframe');
  await p.waitForTimeout(600);
  const meta = await p.textContent('.bk-g-pvmeta');
  ok(/@/.test(meta) && /ha sido enviado/.test(meta), 'vista previa con destinatario y asunto');
  const frame = p.frameLocator('.bk-g-pvframe');
  const body = await frame.locator('body').textContent();
  ok(/va en camino/.test(body) && /2401-ABC-889/.test(body) && /Servientrega/.test(body), 'vista previa: título, guía normalizada y transportadora');
  ok(newMails(before).length === 0, 'la vista previa no envía correos');
  ok(await p.evaluate(() => document.activeElement.hasAttribute('data-ship-confirm')), 'foco en "Confirmar y enviar"');
  await p.screenshot({ path: `${OUT}/envio-preview.png` });

  // Volver a editar → no se guarda nada
  await p.click('.bk-g-pvfoot [data-pv-close]');
  ok(await p.locator('#bk-g-preview').isHidden() && await p.locator('#bk-g-shipform').isVisible(), '"Volver a editar" cierra solo la vista previa');
  ok(await p.locator('.bk-g-shipped').count() === 0, 'sin confirmar no se guarda la guía');

  // Confirmar
  await p.click('#bk-g-shipform button[type="submit"]');
  await p.waitForSelector('#bk-g-preview:not([hidden]) [data-ship-confirm]');
  await p.click('[data-ship-confirm]');
  await p.waitForSelector('.bk-g-shipped'); await p.waitForTimeout(500);
  ok(/Correo enviado/.test(await p.textContent('#bk-g-toast')), 'toast de envío');
  const sent = newMails(before);
  const ship = sent.filter(m => /ha sido enviado/.test(m.subject));
  ok(ship.length === 1, 'un correo "pedido enviado": ' + (ship[0]?.subject || 'ninguno'));
  ok(sent.filter(m => m.to === ship[0]?.to).length === 1, 'sin el correo estándar de "pedido completado" (' + sent.map(m => m.subject).join(' | ') + ')');
  const html = ship[0] ? fs.readFileSync(`${MAILS}/${ship[0].f.replace('.json', '.html')}`, 'utf8') : '';
  ok(/logo-email\.png/.test(html) && /servientrega\.com/.test(html), 'correo con logo PNG y enlace a la transportadora');
  ok(/2401-ABC-889/.test(await p.textContent('.bk-g-shipped')), 'detalle muestra la guía guardada');
  ok(/Guía Servientrega 2401-ABC-889/.test(await p.textContent('.bk-g-notes')), 'nota en el pedido');
  ok(/Complet/.test(await p.textContent('.bk-g-drawer__panel')), 'pedido pasa a Completado');
  ok(/Reenviar/.test(await p.textContent('#bk-g-shipform button[type="submit"]')), 'botón cambia a "Reenviar correo"');
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  await p.selectOption('select[name="status"]', 'all'); await p.waitForTimeout(1200);
  ok(/Servientrega/.test(await p.locator(`tr:has([data-order="${id}"]) .bk-g-trk`).textContent().catch(() => '')), 'indicador de envío en la lista');

  // Mi cuenta de la clienta (si el pedido tiene usuario)
  const view = await p.request.get(`${B}/wp-json/bk/v1/gestion/orders/${id}`, { headers: { 'X-WP-Nonce': nonce } });
  ok((await view.json()).tracking?.carrier === 'servientrega', 'API devuelve la guía');

  // Admin: Correos → vista previa y correo de prueba (antes en Ajustes)
  const a = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  a.on('pageerror', e => errs.push(e.message));
  await login(a, 'admin', 'admin'); await a.goto(B + '/gestion-tienda/#correos'); await a.waitForSelector('[data-mail="bk:shipped"]');
  await a.click('[data-mail="bk:shipped"]'); await a.waitForSelector('#bk-g-mtest'); await a.waitForTimeout(600);
  ok(/enviado|camino/i.test(await a.frameLocator('.bk-g-mframe').locator('body').textContent()), 'Correos: vista previa del correo de guía');
  const b2 = mails();
  await a.fill('#bk-g-mtest input[name="to"]', 'pruebas@buckler.test');
  await a.click('#bk-g-mtest button[type="submit"]'); await a.waitForTimeout(1200);
  const test = newMails(b2);
  ok(test.length === 1 && /^\[Prueba\]/.test(test[0].subject) && test[0].to === 'pruebas@buckler.test', 'correo de prueba: ' + (test[0]?.subject || 'ninguno'));
  const deny = await p.request.post(`${B}/wp-json/bk/v1/gestion/shipment-sample`, { headers: { 'X-WP-Nonce': nonce }, data: { to: 'x@y.co' } });
  ok(deny.status() === 403, 'la gestora no puede enviar correos de prueba');

  // Correo a 600 y 390 px
  if (html) {
    const e = await b.newPage({ viewport: { width: 700, height: 900 } });
    await e.setContent(html); await e.waitForTimeout(400); await e.screenshot({ path: `${OUT}/envio-mail-600.png`, fullPage: true });
    await e.setViewportSize({ width: 390, height: 844 }); await e.waitForTimeout(200);
    ok(!(await e.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'correo sin scroll horizontal a 390');
    await e.screenshot({ path: `${OUT}/envio-mail-390.png`, fullPage: true });
  }

  // Móvil: vista previa a pantalla completa
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', e => errs.push(e.message));
  await m.goto(B + '/gestion-tienda/#pedidos'); await m.waitForSelector('.bk-g-orders [data-order]');
  await m.locator(`[data-order="${id}"]`).first().click(); await m.waitForSelector('#bk-g-shipform');
  await m.click('#bk-g-shipform button[type="submit"]'); await m.waitForSelector('#bk-g-preview:not([hidden]) .bk-g-pvframe'); await m.waitForTimeout(600);
  const box = await m.locator('.bk-g-preview__box').boundingBox();
  ok(box && box.width >= 380, 'móvil: vista previa a pantalla completa');
  ok(await m.locator('[data-ship-confirm]').isVisible(), 'móvil: botón de confirmar visible');
  await m.screenshot({ path: `${OUT}/envio-mobile.png` });

  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
