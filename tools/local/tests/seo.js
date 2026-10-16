// SEO de las páginas limpias (includes/pages/seo.php). Los fixtures imitan producción: SEOPress quita el JSON-LD de
// WooCommerce, título "Tienda -", "REF: … - Buckler Jenas by Denimblue" en fichas, inicio sin og:image.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const B = 'http://localhost:8899';
(async () => {
  const b = await chromium.launch(); const p = await b.newPage();
  const head = async (u) => {
    await p.goto(B + u);
    return p.evaluate(() => {
      const m = (s) => document.querySelector(s)?.getAttribute('content') || '';
      return { title: document.title, desc: m('meta[name="description"]'), ogt: m('meta[property="og:title"]'), ogd: m('meta[property="og:description"]'), ogi: m('meta[property="og:image"]'), site: m('meta[property="og:site_name"]'),
        ld: [...document.querySelectorAll('script[type="application/ld+json"]')].map(s => { try { return JSON.parse(s.textContent); } catch (e) { return null; } }).filter(Boolean),
        nDesc: document.querySelectorAll('meta[name="description"]').length, nTitle: document.querySelectorAll('title').length, h1: document.querySelectorAll('h1').length };
    });
  };
  const good = (h, re) => h.title.length <= 70 && re.test(h.title) && /\| Buckler Jeans$/.test(h.title) && h.desc.length >= 70 && h.desc.length <= 155 && !/…$/.test(h.desc) && h.ogt === h.title && h.ogd === h.desc && h.nDesc === 1 && h.nTitle === 1;
  let h = await head('/shop/');
  ok(good(h, /^Tienda de jeans/), `tienda: "${h.title}" / ${h.desc.length} car.`);
  for (const [u, re] of [['/linea-slim/', /^Línea Slim/], ['/linea-curvy/', /^Línea Curvy: jeans plus size/], ['/linea-curvy/?prenda=jeans', /^Línea Curvy: jeans para mujer/], ['/sales/', /^¡Sale!/], ['/shop/?nuevo=1', /^Lo nuevo/], ['/?s=jean&post_type=product', /^Resultados para "jean"/]]) {
    h = await head(u); ok(good(h, re), `${u}: "${h.title}" / ${h.desc.length} car.`);
  }
  await p.goto(B + '/shop/?linea=slim'); const purl = await p.locator('.bk-card a').first().getAttribute('href');
  h = await head(new URL(purl).pathname);
  ok(!/REF:|Jenas/.test(h.title) && /· Línea (Slim|Curvy)/.test(h.title) && /\| Buckler Jeans$/.test(h.title) && h.title.length <= 70, `ficha: título con el nombre primero "${h.title}"`);
  ok(h.desc.length <= 155 && h.desc.length > 40 && h.ogd === h.desc, `ficha: descripción ${h.desc.length} car.`);
  const prod = h.ld.find(x => x['@type'] === 'Product');
  const off = prod && [].concat(prod.offers || [])[0];
  ok(prod && prod.sku && prod.brand?.name === 'Buckler Jeans' && prod.image?.length >= 1 && /Offer/.test(off?.['@type']) && off.priceCurrency === 'COP' && /schema\.org\/(InStock|OutOfStock)/.test(off.availability), 'ficha: JSON-LD Product con REF, marca, fotos, precio y disponibilidad');
  ok(Number(off.price || off.lowPrice) > 0 && !prod.aggregateRating, 'precio real y sin calificaciones inventadas');
  ok(h.ld.filter(x => x['@type'] === 'Product').length === 1, 'un solo Product (no se duplica)');
  ok(h.site === 'Buckler Jeans by Denimblue', 'errata "Jenas" corregida en og:site_name');
  ok(h.h1 === 1, 'un H1');
  // Con el JSON-LD de WooCommerce presente no se agrega otro.
  execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; update_option("bk_test_keep_wc_ld", 1);'`);
  h = await head(new URL(purl).pathname);
  ok(h.ld.filter(x => x['@type'] === 'Product' || (x['@graph'] || []).some(g => g['@type'] === 'Product')).length === 1, 'si WooCommerce ya trae Product, no se duplica');
  execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; delete_option("bk_test_keep_wc_ld");'`);
  h = await head('/');
  ok(/^https?:\/\//.test(h.ogi), 'inicio con imagen para redes: ' + h.ogi.split('/').pop());
  // Título manual de SEOPress en un producto: se respeta.
  const id = execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; $id=url_to_postid("${purl}"); update_post_meta($id,"_seopress_titles_title","Título a mano"); echo $id;'`).toString().trim();
  h = await head(new URL(purl).pathname);
  ok(!/· Línea/.test(h.title), 'título escrito a mano en SEOPress se respeta: "' + h.title + '"');
  // Título "a mano" que sale roto ("Tienda -" en producción): se reemplaza igual.
  const shopId = execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; $s=wc_get_page_id("shop"); update_post_meta($s,"_seopress_titles_title","%%post_title%% %%sep%%"); echo $s;'`).toString().trim();
  h = await head('/shop/');
  ok(/^Tienda de jeans/.test(h.title), 'título manual roto ("Tienda -") se reemplaza: "' + h.title + '"');
  execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; delete_post_meta(${shopId},"_seopress_titles_title");'`);
  execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; delete_post_meta(${id},"_seopress_titles_title");'`);
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
