// 0.18.25: fotos horizontales nítidas en la galería (marco 2:3) y videos que siempre arrancan, sin pausa al tocar.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const BASE = 'http://localhost:8899';
const WP = '/tmp/claude-0/bk-local/site/wp-load.php';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "${WP}"; ${code}'`, { encoding: 'utf8' }).trim();

(async () => {
  // Foto horizontal 1920×1280 (como las de producción) añadida a la galería del producto con video.
  const info = JSON.parse(php(`
    require_once ABSPATH . "wp-admin/includes/image.php";
    $pid = 0; foreach ( wc_get_products( array( "limit" => -1, "status" => "publish" ) ) as $p ) { if ( bk_get_product_video( $p->get_id() ) ) { $pid = $p->get_id(); break; } }
    $up = wp_upload_dir(); $f = $up["path"] . "/bk-test-horizontal.jpg";
    $im = imagecreatetruecolor( 1920, 1280 ); for ( $x = 0; $x < 1920; $x += 8 ) { imagefilledrectangle( $im, $x, 0, $x + 3, 1280, imagecolorallocate( $im, $x % 255, 90, 160 ) ); } imagejpeg( $im, $f, 85 );
    $aid = wp_insert_attachment( array( "post_mime_type" => "image/jpeg", "post_title" => "bk-test-horizontal", "post_status" => "inherit" ), $f, $pid );
    wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $f ) );
    $p = wc_get_product( $pid ); $orig = $p->get_gallery_image_ids( "edit" );
    $p->set_gallery_image_ids( array_merge( $orig, array( $aid ) ) ); $p->save();
    delete_transient( "bk_card_" . $pid ); do_action( "bk_test_noop" );
    echo wp_json_encode( array( "pid" => $pid, "aid" => $aid, "orig" => $orig, "url" => get_permalink( $pid ) ) );
  `));
  const restore = () => php(`$p = wc_get_product( ${info.pid} ); $p->set_gallery_image_ids( array(${info.orig.join(',')}) ); $p->save(); wp_delete_attachment( ${info.aid}, true );`);
  const url = info.url.replace(/^https?:\/\/[^/]+/, BASE);
  const b = await chromium.launch();
  const errs = [];
  try {
    // --- Fotos: escritorio con pantalla retina ---
    const c = await b.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
    const p = await c.newPage();
    p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(url, { waitUntil: 'load' });
    const imgs = await p.evaluate((aid) => [...document.querySelectorAll('.bk-pg__slide img')].map((i) => ({ sizes: i.getAttribute('sizes') || '', set: i.getAttribute('srcset') || i.getAttribute('data-bk-srcset') || '', horiz: /bk-test-horizontal/.test(i.getAttribute('srcset') || i.getAttribute('data-bk-srcset') || i.getAttribute('src') || i.getAttribute('data-bk-src') || '') })), info.aid);
    const h = imgs.find((i) => i.horiz);
    const v = imgs.find((i) => !i.horiz);
    ok(h && /225vw/.test(h.sizes) && /113vw/.test(h.sizes), 'foto horizontal: sizes agrandado (se pinta a lo alto del marco 2:3) ' + (h && h.sizes));
    ok(h && /\b1920w/.test(h.set), 'foto horizontal: srcset llega al original de 1920 px');
    // Una foto 2:3 exacta (como las de producción) no cambia; una algo más ancha (3:4 en local) se agranda lo justo.
    const vr = await p.evaluate(() => { const i = [...document.querySelectorAll('.bk-pg__slide img')].find((x) => !/bk-test-horizontal/.test(x.outerHTML)); return +i.getAttribute('width') / +i.getAttribute('height'); });
    const want = vr > (2 / 3) * 1.05 ? `(max-width: 900px) ${Math.ceil(100 * vr * 1.5)}vw, ${Math.ceil(50 * vr * 1.5)}vw` : '(max-width: 900px) 100vw, 50vw';
    ok(v && v.sizes === want, `foto vertical (proporción ${vr.toFixed(3)}): sizes ${v && v.sizes} = ${want}`);
    const idx = imgs.findIndex((i) => i.horiz);
    await p.click(`.bk-pg__thumb[data-go="${idx}"]`);
    await p.waitForFunction((k) => { const i = document.querySelectorAll('.bk-pg__slide img')[k]; return i && i.complete && i.naturalWidth > 0 && !/^data:/.test(i.currentSrc); }, idx, { timeout: 10000 });
    const nat = await p.evaluate((k) => { const i = document.querySelectorAll('.bk-pg__slide img')[k]; const r = i.getBoundingClientRect(); return { nw: i.naturalWidth, nh: i.naturalHeight, w: r.width, h: r.height, src: i.currentSrc.split('/').pop() }; }, idx);
    ok(nat.src === 'bk-test-horizontal.jpg', 'foto horizontal en retina: el navegador baja la original de 1920 px (antes 1280) ' + JSON.stringify(nat));
    ok(nat.h > nat.w, 'el marco sigue siendo vertical (misma presentación) ' + JSON.stringify(nat));
    await c.close();

    // --- Video: el navegador bloquea los 2 primeros intentos; debe insistir hasta arrancar ---
    const c2 = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    await c2.addInitScript(() => {
      window.__plays = 0; window.__pauses = 0; window.__started = false;
      HTMLMediaElement.prototype.play = function () {
        window.__plays++;
        if (window.__plays <= 2) return Promise.reject(new DOMException('bloqueado', 'NotAllowedError'));
        window.__started = true;
        Object.defineProperty(this, 'paused', { configurable: true, get: () => false });
        this.dispatchEvent(new Event('play'));
        return Promise.resolve();
      };
      const op = HTMLMediaElement.prototype.pause;
      HTMLMediaElement.prototype.pause = function () {
        window.__pauses++;
        Object.defineProperty(this, 'paused', { configurable: true, get: () => true });
        this.dispatchEvent(new Event('pause'));
      };
    });
    const m = await c2.newPage();
    m.on('pageerror', (e) => errs.push(e.message));
    await m.goto(url, { waitUntil: 'load' });
    const lab = await m.locator('.bk-pg video').getAttribute('aria-label');
    ok(!/pausar/i.test(lab) && /sin sonido/.test(lab), 'video: la etiqueta ya no dice "toca para pausar" ' + lab);
    const vi = await m.evaluate(() => [...document.querySelectorAll('.bk-pg__slide')].findIndex((s) => s.classList.contains('is-video')));
    await m.evaluate((k) => { const t = document.querySelector('.bk-pg__track'); t.scrollTo({ left: k * t.clientWidth }); }, vi);
    await m.waitForFunction(() => window.__started, null, { timeout: 8000 }).catch(() => {});
    const st = await m.evaluate(() => { const v = document.querySelector('.bk-pg video'); return { plays: window.__plays, started: window.__started, muted: v.muted, controls: v.hasAttribute('controls'), loop: v.loop }; });
    ok(st.started && st.plays >= 3, 'video bloqueado al principio: se reintenta solo hasta arrancar ' + JSON.stringify(st));
    ok(st.muted && !st.controls && st.loop, 'video silenciado, en bucle y sin controles');
    const before = await m.evaluate(() => window.__pauses);
    await m.tap('.bk-pg__slide.is-video video');
    await m.waitForTimeout(500);
    const after = await m.evaluate(() => ({ pauses: window.__pauses, paused: document.querySelector('.bk-pg video').paused }));
    ok(after.pauses === before && !after.paused, 'tocar el video NO lo pausa ' + JSON.stringify(after));
    // Si quedó en pausa (p. ej. otra pestaña), al volver a verlo arranca de nuevo.
    await m.evaluate(() => document.querySelector('.bk-pg video').pause());
    await m.waitForTimeout(2600);
    ok(await m.evaluate(() => !document.querySelector('.bk-pg video').paused), 'video en pausa por el navegador: vuelve a arrancar solo');
    // Teclado: Espacio sigue pausando (accesibilidad, WCAG 2.2.2).
    await m.focus('.bk-pg video'); await m.keyboard.press('Space'); await m.waitForTimeout(2600);
    ok(await m.evaluate(() => document.querySelector('.bk-pg video').paused), 'teclado: Espacio pausa y se respeta');
    // Al salir del video se pausa; al volver arranca.
    await m.evaluate(() => { const t = document.querySelector('.bk-pg__track'); t.scrollTo({ left: 0 }); });
    await m.keyboard.press('Space').catch(() => {});
    await c2.close();

    // --- Producto con solo video (única diapositiva): arranca sin deslizar ---
    php(`$p = wc_get_product( ${info.pid} ); update_option( "bk_test_tmp_img", array( $p->get_image_id(), $p->get_gallery_image_ids( "edit" ) ) ); $p->set_image_id( 0 ); $p->set_gallery_image_ids( array() ); $p->save();`);
    const c3 = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    await c3.addInitScript(() => { window.__plays = 0; HTMLMediaElement.prototype.play = function () { window.__plays++; return Promise.resolve(); }; });
    const q = await c3.newPage();
    q.on('pageerror', (e) => errs.push(e.message));
    await q.goto(url, { waitUntil: 'load' });
    await q.waitForTimeout(2500);
    const one = await q.evaluate(() => ({ slides: document.querySelectorAll('.bk-pg__slide').length, plays: window.__plays }));
    ok(one.slides === 1 && one.plays >= 1, 'video como única diapositiva: arranca sin deslizar ' + JSON.stringify(one));
    await c3.close();
    php(`$o = get_option( "bk_test_tmp_img" ); $p = wc_get_product( ${info.pid} ); $p->set_image_id( $o[0] ); $p->set_gallery_image_ids( $o[1] ); $p->save(); delete_option( "bk_test_tmp_img" );`);
  } finally {
    await b.close();
    restore();
  }
  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
