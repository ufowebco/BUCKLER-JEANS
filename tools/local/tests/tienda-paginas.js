// Tienda pintada por buckler-core en todas las páginas de tienda + enlaces del menú en modo "filters".
const { chromium } = require('playwright');
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const B = 'http://localhost:8899';
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1440, height: 900 } }); const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  const info = async (path) => { await p.goto(B + path); return p.evaluate(() => ({ h1: [...document.querySelectorAll('h1')].map(h => h.innerText.trim()), shop: !!document.querySelector('.bk-shop'), cards: document.querySelectorAll('.bk-card').length, main: !!document.querySelector('#main-content.bk-shop-page'), robots: document.querySelector('meta[name="robots"]')?.content || '' })); };
  for (const [path, h1] of [['/shop/', 'Tienda'], ['/linea-slim/', 'Línea Slim'], ['/linea-curvy/', 'Línea Curvy'], ['/sales/', '¡Sale!'], ['/shop/?nuevo=1', 'Lo nuevo'], ['/?s=jean&post_type=product', 'Resultados']]) {
    const i = await info(path);
    ok(i.shop && i.main && i.h1.length === 1 && i.h1[0].includes(h1) && i.cards > 0, `${path}: tienda propia, un H1 "${i.h1.join(' | ')}", ${i.cards} productos`);
  }
  // Línea Curvy: solo productos Curvy
  await p.goto(B + '/linea-curvy/');
  const lines = await p.$$eval('.bk-card', els => els.map(e => e.textContent));
  ok(lines.every(t => !/Línea Slim/.test(t)), 'línea Curvy sin productos Slim');
  // Menú: categorías → página de la línea con ?prenda=
  const links = await p.$$eval('.bk-header a[href*="prenda="]', els => els.map(a => a.getAttribute('href')));
  ok(links.length > 0 && links.every(h => /\/linea-(slim|curvy)\/\?prenda=/.test(h)), `menú enlaza prendas por filtro (${links.length})`);
  const jeans = links.find(h => /linea-curvy\/\?prenda=jeans/.test(h));
  await p.goto(jeans.startsWith('http') ? jeans : B + jeans);
  ok(/Jeans/i.test(await p.textContent('.bk-shop')) && (await info(new URL(p.url()).pathname + new URL(p.url()).search)).robots.includes('noindex'), 'prenda filtrada (noindex,follow)');
  const nuevo = await p.$eval('.bk-header a', () => [...document.querySelectorAll('.bk-header a')].find(a => /Lo nuevo/.test(a.textContent))?.getAttribute('href'));
  ok(/nuevo=1/.test(nuevo || ''), '"Lo nuevo" → /tienda/?nuevo=1');
  const foot = await p.$$eval('.bk-footer a[href*="prenda="]', els => els.length);
  ok(foot > 0, `footer enlaza prendas por filtro (${foot})`);
  // Página normal no afectada
  const faq = await info('/preguntas-frecuentes/');
  ok(!faq.shop, 'páginas normales sin la tienda');
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
