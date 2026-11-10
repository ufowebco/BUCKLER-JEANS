// 0.18.32: el video del producto se optimiza en el navegador antes de subirlo (lado corto 1080 px, sin audio).
// El Chromium de pruebas no codifica H.264: la mecánica se prueba con VP9 (opción bk_test_video_opt del fixture).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const B = 'http://localhost:8899';
const TMP = '/tmp/claude-0/bk-video-test';
const SHOT = process.env.SHOT || '/tmp/claude-0/video';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const opt = (o) => php(o ? `update_option( "bk_test_video_opt", ${JSON.stringify(JSON.stringify(o))} );` : 'delete_option( "bk_test_video_opt" );');
const probe = (f) => execSync(`ffprobe -v error -show_entries stream=codec_type,width,height,r_frame_rate -of csv=p=0 "${f}"`).toString().trim().split('\n');
const ff = (args, out) => { if (!fs.existsSync(out)) execSync(`ffmpeg -v error -y ${args} "${out}"`); };

(async () => {
  fs.mkdirSync(TMP, { recursive: true }); fs.mkdirSync(SHOT, { recursive: true });
  // Videos de prueba: vertical 1440×2560 a 60 cuadros con audio (como los del celular), uno acostado con rotación 90°
  // y uno pequeño.
  ff('-f lavfi -i testsrc2=s=1440x2560:r=60 -f lavfi -i sine=frequency=440 -t 4 -c:v libvpx-vp9 -b:v 9M -minrate 9M -maxrate 9M -deadline realtime -cpu-used 8 -c:a aac', `${TMP}/grande.mp4`);
  ff('-f lavfi -i testsrc2=s=1920x1080:r=30 -t 3 -c:v libvpx-vp9 -b:v 9M -minrate 9M -maxrate 9M -deadline realtime -cpu-used 8', `${TMP}/acostado0.mp4`);
  ff(`-display_rotation 90 -i ${TMP}/acostado0.mp4 -c copy`, `${TMP}/acostado.mp4`);
  // ffmpeg no escribe VP9 en .mov: para la prueba basta el mismo contenido con nombre .mov (el navegador lo trata como QuickTime).
  if (!fs.existsSync(`${TMP}/celular.mov`)) fs.copyFileSync(`${TMP}/acostado.mp4`, `${TMP}/celular.mov`);
  ff('-f lavfi -i testsrc2=s=540x960:r=30 -t 2 -c:v libvpx-vp9 -b:v 600k -deadline realtime -cpu-used 8', `${TMP}/chico.mp4`);
  const size = (f) => fs.statSync(`${TMP}/${f}`).size;
  ok(size('grande.mp4') > 3e6 && size('chico.mp4') < 1e6, `videos de prueba listos (grande ${(size('grande.mp4') / 1048576).toFixed(1)} MB)`);

  const b = await chromium.launch();
  const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 } });
  const g = await ctx.newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  const api = (path) => g.evaluate(async (p) => (await fetch(bkG.api + p, { credentials: 'same-origin', headers: { 'X-WP-Nonce': bkG.nonce }, cache: 'no-store' })).json(), path);
  const open = async () => { await g.goto(B + '/gestion-tienda/'); await g.goto(B + '/gestion-tienda/#productos/nuevo'); await g.reload(); await g.waitForSelector('[name="video"]', { state: 'attached' }); };
  const uploads = []; g.on('request', (r) => { if (r.method() === 'POST' && /gestion\/media$/.test(r.url())) uploads.push(r); });
  const lastFile = () => php('$a = get_posts( array( "post_type" => "attachment", "post_mime_type" => "video/mp4", "numberposts" => 1, "orderby" => "ID", "order" => "DESC" ) ); echo get_attached_file( $a[0]->ID );');
  const toastText = () => g.locator('#bk-g-toast').textContent();

  // 1. Ajustes por defecto.
  opt(null);
  await open();
  const f = await api('product-form');
  ok(f.videoOpt && f.videoOpt.on === true && f.videoOpt.side === 1080 && f.videoOpt.codecs.join() === 'avc' && f.videoOpt.min === 4194304, 'product-form trae los ajustes del optimizador (1080 px, H.264, desde 4 MB)');
  ok(/MP4 o MOV/.test(await g.locator('#bk-gp-video').innerText()) && /quicktime/.test(await g.locator('[name="video"]').getAttribute('accept')), 'el botón dice "MP4 o MOV · se optimiza solo" y acepta .mov');
  ok(await g.evaluate(() => !window.bkVideoOptimize && !document.querySelector('script[src*="bk-video-opt"]')), 'el optimizador no se carga hasta elegir un video');

  // 2. Video pequeño: se sube tal cual, sin cargar el optimizador.
  await g.setInputFiles('[name="video"]', `${TMP}/chico.mp4`);
  await g.waitForSelector('.bk-gp-video video', { timeout: 30000 });
  ok(fs.statSync(lastFile()).size === size('chico.mp4') && await g.evaluate(() => !window.bkVideoOptimize), 'video liviano (menos de 4 MB): se sube tal cual');

  // 3. Navegador que no puede convertir (aquí: sin H.264) → se sube el original como siempre.
  opt({ min: 100000 });
  await open();
  await g.setInputFiles('[name="video"]', `${TMP}/chico.mp4`);
  await g.waitForSelector('.bk-gp-video video', { timeout: 30000 });
  ok(fs.statSync(lastFile()).size === size('chico.mp4') && await g.evaluate(() => typeof window.bkVideoOptimize === 'function'), 'si el navegador no puede optimizar, sube el original (sin error)');
  await open();
  await g.setInputFiles('[name="video"]', `${TMP}/celular.mov`);
  await g.waitForFunction(() => /no pudo convertir/.test(document.querySelector('#bk-g-toast').textContent), null, { timeout: 30000 }).catch(() => {});
  ok(/no pudo convertir/.test(await toastText()) && await g.locator('[name="video"]').count() === 1, 'MOV que no se puede convertir: aviso claro y vuelve el botón');

  // 4. Optimización real (VP9 en la prueba): avance visible, video más liviano, 1080×1920, 30 cuadros, sin audio.
  opt({ codecs: ['vp9'], min: 1000000 });
  await open();
  const n0 = uploads.length;
  await g.setInputFiles('[name="video"]', `${TMP}/grande.mp4`);
  await g.waitForSelector('.bk-gp-vopt progress');
  ok(/Optimizando video/.test(await g.locator('.bk-gp-vopt').innerText()) && await g.locator('[data-vopt-cancel]').isVisible(), 'muestra "Optimizando video… N %" con barra y Cancelar');
  await g.waitForFunction(() => { const p = document.querySelector('.bk-gp-vopt progress'); return p && p.value > 5; }, null, { timeout: 60000 });
  await g.screenshot({ path: `${SHOT}/optimizando-1440.png` });
  ok(await g.locator('.bk-g-gp-save, [data-save], button[type=submit]').first().isVisible(), 'el formulario sigue visible mientras optimiza');
  await g.waitForSelector('.bk-gp-video video', { timeout: 120000 });
  const t = await toastText();
  ok(/Video optimizado: de [\d,]+ MB a [\d,]+ MB/.test(t), `aviso con el peso antes y después ("${t.trim()}")`);
  const out = lastFile(); const pr = probe(out);
  ok(fs.statSync(out).size < size('grande.mp4') * 0.5, `el archivo subido pesa menos de la mitad (${(size('grande.mp4') / 1048576).toFixed(1)} → ${(fs.statSync(out).size / 1048576).toFixed(1)} MB)`);
  ok(pr.length === 1 && /^video,1080,1920,30\/1/.test(pr[0]) || /1080,1920/.test(pr[0]) && pr.length === 1, `1080×1920 a 30 cuadros y sin pista de audio (${pr.join(' | ')})`);
  ok(uploads.length === n0 + 1, 'una sola subida (solo el optimizado)');
  ok(execSync(`ffprobe -v trace "${out}" 2>&1 | grep -m2 -oE "type:'(moov|mdat)'" | head -1`).toString().includes('moov'), 'el archivo empieza por el índice (arranca sin descargar todo)');

  // 5. Video acostado con rotación (celular) y MOV: quedan derechos (vertical) y en MP4.
  for (const [file, label] of [['acostado.mp4', 'MP4 grabado acostado con rotación'], ['celular.mov', 'MOV del celular']]) {
    await open();
    await g.setInputFiles('[name="video"]', `${TMP}/${file}`);
    await g.waitForSelector('.bk-gp-video video', { timeout: 120000 });
    const o = lastFile(); const p = probe(o);
    ok(/\.mp4$/.test(o) && p.length === 1 && /1080,1920/.test(p[0]), `${label}: sale MP4 vertical 1080×1920 (${p.join(' | ')})`);
  }

  // 6. Cancelar.
  await open();
  const n1 = uploads.length;
  await g.setInputFiles('[name="video"]', `${TMP}/grande.mp4`);
  await g.waitForSelector('[data-vopt-cancel]');
  await g.click('[data-vopt-cancel]');
  await g.waitForSelector('[name="video"]', { state: 'attached', timeout: 20000 });
  await g.waitForTimeout(800);
  ok(uploads.length === n1 && await g.locator('.bk-gp-video video').count() === 0, 'Cancelar: no sube nada y vuelve el botón "Agregar video"');

  // 7. Apagado por filtro: todo como antes.
  opt({ on: false });
  await open();
  ok(/^MP4 · máx\./m.test(await g.locator('#bk-gp-video small').innerText()) && await g.locator('[name="video"]').getAttribute('accept') === 'video/mp4', 'apagado (filtro): solo MP4 con el límite de siempre');

  // 8. Celular.
  opt({ codecs: ['vp9'], min: 1000000 });
  const m = await (await b.newContext({ viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() })).newPage();
  m.on('pageerror', (e) => errs.push(e.message));
  await m.goto(B + '/gestion-tienda/#productos/nuevo'); await m.waitForSelector('[name="video"]', { state: 'attached' });
  await m.setInputFiles('[name="video"]', `${TMP}/grande.mp4`);
  await m.waitForFunction(() => { const p = document.querySelector('.bk-gp-vopt progress'); return p && p.value > 5; }, null, { timeout: 60000 });
  await m.locator('.bk-gp-vopt').scrollIntoViewIfNeeded();
  await m.screenshot({ path: `${SHOT}/optimizando-390.png` });
  ok(await m.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'celular: sin desbordes mientras optimiza');
  await m.waitForSelector('.bk-gp-video video', { timeout: 120000 });
  await m.locator('.bk-gp-video').scrollIntoViewIfNeeded();
  await m.screenshot({ path: `${SHOT}/listo-390.png` });
  ok(true, 'celular: termina y muestra el video');

  opt(null);
  ok(errs.length === 0, 'sin errores de JavaScript' + (errs.length ? ': ' + errs.join(' | ') : ''));
  await b.close();
  console.log(fails ? `\n${fails} FAIL` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
