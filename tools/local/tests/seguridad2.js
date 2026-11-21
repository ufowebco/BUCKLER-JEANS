// 0.18.38: datos de personas que no deben verse sin sesión (reseñas, comentarios, autor de páginas y fotos).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  // Reseña de prueba en un producto (como las de producción).
  const pid = +php('$p = wc_get_products( array( "limit" => 1, "status" => "publish", "type" => "variable" ) ); echo $p[0]->get_id();');
  php(`foreach ( get_comments( array( "author_email" => "diana.prueba@ejemplo.com" ) ) as $c ) { wp_delete_comment( $c->comment_ID, true ); } $id = wp_insert_comment( array( "comment_post_ID" => ${pid}, "comment_author" => "Diana Navarro Pérez", "comment_author_email" => "diana.prueba@ejemplo.com", "comment_author_url" => "https://diana.example", "comment_content" => "Me encantó, la talla perfecta.", "comment_type" => "review", "comment_approved" => 1 ) ); update_comment_meta( $id, "rating", 5 ); WC_Comments::clear_transients( ${pid} );`);
  const b = await chromium.launch();
  const anon = await b.newContext();
  const get = async (u) => { const r = await anon.request.get(B + u); return { s: r.status(), j: await r.json().catch(() => null), t: await r.text() }; };

  const rv = await get(`/wp-json/wc/store/v1/products/reviews?product_id=${pid}`);
  const mine = (rv.j || []).find((x) => /talla perfecta/.test(x.review || ''));
  ok(rv.s === 200 && mine, 'las reseñas siguen públicas (la tienda las puede mostrar)');
  ok(mine && mine.reviewer === 'Diana N.', `sin sesión: nombre corto en la reseña ("${mine && mine.reviewer}")`);
  ok(mine && !/gravatar/.test(JSON.stringify(mine)) && !/diana\.prueba/.test(rv.t), 'sin sesión: sin avatar de Gravatar ni correo');

  const cm = await get(`/wp-json/wp/v2/comments?post=${pid}&type=review`);
  ok(!/Navarro|gravatar|diana\.example|diana\.prueba/.test(cm.t), `sin sesión: comentarios de WordPress sin nombre completo, enlace ni avatar (${cm.s})`);

  const pg = await get('/wp-json/wp/v2/pages?per_page=3');
  ok(pg.s === 200 && Array.isArray(pg.j) && pg.j.length && pg.j.every((x) => !('author' in x)) && !/wp\/v2\/users/.test(pg.t), 'sin sesión: páginas sin el número de usuario ni enlace a /users');
  const md = await get('/wp-json/wp/v2/media?per_page=3');
  ok(md.s === 200 && (md.j || []).every((x) => !('author' in x)) && !/wp\/v2\/users/.test(md.t), 'sin sesión: fotos sin el número de usuario');
  const pr = await get(`/wp-json/wp/v2/product/${pid}`);
  ok(pr.s !== 200 || (!('author' in (pr.j || {})) && !/wp\/v2\/users/.test(pr.t)), `sin sesión: producto sin autor (${pr.s})`);
  ok((await get('/wp-json/wp/v2/users')).s === 401, 'sigue: /wp/v2/users → 401');

  // Con sesión (equipo): todo completo.
  const g = await (await b.newContext()).newPage();
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const full = await g.evaluate(async (pid) => {
    const h = { 'X-WP-Nonce': bkG.nonce };
    const r = await (await fetch(`/wp-json/wc/store/v1/products/reviews?product_id=${pid}`, { headers: h })).json();
    const pg = await (await fetch('/wp-json/wp/v2/pages?per_page=1', { headers: h })).json();
    return { rv: (r.find((x) => /talla perfecta/.test(x.review)) || {}).reviewer, page: 'author' in (pg[0] || {}) };
  }, pid);
  ok(full.rv === 'Diana Navarro Pérez' && full.page, `con sesión del equipo: nombre completo y autor visibles (${JSON.stringify(full)})`);

  php('foreach ( get_comments( array( "author_email" => "diana.prueba@ejemplo.com" ) ) as $c ) { wp_delete_comment( $c->comment_ID, true ); }');
  await b.close();
  console.log(fails ? `\n${fails} FAIL` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
