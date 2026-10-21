// Auditoría de clics en el sitio público (solo lectura): node tools/ux-audit.js [salida]
// Busca causas de "dead clicks" (cosas que parecen tocables y no hacen nada, objetivos tapados por otro elemento,
// objetivos pequeños, saltos de diseño que mueven el contenido bajo el dedo), "quickbacks" (enlaces que redirigen a
// otra página distinta de la esperada, páginas lentas) y "rage clicks" (controles que tardan en responder).
// Bloquea píxeles y analítica (no ensucia la pauta) y no crea pedidos.
const { chromium, devices, request } = require('playwright');
const fs = require('fs');

const BASE = 'https://buckler.com.co';
const OUT = process.argv[2] || 'snapshots/ux-audit';
const TRACK = /googletagmanager|google-analytics|analytics\.google|connect\.facebook|facebook\.com\/tr|doubleclick|googleadservices|tiktok|clarity\.ms|hotjar|pinterest|frontendInjection|adsagent/;
const INTERACTIVE = 'a[href],button,input,select,textarea,label,summary,[role=button],[role=tab],[role=radio],[role=link],[role=option],[role=checkbox],[onclick],[tabindex]:not([tabindex="-1"]),video,[data-bk-dialog],[contenteditable]';

const PROXY = process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined;
let api;
async function productUrls() {
  const r = await api.get(BASE + '/wp-json/wc/store/v1/products?per_page=40');
  const list = await r.json();
  const sale = list.find((p) => p.on_sale);
  const normal = list.find((p) => !p.on_sale && p.is_in_stock);
  return { sale: sale && sale.permalink, normal: normal && normal.permalink };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  api = await request.newContext({ proxy: PROXY, ignoreHTTPSErrors: false });
  const prods = await productUrls();
  const PAGES = [
    ['Inicio', '/'],
    ['Tienda', '/tienda/'],
    ['Línea Slim', '/linea-slim/'],
    ['Línea Curvy', '/linea-curvy/'],
    ['¡Sale!', '/sales/'],
    ['Lo nuevo', '/tienda/?nuevo=1'],
    ['Producto', prods.normal],
    ['Producto en Sale', prods.sale],
    ['Búsqueda', '/?s=jean&post_type=product'],
    ['Carrito vacío', '/carrito/'],
    ['Mi cuenta', '/mi-cuenta/'],
    ['Preguntas frecuentes', '/preguntas-frecuentes/'],
    ['Política de envío', '/politica-empresa/politica-de-envio/'],
  ].filter((p) => p[1]).map(([n, u]) => [n, u.replace(BASE, '')]);

  const browser = await chromium.launch({ proxy: process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined });
  const report = { date: new Date().toISOString(), products: prods, pages: [], links: [] };
  const allLinks = new Map();

  for (const [name, path] of PAGES) {
    for (const vp of ['390', '1440']) {
      const ctx = await browser.newContext(vp === '390' ? { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } } : { viewport: { width: 1440, height: 900 } });
      await ctx.route(TRACK, (r) => r.abort());
      const page = await ctx.newPage();
      const errs = [];
      page.on('pageerror', (e) => errs.push(e.message.slice(0, 160)));
      if (vp === '390') { const cdp = await ctx.newCDPSession(page); await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 }); }
      await page.addInitScript(() => {
        window.__cls = 0; window.__shifts = [];
        new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (!e.hadRecentInput) { window.__cls += e.value; (e.sources || []).forEach((s) => s.node && window.__shifts.push((s.node.className && String(s.node.className).slice(0, 60)) || s.node.nodeName)); } } }).observe({ type: 'layout-shift', buffered: true });
        window.__lcp = 0; new PerformanceObserver((l) => { const e = l.getEntries().pop(); if (e) window.__lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
        document.addEventListener('DOMContentLoaded', () => { window.__dcl = performance.now(); });
      });
      const t0 = Date.now();
      let status = 0, finalUrl = '';
      try { const resp = await page.goto(BASE + path, { waitUntil: 'load', timeout: 90000 }); status = resp ? resp.status() : 0; finalUrl = page.url(); } catch (e) { errs.push('GOTO ' + e.message.slice(0, 100)); }
      const tLoad = Date.now() - t0;
      // Momento en que la ficha queda lista para tocar (botones de talla creados por pages.js).
      const sizesReady = /Producto/.test(name) ? await page.waitForSelector('.bk-sizes', { timeout: 20000 }).then(() => Date.now() - t0).catch(() => -1) : null;
      await page.waitForTimeout(2500);
      // Recorre la página para cargar lo diferido y medir saltos al bajar.
      await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } window.scrollTo(0, 0); });
      await page.waitForTimeout(800);

      const m = await page.evaluate(({ INTERACTIVE, mobile }) => {
        const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 2 && r.height > 2 && s.visibility !== 'hidden' && s.display !== 'none' && +s.opacity > 0.05; };
        const label = (el) => {
          const t = (el.getAttribute('aria-label') || el.innerText || el.alt || el.getAttribute('title') || '').replace(/\s+/g, ' ').trim().slice(0, 50);
          const c = (typeof el.className === 'string' ? el.className : '').split(/\s+/).filter(Boolean).slice(0, 2).join('.');
          return `${el.tagName.toLowerCase()}${c ? '.' + c : ''}${t ? ` «${t}»` : ''}`;
        };
        const zone = (el) => { const z = el.closest('header,footer,main,nav,aside,dialog,[class*="bk-"]'); return z ? (z.className && typeof z.className === 'string' ? z.className.split(' ')[0] : z.tagName.toLowerCase()) : ''; };
        // 1. Cursor de mano en algo que no hace nada.
        const fakePointer = [];
        document.querySelectorAll('body *').forEach((el) => {
          if (fakePointer.length > 40 || !vis(el)) return;
          if (getComputedStyle(el).cursor !== 'pointer') return;
          if (el.closest(INTERACTIVE)) return;
          if (el.parentElement && getComputedStyle(el.parentElement).cursor === 'pointer' && !el.parentElement.closest(INTERACTIVE)) return; // ya contado el padre
          fakePointer.push(label(el) + ' — ' + zone(el));
        });
        // 2. Cosas que parecen tocables (fotos, precios, etiquetas, tarjetas de detalle) y no responden.
        const lookClick = [];
        const sel = 'img, .price, .bk-badge, .bk-card__sizes, .bk-desc__item, .bk-benefit, .bk-review, [class*="chip"], [class*="pill"], [class*="tag"], h2, h3';
        document.querySelectorAll(sel).forEach((el) => {
          if (!vis(el) || el.closest(INTERACTIVE) || el.querySelector('a,button')) return;
          const r = el.getBoundingClientRect();
          if (el.tagName === 'IMG' && (r.width < 120 || r.height < 120)) return; // íconos, logos de pago
          lookClick.push(label(el) + ' — ' + zone(el));
        });
        const lookGroups = {};
        lookClick.forEach((s) => { const k = s.replace(/ «.*?»/, ''); lookGroups[k] = (lookGroups[k] || 0) + 1; });
        // 3. Controles tapados por otro elemento (el toque cae en otra cosa).
        const covered = [];
        const ctrls = [...document.querySelectorAll('a[href],button,[role=button],[role=tab],[role=radio],input:not([type=hidden]),select,summary')].filter(vis);
        for (const el of ctrls.slice(0, 400)) {
          const r = el.getBoundingClientRect();
          if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) continue; // solo lo que está en pantalla
          const x = r.left + r.width / 2, y = r.top + r.height / 2;
          const hit = document.elementFromPoint(x, y);
          if (hit && hit !== el && !el.contains(hit) && !hit.contains(el) && !(el.labels && [...el.labels].some((l) => l.contains(hit)))) covered.push(`${label(el)} tapado por ${label(hit)}`);
        }
        // 4. Objetivos pequeños (WCAG 2.2: mínimo 24×24; recomendado 44×44 en celular).
        const small = [];
        ctrls.forEach((el) => {
          const r = el.getBoundingClientRect();
          if (r.width < 24 || r.height < 24) {
            if (el.closest('p, li') && el.tagName === 'A' && getComputedStyle(el).display === 'inline') return; // enlaces dentro de texto: exentos
            small.push(`${label(el)} ${Math.round(r.width)}×${Math.round(r.height)}`);
          }
        });
        // 5. Contenido que solo aparece al pasar el cursor (en celular no existe).
        const hoverOnly = [...document.querySelectorAll('.bk-card__sizes')].filter((e) => !vis(e)).length;
        const links = [...document.querySelectorAll('a[href]')].map((a) => ({ href: a.href, text: (a.innerText || a.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 40) })).filter((l) => l.href.startsWith(location.origin) && !/#|\/wp-json|\?add-to-cart|wa\.me/.test(l.href));
        return {
          title: document.title, h1: [...document.querySelectorAll('h1')].map((h) => h.innerText.trim()).slice(0, 2),
          cls: +window.__cls.toFixed(3), shifts: [...new Set(window.__shifts)].slice(0, 6), lcp: Math.round(window.__lcp), dcl: Math.round(window.__dcl || 0),
          fakePointer, lookGroups, covered: [...new Set(covered)].slice(0, 25), small: [...new Set(small)].slice(0, 25), hoverOnly,
          overflowX: document.documentElement.scrollWidth > innerWidth + 1, links,
        };
      }, { INTERACTIVE, mobile: vp === '390' });

      // 6. Pruebas de toque en la ficha: foto principal, botón de comprar sin talla, guía de tallas.
      const taps = {};
      if (/Producto/.test(name)) {
        const before = page.url();
        const img = page.locator('.bk-pg img, .woocommerce-product-gallery img').first();
        if (await img.count()) {
          await img.click({ timeout: 5000 }).catch(() => {});
          await page.waitForTimeout(700);
          taps.foto = await page.evaluate((b) => ({ urlChange: location.href !== b, dialog: !!document.querySelector('dialog[open], .pswp--open, [class*="lightbox"][class*="open"], [class*="zoom"].is-open') }), before);
        }
        const buy = page.locator('.single_add_to_cart_button').first();
        if (await buy.count()) {
          await page.keyboard.press('Escape');
          await buy.click({ timeout: 5000 }).catch(() => {});
          await page.waitForTimeout(700);
          taps.comprarSinTalla = await page.evaluate(() => (document.querySelector('.bk-inline-msg, .woocommerce-error, [role="alert"]') || {}).textContent?.trim().slice(0, 80) || '(sin mensaje visible)');
        }
      }
      // 7. Tarjeta de producto: ¿tocar la foto abre el producto?
      if (/Tienda|Línea|Sale|nuevo|Inicio|Búsqueda/.test(name)) {
        const card = page.locator('.bk-card').first();
        if (await card.count()) {
          const href = await card.locator('a').first().getAttribute('href').catch(() => null);
          await card.locator('img').first().click({ timeout: 5000 }).catch(() => {});
          await page.waitForTimeout(1500);
          taps.fotoTarjeta = page.url() !== BASE + path && (href ? page.url().startsWith(href.split('?')[0]) : true) ? 'abre el producto' : `no navega (url ${page.url().replace(BASE, '')})`;
        }
      }
      for (const l of m.links) if (!allLinks.has(l.href)) allLinks.set(l.href, { text: l.text, from: name });
      delete m.links;
      await page.screenshot({ path: `${OUT}/${name.replace(/[^\w]+/g, '-')}-${vp}.png`, fullPage: false }).catch(() => {});
      report.pages.push({ name, path, vp, status, finalUrl: finalUrl.replace(BASE, ''), tLoad, sizesReady, errs, taps, ...m });
      console.log(`${name} @${vp}: ${status} ${tLoad} ms · CLS ${m.cls} · cursor-falso ${m.fakePointer.length} · tapados ${m.covered.length} · pequeños ${m.small.length}`);
      await ctx.close();
    }
  }

  // 8. Enlaces internos: redirecciones (posible quickback) y errores.
  for (const [href, info] of allLinks) {
    try {
      const r = await api.get(href, { maxRedirects: 0, failOnStatusCode: false });
      const h = r.headers();
      if (r.status() >= 300) report.links.push({ href: href.replace(BASE, ''), status: r.status(), to: (h.location || '').replace(BASE, ''), by: h['x-redirect-by'] || '', text: info.text, from: info.from });
    } catch (e) { report.links.push({ href, status: 'ERR', to: e.message.slice(0, 60), text: info.text, from: info.from }); }
  }
  report.linksChecked = allLinks.size;
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  console.log(`Enlaces revisados: ${allLinks.size}; con redirección o error: ${report.links.length}`);
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
