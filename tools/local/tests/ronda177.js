// 0.17.7: entrar a /gestion-tienda/ aunque otro plugin mande al inicio, fotos del celular derechas + botones para
// girarlas, total de reseñas del perfil de Google al día, Mi cuenta legible con el CSS del tema de producción.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const T = '/tmp/claude-0/bk-local/t';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

// Reglas en línea del tema (Divi + hijo) copiadas de producción el 2026-10-15.
const THEME_CSS = `
.woocommerce-MyAccount-navigation ul { list-style: none; padding: 0; margin: 0; background: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.04); }
.woocommerce-MyAccount-navigation ul li { border-bottom: 1px solid #f0f0f0; transition: background-color .3s; }
.woocommerce-MyAccount-navigation ul li a { display: flex; align-items: center; padding: 18px 24px; color: #000; font-weight: 600; font-size: 15px; }
.woocommerce-MyAccount-navigation ul li:hover, .woocommerce-MyAccount-navigation ul li.is-active { background-color: #263953; }
.woocommerce-MyAccount-navigation ul li a:hover, .woocommerce-MyAccount-navigation ul li.is-active a { color: #e4ec6a; padding-left: 30px; }
.woocommerce-MyAccount-content a { color: #263953; font-weight: 600; text-decoration: none; border-bottom: 2px solid transparent; transition: .3s; }
.woocommerce-MyAccount-content a:hover { color: #000; border-bottom: 2px solid #e4ec6a; }
.woocommerce-account .woocommerce-MyAccount-content .button { background-color: #263953; color: #e4ec6a; border: none; border-radius: 8px; padding: 12px 28px; text-transform: uppercase; }
.woocommerce table.shop_table thead th { background-color: #263953; color: #e4ec6a; padding: 18px; border: none; }
.woocommerce table.shop_table td .button { background-color: transparent; color: #263953; border: 2px solid #263953; padding: 6px 16px; border-radius: 6px; }
.woocommerce table.shop_table td .button:hover { background-color: #e4ec6a; color: #263953; border-color: #e4ec6a; }`;

const contrast = (el) => {
  const parse = (c) => (c.match(/[\d.]+/g) || [0, 0, 0, 0]).map(Number);
  let bg = null; let n = el;
  while (n) { const a = parse(getComputedStyle(n).backgroundColor); if (a.length < 4 || a[3] > 0.5) { bg = a; break; } n = n.parentElement; }
  const L = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const a = L(parse(getComputedStyle(el).color)); const b = L(bg || [255, 255, 255]);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
};

(async () => {
  php('if(!get_user_by("login","Gestor_ADS")){ $id=wp_create_user("Gestor_ADS","Clave#2026","ads@ejemplo.com"); (new WP_User($id))->set_role("contributor"); } delete_option("bk_g_seo_users");');
  const b = await chromium.launch();
  const errs = [];

  // 1. Entrar a la gestión aunque otro plugin/snippet mande al inicio.
  const login = async (user, pass) => {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
    const g = await ctx.newPage();
    g.on('pageerror', (e) => errs.push(e.message));
    await g.goto(B + '/gestion-tienda/'); await g.fill('[name=log]', user); await g.fill('[name=pwd]', pass);
    await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
    await g.waitForLoadState('load'); await g.waitForTimeout(400);
    return { ctx, g, url: g.url(), app: await g.locator('.bk-g-main').count() };
  };
  for (const [mode, label] of [['wp_login', 'redirección al iniciar sesión (wp_redirect)'], ['header', 'redirección directa con header() al iniciar sesión'], ['template', 'usuarios sin rol de administrador → inicio en cualquier página']]) {
    php(`update_option("bk_test_login_home", "${mode}");`);
    for (const [u, pw] of [['gestora', 'gestora'], ['Gestor_ADS', 'Clave#2026']]) {
      const r = await login(u, pw);
      ok(/gestion-tienda/.test(r.url) && r.app === 1, `${u}: entra a la gestión con ${label} (${r.url.replace(B, '')})`);
      if (mode === 'template' && u === 'gestora') {
        await r.g.goto(B + '/gestion-tienda/#pedidos'); await r.g.waitForSelector('.bk-g-orders', { timeout: 8000 }).catch(() => {});
        ok(await r.g.locator('.bk-g-orders').count() === 1, 'con la sesión abierta, volver a /gestion-tienda/ no manda al inicio');
        await r.g.goto(B + '/'); await r.g.waitForTimeout(300);
        ok(!/gestion-tienda/.test(r.g.url()), 'después de entrar, ir al inicio a propósito ya no devuelve a la gestión');
      }
      await r.ctx.close();
    }
  }
  php('delete_option("bk_test_login_home");');
  const bad = await login('gestora', 'mala');
  ok(/incorrectos/.test(await bad.g.textContent('.bk-g-login__err').catch(() => '')), 'contraseña mala: sigue el mensaje de error');
  await bad.g.goto(B + '/'); await bad.g.waitForTimeout(300);
  ok(!/gestion-tienda/.test(bad.g.url()), 'intento fallido: no queda marcado "ir a la gestión"');
  await bad.ctx.close();

  // 2. Fotos: la del celular (acostada + EXIF "girar 90°") sube derecha; botones para girar.
  const { ctx, g } = await login('gestora', 'gestora');
  await g.goto(B + '/gestion-tienda/#productos/nuevo'); await g.waitForSelector('#bk-gp');
  const last = () => +php('$a=get_posts(["post_type"=>"attachment","posts_per_page"=>1,"orderby"=>"ID","order"=>"DESC","fields"=>"ids"]); echo $a[0];');
  const dims = (id) => JSON.parse(php(`$m=wp_get_attachment_metadata(${id}); echo json_encode([$m["width"],$m["height"]]);`));
  const before = last();
  await g.setInputFiles('input[name=photos]', [`${T}/rotada-exif6.jpg`, `${T}/vertical.jpg`]);
  await g.waitForFunction(() => document.querySelectorAll('[data-img-rot][data-dir="left"]').length === 2, null, { timeout: 30000 });
  const ids = JSON.parse(php(`echo json_encode(get_posts(["post_type"=>"attachment","posts_per_page"=>2,"orderby"=>"ID","order"=>"ASC","fields"=>"ids","post__not_in"=>range(1,${before})]));`));
  const d1 = dims(ids[0]); const d2 = dims(ids[1]);
  ok(ids.length === 2 && d1[1] > d1[0], `foto del celular con orientación EXIF: queda vertical (${d1.join('×')})`);
  ok(d2[1] > d2[0], `foto vertical normal: sigue vertical (${d2.join('×')})`);
  ok(await g.locator('[data-img-rot="0"][data-dir="left"]').count() === 1 && await g.locator('[data-img-rot="0"][data-dir="right"]').count() === 1, 'cada foto tiene botones "Girar a la izquierda" y "Girar a la derecha"');
  const src0 = await g.getAttribute('.bk-gp-imgs li:first-child img', 'src');
  await g.click('[data-img-rot="0"][data-dir="right"]');
  await g.waitForFunction((s) => document.querySelector('.bk-gp-imgs li:first-child img')?.getAttribute('src') !== s, src0, { timeout: 15000 });
  const d1r = dims(ids[0]);
  ok(d1r[0] === d1[1] && d1r[1] === d1[0], `girar a la derecha: la foto pasa a ${d1r.join('×')} y la vista se actualiza`);
  ok(/Foto girada/.test(await g.textContent('#bk-g-toast')), 'aviso "Foto girada"');
  await g.click('[data-img-rot="0"][data-dir="left"]'); await g.waitForTimeout(2500);
  const d1l = dims(ids[0]);
  ok(d1l[0] === d1[0] && d1l[1] === d1[1], `girar a la izquierda la devuelve (${d1l.join('×')})`);
  const files = JSON.parse(php(`$m=wp_get_attachment_metadata(${ids[0]}); $dir=dirname(get_attached_file(${ids[0]})); $base=preg_replace("/-r\\d+.*$/","",pathinfo($m["file"],PATHINFO_FILENAME)); echo json_encode(count(glob($dir."/".$base."*")));`));
  ok(files <= 12, `al girar se borran los archivos anteriores (${files} archivos de esa foto en uploads)`);
  const r403 = await g.evaluate(async () => (await fetch(window.bkG.api + 'media/999999/rotate', { method: 'POST', headers: { 'X-WP-Nonce': window.bkG.nonce, 'Content-Type': 'application/json' }, body: '{"dir":"left"}' })).status);
  ok(r403 === 404, 'girar una foto que no existe: 404');
  php(`foreach(${JSON.stringify(ids)} as $i){ wp_delete_attachment($i, true); }`);
  await ctx.close();
  const anon = await (await b.newContext()).newPage();
  const ra = await anon.evaluate(async (u) => (await fetch(u + '/wp-json/bk/v1/gestion/media/1/rotate', { method: 'POST' })).status, B).catch(() => 0);
  ok(ra === 401 || ra === 403, `girar sin sesión: rechazado (${ra})`);

  // 3. Reseñas: el total sale del perfil de Google que sincroniza WP Social Ninja (el de más reseñas) y se actualiza solo.
  const prod = '/product/jean-bota-campana-flare-dama-tiro-alto/';
  const info0 = php('echo json_encode(get_option("wpsr_reviews_google_business_info", null));');
  php('update_option("wpsr_reviews_google_business_info", ["viejo"=>["total_rating"=>40,"average_rating"=>4.8],"actual"=>["total_rating"=>92,"average_rating"=>4.9]]); update_option("bk_test_reviews_total", 1);');
  await anon.goto(B + prod);
  ok(/92 reseñas en Google/.test(await anon.textContent('body')), 'reseñas: toma el perfil con más reseñas (92) al guardar WP Social Ninja');
  php('update_option("wpsr_reviews_google_business_info", ["actual"=>["total_rating"=>95,"average_rating"=>4.9]]);');
  await anon.goto(B + prod);
  ok(/95 reseñas en Google/.test(await anon.textContent('body')), 'reseñas: nueva sincronización → el número cambia sin esperar');
  php('delete_option("bk_test_reviews_total");');
  await anon.goto(B + prod);
  const score = (await anon.textContent('.bk-reviews__score')).replace(/\s+/g, ' ').trim();
  ok(/4[,.]9 en Google$/.test(score) && !/reseñas/.test(score), `por defecto: solo calificación y estrellas "en Google", sin el total (${score})`);
  ok(await anon.locator('.bk-review__date').count() === 0 && await anon.locator('.bk-review').count() > 0, 'las tarjetas de reseñas no muestran la fecha');
  php(info0 === 'null' ? 'delete_option("wpsr_reviews_google_business_info"); bk_google_reviews_flush();' : `update_option("wpsr_reviews_google_business_info", json_decode(base64_decode("${Buffer.from(info0).toString('base64')}"), true));`);

  // 4. Mi cuenta con el CSS del tema de producción: textos y botones legibles, también al pasar el mouse.
  for (const vw of [1440, 390]) {
    const c = await b.newContext({ viewport: { width: vw, height: 900 } });
    const p = await c.newPage();
    p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(B + '/my-account/'); await p.fill('#username', 'clienta'); await p.fill('#password', 'Clienta#2026');
    await Promise.all([p.waitForNavigation(), p.click('[name=login]')]);
    const bad2 = [];
    for (const path of ['', 'orders/']) {
      await p.goto(B + '/my-account/' + path); await p.addStyleTag({ content: THEME_CSS }); await p.waitForTimeout(300);
      const els = await p.locator('.woocommerce a:visible, .woocommerce button:visible').all();
      for (const e of els) {
        const txt = ((await e.textContent()) || '').trim();
        if (!txt) continue;
        const base = await e.evaluate(contrast);
        await e.hover().catch(() => {}); await p.waitForTimeout(350);
        const hov = await e.evaluate(contrast);
        await p.mouse.move(0, 0);
        if (base < 3.5 || hov < 3.5) bad2.push(`${txt.slice(0, 20)} ${base.toFixed(1)}/${hov.toFixed(1)}`);
      }
      if (vw === 1440 && path === '') {
        const nav = await p.evaluate(() => { const a = document.querySelector('.bk-acc-nav li:not(.is-active) a'); const li = a.parentElement; return { pad: getComputedStyle(a).paddingLeft, border: getComputedStyle(li).borderBottomWidth }; });
        ok(nav.pad === '12px' && nav.border === '0px', `menú de la cuenta sin el relleno ni las líneas del tema (${nav.pad}, ${nav.border})`);
        await p.hover('.bk-acc-nav li:not(.is-active) a'); await p.waitForTimeout(350);
        ok(await p.evaluate(() => getComputedStyle(document.querySelector('.bk-acc-nav li:not(.is-active) a')).paddingLeft) === '12px', 'al pasar el mouse el menú no salta a la derecha');
        await p.screenshot({ path: '/tmp/claude-0/bk-local/r177-cuenta.png' });
      }
    }
    ok(!bad2.length, `Mi cuenta ${vw}px: todos los botones y enlaces legibles con y sin mouse encima ${bad2.join(' | ')}`);
    await c.close();
  }

  // 5. Ayuda: guías paso a paso por tarea y buscador.
  const h = await login('gestora', 'gestora');
  await h.g.goto(B + '/gestion-tienda/#ayuda'); await h.g.waitForSelector('.bk-g-task');
  const titles = await h.g.locator('.bk-g-task:not(.is-screen):not(.is-faq) .bk-g-task__t').allTextContents();
  const faqs = await h.g.locator('.bk-g-task.is-faq .bk-g-task__t').allTextContents();
  ok(titles.length >= 23 && ['Subir un producto nuevo', 'Atender los pedidos del día', 'Crear una campaña', 'Crear un descuento por cantidad', 'Recuperar un carrito', 'Cambiar los banners del inicio'].every((t) => titles.some((x) => x.startsWith(t))) && faqs.some((x) => /foto se ve acostada/.test(x)), `Ayuda: ${titles.length} guías paso a paso + preguntas (productos, pedidos, descuentos, carritos, contenido, problemas)`);
  await h.g.fill('#bk-g-hq', 'guia envio'); await h.g.waitForTimeout(200);
  const vis = await h.g.locator('.bk-g-task:not([hidden]) .bk-g-task__t').allTextContents();
  ok(vis.some((t) => /guía de envío/.test(t)) && await h.g.locator('.bk-g-task[open]:not([hidden])').count() === Math.min(3, vis.length) && /resultado/.test(await h.g.textContent('#bk-g-hq-n')), `buscador sin tildes: "guia envio" → ${vis.length} resultado(s), abre los primeros`);
  await h.g.fill('#bk-g-hq', ''); await h.g.waitForTimeout(200);
  ok(await h.g.locator('.bk-g-hmod[hidden], .bk-g-task[hidden]').count() === 0, 'buscador vacío: vuelve a mostrar todo');
  await h.ctx.close();
  const s = await login('Gestor_ADS', 'Clave#2026');
  await s.g.goto(B + '/gestion-tienda/#ayuda'); await s.g.waitForSelector('.bk-g-help');
  ok(!/Atender los pedidos|Subir un producto/.test(await s.g.textContent('.bk-g-help')), 'usuario de SEO y ADS: no ve las guías de pedidos y productos');
  await s.ctx.close();

  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
