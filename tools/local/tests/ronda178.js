// 0.17.8: acceso propio /ingreso/ (en vez de wp-login.php) y protección + registro de redirecciones ajenas en la
// gestión (otro plugin/snippet manda al inicio antes de que WordPress lea la URL).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  php('if(!get_user_by("login","Gestor_ADS")){ $id=wp_create_user("Gestor_ADS","Clave#2026","ads@ejemplo.com"); (new WP_User($id))->set_role("contributor"); } delete_option("bk_g_seo_users"); delete_option("bk_test_login_home"); delete_option("bk_g_redirect_log");');
  const b = await chromium.launch();
  const errs = [];
  const fresh = async () => { const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message)); return { ctx, p }; };
  const submit = async (p, u, pw) => { await p.fill('[name=log]', u); await p.fill('[name=pwd]', pw); await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]); await p.waitForLoadState('load'); };

  // 1. /ingreso/ reemplaza a wp-login.php.
  let { ctx, p } = await fresh();
  const r = await p.goto(B + '/ingreso/');
  ok(r.status() === 200 && await p.locator('.bk-g-login__form').count() === 1 && /Acceso/.test(await p.textContent('.bk-g-login__brand')), '/ingreso/ muestra el formulario de acceso');
  ok(/noindex/.test(r.headers()['x-robots-tag'] || '') && /no-cache|no-store/.test(r.headers()['cache-control'] || ''), '/ingreso/ sin caché y sin indexar');
  await p.goto(B + '/wp-admin/');
  ok(/\/ingreso\/\?redirect_to=.*wp-admin.*reauth=1/.test(p.url()), `wp-admin sin sesión lleva a /ingreso/ (${p.url().replace(B, '')})`);
  await submit(p, 'admin', 'admin');
  ok(/\/wp-admin\/?$/.test(p.url()) && await p.locator('#wpadminbar').count() === 1, 'administrador: entra por /ingreso/ y llega a wp-admin');
  await p.goto(B + '/ingreso/');
  ok(/\/wp-admin\/?$/.test(p.url()), 'con la sesión abierta, /ingreso/ lleva directo a wp-admin');
  await ctx.close();

  for (const [u, pw, re, label] of [['gestora', 'gestora', /\/gestion-tienda\/$/, 'gestión'], ['Gestor_ADS', 'Clave#2026', /\/gestion-tienda\/#seo$/, 'gestión → SEO y ADS'], ['clienta', 'Clienta#2026', /\/my-account\/?$/, 'Mi cuenta']]) {
    ({ ctx, p } = await fresh());
    await p.goto(B + '/ingreso/'); await submit(p, u, pw);
    ok(re.test(p.url()), `${u}: entra por /ingreso/ y llega a ${label} (${p.url().replace(B, '')})`);
    await ctx.close();
  }
  ({ ctx, p } = await fresh());
  await p.goto(B + '/ingreso/'); await submit(p, 'gestora', 'mala');
  ok(/incorrectos/.test(await p.textContent('.bk-g-login__err')) && /\/ingreso\//.test(p.url()), 'contraseña mala en /ingreso/: mensaje y sigue en /ingreso/');
  await ctx.close();

  // 2. Otro plugin redirige al inicio ANTES de que WordPress lea la URL (wp_loaded): se evita y queda registrado.
  php('update_option("bk_test_login_home", "wp_loaded");');
  for (const [u, pw] of [['gestora', 'gestora'], ['Gestor_ADS', 'Clave#2026'], ['admin', 'admin']]) {
    ({ ctx, p } = await fresh());
    await p.goto(B + '/gestion-tienda/'); await submit(p, u, pw);
    ok(/gestion-tienda/.test(p.url()) && await p.locator('.bk-g-main').count() === 1, `${u}: un plugin redirige al inicio en wp_loaded → igual ve la gestión`);
    await ctx.close();
  }
  const log1 = JSON.parse(php('echo json_encode(get_option("bk_g_redirect_log"));'));
  ok(log1 && log1.length >= 3 && log1.every((x) => x.fixed && /bk-local-fixtures\.php/.test(x.by) && x.how === 'wp_redirect'), `registro: usuario, destino y archivo del plugin que redirigió (${log1 && log1[0] && log1[0].by})`);

  // 3. Con header() directo no se puede evitar, pero queda el origen (ganchos) en el registro.
  php('delete_option("bk_g_redirect_log"); update_option("bk_test_login_home", "wp_loaded_header");');
  ({ ctx, p } = await fresh());
  await p.goto(B + '/gestion-tienda/'); await submit(p, 'gestora', 'gestora');
  const log2 = JSON.parse(php('echo json_encode(get_option("bk_g_redirect_log"));'));
  ok(log2 && log2.length >= 1 && log2[0].how === 'header' && /wp_loaded/.test(log2[0].hooks) && log2[0].user === 'gestora', `header() directo: queda registrado con el gancho (${log2 && log2[0] && log2[0].hooks})`);
  await ctx.close();
  php('delete_option("bk_test_login_home");');

  // 4. El registro se ve en Ajustes → Buckler Core (solo para el usuario de la agencia, ver adminmenu.js).
  execSync(`php ${__dirname}/seed-adminmenu.php`);
  ({ ctx, p } = await fresh());
  await p.goto(B + '/ingreso/'); await submit(p, 'sebas_ufoweb', 'Clave#2026');
  await p.goto(B + '/wp-admin/options-general.php?page=bk-modules');
  const box = await p.textContent('.wrap');
  ok(/Acceso a la gestión/.test(box) && /\/ingreso\//.test(box) && /gestora/.test(box) && /No se pudo evitar/.test(box), 'Ajustes → Buckler Core: acceso propio y registro de redirecciones');
  await Promise.all([p.waitForNavigation(), p.click('form:has([value="bk_g_redirect_log_clear"]) [type=submit]')]);
  ok(/Sin registros/.test(await p.textContent('.wrap')), 'botón "Borrar registro"');
  await ctx.close();

  // 5. Sin redirecciones ajenas no se registra nada; la gestión normal sigue igual.
  ({ ctx, p } = await fresh());
  await p.goto(B + '/gestion-tienda/'); await submit(p, 'gestora', 'gestora');
  await p.goto(B + '/gestion-tienda/?salir=1&_wpnonce=x'); await p.waitForLoadState('load');
  ok(php('echo json_encode(get_option("bk_g_redirect_log", []));') === '[]', 'entrar y salir normal: nada en el registro (las redirecciones propias no cuentan)');
  await ctx.close();

  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
