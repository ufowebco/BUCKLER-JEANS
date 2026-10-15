// Capturas anotadas para la Ayuda de /gestion-tienda/ (buckler-core/assets/img/ayuda/*.webp).
// Uso (servidor local en marcha):  cd tools/local/tests && NODE_PATH=$(npm root -g) node ../help-shots.js
// Prepara datos de ejemplo (descuentos, carritos, tallas por agotarse), toma cada pantalla a 1280 px, dibuja
// números sobre lo que explica cada paso y al final deja los datos como estaban. Los textos de los pasos viven en
// gestion-more.js (HELP): los números de aquí deben coincidir con esa lista.
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

const SHOTS = [
  { name: 'resumen', hash: 'resumen', marks: [
    ['.bk-g-tabs', 1], [['.bk-g-kpi:nth-child(1)', '.bk-g-kpi:nth-child(2)'], 2], ['a.bk-g-kpi.is-link', 3, true], [{ t: 'Tallas por agotarse', c: '.bk-g-card' }, 4], ['.bk-g-user', 5],
  ] },
  { name: 'pedidos', hash: 'pedidos', marks: [
    ['.bk-g-filters .bk-g-search', 1], ['.bk-g-filters label:not(.bk-g-search)', 2, true, { row1: true }],
    ['.bk-g-orders tbody tr:first-child td:first-child [data-order]', 3], [['.bk-g-orders tbody tr:first-child td:nth-child(6)', '.bk-g-orders tbody tr:first-child td:nth-child(8)'], 4],
    ['.bk-g-orders tbody tr:first-child td:last-child', 5],
  ] },
  { name: 'pedido', hash: 'pedidos', open: true, marks: [
    ['.bk-g-dbody select', 1, false, { first: true }], ['.bk-g-dbody .bk-g-row2 .bk-g-switch', 2, true, { pos: 'r' }], ['.bk-g-dactions', 3], ['.bk-g-ship', 4], [{ t: 'Clienta', c: 'section', in: '.bk-g-dbody' }, 5],
  ] },
  { name: 'pedido-historial', hash: 'pedidos', open: true, scroll: '.bk-g-fsec', marks: [
    ['.bk-g-fsec', 1], ['.bk-g-hist', 2], ['.bk-g-notes', 3, false, { closest: 'section' }],
  ] },
  { name: 'productos', hash: 'productos', hidePrueba: true, marks: [
    [['.bk-g-filters .bk-g-search', '.bk-g-filters .bk-g-btn'], 1], ['.bk-g-plist > li:first-child .bk-g-star', 2],
    ['.bk-g-plist > li:first-child [data-sizes]', 3], ['.bk-g-plist > li:first-child a.bk-g-btn', 4], ['.bk-g-h1row a.bk-g-btn', 5],
  ] },
  { name: 'producto-editar', hash: 'productos/editar/22', top: true, marks: [
    [['[name="name"]', '[name="sku"]'], 1, false, { closest: 'label' }], [{ t: 'Prenda', c: 'fieldset' }, 2], [{ t: 'Mostrar como', c: 'label' }, 3],
    [{ t: 'Precio', c: '.bk-g-card', h: 'h2' }, 4], [{ t: 'Tallas y stock', c: '.bk-g-card', h: 'h2' }, 5],
  ] },
  { name: 'producto-fotos', hash: 'productos/editar/22', scroll: { t: 'Fotos y video', c: '.bk-g-card', h: 'h2' }, marks: [
    [{ t: 'Fotos y video', c: '.bk-g-card', h: 'h2' }, 1], [{ t: 'Descripción', c: '.bk-g-card', h: 'h2' }, 2], ['.bk-gp-actions', 3],
  ] },
  { name: 'descuentos', hash: 'descuentos', marks: [
    ['.bk-g-h2row a.bk-g-btn', 1, false, { first: true }], ['.bk-gd-list .bk-gd-item', 2, false, { first: true }], ['.bk-gd-item__act', 3, false, { first: true }],
    [{ t: 'Descuento por cantidad', c: '.bk-g-card', h: 'h2' }, 4],
  ] },
  { name: 'descuento-campana', hash: 'descuentos/campana/nuevo', fill: { name: 'Temporada de jeans', pct: '20' }, marks: [
    ['[name="name"]', 1, false, { closest: 'label' }], ['[name="pct"]', 2, false, { closest: 'label' }], ['.bk-g-row2', 3, false, { first: true }],
    [{ t: 'Activo', c: 'label' }, 4], [{ t: 'A qué productos', c: '.bk-g-card', h: 'h2' }, 5],
  ] },
  { name: 'descuento-cantidad', hash: 'descuentos/cantidad/nuevo', marks: [
    ['#bk-gd-tiers', 1, false, { closest: 'fieldset' }], ['[data-tadd]', 2], ['#bk-gd-tprev', 3], [{ t: 'Cómo se cuentan', c: 'fieldset' }, 4],
  ] },
  { name: 'descuentos-tienda', front: '/product/jean-bota-campana-flare-dama-tiro-alto/', marks: [
    ['.bk-announce', 1], ['.summary .price', 2, false, { first: true }], ['.bk-disc-box', 3],
  ] },
  { name: 'carritos', hash: 'carritos', marks: [
    ['.bk-g-kpis', 1], ['.bk-g-filters', 2], ['.bk-g-carts tbody tr:first-child .c-cust', 3], ['.bk-g-carts tbody tr:nth-child(-n+4) td:last-child', 4, true],
  ] },
  { name: 'correos', hash: 'correos', marks: [
    ['.bk-g-mail__list .bk-g-field', 1], ['.bk-g-mail__list .bk-g-mitem', 2, false, { first: true }], ['.bk-g-pvmeta', 3], ['.bk-g-pvframe', 4, false, { maxH: 380 }],
  ] },
  { name: 'contenido', hash: 'contenido', marks: [
    ['.bk-g-subtabs', 1], ['.bk-gc-slide', 2, false, { first: true, maxH: 330 }], [{ t: 'Subir', c: 'label', in: '.bk-gc-slide' }, 3],
  ] },
  { name: 'seo', hash: 'seo', marks: [
    ['.bk-g-alert', 1], ['.bk-g-subtabs', 2], ['.bk-g-kpis', 3, false, { first: true }], ['.bk-g-h2row select', 4],
  ] },
  { name: 'seo-etiquetas', hash: 'seo', tab: 'Etiquetas Google y Meta', wait: 9000, marks: [
    ['.bk-g-subtabs a[aria-current], .bk-g-subtabs [aria-selected="true"], .bk-g-subtabs .is-on', 1, false, { first: true }],
    [{ t: 'Revisar ahora', c: 'button' }, 2], ['.bk-g-tagrow, .bk-g-tags, .bk-g-table', 3, false, { first: true, maxH: 420 }],
  ] },
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  fs.mkdirSync(TMP, { recursive: true });

  // --- Datos de ejemplo (se restauran al final) ---------------------------------------------------
  const saved = JSON.parse(php(`echo json_encode(["disc"=>get_option("bk_discounts", null)]);`));
  const low = JSON.parse(php(`$out=[]; foreach([24,26] as $i=>$id){ $v=wc_get_product($id); if($v){ $out[$id]=$v->get_stock_quantity(); $v->set_stock_quantity($i+1); $v->save(); } } delete_transient("bk_g_summary"); echo json_encode($out);`));
  php(`global $wpdb; $t=bk_ac_table(); $wpdb->query("DELETE FROM $t WHERE email LIKE \\"%@ejemplo.co\\""); $p=wc_get_product(22); $img=wp_get_attachment_image_url($p->get_image_id(),"woocommerce_thumbnail"); $cart=wp_json_encode([["product_id"=>22,"variation_id"=>24,"quantity"=>1,"variation"=>["attribute_pa_tallas-plus-size"=>["label"=>"Talla","value"=>"16","raw"=>"16"]],"name"=>$p->get_name(),"line_total"=>149900,"image"=>$img]]); $now=time(); foreach([["Sara López","sara@ejemplo.co","converted",1,0,50],["Daniela Muñoz","daniela@ejemplo.co","recovered",1,2,30],["Paula Herrera","paula@ejemplo.co","active",0,0,5],["Valentina Ríos","valentina@ejemplo.co","active",1,1,3]] as $i=>$r){ $d=gmdate("Y-m-d H:i:s",$now-$r[5]*3600); $wpdb->insert($t,["token"=>bin2hex(random_bytes(16)),"session_key"=>"demo$i","email"=>$r[1],"first_name"=>$r[0],"phone"=>"300000000$i","cart"=>$cart,"cart_total"=>149900,"currency"=>"COP","consent"=>$r[3],"status"=>$r[2],"emails_sent"=>$r[4],"clicked_at"=>$r[2]==="recovered"?$d:null,"created_at"=>$d,"updated_at"=>$d]); }`);

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
  for (const s of SHOTS) {
    const pg = s.front ? front : p;
    if (s.front) {
      await front.goto(B + s.front, { waitUntil: 'load' }); await front.mouse.move(5, 5); await front.waitForTimeout(1500);
      await front.evaluate(() => scrollTo(0, 0));
    } else {
      await p.goto('about:blank'); // Carga limpia: sin el panel lateral de la captura anterior.
      await p.goto(B + '/gestion-tienda/#' + s.hash); await p.waitForTimeout(2200);
      if (s.open) { await p.locator('.bk-g-orders tbody tr:first-child td:first-child [data-order]').click(); await p.waitForTimeout(2200); }
      if (s.tab) { await p.getByRole('link', { name: s.tab }).or(p.getByRole('button', { name: s.tab })).first().click(); await p.waitForTimeout(s.wait || 2500); }
      if (s.fill) for (const [k, v] of Object.entries(s.fill)) await p.fill(`[name="${k}"]`, v);
      if (s.hidePrueba) await p.evaluate(() => document.querySelectorAll('.bk-g-plist > li').forEach((li) => { if (/PRUEBA/.test(li.textContent)) li.remove(); }));
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
      document.querySelectorAll('.bk-help-mk').forEach((e) => e.remove());
      const layer = document.createElement('div'); layer.className = 'bk-help-mk';
      layer.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483647';
      document.body.appendChild(layer);
      let bottom = 0; const missing = [];
      for (const [spec, n, all, opt = {}] of marks) {
        const list = (Array.isArray(spec) ? spec.flatMap((x) => find(x, { ...opt, first: true })) : find(spec, opt)).filter(Boolean);
        if (!list.length) { missing.push(n); continue; }
        const rects = (Array.isArray(spec) || all ? list : list.slice(0, 1)).map((e) => e.getBoundingClientRect());
        const r = { l: Math.min(...rects.map((x) => x.left)), t: Math.min(...rects.map((x) => x.top)), r: Math.max(...rects.map((x) => x.right)), b: Math.max(...rects.map((x) => x.bottom)) };
        if (opt.maxH) r.b = Math.min(r.b, r.t + opt.maxH);
        const pad = 4;
        const box = document.createElement('div');
        box.style.cssText = `position:absolute;left:${r.l - pad}px;top:${r.t - pad}px;width:${r.r - r.l + pad * 2}px;height:${r.b - r.t + pad * 2}px;border:3px solid #1a3955;border-radius:12px;box-shadow:0 0 0 3px #e4ec6a`;
        const dot = document.createElement('div');
        dot.textContent = n;
        const dl = opt.pos === 'r' ? Math.min(innerWidth - 36, r.r + pad - 16) : Math.max(4, r.l - pad - 16);
        dot.style.cssText = `position:absolute;left:${dl}px;top:${Math.max(4, r.t - pad - 16)}px;width:32px;height:32px;border-radius:50%;background:#1a3955;color:#e4ec6a;font:800 17px/32px Inter,Arial,sans-serif;text-align:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)`;
        layer.append(box, dot);
        bottom = Math.max(bottom, r.b + pad);
      }
      return { bottom, missing };
    }, { marks: s.marks, scroll: s.scroll, top: s.top });
    const h = Math.round(Math.min(860, Math.max(440, res.bottom + 28)));
    await pg.screenshot({ path: `${TMP}/${s.name}.png`, clip: { x: 0, y: 0, width: 1280, height: h } });
    console.log(`${res.missing.length ? 'FALTAN ' + res.missing.join(',') : 'OK    '} ${s.name} (${h}px)`);
  }
  } finally {
  await b.close();

  // --- Restaurar datos ---------------------------------------------------------------------------------
  const disc = saved.disc === null ? 'delete_option("bk_discounts");' : `update_option("bk_discounts", json_decode(base64_decode("${Buffer.from(JSON.stringify(saved.disc)).toString('base64')}"), true));`;
  php(`${disc} global $wpdb; $t=bk_ac_table(); $wpdb->query("DELETE FROM $t WHERE email LIKE \\"%@ejemplo.co\\""); foreach(json_decode(base64_decode("${Buffer.from(JSON.stringify(low)).toString('base64')}"), true) as $id=>$q){ $v=wc_get_product($id); if($v){ $v->set_stock_quantity($q); $v->save(); } } delete_transient("bk_g_summary"); if(function_exists("bk_disc_flush")) bk_disc_flush();`);
  }

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
