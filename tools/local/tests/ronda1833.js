// 0.18.33: el interruptor "Nuevo" del editor de productos marca/desmarca al instante "LÍNEA NUEVA" y "NUEVO – línea"
// en "Todas las categorías" (y al revés), y al guardar quedan quitadas/puestas.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  // Como en producción: LÍNEA NUEVA con hijas "slim" (NUEVO – SLIM) y "curvy" (NUEVO – CURVY).
  const ids = JSON.parse(php('$p = get_term_by( "slug", "linea-nueva", "product_cat" ); $o = array( "parent" => $p->term_id ); foreach ( array( "slim" => "NUEVO – SLIM", "curvy" => "NUEVO – CURVY" ) as $s => $n ) { $t = get_term_by( "slug", $s, "product_cat" ); if ( ! $t ) { $r = wp_insert_term( $n, "product_cat", array( "slug" => $s, "parent" => $p->term_id ) ); $o[ $s ] = $r["term_id"]; } else { $o[ $s ] = $t->term_id; } } echo json_encode( $o );'));
  const pid = +php('$q = wc_get_products( array( "sku" => "9897", "limit" => 1 ) ); $p = $q[0]; $c = $p->get_category_ids(); $c[] = ' + ids.slim + '; $c[] = ' + ids.parent + '; $p->set_category_ids( array_unique( $c ) ); $p->set_featured( true ); $p->save(); echo $p->get_id();');
  const cats = () => JSON.parse(php(`$p = wc_get_product( ${pid} ); echo json_encode( array( "c" => array_map( "intval", $p->get_category_ids() ), "f" => $p->is_featured() ) );`));
  ok(cats().c.includes(ids.slim) && cats().f, 'producto de prueba (Slim) arranca como Nuevo con LÍNEA NUEVA + NUEVO – SLIM');

  const b = await chromium.launch();
  const errs = [];
  const g = await (await b.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const open = async () => { await g.goto(B + '/gestion-tienda/'); await g.goto(B + `/gestion-tienda/#productos/editar/${pid}`); await g.reload(); await g.waitForSelector('[name="is_new"]', { state: 'attached' }); };
  const tree = (id) => g.locator(`[name="all_cats"][value="${id}"]`).isChecked();
  const save = async () => { await g.locator('#bk-gp button[type=submit]').first().click(); await g.waitForFunction(() => /guardados|publicado/i.test(document.querySelector('#bk-g-toast')?.textContent || ''), null, { timeout: 20000 }).catch(async () => console.log('TOAST:', await g.locator('#bk-g-toast').textContent(), '| ERR:', await g.locator('.bk-g-err, [role=alert]').allInnerTexts())); await g.waitForTimeout(400); };

  await open();
  ok(await tree(ids.parent) && await tree(ids.slim), 'al abrir: LÍNEA NUEVA y NUEVO – SLIM marcadas en "Todas las categorías"');
  await g.locator('[name="is_new"]').uncheck();
  ok(!(await tree(ids.parent)) && !(await tree(ids.slim)), 'apagar Nuevo desmarca al instante LÍNEA NUEVA y NUEVO – SLIM');
  ok(/(\d+) marcadas/.test(await g.locator('.bk-gp-tree summary small').innerText()), 'el contador de categorías se actualiza');
  await save();
  let c = cats();
  ok(!c.c.includes(ids.parent) && !c.c.includes(ids.slim) && !c.f, 'guardado: sin LÍNEA NUEVA, sin NUEVO – SLIM y sin etiqueta Nuevo');
  ok(c.c.length > 0, 'las demás categorías se conservan');

  await open();
  await g.locator('[name="is_new"]').check();
  ok(await tree(ids.parent) && await tree(ids.slim) && !(await tree(ids.curvy)), 'encender Nuevo marca LÍNEA NUEVA + NUEVO – SLIM (no la de Curvy)');
  await save();
  c = cats();
  ok(c.c.includes(ids.parent) && c.c.includes(ids.slim) && !c.c.includes(ids.curvy) && c.f, 'guardado: vuelve a Nuevo con sus dos categorías');

  await open();
  await g.locator('.bk-gp-tree summary').click();
  await g.locator(`[name="all_cats"][value="${ids.slim}"]`).uncheck();
  ok(!(await g.locator('[name="is_new"]').isChecked()) && !(await tree(ids.parent)), 'desmarcar NUEVO – SLIM en la lista apaga el interruptor Nuevo');
  await g.locator(`[name="all_cats"][value="${ids.parent}"]`).check();
  ok(await g.locator('[name="is_new"]').isChecked() && await tree(ids.slim), 'marcar LÍNEA NUEVA en la lista enciende Nuevo y marca NUEVO – SLIM');

  // La estrella de la lista de productos hace lo mismo.
  await g.goto(B + '/gestion-tienda/#productos');
  ok(errs.length === 0, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  console.log(fails ? `\n${fails} FAIL` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
