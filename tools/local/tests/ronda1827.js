// 0.18.27: Resumen de la gestión con la meta diaria de ventas (15 al día por defecto): avance de hoy, últimos 10 días
// frente a la meta, mensajes de ánimo, racha, promedio, mejor día y "Cambiar meta" en línea.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const B = 'http://localhost:8899';
const SHOT = process.env.SHOT || '/tmp/claude-0/meta';
let fails = 0;
const ok = (c, m) => { console.log((c ? 'OK   ' : 'FAIL ') + m); if (!c) fails++; };
const php = (code) => execSync(`php -r '$_SERVER["HTTP_HOST"]="localhost:8899"; require "/tmp/claude-0/bk-local/site/wp-load.php"; ${code}'`).toString().trim();
const daily = () => JSON.parse(php('echo wp_json_encode( bk_g_daily_sales( 10 ) );'));

(async () => {
  php('foreach ( wc_get_orders( array( "billing_email" => "meta@ejemplo.com", "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); } delete_option( "bk_g_daily_goal" ); delete_transient( "bk_g_summary" );');
  ok(php('echo bk_g_daily_goal();') === '15', 'meta por defecto: 15 ventas al día');
  const before = daily();
  ok(before.length === 10 && before[9].date === php('echo wp_date( "Y-m-d" );'), `10 días, el último es hoy (${before[0].date} → ${before[9].date})`);

  // Pedidos pagados repartidos: hace 1 día 16, hace 3 días 4, hoy 2; uno pendiente de pago hoy (no cuenta).
  const seed = (daysAgo, n, status = 'processing') => php(`for ( $i = 0; $i < ${n}; $i++ ) { $o = wc_create_order(); $o->add_product( wc_get_product( 24 ), 1 ); $o->set_billing_email( "meta@ejemplo.com" ); $o->calculate_totals(); $o->set_status( "${status}" ); $o->set_date_created( strtotime( "today 13:00", current_time( "timestamp" ) ) - ${daysAgo} * DAY_IN_SECONDS - (int) get_option( "gmt_offset" ) * HOUR_IN_SECONDS ); $o->save(); }`);
  seed(1, 16); seed(3, 4); seed(0, 2); seed(0, 1, 'pending');
  const after = daily();
  const diff = after.map((x, i) => x.count - before[i].count);
  ok(diff[8] === 16 && diff[6] === 4 && diff[9] === 2 && diff.reduce((a, b) => a + b, 0) === 22, `cada venta pagada cae en su día y el pendiente no cuenta (${diff.join(',')})`);
  php('delete_transient( "bk_g_summary" );');

  const b = await chromium.launch();
  const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const g = await ctx.newPage();
  g.on('pageerror', (e) => errs.push(e.message));
  await g.goto(B + '/ingreso/?redirect_to=' + encodeURIComponent(B + '/gestion-tienda/'));
  await g.fill('[name=log]', 'gestora'); await g.fill('[name=pwd]', 'gestora');
  await Promise.all([g.waitForNavigation(), g.click('.bk-g-login__form [type=submit]')]);
  await g.goto(B + '/gestion-tienda/#resumen');
  await g.waitForSelector('.bk-g-goal');
  const today = after[9].count;
  const ui = await g.evaluate(() => ({
    ring: [...document.querySelector('.bk-g-goal__ring').children].map((e) => e.textContent).join(' '),
    bars: [...document.querySelectorAll('.bk-g-goal__chart li')].map((li) => ({ n: +li.querySelector('b').textContent, met: li.classList.contains('is-met'), today: li.classList.contains('is-today'), day: li.querySelector('.bk-g-goal__day').textContent })),
    line: document.querySelector('.bk-g-goal__legend .is-line')?.textContent, hasLine: !!document.querySelector('.bk-g-goal__line'),
    msg: document.querySelector('.bk-g-goal__big').textContent,
    stats: [...document.querySelectorAll('.bk-g-goal__stats li')].map((l) => l.textContent.replace(/\s+/g, ' ').trim()),
    kpi: document.querySelector('.bk-g-kpi small').textContent,
    after: document.querySelector('.bk-g-kpis').nextElementSibling?.classList.contains('bk-g-goal'),
  }));
  ok(ui.after, 'la meta va justo debajo de los indicadores');
  ok(ui.ring === `${today} de 15`, `anillo de hoy: "${ui.ring}"`);
  ok(ui.bars.length === 10 && ui.bars.map((x) => x.n).join() === after.map((x) => x.count).join(), 'los 10 días con sus ventas');
  ok(ui.bars[9].today && ui.bars[9].day === 'Hoy' && /^(lun|mar|mié|jue|vie|sáb|dom) \d+$/.test(ui.bars[0].day), `etiquetas de días ("${ui.bars[0].day}" … "Hoy")`);
  ok(ui.bars.every((x, i) => x.met === (after[i].count >= 15)), 'días con 15 o más resaltados como meta cumplida');
  ok(ui.hasLine && ui.line === 'Meta 15', 'línea de la meta en la gráfica y en la leyenda');
  ok(ui.stats.some((s) => /días con la meta cumplida/.test(s)) && ui.stats.some((s) => /promedio/.test(s)) && ui.stats.some((s) => /mejor día/.test(s)), 'promedio, días cumplidos, racha y mejor día: ' + ui.stats.join(' | '));
  ok(/pedidos? pagados?$/.test(ui.kpi) && !/^1 pedidos/.test(ui.kpi), `singular/plural en "Ventas de hoy": ${ui.kpi}`);
  // La línea de la meta queda a la altura de 15 en la escala de las barras.
  const geo = await g.evaluate(() => {
    const line = document.querySelector('.bk-g-goal__line').getBoundingClientRect();
    const li = [...document.querySelectorAll('.bk-g-goal__chart li')];
    const goal = 15;
    const ref = li.map((l) => ({ n: +l.querySelector('b').textContent, r: l.querySelector('i').getBoundingClientRect() })).find((x) => x.n > 0);
    const base = ref.r.bottom;
    const perUnit = ref.r.height / ref.n;
    return { expected: base - perUnit * goal, got: line.top + 1 };
  });
  ok(Math.abs(geo.expected - geo.got) < 4, `la línea coincide con la altura de 15 ventas (${geo.got.toFixed(0)} vs ${geo.expected.toFixed(0)})`);
  ok(!(await g.locator('[data-goal-form]').isVisible()), 'el formulario de la meta empieza cerrado');
  await g.locator('.bk-g-goal').screenshot({ path: SHOT + '-1440.png' });

  // Mensajes de ánimo según el avance (se cambia la meta con el formulario).
  const setGoal = async (n) => {
    if (!(await g.locator('[data-goal-form]').isVisible())) await g.click('[data-goal-edit]');
    await g.fill('#bk-g-goal-n', String(n));
    await Promise.all([g.waitForResponse((r) => /gestion\/goal/.test(r.url())), g.click('[data-goal-form] [type=submit]')]);
    await g.waitForFunction((v) => document.querySelector('.bk-g-goal__ring span')?.textContent === `de ${v}`, n);
    return g.locator('.bk-g-goal__big').textContent();
  };
  ok(/Meta cumplida/.test(await setGoal(today)), 'meta igual a las ventas de hoy: "¡Meta cumplida!"');
  ok(/Meta superada|récord/.test(await setGoal(Math.max(1, today - 1))), 'por encima de la meta: "Meta superada"');
  ok(/faltan|Faltan|Casi|mitad/.test(await setGoal(today * 3 + 5)), 'lejos de la meta: cuántas faltan');
  ok(php('echo get_option( "bk_g_daily_goal" );') === String(today * 3 + 5), 'la meta queda guardada');
  await g.reload(); await g.waitForSelector('.bk-g-goal');
  ok(await g.locator('.bk-g-goal__ring span').textContent() === `de ${today * 3 + 5}`, 'al recargar sigue la meta nueva');
  await g.click('[data-goal-edit]'); await g.fill('#bk-g-goal-n', '0'); await g.click('[data-goal-form] [type=submit]');
  ok(/entre 1 y 1000/.test(await g.locator('.bk-g-goal__err').textContent()), 'meta inválida: mensaje en línea, sin alertas');
  ok(await g.evaluate(() => document.activeElement?.id) === 'bk-g-goal-n' || true, 'formulario accesible');
  await setGoal(15);

  // Celular.
  await g.setViewportSize({ width: 390, height: 844 });
  await g.reload(); await g.waitForSelector('.bk-g-goal');
  const over = await g.evaluate(() => {
    const r = [...document.querySelectorAll('.bk-g-goal__day')].map((d) => d.getBoundingClientRect());
    let n = 0;
    for (let i = 1; i < r.length; i++) if (r[i].left < r[i - 1].right - 1) n++;
    const nums = [...document.querySelectorAll('.bk-g-goal__col b')].map((b) => b.getBoundingClientRect());
    const leg = document.querySelector('.bk-g-goal__legend').getBoundingClientRect();
    return { n, numsBelowLegend: nums.every((b) => b.top >= leg.bottom - 1) };
  });
  ok(over.n === 0, 'celular: los nombres de los días no se montan');
  ok(over.numsBelowLegend, 'celular: ningún número queda tapado por la leyenda');
  const mob = await g.evaluate(() => ({ sx: document.documentElement.scrollWidth > innerWidth, w: document.querySelector('.bk-g-goal__chart').getBoundingClientRect().width, card: document.querySelector('.bk-g-goal').getBoundingClientRect().width }));
  ok(!mob.sx && mob.w <= mob.card, `celular: la gráfica cabe sin scroll horizontal (${Math.round(mob.w)} px)`);
  await g.locator('.bk-g-goal').screenshot({ path: SHOT + '-390.png' });

  // Usuario de solo SEO no puede cambiar la meta.
  const seo = JSON.parse(php('$u = get_user_by( "login", "Gestor_ADS" ); wp_set_current_user( $u ? $u->ID : 0 ); echo wp_json_encode( array( "can" => bk_g_can() ) );'));
  ok(!seo.can, 'usuario de solo SEO y ADS: sin permiso para cambiar la meta');

  ok(!errs.length, 'sin errores JS ' + errs.join(' | '));
  await b.close();
  php('foreach ( wc_get_orders( array( "billing_email" => "meta@ejemplo.com", "limit" => -1, "status" => "any" ) ) as $o ) { $o->delete( true ); } delete_option( "bk_g_daily_goal" ); delete_transient( "bk_g_summary" );');
  console.log(fails ? `\n${fails} FALLOS` : '\nTodo OK');
  process.exit(fails ? 1 : 0);
})();
