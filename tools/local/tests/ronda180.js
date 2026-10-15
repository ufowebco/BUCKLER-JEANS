// 0.18.0: wp-admin y wp-login.php ocultos (sin sesión → inicio); el acceso es /ingreso/. Lo interno sigue funcionando.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  php('delete_option("bk_hide_login");'); // Encendido por defecto.
  execSync(`php ${__dirname}/seed-adminmenu.php`);
  const b = await chromium.launch();
  const errs = [];
  const anon = await b.newContext();
  const home = (loc) => loc === B + '/' || loc === '/';

  // 1. Sin sesión: todo lo conocido de WordPress lleva al inicio.
  for (const u of ['/wp-admin/', '/wp-admin/edit.php', '/wp-admin/options-general.php?page=bk-modules', '/wp-login.php', '/wp-login.php?action=register', '/login', '/admin', '/dashboard']) {
    const r = await anon.request.get(B + u, { maxRedirects: 0 });
    ok([301, 302].includes(r.status()) && home(r.headers().location), `sin sesión: ${u} → inicio (${r.status()})`);
  }
  const post = await anon.request.post(B + '/wp-login.php', { form: { log: 'admin', pwd: 'admin' }, maxRedirects: 0 });
  ok([301, 302].includes(post.status()) && home(post.headers().location) && !/wordpress_logged_in/.test(JSON.stringify(post.headers())), 'intentar entrar por wp-login.php (POST): va al inicio y no inicia sesión');
  const nc = await anon.request.get(B + '/wp-admin/', { maxRedirects: 0 });
  ok(/no-cache|no-store/.test(nc.headers()['cache-control'] || ''), 'la redirección no queda en caché');

  // 2. Lo interno sigue funcionando.
  const ajax = await anon.request.post(B + '/wp-admin/admin-ajax.php', { form: { action: 'bk_nada' }, maxRedirects: 0 });
  ok(ajax.status() === 400, `admin-ajax.php sigue disponible para la tienda (${ajax.status()})`);
  const apost = await anon.request.post(B + '/wp-admin/admin-post.php', { form: { action: 'bk_nada' }, maxRedirects: 0 });
  ok(![301, 302].includes(apost.status()) || !home(apost.headers().location), `admin-post.php sigue disponible (${apost.status()})`);
  const p = await anon.newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  await p.goto(B + '/product/jean-bota-campana-flare-dama-tiro-alto/');
  await p.locator('.bk-sizes input:not(:disabled)').first().check({ force: true }).catch(() => {});
  ok((await anon.request.get(B + '/wp-json/wc/store/v1/cart')).status() === 200 && (await anon.request.get(B + '/?wc-ajax=get_refreshed_fragments')).status() < 400, 'carrito (Store API y wc-ajax) sin cambios');

  // 3. /ingreso/ sigue siendo la entrada; "Salir" de WordPress funciona sin wp-login.php.
  await p.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/wp-admin/'));
  await p.fill('[name=log]', 'sebas_ufoweb'); await p.fill('[name=pwd]', 'Clave#2026');
  await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]);
  ok(/\/wp-admin\/?$/.test(p.url()) && await p.locator('#wpadminbar').count() === 1, 'con /ingreso/ se entra a wp-admin normal');
  const out = await p.getAttribute('#wp-admin-bar-logout a', 'href');
  ok(/\/ingreso\/\?action=logout/.test(out) && !/wp-login/.test(out), `"Cerrar sesión" de WordPress apunta a /ingreso/ (${(out || '').replace(B, '').slice(0, 40)}…)`);

  // 4. Interruptor en Ajustes → Buckler Core.
  await p.goto(B + '/wp-admin/options-general.php?page=bk-modules');
  ok(await p.isChecked('[name=bk_hide_login]') && /define\( 'BK_HIDE_LOGIN', false \)/.test(await p.textContent('.wrap')), 'Ajustes: interruptor encendido y cómo apagarlo en una emergencia');
  await p.uncheck('[name=bk_hide_login]');
  await Promise.all([p.waitForNavigation(), p.click('form:has([value="bk_hide_login_save"]) [type=submit]')]);
  const r2 = await (await b.newContext()).request.get(B + '/wp-admin/', { maxRedirects: 0 });
  ok(/\/ingreso\//.test(r2.headers().location || ''), 'apagado: wp-admin sin sesión vuelve a pedir el acceso (/ingreso/)');
  await p.check('[name=bk_hide_login]');
  await Promise.all([p.waitForNavigation(), p.click('form:has([value="bk_hide_login_save"]) [type=submit]')]);
  ok(php('echo get_option("bk_hide_login");') === 'yes', 'encendido otra vez');
  await p.click('#wp-admin-bar-logout a', { force: true }).catch(async () => { await p.goto(out.replace(/&amp;/g, '&')); });
  await p.waitForLoadState('load');
  const logged = await p.context().cookies();
  ok(!logged.some((c) => /wordpress_logged_in/.test(c.name)) && home(p.url()), `"Cerrar sesión" cierra y lleva al inicio (${p.url().replace(B, '')})`);

  // 5. Contraseña olvidada y enlaces de los correos.
  const lp = await anon.request.get(B + '/ingreso/?action=lostpassword', { maxRedirects: 0 });
  ok(/my-account\/lost-password/.test(lp.headers().location || ''), '"¿Olvidaste tu contraseña?" → Mi cuenta (WooCommerce)');
  const key = php('echo get_password_reset_key(get_user_by("login","Gestor_ADS"));');
  const rp = await (await b.newContext()).newPage();
  await rp.goto(`${B}/ingreso/?action=rp&key=${key}&login=Gestor_ADS`);
  ok(await rp.locator('#pass1').count() === 1, 'enlace de restablecer contraseña de WordPress (con clave): abre el formulario');
  await rp.fill('#pass1', 'NuevaClave#2026x'); await rp.locator('#pass1').dispatchEvent('input');
  await rp.locator('.pw-checkbox, [name=pw_weak]').check().catch(() => {});
  await Promise.all([rp.waitForNavigation(), rp.click('#wp-submit')]);
  const okReset = /restablecido|has been reset/i.test(await rp.textContent('body'));
  ok(okReset, 'la contraseña se cambia (el formulario de wp-login.php sigue enviando a sí mismo)');
  const lg = await (await b.newContext()).newPage();
  await lg.goto(B + '/ingreso/'); await lg.fill('[name=log]', 'Gestor_ADS'); await lg.fill('[name=pwd]', 'NuevaClave#2026x');
  await Promise.all([lg.waitForNavigation(), lg.click('.bk-g-login__form [type=submit]')]);
  ok(/gestion-tienda/.test(lg.url()), 'entra con la contraseña nueva por /ingreso/');
  php('wp_set_password("Clave#2026", get_user_by("login","Gestor_ADS")->ID);');
  const bad = await anon.request.get(B + '/wp-login.php?action=rp&key=x&login=y', { maxRedirects: 0 });
  ok(![301, 302].includes(bad.status()) || !home(bad.headers().location), 'enlace con clave (aunque sea inválida) lo responde WordPress, no el inicio');

  // 6. Entradas con contraseña (formulario de WordPress que va a wp-login.php?action=postpass).
  const pp = await anon.request.post(B + '/wp-login.php?action=postpass', { form: { post_password: 'x' }, headers: { Referer: B + '/' }, maxRedirects: 0 });
  ok(/wp-postpass_/.test(JSON.stringify(pp.headers())), 'páginas con contraseña siguen funcionando');

  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { php('update_option("bk_hide_login","yes");'); console.error(e); process.exit(1); });
