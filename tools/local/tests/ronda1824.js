// 0.18.24: "Ver más" y el botón Atrás. Antes, al volver de un producto después de "Ver más", ?pg=2 pintaba solo las
// prendas 25–48 en la posición de la 48 (la clienta veía otra lista → quickbacks en celular). Ahora ?pg=N pinta 1..N×24.
const { chromium, devices } = require('playwright');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };

(async () => {
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ ...devices['iPhone 13'] })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  const hrefs = () => p.$$eval('ul.products > li .bk-card a[href]', (x) => [...new Set(x.map((a) => a.href))]);
  await p.goto(B + '/shop/', { waitUntil: 'networkidle' });
  const first = await hrefs();
  ok(first.length === 24, `primera vista: 24 prendas (${first.length})`);
  await p.locator('a[data-bk-shop-more]').tap(); await p.waitForFunction(() => document.querySelectorAll('ul.products > li').length > 24);
  const after = await hrefs();
  const items = await p.$$eval('ul.products > li', (x) => x.length);
  ok(/pg=2/.test(p.url()) && items > 24 && after.length === items && first.every((h) => after.includes(h)), `"Ver más" añade sin repetir (${items} prendas, ${after.length} distintas)`);
  // Abrir una de las nuevas y volver.
  const target = after[after.length - 3];
  const link = p.locator(`ul.products > li .bk-card a[href="${target}"]`).first();
  await link.scrollIntoViewIfNeeded();
  const yBefore = await p.evaluate(() => scrollY);
  await link.tap(); await p.waitForURL((u) => !/\/shop\//.test(u.href), { timeout: 20000 });
  await p.goBack(); await p.waitForLoadState('load'); await p.waitForTimeout(1500);
  const back = await hrefs();
  ok(back.length === after.length && back.every((h, i) => h === after[i]), `al volver atrás la lista es la misma (${back.length} prendas, en el mismo orden)`);
  const vis = await p.evaluate((h) => { const a = document.querySelector(`ul.products a[href="${h}"]`); if (!a) return false; const r = a.getBoundingClientRect(); return r.bottom > 0 && r.top < innerHeight; }, target);
  ok(vis, `y la prenda que abrió sigue a la vista (scroll ${yBefore} → ${await p.evaluate(() => Math.round(scrollY))})`);
  // URL directa ?pg=2: lista acumulada y noindex.
  const q = await (await b.newContext()).newPage();
  await q.goto(B + '/shop/?pg=2', { waitUntil: 'networkidle' });
  const direct = await q.$$eval('ul.products > li', (x) => x.length);
  ok(direct === items, `/shop/?pg=2 muestra las prendas 1 a ${direct}`);
  ok(/noindex/.test(await q.getAttribute('meta[name="robots"]', 'content')), '?pg=2 lleva noindex (Google indexa solo la lista base)');
  await q.goto(B + '/shop/', { waitUntil: 'networkidle' });
  ok(!/noindex/.test((await q.getAttribute('meta[name="robots"]', 'content').catch(() => '')) || ''), 'la tienda base se sigue indexando');
  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
