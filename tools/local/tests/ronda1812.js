// 0.18.12: origen de la venta en la gestión (Meta Ads, Google orgánico, WhatsApp…) a partir de la atribución de
// WooCommerce: etiqueta en la lista, filtro "Origen", detalle con campaña y dispositivo, y resumen por canal.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

const CASES = {
  meta_ads: { source_type: 'utm', utm_source: 'facebook', utm_medium: 'paid', utm_campaign: 'octubre-curvy', device_type: 'Mobile' },
  meta: { source_type: 'referral', referrer: 'https://l.instagram.com/', device_type: 'Mobile' },
  google_ads: { source_type: 'utm', utm_source: 'google', utm_medium: 'cpc', session_entry: 'https://buckler.com.co/linea-curvy/?gclid=abc123' },
  google: { source_type: 'organic', utm_source: 'google', referrer: 'https://www.google.com/' },
  whatsapp: { source_type: 'referral', referrer: 'https://l.wl.co/' },
  direct: { source_type: 'typein', utm_source: '(direct)' },
  cart_email: { source_type: 'utm', utm_source: 'email', utm_medium: 'carrito-abandonado', utm_campaign: 'recordatorio-1' },
  unknown: {},
};

(async () => {
  php('foreach(wc_get_orders(["billing_email"=>"origen@ejemplo.com","limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); } delete_transient("bk_g_summary");');
  const ids = {};
  for (const [k, meta] of Object.entries(CASES)) {
    const set = Object.entries(meta).map(([mk, mv]) => `$o->update_meta_data("_wc_order_attribution_${mk}", ${JSON.stringify(mv)});`).join(' ');
    ids[k] = +php(`$o=wc_create_order(); $o->add_product(wc_get_product(24),1); $o->set_billing_first_name("Canal ${k}"); $o->set_billing_email("origen@ejemplo.com"); $o->set_payment_method_title("Prueba"); ${set} $o->calculate_totals(); $o->set_status("processing"); $o->save(); echo $o->get_id();`);
  }
  // Clasificación.
  const got = JSON.parse(php(`echo json_encode(array_map(function($id){ return bk_g_source(wc_get_order($id)); }, ${JSON.stringify(JSON.stringify(ids))} ? json_decode(${JSON.stringify(JSON.stringify(ids))}, true) : []));`));
  const bad = Object.keys(CASES).filter((k) => got[k].key !== k);
  ok(!bad.length, `cada pedido en su canal (${Object.keys(CASES).map((k) => `${k}→${got[k].key}`).join(', ')})`);
  ok(/Campaña: octubre-curvy/.test(got.meta_ads.detail) && /Celular/.test(got.meta_ads.detail), `detalle de Meta Ads: ${got.meta_ads.detail}`);

  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);

  // Resumen: ventas por canal.
  await g.goto(B + '/gestion-tienda/#resumen'); await g.waitForSelector('.bk-g-srcs', { timeout: 20000 }).catch(() => {});
  const rows = await g.$$eval('.bk-g-srcs li', (x) => x.map((l) => l.textContent.replace(/\s+/g, ' ').trim()));
  ok(rows.some((r) => /Meta Ads/.test(r)) && rows.some((r) => /Google orgánico/.test(r)) && rows.some((r) => /WhatsApp/.test(r)), `Resumen "¿De dónde vienen las ventas?": ${rows.length} canales`);

  // Lista: columna Origen.
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('.bk-g-orders');
  await g.fill('#bk-g-of [name=q]', 'origen@ejemplo.com'); await g.press('#bk-g-of [name=q]', 'Enter'); await g.waitForTimeout(2000);
  const chips = await g.$$eval('.bk-g-orders tbody .c-src', (x) => x.map((c) => c.textContent.trim()));
  ok(chips.length === 8 && chips.includes('Meta Ads') && chips.includes('Google Ads') && chips.includes('Sin dato') && chips.includes('Correo de la tienda'), `columna Origen en la lista (${chips.join(', ')})`);

  // Filtro por origen (con la búsqueda vacía).
  await g.fill('#bk-g-of [name=q]', '');
  await g.selectOption('#bk-g-of [name=source]', 'meta_ads'); await g.waitForTimeout(2000);
  const only = await g.$$eval('.bk-g-orders tbody .c-src', (x) => x.map((c) => c.textContent.trim()));
  ok(only.length >= 1 && only.every((x) => x === 'Meta Ads'), `filtro "Origen: Meta Ads" (${only.length} pedido)`);
  await g.selectOption('#bk-g-of [name=source]', 'unknown'); await g.waitForTimeout(2000);
  const unk = await g.$$eval('.bk-g-orders tbody .c-src', (x) => x.map((c) => c.textContent.trim()));
  ok(unk.length >= 1 && unk.every((x) => x === 'Sin dato'), `filtro "Sin dato" (${unk.length})`);
  await g.selectOption('#bk-g-of [name=source]', ''); await g.waitForTimeout(800);

  // Detalle.
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('.bk-g-orders');
  await g.fill('#bk-g-of [name=q]', String(ids.meta_ads)); await g.press('#bk-g-of [name=q]', 'Enter'); await g.waitForTimeout(1500);
  await g.click(`[data-order="${ids.meta_ads}"]`); await g.waitForSelector('.bk-g-srcline');
  ok(/Meta Ads/.test(await g.textContent('.bk-g-srcline')) && /octubre-curvy/.test(await g.textContent('.bk-g-srcline')), `detalle: ${(await g.textContent('.bk-g-srcline')).replace(/\s+/g, ' ').trim()}`);

  // Desde el resumen, el enlace lleva a la lista filtrada.
  await g.keyboard.press('Escape');
  await g.goto(B + '/gestion-tienda/#resumen'); await g.waitForSelector('.bk-g-srcs a[href="#pedidos?source=whatsapp"]');
  await g.click('.bk-g-srcs a[href="#pedidos?source=whatsapp"]'); await g.waitForTimeout(2000);
  ok(await g.inputValue('#bk-g-of [name=source]') === 'whatsapp', 'tocar un canal del resumen abre los pedidos de ese canal');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php('foreach(wc_get_orders(["billing_email"=>"origen@ejemplo.com","limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); } delete_transient("bk_g_summary");');
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
