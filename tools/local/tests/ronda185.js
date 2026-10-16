// 0.18.5: píxel de Meta con Facebook for WooCommerce (simulado con la opción bk_test_fb): la revisión de etiquetas lo
// reconoce (FacebookSignals.initPixel), avisa si dos plugins lo cargan y el AddToCart del carrito flotante llega una vez.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const PROD = B + '/product/jean-bota-campana-flare-dama-tiro-alto/';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  const b = await chromium.launch();
  const errs = [];
  const flush = () => php('bk_cache_purge(); delete_transient("bk_gs_tags");');

  // --- Revisión de etiquetas -----------------------------------------------------
  const g = await (await b.newContext()).newPage();
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const nonce = await g.evaluate(() => bkG.nonce);
  const pixel = async () => {
    const d = await (await g.request.get(`${B}/wp-json/bk/v1/gestion/seo/tags?refresh=1`, { headers: { 'X-WP-Nonce': nonce }, timeout: 120000 })).json();
    return { row: d.tags.find((t) => /Píxel de Meta/.test(t.name)), pages: d.pages };
  };

  php('delete_option("bk_test_fb");'); flush();
  let r = await pixel();
  ok(['missing', 'info'].includes(r.row.status) && /Falta el píxel/.test(r.row.note), 'sin plugin: "Falta el píxel" (info si GTM no se pudo leer)');

  php('update_option("bk_test_fb", "1");'); flush();
  r = await pixel();
  ok(r.row.status === 'ok' && /339783714479962/.test(r.row.note) && /Facebook for WooCommerce/.test(r.row.note), `Facebook for WooCommerce: píxel encontrado (${r.row.status}: ${r.row.note.slice(0, 90)}…)`);
  ok(!/desactivado/.test(r.row.note), 'no dice "instalado pero desactivado" cuando el píxel sí carga');
  ok(r.pages.every((p) => p.fbq.filter((e) => e === 'PageView').length === 1), 'eventos por página sin repetir (PageView una vez en la tabla)');

  php('update_option("bk_test_fb", "both");'); flush();
  r = await pixel();
  ok(r.row.status === 'warn' && /DOBLES/.test(r.row.note) && /Desactiva Meta Pixel \(plugin oficial\) y deja solo Facebook for WooCommerce/.test(r.row.note), 'dos plugins: aviso de duplicado y cuál apagar');

  // --- AddToCart del carrito flotante -------------------------------------------
  php('update_option("bk_test_fb", "1");'); flush();
  const add = async (opts = {}) => {
    const ctx = await b.newContext();
    if (opts.interceptor) {
      // Imita pixel-events.js de Facebook for WooCommerce ya cargado: intercepta fetch y marca el evento como enviado.
      await ctx.addInitScript(() => {
        const of = window.fetch;
        window.fetch = function (...a) {
          const url = typeof a[0] === 'string' ? a[0] : a[0].url;
          return of.apply(this, a).then((res) => {
            if (/cart\/add-item/.test(url)) res.clone().json().then((d) => { const e = d.extensions?.['facebook-for-woocommerce']; if (e?.event) { window.wcFacebookPixelFiredEvents = window.wcFacebookPixelFiredEvents || {}; window.wcFacebookPixelFiredEvents[e.params.event_id] = true; (window.bkFbCalls = window.bkFbCalls || []).push(['track', e.event, e.params, { eventID: e.params.event_id }, 'plugin']); } });
            return res;
          });
        };
      });
    }
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(PROD, { waitUntil: 'networkidle' });
    if (opts.interact) { await p.mouse.move(200, 200); await p.mouse.wheel(0, 200); await p.waitForTimeout(800); }
    await p.selectOption('#pa_tallas-plus-size', '16'); await p.waitForTimeout(300);
    await p.click('.single_add_to_cart_button');
    await p.waitForSelector('.bk-sc__item', { timeout: 15000 });
    await p.waitForTimeout(opts.interact ? 1500 : 3000);
    const calls = await p.evaluate(() => (window.bkFbCalls || []).filter((c) => c[1] === 'AddToCart'));
    await p.evaluate(async () => { const c = await (await fetch('/wp-json/wc/store/v1/cart')).json(); for (const i of c.items) await fetch('/wp-json/wc/store/v1/cart/remove-item', { method: 'POST', headers: { 'Content-Type': 'application/json', Nonce: window.bkStoreNonce || '' }, body: JSON.stringify({ key: i.key }) }); }).catch(() => {});
    await ctx.close();
    return calls;
  };

  let calls = await add({ interact: true });
  ok(calls.length === 1 && calls[0][3]?.eventID && calls[0][2].event_id === calls[0][3].eventID, `AddToCart una vez con eventID (mismo de la API de conversiones): ${calls.length}`);
  calls = await add({ interact: false });
  ok(calls.length === 1, `AddToCart también si "Añadir" es lo primero que se toca (píxel aún retrasado): ${calls.length}`);
  calls = await add({ interact: true, interceptor: true });
  ok(calls.length === 1 && calls[0][4] === 'plugin', `con el script del plugin ya activo no se duplica: ${calls.length}`);

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php('delete_option("bk_test_fb");'); flush();
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
