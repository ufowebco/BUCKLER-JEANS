// 0.18.2: datos estructurados para Search Console (Fragmentos de productos y Fichas de comerciantes):
// agotados con precio (offers), envío con deliveryTime, política de cambios, SKU válido, un Offer por talla.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => console.log((c ? 'OK   ' : 'FAIL ') + m);
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();

(async () => {
  const b = await chromium.launch();
  const p = await (await b.newContext()).newPage();
  const ld = async (url) => { await p.goto(url); return (await p.$$eval('script[type="application/ld+json"]', (s) => s.map((x) => { try { return JSON.parse(x.textContent); } catch { return null; } }))).flat().filter(Boolean).flatMap((x) => x['@graph'] || [x]).find((x) => x['@type'] === 'Product'); };
  const url = (id) => php(`echo get_permalink(${id});`);
  const offers = (pr) => [].concat(pr?.offers || []);

  // Producto 22 (variable, tallas 14–24) con REF con espacio y tilde, como "9890 Café Plus" en producción.
  const sku0 = php('echo wc_get_product(22)->get_sku();');
  php('$p=wc_get_product(22); $p->set_sku("9890 Café Plus"); $p->save(); bk_catalog_flush(22);');
  let pr = await ld(url(22));
  const of = offers(pr);
  ok(pr && pr.sku === '9890-CAFE-PLUS' && pr.mpn === '9890-CAFE-PLUS', `SKU válido para Google: "9890 Café Plus" → "${pr && pr.sku}"`);
  ok(/9890 Café Plus/.test(await p.textContent('body')), 'la REF que ve la clienta no cambia');
  ok(of.length >= 5 && of.every((o) => o['@type'] === 'Offer' && Number(o.price) > 0 && o.priceCurrency === 'COP' && /^[A-Z0-9-]+$/.test(o.sku) && /^Talla /.test(o.name)), `un Offer por talla con precio y SKU (${of.length}: ${of.slice(0, 2).map((o) => o.sku).join(', ')}…)`);
  ok(of.some((o) => /OutOfStock/.test(o.availability)) && of.some((o) => /InStock/.test(o.availability)), 'cada talla con su disponibilidad (la 18 agotada)');
  const sd = [].concat(of[0].shippingDetails);
  const co = sd.find((x) => !x.shippingDestination.addressRegion);
  const sap = sd.find((x) => x.shippingDestination.addressRegion === 'SAP');
  ok(co && co.shippingRate.value === 0 && co.deliveryTime?.handlingTime?.unitCode === 'DAY' && co.deliveryTime.transitTime.minValue === 3 && co.deliveryTime.transitTime.maxValue === 7, 'envío gratis en Colombia con deliveryTime (preparación + 3 a 7 días hábiles)');
  ok(!sap && !Array.isArray(of[0].shippingDetails), 'San Andrés también gratis: un solo envío gratis para toda Colombia');
  const rp = of[0].hasMerchantReturnPolicy;
  ok(rp && rp.applicableCountry === 'CO' && rp.merchantReturnDays === 30 && /FiniteReturnWindow/.test(rp.returnPolicyCategory) && /ReturnByMail/.test(rp.returnMethod) && /CustomerResponsibility/.test(rp.returnFees), 'hasMerchantReturnPolicy: cambios en 30 días, envío a cargo de la clienta');
  ok(!pr.aggregateRating && !pr.review, 'sin calificaciones inventadas (las reseñas de Google son de la tienda, no del producto)');

  // Producto agotado con "Ocultar productos agotados" (como producción): antes salía sin offers.
  const st = JSON.parse(php('$o=[]; foreach(wc_get_product(22)->get_children() as $c){ $v=wc_get_product($c); $o[$c]=[$v->get_stock_quantity(),$v->get_stock_status()]; $v->set_stock_quantity(0); $v->set_stock_status("outofstock"); $v->save(); } update_option("woocommerce_hide_out_of_stock_items","yes"); bk_catalog_flush(22); echo json_encode($o);'));
  pr = await ld(url(22));
  ok(pr && offers(pr).length >= 5 && offers(pr).every((o) => /OutOfStock/.test(o.availability) && Number(o.price) > 0), `producto agotado: sigue con precio en cada Offer y "OutOfStock" (${offers(pr).length})`);
  php(`foreach(json_decode(base64_decode("${Buffer.from(JSON.stringify(st)).toString('base64')}"), true) as $c=>$v){ $x=wc_get_product($c); $x->set_stock_quantity($v[0]); $x->set_stock_status($v[1]); $x->save(); } update_option("woocommerce_hide_out_of_stock_items","no"); bk_catalog_flush(22);`);

  // Prenda de ¡Sale!: sin cambio.
  const saleId = php('$ids=wc_get_product_ids_on_sale(); foreach($ids as $i){ $x=wc_get_product($i); if($x && $x->is_type("variation")){ echo $x->get_parent_id(); break; } if($x && $x->is_type("variable")){ echo $i; break; } }');
  if (saleId) {
    pr = await ld(url(saleId));
    ok(pr && /MerchantReturnNotPermitted/.test(offers(pr)[0]?.hasMerchantReturnPolicy?.returnPolicyCategory || ''), `prenda de ¡Sale!: política "sin cambio" (#${saleId})`);
  } else ok(true, 'sin prendas de ¡Sale! en local');

  // Sin ningún precio: no se publica Product (sería error crítico).
  const np = php('$p=new WC_Product_Simple(); $p->set_name("SIN PRECIO PRUEBA"); $p->set_status("publish"); $p->set_regular_price(""); $p->save(); echo $p->get_id();');
  await p.goto(url(np));
  ok(await p.locator('#bk-product-schema').count() === 0, 'producto sin precio: no se publica JSON-LD Product (evita el error crítico)');
  php(`wp_delete_post(${np}, true); $p=wc_get_product(22); $p->set_sku(${JSON.stringify(sku0)}); $p->save(); bk_catalog_flush(22);`);

  // Validación básica de tipos de schema.org usados.
  pr = await ld(url(22));
  const types = JSON.stringify(pr).match(/"@type":"[A-Za-z]+"/g).map((x) => x.slice(9, -1));
  ok(['Product', 'Offer', 'OfferShippingDetails', 'ShippingDeliveryTime', 'QuantitativeValue', 'OpeningHoursSpecification', 'MerchantReturnPolicy', 'DefinedRegion', 'MonetaryAmount', 'Brand', 'Organization'].every((t) => types.includes(t)), 'tipos de schema.org esperados presentes');
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
