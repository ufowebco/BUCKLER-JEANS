// 0.18.0: wp-admin y wp-login.php ocultos (sin sesión → inicio; el acceso es /ingreso/, lo interno sigue funcionando)
// y centro de ayuda con capturas por tarea y marcas que se iluminan con cada paso.
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

  // 7. Centro de ayuda: cada tarea con su captura, marcas numeradas que se iluminan con el paso, ampliar.
  const h = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  h.on('pageerror', (e) => errs.push(e.message));
  await h.goto(B + '/gestion-tienda/'); await h.fill('[name=log]', 'gestora'); await h.fill('[name=pwd]', 'gestora');
  await Promise.all([h.waitForNavigation(), h.click('.bk-g-login__form [type=submit]')]);
  await h.goto(B + '/gestion-tienda/#ayuda'); await h.waitForSelector('.bk-g-task[open] .bk-g-shot img');
  const info = await h.evaluate(() => ({ mods: document.querySelectorAll('.bk-g-hmod').length, nav: document.querySelectorAll('.bk-g-hside a').length, tasks: [...document.querySelectorAll('.bk-g-task:not(.is-faq)')].map((t) => ({ t: t.querySelector('.bk-g-task__t').textContent, shots: t.querySelectorAll('.bk-g-shot').length, marks: [...t.querySelectorAll('.bk-g-mk')].map((m) => +m.dataset.n), steps: t.querySelectorAll('.bk-g-hsteps li').length })) }));
  const noShot = info.tasks.filter((t) => !t.shots || !t.marks.length);
  const badN = info.tasks.filter((t) => t.marks.some((n) => n < 1 || n > t.steps));
  ok(info.mods === 9 && info.nav === 9, `centro de ayuda: 9 módulos con índice lateral (${info.mods})`);
  ok(!noShot.length, `cada tarea tiene su captura con marcas (${info.tasks.length} tareas) ${noShot.map((t) => t.t).join(' | ')}`);
  ok(!badN.length, `cada número de la imagen corresponde a un paso de su lista ${badN.map((t) => t.t).join(' | ')}`);
  const cover = info.tasks.reduce((a, t) => a + new Set(t.marks).size, 0) / info.tasks.reduce((a, t) => a + t.steps, 0);
  ok(cover >= 0.85, `la mayoría de los pasos están señalados en la imagen (${Math.round(cover * 100)} %)`);
  const li = h.locator('.bk-g-task[open] .bk-g-hsteps li[data-n="2"]');
  await h.mouse.move(5, 5); const bb = await li.boundingBox(); await h.mouse.move(bb.x + 60, bb.y + 8); await h.waitForTimeout(300);
  ok(await h.locator('.bk-g-task[open] .bk-g-mk.is-hot[data-n="2"]').count() === 1 && await h.locator('.bk-g-task[open] .is-hot').count() === 2, 'pasar el cursor por el paso 2 ilumina la marca 2 de la imagen (y nada más)');
  const mk = await h.locator('.bk-g-task[open] .bk-g-mk[data-n="1"]').boundingBox(); await h.mouse.move(mk.x + mk.width / 2, mk.y + mk.height / 2); await h.waitForTimeout(300);
  ok(await h.locator('.bk-g-task[open] li.is-hot[data-n="1"]').count() === 1, 'y al revés: la marca 1 ilumina el paso 1');
  await h.click('.bk-g-task[open] [data-hzoom]');
  ok(await h.locator('#bk-g-lightbox[open] .bk-g-mk').count() >= 3 && await h.locator('#bk-g-lightbox[open] .bk-g-hsteps li').count() >= 3, 'ampliar: imagen grande con sus marcas y los pasos al lado');
  await h.keyboard.press('Escape'); await h.waitForTimeout(200);
  ok(await h.locator('#bk-g-lightbox[open]').count() === 0, 'Esc cierra la imagen ampliada');
  await h.fill('#bk-g-hq', 'girar foto'); await h.waitForTimeout(200);
  ok(await h.locator('.bk-g-task[open]:not([hidden]) .bk-g-task__t', { hasText: 'Fotos' }).count() >= 1, 'buscar "girar foto" abre la guía de fotos');
  await h.setViewportSize({ width: 390, height: 844 }); await h.fill('#bk-g-hq', ''); await h.waitForTimeout(300);
  ok(await h.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'centro de ayuda en el celular sin desplazamiento horizontal');
  await h.screenshot({ path: '/tmp/claude-0/bk-local/r180-ayuda-390.png' });

  ok(!errs.length, 'sin errores JS ' + errs.slice(0, 3).join(' | '));
  await b.close();
})().catch((e) => { php('update_option("bk_hide_login","yes");'); console.error(e); process.exit(1); });
