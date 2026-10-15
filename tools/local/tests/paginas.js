// Páginas limpias: inicio, ficha, carrito, checkout → gracias, mi cuenta, FAQ, política, móvil.
// Antes: php seed-pages.php
const { chromium } = require('playwright');
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const B = 'http://localhost:8899';
const OUT = '/tmp/claude-0/bk-local';

(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('console', (m) => { if (m.type() === 'error' && !/favicon|404/.test(m.text())) errs.push(m.text()); });
  const doc = () => p.evaluate(() => ({
    h1: [...document.querySelectorAll('h1')].map((h) => h.innerText.trim()),
    header: !!document.querySelector('.bk-header'), footer: !!document.querySelector('.bk-footer'),
    main: !!document.querySelector('main#main-content'),
    divi: [...document.querySelectorAll('link[rel=stylesheet],script[src]')].map((e) => e.href || e.src).filter((u) => /divi|\/et-|dnxte|bodycommerce/i.test(u)).length,
    font: getComputedStyle(document.body).fontFamily,
  }));

  // Inicio
  await p.goto(B + '/');
  let d = await doc();
  ok(d.header && d.footer && d.main && d.h1.length === 1, `inicio: documento propio, un H1 "${d.h1[0]}"`);
  ok(/Manrope/.test(d.font), 'fuente Manrope local');
  const tabs = p.locator('[role="tab"]');
  ok(await tabs.count() === 2, 'pestañas Lo nuevo Slim / Curvy');
  await tabs.nth(1).click();
  ok(await p.locator('[role="tabpanel"]:not([hidden]) .bk-card').count() > 0 && await tabs.nth(1).getAttribute('aria-selected') === 'true', 'pestaña Curvy muestra productos');
  ok(await p.locator('.bk-home-benefits li').count() === 4, 'beneficios');
  // Ronda 0.12: barra verde con 3 anuncios + sellos, banner sin pausa, caché y dieta
  const ann = await p.evaluate(() => { const vp = document.querySelector('.bk-announce__viewport').getBoundingClientRect(); const inView = [...document.querySelectorAll('.bk-announce__item')].filter((li) => { const r = li.getBoundingClientRect(); return getComputedStyle(li).display !== 'none' && r.left >= vp.left - 1 && r.right <= vp.right + 1; }).length; return { bg: getComputedStyle(document.querySelector('.bk-announce')).backgroundColor, inView, badges: [...document.querySelectorAll('.bk-announce__badges li')].map((b) => b.innerText.trim()), toggle: !!document.querySelector('.bk-announce__toggle') }; });
  ok(ann.bg === 'rgb(228, 236, 106)' && !ann.toggle, 'barra de anuncios verde y sin botón de pausa');
  ok(ann.inView === 3, `escritorio: 3 anuncios a la vista (${ann.inView})`);
  ok(ann.badges.length === 2 && /tienda online/.test(ann.badges[0]) && /colombiano/.test(ann.badges[1]), 'sellos a la derecha: ' + ann.badges.join(' | '));
  const tr0 = await p.locator('.bk-announce__list').evaluate((e) => e.style.transform);
  await p.waitForTimeout(4500);
  ok(tr0 !== await p.locator('.bk-announce__list').evaluate((e) => e.style.transform), 'los anuncios avanzan solos');
  ok(await p.locator('.bk-carousel__toggle').count() === 0, 'banner principal sin botón de pausa');
  ok(await p.locator('.bk-announce a[href*="g.page"]').count() >= 1, 'anuncio: califícanos en Google');
  const heads = await p.evaluate(async () => { const r = await fetch('/?t=' + Date.now(), { credentials: 'omit' }); return r.headers.get('x-bk-cache'); });
  ok(heads === 'store', 'inicio sin sesión: se guarda en la caché del servidor');
  const scr = await p.evaluate(() => [...document.querySelectorAll('script[src],link[rel=stylesheet]')].map((e) => e.src || e.href).filter((u) => /cart-fragments|selectWoo|select2|photoswipe|flexslider|fonts\.googleapis|dashicons|woocommerce-layout|woocommerce\.css/.test(u)));
  ok(scr.length === 0, 'inicio: sin archivos que no usa ' + scr.join(' '));
  const sp = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await sp.goto(B + '/');
  const sp0 = await sp.evaluate(() => ({ opacity: getComputedStyle(document.documentElement).opacity, legacy: [...document.scripts].some((s) => /cargarGrillaAjax|style\.opacity = '0'/.test(s.textContent)), gtm: !!window.bkGtmRan, delayed: document.querySelectorAll('script[type="text/bk-delay"]').length, jq: !!window.jQuery, carousel: document.querySelector('.bk-carousel__track')?.scrollLeft || 0 }));
  ok(sp0.opacity === '1' && !sp0.legacy, 'snippets antiguos quitados (la página ya no se oculta hasta cargar todo)');
  ok(!sp0.gtm && sp0.delayed >= 1, 'Google Tag Manager espera a la primera interacción');
  ok(!sp0.jq, 'inicio sin jQuery (nada lo usa)');
  await sp.waitForTimeout(7000);
  ok((await sp.evaluate(() => document.querySelector('.bk-carousel__track')?.scrollLeft || 0)) === sp0.carousel, 'banner quieto hasta que la persona interactúa');
  await sp.mouse.move(300, 300); await sp.waitForTimeout(800);
  ok(await sp.evaluate(() => !!window.bkGtmRan && Array.isArray(window.dataLayer)), 'al mover el mouse se carga Google Tag Manager');
  const srcset = await sp.evaluate(() => { const i = document.querySelector('.bk-card__img'); return i && (i.getAttribute('srcset') || '').split(',').length; });
  ok(srcset >= 2, `tarjetas con srcset (${srcset} tamaños)`);
  const r13 = await sp.evaluate(() => ({ css: [...document.querySelectorAll('link[rel=stylesheet]')].map((l) => l.href).filter((h) => /buckler-core\/assets\/css|bk-cache/.test(h)), pswp: !!document.querySelector('.pswp'), hero: [...document.querySelectorAll('.bk-carousel__slide img')].slice(1).every((i) => i.hasAttribute('data-bk-src') || i.closest('noscript')), menu: [...document.querySelectorAll('.bk-mega__col')].map((c) => [...c.querySelectorAll('ul a')].map((a) => a.textContent.trim()).filter((t) => !/^Ver todo/.test(t))) }));
  ok(r13.css.length === 1 && /bk-cache\/bk-/.test(r13.css[0]), 'un solo archivo CSS de buckler-core ' + r13.css.join(' '));
  ok(!r13.pswp, 'sin el HTML de PhotoSwipe al final (franja bajo el footer)');
  ok(r13.hero, 'banners 2..n no se descargan al cargar la página');
  const lines2 = r13.menu.slice(0, 2).map((c) => c.slice(1).map((t) => t.replace(/^Sets /, 'Set ')));
  ok(lines2.length === 2 && JSON.stringify(lines2[0]) === JSON.stringify(lines2[1]), 'menú: las dos líneas con el mismo orden de prendas ' + JSON.stringify(lines2));
  const nf = await sp.goto(B + '/.well-known/ai-catalog.json');
  ok(nf.status() === 404 && /No encontramos/.test(await sp.locator('h1').innerText()), 'dirección inexistente: 404 real con página propia (no redirige a la portada)');
  const llms = await sp.goto(B + '/llms.txt');
  ok(llms.status() === 200 && /^# Buckler Jeans/.test(await llms.text()), 'llms.txt para asistentes de IA');
  await sp.close();
  // Ronda 0.11: líneas en una fila, menú Tienda visible, carrito flotante, buscador en vivo
  const ln = await p.evaluate(() => [...document.querySelectorAll('.bk-lineas > *')].map((e) => { const r = e.getBoundingClientRect(); return { t: Math.round(r.top), w: Math.round(r.width) }; }));
  ok(ln.length === 2 && ln[0].t === ln[1].t && ln[0].w < 760, 'inicio: las 2 líneas en una sola fila ' + JSON.stringify(ln));
  await p.hover('.has-mega > button');
  await p.waitForTimeout(400);
  const mega = await p.evaluate(() => { const m = document.querySelector('.bk-mega'); if (!m || m.hidden) return 'oculto'; const r = m.getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(r.height / 2, 60)); return m.contains(el) ? 'visible' : 'tapado por ' + (el && el.className); });
  ok(mega === 'visible', 'menú Tienda: se ve encima del contenido al pasar el mouse (' + mega + ')');
  await p.mouse.move(700, 800); await p.waitForTimeout(400);
  ok(await p.locator('.bk-fcart').count() === 1 && await p.locator('.bk-wa').count() === 0, 'botón flotante: carrito en lugar de WhatsApp');
  await p.click('.bk-fcart');
  await p.waitForSelector('.bk-sc.is-open', { timeout: 5000 }).catch(() => {});
  ok(await p.locator('.bk-sc.is-open').count() === 1, 'botón flotante abre el carrito');
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  const sq = p.locator('.bk-bar__search input[type="search"]');
  await sq.click(); await sq.pressSequentially('oxfor', { delay: 40 });
  await p.waitForSelector('.bk-bar__search .bk-sr__item', { timeout: 5000 }).catch(() => {});
  const sr = await p.locator('.bk-bar__search .bk-sr__item').count();
  ok(sr >= 1 && await sq.getAttribute('aria-expanded') === 'true', `buscador en vivo muestra productos (${sr})`);
  await p.screenshot({ path: `${OUT}/pg-search-1440.png` });
  await sq.press('ArrowDown'); await sq.press('Enter'); await p.waitForLoadState('load');
  ok(/\/product\//.test(p.url()), 'buscador: flecha + Enter abre el producto');
  const bc = await p.evaluate(() => [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => JSON.parse(s.textContent)).find((j) => j['@type'] === 'BreadcrumbList'));
  ok(bc && bc.itemListElement.length >= 3, 'ficha: migas con datos estructurados BreadcrumbList');
  await p.goto(B + '/shop/?linea=slim');
  ok(await p.locator('.bk-crumbs').count() === 1, 'tienda: migas de pan');
  await p.evaluate(() => window.scrollTo(0, 900)); await p.waitForTimeout(200);
  const barPos = await p.evaluate(() => { const e = document.querySelector('.bk-shop__bar'); return e ? getComputedStyle(e).position : 'none'; });
  ok(!['sticky', 'fixed'].includes(barPos), 'tienda: la barra "Ordenar / Prendas" no queda fija (' + barPos + ')');
  await p.screenshot({ path: `${OUT}/pg-home-1440.png`, fullPage: true });

  // Ficha
  await p.goto(B + '/shop/?linea=slim'); await p.locator('.bk-card a').first().click(); await p.waitForLoadState('load');
  d = await doc();
  ok(d.h1.length === 1 && /Línea Slim/.test(d.h1[0]), `ficha: un H1 con la línea "${d.h1[0]}"`);
  ok(await p.locator('.bk-pg__slide').count() >= 1, `galería propia (${await p.locator('.bk-pg__slide').count()} diapositivas)`);
  ok(await p.locator('.bk-crumbs').count() === 1, 'migas de pan');
  const pos = await p.evaluate(() => { const g = document.querySelector('.bk-pg').getBoundingClientRect(); const sm = document.querySelector('.summary').getBoundingClientRect(); return { side: sm.left > g.right - 5, top: Math.abs(sm.top - g.top) < 40 }; });
  ok(pos.side && pos.top, 'ficha: compra al lado de la galería (aunque haya un div.clearfix envolviendo)');
  ok(await p.locator('.bk-test-share').count() === 0, 'ficha: sin botones de compartir de plugins');
  const colors = await p.evaluate(() => ({ price: getComputedStyle(document.querySelector('.summary .price')).color, btn: getComputedStyle(document.querySelector('.single_add_to_cart_button')).backgroundColor }));
  ok(colors.price === 'rgb(26, 57, 85)' && colors.btn === 'rgb(26, 57, 85)', 'precio y botón en azul de marca: ' + JSON.stringify(colors));
  const sizes = p.locator('.bk-sizes [role="radio"]');
  ok(await sizes.count() >= 3 && await p.locator('.bk-sizes[role="radiogroup"]').count() === 1, `tallas como radiogroup (${await sizes.count()})`);
  ok(await p.locator('select[name^="attribute_"]').evaluate((s) => s.classList.contains('bk-visually-hidden')), 'el select de Woo queda oculto pero presente');
  const avail = p.locator('.bk-sizes [role="radio"]:not(.is-out)').first();
  await avail.focus(); await p.keyboard.press('ArrowRight');
  ok(await p.locator('.bk-sizes [aria-checked="true"]').count() === 1, 'tallas con teclado (flechas)');
  await avail.click();
  const val = await avail.getAttribute('data-value');
  ok(await p.locator('select[name^="attribute_"]').inputValue() === val, `elegir talla ${val} actualiza el formulario de Woo`);
  await p.waitForTimeout(400);
  ok(!(await p.locator('.single_add_to_cart_button').getAttribute('class')).includes('disabled'), 'botón de compra habilitado');
  ok(await p.locator('.bk-pdp__addi').count() === 1 && await p.locator('.bk-pdp__benefits li').count() === 3, 'cuotas Addi y beneficios');
  ok(await p.locator('.woocommerce-tabs').count() === 0 && await p.locator('#reviews, #comments, .comment-respond').count() === 0, 'ficha: sin pestañas ni comentarios');
  ok(await p.locator('.bk-pdp__desc h2').innerText().catch(() => '') === 'Descripción' || await p.locator('.bk-pdp__desc').count() === 0, 'ficha: descripción como sección');
  const rv = await p.evaluate(() => ({ n: document.querySelectorAll('.bk-reviews .bk-review').length, low: [...document.querySelectorAll('.bk-review__text')].some((t) => /2 estrellas/.test(t.textContent)), score: document.querySelector('.bk-reviews__score strong')?.textContent, promo: document.querySelector('.bk-reviews .bk-promo a[href*="g.page"]') !== null }));
  ok(rv.n === 4 && !rv.low && rv.score && rv.promo, 'ficha: reseñas de Google (4, sin las de menos de 4★) con promedio y promo ' + JSON.stringify(rv));
  const lazy = await p.evaluate(() => { const imgs = [...document.querySelectorAll('.bk-pg__slide img')]; return { first: imgs[0] && !imgs[0].hasAttribute('data-bk-src'), rest: imgs.slice(2).every((i) => i.hasAttribute('data-bk-src')) }; });
  ok(lazy.first && lazy.rest, 'ficha: fotos 3..n no se descargan hasta acercarse');
  if (await p.locator('.bk-pg__thumb.is-video').count()) {
    // Chromium de pruebas no trae el códec H.264: se comprueba que se pida reproducir (no que suene).
    await p.evaluate(() => { window.__plays = 0; const o = HTMLMediaElement.prototype.play; HTMLMediaElement.prototype.play = function () { window.__plays++; return o.call(this); }; });
    await p.click('.bk-pg__thumb.is-video'); await p.waitForTimeout(1200);
    const vid = await p.evaluate(() => { const v = document.querySelector('.bk-pg video'); return { controls: v.hasAttribute('controls'), plays: window.__plays, poster: !!v.getAttribute('poster') }; });
    ok(!vid.controls && vid.plays >= 1 && vid.poster, 'video: se reproduce solo al seleccionarlo, sin controles ' + JSON.stringify(vid));
    await p.click('.bk-pg__thumb[data-go="0"]'); await p.waitForTimeout(800);
  }
  const dis = await p.evaluate(() => getComputedStyle(document.querySelector('.single_add_to_cart_button')).backgroundColor);
  ok(dis === 'rgb(26, 57, 85)', 'botón de compra: azul de marca (sin morado) ' + dis);
  const twin = await p.locator('.bk-pdp__twin').count();
  ok(true, `enlace a la otra línea: ${twin ? 'sí' : 'no (sin gemelo para este producto)'}`);
  await p.screenshot({ path: `${OUT}/pg-product-1440.png`, fullPage: true });
  await p.click('.single_add_to_cart_button');
  await p.waitForSelector('.bk-sc.is-open', { timeout: 8000 }).catch(() => {});
  ok(await p.locator('.bk-sc.is-open').count() === 1, 'añadir al carrito abre el carrito flotante');

  // Producto con gemelo (OXFORD en Slim y Curvy)
  await p.goto(B + '/?s=OXFORD&post_type=product'); await p.locator('.bk-card a').first().click(); await p.waitForLoadState('load');
  ok(await p.locator('.bk-pdp__twin').count() === 1, 'OXFORD: enlace "Ver este modelo en la otra línea"');

  // Carrito
  await p.goto(B + '/cart/');
  d = await doc();
  ok(d.h1.length === 1 && await p.locator('.cart_item').count() >= 1, 'carrito clásico con el producto');
  ok(await p.locator('.bk-crumbs').count() === 1, 'carrito: migas de pan');
  const cl = await p.evaluate(() => { const f = document.querySelector('.woocommerce-cart-form').getBoundingClientRect(); const t = document.querySelector('.cart_totals').getBoundingClientRect(); return t.left > f.right - 5 && Math.abs(t.top - f.top) < 60; });
  ok(cl, 'carrito: resumen al lado de los productos');
  ok(await p.locator('.cart_item .bk-qty-btn').count() >= 2 && await p.locator('.bk-cart-trust li').count() === 3, 'carrito: botones − / + y confianza bajo el total');
  ok(await p.locator('.cart_item .quantity > .minus:visible, .cart_item .quantity > .plus:visible').count() === 0, 'carrito: sin los − / + duplicados del snippet del tema');
  ok(await p.locator('.bk-cart-suggest .bk-card').count() >= 1 && await p.locator('.cart-collaterals .cross-sells').count() === 0, 'carrito: sugerencias "Combínalo con"');
  // Una variación con stock ≥ 3 para probar el + (las tallas con 1 unidad lo dejan desactivado, como debe ser).
  if (!(await p.locator('.cart_item .bk-qty-btn[data-step="1"]:not([disabled])').count())) {
    const v = JSON.parse(require('child_process').execSync(`php -r 'require "/tmp/claude-0/bk-local/site/wp-load.php"; foreach (wc_get_products(["limit"=>-1,"status"=>"publish","type"=>"variable","stock_status"=>"instock"]) as $p) foreach ($p->get_children() as $c) { $v=wc_get_product($c); if ($v && $v->get_stock_quantity() >= 3) { echo json_encode(["p"=>$p->get_id(),"v"=>$c,"a"=>$v->get_variation_attributes()]); exit; } }'`).toString());
    const r = await p.evaluate(async (v) => {
      const n = (await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' })).headers.get('Nonce');
      const variation = Object.entries(v.a).map(([k, val]) => ({ attribute: k.replace(/^attribute_/, ''), value: val }));
      return (await fetch('/wp-json/wc/store/v1/cart/add-item', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Nonce: n }, body: JSON.stringify({ id: v.v, quantity: 1, variation }) })).status;
    }, v);
    if (r !== 201) console.log('add-item', r);
    await p.goto(B + '/cart/');
  }
  const row = p.locator('.cart_item').filter({ has: p.locator('.bk-qty-btn[data-step="1"]:not([disabled])') }).first();
  const plus = row.locator('.bk-qty-btn[data-step="1"]');
  const sub0 = await row.locator('.product-subtotal').innerText();
  const q0 = Number(await row.locator('input.qty').inputValue());
  const key = await row.locator('input.qty').getAttribute('name');
  await plus.click();
  await p.waitForFunction(([k, s0]) => { const i = document.querySelector(`input[name="${k}"]`); const e = i && i.closest('.cart_item').querySelector('.product-subtotal'); return e && e.innerText !== s0; }, [key, sub0], { timeout: 15000 }).catch(() => {});
  const row1 = p.locator('.cart_item').filter({ has: p.locator(`input[name="${key}"]`) });
  const sub1 = await row1.locator('.product-subtotal').innerText();
  ok(sub1 !== sub0 && Number(await row1.locator('input.qty').inputValue()) === q0 + 1, `carrito: + actualiza solo (${sub0.trim()} → ${sub1.trim()})`);
  ok(await p.waitForFunction(() => document.activeElement && document.activeElement.classList.contains('bk-qty-btn'), null, { timeout: 1500 }).then(() => true).catch(() => false), 'carrito: el foco vuelve al botón tras actualizar');
  ok(await p.locator('.cart_item .bk-qty-btn').count() >= 2, 'carrito: botones siguen tras actualizar');
  ok(await p.locator('.bk-reviews--cart .bk-review').count() >= 1 && await p.locator('.bk-reviews--cart .bk-promo').count() === 1, 'carrito: reseñas de Google y promo');
  await p.locator('.bk-cart-suggest').scrollIntoViewIfNeeded(); await p.waitForTimeout(300);
  const ov = await p.evaluate(() => { const t = document.querySelector('.cart-collaterals').getBoundingClientRect(); const sg = document.querySelector('.bk-cart-suggest').getBoundingClientRect(); return { tBottom: Math.round(t.bottom), sTop: Math.round(sg.top), outside: !document.querySelector('.woocommerce .bk-cart-suggest') }; });
  ok(ov.outside && ov.tBottom <= ov.sTop, 'carrito: el resumen no pasa por encima de "Combínalo con" ' + JSON.stringify(ov));
  // Carrito flotante: abre al instante con la última copia aunque el servidor tarde 3 s.
  await p.goto(B + '/preguntas-frecuentes/'); await p.waitForTimeout(1500);
  await p.route('**/wc/store/v1/cart*', async (r) => { await new Promise((res) => setTimeout(res, 3000)); await r.continue().catch(() => {}); });
  const fcb = await p.evaluate(() => { const e = document.querySelector('.bk-fcart'); const cs = getComputedStyle(e); return { bg: cs.backgroundColor, shadow: cs.boxShadow !== 'none' }; });
  ok(fcb.bg === 'rgb(255, 255, 255)' && fcb.shadow, 'botón flotante blanco con sombra');
  const t0 = Date.now(); await p.click('.bk-fcart');
  await p.waitForSelector('.bk-sc .bk-sc__item, .bk-sc [class*="bk-sc__item"]', { timeout: 2500 }).catch(() => {});
  const dt = Date.now() - t0;
  ok(dt < 1500 && await p.locator('.bk-sc.is-open').count() === 1, `carrito flotante muestra los productos al instante (${dt} ms con el servidor tardando 3 s)`);
  await p.unroute('**/wc/store/v1/cart*'); await p.keyboard.press('Escape'); await p.goto(B + '/cart/');
  const ch = await p.evaluate(async () => (await fetch('/?t=' + Date.now(), { credentials: 'include' })).headers.get('x-bk-cache'));
  ok(ch !== 'store', 'con carrito: el inicio no se guarda en caché');
  await p.screenshot({ path: `${OUT}/pg-cart-1440.png`, fullPage: true });
  // Checkout → gracias
  await p.goto(B + '/checkout/'); await p.waitForSelector('form.checkout');
  d = await doc();
  ok(d.h1.length === 1 && d.divi === 0, 'checkout clásico en documento propio');
  ok(await p.locator('.bk-crumbs li').count() === 3, 'checkout: migas Inicio / Carrito / Finalizar compra');
  await p.fill('#billing_first_name', 'Prueba'); await p.fill('#billing_last_name', 'Páginas');
  await p.fill('#billing_address_1', 'Calle 1 # 2-3'); await p.fill('#billing_city', 'Medellín');
  await p.fill('#billing_phone', '3001234567'); await p.fill('#billing_email', 'paginas@ejemplo.com');
  const st = p.locator('#billing_state'); if (await st.count()) await st.selectOption({ index: 1 }).catch(() => {});
  await p.waitForTimeout(1500);
  const consent = p.locator('#bk_ac_consent'); if (await consent.count()) await consent.check().catch(() => {});
  await p.screenshot({ path: `${OUT}/pg-checkout-1440.png`, fullPage: true });
  await p.click('#place_order');
  await p.waitForURL(/order-received/, { timeout: 20000 }).catch(() => {});
  d = await doc();
  ok(/order-received/.test(p.url()) && /Gracias/.test(d.h1[0] || ''), `pedido creado → "${d.h1[0]}"`);
  ok(await p.locator('.bk-promo--thanks').count() === 1, 'gracias: promo de calificar en Google');
  await p.screenshot({ path: `${OUT}/pg-thanks-1440.png`, fullPage: true });

  // Footer: títulos y enlaces visibles (no heredan el azul de la página)
  const fc = await p.evaluate(() => { const f = document.querySelector('.bk-footer'); const h = f.querySelector('h2, h3'); const a = f.querySelector('nav a, li a'); return { bg: getComputedStyle(f).backgroundColor, h: h && getComputedStyle(h).color, a: a && getComputedStyle(a).color }; });
  ok(fc.h !== fc.bg && fc.a !== 'rgb(26, 57, 85)', 'footer: títulos y enlaces legibles ' + JSON.stringify(fc));
  // Mi cuenta, FAQ, política
  const anon = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  anon.on('pageerror', (e) => errs.push(e.message));
  await anon.goto(B + '/my-account/');
  ok(await anon.locator('form.woocommerce-form-login').count() === 1 && (await anon.locator('h1').count()) === 1, 'mi cuenta: formulario de ingreso');
  await anon.setViewportSize({ width: 1440, height: 1800 });
  const fb = await anon.evaluate(() => ({ bottom: Math.round(document.querySelector('.bk-footer').getBoundingClientRect().bottom), vh: innerHeight, html: getComputedStyle(document.documentElement).backgroundColor }));
  ok(fb.bottom >= fb.vh - 1 && fb.html === 'rgb(26, 57, 85)', 'página corta: el footer llega al final, sin franja blanca ' + JSON.stringify(fb));
  await anon.setViewportSize({ width: 1440, height: 900 });
  ok(await anon.locator('.bk-login__aside li').count() === 5 && /Ingresa/.test(await anon.locator('h1').innerText()), 'mi cuenta: acceso con lo que encuentra en su cuenta');
  ok(await anon.evaluate(() => ![...document.scripts].some((s) => /zxcvbn|password-strength/.test(s.src))), 'mi cuenta: sin el medidor de contraseñas de 800 KB');
  await anon.screenshot({ path: `${OUT}/pg-login-1440.png`, fullPage: true });
  await anon.fill('#username', 'clienta'); await anon.fill('#password', 'Clienta#2026'); await anon.click('button[name=login]'); await anon.waitForLoadState('load');
  const acc = await anon.evaluate(() => ({ nav: document.querySelectorAll('.bk-acc-nav li').length, dl: !!document.querySelector('.woocommerce-MyAccount-navigation-link--downloads'), dash: !!document.querySelector('.bk-acc-dash'), last: !!document.querySelector('.bk-acc-last'), cards: document.querySelectorAll('.bk-acc-cards li').length, side: (() => { const n = document.querySelector('.bk-acc-nav').getBoundingClientRect(); const c = document.querySelector('.woocommerce-MyAccount-content').getBoundingClientRect(); return c.left > n.right - 5; })() }));
  ok(acc.dash && acc.last && acc.cards === 4 && !acc.dl && acc.side, 'mi cuenta: panel propio con último pedido y accesos ' + JSON.stringify(acc));
  await anon.screenshot({ path: `${OUT}/pg-account-1440.png`, fullPage: true });
  await anon.goto(B + '/my-account/orders/');
  ok(await anon.locator('.woocommerce-orders-table tbody tr').count() >= 1, 'mi cuenta: pedidos');
  await anon.screenshot({ path: `${OUT}/pg-orders-1440.png`, fullPage: true });
  await anon.goto(B + '/my-account/customer-logout/'); await anon.locator('a[href*="customer-logout"]').first().click().catch(() => {}); await anon.waitForLoadState('load');
  await anon.goto(B + '/preguntas-frecuentes/');
  ok(await anon.locator('details.bk-faq__item').count() >= 3, 'FAQ con preguntas');
  const ld = JSON.parse(await anon.locator('script[type="application/ld+json"]').last().textContent());
  ok(ld['@type'] === 'FAQPage' && ld.mainEntity.length >= 3, 'FAQ con datos estructurados FAQPage');
  await anon.locator('details.bk-faq__item summary').first().click();
  ok(await anon.locator('details.bk-faq__item[open]').count() === 1, 'pregunta se abre');
  ok(await anon.locator('.bk-crumbs').count() === 1, 'FAQ: migas de pan');
  await anon.goto(B + '/politica-empresa/politica-de-envio/');
  ok((await anon.locator('h1').count()) === 1 && await anon.locator('.bk-side-nav a').count() >= 1, 'política con menú de políticas');
  ok(await anon.locator('.bk-crumbs').count() === 1, 'política: migas de pan');
  await anon.goto(B + '/cart/');
  ok(await anon.locator('.bk-cart-empty-links a').count() >= 1, 'carrito vacío: accesos a las líneas');

  // Móvil
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  for (const u of ['/', '/shop/', '/cart/', '/my-account/', '/preguntas-frecuentes/', '/politica-empresa/politica-de-envio/']) {
    await m.goto(B + u);
    ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), `móvil sin scroll horizontal: ${u}`);
  }
  await m.goto(B + '/'); await m.screenshot({ path: `${OUT}/pg-home-390.png`, fullPage: true });
  const lnm = await m.evaluate(() => [...document.querySelectorAll('.bk-lineas > *')].map((e) => Math.round(e.getBoundingClientRect().top)));
  ok(lnm.length === 2 && lnm[0] === lnm[1], 'móvil: las 2 líneas en una fila');
  const annm = await m.evaluate(() => { const vp = document.querySelector('.bk-announce__viewport').getBoundingClientRect(); return { inView: [...document.querySelectorAll('.bk-announce__item')].filter((li) => { const r = li.getBoundingClientRect(); return r.left >= vp.left - 1 && r.right <= vp.right + 1; }).length, badgesHidden: getComputedStyle(document.querySelector('.bk-announce__badges')).display === 'none', inRotation: document.querySelectorAll('.bk-announce__item.is-badge:not(.is-clone)').length, left: Math.round(document.querySelector('.bk-announce__item').getBoundingClientRect().left) }; });
  ok(annm.inView === 1 && annm.badgesHidden && annm.inRotation === 2 && annm.left <= 24, 'móvil: 1 anuncio a la izquierda y los sellos dentro de la rotación ' + JSON.stringify(annm));
  await m.screenshot({ path: `${OUT}/pg-announce-390.png` });
  await m.click('.bk-search-toggle');
  await m.locator('.bk-msearch input[type="search"]').pressSequentially('jean', { delay: 40 });
  await m.waitForSelector('.bk-msearch .bk-sr__item', { timeout: 5000 }).catch(() => {});
  ok(await m.locator('.bk-msearch .bk-sr__item').count() >= 1, 'móvil: buscador muestra productos');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), 'móvil: resultados sin scroll horizontal');
  await m.screenshot({ path: `${OUT}/pg-search-390.png` });
  await m.goto(B + '/cart/'); await m.screenshot({ path: `${OUT}/pg-cart-390.png`, fullPage: true });
  await m.goto(B + '/shop/'); await m.locator('.bk-card a').first().click(); await m.waitForLoadState('load');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), 'móvil sin scroll horizontal: ficha');
  await m.screenshot({ path: `${OUT}/pg-product-390.png`, fullPage: true });

  ok(errs.length === 0, 'sin errores JS ' + [...new Set(errs)].join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
