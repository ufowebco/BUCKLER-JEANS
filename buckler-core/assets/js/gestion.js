/**
 * Gestión de tienda (/gestion-tienda/): resumen, pedidos, productos, carritos, correos, SEO y ADS (y ajustes).
 * JS propio sin dependencias. Datos por REST (bk/v1/gestion/*) con nonce wp_rest.
 * Velocidad: el resumen llega dentro de la página, las respuestas se guardan en memoria y se muestran al
 * instante mientras se actualizan por detrás, el detalle de un pedido se pide al pasar el cursor, y el
 * editor de productos y los módulos Carritos / Correos / SEO y ADS se cargan solo cuando se abren.
 */
(() => {
	'use strict';

	const C = window.bkG;
	const app = document.getElementById('bk-g-app');
	if (!C || !app) return;

	/* --- Utilidades ----------------------------------------------------------- */
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const $ = (sel, el = document) => el.querySelector(sel);
	const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
	const statusLabel = (s) => C.statuses[s] || s;
	const qs = (o) => new URLSearchParams(Object.entries(o).filter(([, v]) => v !== '' && v != null)).toString();

	const api = async (path, opts = {}) => {
		const res = await fetch(C.api + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			cache: 'no-store', // Siempre datos nuevos (pedidos recién llegados).
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
			body: opts.body ? JSON.stringify(opts.body) : undefined,
		});
		const data = await res.json().catch(() => ({}));
		if (!res.ok) throw Object.assign(new Error(data.message || 'No se pudo completar la acción.'), { field: data.data?.field });
		return data;
	};

	// Caché en memoria: muestra lo último que se vio y lo actualiza por detrás (stale-while-revalidate).
	const memo = new Map();
	const inflight = new Map();
	const getCached = (path) => memo.get(path);
	const fetchFresh = (path) => {
		if (inflight.has(path)) return inflight.get(path);
		const pr = api(path).then((d) => { memo.set(path, d); return d; }).finally(() => inflight.delete(path));
		inflight.set(path, pr);
		return pr;
	};
	const forget = (prefix) => { [...memo.keys()].forEach((k) => { if (k.startsWith(prefix)) memo.delete(k); }); };
	if (C.boot?.summary) memo.set('summary', C.boot.summary);

	// Carga diferida de los demás archivos JS del panel.
	const scripts = {};
	const loadScript = (name) => scripts[name] || (scripts[name] = new Promise((ok, fail) => {
		const el = document.createElement('script');
		el.src = C.js[name];
		el.onload = ok;
		el.onerror = () => { delete scripts[name]; fail(new Error('No se pudo cargar esta sección. Revisa tu conexión.')); };
		document.head.appendChild(el);
	}));

	let toastTimer = 0;
	const toast = (msg, type = 'ok') => {
		const t = $('#bk-g-toast');
		t.textContent = msg;
		t.className = `bk-g-toast is-${type} is-on`;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => t.classList.remove('is-on'), 3500);
	};

	// Evita dobles clics mientras se guarda.
	const busy = async (btn, fn) => {
		if (btn?.disabled) return;
		if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
		try { return await fn(); } catch (e) { toast(e.message, 'err'); } finally {
			if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
		}
	};

	/* --- Estructura ------------------------------------------------------------- */
	// Usuarios de solo SEO y ADS (p. ej. Gestor_ADS): ese módulo y la ayuda.
	const tabs = C.scope === 'seo' ? [
		['seo', 'SEO y ADS', 'chart'],
		['ayuda', 'Ayuda', 'help'],
	] : [
		['resumen', 'Resumen', 'home'],
		['pedidos', 'Pedidos', 'orders'],
		['productos', 'Productos', 'prod'],
		['descuentos', 'Descuentos', 'percent'],
		['carritos', 'Carritos', 'cart'],
		['correos', 'Correos', 'mail'],
		['contenido', 'Contenido', 'layout'],
		['seo', 'SEO y ADS', 'chart'],
		['ayuda', 'Ayuda', 'help'],
	];
	// Ajustes ya no va en el menú (los campos reales quedaron por defecto): sigue en #ajustes para administradores.
	const hidden = C.isAdmin && C.scope !== 'seo' ? ['ajustes'] : [];
	const home = tabs[0][0];
	app.innerHTML = `
		<header class="bk-g-top">
			<a class="bk-g-brand" href="#${home}"><strong>Buckler</strong> <span>Gestión de tienda</span></a>
			<nav class="bk-g-tabs" aria-label="Secciones">${tabs.map(([k, l, i]) => `<a href="#${k}" data-tab="${k}">${C.icons[i] || ''}<span>${l}</span></a>`).join('')}</nav>
			<div class="bk-g-user"><a href="${esc(C.site)}" target="_blank" rel="noopener">Ver tienda</a><span>${esc(C.user)}</span><a href="${esc(C.logout)}">Salir</a></div>
		</header>
		<main id="bk-g-main" class="bk-g-main" tabindex="-1"></main>
		<div id="bk-g-drawer" class="bk-g-drawer" hidden>
			<div class="bk-g-drawer__scrim" data-close></div>
			<section class="bk-g-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="bk-g-dtitle"></section>
		</div>
		<div id="bk-g-preview" class="bk-g-preview" hidden>
			<div class="bk-g-preview__box" role="dialog" aria-modal="true" aria-labelledby="bk-g-ptitle"></div>
		</div>
		<div id="bk-g-toast" class="bk-g-toast" role="status" aria-live="polite"></div>
		${C.isAdmin ? '<footer class="bk-g-foot"><a href="#ajustes">Ajustes avanzados de campos</a></footer>' : ''}`;
	const main = $('#bk-g-main');

	/* --- Router por hash ---------------------------------------------------------- */
	// Cada vista/consulta lleva un número: si llega una respuesta vieja (otra pestaña u otra búsqueda), se descarta.
	let viewSeq = 0;
	let oSeq = 0;
	let pSeq = 0;
	const route = () => {
		viewSeq++;
		const [path, query = ''] = (location.hash.replace('#', '') || home).split('?');
		const [tab, ...sub] = path.split('/');
		const valid = tabs.some(([k]) => k === tab) || hidden.includes(tab) ? tab : home;
		$$('.bk-g-tabs a').forEach((a) => a.toggleAttribute('aria-current', a.dataset.tab === valid));
		// Subrutas de productos (#productos/nuevo, #productos/editar/ID): gestion-product.js (se carga al usarlo).
		if (valid === 'productos' && sub.length) {
			const seq = viewSeq;
			if (!window.bkGProduct) main.innerHTML = '<div class="bk-g-loading">Cargando…</div>';
			loadScript('product').then(() => { if (seq === viewSeq) window.bkGProduct.view(sub, new URLSearchParams(query)); }).catch((e) => { main.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; });
			return;
		}
		// Descuentos, Contenido y Ayuda: gestion-more.js.
		if (['descuentos', 'contenido', 'ayuda'].includes(valid)) {
			const seq = viewSeq;
			if (!window.bkGMore) main.innerHTML = '<div class="bk-g-loading">Cargando…</div>';
			loadScript('more').then(() => { if (seq === viewSeq) window.bkGMore.view(valid, sub, new URLSearchParams(query)); }).catch((e) => { main.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; });
			return;
		}
		// Carritos, Correos y SEO y ADS: gestion-extra.js.
		if (['carritos', 'correos', 'seo'].includes(valid)) {
			const seq = viewSeq;
			if (!window.bkGExtra) main.innerHTML = '<div class="bk-g-loading">Cargando…</div>';
			loadScript('extra').then(() => { if (seq === viewSeq) window.bkGExtra.view(valid, sub, new URLSearchParams(query)); }).catch((e) => { main.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; });
			return;
		}
		({ resumen: viewSummary, pedidos: viewOrders, productos: viewProducts, ajustes: viewSettings })[valid]();
	};
	window.addEventListener('hashchange', route);

	/* --- Resumen ------------------------------------------------------------------- */
	async function viewSummary() {
		const seq = viewSeq;
		const paint = (d) => {
			if (seq !== viewSeq) return;
			main.innerHTML = `<h1 class="bk-g-h1">Resumen</h1>
				<div class="bk-g-kpis">
					<div class="bk-g-kpi"><span>Ventas de hoy</span><b>${esc(d.today.total)}</b><small>${d.today.count} pedidos pagados</small></div>
					<div class="bk-g-kpi"><span>Ventas del mes</span><b>${esc(d.month.total)}</b><small>${d.month.count} pedidos pagados</small></div>
					<a class="bk-g-kpi is-link" href="#pedidos?status=processing"><span>Por despachar</span><b>${d.pending.processing}</b><small>En proceso</small></a>
					<a class="bk-g-kpi is-link" href="#pedidos?status=on-hold"><span>En espera</span><b>${d.pending['on-hold']}</b><small>Revisar pago</small></a>
					<a class="bk-g-kpi is-link" href="#pedidos?status=pending"><span>Pendientes de pago</span><b>${d.pending.pending}</b><small>Sin pagar aún</small></a>
				</div>
				<section class="bk-g-card"><h2>Tallas por agotarse (1–2 unidades)</h2>
				${d.low.length ? `<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Producto</th><th>REF</th><th>Talla</th><th>Quedan</th></tr></thead><tbody>
					${d.low.map((l) => `<tr><td>${esc(l.name)}</td><td>${esc(l.sku)}</td><td>${esc(l.size)}</td><td><b class="bk-g-low">${l.stock}</b></td></tr>`).join('')}
				</tbody></table></div>` : '<p class="bk-g-empty">No hay tallas con stock bajo.</p>'}</section>`;
		};
		const old = getCached('summary');
		if (old) paint(old); else main.innerHTML = '<h1 class="bk-g-h1">Resumen</h1><div class="bk-g-loading">Cargando…</div>';
		try {
			const d = await fetchFresh('summary');
			if (JSON.stringify(d) !== JSON.stringify(old)) paint(d);
		} catch (e) { if (!old) main.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
		// Mientras se mira el resumen, se deja lista la primera página de pedidos.
		if (!getCached(`orders?${qs(oState)}`)) setTimeout(() => fetchFresh(`orders?${qs(oState)}`).catch(() => {}), 300);
	}

	/* --- Pedidos ------------------------------------------------------------------- */
	const hashParams = () => new URLSearchParams(location.hash.split('?')[1] || '');
	const oState = { q: '', status: 'all', paid_ok: '', printed: '', dispatched: '', from: '', to: '', page: 1 };

	async function viewOrders() {
		const hp = hashParams();
		if (hp.get('status')) oState.status = hp.get('status');
		main.innerHTML = `<div class="bk-g-h1row"><h1 class="bk-g-h1">Pedidos</h1>
				<div class="bk-g-refresh"><small id="bk-g-oupd" aria-live="polite"></small><button type="button" class="bk-g-btn" data-orders-refresh>${C.icons.refresh || ''} Buscar pedidos nuevos</button></div></div>
			<form class="bk-g-filters" id="bk-g-of" role="search">
				<label class="bk-g-search"><span class="bk-g-sr">Buscar pedidos</span>${C.icons.search}
					<input type="search" name="q" value="${esc(oState.q)}" placeholder="Número, consecutivo, cédula, nombre, correo o celular" autocomplete="off"></label>
				<label><span>Estado</span><select name="status"><option value="all">Todos</option>${Object.entries(C.statuses).map(([k, v]) => `<option value="${k}" ${oState.status === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
				<label><span>Pago</span><select name="paid_ok"><option value="">Todos</option><option value="no" ${oState.paid_ok === 'no' ? 'selected' : ''}>Sin confirmar</option><option value="yes" ${oState.paid_ok === 'yes' ? 'selected' : ''}>Confirmado</option></select></label>
				<label><span>Impresión</span><select name="printed"><option value="">Todos</option><option value="no" ${oState.printed === 'no' ? 'selected' : ''}>Sin imprimir</option><option value="yes" ${oState.printed === 'yes' ? 'selected' : ''}>Impresos</option></select></label>
				${C.hasDispatch ? `<label><span>Despacho</span><select name="dispatched"><option value="">Todos</option><option value="no" ${oState.dispatched === 'no' ? 'selected' : ''}>Sin despachar</option><option value="yes" ${oState.dispatched === 'yes' ? 'selected' : ''}>Despachados</option></select></label>` : ''}
				<label><span>Desde</span><input type="date" name="from" value="${esc(oState.from)}"></label>
				<label><span>Hasta</span><input type="date" name="to" value="${esc(oState.to)}"></label>
				<button type="submit" class="bk-g-btn">Buscar</button>
			</form>
			<div id="bk-g-olist" aria-live="polite"></div>`;
		const form = $('#bk-g-of');
		let t = 0;
		form.addEventListener('submit', (e) => { e.preventDefault(); readForm(form); loadOrders(); });
		form.addEventListener('change', (e) => { if (e.target.name !== 'q') { readForm(form); loadOrders(); } });
		form.q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { readForm(form); loadOrders(); }, 450); });
		loadOrders();
	}
	const readForm = (form) => {
		Object.assign(oState, Object.fromEntries(new FormData(form).entries()), { page: 1 });
	};

	async function loadOrders() {
		const box = $('#bk-g-olist');
		if (!box) return;
		const seq = ++oSeq;
		const path = `orders?${qs(oState)}`;
		const old = getCached(path);
		if (old) paintOrders(box, old); else box.innerHTML = '<div class="bk-g-loading">Cargando pedidos…</div>';
		try {
			const d = await fetchFresh(path);
			if (seq !== oSeq || !box.isConnected) return;
			if (JSON.stringify(d) !== JSON.stringify(old)) paintOrders(box, d);
			markUpdated();
			return d;
		} catch (e) { if (!old) box.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
		return null;
	}

	/* --- Pedidos nuevos: botón y revisión automática cada 10 minutos ------------------------------- */
	const REFRESH_MS = 10 * 60 * 1000;
	let lastRefresh = Date.now();
	const seenIds = new Set();
	const hhmm = () => new Date().toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' });
	const markUpdated = () => { const el = $('#bk-g-oupd'); if (el) el.textContent = `Actualizado ${hhmm()}`; };
	const rememberIds = (d) => (d?.orders || []).forEach((o) => seenIds.add(o.id));

	async function refreshOrders(manual) {
		lastRefresh = Date.now();
		forget('orders?');
		forget('summary');
		if (!$('#bk-g-olist')) {
			if (/^#?(resumen)?$/.test(location.hash.split('?')[0])) viewSummary(); // En otras secciones solo se vacía la caché.
			return;
		}
		const before = new Set(seenIds);
		const d = await loadOrders();
		if (!d) return;
		const fresh = (d.orders || []).filter((o) => before.size && !before.has(o.id));
		rememberIds(d);
		fresh.forEach((o) => $(`[data-row="${o.id}"]`)?.classList.add('is-new'));
		if (fresh.length) toast(fresh.length === 1 ? '1 pedido nuevo' : `${fresh.length} pedidos nuevos`);
		else if (manual) toast('No hay pedidos nuevos');
	}
	setInterval(() => { if (document.visibilityState === 'visible') refreshOrders(false).catch(() => {}); }, REFRESH_MS);
	// Si la pestaña estuvo oculta más de 10 minutos, revisa apenas vuelve.
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible' && Date.now() - lastRefresh >= REFRESH_MS) refreshOrders(false).catch(() => {});
	});

	const check = (kind, o, label, on) => `<label class="bk-g-check"><input type="checkbox" data-flag="${kind}" data-id="${o.id}" ${on ? 'checked' : ''}><span class="bk-g-mlabel">${label}</span><span class="bk-g-sr">Pedido ${esc(o.number)}: ${label.toLowerCase()}</span></label>`;

	// Sin columna Consecutivo (repetía el número): se sigue buscando por consecutivo y sale en el detalle e impresión.
	const paintOrders = (box, d) => {
		if (!seenIds.size) rememberIds(d);
		const showDisp = d.orders.some((o) => o.dispatched !== null);
		box.innerHTML = d.orders.length ? `
			<p class="bk-g-count">${d.total} pedido${d.total === 1 ? '' : 's'}</p>
			<div class="bk-g-table-wrap"><table class="bk-g-table bk-g-orders">
				<thead><tr><th>Pedido</th><th>Fecha</th><th>Clienta</th><th>Total</th><th>Estado</th><th>Pago confirmado</th><th>Impreso</th>${showDisp ? '<th>Despachado</th>' : ''}<th><span class="bk-g-sr">Imprimir</span></th></tr></thead>
				<tbody>${d.orders.map((o) => `<tr data-row="${o.id}">
					<td class="c-num"><button type="button" class="bk-g-link" data-order="${o.id}">#${esc(o.number)}</button></td>
					<td class="c-date">${esc(o.date)}</td>
					<td class="c-cust">${esc(o.customer)}<small>${esc(o.city)} · ${o.items} prenda${o.items === 1 ? '' : 's'}${o.nfiles ? ` · <span class="bk-g-nfiles" title="Archivos del pedido">📎 ${o.nfiles}</span>` : ''}</small></td>
					<td class="c-total"><b>${esc(o.total)}</b><small>${esc(o.payment)}</small></td>
					<td class="c-status"><span class="bk-g-pill is-${esc(o.status)}">${esc(statusLabel(o.status))}</span>${o.tracking ? `<small class="bk-g-trk">${C.icons.truck} ${esc(C.carriers[o.tracking.carrier] || '')}</small>` : ''}</td>
					<td class="c-print c-paid">${check('paid_ok', o, 'Pago confirmado', o.paid_ok)}</td>
					<td class="c-print">${check('printed', o, 'Impreso', o.printed)}</td>
					${showDisp ? `<td class="c-print c-disp">${check('dispatched', o, 'Despachado', o.dispatched)}</td>` : ''}
					<td class="c-act"><button type="button" class="bk-g-btn is-ghost is-small" data-print="${o.id}">${C.icons.print}<span>Imprimir</span><span class="bk-g-sr"> pedido ${esc(o.number)}</span></button></td>
				</tr>`).join('')}</tbody>
			</table></div>
			${pager(d.pages, oState.page, 'o')}` : '<p class="bk-g-empty">No hay pedidos con esos filtros.</p>';
	};

	const pager = (pages, page, kind) => pages > 1 ? `<nav class="bk-g-pager" aria-label="Páginas">
		<button type="button" class="bk-g-btn is-ghost" data-page="${page - 1}" data-kind="${kind}" ${page <= 1 ? 'disabled' : ''}>Anterior</button>
		<span>Página ${page} de ${pages}</span>
		<button type="button" class="bk-g-btn is-ghost" data-page="${page + 1}" data-kind="${kind}" ${page >= pages ? 'disabled' : ''}>Siguiente</button></nav>` : '';

	/* --- Detalle de pedido (panel lateral) --------------------------------------------- */
	const drawer = $('#bk-g-drawer');
	const panel = $('.bk-g-drawer__panel');
	let lastFocus = null;
	const openDrawer = (html) => {
		lastFocus = document.activeElement;
		panel.innerHTML = html;
		drawer.hidden = false;
		requestAnimationFrame(() => drawer.classList.add('is-open'));
		$('[data-close]', panel)?.focus();
	};
	const closeDrawer = () => {
		drawer.classList.remove('is-open');
		setTimeout(() => { drawer.hidden = true; }, 200);
		lastFocus?.focus?.();
	};
	drawer.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeDrawer(); });
	document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !drawer.hidden) closeDrawer(); });

	// Archivos del pedido (repeater ACF "Archivos Pedidos"): siempre visible, con subir y quitar.
	const filesHtml = (o) => !o.files ? '' : `<section class="bk-g-fsec"><h3>${esc(o.files.label)}</h3>
		${o.files.rows.length ? `<ul class="bk-g-files">${o.files.rows.map((r) => `<li class="bk-g-file">
			${r.files.map((a) => `<a href="${esc(a.url)}" target="_blank" rel="noopener" class="bk-g-file__link">${a.thumb ? `<img src="${esc(a.thumb)}" alt="" width="96" height="96">` : `<span class="bk-g-file__doc">${a.pdf ? 'PDF' : 'Archivo'}</span>`}<small>${esc(a.name)}</small></a>`).join('')}
			${r.texts.map((x) => `<p class="bk-g-file__txt"><b>${esc(x.label)}:</b> ${esc(x.value)}</p>`).join('')}
			<button type="button" class="bk-g-icon is-danger" data-file-del="${r.index}" data-id="${o.id}" aria-label="Quitar el archivo ${r.n}">${C.icons.trash}</button></li>`).join('')}</ul>` : '<p class="bk-g-empty">Este pedido no tiene archivos.</p>'}
		<label class="bk-g-btn is-ghost is-small bk-g-upload">${C.icons.plus} Subir archivo<input type="file" class="bk-g-sr" data-file-up="${o.id}" accept="application/pdf,image/jpeg,image/png,image/webp"></label>
		<small class="bk-g-hint">PDF, JPG, PNG o WebP · máx. ${esc(o.files.max)}. Se guarda en el mismo campo de ACF (se ve igual en WooCommerce).</small></section>`;

	// Historial: cuándo se creó, pagó, cambió de estado, se imprimió, despachó, envió la guía…
	const historyHtml = (o) => o.history?.length ? `<section><h3>Historial del pedido</h3><ol class="bk-g-hist">${[...o.history].reverse().map((h) => `<li class="is-${esc(h.type)}"><b>${esc(h.label)}</b><small>${esc(h.date)}${h.by ? ` · ${esc(h.by)}` : ''}</small></li>`).join('')}</ol></section>` : '';

	const orderHtml = (o) => `
		<header class="bk-g-dhead"><div><h2 id="bk-g-dtitle">Pedido #${esc(o.number)}</h2><small>${esc(o.date)}${o.consecutivo ? ` · Consecutivo ${esc(o.consecutivo)}` : ''}</small></div>
			<button type="button" class="bk-g-icon" data-close aria-label="Cerrar">${C.icons.x}</button></header>
		<div class="bk-g-dbody">
			<div class="bk-g-row2">
				<label><span>Estado</span><select id="bk-g-ostatus">${Object.entries(C.statuses).map(([k, v]) => `<option value="${k}" ${o.status === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
				<label class="bk-g-switch"><input type="checkbox" id="bk-g-opaid" ${o.paid_ok ? 'checked' : ''}><span>Pago confirmado</span></label>
				<label class="bk-g-switch"><input type="checkbox" id="bk-g-oprinted" ${o.printed ? 'checked' : ''}><span>Impreso</span></label>
				${o.dispatched !== null && o.dispatched !== undefined ? `<label class="bk-g-switch"><input type="checkbox" id="bk-g-odispatched" ${o.dispatched ? 'checked' : ''}><span>Despachado</span></label>` : ''}
			</div>
			<div class="bk-g-dactions"><button type="button" class="bk-g-btn" id="bk-g-osave" data-id="${o.id}">Guardar estado</button>
				<button type="button" class="bk-g-btn is-ghost" data-print="${o.id}">${C.icons.print} Imprimir pedido</button></div>
			${shipHtml(o)}
			<section><h3>Clienta</h3><p><b>${esc(o.customer)}</b>${o.fields?.cedula || o.cedula ? `<br>Cédula: ${esc(o.cedula || '—')}` : ''}${o.fields?.consecutivo ? `<br>Consecutivo: ${esc(o.consecutivo || '—')}` : ''}${o.origin ? `<br><small>Origen de la venta: ${esc(o.origin)}</small>` : ''}<br>
				${o.phone ? `<a href="tel:${esc(o.phone)}">${esc(o.phone)}</a> · <a href="https://wa.me/57${esc(String(o.phone).replace(/\D/g, '').replace(/^57/, ''))}" target="_blank" rel="noopener">WhatsApp</a><br>` : ''}
				<a href="mailto:${esc(o.email)}">${esc(o.email)}</a><br>${esc(o.address)}</p>
				${o.note ? `<p class="bk-g-note"><b>Nota de la clienta:</b> ${esc(o.note)}</p>` : ''}</section>
			<section><h3>Productos</h3><ul class="bk-g-items">${o.items.map((i) => `<li>${i.image ? `<img src="${esc(i.image)}" alt="" width="48" height="48">` : '<span></span>'}
				<div><b>${esc(i.name)}</b><small>${esc(i.meta)}${i.sku ? ` · REF ${esc(i.sku)}` : ''}</small></div><span>×${i.qty}</span><b>${esc(i.total)}</b></li>`).join('')}</ul>
				<dl class="bk-g-totals"><div><dt>Subtotal</dt><dd>${esc(o.subtotal)}</dd></div>${o.discount ? `<div><dt>Descuento</dt><dd>${esc(o.discount)}</dd></div>` : ''}<div><dt>Envío</dt><dd>${esc(o.shipping)}</dd></div><div class="is-total"><dt>Total</dt><dd>${esc(o.total)}</dd></div></dl>
				<p><small>Pago: ${esc(o.payment)}</small></p></section>
			${filesHtml(o)}
			${historyHtml(o)}
			${o.extra?.length ? `<details class="bk-g-extra"><summary>Otros datos del pedido (${o.extra.length})</summary><dl>${o.extra.map((x) => `<div><dt title="${esc(x.key)}">${esc(x.label)}</dt><dd>${esc(x.value)}</dd></div>`).join('')}</dl></details>` : ''}
			<section><h3>Notas</h3>
				<form id="bk-g-onote" data-id="${o.id}"><label for="bk-g-notetext" class="bk-g-sr">Nueva nota</label>
					<textarea id="bk-g-notetext" rows="2" placeholder="Escribe una nota del pedido"></textarea>
					<label class="bk-g-switch"><input type="checkbox" id="bk-g-notecust"><span>Enviar a la clienta</span></label>
					<button class="bk-g-btn is-ghost" type="submit">Añadir nota</button></form>
				<ul class="bk-g-notes">${o.notes.map((n) => `<li class="${n.customer ? 'is-customer' : ''}"><p>${esc(n.content)}</p><small>${esc(n.date)}${n.by ? ` · ${esc(n.by)}` : ''}${n.customer ? ' · enviada a la clienta' : ''}</small></li>`).join('')}</ul></section>
			<a class="bk-g-link" href="${esc(o.edit_url)}" target="_blank" rel="noopener">Abrir en WooCommerce ${C.icons.ext}</a>
		</div>`;

	/* --- Guía de envío ------------------------------------------------------------ */
	const shipHtml = (o) => `<section class="bk-g-ship"><h3>${C.icons.truck} Enviar guía de envío</h3>
		${o.tracking ? `<p class="bk-g-shipped">Enviado con <b>${esc(C.carriers[o.tracking.carrier] || o.tracking.carrier)}</b> · guía <b>${esc(o.tracking.number)}</b><small>${esc(o.tracking.date)}${o.tracking.sent_by ? ` · ${esc(o.tracking.sent_by)}` : ''}</small></p>` : ''}
		<form id="bk-g-shipform" data-id="${o.id}" novalidate>
			<div class="bk-g-row2">
				<label><span>Transportadora</span><select name="carrier" required><option value="">Elige…</option>${Object.entries(C.carriers).map(([k, v]) => `<option value="${k}" ${o.tracking?.carrier === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
				<label><span>Número de guía</span><input name="number" required inputmode="text" autocomplete="off" value="${esc(o.tracking?.number || '')}" placeholder="Ej.: 2401234567"></label>
			</div>
			${o.status !== 'completed' ? '<label class="bk-g-switch"><input type="checkbox" name="complete" checked><span>Marcar como Completado (sin el correo estándar de WooCommerce)</span></label>' : ''}
			<p class="bk-g-formerr" role="alert" hidden></p>
			<button type="submit" class="bk-g-btn">${C.icons.mail} ${o.tracking ? 'Reenviar correo' : 'Ver correo antes de enviar'}</button>
		</form></section>`;

	const pv = $('#bk-g-preview');
	const pvBox = $('.bk-g-preview__box');
	let pvReturn = null;
	let pendingShip = null; // { id, body } del envío que se está confirmando.
	const openPreview = (html) => {
		pvReturn = document.activeElement;
		pvBox.innerHTML = html;
		pv.hidden = false;
		$('[data-pv-focus]', pvBox)?.focus();
	};
	const closePreview = () => { pendingShip = null; pv.hidden = true; pvBox.innerHTML = ''; pvReturn?.focus?.(); };
	pv.addEventListener('click', (e) => { if (e.target === pv || e.target.closest('[data-pv-close]')) closePreview(); });
	document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pv.hidden) { e.stopImmediatePropagation(); closePreview(); } }, true);

	const previewHtml = (d, actions) => `
		<header class="bk-g-pvhead"><h2 id="bk-g-ptitle">Vista previa del correo</h2><button type="button" class="bk-g-icon" data-pv-close aria-label="Cerrar">${C.icons.x}</button></header>
		<dl class="bk-g-pvmeta">${d.to ? `<div><dt>Para</dt><dd>${esc(d.to)}</dd></div>` : ''}<div><dt>Asunto</dt><dd>${esc(d.subject)}</dd></div></dl>
		<iframe class="bk-g-pvframe" title="Vista previa del correo" sandbox=""></iframe>
		<footer class="bk-g-pvfoot">${actions}</footer>`;

	const fillFrame = (html) => { const f = $('.bk-g-pvframe', pvBox); if (f) f.srcdoc = html; };

	let openId = 0;
	const showOrder = async (id) => {
		openId = String(id);
		const old = getCached(`orders/${id}`);
		openDrawer(old ? orderHtml(old) : '<div class="bk-g-loading">Cargando pedido…</div>');
		try {
			const d = await fetchFresh(`orders/${id}`);
			if (openId !== String(id) || drawer.hidden) return;
			if (JSON.stringify(d) !== JSON.stringify(old)) { panel.innerHTML = orderHtml(d); $('[data-close]', panel)?.focus(); }
		} catch (e) { if (!old) panel.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
	};
	// El detalle se pide al acercar el cursor o el foco: al hacer clic ya está listo.
	const prefetch = (e) => {
		const b = e.target.closest?.('[data-order]');
		if (b && !getCached(`orders/${b.dataset.order}`)) fetchFresh(`orders/${b.dataset.order}`).catch(() => {});
	};
	document.addEventListener('pointerover', prefetch, { passive: true });
	document.addEventListener('focusin', prefetch);
	const setOrder = (o) => {
		memo.set(`orders/${o.id}`, o);
		forget('orders?');
		forget('summary');
		if (!drawer.hidden && openId === String(o.id)) panel.innerHTML = orderHtml(o);
	};

	// Imprimir: hoja con todo el detalle. En computador se imprime sin salir del panel; en celular abre una pestaña.
	const printOrder = (id) => {
		const url = `${C.printUrl}${id}&auto=1`;
		if (matchMedia('(pointer: coarse)').matches) { window.open(url, '_blank', 'noopener'); return; }
		document.getElementById('bk-g-printframe')?.remove();
		const f = document.createElement('iframe');
		f.id = 'bk-g-printframe';
		f.title = 'Impresión del pedido';
		f.className = 'bk-g-printframe';
		f.src = `${C.printUrl}${id}`;
		f.onload = () => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (err) { window.open(url, '_blank', 'noopener'); } };
		document.body.appendChild(f);
	};

	/* --- Productos ---------------------------------------------------------------- */
	const pState = { q: '', line: '', stock: '', page: 1 };
	function viewProducts() {
		main.innerHTML = `<div class="bk-g-h1row"><h1 class="bk-g-h1">Productos</h1><a class="bk-g-btn" href="#productos/nuevo">${C.icons.plus} Nuevo producto</a></div>
			<form class="bk-g-filters" id="bk-g-pf" role="search">
				<label class="bk-g-search"><span class="bk-g-sr">Buscar productos</span>${C.icons.search}<input type="search" name="q" value="${esc(pState.q)}" placeholder="Nombre o REF" autocomplete="off"></label>
				<label><span>Línea</span><select name="line"><option value="">Todas</option>${Object.entries(C.lines).map(([k, v]) => `<option value="${k}" ${pState.line === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
				<label><span>Stock</span><select name="stock"><option value="">Todos</option><option value="in" ${pState.stock === 'in' ? 'selected' : ''}>Con stock</option><option value="out" ${pState.stock === 'out' ? 'selected' : ''}>Agotados</option></select></label>
				<button type="submit" class="bk-g-btn">Buscar</button>
			</form><div id="bk-g-plist" aria-live="polite"></div>`;
		const form = $('#bk-g-pf');
		let t = 0;
		const read = () => Object.assign(pState, Object.fromEntries(new FormData(form).entries()), { page: 1 });
		form.addEventListener('submit', (e) => { e.preventDefault(); read(); loadProducts(); });
		form.addEventListener('change', (e) => { if (e.target.name !== 'q') { read(); loadProducts(); } });
		form.q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { read(); loadProducts(); }, 450); });
		loadProducts();
	}

	async function loadProducts() {
		const box = $('#bk-g-plist');
		if (!box) return;
		const seq = ++pSeq;
		const path = `products?${qs(pState)}`;
		const old = getCached(path);
		if (old) paintProducts(box, old); else box.innerHTML = '<div class="bk-g-loading">Cargando productos…</div>';
		try {
			const d = await fetchFresh(path);
			if (seq !== pSeq || !box.isConnected) return;
			if (JSON.stringify(d) !== JSON.stringify(old)) paintProducts(box, d);
		} catch (e) { if (!old) box.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
	}

	const paintProducts = (box, d) => {
		box.innerHTML = d.products.length ? `<p class="bk-g-count">${d.total} producto${d.total === 1 ? '' : 's'}</p>
				<ul class="bk-g-plist">${d.products.map((p) => `<li class="bk-g-prod" data-pid="${p.id}">
					<div class="bk-g-prow">
						${p.image ? `<img src="${esc(p.image)}" alt="" width="56" height="56">` : '<span class="bk-g-noimg"></span>'}
						<div class="bk-g-pinfo"><b>${esc(p.name)}</b><small>REF ${esc(p.sku || '—')} · ${esc(p.line || 'Sin línea')}${p.status !== 'publish' ? ` · ${esc(p.status)}` : ''}</small></div>
						<span class="bk-g-pprice">${esc(p.price)}</span>
						<span class="bk-g-pill ${p.stock === 'instock' ? 'is-completed' : 'is-cancelled'}">${p.stock === 'instock' ? 'Con stock' : 'Agotado'}</span>
						<button type="button" class="bk-g-star ${p.featured ? 'is-on' : ''}" data-feat="${p.id}" aria-pressed="${p.featured}" title="Destacado = etiqueta Nuevo">${C.icons.prod}<span class="bk-g-sr">Nuevo (destacado): ${esc(p.name)}</span></button>
						<button type="button" class="bk-g-btn is-ghost" data-sizes="${p.id}" aria-expanded="false">Tallas y precios</button>
						<a class="bk-g-btn is-ghost" href="#productos/editar/${p.id}">${C.icons.edit} Editar<span class="bk-g-sr">: ${esc(p.name)}</span></a>
					</div><div class="bk-g-vars" hidden></div></li>`).join('')}</ul>${pager(d.pages, pState.page, 'p')}` : '<p class="bk-g-empty">No hay productos con esos filtros.</p>';
	};

	const varsHtml = (p) => p.variations.length ? `<div class="bk-g-table-wrap"><table class="bk-g-table bk-g-vtable">
		<thead><tr><th>Talla</th><th>Precio normal</th><th>Precio rebajado</th><th>Stock</th><th></th></tr></thead>
		<tbody>${p.variations.map((v) => `<tr data-vid="${v.id}">
			<th scope="row">${esc(v.size)}</th>
			<td><label class="bk-g-sr" for="r${v.id}">Precio normal talla ${esc(v.size)}</label><input id="r${v.id}" name="regular" inputmode="numeric" value="${esc(v.regular)}"></td>
			<td><label class="bk-g-sr" for="s${v.id}">Precio rebajado talla ${esc(v.size)}</label><input id="s${v.id}" name="sale" inputmode="numeric" value="${esc(v.sale)}" placeholder="—"></td>
			<td><label class="bk-g-sr" for="k${v.id}">Stock talla ${esc(v.size)}</label><input id="k${v.id}" name="stock" inputmode="numeric" value="${v.manage ? esc(v.stock) : ''}" placeholder="${v.status === 'instock' ? 'Sin control' : '0'}"></td>
			<td><button type="button" class="bk-g-btn is-small" data-vsave="${v.id}">Guardar</button></td></tr>`).join('')}</tbody></table></div>
		<p class="bk-g-hint">Deja el precio rebajado vacío para quitar la oferta. <a href="${esc(p.edit_url)}" target="_blank" rel="noopener">Editar fotos y descripción en WooCommerce</a></p>` : '<p class="bk-g-empty">Este producto no tiene tallas.</p>';

	/* --- Ajustes (solo administradores) --------------------------------------------------- */
	async function viewSettings() {
		main.innerHTML = '<h1 class="bk-g-h1">Ajustes</h1><div class="bk-g-loading">Cargando…</div>';
		const seq = viewSeq;
		try {
			const d = await api('settings');
			if (seq !== viewSeq) return;
			const f = d.fields;
			main.innerHTML = `<h1 class="bk-g-h1">Ajustes</h1>
				<section class="bk-g-card"><h2>Campos de los pedidos</h2>
				<p class="bk-g-hint">Indica dónde se guardan hoy estos datos para que el dashboard los muestre y busque, sin cambiar nada de los pedidos existentes.</p>
				<form id="bk-g-sf" class="bk-g-sform">
					<label><span>Consecutivo</span><input name="consecutivo" value="${esc(f.consecutivo)}" placeholder="Ej.: _wcdn_invoice_number"></label>
					<label><span>Check "Impreso"</span><input name="impreso" value="${esc(f.impreso)}"></label>
					<label><span>Check "Despachado"</span><input name="despachado" value="${esc(f.despachado)}"></label>
					<label><span>Check "Pago confirmado"</span><input name="pago" value="${esc(f.pago)}"></label>
					<label><span>Cédula (una o varias, separadas por coma)</span><input name="cedula" value="${esc(f.cedula.join(', '))}"></label>
					<label><span>Archivos del pedido (repeater ACF)</span><input name="adjuntos" value="${esc(f.adjuntos)}"></label>
					<label><span>Usuarios que solo ven SEO y ADS (nombres de usuario, separados por coma)</span><input name="seo_users" value="${esc((d.seo_users || []).join(', '))}"></label>
					<button class="bk-g-btn" type="submit">Guardar campos</button>
				</form></section>
				<section class="bk-g-card"><h2>Campos encontrados en los últimos 40 pedidos</h2>
				<p class="bk-g-hint">Usa esta lista para identificar el consecutivo, la cédula, el impreso y el despachado. Toca un botón para asignarlo.</p>
				<div class="bk-g-table-wrap"><table class="bk-g-table"><thead><tr><th>Campo</th><th>Ejemplo</th><th>Pedidos</th><th>Usar como</th></tr></thead><tbody>
				${d.meta.map((m) => `<tr><td><code>${esc(m.key)}</code></td><td>${esc(m.example)}</td><td>${m.orders}</td><td class="bk-g-use">
					<button type="button" class="bk-g-btn is-small is-ghost" data-use="consecutivo" data-key="${esc(m.key)}">Consecutivo</button>
					<button type="button" class="bk-g-btn is-small is-ghost" data-use="cedula" data-key="${esc(m.key)}">Cédula</button>
					<button type="button" class="bk-g-btn is-small is-ghost" data-use="impreso" data-key="${esc(m.key)}">Impreso</button>
					<button type="button" class="bk-g-btn is-small is-ghost" data-use="despachado" data-key="${esc(m.key)}">Despachado</button></td></tr>`).join('') || '<tr><td colspan="4">Aún no hay pedidos.</td></tr>'}
				</tbody></table></div></section>
				<p class="bk-g-hint">Las vistas previas y pruebas de correos están en <a href="#correos">Correos</a>.</p>`;
		} catch (e) { main.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
	}

	/* --- Eventos delegados -------------------------------------------------------------- */
	document.addEventListener('click', async (e) => {
		const ref = e.target.closest?.('[data-orders-refresh]');
		if (ref) { await busy(ref, () => refreshOrders(true)); return; }
		const t = e.target;
		const ord = t.closest('[data-order]');
		if (ord) { showOrder(ord.dataset.order); return; }

		const pg = t.closest('[data-page]');
		if (pg) {
			if (pg.dataset.kind === 'o') { oState.page = Number(pg.dataset.page); loadOrders(); } else if (pg.dataset.kind === 'p') { pState.page = Number(pg.dataset.page); loadProducts(); } else { window.bkGExtra?.page(pg.dataset.kind, Number(pg.dataset.page)); window.bkGMore?.page?.(pg.dataset.kind, Number(pg.dataset.page)); }
			main.focus();
			return;
		}

		const pr = t.closest('[data-print]');
		if (pr) {
			printOrder(pr.dataset.print);
			// Imprimir = marcar como impreso (se puede desmarcar).
			const row = $(`[data-flag="printed"][data-id="${pr.dataset.print}"]`);
			const inDrawer = $('#bk-g-oprinted');
			if ((row && !row.checked) || (!row && inDrawer && !inDrawer.checked)) {
				api(`orders/${pr.dataset.print}`, { method: 'POST', body: { printed: true } }).then((o) => {
					setOrder(o);
					if (row) row.checked = true;
					toast(`Pedido #${o.number} marcado como impreso`);
				}).catch(() => {});
			}
			return;
		}

		const fdel = t.closest('[data-file-del]');
		if (fdel) {
			if (!window.confirm('¿Quitar este archivo del pedido? (El archivo sigue en la Mediateca.)')) return;
			await busy(fdel, async () => {
				setOrder(await api(`orders/${fdel.dataset.id}/files/delete`, { method: 'POST', body: { index: Number(fdel.dataset.fileDel) } }));
				toast('Archivo quitado');
			});
			return;
		}

		const save = t.closest('#bk-g-osave');
		if (save) {
			await busy(save, async () => {
				const o = await api(`orders/${save.dataset.id}`, { method: 'POST', body: { status: $('#bk-g-ostatus').value, paid_ok: $('#bk-g-opaid').checked, printed: $('#bk-g-oprinted').checked, ...($('#bk-g-odispatched') ? { dispatched: $('#bk-g-odispatched').checked } : {}) } });
				setOrder(o);
				toast(`Pedido #${o.number}: ${statusLabel(o.status)}`);
				loadOrders();
			});
			return;
		}

		const sizes = t.closest('[data-sizes]');
		if (sizes) {
			const box = sizes.closest('.bk-g-prod').querySelector('.bk-g-vars');
			const open = sizes.getAttribute('aria-expanded') !== 'true';
			sizes.setAttribute('aria-expanded', String(open));
			box.hidden = !open;
			if (open) {
				box.innerHTML = '<div class="bk-g-loading">Cargando tallas…</div>';
				try { box.innerHTML = varsHtml(await api(`products/${sizes.dataset.sizes}`)); } catch (err) { box.innerHTML = `<p class="bk-g-error">${esc(err.message)}</p>`; }
			}
			return;
		}

		const vs = t.closest('[data-vsave]');
		if (vs) {
			const row = vs.closest('tr');
			await busy(vs, async () => {
				const body = Object.fromEntries($$('input', row).map((i) => [i.name, i.value.replace(/[^\d.,]/g, '').replace(',', '.')]));
				const v = await api(`variations/${vs.dataset.vsave}`, { method: 'POST', body });
				forget('products');
				forget('summary');
				row.querySelector('[name="regular"]').value = v.regular;
				row.querySelector('[name="sale"]').value = v.sale;
				row.classList.add('is-saved');
				setTimeout(() => row.classList.remove('is-saved'), 1500);
				toast(`Talla ${row.querySelector('th').textContent} guardada`);
			});
			return;
		}

		const feat = t.closest('[data-feat]');
		if (feat) {
			await busy(feat, async () => {
				const on = feat.getAttribute('aria-pressed') !== 'true';
				await api(`products/${feat.dataset.feat}`, { method: 'POST', body: { featured: on } });
				forget('products');
				feat.setAttribute('aria-pressed', String(on));
				feat.classList.toggle('is-on', on);
				toast(on ? 'Marcado como Nuevo' : 'Ya no aparece como Nuevo');
			});
			return;
		}

		const confirmShip = t.closest('[data-ship-confirm]');
		if (confirmShip) {
			await busy(confirmShip, async () => {
				if (!pendingShip) return;
				const o = await api(`orders/${pendingShip.id}/shipment`, { method: 'POST', body: { ...pendingShip.body, confirm: true } });
				pendingShip = null;
				closePreview();
				setOrder(o);
				toast(`Correo enviado a ${o.email}`);
				loadOrders();
			});
			return;
		}

		const sample = t.closest('[data-ship-sample]');
		if (sample) {
			await busy(sample, async () => {
				const d = await api('shipment-sample', { method: 'POST', body: { carrier: $('#bk-g-shiptest').carrier.value } });
				openPreview(previewHtml({ subject: `${d.subject} (ejemplo con el pedido #${d.order})` }, '<button type="button" class="bk-g-btn" data-pv-close data-pv-focus>Cerrar</button>'));
				fillFrame(d.html);
			});
			return;
		}

		const use = t.closest('[data-use]');
		if (use) {
			const form = $('#bk-g-sf');
			const input = form[use.dataset.use];
			input.value = use.dataset.use === 'cedula' && input.value ? `${input.value}, ${use.dataset.key}` : use.dataset.key;
			input.focus();
			toast('Revisa y toca "Guardar campos"');
		}
	});

	const flagMsg = {
		paid_ok: ['Pago confirmado', 'Pago sin confirmar'],
		printed: ['Marcado como impreso', 'Marcado como no impreso'],
		dispatched: ['Marcado como despachado', 'Marcado como no despachado'],
	};
	document.addEventListener('change', async (e) => {
		const up = e.target.closest('[data-file-up]');
		if (up && up.files[0]) {
			const label = up.closest('label');
			label.classList.add('is-busy');
			try {
				const fd = new FormData();
				fd.append('file', up.files[0]);
				const res = await fetch(`${C.api}orders/${up.dataset.fileUp}/files`, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce }, body: fd });
				const d = await res.json().catch(() => ({}));
				if (!res.ok) throw new Error(d.message || 'No se pudo subir el archivo.');
				setOrder(d);
				toast('Archivo agregado al pedido');
			} catch (err) { toast(err.message, 'err'); label.classList.remove('is-busy'); }
			return;
		}
		const cb = e.target.closest('[data-flag]');
		if (!cb) return;
		const kind = cb.dataset.flag;
		cb.disabled = true;
		try {
			const o = await api(`orders/${cb.dataset.id}`, { method: 'POST', body: { [kind]: cb.checked } });
			setOrder(o);
			toast(flagMsg[kind][cb.checked ? 0 : 1]);
		} catch (err) { cb.checked = !cb.checked; toast(err.message, 'err'); } finally { cb.disabled = false; }
	});

	document.addEventListener('submit', async (e) => {
		const form = e.target;
		if (form.id === 'bk-g-onote') {
			e.preventDefault();
			const text = $('#bk-g-notetext').value.trim();
			if (!text) return;
			await busy(form.querySelector('button'), async () => {
				const o = await api(`orders/${form.dataset.id}`, { method: 'POST', body: { note: text, customer_note: $('#bk-g-notecust').checked } });
				setOrder(o);
				toast('Nota añadida');
			});
		}
		if (form.id === 'bk-g-shipform') {
			e.preventDefault();
			const err = $('.bk-g-formerr', form);
			const body = { carrier: form.carrier.value, number: form.number.value.trim(), complete: !!form.complete?.checked };
			err.hidden = true;
			if (!body.carrier || body.number.length < 4) {
				err.textContent = !body.carrier ? 'Elige la transportadora.' : 'Escribe el número de guía.';
				err.hidden = false;
				(!body.carrier ? form.carrier : form.number).focus();
				return;
			}
			await busy(form.querySelector('button[type="submit"]'), async () => {
				const d = await api(`orders/${form.dataset.id}/shipment`, { method: 'POST', body });
				openPreview(previewHtml(d, `<button type="button" class="bk-g-btn is-ghost" data-pv-close>Volver a editar</button>
					<button type="button" class="bk-g-btn" data-pv-focus data-ship-confirm>${C.icons.check} Confirmar y enviar a ${esc(d.to)}</button>`));
				fillFrame(d.html);
				pendingShip = { id: form.dataset.id, body };
			});
		}
		if (form.id === 'bk-g-shiptest') {
			e.preventDefault();
			await busy(form.querySelector('button[type="submit"]'), async () => {
				const d = await api('shipment-sample', { method: 'POST', body: { to: form.to.value.trim(), carrier: form.carrier.value } });
				toast(d.sent ? `Correo de prueba enviado a ${d.to}` : 'No se pudo enviar. Revisa la configuración de correo (SMTP).', d.sent ? 'ok' : 'err');
			});
		}
		if (form.id === 'bk-g-sf') {
			e.preventDefault();
			await busy(form.querySelector('button'), async () => {
				await api('settings', { method: 'POST', body: Object.fromEntries(new FormData(form).entries()) });
				toast('Campos guardados');
				viewSettings();
			});
		}
	});

	// API para gestion-product.js (editor de productos).
	window.bkGApp = { C, api, esc, $, $$, toast, busy, main, route, seq: () => viewSeq, openPreview, closePreview, previewHtml, fillFrame, getCached, fetchFresh, forget, showOrder, pager, qs };
	route();
})();
