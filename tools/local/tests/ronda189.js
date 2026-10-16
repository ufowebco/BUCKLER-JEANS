// 0.18.11: "Ver este modelo en la otra línea" por REF (9898 ↔ 9898 Plus; si no, mismo número) y, si no existe o está
// agotado, "¿Buscas algo similar…? Mira {prenda} en {línea}" con la tienda filtrada por esa prenda.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  // Pareja por REF exacta: dos productos con nombres distintos (antes se emparejaban por nombre).
  const keep = JSON.parse(php('echo json_encode([wc_get_product(10)->get_sku(), wc_get_product(15)->get_sku(), wc_get_product(10)->get_name()]);'));
  php('$a=wc_get_product(10); $a->set_sku("7777 Azul"); $a->set_name("JEAN PRUEBA REF SLIM"); $a->save(); $b=wc_get_product(15); $b->set_sku("7777 azul PLUS"); $b->save(); bk_catalog_flush(10); bk_cache_purge();');
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext()).newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  const twin = async (id) => { await p.goto(php(`echo get_permalink(${id});`), { waitUntil: 'networkidle' }); const a = p.locator('.bk-pdp__twin'); return (await a.count()) ? { text: (await a.textContent()).trim(), href: await a.getAttribute('href'), similar: await a.evaluate((x) => x.classList.contains('is-similar')) } : null; };

  let t = await twin(10);
  ok(t && !t.similar && /Ver este modelo en Línea Curvy/.test(t.text) && t.href === php('echo get_permalink(15);'), `REF "7777 Azul" ↔ "7777 azul PLUS" aunque el nombre sea distinto: ${t && t.text}`);
  t = await twin(15);
  ok(t && !t.similar && /Línea Slim/.test(t.text) && t.href === php('echo get_permalink(10);'), 'y al revés (Curvy → Slim)');

  // Mismo número, distinto color ("784 Negro" ↔ "784 Verde Plus").
  t = await twin(176);
  ok(t && !t.similar && t.href === php('echo get_permalink(181);'), `mismo número de REF en otro color → ese modelo (${t && t.href.replace(B, '')})`);

  // Sin pareja: similares de la misma prenda.
  t = await twin(214);
  ok(t && t.similar && /¿Buscas algo similar en tallas 14 a 24\? Mira jeans en Línea Curvy/.test(t.text) && /\/linea-curvy\/\?prenda=jeans$/.test(t.href), `sin pareja: ${t && t.text} (${t && t.href.replace(B, '')})`);
  await p.goto(t.href, { waitUntil: 'networkidle' });
  ok(await p.locator('.bk-card').count() > 0 && /prenda=jeans/.test(p.url()) && /Línea Curvy/.test(await p.textContent('h1')), 'el enlace abre la Línea Curvy filtrada por jeans, con productos');

  // Pareja agotada → similares.
  php('foreach(wc_get_product(15)->get_children() as $c){ $v=wc_get_product($c); $v->update_meta_data("_bk_t_stock", $v->get_stock_quantity()."|".$v->get_stock_status()); $v->set_stock_quantity(0); $v->set_stock_status("outofstock"); $v->save(); } bk_catalog_flush(15);');
  t = await twin(10);
  ok(t && t.similar && /Mira jeans en Línea Curvy/.test(t.text), 'si la pareja está agotada: similares');
  php('foreach(wc_get_product(15)->get_children() as $c){ $v=wc_get_product($c); list($q,$s)=explode("|",$v->get_meta("_bk_t_stock")); $v->set_stock_quantity((int)$q); $v->set_stock_status($s); $v->delete_meta_data("_bk_t_stock"); $v->save(); } bk_catalog_flush(15);');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`$a=wc_get_product(10); $a->set_sku(${JSON.stringify(keep[0])}); $a->set_name(${JSON.stringify(keep[2])}); $a->save(); $b=wc_get_product(15); $b->set_sku(${JSON.stringify(keep[1])}); $b->save(); bk_catalog_flush(10); bk_cache_purge();`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
