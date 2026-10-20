// Ronda 0.17.3: descuento por cantidad visible (ficha aunque otro plugin borre los ganchos del resumen, tarjetas, barra)
// y combinando productos distintos en el carrito; aviso de ¡Sale! en la ficha; formulario con "combinando" por defecto.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString();

(async () => {
  const modules = php('echo json_encode(get_option("bk_modules"));');
  php('update_option("bk_modules", array_values(array_unique(array_merge(get_option("bk_modules"), ["discounts"])))); delete_option("bk_test_no_bodycommerce"); foreach([23,75] as $id){ $v=wc_get_product($id); $v->set_stock_quantity(10); $v->set_stock_status("instock"); $v->save(); }');
  php('update_option("bk_discounts", ["campaigns"=>[],"qty"=>[["id"=>"q1","name"=>"Lleva más y ahorra","active"=>true,"start_ts"=>time()-3600,"end_ts"=>time()+86400,"scope"=>"all","exclude_sale"=>true,"count"=>"cart","tiers"=>[["min"=>2,"pct"=>10],["min"=>3,"pct"=>15]]]]]); bk_disc_flush();');
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));

  // Ficha: el "plugin" de los fixtures borra los ganchos del resumen (como BodyCommerce en producción).
  await p.goto(B + '/product/jean-bota-campana-flare-dama-tiro-alto/');
  ok(await p.locator('.bk-disc-box__qty').count() === 1 && /2 prendas[\s\S]*−10 %[\s\S]*3 o más prendas[\s\S]*−15 %/.test(await p.textContent('.bk-disc-box')), 'ficha: recuadro del descuento por cantidad aunque otro plugin borre los ganchos');
  ok(/Suma todas las prendas de la promoción/.test(await p.textContent('.bk-disc-box')), 'ficha: dice que se combinan productos');
  ok(/Lleva 2\+ prendas: −10 % · hasta −15 %/.test(await p.textContent('.bk-announce')), 'barra de anuncios con el descuento por cantidad');
  await p.screenshot({ path: '/tmp/claude-0/bk-local/r172-pdp.png' });
  await p.goto(B + '/product/bermuda-dama-tiro-medio-estilo-vintage/');
  ok(await p.locator('.bk-sale-notice').count() >= 1 && await p.locator('.bk-disc-box').count() === 0, 'producto de ¡Sale!: aviso "sin cambio" visible y sin descuento por cantidad');

  // Tienda: píldora en las tarjetas (no en las de ¡Sale!).
  await p.goto(B + '/shop/');
  const cards = await p.evaluate(() => [...document.querySelectorAll('.bk-card:not(.is-out)')].map((c) => ({ qty: c.querySelector('.bk-card__qty')?.textContent.trim() || '', sale: !!c.querySelector('.bk-badge--sale') })));
  const withQty = cards.filter((c) => c.qty);
  ok(withQty.length >= 10 && withQty.every((c) => c.qty === 'Lleva 2+ y ahorra 10 % · hasta 15 %'), `tarjetas: "Lleva 2+ y ahorra 10 % · hasta 15 %" (${withQty.length} de ${cards.length}; el resto son de ¡Sale!)`);
  ok(cards.filter((c) => c.sale).every((c) => !c.qty), 'tarjetas de ¡Sale!: sin la píldora (no aplica)');
  await p.locator('.bk-card__qty').first().scrollIntoViewIfNeeded();
  await p.screenshot({ path: '/tmp/claude-0/bk-local/r172-grid.png' });

  // Carrito: dos productos distintos (1 + 1) → −10 % en ambos.
  await p.goto(`${B}/?add-to-cart=22&variation_id=23&attribute_pa_tallas-plus-size=14&quantity=1`);
  await p.goto(`${B}/?add-to-cart=74&variation_id=75&attribute_pa_tallas-plus-size=14&quantity=1`);
  await p.goto(`${B}/cart/`);
  const tot = await p.evaluate(() => document.querySelector('.cart-subtotal .amount')?.textContent.replace(/\D/g, ''));
  const expected = String(Math.round((149900 + 129800) * 0.9));
  ok(tot === expected, `carrito: 2 productos distintos se combinan → −10 % (subtotal ${tot}, esperado ${expected})`);
  await p.screenshot({ path: '/tmp/claude-0/bk-local/r172-cart.png' });

  // Formulario nuevo: "combinando" marcado por defecto.
  const g = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await g.goto(B + '/gestion-tienda/'); await g.fill('[name=log]', 'admin'); await g.fill('[name=pwd]', 'admin');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#descuentos/cantidad/nuevo'); await g.waitForSelector('[name="count"]');
  ok(await g.inputValue('[name="count"]:checked') === 'cart' && await g.isChecked('[name="exclude_sale"]').catch(() => true), 'nuevo descuento por cantidad: combinando productos y sin ¡Sale! por defecto');
  const saved = await g.evaluate(async () => (await fetch(window.bkG.api + 'discounts', { method: 'POST', headers: { 'X-WP-Nonce': window.bkG.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'qty', rule: { name: 'Sin count', tiers: [{ min: 2, pct: 10 }], start: '2026-01-01T00:00', end: '2027-01-01T00:00', active: false, scope: 'all' } }) })).json());
  ok(saved.qty.find((r) => r.name === 'Sin count')?.count === 'cart', 'la API también combina productos por defecto');

  // Carrito abandonado siempre activo: sin casilla, aviso bajo el correo y correos programados.
  execSync('php /tmp/claude-0/bk-local/t/run.php reset');
  const c7 = await b.newContext(); const k = await c7.newPage();
  await k.goto(`${B}/?add-to-cart=22&variation_id=23&attribute_pa_tallas-plus-size=14`); await k.goto(`${B}/checkout/`);
  ok(await k.locator('#bk_ac_consent').count() === 0 && /te escribiremos a este correo/.test(await k.textContent('.bk-ac-notice')), 'checkout sin casilla: solo un aviso bajo el correo');
  await k.fill('#billing_email', 'siempre@ejemplo.com'); await k.locator('#billing_email').blur(); await k.waitForTimeout(1600);
  const rows7 = JSON.parse(execSync('php /tmp/claude-0/bk-local/t/run.php rows').toString());
  const acts7 = JSON.parse(execSync('php /tmp/claude-0/bk-local/t/run.php actions').toString());
  ok(rows7.length === 1 && +rows7[0].consent === 1 && acts7.length === 3, 'sin marcar nada: carrito guardado y 3 recordatorios programados');
  await c7.close();
  await g.goto('about:blank'); await g.goto(B + '/gestion-tienda/#carritos'); await g.waitForSelector('[data-carts-refresh]');
  ok(/siempre activo/.test(await g.textContent('.bk-g-main')), 'Carritos: dice que está siempre activo');
  const before = await g.locator('.bk-g-carts tbody tr').count();
  execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; global $wpdb; $wpdb->insert(bk_ac_table(), ["token"=>bin2hex(random_bytes(16)),"email"=>"nueva@ejemplo.com","first_name"=>"Nueva","cart"=>"[]","status"=>"active","consent"=>1,"created_at"=>gmdate("Y-m-d H:i:s"),"updated_at"=>gmdate("Y-m-d H:i:s")]);'`);
  await g.click('[data-carts-refresh]'); await g.waitForTimeout(1500);
  ok(await g.locator('.bk-g-carts tbody tr').count() === before + 1 && /Nueva/.test(await g.textContent('.bk-g-carts tbody tr:first-child')), 'botón "Actualizar listado": trae los carritos nuevos sin recargar la página');
  execSync('php /tmp/claude-0/bk-local/t/run.php reset');

  // Archivos del pedido (ACF archivos_adjuntos, subcampo "archivo" de tipo imagen).
  const fields0 = php('echo json_encode(get_option("bk_gestion_fields", null));');
  const img = php('echo wc_get_product(22)->get_image_id();').trim();
  const oid = php('$o=wc_get_orders(["limit"=>1,"exclude"=>[464]]); echo $o[0]->get_id();').trim();
  php(`update_option("bk_gestion_fields", ["adjuntos"=>""]); update_option("bk_test_acf_woo_order_${oid}_archivos_adjuntos", [["field_bk_adj_archivo"=>${img}]]);`);
  const api = (path) => g.evaluate(async (pth) => (await fetch(window.bkG.api + pth, { headers: { 'X-WP-Nonce': window.bkG.nonce } })).json(), path);
  const d464 = await api('orders/464');
  ok(d464.files && d464.files.rows.length >= 1 && d464.files.rows[0].files[0].thumb, `ajustes con el campo vacío: igual se ven los archivos del pedido (${d464.files ? d464.files.rows.length : 'sin sección'})`);
  const dAcf = await api('orders/' + oid);
  ok(dAcf.files && dAcf.files.rows.length === 1 && Number(dAcf.files.rows[0].files[0].id) === Number(img), 'archivos que ACF guardó en la tabla de pedidos (HPOS) también se ven');
  const list = await api('orders?q=464');
  const r464 = (list.orders || list.items || []).find((o) => +o.id === 464);
  ok(r464 && r464.nfiles >= 1, `lista de pedidos: cantidad de archivos (📎 ${r464 ? r464.nfiles : '?'})`);
  await g.goto('about:blank'); await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('.bk-g-orders'); await g.fill('#bk-g-of [name=q]', '464'); await g.waitForTimeout(2500);
  ok(await g.locator('.bk-g-nfiles').count() >= 1, 'lista de pedidos: se ve el 📎 en los pedidos con archivos');
  php(`delete_option("bk_test_acf_woo_order_${oid}_archivos_adjuntos"); ${fields0.trim() === 'null' ? 'delete_option("bk_gestion_fields");' : `update_option("bk_gestion_fields", json_decode(base64_decode("${Buffer.from(fields0.trim()).toString('base64')}"), true));`}`);

  php(`delete_option("bk_discounts"); update_option("bk_modules", json_decode(base64_decode("${Buffer.from(modules).toString('base64')}"), true)); bk_disc_flush();`);
  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
