// Editor de productos del dashboard: crear, subir fotos/video, editar, versión en la otra línea, ficha pública.
// Antes: php seed-editor.php (categorías reales de Línea nueva / Sale y limpieza de pruebas anteriores).
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const B = 'http://localhost:8899';
const FX = '/tmp/claude-0/bk-local/fixtures';
const OUT = '/tmp/claude-0/bk-local';
const db = (sku) => JSON.parse(execFileSync('php', [`${__dirname}/inspect-product.php`, sku]).toString());
const login = async (p, u, pw) => { await p.goto(B + '/wp-login.php?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/')); await p.fill('#user_login', u); await p.fill('#user_pass', pw); await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]); };
const toastText = (p) => p.textContent('#bk-g-toast');

(async () => {
  execFileSync('php', [`${__dirname}/seed-editor.php`]);
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/status of 400/.test(m.text())) errs.push(m.text()); });
  await login(p, 'gestora', 'gestora');

  // Crear
  await p.click('[data-tab="productos"]'); await p.waitForSelector('.bk-g-prod');
  ok(await p.locator('.bk-g-prod a[href^="#productos/editar/"]').count() > 0, 'lista con enlace "Editar" por producto');
  await p.click('a[href="#productos/nuevo"]'); await p.waitForSelector('#bk-gp');
  ok(await p.textContent('h1') === 'Nuevo producto', 'formulario "Nuevo producto"');
  await p.click('#bk-gp [data-status="publish"]');
  ok(/nombre/.test(await p.textContent('.bk-g-formerr')) && await p.evaluate(() => document.activeElement.name === 'name'), 'valida nombre y lleva el foco al campo');
  await p.fill('[name="name"]', 'JEAN PRUEBA DAMA – RECTO – TIRO ALTO');
  await p.fill('[name="sku"]', 'TEST-901');
  await p.click('#bk-gp [data-status="publish"]');
  ok(/línea/.test(await p.textContent('.bk-g-formerr')), 'valida línea');
  await p.check('[name="line"][value="linea-slim"]');
  const sizes = await p.locator('[name="size_on"]').evaluateAll(els => els.map(e => e.value + (e.checked ? '+' : '-')));
  ok(sizes.join() === '6+,8+,10+,12+', 'tallas de Slim (6–12) activas: ' + sizes.join());
  ok(await p.locator('.bk-gp-chip').count() >= 3, 'prendas de la línea Slim');
  await p.locator('.bk-gp-chip', { hasText: 'Jeans' }).click();
  await p.uncheck('[name="size_on"][value="12"]');
  await p.fill('[name="stock_6"]', '3'); await p.fill('[name="stock_8"]', '5'); await p.fill('[name="stock_10"]', '0');
  await p.fill('[name="regular"]', '129900'); await p.fill('[name="sale"]', '99.900');
  ok(/23 % de descuento/.test(await p.textContent('#bk-gp-disc')), 'calcula el descuento: ' + await p.textContent('#bk-gp-disc'));
  await p.fill('[name="sale"]', '150000'); await p.click('#bk-gp [data-status="publish"]');
  ok(/menor/.test(await p.textContent('.bk-g-formerr')), 'valida rebaja menor que el precio');
  await p.fill('[name="sale"]', '99900');
  await p.click('#bk-gp [data-status="publish"]');
  ok(/foto/.test(await p.textContent('.bk-g-formerr')), 'publicar exige al menos una foto');

  // Fotos y video
  await p.setInputFiles('[name="photos"]', `${FX}/malo.txt`); await p.waitForTimeout(300);
  ok(/no son fotos/.test(await toastText(p)), 'rechaza archivos que no son fotos');
  const nonce = await p.evaluate(() => window.bkG.nonce);
  const bad = await p.request.post(`${B}/wp-json/bk/v1/gestion/media`, { headers: { 'X-WP-Nonce': nonce }, multipart: { file: { name: 'x.php', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1;') } } });
  ok(bad.status() === 400, 'la API rechaza un .php disfrazado de foto');
  await p.setInputFiles('[name="photos"]', [`${FX}/foto1.jpg`, `${FX}/foto2.jpg`, `${FX}/foto3.jpg`]);
  await p.waitForFunction(() => document.querySelectorAll('.bk-gp-img img').length === 3 && !document.querySelector('.bk-gp-img.is-loading'), null, { timeout: 30000 });
  ok(true, 'la gestora (sin permiso de Mediateca) sube 3 fotos');
  const ids0 = await p.$$eval('.bk-gp-img img', els => els.map(e => e.src));
  await p.click('[data-img-move="2"][data-dir="-1"]');
  const ids1 = await p.$$eval('.bk-gp-img img', els => els.map(e => e.src));
  ok(ids1[1] === ids0[2] && ids1[2] === ids0[1], 'reordenar fotos');
  await p.setInputFiles('[name="photos"]', `${FX}/grande.jpg`);
  await p.waitForFunction(() => document.querySelectorAll('.bk-gp-img img').length === 4 && !document.querySelector('.bk-gp-img.is-loading'), null, { timeout: 60000 });
  ok(true, 'foto de 11 MB (4000×5300) se reduce en el navegador y sube aunque el servidor permita 2 MB');
  await p.click('[data-img-del="3"]');
  await p.click('[data-img-del="1"]');
  ok(await p.locator('.bk-gp-img img').count() === 2, 'quitar una foto');
  ok(await p.locator('.bk-gp-main').count() === 1, 'marca la foto principal');
  await p.setInputFiles('[name="video"]', `${FX}/video.mp4`);
  await p.waitForSelector('.bk-gp-video video', { timeout: 30000 });
  ok(true, 'sube el video MP4');

  // Descripción
  await p.fill('[name="short"]', 'Jean recto de tiro alto, cómodo para todo el día.');
  await p.click('#bk-gp-desc'); await p.keyboard.type('Tela denim licrada. ');
  await p.click('[data-cmd="bold"]'); await p.keyboard.type('Hecho en Medellín');
  await p.click('#bk-gp [data-status="publish"]');
  await p.waitForFunction(() => /#productos\/editar\/\d+/.test(location.hash), null, { timeout: 15000 });
  await p.waitForSelector('#bk-gp');
  ok(/publicado/.test(await toastText(p)), 'producto publicado → pasa a edición');
  let d = db('TEST-901');
  ok(d.type === 'variable' && d.status === 'publish', 'variable y publicado');
  ok(d.attr.join() === 'pa_tallas-regular', 'atributo de tallas de Slim');
  ok(['linea-slim', 'jeans-linea-slim', 'linea-nueva', 'slim', 'sale', 'jeans-sale'].every(c => d.cats.includes(c)), 'categorías: línea, prenda, Nuevo y Sale → ' + d.cats.join());
  ok(d.featured, 'destacado (etiqueta Nuevo)');
  const v = Object.fromEntries(d.vars.map(x => [x.size, x]));
  ok(d.vars.length === 3 && v['6'].stock === 3 && v['8'].stock === 5 && v['10'].stock === 0 && !v['12'], 'variaciones 6/8/10 con su stock, sin 12');
  ok(d.vars.every(x => x.regular === '129900' && x.sale === '99900'), 'precio y rebaja en todas las tallas');
  ok(d.images.length === 3 && d.images[2].mime === 'video/mp4' && d.video === d.images[2].id, 'video al final de la galería (formato BodyCommerce) + _bk_video_id');
  ok(d.images[0].alt === d.name && /foto 2/.test(d.images[1].alt), 'alt automático: ' + d.images.slice(0, 2).map(i => i.alt).join(' | '));
  ok(d.images.every(i => i.parent === d.id), 'fotos y video adjuntos al producto');
  ok(d.images[0].sizes.length && d.images[0].sizes.every(m => m === 'image/webp'), 'tamaños generados en WebP: ' + d.images[0].sizes.join());
  ok(/<strong>Hecho en Medellín<\/strong>/.test(d.desc) && !/data-|style=/.test(d.desc), 'descripción con negrita, sin atributos: ' + d.desc);

  // Ficha pública: galería sin el MP4 como foto + video del módulo
  const pub = await (await b.newContext()).newPage();
  await pub.goto(d.url);
  // Con "Páginas limpias" la galería es propia (.bk-pg); sin el módulo, la de WooCommerce.
  const own = await pub.locator('.bk-pg').count() > 0;
  const galleryImgs = await pub.$$eval(own ? '.bk-pg__slide img' : '.woocommerce-product-gallery__image img', els => els.map(e => e.getAttribute('src') || ''));
  ok(galleryImgs.length === 2 && galleryImgs.every(s => /\.(webp|jpe?g)/.test(s)), 'ficha: 2 fotos en la galería (el MP4 no sale como foto)');
  ok(await pub.locator(own ? '.bk-pg__slide video source[src$=".mp4"]' : '.bk-gallery-video video source[src$=".mp4"]').count() === 1, 'ficha: el video se pinta con buckler-core');
  ok((await pub.textContent('body')).includes('JEAN PRUEBA DAMA'), 'ficha publicada visible');

  // Editar
  await p.uncheck('[name="size_on"][value="10"]');
  await p.check('[name="size_on"][value="12"]'); await p.fill('[name="stock_12"]', '2');
  await p.fill('[name="sale"]', '');
  await p.uncheck('[name="is_new"]');
  await p.fill('[name="name"]', 'JEAN PRUEBA DAMA – RECTO – TIRO ALTO');
  await p.click('#bk-gp [type="submit"]'); await p.waitForTimeout(1500);
  ok(/guardados/.test(await toastText(p)), 'guardar cambios');
  d = db('TEST-901');
  const v2 = Object.fromEntries(d.vars.map(x => [x.size, x]));
  ok(v2['10'].status === 'private' && v2['12'].status === 'publish' && v2['12'].stock === 2, 'talla desactivada queda privada (no se borra) y talla nueva creada');
  ok(!d.cats.includes('sale') && !d.cats.includes('jeans-sale'), 'sin rebaja → sale de ¡Sale!');
  ok(!d.featured && !d.cats.includes('linea-nueva') && !d.cats.includes('slim'), 'sin Nuevo → sale de Línea nueva: ' + d.featured + ' ' + d.cats.join());
  ok(d.vars.filter(x => x.status === 'publish').every(x => x.sale === ''), 'rebaja quitada en todas las tallas');
  ok(d.video && d.images.length === 3, 'el video se conserva al editar');

  // Estrella de la lista = Nuevo con categorías
  await p.goto(B + '/gestion-tienda/#productos'); await p.waitForSelector('.bk-g-prod');
  await p.fill('#bk-g-pf input[name="q"]', 'TEST-901'); await p.waitForTimeout(1200);
  await p.locator(`[data-feat="${d.id}"]`).click(); await p.waitForTimeout(1000);
  d = db('TEST-901');
  ok(d.featured && d.cats.includes('linea-nueva') && d.cats.includes('slim'), 'estrella "Nuevo" también asigna Línea nueva');

  // REF duplicada
  await p.goto(B + '/gestion-tienda/#productos/nuevo'); await p.waitForSelector('#bk-gp');
  await p.fill('[name="name"]', 'OTRO'); await p.fill('[name="sku"]', 'TEST-901'); await p.check('[name="line"][value="linea-slim"]');
  await p.fill('[name="regular"]', '100000');
  await p.click('#bk-gp [data-status="draft"]'); await p.waitForTimeout(1000);
  ok(/ya la usa/.test(await p.textContent('.bk-g-formerr')) && await p.evaluate(() => document.activeElement.name === 'sku'), 'REF duplicada: error del servidor en el campo REF');

  // Cambios sin guardar
  let dialog = '';
  p.once('dialog', async (dg) => { dialog = dg.message(); await dg.dismiss(); });
  await p.click('.bk-g-back'); await p.waitForTimeout(300);
  ok(/sin guardar/.test(dialog) && /nuevo/.test(await p.evaluate(() => location.hash)), 'avisa de cambios sin guardar al salir');

  // Mismo modelo en la otra línea
  await p.evaluate(() => { location.hash = '#productos'; });
  p.once('dialog', (dg) => dg.accept());
  await p.goto(`${B}/gestion-tienda/#productos/editar/${d.id}`); await p.waitForSelector('#bk-gp');
  await p.click('a[href^="#productos/nuevo?desde="]'); await p.waitForSelector('.bk-gp-note');
  ok(await p.inputValue('[name="sku"]') === 'TEST-901 PLUS', 'versión Curvy: REF con PLUS');
  ok(await p.inputValue('[name="name"]') === 'JEAN PRUEBA DAMA – RECTO – TIRO ALTO', 'versión Curvy: mismo nombre');
  ok(await p.isChecked('[name="line"][value="linea-curvy"]'), 'versión Curvy: línea Curvy');
  const twinCats = await p.$$eval('[name="cats"]:checked', els => els.map(e => e.closest('label').textContent.trim()));
  ok(twinCats.join() === 'Jeans', 'versión Curvy: prenda equivalente (Jeans)');
  ok((await p.locator('[name="size_on"]').evaluateAll(els => els.map(e => e.value))).join() === '14,16,18,20,22,24', 'versión Curvy: tallas 14–24');
  ok(await p.locator('.bk-gp-img img').count() === 0, 'versión Curvy: sin fotos (son otras fotos)');
  await p.fill('[name="stock_14"]', '4');
  await p.click('#bk-gp [data-status="draft"]');
  await p.waitForFunction(() => /#productos\/editar\/\d+/.test(location.hash), null, { timeout: 15000 });
  const t = db('TEST-901 PLUS');
  ok(t && t.status === 'draft' && t.attr.join() === 'pa_tallas-plus-size' && t.cats.includes('jeans') && t.cats.includes('linea-curvy'), 'versión Curvy guardada como borrador: ' + (t && t.cats.join()));

  // Producto real existente: abre y guarda sin perder nada
  const real = db('9897');
  await p.goto(`${B}/gestion-tienda/#productos/editar/${real.id}`); await p.waitForSelector('#bk-gp');
  ok(await p.locator('[name="size_on"]:checked').count() > 0, 'producto existente: tallas cargadas');
  await p.click('#bk-gp [type="submit"]'); await p.waitForTimeout(1500);
  const real2 = db('9897');
  ok(JSON.stringify(real2.vars) === JSON.stringify(real.vars) && real2.price === real.price && real2.cats.sort().join() === real.cats.sort().join(), 'guardar sin cambios no altera tallas, precios ni categorías');

  await p.screenshot({ path: `${OUT}/prod-edit.png`, fullPage: true });

  // Móvil
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', e => errs.push(e.message));
  await m.goto(B + '/gestion-tienda/#productos/nuevo'); await m.waitForSelector('#bk-gp');
  await m.check('[name="line"][value="linea-curvy"]');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'móvil sin scroll horizontal');
  await m.screenshot({ path: `${OUT}/prod-mobile.png`, fullPage: true });
  await m.goto(B + '/gestion-tienda/#productos'); await m.waitForSelector('.bk-g-prod');
  ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'móvil: lista sin scroll horizontal');

  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
