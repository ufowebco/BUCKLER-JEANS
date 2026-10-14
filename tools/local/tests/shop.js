const { chromium } = require('playwright');
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const B = 'http://localhost:8899';
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
  const noReload = async () => p.evaluate(() => window.__bkMark === 1);
  await p.goto(B + '/shop/', { waitUntil: 'networkidle' });
  const total0 = Number(await p.getAttribute('[data-bk-shop]', 'data-total'));
  ok(total0 > 24, `tienda: ${total0} prendas con stock`);
  ok(await p.locator('h1').count() === 1 && (await p.textContent('h1')).trim() === 'Tienda', 'un solo H1: Tienda');
  ok(await p.locator('ul.products > li').count() === 24, 'primera página: 24 prendas');
  const robots0 = await p.getAttribute('meta[name="robots"]', 'content').catch(() => '');
  ok(!/noindex/.test(robots0 || ''), 'tienda sin filtros indexable');
  // Filtro línea Curvy
  await p.evaluate(() => { window.__bkMark = 1; });
  await p.check('#bk-f-linea-curvy'); await p.waitForFunction(() => location.search.includes('linea=curvy'));
  await p.waitForTimeout(500);
  const tC = Number(await p.getAttribute('[data-bk-shop]', 'data-total'));
  ok(tC > 0 && tC < total0 && await noReload(), `línea Curvy: ${tC} prendas, sin recargar`);
  const lines = await p.locator('.bk-card__line').allTextContents();
  ok(lines.every(l => /Curvy/.test(l)), 'todas las tarjetas son Línea Curvy');
  ok(await p.evaluate(() => document.activeElement.id) === 'bk-f-linea-curvy', 'el foco se queda en el filtro');
  // Talla 16 + 18
  await p.click('label[for="bk-f-talla-16"]'); await p.waitForFunction(() => /talla=16/.test(location.search)); await p.waitForTimeout(400);
  await p.click('label[for="bk-f-talla-18"]'); await p.waitForFunction(() => /talla=16(%2C|,)18/.test(location.search)); await p.waitForTimeout(400);
  const t2 = Number(await p.getAttribute('[data-bk-shop]', 'data-total'));
  ok(t2 > 0 && t2 <= tC, `Curvy + tallas 16/18: ${t2} prendas · URL ${decodeURIComponent(new URL(p.url()).search)}`);
  const robots = await p.evaluate(async () => (await (await fetch(location.href)).text()).match(/<meta name='robots' content='([^']+)'/)?.[1] || '');
  ok(/noindex/.test(robots) && /follow/.test(robots), 'URL filtrada: ' + robots);
  ok(await p.locator('.bk-shop__chips a').count() >= 3, 'chips de filtros activos');
  // Quitar chip talla 18
  await p.locator('.bk-shop__chips a', { hasText: 'Talla 18' }).click(); await p.waitForFunction(() => !/18/.test(location.search)); await p.waitForTimeout(300);
  ok(!/18/.test(p.url()), 'quitar chip actualiza la URL');
  // Atrás del navegador
  await p.goBack(); await p.waitForTimeout(800);
  ok(/16(%2C|,)18/.test(p.url()) && await p.locator('#bk-f-talla-18').isChecked(), 'botón Atrás restaura filtros');
  // Orden por precio
  await p.click('a.bk-shop__clear >> nth=0'); await p.waitForTimeout(800); await p.evaluate(() => { window.__bkMark = 1; });
  await p.selectOption('#bk-shop-orden', 'precio-asc'); await p.waitForFunction(() => /orden=precio-asc/.test(location.search)); await p.waitForTimeout(400);
  const prices = await p.$$eval('ul.products > li .bk-card__price', els => els.map(e => Number(((e.querySelector('ins .amount') || e.querySelector('.amount'))?.textContent || '').replace(/[^\d]/g, ''))));
  ok(prices.every((v, i) => i === 0 || v >= prices[i - 1]), 'orden por menor precio correcto');
  // Ver más
  await p.click('[data-bk-shop-more]'); await p.waitForFunction(() => document.querySelectorAll('ul.products > li').length > 24);
  const n2 = await p.locator('ul.products > li').count();
  ok(n2 > 24 && /pg=2/.test(p.url()), `"Ver más" añade prendas: ${n2}`);
  ok(await noReload(), 'todo sin recargar la página');
  await p.screenshot({ path: '/tmp/claude-0/bk-local/shop-1440.png' });
  // Categoría (archivo) y página de línea
  await p.goto(B + '/product-category/linea-curvy/jeans/', { waitUntil: 'networkidle' });
  ok((await p.textContent('h1')).includes('Jeans · Línea Curvy'), 'archivo de categoría: ' + (await p.textContent('h1')).trim());
  ok(await p.locator('#bk-f-linea-curvy').count() === 0, 'en una línea no se muestra el filtro de línea');
  await p.goto(B + '/sales/', { waitUntil: 'networkidle' });
  const tS = Number(await p.getAttribute('[data-bk-shop]', 'data-total'));
  ok(tS > 0 && await p.locator('.bk-badge--sale').count() === Math.min(24, tS), `¡Sale!: ${tS} prendas, todas con descuento`);
  // Sin JS
  const nojs = await (await b.newContext({ javaScriptEnabled: false })).newPage();
  await nojs.goto(B + '/shop/?linea=slim&talla=8');
  ok(Number(await nojs.getAttribute('[data-bk-shop]', 'data-total')) > 0 && await nojs.locator('#bk-f-talla-8').isChecked(), 'sin JavaScript también filtra (URL)');
  // Móvil
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage();
  m.on('pageerror', e => errs.push(e.message));
  await m.goto(B + '/shop/', { waitUntil: 'networkidle' });
  ok(!(await m.isVisible('.bk-shop__filters')), 'móvil: filtros ocultos hasta tocar "Filtrar"');
  await m.click('.bk-shop__filter-btn'); await m.waitForTimeout(200);
  ok(await m.isVisible('.bk-shop__filters'), 'móvil: panel de filtros abierto');
  await m.click('label[for="bk-f-talla-20"]'); await m.waitForFunction(() => /talla=20/.test(location.search)); await m.waitForTimeout(500);
  ok(await m.isVisible('.bk-shop__filters') && /Ver \d+ prenda/.test(await m.textContent('.bk-shop__apply')), 'panel sigue abierto con el conteo: ' + (await m.textContent('.bk-shop__apply')).trim());
  await m.screenshot({ path: '/tmp/claude-0/bk-local/shop-390-filters.png' });
  await m.click('.bk-shop__apply'); await m.waitForTimeout(800);
  ok(!(await m.isVisible('.bk-shop__filters')), 'móvil: "Ver N prendas" cierra el panel');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'móvil sin scroll horizontal');
  await m.screenshot({ path: '/tmp/claude-0/bk-local/shop-390.png' });
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
