// Ronda 0.17: rueda del mouse sobre las tarjetas, buscador, San Andrés, 404 → inicio, acceso propio de la gestión,
// salir sin cambiar de página, Gestor_ADS solo SEO, alerta de etiquetas, Ayuda, Contenido (ACF), Descuentos (campaña y
// por cantidad, ficha, carrito, pedido), aviso de ¡Sale! sin cambio y correos con el diseño de la marca.
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const B = 'http://localhost:8899';
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString();
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
const mails = () => fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter(f => f.endsWith('.json')) : [];
const iso = (d) => { const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000); return z.toISOString().slice(0, 16); };
const loginG = async (p, u, pw) => { await p.goto(B + '/gestion-tienda/'); await p.fill('[name=log]', u); await p.fill('[name=pwd]', pw); await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]); };

(async () => {
  // Datos: stock de las tallas de prueba, usuaria Gestor_ADS, sin descuentos.
  php(`foreach(wc_get_products(["limit"=>-1,"type"=>"variable"]) as $p){ foreach($p->get_children() as $c){ $v=wc_get_product($c); if($v && $v->managing_stock() && $v->get_stock_quantity()<5 && $v->get_stock_quantity()!==0 ){ $v->set_stock_quantity(10); $v->save(); } } } if(!get_user_by("login","Gestor_ADS")){ $id=wp_create_user("Gestor_ADS","Clave#2026","ads@ejemplo.com"); (new WP_User($id))->set_role("contributor"); } delete_option("bk_discounts"); delete_option("bk_g_seo_users");`);
  php(`$v=wc_get_product(934); if($v){ $v->set_stock_quantity(20); $v->set_stock_status("instock"); $v->save(); }`);
  const b = await chromium.launch();
  const errs = [];
  const watch = (pg) => { pg.on('pageerror', e => errs.push(e.message)); pg.on('console', m => { if (m.type() === 'error' && !/status of (400|403|404)|ERR_TUNNEL|gravatar|favicon/.test(m.text())) errs.push(m.text()); }); };

  // --- 1. Rueda del mouse sobre la foto de una tarjeta: la página baja -----------------------------
  const p = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage(); watch(p);
  await p.goto(B + '/shop/');
  const box = await p.locator('.bk-card__media').first().boundingBox();
  await p.mouse.move(box.x + box.width / 2, box.y + box.height / 2); await p.waitForTimeout(300);
  const y0 = await p.evaluate(() => scrollY); await p.mouse.wheel(0, 500); await p.waitForTimeout(700);
  const y1 = await p.evaluate(() => scrollY);
  const tr = await p.evaluate(() => { const t = document.querySelector('.bk-card__track'); return { oy: getComputedStyle(t).overflowY, sh: t.scrollHeight, ch: t.clientHeight }; });
  ok(y1 > y0 + 200 && tr.oy === 'hidden', `rueda del mouse sobre la foto baja la página (${y0} → ${y1}, overflow-y ${tr.oy})`);

  const assets = await p.evaluate(() => ({ me: [...document.querySelectorAll('link[rel=stylesheet],script[src]')].some((e) => /mediaelement/.test(e.href || e.src)), jq: !!window.jQuery }));
  ok(!assets.me && !assets.jq, 'tienda sin el reproductor mediaelement ni jQuery (bloqueaban la carga en el celular)');

  // --- 2. Buscador: el campo no dibuja su propio recuadro; el anillo va en la píldora ---------------
  const inp = p.locator('.bk-header .bk-search input[type="search"]').first();
  await inp.click(); await inp.type('jean', { delay: 40 }); await p.waitForTimeout(600);
  const sv = await inp.evaluate((el) => { const s = getComputedStyle(el); const f = getComputedStyle(el.closest('form')); return { bs: s.boxShadow, br: s.borderRadius, fb: f.boxShadow, fr: f.borderRadius }; });
  ok(sv.bs === 'none' && sv.br === '0px' && sv.fb !== 'none' && sv.fr === '99px', 'buscador: sin recuadro interno al escribir, foco en la píldora redondeada');

  // --- 3. Checkout: San Andrés ------------------------------------------------------------------
  const states = JSON.parse(php('echo json_encode(WC()->countries->get_states("CO"));'));
  ok(states['CO-SAP'] === 'San Andrés', 'departamento "San Andrés" (sin Providencia), mismo código CO-SAP');

  // --- 4. 404 → inicio (archivos: 404 real) ------------------------------------------------------
  const r404 = await p.request.get(B + '/esta-pagina-no-existe/', { maxRedirects: 0 });
  ok(r404.status() === 301 && /localhost:8899\/$/.test(r404.headers().location || ''), 'página inexistente → 301 al inicio');
  const rf = await p.request.get(B + '/.well-known/ai-catalog.json', { maxRedirects: 0 });
  ok(rf.status() === 404, 'archivos técnicos inexistentes siguen con 404 real (agentes, Merchant)');

  // --- 5. Gestión: acceso propio, error, salir sin cambiar de página -------------------------------
  const g = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage(); watch(g);
  const r0 = await g.goto(B + '/gestion-tienda/');
  ok(r0.status() === 200 && /gestion-tienda/.test(g.url()) && await g.locator('.bk-g-login__form').count() === 1, 'gestión sin sesión: pantalla de acceso propia (sin wp-login.php)');
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'mala'); await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  ok(/incorrectos/.test(await g.textContent('.bk-g-login__err')) && await g.inputValue('[name=log]') === 'gestora', 'contraseña incorrecta: mensaje genérico y el usuario se conserva');
  await g.click('.bk-g-login__eye'); ok(await g.getAttribute('[name=pwd]', 'type') === 'text', 'botón para ver la contraseña');
  await g.fill('[name=pwd]', 'gestora'); await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.waitForSelector('.bk-g-tabs');
  const tabs = await g.locator('.bk-g-tabs a').allTextContents();
  ok(tabs.join('|') === 'Resumen|Pedidos|Productos|Descuentos|Carritos|Correos|Contenido|SEO y ADS|Ayuda', 'menú: ' + tabs.join(', '));
  ok(!(await g.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'menú de 9 secciones sin desbordar a 1440 px');
  const nonce = await g.evaluate(() => bkG.nonce);
  const api = async (path, data) => { const r = data ? await g.request.post(`${B}/wp-json/bk/v1/gestion/${path}`, { headers: { 'X-WP-Nonce': nonce }, data }) : await g.request.get(`${B}/wp-json/bk/v1/gestion/${path}`, { headers: { 'X-WP-Nonce': nonce } }); return { s: r.status(), d: await r.json() }; };

  // --- 6. Ayuda ----------------------------------------------------------------------------------
  await g.goto(B + '/gestion-tienda/#ayuda'); await g.waitForSelector('.bk-g-help details');
  ok(await g.locator('.bk-g-help details').count() >= 8 && /descuento por cantidad/i.test(await g.textContent('.bk-g-help')), 'Ayuda: explica cada módulo');

  // --- 7. SEO y ADS: alerta de etiquetas faltantes ------------------------------------------------
  await g.goto(B + '/gestion-tienda/#seo'); await g.waitForSelector('.bk-g-alert');
  ok(/contacto@ufoweb\.co/.test(await g.textContent('.bk-g-alert')) && /^mailto:contacto@ufoweb\.co\?subject=/.test(await g.getAttribute('#bk-g-alert-mail', 'href')), 'SEO y ADS: alerta "envía las etiquetas faltantes a contacto@ufoweb.co"');

  // --- 8. Contenido: banners, guía, políticas, preguntas -----------------------------------------
  const imgs = JSON.parse(php('echo json_encode(get_posts(["post_type"=>"attachment","post_mime_type"=>"image","posts_per_page"=>3,"fields"=>"ids"]));'));
  let c = await api('content/banners', { slides: [{ pc: imgs[0], mo: imgs[1], link: B + '/linea-curvy/', alt: 'Nueva colección Curvy' }, { pc: imgs[2], mo: 0, link: '', alt: 'Segundo banner' }], lines: { linea_slim_pc: imgs[0] } });
  ok(c.s === 200 && c.d.slides.length === 2 && c.d.slides[0].link === B + '/linea-curvy/', 'banners guardados (2) con enlace');
  const raw = JSON.parse(php('echo json_encode([get_option("options_banners_pagina_inicio"), get_option("options_banners_pagina_inicio_0_banner_pc"), get_option("_options_banners_pagina_inicio_0_banner_pc"), get_option("options_banners_pagina_inicio_0_enlace"), get_post_meta(' + imgs[0] + ', "_wp_attachment_image_alt", true)]);'));
  ok(String(raw[0]) === '2' && Number(raw[1]) === imgs[0] && raw[2] === 'field_bk_banner_pc' && raw[3].url === B + '/linea-curvy/' && raw[4] === 'Nueva colección Curvy', 'formato de ACF: filas, claves de campo, enlace y texto alternativo');
  c = await api('content/banners', { slides: [{ pc: imgs[2], mo: 0, link: '', alt: 'Segundo banner', from: 1 }, { pc: imgs[0], mo: imgs[1], link: B + '/linea-curvy/', alt: 'Nueva colección Curvy', from: 0 }], lines: {} });
  ok(c.d.slides[0].pc.id === imgs[2] && c.d.slides[1].mo.id === imgs[1], 'reordenar banners');
  await p.goto(B + '/'); ok(await p.locator('.bk-hero picture, .bk-hero img').count() >= 1, 'el inicio muestra los banners guardados desde la gestión');
  await g.goto(B + '/gestion-tienda/#contenido/banners'); await g.waitForSelector('.bk-gc-slide');
  ok(await g.locator('.bk-gc-slide').count() === 2 && await g.locator('.bk-gc-lines .bk-gc-slot').count() === 4, 'Contenido → Banners: 2 banners y 4 imágenes de líneas');
  // Desde 0.18.7 la guía es texto (tablas + siluetas): guardar y restaurar.
  c = await api('content/guide', { reset: 1 });
  const gt = c.d.tables['linea-slim'];
  c = await api('content/guide', { tables: { 'linea-slim': [...gt, ['16', 89, 94, 117, 122]] }, note: 'Nota de prueba', sil: c.d.sil });
  ok(c.d.tables['linea-slim'].length === gt.length + 1 && c.d.note === 'Nota de prueba', 'guía de tallas guardada');
  await api('content/guide', { reset: 1 });
  // Preguntas frecuentes: crear, editar, ordenar, papelera.
  const term = php('$t=term_exists("Envíos","caregoria-pf"); if(!$t){$t=wp_insert_term("Envíos","caregoria-pf");} echo is_array($t)?$t["term_id"]:$t;').trim();
  c = await api('content/posts/faq/save', { title: '¿Hacen envíos a San Andrés?', text: '<p>Sí, con <strong>costo adicional</strong>.</p>', cat: Number(term), status: 'publish' });
  const q = c.d.items.find(x => x.title === '¿Hacen envíos a San Andrés?');
  ok(c.s === 200 && q && q.cat === Number(term) && php(`echo get_post_meta(${q.id},"respuesta_pregunta_frecuente",true);`).includes('<strong>costo adicional</strong>'), 'pregunta frecuente creada con categoría y respuesta (campo ACF)');
  const bad = await api('content/posts/faq/save', { title: 'x', text: '' });
  ok(bad.s === 400 && bad.d.data?.field === 'title', 'validación de la pregunta');
  await p.goto(B + '/preguntas-frecuentes/'); ok(/costo adicional/.test(await p.textContent('main')), 'la pregunta se ve en /preguntas-frecuentes/');
  await g.goto(B + `/gestion-tienda/#contenido/preguntas/${q.id}`); await g.waitForSelector('#bk-gc-text');
  await g.fill('#bk-gcp [name="title"]', '¿Envían a San Andrés?'); await g.click('#bk-gcp [type="submit"]'); await g.waitForTimeout(1500);
  ok(php(`echo get_the_title(${q.id});`) === '¿Envían a San Andrés?' && /#contenido\/preguntas$/.test(g.url()), 'editar pregunta desde el panel (editor de texto)');
  const ids = (await api('content/posts/faq')).d.items.map(x => x.id);
  c = await api('content/posts/faq/order', { ids: [q.id, ...ids.filter(x => x !== q.id)] });
  ok(c.d.items[0].id === q.id, 'ordenar preguntas');
  c = await api('content/posts/faq/delete', { id: q.id });
  ok(!c.d.items.some(x => x.id === q.id) && php(`echo get_post_status(${q.id});`) === 'trash', 'pregunta a la papelera');
  // Política.
  const pol = (await api('content/posts/policy')).d.items[0];
  c = await api('content/posts/policy/save', { id: pol.id, title: pol.title, text: '<h3>Plazos</h3><p>Texto de prueba de la política.</p>', status: 'publish' });
  ok(c.s === 200 && php(`echo get_post_meta(${pol.id},"texto_politica",true);`).includes('<h3>Plazos</h3>'), 'política editada (texto_politica)');

  // --- 9. Descuentos: campaña y por cantidad --------------------------------------------------------
  const now = new Date();
  let dd = await api('discounts', { type: 'campaign', rule: { name: 'x', pct: 20, start: iso(new Date(now - 86400000)), end: iso(new Date(now - 3600000)) } });
  ok(dd.s === 400, 'validación: nombre y fechas');
  dd = await api('discounts', { type: 'campaign', rule: { name: 'Black Friday', pct: 20, start: iso(new Date(now - 86400000)), end: iso(new Date(+now + 5 * 86400000)), scope: 'all', exclude_sale: true, badge: 'BLACK FRIDAY', bar: true, home: true } });
  ok(dd.s === 200 && dd.d.campaigns[0].state === 'active', 'campaña del 20 % activa');
  dd = await api('discounts', { type: 'qty', rule: { name: 'Compra más y ahorra', start: iso(new Date(now - 86400000)), end: iso(new Date(+now + 5 * 86400000)), scope: 'all', count: 'product', tiers: [{ min: 1, pct: 10 }, { min: 2, pct: 25 }, { min: 4, pct: 30 }] } });
  ok(dd.s === 200 && dd.d.qty[0].tier_labels.map(t => t.label).join('|') === '1 prenda|2 a 3 prendas|4 o más prendas', 'descuento por cantidad con tramos');
  const bad2 = await api('discounts', { type: 'qty', rule: { name: 'Mal', start: iso(now), end: iso(new Date(+now + 86400000)), tiers: [{ min: 2, pct: 30 }, { min: 4, pct: 20 }] } });
  ok(bad2.s === 400 && bad2.d.data?.field === 'tiers', 'validación: cada tramo da más que el anterior');
  const fut = await api('discounts', { type: 'campaign', rule: { name: 'Navidad', pct: 40, start: iso(new Date(+now + 10 * 86400000)), end: iso(new Date(+now + 12 * 86400000)) } });
  ok(fut.d.campaigns.find(x => x.name === 'Navidad').state === 'scheduled', 'campaña futura queda programada (no se aplica todavía)');
  ok(php('echo count(as_get_scheduled_actions(["hook"=>"bk_disc_tick","status"=>"pending"]));') >= 3, 'cambios de precio programados al inicio y fin de cada regla');

  await p.goto(B + '/');
  ok(/−20 % en toda la tienda · Black Friday/.test(await p.textContent('.bk-announce')) && await p.locator('.bk-disc-home .bk-card').count() >= 1, 'inicio: anuncio arriba y sección destacada de la campaña');
  await p.goto(B + '/product/jean-prueba-dama-recto-tiro-alto/');
  const price = (await p.textContent('.summary .price')).replace(/\s+/g, ' ');
  ok(/\$129\.900/.test(price) && /\$103\.920/.test(price), 'ficha: precio tachado $129.900 → $103.920 (−20 %)');
  const boxTxt = (await p.textContent('.bk-disc-box')).replace(/\s+/g, ' ');
  ok(/Black Friday/.test(boxTxt) && /2 a 3 prendas/.test(boxTxt) && !/1 prenda−10/.test(boxTxt.replace(/\s/g, '')), 'ficha: campaña + tramos que superan a la campaña (el de 10 % no se muestra)');
  await p.goto(B + '/shop/');
  ok(/BLACK FRIDAY/.test(await p.locator('.bk-card').first().locator('.bk-card__badges').textContent()), 'tarjetas: etiqueta de la campaña');
  await p.goto(B + '/shop/?oferta=1'); const nOff = await p.locator('.bk-card').count();
  ok(nOff >= 3, `filtro "En descuento" incluye la campaña (${nOff})`);
  const saleUrl = php('foreach(wc_get_product_ids_on_sale() as $i){$x=wc_get_product($i); if($x && $x->is_type("variable") && $x->is_in_stock()){echo $x->get_permalink();break;}}');
  await p.goto(saleUrl);
  const sp = (await p.textContent('.summary .price')).replace(/\s+/g, ' ');
  ok(await p.locator('.bk-sale-notice').count() === 1 && /Productos en SALE: No tienen cambio\./.test(await p.textContent('.bk-sale-notice')) && !/garantía/.test(await p.textContent('.bk-sale-notice')), 'producto de ¡Sale!: aviso "Productos en SALE: No tienen cambio." (sin mencionar garantía)');
  ok(await p.locator('.bk-disc-box').count() === 0 && !/123\.920/.test(sp), 'producto de ¡Sale!: sin campaña (ninguna talla toma el 20 %)');

  // Carrito y pedido.
  const add = async (url, qty) => { await p.goto(B + url); await p.locator('.bk-sizes [role="radio"]:not(.is-out)').first().click(); await p.fill('input.qty', String(qty)); await Promise.all([p.waitForResponse(r => /cart\/add-item/.test(r.url())), p.click('.single_add_to_cart_button')]); await p.waitForTimeout(500); };
  await add('/product/jean-prueba-dama-recto-tiro-alto/', 2);
  await add(new URL(saleUrl).pathname, 1);
  await p.goto(B + '/cart/');
  const cart = (await p.textContent('.woocommerce-cart-form')).replace(/\s+/g, ' ');
  ok(/−25 % por cantidad \(2 prendas\)/.test(cart) && /\$97\.425/.test(cart), 'carrito: 2 prendas → 25 % ($97.425 c/u, mayor que el 20 % de la campaña)');
  ok(/Agrega 2 prendas más y obtén el 30 %/.test(await p.textContent('.bk-disc-hint')), 'carrito: "agrega 2 prendas más y obtén el 30 %"');
  ok(/Sin cambio \(producto en SALE\)/.test(cart) && !/garantía/i.test(cart), 'carrito: la prenda de ¡Sale! dice "sin cambio"');
  await p.goto(B + '/checkout/'); await p.waitForSelector('form.checkout');
  await p.fill('#billing_first_name', 'Prueba'); await p.fill('#billing_last_name', 'Ronda17'); await p.fill('#billing_address_1', 'Calle 1 # 2-3'); if (await p.locator('#billing_city').count()) await p.fill('#billing_city', 'Medellín'); await p.fill('#billing_phone', '3001234567'); await p.fill('#billing_email', 'ronda17@ejemplo.com');
  await p.locator('#billing_state').selectOption('CO-SAP').catch(() => {}); await p.waitForTimeout(1500);
  ok(/productos en SALE .*no tienen cambio/.test(await p.textContent('#order_review')), 'checkout: aviso antes de pagar');
  const consent = p.locator('#bk_ac_consent'); if (await consent.count()) await consent.check().catch(() => {});
  const before = mails();
  await p.click('#place_order'); await p.waitForURL(/order-received/, { timeout: 20000 }).catch(() => {});
  const oid = (p.url().match(/order-received\/(\d+)/) || [])[1];
  const lines = JSON.parse(php(`$o=wc_get_order(${oid}); $out=[]; foreach($o->get_items() as $i){ $m=[]; foreach($i->get_formatted_meta_data() as $x){ $m[$x->display_key]=wp_strip_all_tags($x->display_value);} $out[]=["q"=>$i->get_quantity(),"t"=>$i->get_total(),"m"=>$m]; } echo json_encode(["l"=>$out,"st"=>$o->get_billing_state()]);`));
  ok(lines.l[0].t === '194850' && /25 %/.test(lines.l[0].m.Descuento || '') && /Sin cambio/.test(lines.l[1].m['Cambios'] || ''), 'pedido: descuento y "sin cambio" anotados en cada línea');
  ok(lines.st === 'CO-SAP', 'pedido con departamento San Andrés (CO-SAP)');
  const m = newOf(before).find(x => x.to === 'ronda17@ejemplo.com');
  const html = m ? fs.readFileSync(`${MAILS}/${m.f.replace('.json', '.html')}`, 'utf8') : '';
  ok(/logo-email\.png/.test(html) && /Escribir por WhatsApp/.test(html) && /DENIM BLUE JEANS/.test(html) && /#1a3955/i.test(html), 'correo de WooCommerce con el diseño de la marca (logo, WhatsApp, pie)');

  // Pausar la campaña: precios normales.
  const camp = (await api('discounts')).d.campaigns.find(x => x.name === 'Black Friday');
  await api('discounts', { type: 'campaign', rule: { ...camp, active: false } });
  await p.goto(B + '/product/jean-prueba-dama-recto-tiro-alto/');
  ok(!/103\.920/.test(await p.textContent('.summary .price')) && await p.locator('.bk-disc-box__camp').count() === 0, 'campaña pausada: vuelve el precio normal');
  // Gestión → Descuentos (pantalla).
  await g.goto(B + '/gestion-tienda/#descuentos'); await g.waitForSelector('.bk-gd-item');
  ok(await g.locator('.bk-gd-item').count() === 3 && /Pausada/.test(await g.textContent('#bk-g-main')) && /Programada/.test(await g.textContent('#bk-g-main')), 'Descuentos: lista con estados (activa, pausada, programada)');
  await g.click('a[href="#descuentos/cantidad/nuevo"]'); await g.waitForSelector('#bk-gd');
  await g.fill('#bk-gd [name="name"]', 'Lleva 3'); await g.click('[data-tadd]');
  ok(/4 o más|o más prendas/.test(await g.textContent('#bk-gd-tprev')), 'formulario: vista previa de los tramos');
  await g.click('#bk-gd [type="submit"]'); await g.waitForTimeout(1500);
  ok(/#descuentos$/.test(g.url()) && await g.locator('.bk-gd-item').count() === 4, 'crear descuento por cantidad desde el formulario');
  php('delete_option("bk_discounts");');

  // Salir: se queda en la gestión.
  await g.click('.bk-g-user a:last-child'); await g.waitForLoadState('load');
  ok(/gestion-tienda\/\?adios=1/.test(g.url()) && /Cerraste sesión/.test(await g.textContent('.bk-g-login__ok')), 'Salir: se queda en /gestion-tienda/ con "Cerraste sesión"');

  // --- 10. Gestor_ADS: solo SEO y ADS -----------------------------------------------------------------
  const a = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage(); watch(a);
  await loginG(a, 'Gestor_ADS', 'Clave#2026'); await a.waitForSelector('.bk-g-tabs');
  ok((await a.locator('.bk-g-tabs a').allTextContents()).join('|') === 'SEO y ADS|Ayuda', 'Gestor_ADS (Colaborador) solo ve SEO y ADS y Ayuda');
  await a.waitForSelector('.bk-g-alert');
  const an = await a.evaluate(() => bkG.nonce);
  const ao = await a.request.get(`${B}/wp-json/bk/v1/gestion/orders`, { headers: { 'X-WP-Nonce': an } });
  const av = await a.request.get(`${B}/wp-json/bk/v1/gestion/seo/visits`, { headers: { 'X-WP-Nonce': an } });
  ok(ao.status() === 403 && av.status() === 200, 'Gestor_ADS: pedidos 403, visitas 200');
  await a.goto(B + '/wp-admin/'); ok(/gestion-tienda\/#seo/.test(a.url()), 'Gestor_ADS no entra a wp-admin (va a SEO y ADS)');
  await a.goto(B + '/gestion-tienda/?imprimir=464'); ok(await a.locator('.bk-g-tabs').count() === 1 && !/Pedido #464/.test(await a.textContent('body')), 'Gestor_ADS no puede imprimir pedidos');

  // Móvil.
  const mob = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage(); watch(mob);
  await mob.goto(B + '/gestion-tienda/');
  ok(!(await mob.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'acceso en celular sin desbordar');
  await loginG(mob, 'gestora', 'gestora');
  for (const h of ['#descuentos', '#contenido/banners', '#ayuda']) { await mob.goto(B + '/gestion-tienda/' + h); await mob.waitForTimeout(1200); ok(!(await mob.evaluate(() => document.documentElement.scrollWidth > innerWidth)), `celular ${h}: sin scroll horizontal`); }
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });

function newOf(before) { return mails().filter(f => !before.includes(f)).map(f => ({ f, ...JSON.parse(fs.readFileSync(`${MAILS}/${f}`, 'utf8')) })); }
