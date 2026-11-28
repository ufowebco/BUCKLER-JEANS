// 0.18.52: número de pedidos de la clienta (todo su historial) junto al nombre en la lista de Pedidos.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
(async () => {
  const id = php('$o = wc_create_order(); $p = wc_get_products(array("limit"=>1,"type"=>"variable","status"=>"publish"))[0]; $o->add_product(wc_get_product($p->get_children()[0]), 1); $o->set_billing_first_name("Valentina"); $o->set_billing_last_name("Única"); $o->set_billing_email("valentina.unica.1852@ejemplo.com"); $o->set_billing_phone("3159870001"); $o->calculate_totals(); $o->set_status("processing"); $o->save(); echo $o->get_id();');
  const b = await chromium.launch(); const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('.bk-g-ncust');
  const first = await g.evaluate((id) => { const r = document.querySelector(`[data-row="${id}"] .bk-g-ncust`); return r && { n: r.querySelector('[aria-hidden]').textContent, rep: r.classList.contains('is-repeat'), t: r.title }; }, id);
  ok(first && first.n === '1' && !first.rep && /Primer pedido/.test(first.t), `clienta nueva: "1" gris (${JSON.stringify(first)})`);
  // Fila repetida: el número = conteo de "Pedidos de esta clienta" del detalle.
  const rep = await g.evaluate(() => { const e = document.querySelector('.bk-g-ncust.is-repeat'); return e && { id: e.closest('[data-row]').dataset.row, n: +e.querySelector('[aria-hidden]').textContent }; });
  ok(rep && rep.n > 1, `clienta recurrente: número azul (${rep && rep.n})`);
  await g.click(`[data-order="${rep.id}"]`); await g.waitForFunction(() => /\(\d+\)/.test(document.querySelector('#bk-g-rel h3 small')?.textContent || ''));
  const relN = +(await g.textContent('#bk-g-rel h3 small')).replace(/\D/g, '');
  ok(relN === rep.n, `coincide con "Pedidos de esta clienta" (${relN})`);
  const same = await g.evaluate(() => { const c = document.querySelector('.bk-g-cname'); const n = c.querySelector('.bk-g-ncust').getBoundingClientRect(), t = c.getBoundingClientRect(); return n.height <= 16 && Math.abs(n.top + n.height / 2 - (t.top + t.height / 2)) < 8; });
  ok(same, 'pequeño (≤ 16 px) y en la misma línea del nombre');
  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  php(`wc_get_order(${id})->delete(true); foreach (array(664) as $x) { $o = wc_get_order($x); if ($o && "Valentina" === $o->get_billing_first_name()) $o->delete(true); }`);
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK'); process.exit(fails ? 1 : 0);
})();
