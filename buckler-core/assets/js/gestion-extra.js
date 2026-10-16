/**
 * Gestión de tienda: Carritos abandonados (#carritos), Correos (#correos) y SEO y ADS (#seo).
 * Se carga solo al abrir una de esas secciones. Depende de window.bkGApp (gestion.js).
 */
(() => {
	'use strict';

	const A = window.bkGApp;
	if (!A) return;
	const { C, api, esc, $, toast, busy, main } = A;
	const err = (e) => `<p class="bk-g-error">${esc(e.message)}</p>`;
	const num = (n) => Number(n || 0).toLocaleString('es-CO');

	/* ============================================================================
	 * Carritos abandonados
	 * ========================================================================== */
	const aState = { status: '', page: 1 };

	async function viewCarts() {
		const seq = A.seq();
		const path = `abandoned?${A.qs(aState)}`;
		const old = A.getCached(path);
		if (old) paintCarts(old); else main.innerHTML = '<h1 class="bk-g-h1">Carritos abandonados</h1><div class="bk-g-loading">Cargando…</div>';
		try {
			const d = await A.fetchFresh(path);
			if (seq !== A.seq()) return;
			if (JSON.stringify(d) !== JSON.stringify(old)) paintCarts(d);
		} catch (e) { if (!old) main.innerHTML = err(e); }
	}

	const cartPill = { active: 'is-on-hold', recovered: 'is-completed', converted: 'is-completed', awaiting: 'is-pending', unpaid: 'is-failed', emptied: 'is-cancelled', expired: 'is-cancelled', unsubscribed: 'is-failed' };

	function paintCarts(d) {
		if (!d.enabled) {
			main.innerHTML = `<h1 class="bk-g-h1">Carritos abandonados</h1>
				<section class="bk-g-card"><h2>El sistema está apagado</h2>
				<p>Cuando una clienta escribe su correo en el checkout y no termina la compra, buckler-core le envía hasta 3 recordatorios (a las 2, 24 y 48 horas) con un enlace que le devuelve el carrito en cualquier dispositivo. Pide autorización (Ley 1581) y se puede dar de baja.</p>
				<p class="bk-g-hint">Para encenderlo: wp-admin → Ajustes → Buckler Core → "Carritos abandonados".</p></section>`;
			return;
		}
		const k = d.kpi;
		const s = d.settings;
		main.innerHTML = `<div class="bk-g-h1row"><h1 class="bk-g-h1">Carritos abandonados</h1><button type="button" class="bk-g-btn is-ghost" data-carts-refresh>${C.icons.refresh || ''} Actualizar listado</button></div>
			<p class="bk-g-hint">${s.on ? 'Activo' : 'Correos pausados'} · recordatorios a las ${s.delays.map((h) => `${h} h`).join(', ')}${s.coupons.some((c) => c > 0) ? ` · cupones: ${s.coupons.map((c) => (c ? `${c} %` : '—')).join(' / ')}` : ' · sin cupón'} · ${s.consent === 'checkbox' ? 'con casilla de autorización' : 'siempre activo (aviso bajo el correo)'}${s.admin ? ` · <a href="${esc(s.admin)}" target="_blank" rel="noopener">Configurar</a>` : ''}</p>
			<div class="bk-g-kpis">
				<div class="bk-g-kpi"><span>Carritos capturados</span><b>${num(k.captured)}</b><small>Últimos 30 días</small></div>
				<div class="bk-g-kpi"><span>Pendientes</span><b>${num(k.pending)}</b><small>${esc(k.pending_total)} en carritos</small></div>
				<div class="bk-g-kpi"><span>Pendientes de pago</span><b>${num(k.awaiting || 0)}</b><small>${esc(k.awaiting_total || '')} en pedidos sin pagar</small></div>
				<div class="bk-g-kpi"><span>Correos enviados</span><b>${num(k.emails)}</b><small>${num(k.clicked)} abrieron el enlace</small></div>
				<div class="bk-g-kpi is-good"><span>Recuperados</span><b>${num(k.recovered)}</b><small>${esc(k.recovered_total)} en ventas</small></div>
			</div>
			<form class="bk-g-filters" id="bk-g-af"><label><span>Estado</span><select name="status"><option value="">Todos</option>${Object.entries(d.labels).map(([v, l]) => `<option value="${v}" ${aState.status === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></label></form>
			${d.rows.length ? `<p class="bk-g-count">${d.total} carrito${d.total === 1 ? '' : 's'}</p>
			<div class="bk-g-table-wrap"><table class="bk-g-table bk-g-carts"><thead><tr><th>Fecha</th><th>Clienta</th><th>Productos</th><th>Total</th><th>Correos</th><th>Estado</th></tr></thead><tbody>
			${d.rows.map((r) => `<tr>
				<td class="c-date">${esc(r.date)}</td>
				<td class="c-cust"><b>${esc(r.name || '—')}</b><small>${[r.email ? `<a href="mailto:${esc(r.email)}">${esc(r.email)}</a>` : '', r.phone ? `<a href="https://wa.me/57${esc(String(r.phone).replace(/\D/g, '').replace(/^57/, ''))}" target="_blank" rel="noopener">${esc(r.phone)}</a>` : ''].filter(Boolean).join(' · ')}${r.idnum ? `<br>Cédula ${esc(r.idnum)}` : ''}${r.place ? `<br>${esc(r.place)}` : ''}</small></td>
				<td class="c-items">${r.items.map((i) => `<span>${i.qty} × ${esc(i.name)}${i.size ? ` <small>(${esc(i.size)})</small>` : ''}</span>`).join('')}</td>
				<td class="c-total"><b>${esc(r.total)}</b></td>
				<td>${r.emails} de 3${r.clicked ? '<small>Abrió el enlace</small>' : ''}</td>
				<td><span class="bk-g-pill ${cartPill[r.status] || ''}">${esc(r.label)}</span>${r.order ? `<small><button type="button" class="bk-g-link" data-order="${r.order}">Pedido</button></small>` : ''}</td></tr>`).join('')}
			</tbody></table></div>${A.pager(d.pages, aState.page, 'a')}` : '<p class="bk-g-empty">Aún no hay carritos con esos filtros. Aparecen cuando una clienta escribe su correo o su celular en Finalizar compra y no termina la compra.</p>'}`;
		$('#bk-g-af').addEventListener('change', (e) => { aState.status = e.target.value; aState.page = 1; viewCarts(); });
	}

	/* ============================================================================
	 * Correos
	 * ========================================================================== */
	let mail = null;
	async function viewMails() {
		const seq = A.seq();
		main.innerHTML = '<h1 class="bk-g-h1">Correos</h1><div class="bk-g-loading">Cargando…</div>';
		try {
			mail = await A.fetchFresh('emails');
			if (seq !== A.seq()) return;
		} catch (e) { main.innerHTML = err(e); return; }
		const groups = [['Clienta', 'Correos a la clienta'], ['Tienda', 'Avisos a la tienda']];
		main.innerHTML = `<h1 class="bk-g-h1">Correos</h1>
			<p class="bk-g-hint">Mira cómo le llega cada correo a la clienta con un pedido real y envíate pruebas. La vista previa no envía nada.${mail.settings ? ` Textos y remitente: <a href="${esc(mail.settings)}" target="_blank" rel="noopener">WooCommerce → Ajustes → Correos</a>.` : ''}</p>
			<div class="bk-g-mail">
				<div class="bk-g-mail__list">
					<label class="bk-g-field"><span>Pedido de ejemplo</span><select id="bk-g-morder">${mail.orders.map((o) => `<option value="${o.id}">${esc(o.label)}</option>`).join('') || '<option value="0">Sin pedidos</option>'}</select></label>
					${groups.map(([g, title]) => `<h2>${title}</h2><ul role="list">${mail.emails.filter((m) => m.group === g).map((m) => `<li><button type="button" class="bk-g-mitem" data-mail="${esc(m.id)}" aria-pressed="false">
						<b>${esc(m.title)}</b><small>${esc(m.when)}</small><span class="bk-g-pill ${m.enabled ? 'is-completed' : 'is-cancelled'}">${m.enabled ? 'Activo' : 'Apagado'}</span><span class="bk-g-msrc">${esc(m.source)}</span></button></li>`).join('')}</ul>`).join('')}
				</div>
				<div class="bk-g-mail__view" id="bk-g-mview" aria-live="polite"><p class="bk-g-empty">Elige un correo para ver la vista previa.</p></div>
			</div>`;
		const first = $('[data-mail]');
		if (first && matchMedia('(min-width: 900px)').matches) showMail(first.dataset.mail);
	}

	async function showMail(id) {
		const box = $('#bk-g-mview');
		if (!box) return;
		document.querySelectorAll('[data-mail]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mail === id)));
		const order = Number($('#bk-g-morder')?.value || 0);
		box.innerHTML = '<div class="bk-g-loading">Armando la vista previa…</div>';
		try {
			const d = await api('emails/preview', { method: 'POST', body: { email: id, order } });
			const m = mail.emails.find((x) => x.id === id);
			box.innerHTML = `<header class="bk-g-mhead"><h2>${esc(m?.title || '')}</h2>
				<dl class="bk-g-pvmeta"><div><dt>Para</dt><dd>${esc(d.to || '—')}</dd></div><div><dt>Asunto</dt><dd>${esc(d.subject)}</dd></div>${mail.from ? `<div><dt>De</dt><dd>${esc(mail.from)}</dd></div>` : ''}</dl></header>
				<iframe class="bk-g-pvframe bk-g-mframe" title="Vista previa: ${esc(m?.title || '')}" sandbox=""></iframe>
				<form class="bk-g-mtest" id="bk-g-mtest" data-mail="${esc(id)}"><label><span>Enviar prueba a</span><input type="email" name="to" required value="${esc(mail.me || '')}" autocomplete="email"></label>
					<button class="bk-g-btn" type="submit">${C.icons.send || ''} Enviar prueba</button></form>
				<p class="bk-g-hint">La prueba llega con "[Prueba]" en el asunto y usa el pedido de ejemplo elegido.</p>`;
			$('.bk-g-mframe', box).srcdoc = d.html;
			if (matchMedia('(max-width: 899px)').matches) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
		} catch (e) { box.innerHTML = err(e); }
	}

	/* ============================================================================
	 * SEO y ADS
	 * ========================================================================== */
	const vState = { days: 30 };

	// Selectores estables para seguimiento externo (GTM, Meta, Hotjar…) y eventos de dataLayer de buckler-core.
	const TRACK = [
		['Botón "Añadir al carrito" (ficha)', 'form.cart .single_add_to_cart_button', 'Ficha de producto'],
		['Botón de talla (ficha)', '.bk-sizes .bk-size', 'Ficha de producto'],
		['Guía de tallas', '.bk-pdp__guide', 'Ficha de producto'],
		['Ver el modelo en la otra línea', '.bk-pdp__twin', 'Ficha de producto'],
		['Tarjeta de producto (clic)', '.bk-card__link', 'Tienda, inicio, relacionados'],
		['Tarjeta de producto', '.bk-card', 'Tienda, inicio, relacionados'],
		['Icono del carrito (header)', '[data-bk-cart]', 'Todas'],
		['Botón flotante del carrito', '.bk-fcart', 'Todas'],
		['Carrito flotante: "Finalizar compra"', '.bk-sc__btn--primary', 'Carrito flotante'],
		['Carrito flotante: añadir sugerido', '.bk-sc__btn--add', 'Carrito flotante'],
		['Buscador del header', 'form.bk-search', 'Todas'],
		['Menú Tienda (desplegable)', '.bk-mega', 'Todas (escritorio)'],
		['Menú lateral (celular)', '.bk-drawer', 'Todas (celular)'],
		['Banner principal', '.bk-hero a', 'Inicio'],
		['Banners Línea Slim / Curvy', '.bk-lineas a', 'Inicio'],
		['Filtros de la tienda', '.bk-shop__filters', 'Tienda y categorías'],
		['Botón "Ver más" de la tienda', '[data-bk-shop-more]', 'Tienda y categorías'],
		['WhatsApp', 'a[href*="wa.me"]', 'Todas'],
		['Califícanos en Google', '.bk-promo a, a[href*="g.page/r/"]', 'Ficha, carrito, gracias, Mi cuenta'],
		['Reseñas de Google', '.bk-reviews', 'Ficha y carrito'],
		['Botón "Realizar el pedido" (checkout)', '#place_order', 'Checkout'],
		['Página de gracias', '.woocommerce-order-received, body.woocommerce-order-received', 'Gracias por tu compra'],
	];
	const EVENTS = [
		['bk_add_to_cart', 'Añadió una prenda al carrito sin recargar (ficha o sugerido). Formato de comercio electrónico de GA4: ecommerce.items[] con REF, nombre, talla y precio.'],
		['bk_begin_checkout', 'Clic en "Finalizar compra" (carrito flotante o carrito).'],
		['bk_select_item', 'Clic en una tarjeta de producto (item_name, link_url).'],
		['bk_search', 'Búsqueda en el header (search_term).'],
		['bk_whatsapp_click', 'Clic en cualquier enlace de WhatsApp.'],
		['bk_size_select', 'Eligió una talla en la ficha (size).'],
		['bk_size_guide_open', 'Abrió la guía de tallas.'],
		['bk_other_line_click', 'Clic en "Ver este modelo en la otra línea".'],
		['bk_review_click', 'Clic en "Califícanos en Google".'],
		['bk_banner_click', 'Clic en el banner principal o en los de líneas (banner: hero | lineas).'],
	];
	const stLabel = { ok: ['Funciona', 'is-completed'], warn: ['Revisar', 'is-on-hold'], missing: ['No encontrado', 'is-failed'], info: ['Info', 'is-pending'] };
	const bars = (series, key) => {
		const max = Math.max(1, ...series.map((x) => x[key]));
		return `<div class="bk-g-bars" role="img" aria-label="Visitantes por día">${series.map((x) => `<span style="--h:${Math.round((x[key] / max) * 100)}%" title="${esc(x.label)}: ${x[key]}"><i></i></span>`).join('')}</div>
			<div class="bk-g-bars__axis"><span>${esc(series[0]?.label || '')}</span><span>${esc(series[series.length - 1]?.label || '')}</span></div>`;
	};
	const list = (rows, unit, empty) => rows?.length ? `<ol class="bk-g-rank">${rows.map((r) => { const max = rows[0].n || 1; return `<li><span class="bk-g-rank__l" title="${esc(r.label)}">${esc(r.label)}</span><b>${num(r.n)}</b><i style="--w:${Math.round((r.n / max) * 100)}%"></i></li>`; }).join('')}</ol><small class="bk-g-hint">${unit}</small>` : `<p class="bk-g-empty">${empty}</p>`;

	// Correo a UFO Web con las etiquetas faltantes ya listadas.
	const mailto = (missing) => {
		const body = `Hola, en Buckler (${C.site}) necesitamos instalar o revisar:\n\n${missing.length ? missing.map((m) => `- ${m}`).join('\n') : '- (escribe aquí la etiqueta, su ID y qué debe medir)'}\n\nGracias.`;
		return `mailto:contacto@ufoweb.co?subject=${encodeURIComponent('Etiquetas faltantes · Buckler (SEO y ADS)')}&body=${encodeURIComponent(body)}`;
	};

	async function viewSeo(sub) {
		const tab = ['etiquetas', 'rastreo', 'agencia'].includes(sub[0]) ? sub[0] : (sub[0] === 'visitas' || C.scope !== 'seo' ? 'visitas' : 'agencia');
		main.innerHTML = `<h1 class="bk-g-h1">SEO y ADS</h1>
			<aside class="bk-g-alert" role="note" aria-labelledby="bk-g-alert-t">${C.icons.info || ''}<div><p id="bk-g-alert-t"><b>¿Falta alguna etiqueta o píxel?</b></p>
				<p>Si necesitas medir algo que no aparece aquí (Google Ads, GA4, píxel de Meta o TikTok, conversiones, Merchant Center…), envía el ID y qué debe medir a <a id="bk-g-alert-mail" href="${mailto([])}">contacto@ufoweb.co</a>. No pegues códigos directamente en el sitio: se instalan con carga diferida para no hacerlo más lento.</p></div></aside>
			${tab !== 'agencia' ? `<a class="bk-g-agency-cta" href="#seo/agencia">${C.icons.chart} <span><b>¿Eres la agencia de pauta?</b> Aquí está todo listo para GA4, GTM y Google Ads: eventos de comercio electrónico y contenedor para importar.</span> ${C.icons.ext}</a>` : ''}
			<nav class="bk-g-subtabs" aria-label="SEO y ADS">${[['agencia', 'Guía para la agencia (GA4 + GTM)'], ['visitas', 'Visitas'], ['etiquetas', 'Etiquetas Google y Meta'], ['rastreo', 'Clases y eventos rastreables']].map(([k, l]) => `<a href="#seo/${k}" ${tab === k ? 'aria-current="page"' : ''}>${l}</a>`).join('')}</nav>
			<div id="bk-g-seo"><div class="bk-g-loading">Cargando…</div></div>`;
		({ visitas: seoVisits, etiquetas: seoTags, rastreo: seoTrack, agencia: seoAgency })[tab]();
	}

	/* --- Guía para la agencia: GA4 + GTM ------------------------------------------ */
	const ECOM = [
		['view_item', 'Ficha de producto', 'Al abrir la ficha (1 producto, precio vigente).'],
		['add_to_cart', 'Ficha y carrito flotante', 'Al agregar una prenda (talla en item_variant, cantidad, precio).'],
		['remove_from_cart', 'Carrito flotante y carrito', 'Al quitar una prenda.'],
		['view_cart', 'Página del carrito', 'Al abrir el carrito (todas las prendas y el total).'],
		['begin_checkout', 'Finalizar compra', 'Al abrir el checkout con productos (cupones en coupon).'],
		['add_payment_info', 'Finalizar compra', 'Al tocar "Realizar el pedido" (payment_type = Wompi, Bold, Addi…).'],
		['purchase', 'Gracias por tu compra', 'Una sola vez por pedido, solo pagado (o en espera de verificación). Si la pasarela confirma unos segundos después, se envía al confirmarse. transaction_id = número de pedido.'],
	];
	const SAMPLE = {
		event: 'purchase',
		ecommerce: {
			transaction_id: '3846', affiliation: 'Buckler Jeans · Tienda online', value: 169900, tax: 0, shipping: 0, currency: 'COP', coupon: '', payment_type: 'Wompi',
			items: [{ item_id: '9912', item_name: 'CHAQUETA DAMA', item_brand: 'Buckler Jeans', item_category: 'Línea Slim', item_category2: 'Chaquetas Y Chalecos', item_variant: '8', price: 169900, quantity: 1, index: 0 }],
		},
		user_data: { sha256_email_address: '9f86d0…', sha256_phone_number: 'a3c4e1…' },
	};

	function seoAgency() {
		const box = $('#bk-g-seo');
		const ids = C.adsIds || {};
		const code = (t) => `<code>${esc(t)}</code>`;
		box.innerHTML = `<section class="bk-g-card bk-g-agency">
			<h2>Guía para la agencia: GA4, Google Tag Manager y Google Ads</h2>
			<p>El sitio ya deja en el <b>dataLayer</b> todos los eventos de comercio electrónico de GA4, con el <b>formato estándar de Google</b> (cada uno precedido de ${code('{ ecommerce: null }')}). Solo falta que GTM los envíe a GA4: se hace importando el contenedor de abajo, en 5 minutos y sin tocar el código del sitio.</p>
			<dl class="bk-g-agency__ids">
				<div><dt>Google Tag Manager</dt><dd>${code(ids.gtm || '—')}</dd></div>
				<div><dt>GA4 (ID de medición)</dt><dd>${code(ids.ga4 || '—')}</dd></div>
				<div><dt>Píxel de Meta</dt><dd>${code(ids.meta_pixel || '—')} <small>(lo maneja Facebook for WooCommerce)</small></dd></div>
				<div><dt>Merchant Center</dt><dd>${code(ids.merchant_center || '—')}</dd></div>
			</dl>
			<p><button type="button" class="bk-g-btn" data-gtm-import>${C.icons.dl} Descargar contenedor para importar (JSON)</button></p>

			<h3>1. Importar en GTM (una vez)</h3>
			<ol class="bk-g-agency__steps">
				<li>En <a href="https://tagmanager.google.com/" target="_blank" rel="noopener">Tag Manager</a> abre el contenedor ${code(ids.gtm || '')} → <b>Administrador → Importar contenedor</b>.</li>
				<li>Elige el archivo descargado, el espacio de trabajo actual y la opción <b>Combinar → Cambiar el nombre de etiquetas, activadores y variables en conflicto</b> (no reemplaza nada de lo que ya tienen).</li>
				<li>Se crean: la etiqueta <b>GA4 - Comercio electrónico (Buckler)</b> (Evento de GA4, nombre ${code('{{Event}}')}, "Enviar datos de comercio electrónico" desde la capa de datos, ID ${code(ids.ga4 || '')}), el activador <b>CE - Comercio electrónico GA4 (Buckler)</b> (evento personalizado: ${code(ECOM.map((e) => e[0]).join('|'))}) y 5 variables de capa de datos para Google Ads.</li>
				<li>Revisa que no tengan ya otra etiqueta que envíe ${code('purchase')} a GA4 (si existe, páusala: si no, cada compra se cuenta dos veces).</li>
				<li><b>Vista previa</b> (Tag Assistant): agrega una prenda, abre el carrito y el checkout. Deben aparecer los eventos de la tabla con la pestaña <i>Data Layer</i> llena. Luego <b>Enviar → Publicar</b>.</li>
			</ol>

			<h3>2. En GA4</h3>
			<ol class="bk-g-agency__steps">
				<li><b>Administrador → Eventos</b>: marca ${code('purchase')} como <b>evento clave</b> (aparece tras la primera compra; también se puede crear a mano con ese nombre).</li>
				<li>Los informes de <b>Monetización → Compras de comercio electrónico</b> se llenan solos (productos, tallas, línea y prenda).</li>
				<li>Para Google Ads: vincula GA4 con la cuenta de Ads e <b>importa el evento clave purchase</b> como conversión (valor dinámico en COP).</li>
			</ol>

			<h3>3. Conversiones mejoradas (opcional, recomendado para Google Ads)</h3>
			<p>${code('purchase')} trae ${code('user_data.sha256_email_address')} y ${code('user_data.sha256_phone_number')} (SHA-256, celular en formato +57…). Nunca viajan en claro. En una etiqueta de conversión de Google Ads usen "Incluir datos proporcionados por el usuario" con las variables ${code('DLV - user_data.*')} del contenedor importado.</p>

			<h3>Eventos disponibles</h3>
			<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Evento</th><th>Dónde</th><th>Cuándo y qué datos</th></tr></thead><tbody>
				${ECOM.map(([e, w, d]) => `<tr><td>${code(e)}</td><td>${esc(w)}</td><td>${esc(d)}</td></tr>`).join('')}
			</tbody></table></div>
			<p class="bk-g-hint">Cada item trae: ${code('item_id')} (REF), ${code('item_name')}, ${code('item_brand')}, ${code('item_category')} (Línea Slim / Curvy), ${code('item_category2')} (prenda), ${code('item_variant')} (talla), ${code('price')} y ${code('quantity')}. Moneda: COP.</p>
			<details class="bk-g-agency__sample"><summary>Ejemplo de ${code('purchase')} en el dataLayer</summary><pre>${esc(JSON.stringify(SAMPLE, null, 2))}</pre></details>

			<h3>Bueno saber</h3>
			<ul class="bk-g-agency__notes">
				<li><b>Carga diferida:</b> para que el sitio sea rápido, GTM, GA4 y AdsAgent se cargan con la primera interacción (tocar, mover el mouse o desplazarse). Los eventos quedan en cola en el dataLayer y GTM los procesa al cargar: no se pierden. En el checkout y la página de gracias carga de inmediato.</li>
				<li><b>Meta:</b> el píxel ${code(ids.meta_pixel || '')} y su API de conversiones los maneja el plugin Facebook for WooCommerce. No agreguen el píxel en GTM (se duplicaría).</li>
				<li><b>GA4 directo:</b> el sitio también carga ${code(ids.ga4 || '')} con gtag (vistas de página). La etiqueta importada no envía page_view, así que no se duplican.</li>
				<li>Siguen existiendo los eventos propios ${code('bk_*')} (clics en WhatsApp, tallas, banners, búsquedas…): están en la pestaña <a href="#seo/rastreo">Clases y eventos rastreables</a>.</li>
				<li>¿Dudas o algo que no se pueda medir? Escriban a <a href="mailto:contacto@ufoweb.co">contacto@ufoweb.co</a>. No peguen códigos en el sitio: se instalan con carga diferida.</li>
			</ul>
		</section>`;
	}

	document.addEventListener('click', async (e) => {
		const b = e.target.closest('[data-gtm-import]');
		if (!b) return;
		await busy(b, async () => {
			const d = await api('seo/gtm-import');
			const url = URL.createObjectURL(new Blob([JSON.stringify(d, null, 2)], { type: 'application/json' }));
			const a = Object.assign(document.createElement('a'), { href: url, download: 'buckler-gtm-ga4-ecommerce.json' });
			document.body.append(a); a.click(); a.remove();
			setTimeout(() => URL.revokeObjectURL(url), 2000);
			toast('Contenedor descargado');
		});
	});

	async function seoVisits() {
		const box = $('#bk-g-seo');
		const seq = A.seq();
		let d;
		try { d = await A.fetchFresh(`seo/visits?days=${vState.days}`); } catch (e) { box.innerHTML = err(e); return; }
		if (seq !== A.seq() || !box.isConnected) return;
		let tags = A.getCached('seo/tags');
		if (!d.enabled) {
			box.innerHTML = `<section class="bk-g-card"><h2>El contador de visitas está apagado</h2>
				<p>Enciende el módulo <b>"Estadísticas de visitas (SEO y ADS)"</b> en wp-admin → Ajustes → Buckler Core. Cuenta visitas sin cookies ni datos personales (no necesita aviso de cookies), no cuenta robots ni al equipo, y no hace más lenta la tienda (la señal sale después de cargar la página).</p></section>
				<div id="bk-g-ord"></div>`;
		} else {
			const k = d.kpis;
			box.innerHTML = `<div class="bk-g-kpis">
					<div class="bk-g-kpi"><span>Visitantes hoy</span><b>${num(k.today.visitors)}</b><small>${num(k.today.views)} páginas vistas</small></div>
					<div class="bk-g-kpi"><span>Últimos 7 días</span><b>${num(k.week.visitors)}</b><small>${num(k.week.views)} páginas vistas</small></div>
					<div class="bk-g-kpi"><span>Últimos 30 días</span><b>${num(k.month.visitors)}</b><small>${num(k.month.views)} páginas vistas</small></div>
				</div>
				<section class="bk-g-card"><div class="bk-g-h2row"><h2>Visitantes por día</h2>
					<label><span class="bk-g-sr">Periodo</span><select id="bk-g-vdays">${[7, 30, 90].map((n) => `<option value="${n}" ${vState.days === n ? 'selected' : ''}>Últimos ${n} días</option>`).join('')}</select></label></div>
					${bars(d.series, 'visitors')}
					<p class="bk-g-hint">Contando desde ${esc(d.since || 'hoy')}. Visitante = persona única en el periodo (huella anónima, sin cookies; se reinicia cada mes).</p></section>
				<div class="bk-g-grid2">
					<section class="bk-g-card"><h2>Páginas más vistas</h2>${list(d.pages, 'Páginas vistas en el periodo.', 'Aún no hay datos.')}</section>
					<section class="bk-g-card"><h2>De dónde llegan</h2>${list(d.sources, 'Visitantes por origen (primera visita del día). Google Ads = gclid; Meta Ads = fbclid o utm de pago.', 'Aún no hay datos.')}</section>
					<section class="bk-g-card"><h2>Ciudades de los visitantes</h2>${d.geo.has ? list(d.cities, 'Visitantes por ciudad (según la IP, aproximado).', 'Aún no hay datos.') : `<p class="bk-g-empty">Para ver ciudades de visitantes hace falta la base de ciudades gratuita DB-IP Lite (≈ 130 MB, se actualiza sola cada mes).</p>${d.geo.admin ? `<button type="button" class="bk-g-btn is-ghost is-small" data-geo>${C.icons.refresh} Descargar base de ciudades</button>` : '<p class="bk-g-hint">Pídele a un administrador que la descargue aquí.</p>'}${d.geo.status?.state ? `<p class="bk-g-hint">Estado: ${esc({ queued: 'en cola', running: 'descargando…', ok: 'lista', error: `error: ${d.geo.status.msg || ''}` }[d.geo.status.state] || d.geo.status.state)}</p>` : ''}`}
						${d.geo.has ? '<small class="bk-g-hint">IP Geolocation by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a></small>' : ''}</section>
					<section class="bk-g-card"><h2>Dispositivos</h2>${list(d.devices, 'Visitantes por tipo de pantalla.', 'Aún no hay datos.')}
						<h2 class="bk-g-mt">Páginas de entrada</h2>${list(d.landing, 'Primera página que vieron.', 'Aún no hay datos.')}</section>
					${d.camps?.length ? `<section class="bk-g-card"><h2>Campañas (utm_campaign)</h2>${list(d.camps, 'Visitantes por campaña.', '')}</section>` : ''}
				</div>
				<div id="bk-g-ord"></div>`;
			$('#bk-g-vdays')?.addEventListener('change', (e) => { vState.days = Number(e.target.value); seoVisits(); });
		}
		// Ventas por origen y ciudades de compra (de los pedidos reales; usa la revisión de etiquetas en caché).
		const ord = $('#bk-g-ord');
		const paintOrd = (t) => {
			if (!ord?.isConnected || !t?.orders) return;
			const o = t.orders;
			ord.innerHTML = `<div class="bk-g-grid2">
				<section class="bk-g-card"><h2>Ventas por origen (30 días)</h2>${o.sources.length ? `<ol class="bk-g-rank">${o.sources.map((x) => `<li><span class="bk-g-rank__l">${esc(x.label)}</span><b>${x.orders} · ${esc(x.total)}</b></li>`).join('')}</ol>` : '<p class="bk-g-empty">Sin ventas pagadas en 30 días.</p>'}<small class="bk-g-hint">Atribución nativa de WooCommerce en ${o.attributed} de ${o.orders} pedidos pagados.</small></section>
				<section class="bk-g-card"><h2>Ciudades de compra (30 días)</h2>${list(o.cities, 'Pedidos pagados por ciudad de facturación.', 'Sin ventas pagadas en 30 días.')}</section></div>`;
		};
		if (tags) paintOrd(tags);
		else if (ord) {
			ord.innerHTML = '<div class="bk-g-loading">Revisando pedidos y etiquetas…</div>';
			try { tags = await A.fetchFresh('seo/tags'); paintOrd(tags); } catch (e) { ord.innerHTML = ''; }
		}
	}

	async function seoTags(refresh = false) {
		const box = $('#bk-g-seo');
		const seq = A.seq();
		if (refresh) A.forget('seo/tags');
		const old = A.getCached('seo/tags');
		if (!old) box.innerHTML = '<div class="bk-g-loading">Revisando el HTML público del inicio, la tienda y un producto, y el contenedor de Google Tag Manager… (puede tardar unos segundos)</div>';
		let d;
		try { d = old || await api(`seo/tags${refresh ? '?refresh=1' : ''}`); } catch (e) { box.innerHTML = err(e); return; }
		if (!old) A.forget('seo/tags');
		if (seq !== A.seq() || !box.isConnected) return;
		const missing = d.tags.filter((t) => t.status === 'missing' || t.status === 'warn').map((t) => `${t.name}: ${t.note}`);
		const am = $('#bk-g-alert-mail');
		if (am) am.href = mailto(missing);
		const o = d.orders;
		const yes = (b) => (b ? '<span class="bk-g-pill is-completed">Sí</span>' : '<span class="bk-g-pill is-failed">No</span>');
		box.innerHTML = `<div class="bk-g-h2row"><p class="bk-g-hint">Revisado el ${esc(d.checked)} (se guarda 6 horas).</p><button type="button" class="bk-g-btn is-ghost is-small" data-tags-refresh>${C.icons.refresh} Revisar ahora</button></div>
			${d.delayed ? '<p class="bk-g-note">Las etiquetas cargan <b>después de la primera interacción</b> (mover, tocar o desplazar): así la tienda carga rápido y los eventos se conservan. Las visitas que se van sin tocar nada no se envían a Google/Meta; el contador propio sí las cuenta.</p>' : ''}
			<section class="bk-g-card"><h2>Etiquetas encontradas</h2>
			<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Etiqueta</th><th>ID</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>
			${d.tags.map((t) => `<tr><td><b>${esc(t.name)}</b></td><td>${t.ids.map((i) => `<code>${esc(i)}</code>`).join('<br>') || '—'}</td><td><span class="bk-g-pill ${stLabel[t.status][1]}">${stLabel[t.status][0]}</span></td><td>${esc(t.note)}${t.link ? ` <a href="${esc(t.link)}" target="_blank" rel="noopener">Abrir la cuenta ${C.icons.open || ''}</a>` : ''}</td></tr>`).join('')}
			</tbody></table></div>${d.extra.length ? `<p class="bk-g-hint">Otras herramientas detectadas: ${d.extra.map(esc).join(', ')}.</p>` : ''}</section>
			${Object.entries(d.gtm).map(([id, g]) => `<section class="bk-g-card"><h2>Contenedor ${esc(id)} (lo que hay dentro de GTM)</h2>
				${g.ok ? `<div class="bk-g-grid2"><div><h3>Etiquetas configuradas</h3>${g.tags.length ? `<ul class="bk-g-ul">${g.tags.map((t) => `<li>${esc(t.type)} <b>×${t.count}</b></li>`).join('')}</ul>` : '<p class="bk-g-empty">Sin etiquetas reconocidas.</p>'}${g.extra.length ? `<p class="bk-g-hint">${g.extra.map(esc).join(' · ')}</p>` : ''}</div>
				<div><h3>IDs y eventos</h3><p>GA4: ${g.ga4.map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}<br>Google Ads: ${[...g.ads, ...g.ads_conv.map((x) => `AW-${x}`)].filter((x, i, a) => a.indexOf(x) === i).map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}</p>
				<p>Eventos que envía: ${g.events.map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}</p>
				${g.triggers.length ? `<p>Escucha eventos de la web: ${g.triggers.map((x) => `<code>${esc(x)}</code>`).join(' ')}</p>` : '<p class="bk-g-hint">Aún no escucha los eventos bk_ de buckler-core (ver "Clases y eventos rastreables").</p>'}</div></div>
				<p class="bk-g-hint">Leído del archivo público gtm.js (${g.size_kb} KB).</p>` : `<p class="bk-g-error">No se pudo leer: ${esc(g.error)}</p>`}</section>`).join('')}
			<section class="bk-g-card"><h2>¿Las compras llegan a Google y Meta? (pedidos pagados, 30 días)</h2>
				<div class="bk-g-kpis is-small">
					<div class="bk-g-kpi"><span>Pedidos pagados</span><b>${o.orders}</b><small>${esc(o.total)}</small></div>
					<div class="bk-g-kpi"><span>Con evento de compra de Meta</span><b>${o.meta} / ${o.orders}</b><small>Marcados por el píxel / API de conversiones</small></div>
					<div class="bk-g-kpi"><span>Con origen registrado</span><b>${o.attributed} / ${o.orders}</b><small>Atribución de WooCommerce (utm, gclid, fbclid)</small></div>
					${o.ufo ? `<div class="bk-g-kpi"><span>Analytics UFO</span><b>${o.ufo} / ${o.orders}</b><small>Compra registrada</small></div>` : ''}
				</div>
				<p class="bk-g-hint">Si "Con evento de compra de Meta" es mucho menor que los pedidos pagados, Meta no está recibiendo todas las ventas (revisa la API de conversiones del plugin de Facebook).</p></section>
			<section class="bk-g-card"><h2>Eventos vistos en el HTML</h2>
				<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Página</th><th>Respuesta</th><th>Meta (fbq)</th><th>Google (gtag)</th><th>dataLayer</th></tr></thead><tbody>
				${d.pages.map((p) => `<tr><td><a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.label)}</a></td><td>${p.code ? `${p.code} · ${num(p.ms)} ms` : `<span class="bk-g-pill is-failed">${esc(p.error || 'Error')}</span>`}</td><td>${p.fbq.map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}</td><td>${p.gtag.map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}</td><td>${p.dl.slice(0, 8).map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}</td></tr>`).join('')}
				</tbody></table></div><p class="bk-g-hint">Los eventos de compra (Purchase / purchase) solo aparecen en la página de gracias.</p></section>
			<section class="bk-g-card"><h2>SEO técnico</h2>
				<div class="bk-g-table-wrap"><table class="bk-g-table bk-g-seot"><thead><tr><th>Página</th><th>Título</th><th>Descripción</th><th>H1</th><th>Canónica</th><th>Imagen para redes</th><th>Datos estructurados</th><th>Indexable</th></tr></thead><tbody>
				${d.seo.map((p) => `<tr><td><a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.label)}</a></td><td>${esc(p.title || '—')} <small>${p.title.length} car.</small></td><td>${p.desc ? `${esc(p.desc.slice(0, 90))}… <small>${p.desc.length} car.</small>` : '<span class="bk-g-pill is-failed">Falta</span>'}</td><td>${p.h1 === 1 ? '<span class="bk-g-pill is-completed">1</span>' : `<span class="bk-g-pill is-on-hold">${p.h1}</span>`}</td><td>${yes(p.canonical)}</td><td>${yes(p.og)}</td><td>${p.schema.map((x) => `<code>${esc(x)}</code>`).join(' ') || '—'}</td><td>${yes(!p.noindex)}</td></tr>`).join('')}
				</tbody></table></div>
				<ul class="bk-g-ul">
					<li>Sitio visible para buscadores: ${yes(d.site.blog_public)}</li>
					<li>HTTPS: ${yes(d.site.https)}</li>
					<li>robots.txt: ${yes(d.site.robots)}${d.site.robots_all ? ' <span class="bk-g-pill is-failed">Bloquea todo el sitio</span>' : ''}</li>
					<li>Mapa del sitio (sitemap): ${d.site.sitemap ? `<a href="${esc(d.site.sitemap)}" target="_blank" rel="noopener">${esc(d.site.sitemap)}</a>` : yes(false)}</li>
					<li>llms.txt (asistentes de IA): ${yes(d.site.llms)}</li>
					<li>Catálogo para Google Merchant (Google for WooCommerce): ${yes(d.feeds.google)} · Catálogo de Meta (Facebook for WooCommerce): ${yes(d.feeds.meta)}</li>
				</ul></section>`;
	}

	function seoTrack() {
		$('#bk-g-seo').innerHTML = `<section class="bk-g-card"><h2>Eventos de dataLayer (Google Tag Manager)</h2>
				<p class="bk-g-hint">buckler-core los envía a <code>window.dataLayer</code>. En GTM: Activador → "Evento personalizado" → nombre del evento. Llevan el prefijo <code>bk_</code> para no chocar con los de otros plugins.</p>
				<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Evento</th><th>Cuándo</th></tr></thead><tbody>
				${EVENTS.map(([e, w]) => `<tr><td><code>${esc(e)}</code> <button type="button" class="bk-g-icon" data-copy="${esc(e)}" aria-label="Copiar ${esc(e)}">${C.icons.copy}</button></td><td>${esc(w)}</td></tr>`).join('')}
				</tbody></table></div></section>
			<section class="bk-g-card"><h2>Clases y selectores rastreables</h2>
				<p class="bk-g-hint">Estables entre versiones de buckler-core. Úsalos en GTM (Activador → "Clic: todos los elementos" → "Click Element coincide con el selector CSS"), Meta Event Setup Tool, Hotjar o Clarity.</p>
				<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Elemento</th><th>Selector CSS</th><th>Dónde</th></tr></thead><tbody>
				${TRACK.map(([l, sel, w]) => `<tr><td>${esc(l)}</td><td><code>${esc(sel)}</code> <button type="button" class="bk-g-icon" data-copy="${esc(sel)}" aria-label="Copiar selector de ${esc(l)}">${C.icons.copy}</button></td><td>${esc(w)}</td></tr>`).join('')}
				</tbody></table></div></section>
			<section class="bk-g-card"><h2>Ejemplo para GTM</h2>
				<pre class="bk-g-pre">dataLayer.push({
  event: 'bk_add_to_cart',
  ecommerce: {
    currency: 'COP', value: 129900,
    items: [{ item_id: '816', item_name: 'JEAN BOTA ANCHA', item_variant: '10', price: 129900, quantity: 1 }]
  }
});</pre></section>`;
	}

	/* ============================================================================
	 * Eventos
	 * ========================================================================== */
	document.addEventListener('click', async (e) => {
		const t = e.target;
		const m = t.closest('[data-mail]');
		if (m && m.matches('button')) { showMail(m.dataset.mail); return; }
		if (t.closest('[data-carts-refresh]')) {
			await busy(t.closest('button'), async () => {
				A.forget('abandoned');
				const d = await A.fetchFresh(`abandoned?${A.qs(aState)}`);
				paintCarts(d);
				toast(`Listado actualizado · ${new Date().toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })}`);
			});
			return;
		}
		if (t.closest('[data-tags-refresh]')) { await busy(t.closest('button'), () => seoTags(true)); return; }
		const cp = t.closest('[data-copy]');
		if (cp) {
			try { await navigator.clipboard.writeText(cp.dataset.copy); toast(`Copiado: ${cp.dataset.copy}`); } catch (er) { toast('No se pudo copiar', 'err'); }
			return;
		}
		const geo = t.closest('[data-geo]');
		if (geo) {
			await busy(geo, async () => {
				await api('seo/geo', { method: 'POST' });
				A.forget('seo/visits');
				toast('Descarga programada. Las ciudades aparecen desde las próximas visitas.');
				seoVisits();
			});
		}
	});
	document.addEventListener('change', (e) => {
		if (e.target.id === 'bk-g-morder') {
			const on = document.querySelector('[data-mail][aria-pressed="true"]');
			if (on) showMail(on.dataset.mail);
		}
	});
	document.addEventListener('submit', async (e) => {
		const f = e.target;
		if (f.id !== 'bk-g-mtest') return;
		e.preventDefault();
		await busy(f.querySelector('button'), async () => {
			const d = await api('emails/test', { method: 'POST', body: { email: f.dataset.mail, order: Number($('#bk-g-morder')?.value || 0), to: f.to.value.trim() } });
			toast(d.sent ? `Prueba enviada a ${d.to}` : 'No se pudo enviar. Revisa la configuración de correo (SMTP).', d.sent ? 'ok' : 'err');
		});
	});

	window.bkGExtra = {
		view(tab, sub) {
			if (tab === 'carritos') viewCarts();
			else if (tab === 'correos') viewMails();
			else viewSeo(sub || []);
		},
		page(kind, n) { if (kind === 'a') { aState.page = n; viewCarts(); } },
	};
})();
