// 0.18.17: "¡Últimas unidades!" en prendas de ¡Sale! con 1–2 prendas en alguna talla (ficha, aviso por talla y tarjeta).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  // Dos productos variables con 3+ tallas: A en ¡Sale! (rebaja propia), B sin rebaja.
  const [A, B2] = JSON.parse(php('$ids=[]; foreach(wc_get_products(["type"=>"variable","limit"=>60,"status"=>"publish","orderby"=>"ID","order"=>"ASC"]) as $p){ if(count($p->get_children())>=3 && !bk_disc_is_sale($p)) $ids[]=$p->get_id(); if(count($ids)==2) break; } echo json_encode($ids);'));
  // Guardar el estado de las variaciones para restaurar.
  const keep = php(`$o=[]; foreach([${A},${B2}] as $pid){ foreach(wc_get_product($pid)->get_children() as $c){ $v=wc_get_product($c); $o[$c]=[$v->get_manage_stock(),$v->get_stock_quantity(),$v->get_stock_status(),$v->get_sale_price("edit")]; } } echo base64_encode(json_encode($o));`);
  const set = (pid, fn) => php(`$k=wc_get_product(${pid})->get_children(); sort($k); foreach($k as $i=>$c){ $v=wc_get_product($c); ${fn} $v->save(); } bk_catalog_flush(${pid}); wc_delete_product_transients(${pid}); delete_transient("bk_card_${pid}"); bk_cache_purge();`);
  // A: rebaja en todas; talla 1 → 1 prenda, talla 2 → 2, resto 10.
  set(A, '$v->set_manage_stock(true); $v->set_stock_quantity($i===0?1:($i===1?2:10)); $v->set_stock_status("instock"); $r=(float)$v->get_regular_price("edit"); $v->set_sale_price((string)round($r*0.7));');
  // B: sin rebaja, también con 1 prenda en una talla.
  set(B2, '$v->set_manage_stock(true); $v->set_stock_quantity($i===0?1:10); $v->set_stock_status("instock");');
  const sizes = JSON.parse(php(`echo json_encode(array_column(bk_low_stock_sizes(wc_get_product(${A})),"label"));`));

  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  await p.goto(php(`echo get_permalink(${A});`) + '?v=' + Date.now(), { waitUntil: 'networkidle' });
  const box = p.locator('.bk-low-stock');
  ok(await box.count() === 1 && /¡Últimas unidades!/.test(await box.textContent()), `ficha en ¡Sale!: "${(await box.textContent().catch(() => '')).trim()}"`);
  ok(sizes.length === 2 && (await box.textContent()).trim() === '¡Últimas unidades!', `el recuadro dice solo "¡Últimas unidades!" (tallas con pocas: ${sizes.join(', ')})`);
  ok(await p.locator('.bk-size.is-low').count() === 2, 'las 2 tallas con pocas prendas llevan un punto');
  await p.locator(`.bk-size[data-value]`).filter({ hasText: new RegExp(`^${sizes[0]}$`) }).first().click();
  ok(/¡Solo queda 1 en esta talla!/.test(await p.textContent('.bk-size-low')), 'al elegir la talla con 1: "¡Solo queda 1 en esta talla!"');
  await p.locator(`.bk-size[data-value]`).filter({ hasText: new RegExp(`^${sizes[1]}$`) }).first().click();
  ok(/¡Solo quedan 2 en esta talla!/.test(await p.textContent('.bk-size-low')), 'con 2: "¡Solo quedan 2…"');
  await p.locator('.bk-size:not(.is-low):not(.is-out)').first().click();
  ok(await p.locator('.bk-size-low').isHidden(), 'con una talla con más existencias el aviso se oculta');

  // Producto sin rebaja: no dice nada aunque quede 1.
  await p.goto(php(`echo get_permalink(${B2});`) + '?v=' + Date.now(), { waitUntil: 'networkidle' });
  ok(await p.locator('.bk-low-stock').count() === 0 && await p.locator('.bk-size.is-low').count() === 0, 'producto sin ¡Sale!: sin aviso (solo aplica a ¡Sale!)');

  // Tarjeta en la tienda.
  await p.goto(B + '/shop/?v=' + Date.now(), { waitUntil: 'networkidle' });
  const card = p.locator(`.bk-card:has(a[href="${php(`echo get_permalink(${A});`)}"])`).first();
  const cardB = p.locator(`.bk-card:has(a[href="${php(`echo get_permalink(${B2});`)}"])`).first();
  ok(await card.count() === 1 && await card.locator('.bk-badge--low').count() === 0 && await p.locator('.bk-badge--low').count() === 0, 'grid: sin etiqueta "Últimas unidades" (solo dentro del producto, decisión del cliente)');
  ok(!(await cardB.count()) || await cardB.locator('.bk-badge--low').count() === 0, 'la tarjeta del producto sin rebaja tampoco');

  // Si se reponen las existencias, desaparece.
  set(A, '$v->set_stock_quantity(10);');
  await p.goto(php(`echo get_permalink(${A});`) + '?v=' + Date.now(), { waitUntil: 'networkidle' });
  ok(await p.locator('.bk-low-stock').count() === 0, 'con existencias repuestas ya no aparece');

  // Todas las tallas con 1.
  set(A, '$v->set_stock_quantity(1);');
  await p.goto(php(`echo get_permalink(${A});`) + '?v=' + Date.now(), { waitUntil: 'networkidle' });
  ok((await p.textContent('.bk-low-stock')).trim() === '¡Últimas unidades!', 'todas con 1: también solo "¡Últimas unidades!"');

  // Celular.
  set(A, '$v->set_stock_quantity($i===0?1:10);');
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage();
  await m.goto(php(`echo get_permalink(${A});`) + '?v=' + Date.now(), { waitUntil: 'networkidle' });
  ok(await m.locator('.bk-low-stock').isVisible() && (await m.textContent('.bk-low-stock')).trim() === '¡Últimas unidades!' && await m.evaluate(() => document.documentElement.scrollWidth <= 390), 'celular: "¡Últimas unidades!" sin desborde');
  await m.screenshot({ path: '/tmp/claude-0/bk-local/r1817-movil.png', clip: { x: 0, y: 300, width: 390, height: 700 } });

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`$o=json_decode(base64_decode("${keep}"),true); $ps=[]; foreach($o as $c=>$s){ $v=wc_get_product($c); $v->set_manage_stock($s[0]); $v->set_stock_quantity($s[1]); $v->set_stock_status($s[2]); $v->set_sale_price($s[3]); $v->save(); $ps[$v->get_parent_id()]=1; } foreach(array_keys($ps) as $pid){ bk_catalog_flush($pid); wc_delete_product_transients($pid); delete_transient("bk_card_".$pid); } bk_cache_purge();`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
