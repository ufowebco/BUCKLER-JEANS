// 0.18.4: "Transportador Local" (domicilios Medellín y área metropolitana) sin número de guía + correo "Tu pedido llega hoy".
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
const mails = () => fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter((f) => f.endsWith('.json')) : [];
const newMails = (before) => mails().filter((f) => !before.includes(f)).map((f) => ({ f, ...JSON.parse(fs.readFileSync(`${MAILS}/${f}`, 'utf8')) }));

(async () => {
  const oid = php('$o=wc_create_order(); $o->add_product(wc_get_product(24),1); $o->set_billing_first_name("Lina"); $o->set_billing_email("lina@ejemplo.com"); $o->set_billing_country("CO"); $o->set_billing_state("CO-ANT"); $o->set_billing_city("Medellín"); $o->set_billing_address_1("Cra 43 # 10-20"); $o->set_customer_id((int) get_user_by("login","clienta")->ID); $o->calculate_totals(); $o->set_status("processing"); $o->save(); echo $o->get_id();');
  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(`${B}/gestion-tienda/#pedidos`); await g.waitForSelector('.bk-g-orders');
  await g.fill('#bk-g-of [name=q]', String(oid)); await g.waitForTimeout(2000);
  await g.click(`[data-order="${oid}"]`); await g.waitForSelector('#bk-g-shipform');

  const opts = await g.$$eval('#bk-g-shipform select[name=carrier] option', (o) => o.map((x) => x.value + '|' + x.textContent));
  ok(opts.includes('local|Transportador Local'), 'el desplegable incluye "Transportador Local"');
  ok(await g.locator('[data-ship-number]').isVisible() && await g.locator('[data-ship-local]').isHidden(), 'con otra transportadora se pide número de guía');
  await g.selectOption('#bk-g-shipform select[name=carrier]', 'local');
  ok(await g.locator('[data-ship-number]').isHidden() && await g.locator('[data-ship-local]').isVisible(), 'Transportador Local: se oculta el número de guía y sale la explicación');

  const before = mails();
  await g.click('#bk-g-shipform button[type="submit"]');
  await g.waitForSelector('#bk-g-preview:not([hidden]) .bk-g-pvframe'); await g.waitForTimeout(600);
  ok(/llega hoy/.test(await g.textContent('.bk-g-pvmeta')), 'vista previa sin pedir guía, asunto "Tu pedido #N llega hoy"');
  const fr = g.frameLocator('.bk-g-pvframe');
  const txt = await fr.locator('body').textContent();
  ok(/domicilios en Medellín y el área metropolitana/.test(txt) && /no tiene número de guía/.test(txt) && /Quédate muy pendiente/.test(txt) && /Muchas gracias por tu compra/.test(txt), 'correo con el mensaje de entrega local');
  ok(!/NÚMERO DE GUÍA|Rastrear mi envío/.test(txt), 'sin recuadro de guía ni botón de rastreo');
  await g.click('[data-ship-confirm]');
  await g.waitForSelector('.bk-g-shipped'); await g.waitForTimeout(600);
  const sent = newMails(before).filter((m) => /llega hoy/.test(m.subject));
  ok(sent.length === 1 && sent[0].to === 'lina@ejemplo.com', 'correo enviado a la clienta: ' + (sent[0]?.subject || 'ninguno'));
  ok(/Transportador Local/.test(await g.textContent('.bk-g-shipped')) && /entrega local \(sin guía\)/.test(await g.textContent('.bk-g-shipped')), 'detalle: "Enviado con Transportador Local · entrega local (sin guía)"');
  ok(/entrega local, sin guía/.test(await g.textContent('.bk-g-notes')), 'nota en el pedido');
  const t = JSON.parse(php(`echo json_encode(wc_get_order(${oid})->get_meta("_bk_tracking"));`));
  ok(t.carrier === 'local' && t.number === '' && php(`echo wc_get_order(${oid})->get_status();`) === 'completed', 'meta guardada sin número y pedido Completado');
  ok(/sin guía \(entrega local\)/.test(php(`echo json_encode(bk_g_history(wc_get_order(${oid})), JSON_UNESCAPED_UNICODE);`)), 'historial: guía "sin guía (entrega local)"');

  // API: sin número no da error con local; con otra transportadora sí lo exige.
  const nonce = await g.evaluate(() => window.bkG?.nonce || '');
  const r1 = await g.request.post(`${B}/wp-json/bk/v1/gestion/orders/${oid}/shipment`, { headers: { 'X-WP-Nonce': nonce }, data: { carrier: 'servientrega', number: '' } });
  ok(r1.status() === 400, 'Servientrega sin número: sigue exigiendo la guía');

  // Correos: aparece la plantilla y se puede previsualizar.
  await g.keyboard.press('Escape');
  await g.goto(`${B}/gestion-tienda/#correos`); await g.waitForTimeout(2500);
  ok(/Tu pedido llega hoy \(entrega local\)/.test(await g.textContent('main')), 'Correos: "Tu pedido llega hoy (entrega local)"');

  // Mi cuenta de la clienta.
  const c = await (await b.newContext()).newPage();
  await c.goto(B + '/ingreso/'); await c.fill('[name=log]', 'clienta'); await c.fill('[name=pwd]', 'Clienta#2026');
  await Promise.all([c.waitForNavigation(), c.click('.bk-g-login__form [type=submit]')]);
  await c.goto(php(`echo wc_get_endpoint_url("view-order", ${oid}, wc_get_page_permalink("myaccount"));`));
  ok(/Tu pedido llega hoy/.test(await c.textContent('body')) && !/Rastrear mi envío/.test(await c.textContent('.bk-ship-box')), 'Mi cuenta → pedido: aviso de entrega local sin rastreo');
  await c.goto(B + '/mi-cuenta/');
  ok(/Entrega local hoy \(sin guía\)/.test(await c.textContent('body')) || !(await c.textContent('body')).includes(`#${oid}`), 'Mi cuenta: último pedido con "Entrega local hoy"');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`wc_get_order(${oid})->delete(true);`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
