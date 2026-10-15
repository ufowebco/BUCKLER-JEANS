// 0.17.5 (urgente): pedidos nuevos en la gestión. Estados de otros plugins/snippets (no registrados en la API
// rápida), botón "Buscar pedidos nuevos", revisión automática cada 10 minutos, resumen y caché.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
// Pedido nuevo como lo crearía el checkout (producto 22, talla 16).
const newOrder = (status, name) => php(`$o=wc_create_order(); $o->add_product(wc_get_product(24),1); $o->set_billing_first_name("${name}"); $o->set_billing_last_name("Nueva"); $o->set_billing_city("Medellín"); $o->set_billing_email("nueva@ejemplo.com"); $o->calculate_totals(); $o->set_status("${status}"); $o->save(); echo $o->get_id();`);

(async () => {
  const created = [];
  const b = await chromium.launch();
  const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.clock.install();
  const g = await ctx.newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/gestion-tienda/'); await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('.bk-g-orders');
  ok(await g.locator('[data-orders-refresh]').count() === 1 && /Actualizado/.test(await g.textContent('#bk-g-oupd')), 'botón "Buscar pedidos nuevos" y hora de la última actualización');

  // 1. Estado propio de otro plugin/snippet (no registrado en la API rápida).
  const idCustom = newOrder('processing', 'Estado Propio');
  created.push(idCustom);
  php(`global $wpdb; $wpdb->update($wpdb->prefix."wc_orders", ["status"=>"wc-pago-bold"], ["id"=>${idCustom}]); wp_cache_flush();`);
  const r = await g.evaluate(async () => { const res = await fetch(window.bkG.api + 'orders?status=all', { headers: { 'X-WP-Nonce': window.bkG.nonce } }); return { fast: res.headers.get('X-Bk-Fast'), cc: res.headers.get('Cache-Control'), ls: res.headers.get('X-LiteSpeed-Cache-Control'), d: await res.json() }; });
  ok(r.fast === '1' && r.d.orders.some((o) => +o.id === +idCustom), `"Todos": sale el pedido con un estado propio de otro plugin aunque la API rápida no lo cargue (X-Bk-Fast ${r.fast})`);
  ok(/no-store/.test(r.cc || '') && r.ls === 'no-cache', 'respuestas de la gestión sin caché (navegador, servidor y LiteSpeed)');

  // 2. Botón: llega un pedido nuevo y se ve sin recargar.
  const id1 = newOrder('processing', 'Botón');
  created.push(id1);
  await g.click('[data-orders-refresh]'); await g.waitForTimeout(1800);
  ok(await g.locator(`[data-row="${id1}"]`).count() === 1, 'botón: el pedido nuevo aparece en la lista');
  ok(await g.locator(`[data-row="${id1}"].is-new`).count() === 1 && /pedido(s)? nuevo/.test(await g.textContent('#bk-g-toast')), 'pedido nuevo resaltado y aviso "pedido nuevo"');
  await g.click('[data-orders-refresh]'); await g.waitForTimeout(1500);
  ok(/No hay pedidos nuevos/.test(await g.textContent('#bk-g-toast')), 'si no hay nada nuevo lo dice');

  // 3. Automático: a los 10 minutos revisa solo.
  const id2 = newOrder('on-hold', 'Automático');
  created.push(id2);
  await g.clock.fastForward('10:05'); await g.waitForTimeout(2500);
  ok(await g.locator(`[data-row="${id2}"]`).count() === 1, 'a los 10 minutos la lista se actualiza sola con el pedido nuevo');

  // 4. Resumen: no se queda con los números viejos.
  php('set_transient("bk_g_summary", ["viejo"=>1], 300);');
  const id3 = newOrder('processing', 'Resumen'); created.push(id3);
  ok(php('echo get_transient("bk_g_summary") ? "si" : "no";') === 'no', 'pedido nuevo: el resumen se vuelve a calcular');

  // 5. Recargar la página también los trae (sin caché del navegador).
  await g.reload(); await g.waitForSelector('.bk-g-orders');
  ok(await g.locator(`[data-row="${id3}"]`).count() === 1, 'recargar la página muestra el último pedido');

  php(`foreach([${created.join(',')}] as $i){ $o=wc_get_order($i); if($o){ $o->delete(true); } } delete_transient("bk_g_summary");`);
  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
