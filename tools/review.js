// Revisión del sitio público (solo lectura): node tools/review.js [salida]
// Por página y ancho (1440/390): estado HTTP, título, H1, errores JS, peticiones fallidas, imágenes rotas,
// scroll horizontal, tiempos. Luego revisa los enlaces internos y el flujo ficha → talla → carrito (y vacía el
// carrito al final). No abre el checkout con productos: con el checkout de bloques eso crea un pedido borrador.
// Bloquea píxeles y analítica para no ensuciar los datos de pauta (Meta, Google, TikTok).
// Sirve de línea base para comparar después del despliegue.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'https://buckler.com.co';
const OUT = process.argv[2] || 'snapshots/review';
const PAGES = [
  ['inicio', '/'],
  ['tienda', '/tienda/'],
  ['linea-slim', '/linea-slim/'],
  ['linea-curvy', '/linea-curvy/'],
  ['sales', '/sales/'],
  ['cat-curvy', '/categoria-producto/linea-curvy/'],
  ['cat-nueva', '/categoria-producto/linea-nueva/'],
  ['cat-jeans-curvy', '/categoria-producto/linea-curvy/jeans/'],
  ['busqueda', '/?s=jean&post_type=product'],
  ['faq', '/preguntas-frecuentes/'],
  ['politica-envio', '/politica-empresa/politica-de-envio/'],
  ['carrito', '/carrito/'],
  ['mi-cuenta', '/mi-cuenta/'],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ proxy: process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined });
  const report = { date: new Date().toISOString(), pages: [], links: [], flow: [] };
  const links = new Set();
  const TRACK = /googletagmanager|google-analytics|analytics\.google|connect\.facebook|facebook\.com\/tr|doubleclick|googleadservices|tiktok|clarity\.ms|hotjar|pinterest/;
  const block = (ctx) => ctx.route(TRACK, (r) => r.abort());

  const audit = async (ctx, name, path, w) => {
    const page = await ctx.newPage();
    const errs = []; const failed = [];
    page.on('pageerror', (e) => errs.push(e.message.slice(0, 160)));
    page.on('console', (m) => { if (m.type() === 'error' && !/ERR_FAILED|ERR_BLOCKED|fbq|gtag|ttq/.test(m.text())) errs.push(m.text().slice(0, 160)); });
    page.on('response', (r) => { if (r.status() >= 400 && !/google|facebook|doubleclick|tiktok|clarity|hotjar/.test(r.url())) failed.push(`${r.status()} ${r.url().slice(0, 140)}`); });
    page.on('requestfailed', (r) => { if (!TRACK.test(r.url())) failed.push(`ERR ${r.failure()?.errorText} ${r.url().slice(0, 140)}`); });
    let reqs = 0; page.on('requestfinished', () => reqs++);
    const t0 = Date.now();
    let status = 0;
    try { status = (await page.goto(BASE + path, { waitUntil: 'load', timeout: 90000 }))?.status() || 0; } catch (e) { errs.push('GOTO ' + e.message.slice(0, 120)); }
    const tLoad = Date.now() - t0;
    await page.waitForTimeout(2500);
    const m = await page.evaluate(() => ({
      title: document.title,
      h1: [...document.querySelectorAll('h1')].map((h) => h.innerText.trim().slice(0, 70)).filter(Boolean),
      robots: document.querySelector('meta[name="robots"]')?.content || '',
      canonical: document.querySelector('link[rel="canonical"]')?.href || '',
      brokenImgs: [...document.images].filter((i) => i.complete && i.naturalWidth === 0 && i.src && !i.src.startsWith('data:')).map((i) => i.src.slice(0, 120)),
      noAlt: [...document.images].filter((i) => !i.hasAttribute('alt')).length,
      overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
      products: document.querySelectorAll('li.product, .product.type-product, .et_pb_shop li').length,
      links: [...document.querySelectorAll('a[href]')].map((a) => a.href).filter((h) => h.startsWith(location.origin)),
      htmlKB: Math.round(document.documentElement.outerHTML.length / 1024),
    }));
    m.links.forEach((l) => links.add(l.split('#')[0]));
    delete m.links;
    await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: false });
    report.pages.push({ name, path, w, status, tLoad, requests: reqs, ...m, jsErrors: [...new Set(errs)], failed: [...new Set(failed)].slice(0, 15) });
    console.log(`${w} ${status} ${name} ${tLoad}ms req=${reqs} h1=${m.h1.length} err=${errs.length} fail=${failed.length} broken=${m.brokenImgs.length} ovf=${m.overflowX}`);
    await page.close();
  };

  const FLOW_ONLY = !!process.env.FLOW_ONLY;
  for (const w of FLOW_ONLY ? [] : [1440, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, isMobile: w < 500, hasTouch: w < 500, locale: 'es-CO' });
    await block(ctx);
    for (const [name, path] of PAGES) await audit(ctx, name, path, w);
    await ctx.close();
  }

  // Un producto en stock (primero de la tienda) para la ficha y el flujo.
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'es-CO' });
  await block(ctx);
  const product = process.env.PRODUCT || [...links].find((l) => /\/producto\//.test(l));
  if (product && !FLOW_ONLY) {
    const path = product.replace(BASE, '');
    await audit(ctx, 'producto', path, 1440);
    const mctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'es-CO' });
    await block(mctx);
    await audit(mctx, 'producto', path, 390);
    await mctx.close();
  }

  // Enlaces internos (sin parámetros de filtros/añadir al carrito).
  const toCheck = FLOW_ONLY ? [] : [...links].filter((l) => !/add-to-cart|\?.*(orderby|filter|pa_|min_price)|wp-admin|wp-login|logout|\/feed\//.test(l)).slice(0, 250);
  const req = await browser.newContext();
  for (const l of toCheck) {
    let s = 0;
    try { s = (await req.request.get(l, { maxRedirects: 5, timeout: 30000 })).status(); } catch { s = -1; }
    if (s >= 400 || s < 0) report.links.push({ url: l, status: s });
  }
  console.log(`enlaces revisados: ${toCheck.length}, rotos: ${report.links.length}`);
  report.linksChecked = toCheck.length;

  // Flujo de compra hasta el checkout (no se paga ni se crea pedido).
  if (product) {
    const p = await ctx.newPage();
    const step = (s, ok, extra = '') => { report.flow.push({ step: s, ok, extra }); console.log(`${ok ? 'OK  ' : 'FAIL'} flujo: ${s} ${extra}`); };
    try {
      await p.goto(product, { waitUntil: 'load', timeout: 90000 });
      // Tallas: <select> nativo o botones de BodyCommerce (li.variable-item con el select oculto).
      const sel = p.locator('form.variations_form select').first();
      const swatches = p.locator('form.variations_form li.variable-item.instock');
      let picked = '';
      if (await swatches.count()) {
        for (let i = 0; i < await swatches.count() && !picked; i++) {
          await swatches.nth(i).click({ force: true }); await p.waitForTimeout(700);
          if (await p.locator('.single_add_to_cart_button:not(.disabled)').count()) picked = await swatches.nth(i).getAttribute('data-value');
        }
        step('elegir talla (botones BodyCommerce)', !!picked, picked);
      } else if (await sel.count()) {
        for (const v of await sel.locator('option').evaluateAll((o) => o.map((x) => x.value).filter(Boolean))) {
          await sel.selectOption(v); await p.waitForTimeout(600);
          if (await p.locator('.single_add_to_cart_button:not(.disabled)').count()) { picked = v; break; }
        }
        step('elegir talla (select)', !!picked, picked);
      } else step('elegir talla', false, 'sin selector de variaciones');
      await p.locator('.single_add_to_cart_button').first().click();
      await p.waitForTimeout(4000);
      const count = await p.evaluate(() => document.cookie.includes('woocommerce_items_in_cart'));
      step('añadir al carrito', count, count ? 'cookie de carrito creada' : '');
      await p.goto(BASE + '/carrito/', { waitUntil: 'load' }); await p.waitForTimeout(1500);
      const items = await p.locator('.woocommerce-cart-form__cart-item, .cart_item, .wc-block-cart-items__row').count();
      step('carrito con el producto', items > 0, `${items} línea(s)`);
      await p.screenshot({ path: `${OUT}/flujo-carrito-1440.png` });
      // Vaciar el carrito (no dejar sesiones con productos en producción).
      // El enlace "quitar" del carrito es un GET con nonce; se abre directo (el primero puede ser del carrito lateral, oculto).
      for (let i = 0; i < 5; i++) {
        const href = await p.locator('.woocommerce-cart-form a.remove, td.product-remove a').first().getAttribute('href').catch(() => null);
        if (!href) break;
        await p.goto(href, { waitUntil: 'load' }); await p.waitForTimeout(1500);
      }
      const left = await p.locator('.woocommerce-cart-form__cart-item, .cart_item').count();
      step('carrito vaciado', left === 0, `${left} línea(s)`);
      // Checkout con el carrito vacío: solo comprobar que responde (Woo redirige al carrito).
      const r = await p.goto(BASE + '/finalizar-compra/', { waitUntil: 'load' });
      step('checkout responde', !!r && r.status() < 400, `${r?.status()} → ${new URL(p.url()).pathname}`);
    } catch (e) { step('error en el flujo', false, e.message.slice(0, 160)); }
  }

  fs.writeFileSync(`${OUT}/${FLOW_ONLY ? 'flow' : 'report'}.json`, JSON.stringify(report, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
