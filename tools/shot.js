// Capturas del sitio real: node tools/shot.js <url> <nombre> [outdir]
// Genera <nombre>-1440.png y <nombre>-390.png (página completa) + métricas básicas.
const { chromium } = require('playwright');
(async () => {
  const [url, name, out = 'snapshots/shots'] = process.argv.slice(2);
  require('fs').mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({
    proxy: process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined,
  });
  for (const w of [1440, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, ignoreHTTPSErrors: false,
      isMobile: w < 500, deviceScaleFactor: 1, locale: 'es-CO' });
    const page = await ctx.newPage();
    const reqs = []; page.on('requestfinished', r => reqs.push(r));
    const t0 = Date.now();
    await page.goto(url, { waitUntil: 'load', timeout: 90000 });
    const tLoad = Date.now() - t0;
    await page.waitForTimeout(2500);
    const m = await page.evaluate(() => ({
      h1: [...document.querySelectorAll('h1')].map(h => h.innerText.trim().slice(0, 80)),
      imgsNoAlt: [...document.querySelectorAll('img')].filter(i => !i.hasAttribute('alt')).length,
      overflowX: document.documentElement.scrollWidth > window.innerWidth,
      height: document.body.scrollHeight,
    }));
    console.log(JSON.stringify({ w, url, tLoad, requests: reqs.length, ...m }));
    await page.screenshot({ path: `${out}/${name}-${w}.png`, fullPage: true });
    await ctx.close();
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
