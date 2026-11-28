// 0.18.52/53: número de pedidos DESPACHADOS de la clienta (todo su historial) junto al nombre en la lista de Pedidos.
// Despachado = check "Despachado" marcado o pedido Completado. Sin despachados → sin número.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const mk = (status, disp) => php(`$o = wc_create_order(); $p = wc_get_products(array("limit"=>1,"type"=>"variable","status"=>"publish"))[0]; $o->add_product(wc_get_product($p->get_children()[0]), 1); $o->set_billing_first_name("Valentina"); $o->set_billing_last_name("Única"); $o->set_billing_email("valentina.unica.1852@ejemplo.com"); $o->set_billing_phone("3159870001"); $o->calculate_totals(); $o->set_status("${status}"); ${disp ? '$o->update_meta_data(bk_g_fields()["despachado"], "yes");' : ''} $o->save(); echo $o->get_id();`);
(async () => {
  const ids = [mk('processing', false)];
  const b = await chromium.launch(); const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const badge = async (id) => { await g.goto(B + '/gestion-tienda/#pedidos'); await g.reload(); await g.waitForSelector(`[data-row="${id}"]`); return g.evaluate((id) => { const r = document.querySelector(`[data-row="${id}"] .bk-g-ncust`); return r ? { n: r.querySelector('[aria-hidden]').textContent, rep: r.classList.contains('is-repeat'), t: r.title } : null; }, id); };
  ok(await badge(ids[0]) === null, 'primer pedido sin despachar: sin número');
  ids.push(mk('processing', true)); // otro pedido de la misma clienta, con el check Despachado
  let x = await badge(ids[1]);
  ok(x && x.n === '1' && !x.rep && /1 pedido despachado/.test(x.t), `1 despachado (check): "1" gris (${JSON.stringify(x)})`);
  ids.push(mk('completed', false)); // Completado cuenta como despachado
  ids.push(mk('cancelled', true)); // cancelado no cuenta aunque tenga el check
  x = await badge(ids[2]);
  ok(x && x.n === '2' && x.rep, `check + Completado = "2" azul; cancelado no cuenta (${x && x.n})`);
  ok((await badge(ids[0]))?.n === '2', 'el pedido sin despachar de la misma clienta también muestra 2');
  const same = await g.evaluate(() => { const c = document.querySelector('.bk-g-cname:has(.bk-g-ncust)'); const n = c.querySelector('.bk-g-ncust').getBoundingClientRect(), t = c.getBoundingClientRect(); return n.height <= 16 && Math.abs(n.top + n.height / 2 - (t.top + t.height / 2)) < 8; });
  ok(same, 'pequeño (≤ 16 px) y en la misma línea del nombre');
  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  php(`foreach (array(${ids.join(',')}) as $x) { $o = wc_get_order($x); if ($o) $o->delete(true); }`);
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK'); process.exit(fails ? 1 : 0);
})();
