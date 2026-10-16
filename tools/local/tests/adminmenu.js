// Administrador del menú (módulo adminmenu). Antes: php seed-adminmenu.php
const { chromium } = require('playwright');
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const B = 'http://localhost:8899';
const OUT = '/tmp/claude-0/bk-local';
const login = async (p, u, pw = 'Clave#2026') => { await p.goto(B + '/ingreso/'); await p.fill('[name=log]', u); await p.fill('[name=pwd]', pw); await Promise.all([p.waitForNavigation(), p.click('.bk-g-login__form [type=submit]')]); };
const tool = (q = '') => B + '/wp-admin/tools.php?page=bk-admin-menu' + q;
const menuIds = (p) => p.evaluate(() => [...document.querySelectorAll('#adminmenu > li')].map((li) => li.classList.contains('bk-am-logo') ? 'LOGO' : li.classList.contains('bk-am-head') ? 'H:' + li.textContent.trim() : li.classList.contains('wp-menu-separator') ? 'SEP' : (li.querySelector('a') || {}).getAttribute?.('href') || ''));

(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('dialog', (d) => d.accept());
  await login(p, 'sebas_ufoweb');
  await p.goto(tool());

  // Diseño
  const look = await p.evaluate(() => ({ w: Math.round(document.querySelector('#adminmenuwrap').getBoundingClientRect().width), bg: getComputedStyle(document.querySelector('#adminmenuback')).backgroundColor, ml: getComputedStyle(document.querySelector('#wpcontent')).marginLeft }));
  ok(look.w === 200 && look.ml === '200px' && look.bg === 'rgb(26, 57, 85)', 'menú de 200 px en escritorio con el azul de marca ' + JSON.stringify(look));
  const cur = await p.evaluate(() => { const a = document.querySelector('#adminmenu li.wp-has-current-submenu > a, #adminmenu li.current > a'); const cs = getComputedStyle(a); return { shadow: cs.boxShadow, color: cs.color }; });
  ok(/228, 236, 106/.test(cur.shadow), 'elemento activo marcado con la barra lima ' + JSON.stringify(cur));
  await p.focus('#adminmenu a.menu-top >> nth=0');
  ok(/228, 236, 106/.test(await p.evaluate(() => getComputedStyle(document.activeElement).outlineColor)), 'foco visible en lima');
  for (const [w, exp] of [[1000, 200], [900, 36]]) {
    await p.setViewportSize({ width: w, height: 800 }); await p.waitForTimeout(200);
    const ww = await p.evaluate(() => Math.round(document.querySelector('#adminmenuwrap').getBoundingClientRect().width));
    ok(ww === exp, `a ${w} px el menú mide ${ww} px (esperado ${exp})`);
  }
  await p.setViewportSize({ width: 390, height: 800 }); await p.waitForTimeout(200);
  ok(!(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), 'celular: sin forzar 200 px ni scroll horizontal');
  await p.setViewportSize({ width: 1440, height: 900 });

  // Herramienta: reordenar con teclado, ocultar, encabezado, separador, submenú
  await p.goto(tool());
  const rows = p.locator('#bk-am-list > li');
  ok(await rows.count() > 10 && await p.locator('[data-bk-preview] li').count() > 5, `herramienta con ${await rows.count()} elementos y vista previa`);
  ok(await p.locator('#bk-am-list li[data-id="tools.php"] .bk-am-visible').isDisabled(), 'Herramientas es obligatorio (no se puede ocultar)');
  const cm = p.locator('#bk-am-list li[data-id="edit-comments.php"] .bk-am-handle');
  const idx = await p.locator('#bk-am-list li[data-id="edit-comments.php"]').evaluate((li) => [...li.parentNode.children].indexOf(li));
  await cm.focus(); for (let i = 0; i < idx; i++) await p.keyboard.press('ArrowUp');
  ok(await p.locator('#bk-am-list > li').first().getAttribute('data-id') === 'edit-comments.php', 'reordenar con el teclado (flechas)');
  await p.locator('#bk-am-list li[data-id="edit.php"] .bk-am-visible').uncheck();
  await p.click('[data-bk-add="sep"]');
  await p.click('[data-bk-add="head"]'); await p.locator('#bk-am-list .bk-am-head-input').first().fill('Tienda');
  // Arrastrar y soltar: el encabezado (arriba) se lleva debajo de Comentarios.
  const h = await p.locator('#bk-am-list > li').first().locator('.bk-am-handle').boundingBox();
  const t = await p.locator('#bk-am-list li[data-id="edit-comments.php"]').boundingBox();
  await p.mouse.move(h.x + 10, h.y + 10); await p.mouse.down(); await p.mouse.move(h.x + 10, h.y + 16, { steps: 4 }); await p.waitForTimeout(150);
  for (let y = h.y + 16; y <= t.y + t.height + 6; y += 6) { await p.mouse.move(h.x + 10, y); await p.waitForTimeout(25); }
  await p.waitForTimeout(200); await p.mouse.up(); await p.waitForTimeout(200);
  const order3 = await p.evaluate(() => [...document.querySelectorAll('#bk-am-list > li')].slice(0, 3).map((li) => li.dataset.type === 'head' ? 'H' : li.dataset.type === 'sep' ? 'S' : li.dataset.id));
  ok(order3.includes('H') && order3.indexOf('edit-comments.php') < order3.indexOf('H'), 'arrastrar y soltar ' + JSON.stringify(order3));
  await p.locator('#bk-am-list li[data-id="edit.php?post_type=page"] details summary').click();
  await p.locator('#bk-am-list li[data-id="edit.php?post_type=page"] .bk-am-sub').nth(1).uncheck();
  ok((await p.locator('[data-bk-preview]').innerText()).includes('TIENDA') || (await p.locator('[data-bk-preview] .is-head').count()) === 1, 'vista previa con el encabezado');
  await Promise.all([p.waitForNavigation(), p.click('[data-bk-save] [type=submit]')]);
  ok(await p.locator('.notice-success').count() === 1, 'guardado con mensaje de éxito');
  let ids = await menuIds(p);
  const real = ids.filter((x) => x !== 'SEP' && x !== 'LOGO');
  ok(real[0] === 'edit-comments.php' && real[1] === 'H:Tienda' && ids.includes('H:Tienda') && ids.includes('SEP') && !ids.includes('edit.php'), 'menú real: Comentarios primero, encabezado, separador, Entradas oculta ' + JSON.stringify(ids.slice(0, 5)));
  ok(await p.evaluate(() => { const h = document.querySelector('#adminmenu li.bk-am-head'); return h && !h.querySelector('a') && h.querySelector('[role="heading"]'); }), 'el encabezado no es un enlace (texto con rol de encabezado)');
  ok(await p.locator('#adminmenu a[href="post-new.php?post_type=page"]').count() === 0, 'submenú oculto (Añadir página)');
  const direct = await p.goto(B + '/wp-admin/edit.php');
  ok(direct.status() === 200 && /Entradas|Posts/.test(await p.title()), 'ocultar no quita la página: Entradas sigue abriendo por su dirección');

  // Por usuario
  await p.goto(tool('&usuario=otroadmin'));
  ok(/global/.test(await p.locator('.bk-am-help').innerText()), 'usuario sin ajuste propio usa la global');
  await p.locator('#bk-am-list li[data-id="upload.php"] .bk-am-visible').uncheck();
  await Promise.all([p.waitForNavigation(), p.click('[data-bk-save] [type=submit]')]);
  const o = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await login(o, 'otroadmin'); await o.goto(B + '/wp-admin/');
  let oi = await menuIds(o);
  ok(!oi.includes('upload.php') && oi.filter((x) => x !== 'SEP')[0] === 'edit-comments.php', 'otroadmin: su organización propia (parte de la global y además sin Medios)');
  await p.goto(B + '/wp-admin/'); ids = await menuIds(p);
  ok(ids.includes('upload.php'), 'sebas_ufoweb sigue viendo Medios (global)');
  await p.goto(tool('&usuario=otroadmin'));
  await Promise.all([p.waitForNavigation(), p.click('[data-bk-reset] [type=submit]')]);
  await o.goto(B + '/wp-admin/'); oi = await menuIds(o);
  ok(oi.includes('upload.php') && !oi.includes('edit.php'), 'restablecer usuario: vuelve a la global');

  // Restricción de plugins de IA y buckler-core
  ok(oi.every((h) => !/ai-editor|bk-modules|bk-admin-menu/.test(h)) && await o.locator('#adminmenu a[href*="bk-abandoned"]').count() === 1, 'otroadmin no ve IA ni buckler-core; sí Carritos abandonados (tienda)');
  for (const u of ['/wp-admin/admin.php?page=ai-editor-divi5', '/wp-admin/options-general.php?page=bk-modules', '/wp-admin/tools.php?page=bk-admin-menu']) {
    const r = await o.goto(B + u); ok(r.status() === 403, `otroadmin: ${u} → 403`);
  }
  const post = await o.request.post(B + '/wp-admin/admin-post.php', { form: { action: 'bk_modules_save', modules: 'pages' } });
  ok(post.status() === 403, 'otroadmin: acción de buckler-core bloqueada (403)');
  const r200 = await o.goto(B + '/wp-admin/admin.php?page=bk-abandoned'); ok(r200.status() === 200, 'otroadmin abre Carritos abandonados');
  const c = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await login(c, 'sebas_ufoweb.co'); await c.goto(B + '/wp-admin/');
  ok(await c.locator('#adminmenu a[href*="ai-editor-divi5"]').count() >= 1, 'sebas_ufoweb.co sí ve el editor con IA');
  ok((await c.goto(B + '/wp-admin/admin.php?page=ai-editor-divi5')).status() === 200, 'sebas_ufoweb.co abre el editor con IA');
  const ed = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await login(ed, 'editora'); await ed.goto(B + '/wp-admin/');
  ok(await ed.locator('#adminmenu a[href*="ai-editor"], #adminmenu a[href*="bk-modules"]').count() === 0, 'editora (sin permisos de administración) no ve nada de eso');

  // Logo
  const logoId = require('child_process').execSync(`php -r 'require "/tmp/claude-0/bk-local/site/wp-load.php"; echo (int) get_posts(["post_type"=>"attachment","post_mime_type"=>"image","posts_per_page"=>1,"fields"=>"ids"])[0];'`).toString().trim();
  await p.goto(tool('&tab=logo'));
  await p.check('input[name="on"]'); await p.evaluate((id) => { document.querySelector('[data-bk-logo-id]').value = id; }, logoId);
  await Promise.all([p.waitForNavigation(), p.click('.bk-am-form [type=submit]')]);
  const logo = await p.evaluate(() => { const li = document.querySelector('#adminmenu .bk-am-logo'); const a = li && li.querySelector('a'); const first = [...document.querySelectorAll('#adminmenu > li')].sort((x, y) => x.getBoundingClientRect().top - y.getBoundingClientRect().top)[0]; const img = li && li.querySelector('img'); return a && { href: a.getAttribute('href'), target: a.target, rel: a.rel, first: first === li, ratioOk: img && Math.abs(img.getBoundingClientRect().width / img.getBoundingClientRect().height - img.naturalWidth / img.naturalHeight) < 0.05 }; });
  ok(logo && logo.target === '_blank' && /noopener/.test(logo.rel) && /noreferrer/.test(logo.rel) && logo.first && logo.ratioOk && logo.href.startsWith(B), 'logo arriba, enlace al sitio en pestaña nueva, sin deformarse ' + JSON.stringify(logo));
  await p.screenshot({ path: `${OUT}/am-menu-1440.png` });
  // Menú contraído
  await p.click('#collapse-button'); await p.waitForTimeout(300);
  const folded = await p.evaluate(() => ({ w: Math.round(document.querySelector('#adminmenuwrap').getBoundingClientRect().width), logo: Math.round(document.querySelector('#adminmenu .bk-am-logo img').getBoundingClientRect().height) }));
  ok(folded.w === 36 && folded.logo <= 28, 'menú contraído: 36 px y logo pequeño ' + JSON.stringify(folded));
  await p.screenshot({ path: `${OUT}/am-menu-folded.png` });
  await p.click('#collapse-button'); await p.waitForTimeout(300);

  // Listado de usuarios intacto (sin columna de ID)
  await p.goto(B + '/wp-admin/users.php');
  ok(!(await p.locator('table.users thead').innerText()).match(/\bID\b/), 'listado de usuarios sin columna de ID');

  // Restablecer global
  await p.goto(tool());
  await Promise.all([p.waitForNavigation(), p.click('[data-bk-reset] [type=submit]')]);
  ids = await menuIds(p);
  ok(ids.includes('edit.php') && !ids.some((x) => x.startsWith('H:')) && ids.filter((x) => x !== 'LOGO' && !/gestion-tienda/.test(x))[0] === 'index.php', 'restablecer global: menú original de WordPress ' + JSON.stringify(ids.slice(0, 4)));
  await p.goto(tool()); await p.screenshot({ path: `${OUT}/am-tool.png`, fullPage: true });
  ok(errs.length === 0, 'sin errores JS ' + errs.join(' | '));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
