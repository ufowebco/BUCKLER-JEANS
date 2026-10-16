// 0.18.7: guía de tallas y siluetas en texto (dos botones, dos ventanas), "Encuentra tu talla", siluetas dibujadas con
// "Este modelo" detectado por el nombre, y editor en Gestión → Contenido → Guía de tallas.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const FLARE = B + '/product/jean-bota-campana-flare-dama-tiro-alto/';
const SKIRT = B + '/product/falda-botones-dama-tiro-alto/';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  php('delete_option("bk_size_guide"); bk_cache_purge();');
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));

  // 1. Ficha de un jean: dos botones.
  await p.goto(FLARE, { waitUntil: 'networkidle' });
  const btns = await p.$$eval('.bk-pdp__guides [data-bk-dialog]', (x) => x.map((e) => e.textContent.trim()));
  ok(btns.length === 2 && /Guía de tallas/.test(btns[0]) && /Siluetas/.test(btns[1]), `dos botones independientes: ${btns.join(' | ')}`);
  ok(await p.locator('#bk-size-guide img, #bk-silhouettes img').count() === 0, 'sin imágenes: todo es texto y dibujos');

  // 2. Guía de tallas.
  await p.click('[data-bk-dialog="bk-size-guide"]');
  ok(await p.locator('#bk-size-guide[open]').count() === 1 && await p.evaluate(() => document.activeElement.matches('[data-bk-close]')), 'abre la ventana de tallas con el foco en Cerrar');
  const sel = await p.getAttribute('#bk-size-guide [role="tab"][aria-selected="true"]', 'data-bk-sg-tab');
  ok(sel === 'linea-curvy' && await p.locator('#bk-sg-linea-curvy').isVisible() && await p.locator('#bk-sg-linea-slim').isHidden(), 'abre en la pestaña de la línea del producto (Curvy)');
  const rows = await p.$$eval('#bk-sg-linea-curvy tbody tr', (x) => x.map((r) => [...r.children].map((c) => c.textContent).join(' ').replace(/\s+/g, ' ').trim()));
  ok(rows.length === 6 && rows[1] === '16 89 – 94 cm 117 – 122 cm', `tabla Curvy en texto (${rows.length} tallas: ${rows[1]})`);
  await p.fill('#bk-sg-w', '92'); await p.fill('#bk-sg-h', '120');
  ok(/Tu talla: 16/.test(await p.textContent('[data-bk-sg-out]')) && await p.locator('#bk-sg-linea-curvy tr.is-match').getAttribute('data-size') === '16', 'Encuentra tu talla: cintura 92 y cadera 120 → 16, resaltada en la tabla');
  await p.fill('#bk-sg-w', '70'); await p.fill('#bk-sg-h', '100');
  ok(/Tu talla: 8/.test(await p.textContent('[data-bk-sg-out]')) && await p.locator('#bk-sg-linea-slim').isVisible() && await p.getAttribute('[data-bk-sg-tab="linea-slim"]', 'aria-selected') === 'true', 'medidas de Slim → talla 8 y cambia sola a la pestaña Línea Slim');
  await p.fill('#bk-sg-w', '86'); await p.fill('#bk-sg-h', '113');
  ok(/Tu talla: 14/.test(await p.textContent('[data-bk-sg-out]')) && /Slim o Línea Curvy/.test(await p.textContent('[data-bk-sg-out]')), 'talla 14: existe en las dos líneas');
  await p.fill('#bk-sg-w', '150');
  ok(/WhatsApp/.test(await p.textContent('[data-bk-sg-out]')), 'medida fuera de la tabla: invita a WhatsApp');
  ok(/Cintura/.test(await p.textContent('#bk-size-guide .bk-sg__measure')) && /Índigo Stretch/.test(await p.textContent('#bk-size-guide .bk-sg__note')), 'cómo medirte + nota del Índigo Stretch');
  await p.keyboard.press('Escape');
  ok(await p.locator('#bk-size-guide[open]').count() === 0, 'Esc cierra la ventana');

  // 3. Siluetas.
  await p.click('[data-bk-dialog="bk-silhouettes"]');
  const cards = p.locator('#bk-silhouettes .bk-sil');
  ok(await cards.count() === 11 && await p.locator('#bk-silhouettes .bk-sil svg path').count() >= 22, '11 siluetas, cada una con su dibujo');
  ok(/Bota campana/.test(await cards.first().textContent()) && await cards.first().evaluate((c) => c.classList.contains('is-current')) && /Este modelo/.test(await cards.first().textContent()), '"Este modelo": Flare detectada por el nombre y primera de la lista');
  await p.click('#bk-silhouettes [data-bk-sil-g="ajustado"]');
  ok(await p.locator('#bk-silhouettes .bk-sil:visible').count() === 1 && /Skinny/i.test(await p.locator('#bk-silhouettes .bk-sil:visible').textContent()), 'filtro "Ajustadas"');
  await p.click('#bk-silhouettes [data-bk-sil-g="all"]');
  ok(await p.locator('#bk-silhouettes .bk-sil:visible').count() === 11, 'filtro "Todas"');
  await p.keyboard.press('Escape');

  // 4. Falda: guía de tallas sí, siluetas no.
  await p.goto(SKIRT, { waitUntil: 'networkidle' });
  ok(await p.locator('[data-bk-dialog="bk-size-guide"]').count() === 1 && await p.locator('[data-bk-dialog="bk-silhouettes"]').count() === 1 && await p.locator('#bk-silhouettes .bk-sil.is-current').count() === 0, 'falda: también tiene Siluetas, pero sin "Este modelo" (una "falda recta" no es un jean recto)');

  // 5. Celular: ventana desde abajo, sin desborde.
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  await m.goto(FLARE, { waitUntil: 'networkidle' });
  await m.click('[data-bk-dialog="bk-silhouettes"]'); await m.waitForTimeout(300);
  const box = await m.locator('#bk-silhouettes').boundingBox();
  ok(box && box.width >= 388 && Math.round(box.y + box.height) >= 840, `celular: la ventana sube desde abajo a todo el ancho (${Math.round(box?.width)}×${Math.round(box?.height)})`);
  ok(await m.evaluate(() => document.documentElement.scrollWidth <= 390), 'celular: sin desborde horizontal');

  // 6. Gestión → Contenido → Guía de tallas.
  const g = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#contenido/guia'); await g.waitForSelector('#bk-gcg');
  ok(await g.locator('.bk-gsg').count() === 2 && await g.locator('.bk-gsil__i').count() === 11 && await g.locator('.bk-gsil__art svg').count() === 11, 'editor: 2 tablas (Slim y Curvy) y 11 siluetas con su dibujo');
  await g.locator('.bk-gsg[data-line="linea-curvy"] [data-sgadd]').click();
  const last = g.locator('.bk-gsg[data-line="linea-curvy"] tbody tr').last();
  for (const [i, v] of ['26', '119', '124', '147', '152'].entries()) await last.locator(`[data-c="${i}"]`).fill(v);
  await g.fill('#bk-gcg [name=note]', 'Nota editada desde la gestión.');
  await g.locator('.bk-gsil__i[data-k="barrel"] [data-f="on"]').uncheck();
  await g.locator('.bk-gsil__i[data-k="flare"] [data-f="es"]').fill('Jean campana');
  await g.click('#bk-gcg [type=submit]'); await g.waitForTimeout(1500);
  const opt = JSON.parse(php('echo json_encode(get_option("bk_size_guide"));'));
  ok(opt.tables['linea-curvy'].length === 7 && opt.note === 'Nota editada desde la gestión.' && opt.sil.find((x) => x.k === 'barrel').off === true, 'guardar: talla nueva, nota y silueta oculta');
  await p.goto(FLARE + '?v=' + Date.now(), { waitUntil: 'networkidle' });
  ok(await p.locator('#bk-sg-linea-curvy tbody tr').count() === 7 && /Nota editada/.test(await p.textContent('#bk-size-guide')) && await p.locator('#bk-silhouettes .bk-sil').count() === 10 && /Jean campana/.test(await p.textContent('#bk-silhouettes .bk-sil.is-current')), 'la ficha muestra los cambios de la gestión');
  // Validación: "hasta" menor que "desde".
  await last.locator('[data-c="2"]').fill('100');
  await g.click('#bk-gcg [type=submit]'); await g.waitForTimeout(1200);
  ok(php('echo json_encode(get_option("bk_size_guide"));').includes('"124"') || php('echo get_option("bk_size_guide")["tables"]["linea-curvy"][6][2];') === '124', 'medida inválida: no se guarda (se avisa a la gestora)');
  await g.click('[data-sgreset]'); await g.click('[data-sgreset]'); await g.waitForTimeout(1500);
  ok(php('echo get_option("bk_size_guide") === false ? "x" : "y";') === 'x' && await g.locator('.bk-gsg[data-line="linea-curvy"] tbody tr').count() === 6, 'restaurar valores originales (con doble toque)');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php('delete_option("bk_size_guide"); bk_cache_purge();');
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
