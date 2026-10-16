// 0.18.6: comercio electrónico de GA4 en el dataLayer (view_item → add_to_cart → view_cart → remove_from_cart →
// begin_checkout → add_payment_info → purchase una vez) + guía para la agencia y contenedor de GTM para importar.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const PROD = B + '/product/jean-bota-campana-flare-dama-tiro-alto/';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const EMAILS = ['ga4.compra@ejemplo.com', 'ga4.espera@ejemplo.com'];

(async () => {
  php(`update_option("bk_test_pending_gw", 1); foreach(wc_get_product(22)->get_children() as $c){ $v=wc_get_product($c); if($v->get_attribute("pa_tallas-plus-size")!=="18"){ $v->set_stock_quantity(10); $v->set_stock_status("instock"); $v->save(); } } foreach(${JSON.stringify(JSON.stringify(EMAILS))} ? json_decode(${JSON.stringify(JSON.stringify(EMAILS))}) : [] as $e){ foreach(wc_get_orders(["billing_email"=>$e,"limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); } } global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}wc_reserved_stock"); bk_cache_purge();`);
  const b = await chromium.launch();
  const errs = [];
  const ev = (p, name) => p.evaluate((n) => (window.dataLayer || []).filter((x) => x && x.event === n), name);
  const before = (p, name) => p.evaluate((n) => { const dl = window.dataLayer || []; const i = dl.findIndex((x) => x && x.event === n); return i > 0 && dl[i - 1] && dl[i - 1].ecommerce === null && Object.keys(dl[i - 1]).length === 1; }, name);

  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(e.message));

  // 1. Ficha: view_item.
  await p.goto(PROD, { waitUntil: 'networkidle' });
  let vi = await ev(p, 'view_item');
  const it = vi[0]?.ecommerce?.items?.[0] || {};
  ok(vi.length === 1 && it.item_id && it.item_name && it.item_brand === 'Buckler Jeans' && it.price > 0 && vi[0].ecommerce.currency === 'COP', `view_item en la ficha (${it.item_id} · ${it.item_name} · ${it.price})`);
  ok(/^Línea (Slim|Curvy)$/.test(it.item_category || '') && it.item_category2, `categorías: ${it.item_category} / ${it.item_category2}`);
  ok(await before(p, 'view_item'), 'precedido de { ecommerce: null } (recomendación de Google)');

  // 2. Agregar: add_to_cart con talla.
  await p.selectOption('#pa_tallas-plus-size', '16'); await p.waitForTimeout(300);
  await p.click('.single_add_to_cart_button'); await p.waitForSelector('.bk-sc__item', { timeout: 15000 }); await p.waitForTimeout(500);
  const ad = await ev(p, 'add_to_cart');
  const ai = ad[0]?.ecommerce?.items?.[0] || {};
  ok(ad.length === 1 && ai.item_variant === '16' && ai.quantity === 1 && ai.price > 0 && ai.item_id === it.item_id && ai.item_category === it.item_category && ad[0].ecommerce.value === ai.price, `add_to_cart (talla ${ai.item_variant}, ${ai.price}, mismas categorías de la ficha)`);

  // 3. Carrito: view_cart y quitar → remove_from_cart.
  await p.goto(B + '/cart/', { waitUntil: 'networkidle' });
  const vc = await ev(p, 'view_cart');
  ok(vc.length === 1 && vc[0].ecommerce.items.length >= 1 && vc[0].ecommerce.value > 0, `view_cart (${vc[0]?.ecommerce?.items?.length} prenda, ${vc[0]?.ecommerce?.value})`);
  await p.evaluate(() => { document.addEventListener('click', (e) => { if (e.target.closest('a.remove')) e.preventDefault(); }); });
  await p.locator('a.remove').first().click();
  const rm = await ev(p, 'remove_from_cart');
  ok(rm.length === 1 && rm[0].ecommerce.items[0].item_variant === '16', 'remove_from_cart al tocar "Quitar" en el carrito');

  // Woo quita la prenda por AJAX: se vuelve a agregar para seguir.
  await p.goto(PROD, { waitUntil: 'networkidle' });
  await p.selectOption('#pa_tallas-plus-size', '16'); await p.waitForTimeout(300);
  await p.click('.single_add_to_cart_button'); await p.waitForSelector('.bk-sc__item', { timeout: 15000 }); await p.waitForTimeout(500);

  // 4. Checkout y compra (contra entrega = pagada en local).
  const checkout = async (page, email, method) => {
    await page.goto(`${B}/checkout/`, { waitUntil: 'networkidle' });
    await page.fill('#billing_email', email); await page.fill('#billing_first_name', 'Gina'); await page.fill('#billing_last_name', 'Analytics');
    await page.fill('#billing_address_1', 'Cra 43A # 1-50'); await page.fill('#billing_phone', '300 123 4567');
    if (await page.locator('#billing_id').count()) await page.fill('#billing_id', '1037000111');
    await page.evaluate(() => { const s = document.querySelector('#billing_state'); s.value = s.tagName === 'SELECT' ? [...s.options].find((o) => /Antioquia/.test(o.text)).value : 'Antioquia'; s.dispatchEvent(new Event('change', { bubbles: true })); });
    await page.locator('#billing_email').blur(); await page.waitForTimeout(1800);
    await page.check(`#payment_method_${method}`, { force: true });
  };
  await checkout(p, EMAILS[0], 'cod');
  const bc = await ev(p, 'begin_checkout');
  ok(bc.length === 1 && bc[0].ecommerce.items.length === 1 && bc[0].ecommerce.value > 0, 'begin_checkout al abrir el checkout');
  // add_payment_info se empuja al enviar: lo leemos justo antes de navegar.
  await p.evaluate(() => { window.addEventListener('beforeunload', () => { sessionStorage.setItem('bk_api', JSON.stringify((window.dataLayer || []).filter((x) => x && x.event === 'add_payment_info'))); }); });
  await p.click('#place_order'); await p.waitForURL(/order-received/, { timeout: 30000 }); await p.waitForLoadState('networkidle');
  const api = JSON.parse(await p.evaluate(() => sessionStorage.getItem('bk_api') || '[]'));
  ok(api.length === 1 && api[0].ecommerce.payment_type && api[0].ecommerce.items.length === 1, `add_payment_info al realizar el pedido (${api[0]?.ecommerce?.payment_type})`);
  const oid = +(p.url().match(/order-received\/(\d+)/) || [])[1];
  let pu = await ev(p, 'purchase');
  const pe = pu[0]?.ecommerce || {};
  const num = php(`echo wc_get_order(${oid})->get_order_number();`);
  ok(pu.length === 1 && pe.transaction_id === num && pe.currency === 'COP' && pe.value > 0 && pe.items.length === 1 && pe.items[0].item_variant === '16', `purchase en la página de gracias (pedido ${pe.transaction_id}, ${pe.value} COP)`);
  const ud = pu[0]?.user_data || {};
  const sha = (s) => require('crypto').createHash('sha256').update(s).digest('hex');
  ok(ud.sha256_email_address === sha(EMAILS[0]) && ud.sha256_phone_number === sha('+573001234567') && !JSON.stringify(pu).includes(EMAILS[0]), 'user_data en SHA-256 (correo y +57…), nunca en claro');
  await p.reload({ waitUntil: 'networkidle' });
  pu = await ev(p, 'purchase');
  ok(pu.length === 0, 'al recargar la página de gracias no se repite la compra');

  // 5. Pago aún confirmándose: purchase llega cuando la pasarela confirma.
  const p2 = await (await b.newContext()).newPage();
  p2.on('pageerror', (e) => errs.push(e.message));
  await p2.goto(PROD, { waitUntil: 'networkidle' });
  await p2.selectOption('#pa_tallas-plus-size', '16'); await p2.waitForTimeout(300);
  await p2.click('.single_add_to_cart_button'); await p2.waitForSelector('.bk-sc__item', { timeout: 15000 });
  await checkout(p2, EMAILS[1], 'bk_test_pending');
  await p2.click('#place_order'); await p2.waitForURL(/order-received/, { timeout: 30000 }); await p2.waitForLoadState('networkidle');
  const oid2 = +(p2.url().match(/order-received\/(\d+)/) || [])[1];
  ok((await ev(p2, 'purchase')).length === 0 && await p2.evaluate(() => !!window.bkDL?.wait), 'pedido pendiente: no cuenta todavía (queda esperando la confirmación)');
  php(`$o=wc_get_order(${oid2}); $o->payment_complete();`);
  await p2.waitForTimeout(9000);
  pu = await ev(p2, 'purchase');
  ok(pu.length === 1 && pu[0].ecommerce.transaction_id === php(`echo wc_get_order(${oid2})->get_order_number();`), 'al confirmarse el pago, purchase llega sin recargar (una vez)');

  // 6. Guía para la agencia (usuaria de solo SEO y ADS) + contenedor para importar.
  const g = await (await b.newContext({ acceptDownloads: true })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'Gestor_ADS'); await g.fill('[name=pwd]', 'Clave#2026');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.waitForSelector('.bk-g-agency', { timeout: 20000 }).catch(() => {});
  const txt = await g.textContent('main');
  ok(/Guía para la agencia/.test(txt) && /G-BR4MXPY7HS/.test(txt) && /GTM-NCDM5V29/.test(txt) && /purchase/.test(txt), 'la agencia entra directo a "Guía para la agencia" con sus IDs');
  const [dl] = await Promise.all([g.waitForEvent('download'), g.click('[data-gtm-import]')]);
  const json = JSON.parse(require('fs').readFileSync(await dl.path(), 'utf8'));
  const cv = json.containerVersion;
  const tag = cv.tag[0];
  const par = Object.fromEntries(tag.parameter.map((x) => [x.key, x.value]));
  ok(json.exportFormatVersion === 2 && tag.type === 'gaawe' && par.measurementIdOverride === 'G-BR4MXPY7HS' && par.sendEcommerceData === 'true' && par.getEcommerceDataFrom === 'dataLayer' && par.eventName === '{{Event}}', 'contenedor: etiqueta Evento de GA4 con datos de comercio electrónico');
  const re = new RegExp(cv.trigger[0].customEventFilter[0].parameter.find((x) => x.key === 'arg1').value);
  ok(['view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase'].every((e) => re.test(e)) && !re.test('bk_add_to_cart') && tag.firingTriggerId[0] === cv.trigger[0].triggerId, 'activador con los 7 eventos (sin los bk_*)');
  await g.goto(B + '/gestion-tienda/#seo/visitas'); await g.waitForTimeout(1500);
  ok(await g.locator('.bk-g-agency-cta').count() === 1, 'en las otras pestañas: aviso "¿Eres la agencia de pauta?"');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`foreach(json_decode(${JSON.stringify(JSON.stringify(EMAILS))}) as $e){ foreach(wc_get_orders(["billing_email"=>$e,"limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); } } delete_option("bk_test_pending_gw");`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
