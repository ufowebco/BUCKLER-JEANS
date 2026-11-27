// 0.18.42/43: Black Friday primero en el inicio, barra centrada y etiquetas de tarjeta que no tapan a la modelo.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
(async () => {
  php('update_option( "bk_test_disc_backup", get_option( "bk_discounts" ) ); update_option( "bk_test_now", strtotime( "2026-11-27 10:00:00 America/Bogota" ) ); $s = strtotime( "2026-11-26 00:00:00 America/Bogota" ); $e = strtotime( "2026-11-30 23:59:00 America/Bogota" ); update_option( "bk_discounts", array( "campaigns" => array( array( "id" => "bf", "name" => "Black Friday", "pct" => 25, "start_ts" => $s, "end_ts" => $e, "scope" => "all", "exclude_sale" => true, "badge" => "BLACK FRIDAY", "bar" => true, "home" => true, "featured" => array(), "active" => true, "countdown" => true, "pre_days" => 3 ) ), "qty" => array() ) ); foreach ( wc_get_products( array( "limit" => 4, "status" => "publish", "type" => "variable" ) ) as $p ) { $p->set_featured( true ); $p->save(); }');
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  await p.clock.install({ time: new Date('2026-11-27T10:00:00-05:00') });
  await p.goto(B + '/');
  const order = await p.evaluate(() => { const cd = document.querySelector('.bk-cd--hero'), h = document.querySelector('.bk-hero'); return cd && h ? !!(cd.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING) : null; });
  ok(order === true, 'inicio: el bloque de Black Friday va antes del banner');
  ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-announce__item')).textAlign) === 'center', 'barra de anuncios centrada');
  await p.goto(B + '/shop/');
  const cards = await p.evaluate(() => [...document.querySelectorAll('.bk-card:not(.is-out)')].map((c) => ({ badges: c.querySelectorAll('.bk-card__badges .bk-badge').length, deal: c.querySelector('.bk-badge--deal')?.textContent.trim() || '', newOnPhoto: !!c.querySelector('.bk-card__media .bk-card__new, .bk-card__media .bk-badge--new'), newBody: !!c.querySelector('.bk-card__body .bk-card__new') })));
  ok(cards.length && cards.every((c) => c.badges <= 1), 'cada tarjeta lleva como máximo una etiqueta sobre la foto');
  ok(cards.some((c) => /^-25 % · BLACK FRIDAY$/.test(c.deal)), 'etiqueta combinada "-25 % · BLACK FRIDAY"');
  ok(cards.some((c) => c.newBody) && !cards.some((c) => c.newOnPhoto), 'NUEVO va debajo de la foto, junto a la línea');
  ok(await p.evaluate(() => document.documentElement.scrollWidth <= 390), '390 px sin desborde');
  await b.close();
  php('delete_option( "bk_test_now" ); update_option( "bk_discounts", get_option( "bk_test_disc_backup" ) ); delete_option( "bk_test_disc_backup" );');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK'); process.exit(fails ? 1 : 0);
})();
