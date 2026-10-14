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
  ok(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('bk-qty-btn')), 'carrito: el foco vuelve al botón tras actualizar');
  ok(await p.locator('.cart_item .bk-qty-btn').count() >= 2, 'carrito: botones siguen tras actualizar');
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
  await p.screenshot({ path: `${OUT}/pg-thanks-1440.png`, fullPage: true });

  // Footer: títulos y enlaces visibles (no heredan el azul de la página)
  const fc = await p.evaluate(() => { const f = document.querySelector('.bk-footer'); const h = f.querySelector('h2, h3'); const a = f.querySelector('nav a, li a'); return { bg: getComputedStyle(f).backgroundColor, h: h && getComputedStyle(h).color, a: a && getComputedStyle(a).color }; });
  ok(fc.h !== fc.bg && fc.a !== 'rgb(26, 57, 85)', 'footer: títulos y enlaces legibles ' + JSON.stringify(fc));
  // Mi cuenta, FAQ, política
  const anon = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  anon.on('pageerror', (e) => errs.push(e.message));
  await anon.goto(B + '/my-account/');
  ok(await anon.locator('form.woocommerce-form-login').count() === 1 && (await anon.locator('h1').count()) === 1, 'mi cuenta: formulario de ingreso');
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
