// 0.18.14: el buscador de Pedidos encuentra el número que se ve (#3846), aunque un plugin de numeración lo guarde en
// `_order_number` y el ID interno sea otro. Los fixtures imitan ese plugin (filtro woocommerce_order_number).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const MAIL = 'numero@ejemplo.com';
const make = (name, num) => +php(`$o=wc_create_order(); $o->add_product(wc_get_product(24),1); $o->set_billing_first_name("${name}"); $o->set_billing_email("${MAIL}"); ${num ? `$o->update_meta_data("_order_number","${num}");` : ''} $o->calculate_totals(); $o->set_status("processing"); $o->save(); echo $o->get_id();`);

(async () => {
  php(`foreach(wc_get_orders(["billing_email"=>"${MAIL}","limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); }`);
  const a = make('Numero Propio', '98765');           // Número visible sin relación con el ID.
  const b = make('Numero Cambiado', '77777');         // Su ID lo "usa" como número otro pedido (c).
  const c = make('Numero Igual Al ID De B', String(b));
  const d = make('Sin Plugin', '');                   // Pedido viejo sin número propio: se busca por el ID.

  const b0 = await chromium.launch();
  const errs = [];
  const g = await (await b0.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('.bk-g-orders');
  const api = (q) => g.evaluate(async (q) => (await (await fetch(window.bkG.api + 'orders?q=' + encodeURIComponent(q), { headers: { 'X-WP-Nonce': window.bkG.nonce }, cache: 'no-store' })).json()).orders.map((o) => o.id), q);

  let r = await api('98765');
  ok(r.length === 1 && r[0] === a, `"98765" (número visible, el ID es ${a}) → ese pedido`);
  r = await api('#98765');
  ok(r.length === 1 && r[0] === a, '"#98765" también');
  r = await api(String(b));
  ok(r.length === 1 && r[0] === c, `"${b}" → el pedido que se ve como #${b}, no el que tiene ese ID por dentro`);
  r = await api('77777');
  ok(r.length === 1 && r[0] === b, '"77777" → el pedido con ese número visible');
  r = await api(String(d));
  ok(r.length === 1 && r[0] === d, 'pedido sin número propio: se encuentra por su ID');

  // En la pantalla: escribir el número muestra la fila con ese número.
  await g.fill('#bk-g-of [name=q]', '98765'); await g.waitForTimeout(2000);
  const rows = await g.$$eval('.bk-g-orders tbody tr', (x) => x.map((t) => t.textContent.replace(/\s+/g, ' ').trim()));
  ok(rows.length === 1 && /98765/.test(rows[0]) && /Numero Propio/.test(rows[0]), `en la lista: ${rows[0] && rows[0].slice(0, 60)}`);

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`foreach(wc_get_orders(["billing_email"=>"${MAIL}","limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); }`);
  await b0.close();
})().catch((e) => { console.error(e); process.exit(1); });
