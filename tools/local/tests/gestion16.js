// Gestión de tienda 0.16: pedidos (sin consecutivo, pago confirmado, imprimir), historial, archivos ACF,
// categorías, saltos de línea, Ajustes oculto, Carritos, Correos, SEO y ADS (visitas, etiquetas, rastreo), velocidad.
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const B = 'http://localhost:8899';
const WP = '/tmp/claude-0/bk-local/site/wp-load.php';
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "${WP}"; ${code}'`).toString();
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
const mails = () => fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter(f => f.endsWith('.json')) : [];
const newMails = (before) => mails().filter(f => !before.includes(f)).map(f => ({ f, ...JSON.parse(fs.readFileSync(`${MAILS}/${f}`, 'utf8')) }));
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const login = async (p, u, pw) => { await p.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/')); await p.fill('[name=log]', u); await p.fill('[name=pwd]', pw); await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]); };

(async () => {
  const b = await chromium.launch({ args: ['--disable-blink-features=AutomationControlled'] });
  const errs = [];
  const watch = (pg) => { pg.on('pageerror', e => errs.push(e.message)); pg.on('console', m => { if (m.type() === 'error' && !/status of (400|404)|ERR_TUNNEL|gravatar/.test(m.text())) errs.push(m.text()); }); };

  // Datos: pedido 464 con historial de notas (antes del historial propio) y descripción con saltos de línea.
  php(`delete_transient("bk_g_summary"); $o=wc_get_order(464); $o->delete_meta_data("_bk_history"); $o->delete_meta_data("_bk_pago_confirmado"); $o->save();`);
  const pid = php(`$p=wc_get_products(["limit"=>1,"type"=>"variable","status"=>"publish","orderby"=>"ID","order"=>"ASC"])[0]; $p->set_description("Detalles:\\nTiro alto\\nBota recta\\n\\nLavado suave."); $p->save(); echo $p->get_id();`).trim();

  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage(); watch(p);
  await login(p, 'gestora', 'gestora');
  await p.waitForSelector('.bk-g-kpi');
  const nonce = await p.evaluate(() => bkG.nonce);
  const get = async (path) => (await p.request.get(`${B}/wp-json/bk/v1/gestion/${path}`, { headers: { 'X-WP-Nonce': nonce } }));

  // --- Velocidad -------------------------------------------------------------------------------
  const scripts = await p.evaluate(() => [...document.scripts].map(s => s.src).filter(Boolean));
  ok(scripts.length === 1 && /gestion\.js/.test(scripts[0]), 'al abrir solo carga gestion.js (editor y módulos bajo demanda)');
  await p.reload(); await p.waitForSelector('.bk-g-kpi');
  ok(await p.evaluate(() => !!bkG.boot?.summary), 'el resumen llega dentro de la página (sin esperar a la API)');
  const fast = await get('orders');
  ok(fast.headers()['x-bk-fast'] === '1', 'API rápida activa en lecturas (X-Bk-Fast: 1, solo Woo + buckler-core)');
  const slow = await p.request.post(`${B}/wp-json/bk/v1/gestion/orders/464`, { headers: { 'X-WP-Nonce': nonce }, data: {} });
  ok(!slow.headers()['x-bk-fast'], 'las escrituras cargan todos los plugins');
  ok(fs.existsSync('/tmp/claude-0/bk-local/site/wp-content/mu-plugins/bk-fast-rest.php'), 'mu-plugin de la API rápida instalado');

  // --- Menú -------------------------------------------------------------------------------------
  const tabs = await p.locator('.bk-g-tabs a').allTextContents();
  ok(tabs.join('|') === 'Resumen|Pedidos|Productos|Descuentos|Carritos|Correos|Contenido|SEO y ADS|Ayuda', 'menú: ' + tabs.join(', '));

  // --- Pedidos: columnas, pago confirmado, imprimir ------------------------------------------------
  await p.click('[data-tab="pedidos"]'); await p.waitForSelector('.bk-g-orders tbody tr');
  const head = await p.textContent('.bk-g-orders thead');
  ok(!/Consecutivo/.test(head) && /Pago confirmado/.test(head) && /Imprimir/.test(head), 'lista: sin Consecutivo, con Pago confirmado e Imprimir al final');
  ok(await p.locator('.bk-g-orders tbody tr:first-child td:last-child [data-print]').count() === 1, 'botón Imprimir en la última columna');
  await p.fill('input[name="q"]', '464'); await p.waitForTimeout(1200);
  const paid = p.locator('[data-flag="paid_ok"][data-id="464"]');
  await paid.check(); await p.waitForTimeout(1000);
  ok(/Pago confirmado/.test(await p.textContent('#bk-g-toast')), 'check "Pago confirmado" desde la lista');
  ok(php(`echo wc_get_order(464)->get_meta("_bk_pago_confirmado");`) === 'yes', 'guardado en _bk_pago_confirmado = yes');
  await p.selectOption('select[name="paid_ok"]', 'yes'); await p.waitForTimeout(1000);
  ok(await p.locator('[data-flag="paid_ok"][data-id="464"]').count() === 1 && await p.$$eval('[data-flag="paid_ok"]', els => els.every(e => e.checked)), 'filtro "Pago confirmado"');
  await p.selectOption('select[name="paid_ok"]', ''); await p.waitForTimeout(800);

  // Imprimir (hoja)
  const pr = await p.request.get(`${B}/gestion-tienda/?imprimir=464`);
  const html = await pr.text();
  ok(pr.status() === 200 && /Pedido #464/.test(html) && /Consecutivo: PEDIDO-/.test(html) && /Cédula:/.test(html) && /Total/.test(html) && /✓ Pago confirmado/.test(html), 'hoja de impresión con número, consecutivo, cédula, pago confirmado y totales');
  ok(/<table/.test(html) && /REF/.test(html) && /Prendas completas/.test(html), 'hoja con productos, REF y casillas de empaque');
  const two = await (await p.request.get(`${B}/gestion-tienda/?imprimir=464,465`)).text();
  ok((two.match(/class="sheet"/g) || []).length === 2, 'varios pedidos en una sola impresión (salto de página)');
  const anonPrint = await (await b.newContext()).request.get(`${B}/gestion-tienda/?imprimir=464`, { maxRedirects: 0 });
  ok(anonPrint.status() === 200 && !/Pedido #464/.test(await anonPrint.text()) && /bk-g-login__form/.test(await anonPrint.text()), 'impresión sin sesión → pantalla de acceso (sin datos del pedido)');
  php(`$o=wc_get_order(464); $o->update_meta_data("_impreso_status","no"); $o->save();`);
  await p.fill('input[name="q"]', ''); await p.fill('input[name="q"]', '464'); await p.waitForTimeout(1200);
  await p.evaluate(() => { window.print = () => {}; });
  await p.click('[data-print="464"]'); await p.waitForTimeout(1500);
  ok(await p.locator('#bk-g-printframe').count() === 1 && /imprimir=464/.test(await p.getAttribute('#bk-g-printframe', 'src')), 'Imprimir abre la hoja en un marco oculto (sin salir del panel)');
  ok(php(`echo wc_get_order(464)->get_meta("_impreso_status");`) === 'yes' && await p.locator('[data-flag="printed"][data-id="464"]').isChecked(), 'al imprimir queda marcado como Impreso');

  // --- Detalle: historial y archivos ------------------------------------------------------------
  await p.hover('[data-order="464"]'); await p.waitForTimeout(500);
  const t0 = Date.now(); await p.click('[data-order="464"]'); await p.waitForSelector('.bk-g-hist'); const openMs = Date.now() - t0;
  ok(openMs < 400, `detalle precargado al pasar el cursor (${openMs} ms)`);
  const hist = await p.locator('.bk-g-hist').textContent();
  ok(/Pedido creado/.test(hist) && /Pago confirmado/.test(hist) && /Gestión de tienda/.test(hist) && /impreso/.test(hist), 'historial: creado, pago confirmado e impreso (por Gestión de tienda)');
  ok(/→/.test(hist), 'historial de estados de pedidos antiguos tomado de las notas');
  ok(await p.locator('#bk-g-opaid').isChecked(), 'detalle con el interruptor Pago confirmado');
  await p.selectOption('#bk-g-ostatus', 'on-hold'); await p.click('#bk-g-osave'); await p.waitForTimeout(1500);
  const hist2 = await p.locator('.bk-g-hist li').first().textContent();
  ok(/espera|on hold/i.test(hist2) && /Gestión de tienda/.test(hist2), 'cambio de estado registrado arriba del historial: ' + hist2.replace(/\s+/g, ' ').trim());
  php(`wc_get_order(464)->update_status("processing", "Cambio externo de prueba.");`);
  const h3 = (await (await get('orders/464')).json()).history;
  ok(/Procesando|Processing/.test(h3[h3.length - 1].label), 'cambios hechos fuera del panel (wp-admin, pasarelas) también quedan en el historial');

  ok(await p.locator('.bk-g-fsec h3').count() === 1 && await p.locator('.bk-g-file').count() === 1, 'archivos del pedido (ACF) visibles: 1');
  const pdf = '/tmp/claude-0/bk-local/comprobante.pdf';
  fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
  await p.setInputFiles('[data-file-up="464"]', pdf); await p.waitForTimeout(2500);
  ok(await p.locator('.bk-g-file').count() === 2 && await p.locator('.bk-g-file__doc').count() === 1, 'subir PDF al pedido → 2 archivos');
  const acf = JSON.parse(php(`$o=wc_get_order(464); echo json_encode([$o->get_meta("archivos_adjuntos"), $o->get_meta("archivos_adjuntos_1_archivo")]);`));
  ok(String(acf[0]) === '2' && Number(acf[1]) > 0, 'guardado con el formato del repeater ACF (archivos_adjuntos = 2, _1_archivo = ID)');
  p.once('dialog', d => d.accept());
  await p.locator('[data-file-del="0"]').click(); await p.waitForTimeout(1500);
  const acf2 = JSON.parse(php(`$o=wc_get_order(464); echo json_encode([$o->get_meta("archivos_adjuntos"), $o->get_meta("archivos_adjuntos_0_archivo"), $o->get_meta("archivos_adjuntos_1_archivo")]);`));
  ok(await p.locator('.bk-g-file').count() === 1 && String(acf2[0]) === '1' && Number(acf2[1]) === Number(acf[1]) && acf2[2] === '', 'quitar archivo reordena las filas');
  ok(/Archivo agregado/.test(await p.locator('.bk-g-hist').textContent()), 'historial registra los archivos');
  // Devolver el archivo original al pedido para las otras suites.
  php(`$o=wc_get_order(464); $img=(int)wc_get_products(["limit"=>1,"type"=>"variable","orderby"=>"ID","order"=>"ASC"])[0]->get_image_id(); $o->update_meta_data("archivos_adjuntos",1); $o->update_meta_data("archivos_adjuntos_0_archivo",57); $o->save();`);
  await p.keyboard.press('Escape'); await p.waitForTimeout(300);

  // --- Productos: todas las categorías y saltos de línea --------------------------------------------
  await p.goto(B + `/gestion-tienda/#productos/editar/${pid}`); await p.waitForSelector('#bk-gp');
  ok(await p.evaluate(() => [...document.scripts].some(s => /gestion-product\.js/.test(s.src))), 'el editor de productos se carga al abrirlo');
  const desc = await p.innerHTML('#bk-gp-desc');
  ok(/<br>/.test(desc) && (desc.match(/<p>/g) || []).length >= 2, 'descripción con saltos de línea visibles en el editor');
  const tree = p.locator('.bk-gp-tree');
  await tree.locator('summary').click();
  const nCats = await tree.locator('input[name="all_cats"]').count();
  ok(nCats >= 10, `todas las categorías de la tienda en el editor (${nCats})`);
  const extra = tree.locator('input[name="all_cats"]:not(:checked)').first(); const extraId = await extra.getAttribute('value');
  await extra.check();
  const chip = p.locator('input[name="cats"]:not(:checked)').first(); const chipId = await chip.count() ? await chip.getAttribute('value') : null;
  if (chipId) await chip.check();
  ok(!chipId || await tree.locator(`input[value="${chipId}"]`).isChecked(), 'Prenda y Todas las categorías sincronizadas');
  await p.click('#bk-gp [type="submit"][data-status], #bk-gp button[type="submit"]'); await p.waitForTimeout(2000);
  const cats = JSON.parse(php(`echo json_encode(wc_get_product(${pid})->get_category_ids());`)).map(String);
  ok(cats.includes(extraId), 'categoría extra guardada');
  await p.locator('.bk-gp-tree summary').click();
  await p.locator(`.bk-gp-tree input[value="${extraId}"]`).uncheck();
  await p.click('#bk-gp button[type="submit"]'); await p.waitForTimeout(2000);
  ok(!JSON.parse(php(`echo json_encode(wc_get_product(${pid})->get_category_ids());`)).map(String).includes(extraId), 'categoría quitada');
  const saved = php(`echo wc_get_product(${pid})->get_description();`);
  ok(/<p>Detalles:<br>/.test(saved.replace(/<br\s*\/>/g, '<br>')), 'la descripción se guarda con párrafos y saltos');

  // --- Carritos ---------------------------------------------------------------------------------
  await p.goto(B + '/gestion-tienda/#carritos'); await p.waitForSelector('.bk-g-kpi');
  ok(await p.locator('.bk-g-kpi').count() === 4 && /Recuperados/.test(await p.textContent('#bk-g-main')), 'Carritos abandonados: indicadores y lista');

  // --- Correos ----------------------------------------------------------------------------------
  await p.goto(B + '/gestion-tienda/#correos'); await p.waitForSelector('[data-mail]');
  const ids = await p.$$eval('[data-mail]', els => els.map(e => e.dataset.mail));
  ok(['wc:customer_processing_order', 'wc:customer_completed_order', 'wc:customer_cancelled_order', 'wc:new_order', 'bk:shipped', 'bk:ac1'].every(i => ids.includes(i)), `plantillas de correo (${ids.length})`);
  const ex = await p.$eval('#bk-g-morder option:nth-child(2)', o => ({ v: o.value, n: o.textContent.match(/#(\d+)/)[1] }));
  await p.selectOption('#bk-g-morder', ex.v);
  const before = mails();
  await p.click('[data-mail="wc:customer_processing_order"]'); await p.waitForTimeout(1500);
  ok(new RegExp(ex.n).test(await p.frameLocator('.bk-g-mframe').locator('body').textContent()), 'vista previa de Woo con el pedido elegido');
  await p.click('[data-mail="wc:customer_cancelled_order"]'); await p.waitForTimeout(1500);
  ok((await p.frameLocator('.bk-g-mframe').locator('body').textContent()).length > 100, 'vista previa del correo de cancelado');
  ok(newMails(before).length === 0, 'la vista previa no envía nada');
  await p.fill('#bk-g-mtest input[name="to"]', 'pruebas@buckler.test'); await p.click('#bk-g-mtest button'); await p.waitForTimeout(1500);
  const sent = newMails(before);
  ok(sent.length === 1 && /^\[Prueba\]/.test(sent[0].subject) && sent[0].to === 'pruebas@buckler.test', 'correo de prueba: ' + (sent[0]?.subject || 'ninguno'));

  // --- SEO y ADS: contador de visitas -------------------------------------------------------------
  php(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bk_stats"); $wpdb->query("DELETE FROM {$wpdb->prefix}bk_stats_seen");`);
  const v = await (await b.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } })).newPage(); watch(v);
  await v.goto(B + '/?utm_source=facebook&utm_medium=paid&utm_campaign=octubre'); await v.waitForTimeout(1800);
  await v.goto(B + '/shop/'); await v.waitForTimeout(1800);
  const bot = await (await b.newContext({ userAgent: 'Googlebot/2.1' })).newPage();
  await bot.goto(B + '/'); await bot.waitForTimeout(1800);
  await p.goto(B + '/'); await p.waitForTimeout(1800); // La gestora no cuenta.
  const st = (await (await get('seo/visits?days=30')).json());
  ok(st.enabled && st.kpis.today.visitors === 1 && st.kpis.today.views === 2, `contador: 1 visitante, 2 páginas (sin robots ni equipo) → ${st.kpis.today.visitors}/${st.kpis.today.views}`);
  ok(st.pages.some(x => x.label === '/') && st.pages.some(x => x.label === '/shop/'), 'páginas más vistas');
  ok(st.sources[0]?.label === 'Meta Ads (Facebook / Instagram)' && st.devices[0]?.label === 'Celular' && st.camps[0]?.label === 'octubre', 'origen (Meta Ads), dispositivo y campaña');
  ok(php(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bk_stats_seen WHERE LENGTH(h)=16");`) === '1', 'solo una huella anónima de 16 caracteres (sin IP ni cookies)');
  ok(await v.evaluate(() => document.cookie === ''), 'sin cookies de seguimiento');
  await p.goto(B + '/gestion-tienda/#seo'); await p.waitForSelector('.bk-g-bars');
  ok(await p.locator('.bk-g-bars span').count() === 30 && /Meta Ads/.test(await p.textContent('#bk-g-main')), 'SEO y ADS → Visitas: gráfica de 30 días y orígenes');

  // --- Etiquetas y SEO técnico ----------------------------------------------------------------
  await p.click('a[href="#seo/etiquetas"]'); await p.waitForSelector('[data-tags-refresh]', { timeout: 60000 });
  const tg = await p.textContent('#bk-g-seo');
  ok(/GTM-TEST/.test(tg) && /Google Tag Manager/.test(tg) && /Píxel de Meta/.test(tg), 'etiquetas encontradas en el HTML público (GTM)');
  ok(await p.locator('.bk-g-seot tbody tr').count() === 3, 'SEO técnico de inicio, tienda y producto');
  ok(/llms\.txt/.test(tg) && /robots\.txt/.test(tg), 'revisión del sitio (robots, sitemap, llms.txt)');
  ok(/Con evento de compra de Meta/.test(tg), 'verificación de compras con pedidos reales');

  // --- Rastreo: clases y eventos dataLayer --------------------------------------------------------
  await p.click('a[href="#seo/rastreo"]'); await p.waitForSelector('[data-copy]');
  ok(await p.locator('#bk-g-seo code').count() > 20, 'lista de clases y eventos rastreables');
  await v.goto(B + '/shop/?linea=slim'); await v.locator('.bk-card a').first().click(); await v.waitForLoadState('load');
  const sels = ['.bk-card', '[data-bk-cart]', 'form.cart .single_add_to_cart_button', '.bk-sizes .bk-size', 'a[href*="wa.me"]'];
  const found = await v.evaluate((ss) => ss.map(s => document.querySelectorAll(s).length), sels);
  ok(found.every(n => n > 0), 'los selectores documentados existen en la ficha: ' + found.join(','));
  await v.locator('.bk-sizes [role="radio"]:not(.is-out)').first().click();
  await v.click('.single_add_to_cart_button'); await v.waitForSelector('.bk-sc__item', { timeout: 15000 }); await v.waitForTimeout(500);
  const dl = await v.evaluate(() => (window.dataLayer || []).filter(e => e.event && e.event.startsWith('bk_')));
  const add = dl.find(e => e.event === 'bk_add_to_cart');
  ok(dl.some(e => e.event === 'bk_size_select') && add && add.ecommerce.items[0].item_name && add.ecommerce.currency === 'COP' && add.ecommerce.value > 0, 'dataLayer: bk_size_select y bk_add_to_cart con formato GA4');

  // --- Ajustes oculto / Móvil -----------------------------------------------------------------------
  const a = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage(); watch(a);
  await login(a, 'admin', 'admin');
  ok(await a.locator('[data-tab="ajustes"]').count() === 0, 'Ajustes no aparece en el menú');
  await a.goto(B + '/gestion-tienda/#ajustes'); await a.waitForSelector('#bk-g-sf');
  ok(await a.locator('#bk-g-sf [name="pago"]').count() === 1, 'Ajustes sigue disponible para administradores en #ajustes (campo Pago confirmado)');
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, storageState: await ctx.storageState() })).newPage(); watch(m);
  for (const h of ['#pedidos', '#correos', '#seo', '#carritos']) {
    await m.goto(B + '/gestion-tienda/' + h); await m.waitForTimeout(1500);
    ok(!(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)), `móvil ${h}: sin scroll horizontal`);
  }
  await m.goto(B + '/gestion-tienda/#pedidos'); await m.waitForSelector('.bk-g-orders'); await m.screenshot({ path: '/tmp/claude-0/bk-local/g16-mobile.png' });
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
