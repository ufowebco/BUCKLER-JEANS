// 0.17.9: favicon en la gestión, el acceso y la impresión; editar los datos de la clienta desde el detalle del pedido.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  const icon0 = php('echo (int) get_option("site_icon");');
  const img = php('echo wc_get_product(22)->get_image_id();');
  php(`update_option("site_icon", ${img});`);
  const oid = php('$o=wc_create_order(); $o->add_product(wc_get_product(24),1); $o->set_billing_first_name("Ana"); $o->set_billing_last_name("Pérez"); $o->set_billing_email("ana@ejemplo.com"); $o->set_billing_phone("3001112233"); $o->set_billing_country("CO"); $o->set_billing_state("CO-ANT"); $o->set_billing_address_1("Calle 10 # 20-30"); $o->update_meta_data("_billing_id","111"); $o->calculate_totals(); $o->set_status("processing"); $o->save(); echo $o->get_id();');
  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));

  // 1. Favicon.
  for (const path of ['/ingreso/', '/gestion-tienda/']) {
    await g.goto(B + path);
    const icons = await g.$$eval('link[rel="icon"], link[rel="apple-touch-icon"]', (l) => l.map((x) => x.getAttribute('href')));
    ok(icons.length === 3 && icons.every((h) => /\.(webp|jpe?g|png)/.test(h)), `favicon en ${path} (${icons.length} tamaños)`);
  }
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  ok(await g.locator('link[rel="icon"]').count() === 2, 'favicon dentro de la gestión');
  const pr = await (await g.context().newPage());
  await pr.goto(`${B}/gestion-tienda/?imprimir=${oid}`);
  ok(await pr.locator('link[rel="icon"]').count() === 2, 'favicon en la hoja de impresión');
  await pr.close();

  // 2. Editar datos de la clienta.
  await g.goto(`${B}/gestion-tienda/#pedidos`); await g.waitForSelector('.bk-g-orders');
  await g.fill('#bk-g-of [name=q]', String(oid)); await g.waitForTimeout(2000);
  await g.click(`[data-order="${oid}"]`); await g.waitForSelector('[data-cust-edit]');
  ok(await g.locator('#bk-g-custform').isHidden(), 'detalle del pedido: botón "Editar datos" (formulario oculto)');
  await g.click('[data-cust-edit]');
  ok(await g.locator('#bk-g-custform').isVisible() && await g.locator('.bk-g-cust__view').isHidden() && await g.inputValue('[name="billing.first_name"]') === 'Ana' && await g.inputValue('[name=cedula]') === '111' && await g.inputValue('[name="billing.state"]') === 'CO-ANT', 'formulario con los datos actuales (nombre, cédula, departamento)');
  await g.fill('[name="billing.email"]', 'malo@'); await g.click('#bk-g-custform [type=submit]');
  ok(/correo no es válido/.test(await g.textContent('#bk-g-custform .bk-g-formerr')), 'correo inválido: mensaje en el formulario');
  await g.fill('[name="billing.email"]', 'ana.nueva@ejemplo.com');
  await g.fill('[name="billing.phone"]', '3209998877');
  await g.fill('[name=cedula]', '1037654321');
  await g.selectOption('[name="billing.state"]', 'CO-SAP');
  await g.fill('[name="billing.city"]', 'San Andrés');
  await g.fill('[name="billing.address_1"]', 'Av. Colombia 5-12');
  await g.click('#bk-g-custform [type=submit]'); await g.waitForTimeout(2000);
  const d = JSON.parse(php(`$o=wc_get_order(${oid}); echo json_encode(["e"=>$o->get_billing_email(),"p"=>$o->get_billing_phone(),"c"=>$o->get_meta("_billing_id"),"s"=>$o->get_billing_state(),"a"=>$o->get_billing_address_1(),"sa"=>$o->get_shipping_address_1(),"sc"=>$o->get_shipping_city()]);`));
  ok(d.e === 'ana.nueva@ejemplo.com' && d.p === '3209998877' && d.c === '1037654321' && d.s === 'CO-SAP' && d.a === 'Av. Colombia 5-12', 'guardado en el pedido: correo, celular, cédula, departamento y dirección');
  ok(d.sa === 'Av. Colombia 5-12' && d.sc === 'San Andrés', '"Enviar a la misma dirección": la dirección de envío queda igual');
  ok(/guardados/.test(await g.textContent('#bk-g-toast')) && /Av\. Colombia 5-12/.test(await g.textContent('.bk-g-cust__view')) && /1037654321/.test(await g.textContent('.bk-g-cust__view')), 'aviso y el detalle muestra los datos nuevos');
  ok(/Datos de la clienta editados: .*Correo.*Celular/.test(await g.textContent('.bk-g-hist')) && /Datos de la clienta editados desde Gestión de tienda/.test(await g.textContent('.bk-g-notes')), 'queda en el historial (con quién) y en las notas del pedido');

  // Envío a otra dirección.
  await g.click('[data-cust-edit]'); await g.uncheck('#bk-g-custform [name=same]');
  ok(await g.locator('.bk-g-custship').isVisible(), 'desmarcar "misma dirección" muestra los datos de envío');
  await g.fill('[name="shipping.address_1"]', 'Cra 50 # 10-20 Oficina'); await g.fill('[name="shipping.city"]', 'Medellín'); await g.selectOption('[name="shipping.state"]', 'CO-ANT');
  await g.click('#bk-g-custform [type=submit]'); await g.waitForTimeout(2000);
  const d2 = JSON.parse(php(`$o=wc_get_order(${oid}); echo json_encode(["a"=>$o->get_billing_address_1(),"sa"=>$o->get_shipping_address_1(),"ss"=>$o->get_shipping_state()]);`));
  ok(d2.a === 'Av. Colombia 5-12' && d2.sa === 'Cra 50 # 10-20 Oficina' && d2.ss === 'CO-ANT', 'dirección de envío distinta guardada sin tocar la de facturación');
  await g.click('[data-cust-edit]'); await g.click('#bk-g-custform [type=submit]'); await g.waitForTimeout(1500);
  ok(/No había cambios/.test(await g.textContent('#bk-g-toast')), 'guardar sin cambios no toca el pedido');
  const r403 = await (await b.newContext()).request.post(`${B}/wp-json/bk/v1/gestion/orders/${oid}/customer`, { data: { billing: { first_name: 'X' } } });
  ok(r403.status() === 401 || r403.status() === 403, `sin sesión: rechazado (${r403.status()})`);
  await g.screenshot({ path: '/tmp/claude-0/bk-local/r179-cliente.png' });

  // Celular.
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, storageState: await g.context().storageState() })).newPage();
  await m.goto(`${B}/gestion-tienda/#pedidos`); await m.waitForSelector('.bk-g-orders');
  await m.fill('#bk-g-of [name=q]', String(oid)); await m.waitForTimeout(2000);
  await m.click(`[data-order="${oid}"]`); await m.click('[data-cust-edit]');
  const over = await m.evaluate(() => { const f = document.querySelector('#bk-g-custform'); return f.scrollWidth > f.clientWidth + 1; });
  ok(!over, 'celular: el formulario cabe sin desplazamiento lateral');
  await m.locator('#bk-g-custform').screenshot({ path: '/tmp/claude-0/bk-local/r179-cliente-390.png' });

  php(`wc_get_order(${oid})->delete(true); update_option("site_icon", ${icon0});`);
  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
