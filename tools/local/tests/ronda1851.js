// 0.18.51: detalle del pedido en media pantalla, barra de estado y dos columnas (pedido | clienta).
const { chromium } = require('playwright');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
(async () => {
  const b = await chromium.launch(); const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1920, height: 1080 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#pedidos'); await g.waitForSelector('[data-order]');
  await g.locator('[data-order]').first().click(); await g.waitForSelector('.bk-g-ogrid'); await g.waitForTimeout(600);
  const w = await g.evaluate(() => document.querySelector('.bk-g-drawer__panel').getBoundingClientRect().width);
  ok(w >= 940 && w <= 980, `1920 px: el panel ocupa la mitad (${Math.round(w)} px)`);
  const cols = await g.evaluate(() => [...document.querySelectorAll('.bk-g-ocol')].map((c) => ({ x: Math.round(c.getBoundingClientRect().left), h: [...c.children].map((e) => e.querySelector('h3')?.textContent.trim().split('\n')[0] || e.tagName) })));
  ok(cols.length === 2 && cols[1].x > cols[0].x + 200, 'dos columnas lado a lado');
  ok(/Productos/.test(cols[0].h.join()) && /Clienta/.test(cols[1].h.join()) && /Pedidos de esta clienta/.test(cols[1].h.join()), `izquierda: ${cols[0].h.join(' / ')} | derecha: ${cols[1].h.join(' / ')}`);
  ok(await g.isVisible('.bk-g-dhead__tot b'), 'total grande en el encabezado');
  const bar = await g.evaluate(() => { const r = (s) => document.querySelector(s).getBoundingClientRect().top; return Math.abs(r('#bk-g-ostatus') - r('#bk-g-osave')) < 12; });
  ok(bar, 'estado, checks y "Guardar estado" en una sola fila');
  // Marcar Impreso desde la barra y guardar.
  const was = await g.isChecked('#bk-g-oprinted');
  await g.click('label.bk-g-ocheck:has(#bk-g-oprinted)');
  ok(await g.evaluate(() => getComputedStyle(document.querySelector('label.bk-g-ocheck:has(#bk-g-oprinted)')).backgroundColor) !== 'rgb(255, 255, 255)' || was, 'el check marcado se pone verde');
  await g.click('#bk-g-osave'); await g.waitForTimeout(1500);
  await g.click('#bk-g-oprinted'); await g.click('#bk-g-osave'); await g.waitForTimeout(1200); // vuelve como estaba
  ok(await g.isChecked('#bk-g-oprinted') === was, 'guardar estado sigue funcionando');
  await g.setViewportSize({ width: 1440, height: 900 }); await g.waitForTimeout(300);
  const w2 = await g.evaluate(() => document.querySelector('.bk-g-drawer__panel').getBoundingClientRect().width);
  ok(w2 >= 790 && w2 <= 810, `1440 px: panel de 800 px (${Math.round(w2)})`);
  await g.setViewportSize({ width: 390, height: 844 }); await g.waitForTimeout(300);
  const one = await g.evaluate(() => { const c = [...document.querySelectorAll('.bk-g-ocol')]; return c[1].getBoundingClientRect().top > c[0].getBoundingClientRect().bottom - 2; });
  ok(one, '390 px: una sola columna');
  ok(await g.evaluate(() => document.querySelector('.bk-g-drawer__panel').scrollWidth <= 390), '390 px sin desborde');
  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK'); process.exit(fails ? 1 : 0);
})();
