// 0.18.15: celular obligatorio al finalizar la compra (aunque WooCommerce lo tenga como opcional u oculto) y con 10 dígitos.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const MAIL = 'celular@ejemplo.com';

(async () => {
  const keep = php('echo get_option("woocommerce_checkout_phone_field", "");');
  php('update_option("woocommerce_checkout_phone_field", "hidden");'); // El peor caso: oculto en los ajustes de Woo.
  const b = await chromium.launch();
  const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  // Una talla con existencias (otras pruebas agotan las de siempre).
  const [pid, vid, attr, val] = JSON.parse(php('foreach(wc_get_products(["type"=>"variable","limit"=>40,"status"=>"publish"]) as $pp){ foreach($pp->get_children() as $c){ $v=wc_get_product($c); if($v && $v->is_in_stock() && $v->is_purchasable() && ($v->get_stock_quantity()===null || $v->get_stock_quantity()>2)){ $a=$v->get_attributes(); $k=array_key_first($a); echo json_encode([$pp->get_id(),$c,"attribute_".$k,$a[$k]]); exit; } } }'));
  await p.goto(`${B}/?add-to-cart=${pid}&variation_id=${vid}&${attr}=${encodeURIComponent(val)}&quantity=1`);
  await p.goto(`${B}/checkout/`);
  const f = p.locator('#billing_phone_field');
  ok(await f.isVisible() && await f.evaluate((e) => e.classList.contains('validate-required')), 'el celular aparece y es obligatorio aunque Woo lo tenga oculto');
  ok(/Celular/.test(await f.locator('label').textContent()) && await p.getAttribute('#billing_phone', 'type') === 'tel' && await p.getAttribute('#billing_phone', 'inputmode') === 'tel', 'se llama "Celular" y abre el teclado numérico');

  const fill = async (phone) => {
    await p.fill('#billing_email', MAIL); await p.fill('#billing_first_name', 'Ana'); await p.fill('#billing_last_name', 'Celular');
    await p.fill('#billing_address_1', 'Cra 43A # 1-50'); await p.fill('#billing_city', 'Medellín'); await p.fill('#billing_phone', phone);
    if (await p.locator('#billing_id').count()) await p.fill('#billing_id', '1234567890');
    await p.evaluate(() => { const s = document.querySelector('#billing_state'); s.value = s.tagName === 'SELECT' ? [...s.options].find((o) => /Antioquia/.test(o.text)).value : 'Antioquia'; s.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.waitForTimeout(1200);
    const res = p.waitForResponse((r) => /wc-ajax=checkout/.test(r.url()), { timeout: 30000 });
    await p.click('#place_order');
    const body = await (await res).json().catch(() => ({}));
    if (body.result === 'success') await p.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
    return String(body.messages || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  };
  let err = await fill('');
  ok(!/order-received/.test(p.url()) && /Celular/i.test(err), `sin celular no deja comprar: "${err.trim().slice(0, 80)}"`);
  err = await fill('300 123');
  ok(!/order-received/.test(p.url()) && /10 dígitos/.test(err), 'celular incompleto: pide 10 dígitos');
  await fill('+57 300 123 4567');
  ok(/order-received/.test(p.url()), 'con +57 300 123 4567 la compra pasa');
  const oid = (p.url().match(/order-received\/(\d+)/) || [])[1];
  if (oid) ok(/3001234567/.test(php(`echo preg_replace("/\\\\D/","",wc_get_order(${oid})->get_billing_phone());`)), 'el pedido guarda el celular');

  ok(!errs.length, 'sin errores de JS ' + errs.join(' | '));
  php(`foreach(wc_get_orders(["billing_email"=>"${MAIL}","limit"=>-1,"status"=>"any"]) as $o){ $o->delete(true); } ${keep ? `update_option("woocommerce_checkout_phone_field","${keep}");` : 'delete_option("woocommerce_checkout_phone_field");'}`);
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
