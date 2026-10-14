const { chromium } = require('playwright');
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
(async () => {
  const b = await chromium.launch(); const errs = [];
  for (const [url, name] of [['/shop/', 'shop'], ['/lo-nuevo-test/', 'grilla']]) for (const w of [1440, 390]) {
    const p = await (await b.newContext({ viewport: { width: w, height: 1000 }, hasTouch: w < 500 })).newPage();
    p.on('pageerror', e => errs.push(e.message));
    const loaded = new Set(); p.on('requestfinished', r => { if (/\.jpe?g/.test(r.url())) loaded.add(r.url().split('/').pop()); });
    await p.goto('http://localhost:8899' + url, { waitUntil: 'networkidle' });
    const cards = await p.locator('[data-bk-card]').count();
    ok(cards >= 5, `${name} ${w}: ${cards} tarjetas`);
    const deferred = await p.locator('img[data-bk-src]').count();
    ok(deferred > 0, `${name} ${w}: ${deferred} fotos extra diferidas; descargadas al cargar: ${loaded.size}`);
    ok(!(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth)), `${name} ${w}: sin scroll horizontal`);
    if (w === 1440 && name === 'shop') {
      const card = p.locator('[data-bk-card]:has(.bk-card__next)').first();
      await card.hover(); await p.waitForTimeout(300);
      ok(await card.locator('img[data-bk-src]').count() === 0, 'al pasar el cursor se cargan sus fotos');
      ok(await card.locator('.bk-card__sizes').isVisible(), 'tallas visibles al pasar el cursor');
      await card.locator('.bk-card__next').click(); await p.waitForTimeout(600);
      ok(await card.locator('.bk-card__dots i.on').evaluate(el => [...el.parentNode.children].indexOf(el)) === 1, 'flecha → foto 2');
      ok(p.url().endsWith('/shop/'), 'la flecha no navega (sin dead click ni salto)');
      const flare = p.locator('[data-bk-card]', { hasText: 'FLARE' });
      ok(await flare.locator('.bk-badge--new').count() === 1, 'producto destacado muestra NUEVO');
      ok((await flare.locator('.bk-card__sizes .is-off').allTextContents()).join() === '18', 'talla 18 tachada (agotada)');
      ok(await p.locator('[data-bk-card]', { hasText: 'SHORT' }).locator('.bk-badge--sale').textContent() === '-12 %', 'descuento -12 % en el short');
      const sr = await flare.locator('.bk-sr-only').textContent();
      ok(/Tallas disponibles: 14, 16, 20, 22, 24/.test(sr), 'lector de pantalla: ' + sr);
      await p.screenshot({ path: `/tmp/claude-0/bk-local/cards-1440.png` });
      await Promise.all([p.waitForNavigation(), flare.locator('.bk-card__body').click({ position: { x: 5, y: 40 } })]);
      ok(/\/product\//.test(p.url()), 'clic en zona sin enlace de la tarjeta → ficha');
    }
    if (w === 390 && name === 'grilla') {
      const t = p.locator('.bk-card__track').first();
      await t.evaluate(el => el.scrollTo({ left: el.clientWidth }));
      await p.waitForTimeout(400);
      ok(await p.locator('[data-bk-card]').first().locator('img[data-bk-src]').count() === 0, 'al deslizar en móvil se cargan las fotos');
      await p.screenshot({ path: `/tmp/claude-0/bk-local/cards-390.png`, fullPage: true });
    }
  }
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})();
