// 0.18.50: "Faltan por marcar" (Pago confirmado / Impreso / Despachado) junto al título de Pedidos + estados propios de
// pasarelas ("Transacción Aprobada") cuentan como venta pagada en la meta diaria y el resumen.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const sql = (q) => execSync(`mariadb -uroot --socket=/tmp/claude-0/bk-local/mysql.sock bk -N -e "${q}"`).toString().trim();
const counts = () => JSON.parse(php('$b = bk_g_todo_base_ids(); $o = array(); foreach ( array( "paid_ok", "printed", "dispatched" ) as $f ) { $i = bk_g_todo_ids( $f, $b ); $o[ $f ] = null === $i ? null : count( $i ); } echo wp_json_encode( $o );'));
(async () => {
  // Estados propios (sin registrar, como en la API rápida): uno aprobado y uno en espera de pago.
  const ids = sql("SELECT id FROM wp_wc_orders WHERE type='shop_order' AND status='wc-pending' ORDER BY id DESC LIMIT 2").split('\n').map(Number);
  const before = JSON.parse(php('echo wp_json_encode( wp_list_pluck( bk_g_daily_sales( 10 ), "count" ) );')).reduce((a, n) => a + n, 0);
  sql(`UPDATE wp_wc_orders SET status='wc-tx-aprobada', date_created_gmt=UTC_TIMESTAMP() WHERE id=${ids[0]}; UPDATE wp_wc_orders SET status='wc-espera-pago' WHERE id=${ids[1]};`);
  const paid = php('echo implode( ",", bk_g_paid_statuses() );');
  ok(/wc-tx-aprobada/.test(paid), 'estado propio "aprobada" cuenta como pagado');
  ok(!/espera-pago/.test(paid), 'estado propio "espera de pago" no cuenta');
  const after = JSON.parse(php('echo wp_json_encode( wp_list_pluck( bk_g_daily_sales( 10 ), "count" ) );')).reduce((a, n) => a + n, 0);
  ok(after === before + 1, `meta diaria suma el pedido "aprobado" (${before} → ${after})`);
  php('delete_transient( "bk_g_summary" );');

  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos');
  await g.waitForSelector('.bk-g-todo__pill');
  const c0 = counts();
  const pills = await g.$$eval('.bk-g-todo__pill', (els) => els.map((e) => ({ k: e.dataset.todo, n: e.querySelector('b').textContent.trim() })));
  ok(pills.length === Object.values(c0).filter((v) => v !== null).length, `una píldora por check configurado (${pills.map((p) => p.k).join(', ')})`);
  ok(pills.every((p) => p.n === (c0[p.k] ? String(c0[p.k]) : '✓')), `números iguales al servidor (${JSON.stringify(c0)})`);
  const head = await g.evaluate(() => { const h = document.querySelector('.bk-g-h1').getBoundingClientRect(), t = document.querySelector('#bk-g-todo').getBoundingClientRect(); return Math.abs(h.top + h.height / 2 - (t.top + t.height / 2)) < 20 && t.left > h.right; });
  ok(head, 'píldoras al lado del título');
  await g.screenshot({ path: '/tmp/claude-0/bk-local/r1850-1440.png' });

  // Tocar "Impreso" → solo los que faltan.
  await g.click('[data-todo="printed"]');
  await g.waitForSelector('.bk-g-todo__bar');
  ok(await g.getAttribute('[data-todo="printed"]', 'aria-pressed') === 'true', 'píldora activa (aria-pressed)');
  const bar = await g.textContent('.bk-g-todo__bar');
  ok(new RegExp(`${c0.printed} pedidos? sin imprimir`).test(bar), `barra: "${bar.replace(/\s+/g, ' ').trim()}"`);
  const rows = await g.$$eval('#bk-g-olist [data-flag="printed"]', (els) => els.map((e) => e.checked));
  ok(rows.length > 0 && rows.every((v) => !v), `lista solo con pedidos sin imprimir (${rows.length} filas)`);
  await g.screenshot({ path: '/tmp/claude-0/bk-local/r1850-filtro.png' });

  // Marcar uno → el número baja al instante; desmarcar → vuelve.
  const first = g.locator('#bk-g-olist [data-flag="printed"]').first();
  const oid = await first.getAttribute('data-id');
  await first.check();
  await g.waitForFunction((n) => document.querySelector('[data-todo="printed"] b').textContent.trim() === (n ? String(n) : '✓'), c0.printed - 1);
  ok(true, `al marcar impreso el número baja a ${c0.printed - 1}`);
  await g.waitForTimeout(1500);
  ok(counts().printed === c0.printed - 1, 'servidor confirma el nuevo número');
  await g.locator(`#bk-g-olist [data-flag="printed"][data-id="${oid}"]`).uncheck();
  await g.waitForFunction((n) => document.querySelector('[data-todo="printed"] b').textContent.trim() === String(n), c0.printed);
  ok(true, 'al desmarcar vuelve al número anterior');

  // "Ver todos" quita el filtro.
  await g.click('[data-todo-clear]');
  await g.waitForFunction(() => !document.querySelector('.bk-g-todo__bar'));
  ok(await g.getAttribute('[data-todo="printed"]', 'aria-pressed') === 'false', '"Ver todos" quita el filtro');
  // Tocar dos veces la misma píldora también.
  await g.click('[data-todo="paid_ok"]'); await g.waitForSelector('.bk-g-todo__bar');
  await g.click('[data-todo="paid_ok"]'); await g.waitForFunction(() => !document.querySelector('.bk-g-todo__bar'));
  ok(true, 'tocar otra vez la píldora quita el filtro');
  // Enlace directo #pedidos?todo=dispatched.
  await g.goto(B + '/gestion-tienda/#pedidos?todo=dispatched'); await g.reload();
  await g.waitForSelector('.bk-g-todo__bar');
  ok(/sin despachar/.test(await g.textContent('.bk-g-todo__bar')), 'enlace #pedidos?todo=dispatched abre filtrado');

  // Celular.
  await g.setViewportSize({ width: 390, height: 844 });
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.reload(); await g.waitForSelector('.bk-g-todo__pill');
  ok(await g.evaluate(() => document.documentElement.scrollWidth <= 390), '390 px sin desborde');
  const tap = await g.$$eval('.bk-g-todo__pill', (els) => els.every((e) => e.getBoundingClientRect().height >= 36));
  ok(tap, 'píldoras con área de toque ≥ 36 px');
  await g.screenshot({ path: '/tmp/claude-0/bk-local/r1850-390.png' });
  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  sql(`UPDATE wp_wc_orders SET status='wc-pending' WHERE id IN (${ids.join(',')});`);
  php('delete_transient( "bk_g_summary" );');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK'); process.exit(fails ? 1 : 0);
})();
