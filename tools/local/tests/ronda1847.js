// 0.18.47: modo Navidad (1 de diciembre – 6 de enero) con interruptor en Gestión → Contenido → Temporada.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const SHOT = process.env.SHOT || '/tmp/claude-0/navidad';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const setNow = (t) => php(t ? `update_option( "bk_test_now", strtotime( "${t} America/Bogota" ) );` : 'delete_option( "bk_test_now" );');

(async () => {
  fs.mkdirSync(SHOT, { recursive: true });
  php('delete_option( "bk_xmas_mode" ); update_option( "bk_test_disc_backup", get_option( "bk_discounts" ) ); update_option( "bk_discounts", array( "campaigns" => array(), "qty" => array() ) );');
  const product = php('$q = wc_get_products( array( "limit" => 1, "type" => "variable", "status" => "publish", "stock_status" => "instock" ) ); echo get_permalink( $q[0]->get_id() );').replace(B, '');
  const b = await chromium.launch();
  const errs = [];
  const page = async (url, w = 1440, opts = {}) => { const p = await b.newPage({ viewport: { width: w, height: 900 }, ...opts }); p.on('pageerror', (e) => errs.push(e.message)); await p.goto(B + url); return p; };
  const isOn = (p) => p.evaluate(() => document.body.classList.contains('bk-xmas'));

  setNow('2026-11-27 10:00:00');
  let p = await page('/');
  ok(!(await isOn(p)), '27 de noviembre: modo Navidad apagado (automático)');
  await p.close();

  setNow('2026-12-01 00:01:00');
  p = await page('/');
  ok(await isOn(p), '1 de diciembre 12:01 a. m.: se prende solo');
  ok(/Felices fiestas/.test(await p.locator('.bk-announce__item').first().textContent()), 'franja: "¡Felices fiestas!" primero');
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-announce')).backgroundColor) === 'rgb(200, 16, 46)', 'franja en rojo navidad');
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-logo a'), '::after').backgroundImage.includes('data:image/svg')), 'gorro sobre el logo');
  const hat = await p.evaluate(() => { const a = document.querySelector('.bk-announce').getBoundingClientRect(); const l = document.querySelector('.bk-logo a').getBoundingClientRect(); const cs = getComputedStyle(document.querySelector('.bk-logo a'), '::after'); return { annBottom: a.bottom, logoTop: l.top, top: parseFloat(cs.top), h: parseFloat(cs.height) }; });
  ok(hat.logoTop + hat.top >= hat.annBottom - 6, `el gorro no queda debajo de la franja (gorro ${Math.round(hat.logoTop + hat.top)} px, franja termina en ${Math.round(hat.annBottom)} px)`);
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-bar'), '::before').backgroundImage.includes('data:image/svg')), 'guirnalda de luces bajo el menú');
  ok(await p.locator('.bk-xmas-home').count() === 1 && /regala el jean perfecto/.test(await p.locator('.bk-xmas-home').textContent()), 'inicio: bloque "Esta Navidad, regala el jean perfecto"');
  ok(await p.locator('.bk-xmas-ft').count() === 1, 'pie de página: "Felices fiestas te desea Buckler Jeans"');
  await p.screenshot({ path: `${SHOT}/inicio-1440.png` });
  await p.close();
  p = await page('/', 390);
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= 390), '390 px: sin desborde horizontal');
  await p.screenshot({ path: `${SHOT}/inicio-390.png` });
  await p.close();
  p = await page('/shop/', 390);
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-card__media'), '::before').backgroundImage.includes('data:image/svg')), 'tienda: nieve en el borde de las fotos');
  await p.close();
  p = await page(product, 390);
  ok(await p.locator('.bk-xmas-gift').count() === 1, 'ficha: "¿Es un regalo?" (una sola vez)');
  await p.screenshot({ path: `${SHOT}/ficha-390.png` });
  await p.close();
  p = await page('/', 1440, { reducedMotion: 'reduce' });
  ok(await p.evaluate(() => getComputedStyle(document.body, '::after').display) === 'none', 'con "reducir movimiento" no cae nieve');
  await p.close();

  setNow('2027-01-06 23:00:00');
  p = await page('/');
  ok(await isOn(p), '6 de enero 11:00 p. m.: sigue activo');
  await p.close();
  setNow('2027-01-07 00:01:00');
  p = await page('/');
  ok(!(await isOn(p)), '7 de enero 12:01 a. m.: se apaga solo');
  await p.close();

  // Interruptor de la gestión
  setNow('2026-12-05 10:00:00');
  const g = await b.newPage({ viewport: { width: 1440, height: 900 } });
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#contenido/temporada'); await g.reload();
  await g.waitForSelector('#bk-gseason');
  ok(/1 de December de 2026|1 de diciembre de 2026/.test(await g.locator('#bk-gseason').textContent()) && /6 de (January|enero) de 2027/.test(await g.locator('#bk-gseason').textContent()), 'gestión: muestra las fechas del 1 de diciembre al 6 de enero');
  ok(/Activo ahora/.test(await g.locator('#bk-gseason').textContent()), 'gestión: "Activo ahora" el 5 de diciembre');
  await g.screenshot({ path: `${SHOT}/gestion-temporada-1440.png` });
  await g.check('input[value="off"]'); await g.click('#bk-gseason [type=submit]');
  await g.waitForFunction(() => /Apagado ahora/.test(document.querySelector('#bk-gseason').textContent));
  p = await page('/');
  ok(!(await isOn(p)), 'Apagado: no se muestra aunque sea temporada');
  await p.close();
  setNow('2026-11-27 10:00:00');
  await g.reload(); await g.waitForSelector('#bk-gseason');
  await g.check('input[value="on"]'); await g.click('#bk-gseason [type=submit]');
  await g.waitForFunction(() => /Activo ahora/.test(document.querySelector('#bk-gseason').textContent));
  p = await page('/');
  ok(await isOn(p), 'Siempre encendido: se ve antes de la temporada');
  await p.close();
  await g.check('input[value="auto"]'); await g.click('#bk-gseason [type=submit]');
  await g.waitForFunction(() => /Apagado ahora/.test(document.querySelector('#bk-gseason').textContent));
  ok(true, 'vuelve a Automático');

  ok(errs.length === 0, 'sin errores de JS ' + errs.join(' | '));
  await b.close();
  setNow(null);
  php('delete_option( "bk_xmas_mode" ); update_option( "bk_discounts", get_option( "bk_test_disc_backup" ) ); delete_option( "bk_test_disc_backup" );');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
