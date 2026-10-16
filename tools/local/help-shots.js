// Capturas anotadas para la Ayuda de /gestion-tienda/ (buckler-core/assets/img/ayuda/*.webp).
// Uso (servidor local en marcha):  cd tools/local/tests && NODE_PATH=$(npm root -g) node ../help-shots.js
// Prepara datos de ejemplo (descuentos, carritos, tallas por agotarse), toma cada pantalla a 1280 px SIN marcas y
// guarda dónde va cada número en assets/img/ayuda/marks.json (% de la imagen); la gestión las pinta encima y las
// ilumina con el paso. Al final deja los datos como estaban. Los pasos viven en gestion-more.js (MODULES): el número
// de cada marca es el número del paso.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const B = 'http://localhost:8899';
const OUT = path.resolve(__dirname, '../../buckler-core/assets/img/ayuda');
const TMP = '/tmp/claude-0/bk-help-shots';
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString();
// Fecha y hora de Colombia (UTC-5, sin horario de verano) en el formato de los campos de la gestión.
const iso = (d) => new Date(d.getTime() - 5 * 3600e3).toISOString().slice(0, 16);

// Textos que en local salen en inglés (sin traducciones de WooCommerce) o con nombres de prueba.
const DICT = [
  ['Processing order', 'Pedido en proceso'], ['Completed order', 'Pedido completado'], ['Cancelled order', 'Pedido cancelado'],
  ['Failed order', 'Pedido fallido'], ['Refunded order', 'Pedido reembolsado'], ['Order on-hold', 'Pedido en espera'],
  ['Order details', 'Detalles del pedido'], ['Customer note', 'Nota para la clienta'],
  ['Pending payment', 'Pendiente de pago'], ['Processing', 'Procesando'], ['On hold', 'En espera'], ['Completed', 'Completado'],
  ['Cancelled', 'Cancelado'], ['Refunded', 'Reembolsado'], ['Failed', 'Fallido'], ['Draft', 'Borrador'],
  ['Pago contra entrega (prueba)', 'Pago contra entrega'], ['Prueba Páginas', 'Camila Restrepo'], ['Prueba Ronda17', 'Mariana Gil'],
  ['Thank you for your order', 'Gracias por tu pedido'], ['Hi Laura,', 'Hola Laura,'],
  ['We’ve received your order and it’s currently on hold until we can confirm your payment has been processed.', 'Recibimos tu pedido. Queda en espera hasta confirmar el pago.'],
  ['Here’s a reminder of what you’ve ordered:', 'Esto es lo que pediste:'], ['Pay with cash upon delivery.', 'Pago contra entrega.'],
  ['Order summary', 'Resumen del pedido'], ['Your Buckler Local order has been received!', '¡Recibimos tu pedido en Buckler!'],
  ['October', 'octubre'], ['<admin@example.com>', 'Buckler Jeans'], ['Email "', 'Correo "'], ['" sent.', '" enviado.'], ['· system', '· sistema'], ['Buckler Local', 'Buckler'], ['admin', 'Gestora'],
];

const O = '.bk-g-orders tbody tr:first-child';
const P1 = '.bk-g-plist > li:first-child';
const SHOTS = [
  // --- Pantallas (Conoce la pantalla) ---
  { name: 'resumen', hash: 'resumen', marks: [
    ['.bk-g-tabs', 1], [['.bk-g-kpi:nth-child(1)', '.bk-g-kpi:nth-child(2)'], 2], ['a.bk-g-kpi.is-link', 3, true], [{ t: 'Tallas por agotarse', c: '.bk-g-card' }, 4], ['.bk-g-user', 5],
  ] },
  { name: 'pedidos', hash: 'pedidos', marks: [
    ['.bk-g-filters .bk-g-search', 1], ['.bk-g-filters label:not(.bk-g-search)', 2, true, { row1: true }],
    [`${O} td:first-child [data-order]`, 3], [[`${O} td:nth-child(6)`, `${O} td:nth-child(8)`], 4], [`${O} td:last-child`, 5],
  ] },
  { name: 'pedido', hash: 'pedidos', open: true, marks: [
    ['.bk-g-dbody select', 1, false, { first: true }], ['.bk-g-dbody .bk-g-row2 .bk-g-switch', 2, true, { pos: 'r' }], ['.bk-g-dactions', 3, false, { first: true }], ['.bk-g-ship', 4], ['.bk-g-cust', 5],
  ] },
  { name: 'pedido-historial', hash: 'pedidos', open: true, scroll: '.bk-g-fsec', marks: [
    ['.bk-g-fsec', 1], ['.bk-g-hist', 2], ['.bk-g-notes', 3, false, { closest: 'section' }],
  ] },
  { name: 'productos', hash: 'productos', hidePrueba: true, marks: [
    [['.bk-g-filters .bk-g-search', '.bk-g-filters .bk-g-btn'], 1], [`${P1} .bk-g-star`, 2], [`${P1} [data-sizes]`, 3], [`${P1} a.bk-g-btn`, 4], ['.bk-g-h1row a.bk-g-btn', 5],
  ] },
  { name: 'producto-editar', hash: 'productos/editar/22', top: true, marks: [
    [['[name="name"]', '[name="sku"]'], 1, false, { closest: 'label' }], [{ t: 'Prenda', c: 'fieldset' }, 2], [{ t: 'Mostrar como', c: 'label' }, 3],
    [{ t: 'Precio', c: '.bk-g-card', h: 'h2' }, 4], [{ t: 'Tallas y stock', c: '.bk-g-card', h: 'h2' }, 5],
  ] },
  { name: 'descuentos-tienda', front: '/product/jean-bota-campana-flare-dama-tiro-alto/', marks: [
    ['.bk-announce', 1], ['.summary .price', 2, false, { first: true }], ['.bk-disc-box', 3],
  ] },
  { name: 'carritos', hash: 'carritos', marks: [
    ['.bk-g-kpis', 1], ['.bk-g-filters', 2], ['.bk-g-carts tbody tr:first-child .c-cust', 3], ['.bk-g-carts tbody tr:nth-child(-n+4) td:last-child', 4, true],
  ] },
  { name: 'seo', hash: 'seo', marks: [
    ['.bk-g-alert', 1], ['.bk-g-subtabs', 2], ['.bk-g-kpis', 3, false, { first: true }], ['.bk-g-h2row select', 4],
  ] },
  { name: 'seo-etiquetas', hash: 'seo', tab: 'Etiquetas Google y Meta', wait: 9000, marks: [
    ['.bk-g-subtabs a[aria-current], .bk-g-subtabs [aria-selected="true"], .bk-g-subtabs .is-on', 1, false, { first: true }],
    [{ t: 'Revisar ahora', c: 'button' }, 2], ['.bk-g-tagrow, .bk-g-tags, .bk-g-table', 3, false, { first: true, maxH: 420 }],
  ] },
  // --- Tareas (Cómo hacerlo) ---
  { name: 'g-pedidos-dia', hash: 'pedidos', marks: [
    ['[data-orders-refresh]', 1], [`${O} td:nth-child(6)`, 2], [`${O} td:last-child`, 3], [`${O} td:nth-child(8)`, 4], [`${O} td:first-child [data-order]`, 5],
  ] },
  { name: 'g-guia', hash: 'pedidos', open: '.bk-g-orders tbody tr:not(:has(.is-completed)) td:first-child [data-order]', scroll: '.bk-g-ship', marks: [
    ['.bk-g-ship [name="carrier"]', 1, false, { closest: 'label' }], ['.bk-g-ship [name="number"]', 2, false, { closest: 'label' }],
    ['.bk-g-ship [name="complete"]', 3, false, { closest: 'label' }], ['#bk-g-shipform [type="submit"]', 4],
  ] },
  { name: 'g-buscar', hash: 'pedidos', fill: { q: 'Laura' }, marks: [
    ['.bk-g-filters .bk-g-search', 1], ['.bk-g-filters label:not(.bk-g-search)', 2, true, { row1: true }],
  ] },
  { name: 'g-estado', hash: 'pedidos', open: true, marks: [
    ['#bk-g-ostatus', 1, false, { closest: 'label' }], ['.bk-g-dbody .bk-g-row2 .bk-g-switch', 2, true, { pos: 'r' }], ['#bk-g-osave', 3],
  ] },
  { name: 'g-cliente', hash: 'pedidos', open: true, click: ['[data-cust-edit]'], scroll: '.bk-g-cust', marks: [
    ['[data-cust-edit]', 1, false, { pos: 'r' }], ['.bk-g-custgrid', 2, false, { first: true }], ['#bk-g-custform [name="same"]', 3, false, { closest: 'label' }], ['#bk-g-custform [type="submit"]', 4],
  ] },
  { name: 'g-pago-pendiente', hash: 'pedidos', open: '.bk-g-orders tbody tr:has(.is-pending) td:first-child [data-order]', scroll: '#bk-g-payrem', marks: [
    ['#bk-g-payrem h3', 1], ['.bk-g-pptl', 2], ['[data-pp="preview"]', 3], ['[data-pp="copy"]', 4], ['[data-pp="stop"]', 5, false, { pos: 'r' }],
  ] },
  { name: 'g-archivos', hash: 'pedidos', open: true, scroll: '.bk-g-fsec', marks: [
    ['.bk-g-fsec h3', 1], ['.bk-g-upload', 2],
  ] },
  { name: 'g-escribir', hash: 'pedidos', open: true, scroll: '.bk-g-cust', marks: [
    ['.bk-g-cust__view a[href*="wa.me"]', 1],
  ] },
  { name: 'g-escribir-2', hash: 'pedidos', open: true, scroll: '#bk-g-onote', marks: [
    ['#bk-g-notetext', 2], ['#bk-g-notecust', 3, false, { closest: 'label' }], ['#bk-g-onote [type="submit"]', 4],
  ] },
  { name: 'g-prod-nuevo-1', hash: 'productos/nuevo', top: true, marks: [
    ['[name="name"]', 1, false, { closest: 'label' }], ['[name="sku"]', 2, false, { closest: 'label' }], ['[data-field="line"]', 3], ['[data-field="cats"]', 4],
    [{ t: 'Mostrar como', c: 'label' }, 5], [{ t: 'Precio', c: '.bk-g-card', h: 'h2' }, 6], ['[data-field="sizes"]', 7, false, { maxH: 260 }],
  ] },
  { name: 'g-prod-nuevo-2', hash: 'productos/nuevo', scroll: '[data-field="images"]', marks: [
    ['.bk-gp-add', 8], [{ t: 'Descripción', c: '.bk-g-card', h: 'h2' }, 9], ['.bk-gp-btns', 10],
  ] },
  { name: 'g-fotos', hash: 'productos/editar/22', scroll: '[data-field="images"]', marks: [
    ['.bk-gp-add', 1], ['.bk-gp-img:nth-child(2) .bk-gp-imgbar [data-img-move]', 2, true], ['.bk-gp-img:first-child .bk-gp-imgrot', 3, false, { pos: 'r' }],
    ['.bk-gp-img:nth-child(2) [data-img-del]', 4, false, { pos: 'r' }], ['.bk-gp-btns', 5],
  ] },
  { name: 'g-otra-linea', hash: 'productos/editar/22', scroll: '.bk-gp-links', marks: [
    ['.bk-gp-links a[href*="desde"]', 1],
  ] },
  { name: 'g-otra-linea-2', hash: 'productos/nuevo?desde=22', top: true, marks: [
    ['.bk-gp-note', 2], [['[name="name"]', '[name="sku"]'], 3, false, { closest: 'label' }], ['[data-field="sizes"]', 4, false, { maxH: 260 }],
  ] },
  { name: 'g-precio', hash: 'productos', hidePrueba: true, click: [`${P1} [data-sizes]`], waitFor: '.bk-g-vtable', marks: [
    [`${P1} [data-sizes]`, 1], [`${P1} .bk-g-vtable tbody tr:first-child td:nth-child(2)`, 2], [`${P1} .bk-g-vtable tbody tr:first-child td:nth-child(3)`, 3],
    [`${P1} .bk-g-vtable tbody tr:first-child [data-vsave]`, 4, false, { pos: 'r' }],
  ] },
  { name: 'g-stock', hash: 'productos', hidePrueba: true, click: [`${P1} [data-sizes]`], waitFor: '.bk-g-vtable', marks: [
    ['.bk-g-filters .bk-g-search', 1], [`${P1} [data-sizes]`, 2], [`${P1} .bk-g-vtable tbody tr:first-child td:nth-child(4)`, 3],
    [`${P1} .bk-g-vtable tbody tr:first-child [data-vsave]`, 4, false, { pos: 'r' }],
  ] },
  { name: 'g-nuevo', hash: 'productos', hidePrueba: true, marks: [[`${P1} .bk-g-star`, 1]] },
  { name: 'g-ocultar', hash: 'productos/editar/22', scroll: '.bk-gp-actions', marks: [['[name="status"]', 1, false, { closest: 'label' }], ['.bk-gp-btns', 2]] },
  { name: 'g-camp-1', hash: 'descuentos', marks: [[{ t: 'Nueva campaña', c: 'a', h: 'a' }, 1]] },
  { name: 'g-camp-2', hash: 'descuentos/campana/nuevo', fill: { name: 'Fin de semana Buckler', pct: '20' }, marks: [
    ['[name="name"]', 2, false, { closest: 'label' }], ['[name="pct"]', 3, false, { closest: 'label' }], ['.bk-g-row2', 4, false, { first: true }],
    [{ t: 'A qué productos', c: '.bk-g-card', h: 'h2' }, 5, false, { maxH: 260 }], [{ t: 'Activo', c: 'label' }, 6],
  ] },
  { name: 'g-cant-1', hash: 'descuentos', marks: [[{ t: 'Nuevo descuento por cantidad', c: 'a', h: 'a' }, 1]] },
  { name: 'g-cant-2', hash: 'descuentos/cantidad/nuevo', fill: { name: 'Lleva más y ahorra' }, marks: [
    ['[name="name"]', 2, false, { closest: 'label' }], ['#bk-gd-tiers', 3, false, { closest: 'fieldset' }], ['[data-tadd]', 4], [{ t: 'Cómo se cuentan', c: 'fieldset' }, 5],
  ] },
  { name: 'g-desc-lista', hash: 'descuentos', marks: [
    ['.bk-gd-item__act [data-dtoggle]', 1, false, { first: true }], ['.bk-gd-item__act a', 2, false, { first: true }], ['.bk-gd-item__act [data-ddel]', 3, false, { first: true, pos: 'r' }],
  ] },
  { name: 'g-carritos', hash: 'carritos', marks: [
    ['[data-carts-refresh]', 1, false, { pos: 'r' }], ['.bk-g-carts tbody tr:first-child td:last-child', 2], ['.bk-g-carts tbody tr:first-child .c-cust', 3],
  ] },
  { name: 'g-correos', hash: 'correos', marks: [
    ['.bk-g-mail__list .bk-g-field', 1], ['.bk-g-mail__list .bk-g-mitem', 2, false, { first: true }], ['.bk-g-pvframe', 3, false, { maxH: 320 }],
  ] },
  { name: 'g-correos-2', hash: 'correos', scroll: '#bk-g-mtest', marks: [['#bk-g-mtest', 4]] },
  { name: 'g-banners', hash: 'contenido', marks: [
    ['.bk-g-subtabs a:first-child', 1], ['.bk-gc-slide:first-child .bk-gc-slot:nth-child(1)', 2], ['.bk-gc-slide:first-child .bk-gc-slot:nth-child(2)', 3],
    ['.bk-gc-slide:first-child .bk-gc-slide__head', 6],
  ] },
  { name: 'g-banners-2', hash: 'contenido', scroll: '.bk-gc-slide:first-child [data-balt]', marks: [
    ['.bk-gc-slide:first-child [data-balt]', 4, false, { closest: 'label' }], ['.bk-gc-slide:first-child [data-blink]', 5, false, { closest: 'label' }],
  ] },
  { name: 'g-tallas', hash: 'contenido/guia', marks: [['.bk-g-subtabs a:nth-child(2)', 1]] },
  { name: 'g-tallas-2', hash: 'contenido/guia', scroll: '.bk-gc-slot:first-child .bk-gc-slot__bar', marks: [
    ['.bk-gc-slot:first-child .bk-gc-slot__bar', 2], ['[name="guide_alt"]', 3, false, { closest: 'label' }], ['#bk-gcg [type="submit"]', 4],
  ] },
  { name: 'g-faq-1', hash: 'contenido/preguntas', marks: [
    ['.bk-g-subtabs a:last-child', 1], ['#bk-gc .bk-g-h2row a.bk-g-btn', 2, false, { pos: 'r' }], ['.bk-gc-post .bk-gd-item__act a.bk-g-btn', 3, false, { first: true }],
  ] },
  { name: 'g-faq-2', hash: 'contenido/preguntas', click: ['.bk-gc-post .bk-gd-item__act a.bk-g-btn'], marks: [
    ['#bk-gcp [name="title"]', 4, false, { closest: 'label' }], ['#bk-gc-text', 5, false, { closest: '.bk-gp-field', maxH: 220 }], [{ t: 'Publicada', c: 'label' }, 6], ['#bk-gcp [type="submit"]', 7],
  ] },
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  fs.rmSync(TMP, { recursive: true, force: true });
  fs.mkdirSync(TMP, { recursive: true });
  const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null; // ONLY=g-fotos,g-guia → solo esas.
  const MARKS = fs.existsSync(`${OUT}/marks.json`) ? JSON.parse(fs.readFileSync(`${OUT}/marks.json`, 'utf8')) : {};

  // --- Datos de ejemplo (se restauran al final) ---------------------------------------------------
  const saved = JSON.parse(php(`echo json_encode(["disc"=>get_option("bk_discounts", null)]);`));
  const low = JSON.parse(php(`$out=[]; foreach([24,26] as $i=>$id){ $v=wc_get_product($id); if($v){ $out[$id]=$v->get_stock_quantity(); $v->set_stock_quantity($i+1); $v->save(); } } delete_transient("bk_g_summary"); echo json_encode($out);`));
  php(`global $wpdb; $t=bk_ac_table(); $wpdb->query("DELETE FROM $t WHERE email LIKE \\"%@ejemplo.co\\""); $p=wc_get_product(22); $img=wp_get_attachment_image_url($p->get_image_id(),"woocommerce_thumbnail"); $cart=wp_json_encode([["product_id"=>22,"variation_id"=>24,"quantity"=>1,"variation"=>["attribute_pa_tallas-plus-size"=>["label"=>"Talla","value"=>"16","raw"=>"16"]],"name"=>$p->get_name(),"line_total"=>149900,"image"=>$img]]); $now=time(); foreach([["Sara López","sara@ejemplo.co","converted",1,0,50],["Daniela Muñoz","daniela@ejemplo.co","recovered",1,2,30],["Paula Herrera","paula@ejemplo.co","active",0,0,5],["Valentina Ríos","valentina@ejemplo.co","active",1,1,3]] as $i=>$r){ $d=gmdate("Y-m-d H:i:s",$now-$r[5]*3600); $wpdb->insert($t,["token"=>bin2hex(random_bytes(16)),"session_key"=>"demo$i","email"=>$r[1],"first_name"=>$r[0],"phone"=>"300000000$i","cart"=>$cart,"cart_total"=>149900,"currency"=>"COP","consent"=>$r[3],"status"=>$r[2],"emails_sent"=>$r[4],"clicked_at"=>$r[2]==="recovered"?$d:null,"created_at"=>$d,"updated_at"=>$d]); }`);

  const ppOrder = php(`$o=wc_create_order(["created_via"=>"checkout"]); $o->add_product(wc_get_product(24),1); $o->set_billing_first_name("Valentina"); $o->set_billing_last_name("Ríos"); $o->set_billing_email("valentina@ejemplo.co"); $o->set_billing_phone("3001112233"); $o->set_billing_state("CO-ANT"); $o->set_billing_address_1("Cra 43A # 1-50"); $o->calculate_totals(); $o->set_status("pending"); $o->set_date_created(time()-50*60); $o->save(); bk_pp_track($o); bk_pp_send($o->get_id(), 1); echo $o->get_id();`).trim();
  const b = await chromium.launch({ args: ['--lang=es-CO'] });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 860 }, locale: 'es-CO', timezoneId: 'America/Bogota' });
  const p = await ctx.newPage();
  const front = await (await b.newContext({ viewport: { width: 1280, height: 860 }, locale: 'es-CO' })).newPage(); // Clienta sin sesión.
  await p.goto(B + '/gestion-tienda/'); await p.fill('[name=log]', 'admin'); await p.fill('[name=pwd]', 'admin');
  await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]); await p.waitForSelector('.bk-g-tabs');

  // Descuentos de ejemplo con la misma API de la gestión.
  const now = new Date();
  await p.evaluate(async ({ s, e }) => {
    const post = (body) => fetch(window.bkG.api + 'discounts', { method: 'POST', headers: { 'X-WP-Nonce': window.bkG.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    await post({ type: 'campaign', rule: { name: 'Temporada de jeans', pct: 20, start: s, end: e, active: true, scope: 'all', exclude_sale: true, badge: '-20 %', bar: true, home: true, featured: [] } });
    await post({ type: 'qty', rule: { name: 'Lleva más y ahorra', tiers: [{ min: 2, pct: 25 }, { min: 3, pct: 30 }], count: 'product', start: s, end: e, active: true, scope: 'all', exclude_sale: true } });
  }, { s: iso(new Date(now.getTime() - 3600e3)), e: iso(new Date(now.getTime() + 10 * 86400e3)) });

  const translate = async (pg) => {
    for (const f of pg.frames()) {
      await f.evaluate((dict) => {
        const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const nodes = []; while (w.nextNode()) nodes.push(w.currentNode);
        for (const n of nodes) { let v = n.nodeValue; for (const [a, z] of dict) if (v.includes(a)) v = a === 'admin' ? (v.trim() === 'admin' ? z : v) : v.split(a).join(z); if (v !== n.nodeValue) n.nodeValue = v; }
        // Formato de fecha de Colombia (Chromium sin interfaz muestra mm/dd/yyyy aunque se pida es-CO).
        document.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach((i) => {
          const v = i.value; const [d, t] = v.split('T');
          const f = d ? d.split('-').reverse().join('/') + (t ? `, ${(+t.slice(0, 2) % 12) || 12}:${t.slice(3, 5)} ${+t.slice(0, 2) < 12 ? 'a. m.' : 'p. m.'}` : '') : '';
          i.type = 'text'; i.value = f; i.placeholder = 'dd/mm/aaaa';
        });
        // Logo (en local no está el archivo del sitio): el del correo, que es el mismo.
        document.querySelectorAll('img.bk-logo__img').forEach((im) => { im.removeAttribute('srcset'); im.removeAttribute('width'); im.removeAttribute('height'); im.style.height = 'auto'; im.src = '/wp-content/plugins/buckler-core/assets/img/logo-email.png'; });
        document.querySelectorAll('option').forEach((o) => { for (const [a, z] of dict) if (a !== 'admin' && o.text.includes(a)) o.text = o.text.split(a).join(z); });
      }, DICT).catch(() => {});
    }
  };

  try {
  for (const s of SHOTS.filter((x) => !ONLY || ONLY.includes(x.name))) {
    const pg = s.front ? front : p;
    if (s.front) {
      await front.goto(B + s.front, { waitUntil: 'load' }); await front.mouse.move(5, 5); await front.waitForTimeout(1500);
      await front.evaluate(() => scrollTo(0, 0));
    } else {
      await p.goto('about:blank'); // Carga limpia: sin el panel lateral de la captura anterior.
      await p.goto(B + '/gestion-tienda/#' + s.hash); await p.waitForTimeout(2200);
      if (s.open) { await p.locator(typeof s.open === 'string' ? s.open : '.bk-g-orders tbody tr:first-child td:first-child [data-order]').first().click(); await p.waitForTimeout(2200); }
      if (s.tab) { await p.getByRole('link', { name: s.tab }).or(p.getByRole('button', { name: s.tab })).first().click(); await p.waitForTimeout(s.wait || 2500); }
      if (s.fill) for (const [k, v] of Object.entries(s.fill)) await p.fill(`[name="${k}"]`, v);
      if (s.hidePrueba) await p.evaluate(() => document.querySelectorAll('.bk-g-plist > li').forEach((li) => { if (/PRUEBA/.test(li.textContent)) li.remove(); }));
      for (const c of s.click || []) { await p.locator(c).first().click(); await p.waitForTimeout(1800); }
      if (s.waitFor) await p.waitForSelector(s.waitFor, { timeout: 15000 }).catch(() => {});
    }
    await translate(pg);
    await pg.evaluate(() => document.activeElement && document.activeElement.blur());
    const res = await pg.evaluate(({ marks, scroll, top }) => {
      const find = (spec, opt = {}) => {
        let els = [];
        if (typeof spec === 'string') els = [...document.querySelectorAll(spec)];
        else {
          const root = spec.in ? document.querySelector(spec.in) : document;
          const cands = [...(root || document).querySelectorAll(spec.h || 'h1,h2,h3,legend,label,button,span,b,summary')];
          const hit = cands.find((e) => e.textContent.trim().startsWith(spec.t));
          if (hit) els = [spec.c ? (hit.closest(spec.c) || hit) : hit];
        }
        els = els.filter((e) => e.getClientRects().length);
        if (opt.closest) els = els.map((e) => e.closest(opt.closest) || e);
        if (opt.row1 && els.length) { const t0 = els[0].getBoundingClientRect().top; els = els.filter((e) => Math.abs(e.getBoundingClientRect().top - t0) < 30); }
        return opt.first ? els.slice(0, 1) : els;
      };
      // Desplazamiento: dentro del panel lateral o de la página (debajo del header fijo).
      const scrollTo = (el) => {
        const box = el.closest('.bk-g-dbody');
        if (box) box.scrollTop += el.getBoundingClientRect().top - box.getBoundingClientRect().top - 12;
        else window.scrollBy(0, el.getBoundingClientRect().top - 84);
      };
      if (top) window.scrollTo(0, 0);
      if (scroll) { const el = find(scroll)[0]; if (el) scrollTo(el); }
      const out = []; let bottom = 0; const missing = [];
      for (const [spec, n, all, opt = {}] of marks) {
        const list = (Array.isArray(spec) ? spec.flatMap((x) => find(x, { ...opt, first: true })) : find(spec, opt)).filter(Boolean);
        const rects = (Array.isArray(spec) || all ? list : list.slice(0, 1)).map((e) => e.getBoundingClientRect()).filter((x) => x.bottom > 0 && x.top < innerHeight);
        if (!rects.length) { missing.push(n); continue; }
        const r = { l: Math.min(...rects.map((x) => x.left)), t: Math.min(...rects.map((x) => x.top)), r: Math.max(...rects.map((x) => x.right)), b: Math.max(...rects.map((x) => x.bottom)) };
        if (opt.maxH) r.b = Math.min(r.b, r.t + opt.maxH);
        r.b = Math.min(r.b, innerHeight - 2);
        const pad = 4;
        out.push({ n, l: r.l - pad, t: r.t - pad, w: r.r - r.l + pad * 2, h: r.b - r.t + pad * 2, r: opt.pos === 'r' || r.l < 20 ? 1 : 0 });
        bottom = Math.max(bottom, r.b + pad);
      }
      return { bottom, missing, out };
    }, { marks: s.marks, scroll: s.scroll, top: s.top });
    const h = Math.round(Math.min(860, Math.max(440, res.bottom + 28)));
    await pg.screenshot({ path: `${TMP}/${s.name}.png`, clip: { x: 0, y: 0, width: 1280, height: h } });
    const pct = (v, t) => Math.round((v / t) * 10000) / 100;
    MARKS[s.name] = { h, marks: res.out.map((m) => ({ n: m.n, x: pct(Math.max(0, m.l), 1280), y: pct(Math.max(0, m.t), h), w: pct(Math.min(m.w, 1280 - Math.max(0, m.l)), 1280), h: pct(Math.min(m.h, h - Math.max(0, m.t)), h), ...(m.r ? { r: 1 } : {}) })).sort((a, b) => a.n - b.n) };
    console.log(`${res.missing.length ? 'FALTAN ' + res.missing.join(',') : 'OK    '} ${s.name} (${h}px)`);
  }
  } finally {
  await b.close();

  // --- Restaurar datos ---------------------------------------------------------------------------------
  const disc = saved.disc === null ? 'delete_option("bk_discounts");' : `update_option("bk_discounts", json_decode(base64_decode("${Buffer.from(JSON.stringify(saved.disc)).toString('base64')}"), true));`;
  php(`$o=wc_get_order(${ppOrder}); if($o){ bk_pp_unschedule($o->get_id()); $o->delete(true); }`);
  php(`${disc} global $wpdb; $t=bk_ac_table(); $wpdb->query("DELETE FROM $t WHERE email LIKE \\"%@ejemplo.co\\""); foreach(json_decode(base64_decode("${Buffer.from(JSON.stringify(low)).toString('base64')}"), true) as $id=>$q){ $v=wc_get_product($id); if($v){ $v->set_stock_quantity($q); $v->save(); } } delete_transient("bk_g_summary"); if(function_exists("bk_disc_flush")) bk_disc_flush();`);
  }

  fs.writeFileSync(`${OUT}/marks.json`, JSON.stringify(MARKS));
  // Capturas que ya no se usan.
  for (const f of fs.readdirSync(OUT)) if (f.endsWith('.webp') && !SHOTS.some((x) => `${x.name}.webp` === f)) fs.rmSync(`${OUT}/${f}`);

  // --- WebP (calidad 72; 1280 px de ancho) ----------------------------------------------------------------
  execSync(`python3 -c "
import sys,glob,os
from PIL import Image
for f in glob.glob('${TMP}/*.png'):
    out=os.path.join('${OUT}',os.path.basename(f)[:-4]+'.webp')
    Image.open(f).convert('RGB').save(out,'WEBP',quality=72,method=6)
    print(os.path.basename(out), os.path.getsize(out)//1024, 'KB', Image.open(f).size)
"`, { stdio: 'inherit' });
})().catch((e) => { console.error(e); process.exit(1); });
