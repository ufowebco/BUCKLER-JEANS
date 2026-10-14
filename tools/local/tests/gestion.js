const { chromium } = require('playwright');
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const B = 'http://localhost:8899';
const login = async (p, u, pw) => { await p.goto(B + '/wp-login.php?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/')); await p.fill('#user_login', u); await p.fill('#user_pass', pw); await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]); };
(async () => {
  const b = await chromium.launch(); const errs = [];
  // Sin sesión
  const anon = await (await b.newContext()).newPage();
  await anon.goto(B + '/gestion-tienda/');
  ok(/wp-login\.php/.test(anon.url()), 'sin sesión → login');
  const r = await anon.request.get(B + '/wp-json/bk/v1/gestion/orders');
  ok(r.status() === 401, 'API sin sesión rechazada: ' + r.status());
  // Gestora
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/status of 400/.test(m.text())) errs.push(m.text()); });
  await login(p, 'gestora', 'gestora');
  ok(/gestion-tienda/.test(p.url()), 'la gestora entra directo al dashboard');
  await p.waitForSelector('.bk-g-kpi');
  ok(await p.locator('.bk-g-kpi').count() === 5, 'resumen con 5 indicadores');
  ok(await p.locator('[data-tab="ajustes"]').count() === 0, 'la gestora no ve Ajustes');
  const css = await p.evaluate(() => [...document.styleSheets].map(s => s.href || '').filter(Boolean));
  ok(css.length === 1 && /gestion\.css/.test(css[0]), 'no carga el tema ni Divi (1 hoja de estilos)');
  await p.goto(B + '/wp-admin/'); ok(/gestion-tienda/.test(p.url()), 'la gestora no entra a wp-admin');
  // Pedidos
  await p.click('[data-tab="pedidos"]'); await p.waitForSelector('.bk-g-orders tbody tr');
  const n0 = await p.locator('.bk-g-orders tbody tr').count();
  ok(n0 > 5, `lista de pedidos: ${n0}`);
  await p.fill('input[name="q"]', '52333444'); await p.waitForTimeout(1200);
  const names = await p.locator('.bk-g-orders tbody .c-cust').allTextContents();
  ok(names.length > 0 && names.every(n => /Carolina/.test(n)), `búsqueda por cédula → ${names.length} pedidos de Carolina`);
  await p.fill('input[name="q"]', ''); await p.selectOption('select[name="status"]', 'processing'); await p.waitForTimeout(1000);
  const st = await p.locator('.bk-g-pill').allTextContents();
  ok(st.length && st.every(s => /process|proceso/i.test(s)), 'filtro por estado: ' + [...new Set(st)].join());
  await p.selectOption('select[name="status"]', 'all'); await p.selectOption('select[name="printed"]', 'yes'); await p.waitForTimeout(1000);
  const allChecked = await p.$$eval('[data-printed]', els => els.every(e => e.checked));
  ok(allChecked, 'filtro "Impresos"');
  await p.selectOption('select[name="printed"]', ''); await p.waitForTimeout(900);
  const cb = p.locator('[data-printed]').first(); const before = await cb.isChecked();
  await cb.click(); await p.waitForTimeout(800);
  await p.reload(); await p.click('[data-tab="pedidos"]').catch(() => {}); await p.waitForSelector('[data-printed]');
  ok(await p.locator('[data-printed]').first().isChecked() === !before, 'check "Impreso" se guarda');
  // Consecutivo con prefijo (PEDIDO-2405) y despachado
  for (const q of ['PEDIDO-2405', '2405', '#2405']) {
    await p.fill('input[name="q"]', q); await p.waitForTimeout(1200);
    const cons = await p.locator('.bk-g-orders tbody .c-cons').allTextContents();
    ok(cons.length >= 1 && cons.every(c => /PEDIDO-2405/.test(c)), `búsqueda por consecutivo "${q}" → ${cons.length}`);
  }
  await p.fill('input[name="q"]', ''); await p.waitForTimeout(900);
  ok((await p.textContent('.bk-g-orders thead')).includes('Despachado'), 'columna Despachado con la clave real por defecto');
  await p.selectOption('select[name="dispatched"]', 'yes'); await p.waitForTimeout(1000);
  ok(await p.$$eval('[data-dispatched]', els => els.length > 0 && els.every(e => e.checked)), 'filtro "Despachados"');
  await p.selectOption('select[name="dispatched"]', 'no'); await p.waitForTimeout(1000);
  const dcb = p.locator('[data-dispatched]').first(); const did = await dcb.getAttribute('data-dispatched');
  await dcb.click(); await p.waitForTimeout(900);
  ok(/despachado/.test(await p.textContent('#bk-g-toast')), 'check "Despachado" desde la lista');
  await p.selectOption('select[name="dispatched"]', 'yes'); await p.waitForTimeout(1000);
  ok(await p.locator(`[data-dispatched="${did}"]`).count() === 1, 'el pedido marcado aparece en "Despachados"');
  await p.selectOption('select[name="dispatched"]', ''); await p.waitForTimeout(900);
  // Detalle
  await p.fill('input[name="q"]', '464'); await p.waitForTimeout(1200); await p.locator('[data-order="464"]').click(); await p.waitForSelector('#bk-g-osave');
  ok((await p.textContent('.bk-g-dbody')).includes('Cédula'), 'detalle muestra cédula');
  ok(await p.locator('.bk-g-files img').count() === 1, 'detalle muestra el archivo ACF del pedido');
  ok(await p.evaluate(() => document.activeElement.closest('.bk-g-drawer__panel') !== null), 'foco dentro del panel');
  await p.selectOption('#bk-g-ostatus', 'completed'); await p.click('#bk-g-osave'); await p.waitForTimeout(1200);
  ok((await p.textContent('#bk-g-toast')).includes('Completado') || (await p.textContent('#bk-g-toast')).includes('Completed'), 'cambio de estado: ' + await p.textContent('#bk-g-toast'));
  await p.fill('#bk-g-notetext', 'Despachado con Servientrega guía 123'); await p.click('#bk-g-onote button'); await p.waitForTimeout(1200);
  ok((await p.textContent('.bk-g-notes')).includes('Servientrega'), 'nota añadida');
  await p.screenshot({ path: '/tmp/claude-0/bk-local/g-order.png' });
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  // Productos
  await p.click('[data-tab="productos"]'); await p.waitForSelector('.bk-g-prod');
  await p.fill('#bk-g-pf input[name="q"]', 'OXFORD'); await p.waitForTimeout(1200);
  ok((await p.locator('.bk-g-pinfo b').allTextContents()).every(t => /OXFORD/.test(t)), 'búsqueda de productos');
  await p.locator('[data-sizes]').first().click(); await p.waitForSelector('.bk-g-vtable');
  const row = p.locator('.bk-g-vtable tbody tr').first();
  await row.locator('[name="stock"]').fill('7'); await row.locator('[data-vsave]').click(); await p.waitForTimeout(1200);
  ok(/guardada/.test(await p.textContent('#bk-g-toast')), 'stock de talla guardado');
  await row.locator('[name="sale"]').fill('999999'); await row.locator('[data-vsave]').click(); await p.waitForTimeout(1200);
  ok(/menor/.test(await p.textContent('#bk-g-toast')), 'valida que la oferta sea menor: ' + await p.textContent('#bk-g-toast'));
  await row.locator('[name="sale"]').fill('99900'); await row.locator('[data-vsave]').click(); await p.waitForTimeout(1200);
  const star = p.locator('[data-feat]').first(); const f0 = await star.getAttribute('aria-pressed');
  await star.click(); await p.waitForTimeout(1000);
  ok(await star.getAttribute('aria-pressed') !== f0, 'toggle Nuevo (destacado)');
  await p.screenshot({ path: '/tmp/claude-0/bk-local/g-products.png' });
  // Admin: ajustes y detector
  const a = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  a.on('pageerror', e => errs.push(e.message));
  await login(a, 'admin', 'admin'); await a.goto(B + '/gestion-tienda/#ajustes'); await a.waitForSelector('#bk-g-sf');
  ok((await a.textContent('.bk-g-card:nth-of-type(2)')).includes('_wcdn_invoice_number'), 'detector encuentra _wcdn_invoice_number');
  await a.locator('tr', { hasText: '_wcdn_invoice_number' }).locator('[data-use="consecutivo"]').click();
  await a.click('#bk-g-sf button'); await a.waitForTimeout(1000);
  await a.goto(B + '/gestion-tienda/#pedidos'); await a.waitForSelector('.bk-g-orders');
  ok((await a.textContent('.bk-g-orders thead')).includes('Consecutivo'), 'columna Consecutivo al configurarlo');
  await a.fill('input[name="q"]', '2405'); await a.waitForTimeout(1200);
  ok(await a.locator('.bk-g-orders tbody tr').count() >= 1, 'búsqueda por consecutivo');
  // Móvil
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', e => errs.push(e.message));
  await m.goto(B + '/gestion-tienda/#pedidos'); await m.waitForSelector('.bk-g-orders');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'móvil sin scroll horizontal de página');
  await m.screenshot({ path: '/tmp/claude-0/bk-local/g-mobile.png' });
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
