// 0.18.22: arreglos de la revisión de clics (Clarity): visor de fotos a pantalla completa con zoom en la ficha,
// banners del inicio sin enlace → "Lo nuevo", y áreas de toque de 44 px.
const { chromium, devices } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const FLARE = B + '/product/jean-bota-campana-flare-dama-tiro-alto/';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  php('bk_cache_purge();');
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));

  // 1. Banners sin enlace en ACF → Lo nuevo.
  await p.goto(B + '/', { waitUntil: 'networkidle' });
  const slides = await p.$$eval('.bk-hero .bk-carousel__slide', (x) => x.map((s) => (s.querySelector('a.bk-banner__link') || {}).href || ''));
  const noLink = php('echo get_option("options_banners_pagina_inicio_0_enlace") === "" ? "1" : "0";');
  ok(slides.length >= 1 && slides.every(Boolean), `todos los banners llevan a algún lado (${slides.map((h) => h.replace(B, '')).join(', ')})`);
  ok(noLink !== '1' || /\/tienda\/\?nuevo=1$/.test(slides[0]), 'el banner sin enlace en ACF lleva a "Lo nuevo"');

  // 2. Visor de fotos en computador.
  await p.goto(FLARE, { waitUntil: 'networkidle' });
  const n = await p.locator('.bk-pg__slide[data-bk-full]').count();
  ok(n >= 2 && await p.locator('[data-bk-zoom]').isVisible(), `ficha: botón "Ampliar foto" visible (${n} fotos)`);
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-pg__slide img')).cursor) === 'zoom-in', 'la foto muestra la lupa al pasar el cursor');
  await p.locator('.bk-pg__slide img').first().click();
  await p.waitForSelector('dialog.bk-lb[open]');
  ok(/1 de \d+/.test(await p.textContent('.bk-lb__count')), `tocar la foto abre el visor a pantalla completa (${await p.textContent('.bk-lb__count')})`);
  const box = await p.locator('dialog.bk-lb').boundingBox();
  ok(box.width >= 1270 && box.height >= 890, 'el visor ocupa toda la pantalla');
  await p.waitForFunction(() => { const i = document.querySelector('.bk-lb__slide img'); return i && i.complete && i.naturalWidth > 0; });
  ok(await p.evaluate(() => !document.querySelector('.bk-lb__slide img').src.includes('-600x')), 'carga la foto en tamaño grande');
  // Producción: fotos de 1200×1800. Se reemplaza por una grande y debe caber entera en la pantalla.
  const fit = await p.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 1200; c.height = 1800; c.getContext('2d').fillRect(0, 0, 1200, 1800);
    const img = document.querySelector('.bk-lb__slide img'); img.removeAttribute('srcset'); img.src = c.toDataURL('image/jpeg', 0.5);
    await new Promise((r) => { if (img.complete) r(); else img.onload = r; });
    const b = img.getBoundingClientRect(), sl = img.parentElement.getBoundingClientRect();
    const scale = Math.min(b.width / img.naturalWidth, b.height / img.naturalHeight); // object-fit: contain
    return { painted: [Math.round(img.naturalWidth * scale), Math.round(img.naturalHeight * scale)], box: [Math.round(b.width), Math.round(b.height)], slide: [Math.round(sl.width), Math.round(sl.height)], fit: getComputedStyle(img).objectFit };
  });
  ok(fit.fit === 'contain' && fit.box[0] <= fit.slide[0] && fit.box[1] <= fit.slide[1] && fit.painted[1] <= fit.slide[1], `una foto grande (1200×1800) se ajusta a la pantalla al abrir (${fit.painted.join('×')} en ${fit.slide.join('×')})`);
  await p.keyboard.press('ArrowRight'); await p.waitForTimeout(600);
  ok(/^2 de/.test(await p.textContent('.bk-lb__count')), 'flecha derecha → foto 2');
  const img2 = p.locator('.bk-lb__slide').nth(1).locator('img');
  await p.waitForFunction(() => { const i = document.querySelectorAll('.bk-lb__slide img')[1]; return i && i.complete && i.naturalWidth > 0; });
  await img2.click();
  ok(await p.locator('.bk-lb__slide.is-zoom').count() === 1 && (await img2.boundingBox()).height > 900, 'tocar la foto la acerca');
  await img2.click({ position: { x: 20, y: 20 } }).catch(() => {});
  ok(await p.locator('.bk-lb__slide.is-zoom').count() === 0, 'volver a tocarla la aleja');
  await p.keyboard.press('Escape'); await p.waitForTimeout(400);
  ok(await p.locator('dialog.bk-lb[open]').count() === 0 && p.url() === FLARE, 'Esc cierra y la clienta sigue en el producto');
  ok(await p.evaluate(() => Math.round(document.querySelector('.bk-pg__track').scrollLeft / document.querySelector('.bk-pg__track').clientWidth)) === 1, 'al cerrar, la galería queda en la foto que estaba viendo');
  await p.click('[data-bk-zoom]');
  ok(await p.locator('dialog.bk-lb[open]').count() === 1, 'el botón "Ampliar foto" también abre el visor (teclado)');
  await p.click('.bk-lb__close');
  ok(await p.locator('dialog.bk-lb[open]').count() === 0, 'el botón × cierra');

  // 3. Celular: tocar, deslizar y el botón Atrás cierra el visor sin salir del producto.
  const m = await (await b.newContext({ ...devices['iPhone 13'] })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  await m.goto(FLARE, { waitUntil: 'networkidle' });
  await m.locator('.bk-pg__slide img').first().tap();
  await m.waitForSelector('dialog.bk-lb[open]');
  ok(await m.isVisible('.bk-lb__hint') && /Toca la foto para acercar/.test(await m.textContent('.bk-lb__hint')), 'celular: abre con la ayuda "Toca la foto para acercar"');
  await m.evaluate(() => { const t = document.querySelector('.bk-lb__track'); t.scrollTo({ left: t.clientWidth, behavior: 'auto' }); }); await m.waitForTimeout(400);
  ok(/^2 de/.test(await m.textContent('.bk-lb__count')), 'celular: deslizar pasa a la foto 2');
  await m.goBack(); await m.waitForTimeout(600);
  ok(await m.locator('dialog.bk-lb[open]').count() === 0 && m.url() === FLARE, 'celular: el botón Atrás cierra el visor y no sale del producto (evita un quickback)');
  ok(await m.evaluate(() => document.documentElement.scrollWidth <= 390), 'celular: sin desborde');

  // 4. Áreas de toque de 44 px.
  const twin = await p.evaluate(() => { const a = document.querySelector('.bk-pdp__twin'); return a ? a.getBoundingClientRect().height : 44; });
  await p.goto(FLARE, { waitUntil: 'networkidle' });
  const tw = await p.evaluate(() => { const a = document.querySelector('.bk-pdp__twin'); return a ? Math.round(a.getBoundingClientRect().height) : null; });
  ok(tw === null || tw >= 44, `"Ver este modelo en la otra línea": ${tw} px de alto`);
  const g = await (await b.newContext()).newPage();
  await g.goto(B + '/my-account/', { waitUntil: 'networkidle' }).catch(() => {});
  if (!/my-account|mi-cuenta/.test(g.url())) await g.goto(B + '/mi-cuenta/', { waitUntil: 'networkidle' });
  const sizes = await g.evaluate(() => [...document.querySelectorAll('.show-password-input, .woocommerce-form__label-for-checkbox, .bk-login__aside a:not(.bk-btn)')].map((e) => [e.className || e.tagName, Math.round(e.getBoundingClientRect().width), Math.round(e.getBoundingClientRect().height)]));
  ok(sizes.length > 0 && sizes.every(([, w, h]) => h >= 44 && w >= 44), `Mi cuenta: mostrar contraseña, recordarme y enlaces del costado ≥ 44 px (${sizes.map((s) => s[1] + '×' + s[2]).join(', ')})`);

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
