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
  await p.screenshot({ path: `${OUT}/pg-home-1440.png`, fullPage: true });

  // Ficha
  await p.goto(B + '/shop/?linea=slim'); await p.locator('.bk-card a').first().click(); await p.waitForLoadState('load');
  d = await doc();
  ok(d.h1.length === 1 && /Línea Slim/.test(d.h1[0]), `ficha: un H1 con la línea "${d.h1[0]}"`);
  ok(await p.locator('.bk-pg__slide').count() >= 1, `galería propia (${await p.locator('.bk-pg__slide').count()} diapositivas)`);
  ok(await p.locator('.bk-crumbs').count() === 1, 'migas de pan');
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
  await p.screenshot({ path: `${OUT}/pg-cart-1440.png`, fullPage: true });
  // Checkout → gracias
  await p.goto(B + '/checkout/'); await p.waitForSelector('form.checkout');
  d = await doc();
  ok(d.h1.length === 1 && d.divi === 0, 'checkout clásico en documento propio');
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
  await anon.goto(B + '/politica-empresa/politica-de-envio/');
  ok((await anon.locator('h1').count()) === 1 && await anon.locator('.bk-side-nav a').count() >= 1, 'política con menú de políticas');

  // Móvil
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  for (const u of ['/', '/shop/', '/cart/', '/my-account/', '/preguntas-frecuentes/', '/politica-empresa/politica-de-envio/']) {
    await m.goto(B + u);
    ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), `móvil sin scroll horizontal: ${u}`);
  }
  await m.goto(B + '/'); await m.screenshot({ path: `${OUT}/pg-home-390.png`, fullPage: true });
  await m.goto(B + '/shop/'); await m.locator('.bk-card a').first().click(); await m.waitForLoadState('load');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), 'móvil sin scroll horizontal: ficha');
  await m.screenshot({ path: `${OUT}/pg-product-390.png`, fullPage: true });

  ok(errs.length === 0, 'sin errores JS ' + [...new Set(errs)].join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
