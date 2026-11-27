// 0.18.46: cuentas de clientas. Crear cuenta después de pagar (página de gracias), pestañas Ingresar / Crear cuenta,
// enlace de 72 h para crear la contraseña y "Enviar acceso" desde la gestión (por pedido y masivo).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const SHOT = process.env.SHOT || '/tmp/claude-0/cuentas';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const stamp = Date.now().toString(36);
const mail = (n) => `cuenta.${n}.${stamp}@ejemplo.com`;
// Pedido de invitada: [correo, estado] → {id, key}
const mkOrder = (email, st = 'processing', name = 'Laura') => JSON.parse(php(`$o = wc_create_order(); $p = wc_get_products( array( "limit" => 1, "type" => "variable", "status" => "publish" ) ); $v = wc_get_product( $p[0]->get_children()[0] ); $o->add_product( $v, 1 ); $o->set_billing_first_name( "${name}" ); $o->set_billing_last_name( "Prueba" ); $o->set_billing_email( "${email}" ); $o->set_billing_phone( "3001112233" ); $o->set_billing_city( "Medellín" ); $o->set_billing_state( "CO-ANT" ); $o->set_billing_address_1( "Calle 10 # 20-30" ); $o->set_billing_country( "CO" ); $o->update_meta_data( "_bk_test_acc", 1 ); $o->calculate_totals(); $o->set_status( "${st}" ); $o->save(); echo wp_json_encode( array( "id" => $o->get_id(), "key" => $o->get_order_key() ) );`));
const userOf = (email) => JSON.parse(php(`$u = get_user_by( "email", "${email}" ); echo wp_json_encode( $u ? array( "id" => $u->ID, "roles" => $u->roles, "city" => get_user_meta( $u->ID, "billing_city", true ), "orders" => count( wc_get_orders( array( "customer_id" => $u->ID, "limit" => -1, "return" => "ids", "status" => "any" ) ) ) ) : null );`));

(async () => {
  fs.mkdirSync(SHOT, { recursive: true });
  php('foreach ( wc_get_orders( array( "limit" => -1, "status" => "any", "meta_key" => "_bk_test_acc", "meta_compare" => "EXISTS" ) ) as $o ) { $o->delete( true ); } delete_option( "bk_acc_bulk" ); delete_option( "bk_acc_bulk_done" );');
  const b = await chromium.launch();
  const errs = [];
  const fresh = async (w = 1440) => { const c = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await c.newPage(); p.on('pageerror', (e) => errs.push(e.message)); return p; };

  // 1. Página de gracias: ventana "Crea tu cuenta" (sale sola) y creación con contraseña.
  const e1 = mail('gracias');
  const older = mkOrder(e1, 'completed');
  const o1 = mkOrder(e1, 'processing');
  let p = await fresh(390);
  await p.goto(`${B}/checkout/order-received/${o1.id}/?key=${o1.key}`);
  ok(await p.locator('.bk-acc-card').count() === 1, 'gracias: recuadro "¿Quieres crear tu cuenta?"');
  await p.waitForSelector('#bk-acc-pop[open]', { timeout: 4000 }).catch(() => {});
  ok(await p.locator('#bk-acc-pop[open]').count() === 1, 'la ventana se abre sola');
  ok((await p.locator('#bk-acc-pop').textContent()).includes(e1), 'muestra el correo del pedido');
  await p.screenshot({ path: `${SHOT}/gracias-390.png` });
  await p.fill('#bk-acc-pass', '123');
  await p.click('.bk-acc-submit');
  ok(/al menos 8/.test(await p.locator('#bk-acc-err').textContent()), 'contraseña corta: error en línea');
  await p.fill('#bk-acc-pass', 'Clave-segura-1');
  await p.click('.bk-acc-submit');
  await p.waitForSelector('[data-step="done"]:not([hidden])', { timeout: 15000 });
  ok(true, 'cuenta creada: "¡Listo, ya tienes cuenta!"');
  await p.screenshot({ path: `${SHOT}/gracias-lista-390.png` });
  const u1 = userOf(e1);
  ok(u1 && u1.roles.includes('customer') && u1.city === 'Medellín', 'usuario cliente con los datos del pedido (ciudad)');
  ok(u1 && u1.orders === 2, `el pedido y el anterior con el mismo correo quedan en su cuenta (${u1 && u1.orders})`);
  await p.goto(`${B}/my-account/orders/`);
  ok(await p.locator('.woocommerce-orders-table, .woocommerce-MyAccount-orders').count() >= 1, 'queda con la sesión iniciada y ve sus pedidos');
  // Volver a la página de gracias: ya no ofrece la cuenta.
  await p.goto(`${B}/checkout/order-received/${o1.id}/?key=${o1.key}`);
  ok(await p.locator('.bk-acc-card').count() === 0, 'con sesión ya no se ofrece crear cuenta');
  // La API rechaza una clave equivocada.
  const bad = await p.evaluate(async ([u, id]) => (await fetch(u + '/wp-json/bk/v1/account/signup', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order: id, key: 'wc_order_falsa', password: 'Clave-segura-1' }) })).status, [B, mkOrder(mail('falsa')).id]);
  ok(bad === 403, `clave de pedido falsa: rechazado (${bad})`);
  await p.context().close();

  // 2. Correo que ya tiene cuenta → "Ya tienes una cuenta".
  const o2 = mkOrder(e1, 'processing');
  p = await fresh();
  await p.goto(`${B}/checkout/order-received/${o2.id}/?key=${o2.key}`);
  ok(await p.locator('.bk-acc-card.is-exists').count() === 1 && await p.locator('#bk-acc-pop').count() === 0, 'correo con cuenta: "Ya tienes una cuenta" sin ventana');
  await p.context().close();

  // 3. Mi cuenta: pestañas Ingresar / Crear cuenta.
  p = await fresh(390);
  await p.goto(`${B}/my-account/`);
  ok(await p.locator('#bk-tab-login[aria-selected="true"]').count() === 1 && await p.locator('#bk-pane-register').isHidden(), 'Mi cuenta: pestaña Ingresar activa');
  await p.click('#bk-tab-register');
  ok(await p.locator('#bk-pane-register').isVisible() && /Crea tu cuenta/.test(await p.locator('.bk-page-title').textContent()), 'pestaña Crear cuenta');
  await p.screenshot({ path: `${SHOT}/mi-cuenta-crear-390.png` });
  const e3 = mail('registro');
  mkOrder(e3, 'completed');
  await p.fill('#reg_email', e3); await p.fill('#reg_password', 'corta');
  await Promise.all([p.waitForNavigation(), p.click('.woocommerce-form-register__submit')]);
  ok(/al menos 8/.test(await p.locator('.woocommerce-error, .woocommerce-notices-wrapper').first().textContent()) && await p.locator('#bk-pane-register').isVisible(), 'registro con contraseña corta: error y sigue en Crear cuenta');
  await p.fill('#reg_email', e3); await p.fill('#reg_password', 'Clave-segura-2');
  await Promise.all([p.waitForNavigation(), p.click('.woocommerce-form-register__submit')]);
  const u3 = userOf(e3);
  ok(u3 && u3.orders === 1 && await p.locator('.bk-acc-nav, .woocommerce-MyAccount-navigation').count() >= 1, `registro: entra a su cuenta con su pedido anterior (${u3 && u3.orders})`);
  await p.context().close();

  // 4. Gestión: enviar acceso / copiar enlace a una clienta antigua.
  const e4 = mail('antigua');
  mkOrder(e4, 'completed', 'Marta');
  const o4 = mkOrder(e4, 'processing', 'Marta');
  const g = await fresh();
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const gapi = (path, body) => g.evaluate(async ([p2, bd]) => { const r = await fetch(bkG.api + p2, { method: bd ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': bkG.nonce, 'Content-Type': 'application/json' }, body: bd ? JSON.stringify(bd) : undefined, cache: 'no-store' }); return { s: r.status, j: await r.json() }; }, [path, body]);
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.reload(); await g.waitForSelector(`[data-order="${o4.id}"]`);
  await g.locator(`[data-order="${o4.id}"]`).first().click();
  await g.waitForSelector('#bk-g-acc .bk-g-acc__state');
  ok(/Sin cuenta/.test(await g.locator('#bk-g-acc').textContent()), 'detalle del pedido: "Cuenta de la clienta · Sin cuenta"');
  await g.locator('#bk-g-acc').scrollIntoViewIfNeeded();
  await g.screenshot({ path: `${SHOT}/gestion-cuenta-1440.png` });
  await g.click('[data-acc-preview]');
  await g.waitForSelector('#bk-g-acc iframe');
  ok(/cuenta en Buckler Jeans está lista/.test(await g.locator('#bk-g-acc .bk-g-pvmeta').textContent()), 'vista previa del correo "Tu cuenta está lista"');
  ok(userOf(e4) === null, 'la vista previa no crea la cuenta');
  await g.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  await g.click('[data-acc-link]');
  await g.waitForSelector('#bk-g-acc input[readonly]');
  const link = await g.locator('#bk-g-acc input[readonly]').inputValue();
  ok(/crear-contrasena\/\?u=\d+&k=/.test(link) && /Cuenta creada/.test(await g.locator('#bk-g-acc').textContent()), 'Copiar enlace: crea la cuenta y deja el enlace');
  const u4 = userOf(e4);
  ok(u4 && u4.orders === 2, `la cuenta tiene sus 2 pedidos (${u4 && u4.orders})`);
  const st = await gapi(`orders/${o4.id}/account`);
  ok(st.j.status === 'pending' && st.j.sent && st.j.sent.how === 'enlace', 'estado: cuenta creada, falta su contraseña');
  const hist = await gapi(`orders/${o4.id}`);
  ok(JSON.stringify(hist.j.history || []).includes('Acceso a Mi cuenta'), 'historial del pedido: "Acceso a Mi cuenta"');
  // Enviar por correo
  const mailsDir = '/tmp/claude-0/bk-local/site/wp-content/uploads/bk-mails';
  const before = fs.existsSync(mailsDir) ? fs.readdirSync(mailsDir).length : 0;
  const sent = await gapi(`orders/${o4.id}/account`, { action: 'send' });
  const after = fs.existsSync(mailsDir) ? fs.readdirSync(mailsDir).length : 0;
  ok(sent.s === 200 && after > before, 'Enviar acceso: correo enviado');
  const last = fs.readdirSync(mailsDir).filter((f) => f.endsWith('.html')).map((f) => [f, fs.statSync(`${mailsDir}/${f}`).mtimeMs]).sort((a, c) => c[1] - a[1])[0][0];
  const body = fs.readFileSync(`${mailsDir}/${last}`, 'utf8');
  ok(body.includes('crear-contrasena') && body.includes(e4) && !/contraseña es|password:/i.test(body), 'el correo trae el enlace y el usuario, nunca una contraseña');

  // 5. Página del enlace: el primer enlace quedó reemplazado por el del correo (solo vale el último).
  p = await fresh(390);
  await p.goto(link);
  ok(/ya no funciona/.test(await p.locator('h1').textContent()), 'enlace anterior: "Este enlace ya no funciona"');
  const newLink = (body.match(/href="([^"]*crear-contrasena[^"]*)"/) || [])[1].replace(/&amp;|&#038;/g, '&');
  await p.goto(newLink);
  ok(/Hola, Marta/.test(await p.locator('h1').textContent()), 'enlace del correo: "Hola, Marta"');
  await p.screenshot({ path: `${SHOT}/crear-contrasena-390.png` });
  await p.fill('#pass1', 'Clave-segura-3'); await p.fill('#pass2', 'Otra-clave-3');
  await Promise.all([p.waitForNavigation(), p.click('.btn')]);
  ok(/no coinciden/.test(await p.locator('.err').textContent()), 'contraseñas distintas: error');
  await p.fill('#pass1', 'Clave-segura-3'); await p.fill('#pass2', 'Clave-segura-3');
  await Promise.all([p.waitForNavigation(), p.click('.btn')]);
  ok(p.url().includes('bk_bienvenida=1') && /contraseña quedó guardada/.test(await p.content()), 'guarda la contraseña y entra a Mi cuenta con bienvenida');
  await p.screenshot({ path: `${SHOT}/bienvenida-390.png` });
  await p.goto(newLink);
  ok(/ya no funciona/.test(await p.locator('h1').textContent()), 'el enlace sirve una sola vez');
  ok((await gapi(`orders/${o4.id}/account`)).j.status === 'active', 'gestión: ahora "Ya tiene cuenta"');
  await p.context().close();
  // Vencido
  const e5 = mail('vencido'); const o5 = mkOrder(e5, 'completed', 'Ana');
  const l5 = (await gapi(`orders/${o5.id}/account`, { action: 'link' })).j.url;
  php(`$u = get_user_by( "email", "${e5}" ); update_user_meta( $u->ID, "_bk_acc_exp", time() - 60 );`);
  p = await fresh(); await p.goto(l5);
  ok(/ya no funciona/.test(await p.locator('h1').textContent()), 'enlace vencido (72 h): no funciona');
  await p.context().close();
  // Cuenta del equipo
  const staff = mkOrder(php('echo get_user_by( "login", "gestora" )->user_email;'), 'completed');
  const sf = await gapi(`orders/${staff.id}/account`, { action: 'send' });
  ok(sf.s === 400 && /equipo/.test(sf.j.message), 'correo de una cuenta del equipo: no se envía');

  // 6. Envío masivo
  const e6a = mail('masivo-a'); const e6b = mail('masivo-b');
  mkOrder(e6a, 'completed'); mkOrder(e6a, 'processing'); mkOrder(e6b, 'processing'); mkOrder(mail('masivo-pend'), 'pending');
  php('update_option( "bk_test_acc_bulk_batch", 200 );');
  let bk = await gapi('access/bulk');
  ok(bk.j.pending >= 2, `masivo: ${bk.j.pending} clientas con pedidos pagados y sin cuenta`);
  await g.goto(B + '/gestion-tienda/#correos'); await g.reload(); await g.waitForSelector('#bk-g-accbulk [data-bulk-ask], #bk-g-accbulk .bk-g-accbulk__stats');
  await g.screenshot({ path: `${SHOT}/gestion-masivo-1440.png` });
  await g.click('[data-bulk-ask]');
  ok(await g.locator('[data-bulk-start]').count() === 1, 'pide confirmación antes de enviar');
  await Promise.all([g.waitForResponse((r) => r.url().includes('access/bulk') && r.request().method() === 'POST', { timeout: 120000 }), g.click('[data-bulk-start]')]);
  await g.waitForTimeout(400);
  await g.screenshot({ path: `${SHOT}/gestion-masivo-hecho-1440.png` });
  bk = await gapi('access/bulk');
  ok(bk.j.run && bk.j.run.sent >= 2 && userOf(e6a) && userOf(e6b), `masivo: ${bk.j.run.sent} accesos enviados y cuentas creadas`);
  ok(userOf(mail('masivo-pend')) === null, 'pedido sin pagar: no recibe acceso');
  ok(bk.j.pending === 0 || !bk.j.pending, 'nadie queda pendiente (no se repite)');
  const again = await gapi('access/bulk', { action: 'start' });
  ok(again.s === 400, 'volver a enviar sin pendientes: "No hay clientas pendientes"');

  ok(errs.length === 0, 'sin errores de JS ' + errs.join(' | '));
  await b.close();
  php('delete_option( "bk_test_acc_bulk_batch" );');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
