// Seguridad: nombres de usuario no expuestos a visitantes (includes/security.php).
const { chromium } = require('playwright');
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const B = 'http://localhost:8899';
(async () => {
  const b = await chromium.launch(); const anon = await b.newContext();
  for (const u of ['/wp-json/wp/v2/users', '/wp-json/wp/v2/users/1']) ok((await anon.request.get(B + u)).status() === 401, `sin sesión: ${u} → 401`);
  const au = await anon.request.get(B + '/?author=1', { maxRedirects: 0 });
  ok([404, 301].includes(au.status()) && !/author|sebas|admin/i.test(au.headers().location || '') && !/\/author\//.test(au.headers().location || ''), 'sin sesión: /?author=1 → 404 o portada (no revela el usuario) ' + au.status() + ' ' + (au.headers().location || ''));
  ok(!/wp-sitemap-users/.test(await (await anon.request.get(B + '/wp-sitemap.xml')).text()), 'mapa del sitio sin usuarios');
  ok((await anon.request.get(B + '/wp-json/wc/store/v1/products?per_page=1')).status() === 200, 'la tienda (Store API) sigue pública');
  const lg = await anon.newPage(); await lg.goto(B + '/wp-login.php'); await lg.fill('#user_login', 'sebas_ufoweb'); await lg.fill('#user_pass', 'mala'); await lg.click('#wp-submit'); await lg.waitForLoadState('load');
  ok(/usuario o la contraseña no son correctos/.test(await lg.locator('#login_error').innerText()), 'acceso fallido: mensaje genérico');
  const p = await (await b.newContext()).newPage();
  await p.goto(B + '/wp-login.php'); await p.fill('#user_login', 'sebas_ufoweb'); await p.fill('#user_pass', 'Clave#2026'); await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  await p.goto(B + '/wp-admin/post-new.php?post_type=page'); await p.waitForTimeout(2500);
  const r = await p.evaluate(async () => { const h = { 'X-WP-Nonce': wpApiSettings.nonce }; return [(await fetch('/wp-json/wp/v2/users/me', { headers: h })).status, (await fetch('/wp-json/wp/v2/users?who=authors', { headers: h })).status]; });
  ok(r[0] === 200 && r[1] === 200, 'con sesión: el editor sigue leyendo usuarios ' + JSON.stringify(r));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
