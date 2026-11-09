// 0.18.31: "Editar guía" en el pedido: corregir transportadora o número después de enviada y reenviar el correo, que
// avisa a la clienta que la guía anterior ya no es válida.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const MAILS = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
const SHOT = process.env.SHOT || '/tmp/claude-0/guia';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const subjects = () => (fs.existsSync(MAILS) ? fs.readdirSync(MAILS).filter((f) => f.endsWith('.json') && f.includes('guiaejemplo-com')).sort().map((f) => JSON.parse(fs.readFileSync(`${MAILS}/${f}`, 'utf8')).subject) : []);

(async () => {
  php('foreach ( wc_get_orders( array( "billing_email" => "guia@ejemplo.com", "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); }');
  if (fs.existsSync(MAILS)) fs.readdirSync(MAILS).filter((f) => f.includes('guiaejemplo-com')).forEach((f) => fs.unlinkSync(`${MAILS}/${f}`));
  const id = +php('$o = wc_create_order(); $o->add_product( wc_get_product( 24 ), 1 ); $o->set_billing_first_name( "Laura" ); $o->set_billing_last_name( "Gómez" ); $o->set_billing_email( "guia@ejemplo.com" ); $o->set_billing_phone( "3001234567" ); $o->set_billing_city( "Medellín" ); $o->set_billing_address_1( "Calle 10 # 20-30" ); $o->set_payment_method_title( "Wompi" ); $o->calculate_totals(); $o->set_status( "processing" ); $o->save(); echo $o->get_id();');
  const num = php(`echo wc_get_order( ${id} )->get_order_number();`);

  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector(`[data-order="${id}"]`);
  await g.locator(`[data-order="${id}"]`).first().click(); await g.waitForSelector('#bk-g-shipform');
  ok(await g.locator('#bk-g-shipform').isVisible() && await g.locator('[data-ship-edit]').count() === 0, 'pedido sin guía: formulario normal, sin "Editar guía"');

  const send = async (confirmText) => {
    await g.click('#bk-g-shipform button[type="submit"]');
    await g.waitForSelector('#bk-g-preview:not([hidden]) [data-ship-confirm]');
    const pv = { btn: await g.locator('[data-ship-confirm]').innerText(), subject: await g.locator('#bk-g-preview').innerText(), html: await g.frameLocator('#bk-g-preview iframe').locator('body').innerText() };
    if (confirmText !== false) { await g.click('[data-ship-confirm]'); await g.waitForSelector('#bk-g-preview', { state: 'hidden' }); await g.waitForTimeout(600); }
    return pv;
  };

  // 1. Primer envío (con un número mal escrito).
  await g.selectOption('#bk-g-shipform [name=carrier]', 'servientrega');
  await g.fill('#bk-g-shipform [name=number]', '1111-MAL');
  const first = await send();
  ok(!/corregida/i.test(first.btn) && !/ya no es válida/.test(first.html), 'primer envío: correo normal, sin aviso de corrección');
  await g.waitForSelector('.bk-g-shipped');
  ok(await g.locator('[data-ship-edit]').isVisible() && /Editar guía/.test(await g.locator('[data-ship-edit]').innerText()) && await g.locator('#bk-g-shipform').isHidden(), 'guía enviada: aparece "Editar guía" y el formulario queda cerrado');
  await g.locator('.bk-g-ship').screenshot({ path: SHOT + '-1-boton.png' });

  // 2. Abrir, cancelar.
  await g.click('[data-ship-edit]');
  const open = await g.evaluate(() => ({ vis: !document.querySelector('#bk-g-shipform').hidden, num: document.querySelector('#bk-g-shipform [name=number]').value, car: document.querySelector('#bk-g-shipform [name=carrier]').value, focus: document.activeElement?.name, exp: document.querySelector('[data-ship-edit]').getAttribute('aria-expanded'), hint: document.querySelector('#bk-g-shipform .bk-g-hint').textContent }));
  ok(open.vis && open.num === '1111-MAL' && open.car === 'servientrega' && open.focus === 'number' && open.exp === 'true', 'al tocarlo: formulario con los datos actuales y el cursor en el número');
  ok(/ya no es válida/.test(open.hint), 'explica que la clienta recibe un correo nuevo con el aviso');
  await g.fill('#bk-g-shipform [name=number]', '2222-BIEN');
  await g.locator('.bk-g-ship').screenshot({ path: SHOT + '-2-editar.png' });
  await g.click('[data-ship-cancel]');
  ok(await g.locator('#bk-g-shipform').isHidden() && await g.evaluate(() => document.querySelector('#bk-g-shipform [name=number]').value) === '1111-MAL', '"Cancelar" cierra y descarta lo escrito');

  // 3. Corregir el número: vista previa con aviso, y enviar.
  await g.click('[data-ship-edit]');
  await g.fill('#bk-g-shipform [name=number]', '2222-BIEN');
  await g.click('#bk-g-shipform button[type="submit"]');
  await g.waitForSelector('#bk-g-preview:not([hidden]) [data-ship-confirm]');
  const pvText = await g.locator('#bk-g-preview').innerText();
  const mail = await g.frameLocator('#bk-g-preview iframe').locator('body').innerText();
  ok(new RegExp(`Guía corregida de tu pedido #${num}`).test(pvText), 'vista previa: asunto "Guía corregida de tu pedido #N"');
  ok(/Corregimos los datos de tu envío/.test(mail) && /Servientrega 1111-MAL/.test(mail) && /ya no es válida/.test(mail) && /2222-BIEN/.test(mail), 'el correo avisa que la guía anterior ya no sirve y trae la nueva');
  ok(/guía corregida/i.test(await g.locator('[data-ship-confirm]').innerText()), 'botón "Confirmar y enviar guía corregida a…"');
  await g.waitForTimeout(500);
  await g.locator('.bk-g-preview__box').screenshot({ path: SHOT + '-3-correo.png' });
  await g.click('[data-ship-confirm]'); await g.waitForSelector('#bk-g-preview', { state: 'hidden' }); await g.waitForTimeout(700);
  ok(/Guía corregida/.test(await g.locator('#bk-g-toast').innerText().catch(() => '')), 'aviso "Guía corregida. Correo enviado a…"');
  const box = await g.locator('.bk-g-shipped').innerText();
  ok(/2222-BIEN/.test(box) && /corregida 1 vez/.test(box) && !/1111-MAL/.test(box), `el pedido muestra la guía nueva y "corregida 1 vez"`);
  await g.locator('.bk-g-ship').screenshot({ path: SHOT + '-4-corregida.png' });
  const O = JSON.parse(php(`$o = wc_get_order( ${id} ); echo wp_json_encode( array( "t" => $o->get_meta( "_bk_tracking" ), "notes" => implode( " | ", wp_list_pluck( wc_get_order_notes( array( "order_id" => ${id} ) ), "content" ) ), "hist" => wp_list_pluck( (array) $o->get_meta( "_bk_history" ), "ev" ) ) );`));
  ok(O.t.number === '2222-BIEN' && O.t.edits === 1 && O.t.prev.number === '1111-MAL', 'guarda la guía nueva, la anterior y cuántas veces se corrigió');
  ok(/Guía corregida: Servientrega 1111-MAL → Servientrega 2222-BIEN/.test(O.notes) && O.hist.includes('guia_edit'), 'nota e historial "Guía corregida y reenviada"');
  const subj = subjects().filter((x) => /enviado|corregida|llega hoy/i.test(x)); // sin el "pedido recibido" de WooCommerce
  ok(subj.length === 2 && /ha sido enviado/.test(subj[0]) && /Guía corregida/.test(subj[1]), `correos enviados: ${subj.join(' | ')}`);
  ok(/Guía corregida y reenviada: Servientrega 1111-MAL → Servientrega 2222-BIEN/.test(await g.locator('.bk-g-drawer__panel').innerText()), 'el historial del pedido lo muestra');

  // 4. Reenviar sin cambiar nada: correo normal (no dice "corregida").
  await g.click('[data-ship-edit]');
  const same = await send();
  ok(!/corregida/i.test(same.btn) && !/ya no es válida/.test(same.html), 'sin cambios: reenvía el mismo correo, sin aviso de corrección');
  ok(php(`echo (int) wc_get_order( ${id} )->get_meta( "_bk_tracking" )["edits"];`) === '1', 'un reenvío sin cambios no cuenta como corrección');

  // 5. Cambiar de transportadora.
  await g.click('[data-ship-edit]');
  await g.selectOption('#bk-g-shipform [name=carrier]', 'coordinadora');
  await g.fill('#bk-g-shipform [name=number]', '3333-COO');
  const car = await send();
  ok(/Servientrega 2222-BIEN/.test(car.html) && /Coordinadora/.test(car.html) && /3333-COO/.test(car.html), 'cambio de transportadora: avisa la anterior y trae la nueva');
  ok(/corregida 2 veces/.test(await g.locator('.bk-g-shipped').innerText()), 'cuenta "corregida 2 veces"');

  // 6. Cambiar a entrega local.
  await g.click('[data-ship-edit]');
  const localKey = php('echo bk_ship_local_keys()[0];');
  await g.selectOption('#bk-g-shipform [name=carrier]', localKey);
  ok(await g.locator('#bk-g-shipform [data-ship-number]').isHidden(), 'entrega local: sin campo de número');
  const loc = await send();
  ok(/llega hoy/.test(loc.subject) && /Coordinadora 3333-COO/.test(loc.html) && /ya no es válida/.test(loc.html), 'pasa a entrega local: "llega hoy" + aviso de que la guía anterior ya no aplica');

  // 7. Celular.
  await g.setViewportSize({ width: 390, height: 844 });
  await g.reload(); await g.waitForSelector(`[data-order="${id}"]`).catch(() => {});
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector(`[data-order="${id}"]`);
  await g.locator(`[data-order="${id}"]`).first().click(); await g.waitForSelector('[data-ship-edit]');
  const mob = await g.evaluate(() => { const r = document.querySelector('[data-ship-edit]').getBoundingClientRect(); const p = document.querySelector('.bk-g-drawer__panel'); return { h: Math.round(r.height), in: r.right <= innerWidth, sx: p.scrollWidth > p.clientWidth + 1 }; });
  ok(mob.h >= 44 && mob.in && !mob.sx, `celular: botón de ${mob.h} px, dentro de la pantalla, sin scroll de lado`);
  await g.locator('[data-ship-edit]').scrollIntoViewIfNeeded(); await g.click('[data-ship-edit]');
  await g.locator('.bk-g-ship').screenshot({ path: SHOT + '-5-celular.png' });

  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  php('foreach ( wc_get_orders( array( "billing_email" => "guia@ejemplo.com", "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); }');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
