// 0.17.6: tallas ordenadas en las tarjetas (al pasar el mouse) y checkout sin Población ni Código postal.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  php('foreach(wc_get_product(22)->get_children() as $c){ $v=wc_get_product($c); if($v->get_attribute("pa_tallas-plus-size")!=="18"){ $v->set_stock_quantity(10); $v->set_stock_status("instock"); $v->save(); } }');
  // Orden de la función con tallas desordenadas (como llegan de algunos productos).
  const sorted = JSON.parse(php('echo json_encode(array_column(bk_sort_sizes([["label"=>"24"],["label"=>"14"],["label"=>"Única"],["label"=>"8"],["label"=>"20"],["label"=>"10"],["label"=>"6"]]), "label"));'));
  ok(sorted.join(',') === '6,8,10,14,20,24,Única', `tallas de menor a mayor (${sorted.join(', ')})`);

  // Producto con las tallas guardadas en desorden (atributo del padre al revés).
  php('$p=wc_get_product(22); $a=$p->get_attributes(); foreach($a as $k=>$at){ if($at->is_taxonomy()){ $o=$at->get_options(); $at->set_options(array_reverse($o)); $a[$k]=$at; } } $p->set_attributes($a); $p->save(); bk_catalog_flush(22);');
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  await p.goto(B + '/shop/');
  const cards = await p.evaluate(() => [...document.querySelectorAll('.bk-card__sizes')].map((s) => [...s.querySelectorAll('span')].map((x) => x.textContent.trim())));
  const isSorted = (a) => a.every((v, i) => i === 0 || !(+v < +a[i - 1]));
  ok(cards.length > 5 && cards.every(isSorted), `tarjetas: tallas en orden en las ${cards.length} tarjetas`);
  const flare = await p.evaluate(() => { const c = [...document.querySelectorAll('.bk-card')].find((x) => /FLARE/.test(x.textContent)); return c ? [...c.querySelectorAll('.bk-card__sizes span')].map((x) => x.textContent.trim()) : []; });
  ok(flare.join(',') === '14,16,18,20,22,24', `producto con las tallas guardadas al revés: ${flare.join(', ')}`);
  await p.locator('.bk-card').first().hover(); await p.waitForTimeout(400);
  await p.screenshot({ path: '/tmp/claude-0/bk-local/r176-hover.png', clip: { x: 300, y: 250, width: 1100, height: 650 } });

  // Checkout sin Población ni Código postal; el pedido se crea igual.
  await p.goto(`${B}/?add-to-cart=22&variation_id=24&attribute_pa_tallas-plus-size=16&quantity=1`);
  await p.goto(`${B}/checkout/`);
  const vis = await p.evaluate(() => ['billing_city_field', 'billing_postcode_field', 'shipping_city_field', 'shipping_postcode_field'].map((id) => { const e = document.getElementById(id); return !!e && !!e.offsetParent; }));
  ok(vis.every((v) => !v), 'checkout: sin "Población" ni "Código postal" (facturación y envío)');
  ok(await p.locator('#billing_email_field .bk-ac-notice').count() === 1, 'aviso del carrito abandonado justo debajo del correo');
  await p.fill('#billing_email', 'sincp@ejemplo.com'); await p.fill('#billing_first_name', 'Ana'); await p.fill('#billing_last_name', 'Sin CP');
  await p.fill('#billing_address_1', 'Cra 43A # 1-50'); await p.fill('#billing_phone', '3000000000');
  if (await p.locator('#billing_id').count()) await p.fill('#billing_id', '1234567890');
  await p.evaluate(() => { const s = document.querySelector('#billing_state'); s.value = s.tagName === 'SELECT' ? [...s.options].find((o) => /Antioquia/.test(o.text)).value : 'Antioquia'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitForTimeout(1500);
  const vis2 = await p.evaluate(() => ['billing_city_field', 'billing_postcode_field'].map((id) => { const e = document.getElementById(id); return !!e && !!e.offsetParent; }));
  ok(vis2.every((v) => !v), 'después de elegir departamento siguen ocultos (el script de Woo no los vuelve a mostrar)');
  await p.screenshot({ path: '/tmp/claude-0/bk-local/r176-checkout.png', fullPage: true });
  await p.click('#place_order'); await p.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
  const err = (await p.locator('.woocommerce-error, .woocommerce-NoticeGroup').allTextContents()).join(' ').replace(/\s+/g, ' ').slice(0, 200);
  ok(/order-received/.test(p.url()), 'pedido creado sin ciudad ni código postal ' + err);
  const oid = (p.url().match(/order-received\/(\d+)/) || [])[1];

  // Gestión: muestra el departamento donde antes iba la ciudad.
  if (oid) {
    const g = await (await b.newContext()).newPage();
    await g.goto(B + '/gestion-tienda/'); await g.fill('[name=log]', 'admin'); await g.fill('[name=pwd]', 'admin');
    await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
    const row = await g.evaluate(async (id) => (await (await fetch(window.bkG.api + 'orders?q=' + id, { headers: { 'X-WP-Nonce': window.bkG.nonce } })).json()).orders[0], oid);
    ok(row && /Antioquia/.test(row.city), `gestión: el pedido muestra el departamento (${row && row.city})`);
    php(`wc_get_order(${oid})->delete(true);`);
  }

  // Restaurar el orden original del atributo.
  php('$p=wc_get_product(22); $a=$p->get_attributes(); foreach($a as $k=>$at){ if($at->is_taxonomy()){ $o=$at->get_options(); sort($o); $at->set_options($o); $a[$k]=$at; } } $p->set_attributes($a); $p->save(); bk_catalog_flush(22);');
  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
