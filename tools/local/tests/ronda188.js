// 0.18.8: descripción larga "inteligente" con íconos (textos reales de producción en fixtures/descripciones.json)
// y accesos a /gestion-tienda/ desde WordPress (menú lateral, barra superior, Escritorio).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const b64 = (s) => Buffer.from(s).toString('base64');
const DESC = JSON.parse(fs.readFileSync(__dirname + '/fixtures/descripciones.json', 'utf8'));

(async () => {
  const keep = { 22: php('echo base64_encode(wc_get_product(22)->get_description());'), 10: php('echo base64_encode(wc_get_product(10)->get_description());') };
  const setDesc = (id, html) => php(`$p=wc_get_product(${id}); $p->set_description(base64_decode("${b64(html)}")); $p->save(); bk_cache_purge();`);
  setDesc(22, DESC.pegado); // Texto pegado desde otro editor (<br data-start=…>), como en producción.
  setDesc(10, '<p>Jean cómodo para todos los días.</p><p>Lávalo al revés y con agua fría.</p>');
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));

  // 1. Descripción con formato de la tienda → íconos.
  await p.goto(B + '/product/jean-bota-campana-flare-dama-tiro-alto/', { waitUntil: 'networkidle' });
  const items = await p.$$eval('.bk-desc__item', (x) => x.map((li) => ({ icon: (li.querySelector('svg').getAttribute('class') || '').replace(/.*bk-icon--/, ''), text: li.textContent.trim() })));
  ok(items.length === 8, `8 detalles con ícono (${items.length})`);
  const icon = (re) => (items.find((i) => re.test(i.text)) || {}).icon;
  ok(icon(/^Tiro alto/) === 'lucide-ruler' && icon(/^Tela denim/) === 'lucide-layers' && icon(/^Lavado/) === 'lucide-palette' && icon(/bolsillos/) === 'lucide-wallet' && icon(/^Ruedo/) === 'lucide-scissors' && icon(/^Silueta/) === 'lucide-sparkles', 'cada detalle con el ícono que corresponde (tiro, tela, lavado, bolsillos, ruedo, silueta)');
  ok(icon(/^Origen/) === 'flag-co-4x3' && await p.locator('.bk-desc__item.is-origin strong').textContent() === 'Origen:', 'origen con la bandera de Colombia');
  ok(await p.locator('.bk-desc__item strong').first().textContent() === 'Silueta ajustada', 'el inicio de cada detalle va en negrita ("Silueta ajustada" que realza…)');
  ok(!/✅|📏|📌|🚚|✨/.test(await p.textContent('.bk-desc')), 'sin emojis sueltos (se reemplazan por íconos)');
  const models = await p.$$eval('.bk-desc__models li', (x) => x.map((l) => l.textContent.trim()));
  ok(models.length === 2 && /Modelo estándar\s*Mide 1,60 m · usa talla 8/.test(models[0]) && /plus size\s*Mide 1,65 m · usa talla 14/.test(models[1]), `modelos en fotos: ${models.join(' | ')}`);
  ok(/Nota:/.test(await p.textContent('.bk-desc__note')) && /¡Envíos a toda Colombia!/.test(await p.textContent('.bk-desc__ship strong')), 'nota y envíos en sus recuadros');
  ok(/Detalles que lo hacen único/.test(await p.textContent('.bk-desc__title')), 'título de la sección tomado del texto');

  // 2. Descripción sin ese formato → igual que antes.
  await p.goto(B + '/product/jean-bota-ancha-dama-oxford-tiro-alto/', { waitUntil: 'networkidle' }).catch(() => {});
  const plainUrl = php('echo get_permalink(10);');
  await p.goto(plainUrl, { waitUntil: 'networkidle' });
  ok(await p.locator('.bk-desc').count() === 0 && /Lávalo al revés/.test(await p.textContent('.bk-pdp__desc .bk-prose')), 'texto libre: se muestra tal cual');

  // 3. Celular: los detalles en una columna, sin desborde.
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  await m.goto(B + '/product/jean-bota-campana-flare-dama-tiro-alto/', { waitUntil: 'networkidle' });
  const cols = await m.$$eval('.bk-desc__item', (x) => new Set(x.map((e) => Math.round(e.getBoundingClientRect().left))).size);
  ok(cols === 1 && await m.evaluate(() => document.documentElement.scrollWidth <= 390), 'celular: una columna y sin desborde');

  // 4. WordPress → Gestión tienda.
  const a = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  a.on('pageerror', (e) => errs.push(e.message));
  await a.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/wp-admin/'));
  await a.fill('[name=log]', 'admin'); await a.fill('[name=pwd]', 'admin');
  await Promise.all([a.waitForNavigation(), a.click('.bk-g-login__form [type=submit]')]);
  await a.goto(B + '/wp-admin/', { waitUntil: 'domcontentloaded' });
  const first = await a.$eval('#adminmenu > li.menu-top a', (x) => ({ href: x.getAttribute('href'), text: x.textContent.trim() }));
  ok(/gestion-tienda/.test(first.href) && /Gestión tienda/.test(first.text), `primer ítem del menú lateral: ${first.text}`);
  const bar = a.locator('#wp-admin-bar-bk-gestion > a');
  ok(await bar.count() === 1 && /Ir a Gestión tienda/.test(await bar.textContent()) && /gestion-tienda/.test(await bar.getAttribute('href')), 'botón "Ir a Gestión tienda" en la barra superior');
  ok(await a.evaluate(() => getComputedStyle(document.querySelector('#wp-admin-bar-bk-gestion > a')).backgroundColor) === 'rgb(228, 236, 106)', 'el botón de la barra va en lima');
  ok(await a.locator('#bk_gestion_widget .bk-gl-hero').count() === 1, 'tarjeta en el Escritorio con el botón');
  await Promise.all([a.waitForNavigation(), bar.click()]);
  ok(/gestion-tienda/.test(a.url()) && await a.locator('.bk-g-main, .bk-g-app, main').count() >= 1, 'el botón abre la gestión');
  await a.goto(B + '/', { waitUntil: 'domcontentloaded' });
  ok(await a.locator('#wp-admin-bar-bk-gestion').count() === 1, 'también en la tienda con sesión (barra superior)');

  // Clienta: no lo ve.
  const c = await (await b.newContext()).newPage();
  await c.goto(B + '/ingreso/'); await c.fill('[name=log]', 'clienta'); await c.fill('[name=pwd]', 'Clienta#2026');
  await Promise.all([c.waitForNavigation(), c.click('.bk-g-login__form [type=submit]')]);
  await c.goto(B + '/', { waitUntil: 'domcontentloaded' });
  ok(await c.locator('#wp-admin-bar-bk-gestion, #bk-gl').count() === 0, 'una clienta no ve el botón');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`foreach(${JSON.stringify(JSON.stringify(keep))} ? json_decode(${JSON.stringify(JSON.stringify(keep))}, true) : [] as $id=>$d){ $p=wc_get_product($id); $p->set_description(base64_decode($d)); $p->save(); } bk_cache_purge();`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
