/**
 * Gestión de tienda: Descuentos (#descuentos), Contenido (#contenido: banners, guía de tallas, políticas y preguntas
 * frecuentes) y Ayuda (#ayuda). Se carga solo al abrir una de esas secciones. Depende de window.bkGApp (gestion.js).
 */
(() => {
	'use strict';

	const A = window.bkGApp;
	if (!A) return;
	const { C, api, esc, $, $$, toast, busy, main } = A;
	const err = (e) => `<p class="bk-g-error">${esc(e.message)}</p>`;
	const pctTxt = (n) => String(n).replace('.', ',');
	const stateLbl = { active: ['Activa', 'is-completed'], scheduled: ['Programada', 'is-processing'], ended: ['Terminada', 'is-cancelled'], paused: ['Pausada', 'is-on-hold'] };
	let dirty = false;

	/* --- Utilidades compartidas -------------------------------------------------------------- */

	// Muestra el error junto al campo que lo causó (o arriba del formulario).
	const showErr = (form, e) => {
		const box = $('.bk-g-formerr', form);
		if (box) { box.textContent = e.message; box.hidden = false; }
		$$('[aria-invalid]', form).forEach((el) => el.removeAttribute('aria-invalid'));
		const t = e.field ? form.querySelector(`[name="${e.field}"], [data-field="${e.field}"]`) : null;
		if (t) {
			if (t.matches('input,select,textarea')) t.setAttribute('aria-invalid', 'true');
			t.scrollIntoView({ block: 'center', behavior: 'smooth' });
			(t.matches('input,select,textarea,[contenteditable]') ? t : t.querySelector('input,button,[contenteditable]') || t).focus({ preventScroll: true });
		}
		toast(e.message, 'err');
	};
	const clearErr = (form) => { const box = $('.bk-g-formerr', form); if (box) box.hidden = true; };

	// Subida de imágenes a la Mediateca (mismo endpoint que el editor de productos). Reduce fotos enormes en el navegador.
	const shrink = (file, max = 2560) => new Promise((ok) => {
		if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1.5 * 1048576) { ok(file); return; }
		const img = new Image();
		img.onload = () => {
			const s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
			if (s >= 1) { ok(file); return; }
			const c = document.createElement('canvas');
			c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
			c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
			c.toBlob((b) => ok(b ? new File([b], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' }) : file), 'image/jpeg', 0.9);
		};
		img.onerror = () => ok(file);
		img.src = URL.createObjectURL(file);
	});
	const uploadImage = async (file) => {
		const fd = new FormData();
		fd.append('file', await shrink(file));
		const res = await fetch(`${C.api}media`, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce }, body: fd });
		const d = await res.json().catch(() => ({}));
		if (!res.ok) throw new Error(d.message || 'No se pudo subir la imagen.');
		if (d.kind !== 'image') throw new Error('Sube una imagen (JPG, PNG o WebP).');
		return { id: d.id, url: d.url };
	};

	// Editor de texto básico (negrita, cursiva, títulos, listas, enlaces). HTML limpio.
	const ALLOWED = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'H2', 'H3', 'H4', 'A']);
	const cleanHtml = (html) => {
		const doc = new DOMParser().parseFromString(`<div>${html || ''}</div>`, 'text/html');
		const walk = (node) => [...node.childNodes].map((n) => {
			if (n.nodeType === 3) return esc(n.textContent);
			if (n.nodeType !== 1) return '';
			const inner = walk(n);
			if (!ALLOWED.has(n.tagName)) return n.tagName === 'DIV' ? `<p>${inner}</p>` : inner;
			const tag = ({ B: 'strong', I: 'em' })[n.tagName] || n.tagName.toLowerCase();
			if (tag === 'br') return '<br>';
			const href = tag === 'a' && /^(https?:\/\/|mailto:|tel:|\/)/i.test(n.getAttribute('href') || '') ? ` href="${esc(n.getAttribute('href'))}"` : '';
			return `<${tag}${href}>${inner}</${tag}>`;
		}).join('');
		return walk(doc.body.firstChild).replace(/<p>\s*<\/p>/g, '').trim();
	};
	const editorHtml = (id, html, label) => `<div class="bk-gp-field" data-field="text"><span id="${id}-l">${esc(label)}</span>
		<div class="bk-gp-editor">
			<div class="bk-gp-tools" role="toolbar" aria-label="Formato" data-for="${id}">
				<button type="button" data-cmd="bold" aria-label="Negrita"><b>B</b></button>
				<button type="button" data-cmd="italic" aria-label="Cursiva"><i>I</i></button>
				<button type="button" data-cmd="formatBlock" data-arg="h3" aria-label="Subtítulo">Subtítulo</button>
				<button type="button" data-cmd="formatBlock" data-arg="p" aria-label="Párrafo normal">Párrafo</button>
				<button type="button" data-cmd="insertUnorderedList" aria-label="Lista con viñetas">• Lista</button>
				<button type="button" data-cmd="insertOrderedList" aria-label="Lista numerada">1. Lista</button>
				<button type="button" data-cmd="createLink" aria-label="Agregar enlace">Enlace</button>
			</div>
			<div id="${id}" class="bk-gp-desc bk-g-rich" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="${id}-l">${cleanHtml(html)}</div>
		</div></div>`;

	const imgSlot = (key, img, label, hint, ratio = '16 / 9') => `<div class="bk-gc-slot" data-slot="${esc(key)}">
		<span class="bk-gc-slot__l">${esc(label)}</span>
		<div class="bk-gc-slot__img" style="aspect-ratio:${ratio}">${img ? `<img src="${esc(img.url)}" alt="">` : '<span>Sin imagen</span>'}</div>
		${hint ? `<small>${esc(hint)}${img?.w ? ` · actual ${img.w}×${img.h}` : ''}</small>` : ''}
		<div class="bk-gc-slot__bar">
			<label class="bk-g-btn is-ghost is-small">${C.icons.image} ${img ? 'Cambiar' : 'Subir'}<input type="file" accept="image/jpeg,image/png,image/webp" class="bk-g-sr" data-up="${esc(key)}"></label>
			${img ? `<button type="button" class="bk-g-icon is-danger" data-rm="${esc(key)}" aria-label="Quitar: ${esc(label)}">${C.icons.trash}</button>` : ''}
		</div></div>`;

	/* ============================================================================
	 * Descuentos
	 * ========================================================================== */
	let D = null; // Datos del servidor.
	let F = null; // Regla en edición.

	const scopeTxt = (r) => r.scope === 'cats' ? `Categorías: ${r.cats.map((id) => D.cats.find((c) => c.id === id)?.name || id).join(', ')}` : r.scope === 'products' ? `${r.products.length} producto${r.products.length === 1 ? '' : 's'}` : 'Toda la tienda';

	async function viewDiscounts(sub) {
		const seq = A.seq();
		if (!D || !sub.length) main.innerHTML = '<h1 class="bk-g-h1">Descuentos</h1><div class="bk-g-loading">Cargando…</div>';
		try { D = await api('discounts'); } catch (e) { main.innerHTML = err(e); return; }
		if (seq !== A.seq()) return;
		const [kind, id] = sub;
		if (kind === 'campana' || kind === 'cantidad') {
			const list = kind === 'campana' ? D.campaigns : D.qty;
			const found = id && id !== 'nuevo' ? list.find((r) => r.id === id) : null;
			if (id && id !== 'nuevo' && !found) { location.hash = '#descuentos'; return; }
			F = found ? JSON.parse(JSON.stringify(found)) : newRule(kind);
			F.kind = kind;
			paintForm();
			return;
		}
		paintList();
	}

	const fmtLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
	const newRule = (kind) => {
		const now = new Date(`${D.now}:00`);
		const start = new Date(now); start.setHours(0, 0, 0, 0);
		const end = new Date(start); end.setDate(end.getDate() + 7); end.setHours(23, 59, 0, 0);
		const base = { id: '', name: '', active: true, start: fmtLocal(start), end: fmtLocal(end), scope: 'all', cats: [], products: [], products_info: [], exclude_sale: true };
		return kind === 'campana'
			? { ...base, pct: 20, badge: '', bar: true, home: true, featured: [], featured_info: [] }
			: { ...base, name: 'Compra más y ahorra', tiers: [{ min: 1, pct: 10 }, { min: 2, pct: 20 }, { min: 4, pct: 30 }], count: 'product' };
	};

	function paintList() {
		const card = (r, kind) => {
			const [l, cls] = stateLbl[r.state] || [r.state, ''];
			return `<li class="bk-gd-item">
				<div class="bk-gd-item__main"><b>${esc(r.name)}</b>
					<span class="bk-gd-item__pct">${kind === 'campana' ? `−${pctTxt(r.pct)} %` : r.tier_labels.map((t) => `${esc(t.label)}: −${t.pct} %`).join(' · ')}</span>
					<small>${esc(r.start_text)} → ${esc(r.end_text)} · ${esc(scopeTxt(r))}${r.exclude_sale ? ` · sin ${esc(D.saleCat || '¡Sale!')}` : ''}${kind === 'cantidad' ? ` · ${r.count === 'cart' ? 'suma todo el carrito' : 'por modelo'}` : ''}</small></div>
				<span class="bk-g-pill ${cls}">${l}</span>
				<div class="bk-gd-item__act">
					<a class="bk-g-btn is-ghost is-small" href="#descuentos/${kind}/${r.id}">${C.icons.edit} Editar<span class="bk-g-sr">: ${esc(r.name)}</span></a>
					<button type="button" class="bk-g-btn is-ghost is-small" data-dtoggle="${kind}:${r.id}">${r.active ? 'Pausar' : 'Activar'}<span class="bk-g-sr">: ${esc(r.name)}</span></button>
					<button type="button" class="bk-g-icon is-danger" data-ddel="${kind}:${r.id}" aria-label="Borrar ${esc(r.name)}">${C.icons.trash}</button>
				</div></li>`;
		};
		main.innerHTML = `<h1 class="bk-g-h1">Descuentos</h1>
			${D.module ? '' : `<div class="bk-g-alert is-warn" role="status"><p><b>Los descuentos están apagados en la tienda.</b> Puedes preparar las reglas, pero los precios no cambian hasta encenderlos. Al guardar o activar un descuento se encienden solos.</p><button type="button" class="bk-g-btn" data-dmodule>${C.icons.percent || ''} Encender descuentos</button></div>`}
			${D.yaypricing ? '<p class="bk-g-note">YayPricing también está activo: revisa que sus reglas no se crucen con estas (podrían dar dos descuentos).</p>' : ''}
			<p class="bk-g-hint">Los descuentos no se suman: a cada prenda se le aplica el mayor, sobre el precio normal. Se activan y se apagan solos en las fechas elegidas (hora de Colombia).</p>
			<section class="bk-g-card"><div class="bk-g-h2row"><h2>Campañas por porcentaje</h2><a class="bk-g-btn" href="#descuentos/campana/nuevo">${C.icons.plus} Nueva campaña</a></div>
				<p class="bk-g-hint">Ej.: 20 % en toda la tienda (menos ${esc(D.saleCat || '¡Sale!')}) del 1 al 7 de noviembre. Precio tachado, etiqueta en las tarjetas, anuncio arriba y sección en el inicio.</p>
				${D.campaigns.length ? `<ul class="bk-gd-list">${D.campaigns.map((r) => card(r, 'campana')).join('')}</ul>` : '<p class="bk-g-empty">Aún no hay campañas.</p>'}</section>
			<section class="bk-g-card"><div class="bk-g-h2row"><h2>Descuento por cantidad</h2><a class="bk-g-btn" href="#descuentos/cantidad/nuevo">${C.icons.plus} Nuevo descuento por cantidad</a></div>
				<p class="bk-g-hint">Ej.: 1 prenda 10 %, desde 2 prendas 20 %, desde 4 prendas 30 %. Se muestra en la ficha y se aplica en el carrito.</p>
				${D.qty.length ? `<ul class="bk-gd-list">${D.qty.map((r) => card(r, 'cantidad')).join('')}</ul>` : '<p class="bk-g-empty">Aún no hay descuentos por cantidad.</p>'}</section>`;
	}

	const catsHtml = () => `<div class="bk-gp-tree is-open"><ul>${D.cats.map((c) => `<li style="--d:${c.depth}"><label class="bk-gp-tcat"><input type="checkbox" name="cats" value="${c.id}" ${F.cats.includes(c.id) ? 'checked' : ''}><span>${esc(c.name)}</span><small>${c.count}</small></label></li>`).join('')}</ul></div>`;
	const pickHtml = (key, title, hint) => `<div class="bk-gd-pick" data-field="${key}"><span class="bk-gd-pick__t">${esc(title)}</span>
		<ul class="bk-gd-chips">${(F[`${key}_info`] || []).map((p) => `<li>${p.image ? `<img src="${esc(p.image)}" alt="" width="28" height="28">` : ''}<span>${esc(p.name)}${p.sale ? ' <small>(en Sale)</small>' : ''}</span><button type="button" class="bk-g-icon" data-unpick="${key}:${p.id}" aria-label="Quitar ${esc(p.name)}">${C.icons.x}</button></li>`).join('')}</ul>
		<label class="bk-g-search bk-gd-psearch"><span class="bk-g-sr">Buscar producto</span>${C.icons.search}<input type="search" data-psearch="${key}" placeholder="Buscar por nombre o REF" autocomplete="off"></label>
		<ul class="bk-gd-results" data-presults="${key}" role="listbox" aria-label="Resultados"></ul>
		${hint ? `<small class="bk-g-hint">${esc(hint)}</small>` : ''}</div>`;
	const tiersHtml = () => `<table class="bk-gd-tiers"><thead><tr><th scope="col">Desde (prendas)</th><th scope="col">Descuento</th><th></th></tr></thead><tbody>
		${F.tiers.map((t, i) => `<tr><td><input type="number" min="1" step="1" inputmode="numeric" name="tmin" value="${esc(t.min)}" aria-label="Tramo ${i + 1}: desde cuántas prendas"></td>
			<td><span class="bk-gd-pctin"><input type="number" min="1" max="90" step="0.5" inputmode="decimal" name="tpct" value="${esc(t.pct)}" aria-label="Tramo ${i + 1}: porcentaje"><i>%</i></span></td>
			<td>${F.tiers.length > 1 ? `<button type="button" class="bk-g-icon is-danger" data-tdel="${i}" aria-label="Quitar tramo ${i + 1}">${C.icons.trash}</button>` : ''}</td></tr>`).join('')}
		</tbody></table>${F.tiers.length < 6 ? `<button type="button" class="bk-g-btn is-ghost is-small" data-tadd>${C.icons.plus} Agregar tramo</button>` : ''}
		<p class="bk-g-hint" id="bk-gd-tprev">${tierPreview()}</p>`;
	const tierPreview = () => {
		const t = [...F.tiers].filter((x) => Number(x.min) > 0).sort((a, b) => a.min - b.min);
		return t.length ? `Así se verá en la ficha: ${t.map((x, i) => { const n = t[i + 1] ? Number(t[i + 1].min) - 1 : 0; const lab = n > Number(x.min) ? `${x.min} a ${n} prendas` : n === Number(x.min) ? `${x.min} prenda${x.min > 1 ? 's' : ''}` : `${x.min} o más prendas`; return `${lab}: −${pctTxt(x.pct)} %`; }).join(' · ')}` : '';
	};

	function paintForm() {
		const camp = F.kind === 'campana';
		main.innerHTML = `<a class="bk-g-back" href="#descuentos">${C.icons.back} Descuentos</a>
			<h1 class="bk-g-h1">${F.id ? `Editar: ${esc(F.name)}` : camp ? 'Nueva campaña' : 'Nuevo descuento por cantidad'}</h1>
			<form id="bk-gd" class="bk-gp" novalidate>
				<div class="bk-gp-col">
					<section class="bk-g-card"><h2>${camp ? 'Campaña' : 'Descuento por cantidad'}</h2>
						<label class="bk-gp-field"><span>Nombre <b class="bk-gp-req" aria-hidden="true">*</b></span><input name="name" required maxlength="80" value="${esc(F.name)}" placeholder="${camp ? 'Ej.: Black Friday' : 'Ej.: Compra más y ahorra'}"><small>${camp ? 'Se ve en el anuncio de arriba, en el inicio y en la ficha.' : 'Se ve en la ficha y en el carrito.'}</small></label>
						${camp ? `<label class="bk-gp-field"><span>Porcentaje de descuento <b class="bk-gp-req" aria-hidden="true">*</b></span><span class="bk-gd-pctin is-big"><input type="number" name="pct" min="1" max="90" step="0.5" inputmode="decimal" value="${esc(F.pct)}" required><i>%</i></span></label>` : `<fieldset class="bk-gp-field" data-field="tiers"><legend>Tramos <b class="bk-gp-req" aria-hidden="true">*</b></legend><div id="bk-gd-tiers">${tiersHtml()}</div></fieldset>
							<fieldset class="bk-gp-field"><legend>Cómo se cuentan las prendas</legend>
								<label class="bk-gp-line"><input type="radio" name="count" value="product" ${F.count !== 'cart' ? 'checked' : ''}><span><b>Del mismo modelo</b><small>Ej.: 2 jeans iguales en distintas tallas</small></span></label>
								<label class="bk-gp-line"><input type="radio" name="count" value="cart" ${F.count === 'cart' ? 'checked' : ''}><span><b>Todas las prendas de la promoción</b><small>Suma lo que haya en el carrito</small></span></label></fieldset>`}
						<div class="bk-g-row2">
							<label class="bk-gp-field"><span>Desde <b class="bk-gp-req" aria-hidden="true">*</b></span><input type="datetime-local" name="start" value="${esc(F.start)}" required></label>
							<label class="bk-gp-field"><span>Hasta <b class="bk-gp-req" aria-hidden="true">*</b></span><input type="datetime-local" name="end" value="${esc(F.end)}" required></label>
						</div>
						<label class="bk-g-switch"><input type="checkbox" name="active" ${F.active ? 'checked' : ''}><span>Activo (desmarca para pausarlo sin borrarlo)</span></label>
					</section>
					<section class="bk-g-card"><h2>A qué productos</h2>
						<fieldset class="bk-gp-field"><legend class="bk-g-sr">Alcance</legend><div class="bk-gd-scope">
							${[['all', 'Toda la tienda'], ['cats', 'Categorías'], ...(camp ? [] : [['products', 'Productos']])].map(([v, l]) => `<label class="bk-gp-chip"><input type="radio" name="scope" value="${v}" ${F.scope === v ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></fieldset>
						<div data-field="cats" ${F.scope === 'cats' ? '' : 'hidden'} id="bk-gd-cats">${catsHtml()}</div>
						<div ${F.scope === 'products' ? '' : 'hidden'} id="bk-gd-prods">${pickHtml('products', 'Productos', '')}</div>
						<label class="bk-g-switch"><input type="checkbox" name="exclude_sale" ${F.exclude_sale ? 'checked' : ''}><span>Excluir los productos de ${esc(D.saleCat || '¡Sale!')} (ya tienen su propio descuento)</span></label>
					</section>
					${camp ? `<section class="bk-g-card"><h2>Cómo se destaca</h2>
						<label class="bk-gp-field"><span>Etiqueta en las tarjetas (opcional)</span><input name="badge" maxlength="18" value="${esc(F.badge || '')}" placeholder="Ej.: BLACK FRIDAY"><small>Además del "−${pctTxt(F.pct || 0)} %". Máximo 18 letras.</small></label>
						<label class="bk-g-switch"><input type="checkbox" name="bar" ${F.bar ? 'checked' : ''}><span>Anuncio en la barra verde de arriba</span></label>
						<label class="bk-g-switch"><input type="checkbox" name="home" ${F.home ? 'checked' : ''}><span>Sección destacada en el inicio con cuenta regresiva</span></label>
						${pickHtml('featured', 'Productos destacados de la oferta (opcional, máx. 12)', 'Si no eliges, se muestran los más nuevos de la campaña.')}
					</section>` : ''}
					<section class="bk-g-card bk-gp-actions">
						<p class="bk-g-formerr" role="alert" hidden></p>
						<button type="submit" class="bk-g-btn">${C.icons.check} Guardar</button>
						<a class="bk-g-btn is-ghost" href="#descuentos">Cancelar</a>
					</section>
				</div>
			</form>`;
		dirty = false;
		main.focus();
	}

	const readForm = () => {
		const f = $('#bk-gd');
		F.name = f.name.value.trim();
		F.start = f.start.value;
		F.end = f.end.value;
		F.active = f.active.checked;
		F.scope = f.querySelector('[name="scope"]:checked')?.value || 'all';
		F.cats = $$('[name="cats"]:checked', f).map((c) => Number(c.value));
		F.exclude_sale = f.exclude_sale.checked;
		if (F.kind === 'campana') {
			F.pct = f.pct.value; F.badge = f.badge.value.trim(); F.bar = f.bar.checked; F.home = f.home.checked;
		} else {
			F.count = f.querySelector('[name="count"]:checked')?.value || 'product';
			readTiers();
		}
	};
	const readTiers = () => {
		const f = $('#bk-gd');
		const mins = $$('[name="tmin"]', f);
		const pcts = $$('[name="tpct"]', f);
		F.tiers = mins.map((m, i) => ({ min: Number(m.value) || 0, pct: Number(String(pcts[i].value).replace(',', '.')) || 0 }));
	};

	async function saveRule(btn) {
		const f = $('#bk-gd');
		readForm();
		clearErr(f);
		const body = { type: F.kind === 'campana' ? 'campaign' : 'qty', rule: { ...F, featured: (F.featured_info || []).map((p) => p.id), products: (F.products_info || []).map((p) => p.id) } };
		await busy(btn, async () => {
			try {
				D = await api('discounts', { method: 'POST', body });
				dirty = false;
				A.forget('');
				toast(D.module ? 'Descuento guardado' : 'Descuento guardado (pausado)');
				location.hash = '#descuentos';
			} catch (e) { showErr(f, e); }
		});
	}

	// Buscador de productos (alcance y destacados).
	let psT = 0;
	const searchProducts = (input) => {
		clearTimeout(psT);
		const key = input.dataset.psearch;
		const box = $(`[data-presults="${key}"]`);
		const q = input.value.trim();
		if (q.length < 2) { box.innerHTML = ''; return; }
		psT = setTimeout(async () => {
			try {
				const list = await api(`discounts/products?q=${encodeURIComponent(q)}`);
				box.innerHTML = list.length ? list.map((p) => `<li><button type="button" data-pick="${key}" data-p='${esc(JSON.stringify(p))}'>${p.image ? `<img src="${esc(p.image)}" alt="" width="32" height="32">` : ''}<span>${esc(p.name)}<small>REF ${esc(p.sku || '—')}${p.sale ? ' · en Sale' : ''}</small></span></button></li>`).join('') : '<li class="bk-g-empty">Sin resultados.</li>';
			} catch (e) { box.innerHTML = ''; }
		}, 300);
	};

	/* ============================================================================
	 * Contenido (ACF)
	 * ========================================================================== */
	let B = null; // Banners.
	let G = null; // Guía.
	let P = null; // Políticas / preguntas.

	async function viewContent(sub) {
		const tab = ['banners', 'guia', 'politicas', 'preguntas'].includes(sub[0]) ? sub[0] : 'banners';
		main.innerHTML = `<h1 class="bk-g-h1">Contenido</h1>
			<nav class="bk-g-subtabs" aria-label="Contenido">${[['banners', 'Banners'], ['guia', 'Guía de tallas'], ['politicas', 'Políticas'], ['preguntas', 'Preguntas frecuentes']].map(([k, l]) => `<a href="#contenido/${k}" ${tab === k ? 'aria-current="page"' : ''}>${l}</a>`).join('')}</nav>
			<div id="bk-gc"><div class="bk-g-loading">Cargando…</div></div>`;
		const seq = A.seq();
		try {
			if (tab === 'banners') { B = await api('content/banners'); if (seq === A.seq()) paintBanners(); }
			if (tab === 'guia') { G = await api('content/guide'); if (seq === A.seq()) paintGuide(); }
			if (tab === 'politicas' || tab === 'preguntas') {
				const type = tab === 'politicas' ? 'policy' : 'faq';
				P = { type, ...(await api(`content/posts/${type}`)) };
				if (seq !== A.seq()) return;
				if (sub[1]) paintPostForm(sub[1]); else paintPosts();
			}
		} catch (e) { $('#bk-gc').innerHTML = err(e); }
	}

	function paintBanners() {
		const box = $('#bk-gc');
		if (!B.ready) { box.innerHTML = '<p class="bk-g-error">No se encontraron los campos de los banners (ACF). Revísalos en wp-admin → Banners.</p>'; return; }
		box.innerHTML = `<form id="bk-gcb" novalidate>
			<section class="bk-g-card"><h2>Banners del inicio</h2>
				<p class="bk-g-hint">Computador ${esc(B.sizes.pc)} y celular ${esc(B.sizes.mo)}. Como el texto va dentro de la imagen, escríbelo en "Texto de la imagen" (lo leen Google y los lectores de pantalla). El primero es el que carga más rápido: úsalo para lo más importante.</p>
				<ol class="bk-gc-slides">${B.slides.map((s, i) => `<li class="bk-gc-slide" data-i="${i}">
					<div class="bk-gc-slide__head"><b>Banner ${i + 1}</b>
						<button type="button" class="bk-g-icon" data-bmove="${i}:-1" ${i === 0 ? 'disabled' : ''} aria-label="Subir el banner ${i + 1}">${C.icons.up}</button>
						<button type="button" class="bk-g-icon" data-bmove="${i}:1" ${i === B.slides.length - 1 ? 'disabled' : ''} aria-label="Bajar el banner ${i + 1}">${C.icons.down}</button>
						<button type="button" class="bk-g-icon is-danger" data-bdel="${i}" aria-label="Quitar el banner ${i + 1}">${C.icons.trash}</button></div>
					<div class="bk-gc-slide__imgs">${imgSlot(`s${i}:pc`, s.pc, 'Computador', '1920×1080')}${imgSlot(`s${i}:mo`, s.mo, 'Celular', '1200×1200', '1 / 1')}</div>
					<div class="bk-g-row2">
						<label class="bk-gp-field"><span>Texto de la imagen (alt)</span><input data-balt="${i}" value="${esc(s.alt ?? s.pc?.alt ?? s.mo?.alt ?? '')}" maxlength="180" placeholder="Ej.: Nueva colección Curvy: jeans desde $129.900"></label>
						${B.hasLink ? `<label class="bk-gp-field"><span>Enlace al tocar el banner</span><input data-blink="${i}" value="${esc(s.link || '')}" inputmode="url" placeholder="${esc(C.site)}linea-curvy/"></label>` : ''}
					</div></li>`).join('')}</ol>
				<button type="button" class="bk-g-btn is-ghost" data-badd>${C.icons.plus} Agregar banner</button>
			</section>
			<section class="bk-g-card"><h2>Imágenes de las líneas (Slim y Curvy)</h2>
				<div class="bk-gc-lines">${[['linea_slim_pc', 'Línea Slim · computador'], ['linea_slim_mo', 'Línea Slim · celular'], ['linea_curvy_pc', 'Línea Curvy · computador'], ['linea_curvy_mo', 'Línea Curvy · celular']].map(([k, l]) => imgSlot(`l:${k}`, B.lines[k], l, '', k.endsWith('_mo') ? '1 / 1' : '16 / 9')).join('')}</div>
			</section>
			<section class="bk-g-card bk-gp-actions"><p class="bk-g-formerr" role="alert" hidden></p>
				<button type="submit" class="bk-g-btn">${C.icons.check} Guardar banners</button>
				${B.admin ? `<a class="bk-g-link" href="${esc(B.admin)}" target="_blank" rel="noopener">Abrir en wp-admin ${C.icons.open}</a>` : ''}</section>
		</form>`;
		dirty = false;
	}
	const readBanners = () => {
		$$('[data-balt]').forEach((el) => { B.slides[el.dataset.balt].alt = el.value; });
		$$('[data-blink]').forEach((el) => { B.slides[el.dataset.blink].link = el.value; });
	};

	function paintGuide() {
		$('#bk-gc').innerHTML = `<form id="bk-gcg" novalidate><section class="bk-g-card"><h2>Guía de tallas y siluetas</h2>
			<p class="bk-g-hint">Se abren en la ficha de producto con el botón "Guía de tallas".</p>
			<div class="bk-gc-lines is-2">${imgSlot('g:guide', G.guide, 'Guía de tallas', '', 'auto')}${imgSlot('g:shapes', G.shapes, 'Siluetas', '', 'auto')}</div>
			<div class="bk-g-row2"><label class="bk-gp-field"><span>Texto de la guía (alt)</span><input name="guide_alt" value="${esc(G.guide?.alt || '')}" placeholder="Ej.: Tabla de tallas Slim y Curvy en centímetros"></label>
			<label class="bk-gp-field"><span>Texto de las siluetas (alt)</span><input name="shapes_alt" value="${esc(G.shapes?.alt || '')}"></label></div></section>
			<section class="bk-g-card bk-gp-actions"><button type="submit" class="bk-g-btn">${C.icons.check} Guardar guía</button>
			${G.admin ? `<a class="bk-g-link" href="${esc(G.admin)}" target="_blank" rel="noopener">Abrir en wp-admin ${C.icons.open}</a>` : ''}</section></form>`;
		dirty = false;
	}

	function paintPosts() {
		const faq = P.type === 'faq';
		const base = faq ? 'preguntas' : 'politicas';
		if (P.missing) { $('#bk-gc').innerHTML = '<p class="bk-g-error">Este tipo de contenido no está activo en el sitio.</p>'; return; }
		const row = (it, i, n) => `<li class="bk-gc-post">
			<div><b>${esc(it.title)}</b>${it.status !== 'publish' ? ' <span class="bk-g-pill is-on-hold">Borrador</span>' : ''}<small>${esc((it.text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120))}…</small></div>
			<div class="bk-gd-item__act">
				${faq ? `<button type="button" class="bk-g-icon" data-qmove="${i}:-1" ${i === 0 ? 'disabled' : ''} aria-label="Subir: ${esc(it.title)}">${C.icons.up}</button><button type="button" class="bk-g-icon" data-qmove="${i}:1" ${i === n - 1 ? 'disabled' : ''} aria-label="Bajar: ${esc(it.title)}">${C.icons.down}</button>` : ''}
				<a class="bk-g-btn is-ghost is-small" href="#contenido/${base}/${it.id}">${C.icons.edit} Editar<span class="bk-g-sr">: ${esc(it.title)}</span></a>
				<a class="bk-g-icon" href="${esc(it.url)}" target="_blank" rel="noopener" aria-label="Ver en la tienda: ${esc(it.title)}">${C.icons.open}</a>
			</div></li>`;
		let list = '';
		if (faq) {
			const groups = [...P.cats.map((c) => ({ ...c, items: P.items.filter((x) => x.cat === c.id) })), { id: 0, name: 'Sin categoría', items: P.items.filter((x) => !x.cat || !P.cats.some((c) => c.id === x.cat)) }].filter((g) => g.items.length);
			list = groups.map((g) => `<h3 class="bk-gc-group">${esc(g.name)}</h3><ul class="bk-gc-posts" data-group="${g.id}">${g.items.map((it, i) => row(it, i, g.items.length)).join('')}</ul>`).join('');
		} else {
			list = `<ul class="bk-gc-posts">${P.items.map((it, i) => row(it, i, P.items.length)).join('')}</ul>`;
		}
		$('#bk-gc').innerHTML = `<section class="bk-g-card"><div class="bk-g-h2row"><h2>${faq ? 'Preguntas frecuentes' : 'Políticas de la tienda'}</h2>
			<a class="bk-g-btn" href="#contenido/${base}/nueva">${C.icons.plus} ${faq ? 'Nueva pregunta' : 'Nueva política'}</a></div>
			${faq && P.page ? `<p class="bk-g-hint">Se publican en <a href="${esc(P.page)}" target="_blank" rel="noopener">${esc(P.page)}</a> agrupadas por categoría, en este orden.</p>` : ''}
			${P.items.length ? list : '<p class="bk-g-empty">Aún no hay contenido.</p>'}</section>`;
		dirty = false;
	}

	function paintPostForm(id) {
		const faq = P.type === 'faq';
		const base = faq ? 'preguntas' : 'politicas';
		const it = id === 'nueva' ? { id: 0, title: '', text: '', status: 'publish', cat: P.cats[0]?.id || 0 } : P.items.find((x) => String(x.id) === String(id));
		if (!it) { location.hash = `#contenido/${base}`; return; }
		$('#bk-gc').innerHTML = `<a class="bk-g-back" href="#contenido/${base}">${C.icons.back} ${faq ? 'Preguntas frecuentes' : 'Políticas'}</a>
			<form id="bk-gcp" class="bk-g-card" data-id="${it.id}" novalidate>
				<label class="bk-gp-field"><span>${faq ? 'Pregunta' : 'Título'} <b class="bk-gp-req" aria-hidden="true">*</b></span><input name="title" required maxlength="200" value="${esc(it.title)}"></label>
				${faq && P.cats.length ? `<label class="bk-gp-field"><span>Categoría</span><select name="cat"><option value="0">Sin categoría</option>${P.cats.map((c) => `<option value="${c.id}" ${it.cat === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>` : ''}
				${editorHtml('bk-gc-text', it.text, faq ? 'Respuesta' : 'Texto de la política')}
				<label class="bk-g-switch"><input type="checkbox" name="publish" ${it.status === 'publish' ? 'checked' : ''}><span>Publicada (desmarca para dejarla en borrador)</span></label>
				<p class="bk-g-formerr" role="alert" hidden></p>
				<div class="bk-g-dactions"><button type="submit" class="bk-g-btn">${C.icons.check} Guardar</button>
				${it.id ? `<button type="button" class="bk-g-btn is-ghost" data-pdel="${it.id}">${C.icons.trash} Enviar a la papelera</button><a class="bk-g-link" href="${esc(it.url)}" target="_blank" rel="noopener">Ver en la tienda ${C.icons.open}</a>` : ''}</div>
			</form>`;
		dirty = false;
		$('#bk-gcp [name="title"]').focus();
	}

	/* ============================================================================
	 * Ayuda
	 * ========================================================================== */
	// Guía con capturas: cada figura lleva números sobre la pantalla y los mismos números en la lista de pasos.
	// Las imágenes salen de tools/local/help-shots.js (assets/img/ayuda/*.webp, 1280 px).
	const fig = (img, title, steps, h) => ({ img, title, steps, h });
	const HELP = [
		['seo', 'SEO y ADS', `
			<p>Mide de dónde llegan las visitas y revisa que las etiquetas de Google y Meta estén bien puestas. Las visitas se cuentan sin cookies ni datos personales y no cuentan robots ni al equipo de la tienda.</p>`, [
			fig('seo', 'Visitas', [
				'<b>¿Falta una etiqueta?</b> Escribe a <a href="mailto:contacto@ufoweb.co">contacto@ufoweb.co</a> con el ID (GTM-…, G-…, AW-…, ID del píxel) y qué debe medir. No pegues códigos en el sitio.',
				'<b>Pestañas:</b> Visitas, Etiquetas Google y Meta, y Clases y eventos rastreables.',
				'<b>Visitantes</b> de hoy, de los últimos 7 y de los últimos 30 días, con sus páginas vistas.',
				'<b>Periodo</b> del gráfico. Debajo: páginas más vistas, de dónde llegan (Google, Meta Ads, WhatsApp…), campañas (utm_campaign), ciudades y dispositivos.',
			], 549),
			fig('seo-etiquetas', 'Etiquetas Google y Meta', [
				'<b>Etiquetas Google y Meta:</b> revisa el código público de la tienda (inicio, tienda y un producto) y el contenedor de Google Tag Manager.',
				'<b>Revisar ahora:</b> úsalo después de cualquier cambio en GTM o en Meta (el resultado se guarda 6 horas).',
				'<b>Resultado:</b> qué etiquetas hay, si alguna está duplicada y qué falta. "Clases y eventos rastreables" lista los eventos <code>bk_</code> (añadir al carrito, WhatsApp, búsqueda…) para crear activadores en GTM.',
			], 847),
		]],
		['resumen', 'Resumen', '<p>Es la primera pantalla: cómo va la venta y qué hay por hacer hoy.</p>', [
			fig('resumen', 'Resumen', [
				'<b>Menú de secciones.</b> En pantallas medianas solo se ven los íconos: pasa el cursor para ver el nombre.',
				'<b>Ventas pagadas</b> de hoy y del mes.',
				'<b>Pedidos por atender:</b> por despachar, en espera y pendientes de pago. Toca uno para ver esos pedidos.',
				'<b>Tallas por agotarse:</b> tallas con 1 o 2 unidades, para reponer a tiempo.',
				'<b>Ver tienda</b> abre el sitio y <b>Salir</b> cierra la sesión (te deja en la pantalla de acceso).',
			], 524),
		]],
		['pedidos', 'Pedidos', '<p>Todos los pedidos de la tienda, con búsqueda, filtros y las marcas de trabajo del día (pago confirmado, impreso y despachado).</p>', [
			fig('pedidos', 'Lista de pedidos', [
				'<b>Buscar</b> por número (#3846), consecutivo (PEDIDO-3841 o 3841), cédula, nombre, correo o celular.',
				'<b>Filtros:</b> estado, pago confirmado, impreso, despachado y fechas.',
				'<b>Número del pedido:</b> tócalo para abrir el detalle a la derecha.',
				'<b>Pago confirmado, Impreso y Despachado:</b> se guardan al instante, sin botón de guardar.',
				'<b>Imprimir:</b> hoja con la clienta, la dirección, los productos con talla y REF y casillas de empaque. Al imprimir, el pedido queda como "Impreso".',
			], 500),
			fig('pedido', 'Detalle del pedido', [
				'<b>Estado del pedido</b> (en espera, procesando, completado…).',
				'<b>Marcas</b> de pago confirmado, impreso y despachado.',
				'<b>Guardar estado</b> e <b>Imprimir pedido</b>.',
				'<b>Enviar guía de envío:</b> elige la transportadora, escribe el número y toca "Ver correo antes de enviar". La clienta recibe un correo con la guía y la ve en Mi cuenta. Si dejas la casilla marcada, el pedido pasa a Completado.',
				'<b>Clienta:</b> cédula, consecutivo, celular con enlace a WhatsApp, correo y dirección.',
			], 771),
			fig('pedido-historial', 'Archivos, historial y notas', [
				'<b>Archivos del pedido</b> (comprobantes, facturas): sube PDF o fotos y quítalos. Son los mismos de ACF "Archivos Pedidos".',
				'<b>Historial:</b> cuándo se creó, se pagó, cambió de estado, se imprimió y se despachó, y quién lo hizo.',
				'<b>Notas:</b> internas o para la clienta (marca "Enviar a la clienta" y le llega por correo).',
			], 860),
		]],
		['productos', 'Productos', '<p>Precios, stock por talla, productos nuevos y fotos, sin entrar a WordPress.</p>', [
			fig('productos', 'Lista de productos', [
				'<b>Buscar</b> por nombre o REF y filtrar por línea o stock.',
				'<b>Estrella:</b> marca el producto como <b>Nuevo</b> (etiqueta en la tarjeta y sección "Lo nuevo").',
				'<b>Tallas y precios:</b> cambia el precio, el precio rebajado y el stock de cada talla sin abrir el producto.',
				'<b>Editar</b> el producto completo.',
				'<b>Nuevo producto.</b> Desde un producto también puedes "Crear en la otra línea" (copia el modelo a Slim o Curvy).',
			], 440),
			fig('producto-editar', 'Editar producto', [
				'<b>Nombre y REF.</b> En Línea Curvy la REF termina en PLUS (816 PLUS).',
				'<b>Prenda</b> (jeans, faldas…). "Todas las categorías" abre la lista completa.',
				'<b>Mostrar como Nuevo.</b>',
				'<b>Precio normal y rebajado.</b> Con precio rebajado el producto entra solo a ¡Sale! y muestra el aviso "no tiene garantía ni cambio".',
				'<b>Tallas y stock:</b> marca las tallas que vendes y escribe las unidades. Una talla desmarcada deja de verse.',
			], 860),
			fig('producto-fotos', 'Fotos, descripción y publicación', [
				'<b>Fotos y video:</b> la primera foto es la principal; ordénalas con las flechas. Se optimizan solas al subirlas. El video (MP4) se ve en la galería de la ficha.',
				'<b>Descripción</b> corta (junto al precio) y completa (negrita y listas).',
				'<b>Publicación:</b> publicado o borrador, y el botón para guardar.',
			], 860),
		]],
		['descuentos', 'Descuentos', `
			<p>Los descuentos <b>no se suman</b>: a cada prenda se le aplica el más alto, sobre el precio normal. Se activan y se apagan solos en las fechas elegidas (hora de Colombia). Si en Descuentos ves el aviso "Los descuentos están apagados en la tienda", toca <b>Encender descuentos</b> (también se encienden solos al guardar un descuento activo).</p>`, [
			fig('descuentos', 'Lista de descuentos', [
				'<b>Nueva campaña:</b> un porcentaje a toda la tienda (o a categorías) entre dos fechas.',
				'<b>Campaña</b> con su porcentaje, fechas, alcance y estado (Activa, Programada, Terminada o Pausada).',
				'<b>Editar, Pausar/Activar y Borrar.</b>',
				'<b>Descuento por cantidad:</b> tramos según cuántas prendas lleva la clienta.',
			], 698),
			fig('descuento-campana', 'Nueva campaña', [
				'<b>Nombre</b>: se ve en el anuncio de arriba, en el inicio y en la ficha.',
				'<b>Porcentaje</b> de descuento.',
				'<b>Desde y hasta</b> (hora de Colombia).',
				'<b>Activo:</b> desmárcalo para pausarla sin borrarla. Para probar sin que lo vean las clientas, usa una fecha futura.',
				'<b>A qué productos:</b> toda la tienda o categorías, sin los productos de ¡Sale! (ya tienen su rebaja). Más abajo: etiqueta de las tarjetas, anuncio y productos destacados del inicio.',
			], 744),
			fig('descuento-cantidad', 'Descuento por cantidad', [
				'<b>Tramos:</b> desde cuántas prendas y qué porcentaje (ej.: 2 prendas −20 %, 4 o más −30 %).',
				'<b>Agregar tramo</b> (hasta 6).',
				'<b>Vista previa</b> de cómo se verá en la ficha.',
				'<b>Cómo se cuentan:</b> prendas del mismo modelo (en cualquier talla) o todas las del carrito.',
			], 788),
			fig('descuentos-tienda', 'Así lo ve la clienta', [
				'<b>Anuncio</b> en la barra superior mientras dura la campaña.',
				'<b>Precio tachado</b> y precio con descuento, también en las tarjetas de la tienda (con etiqueta).',
				'<b>Recuadro</b> con la campaña y los tramos por cantidad. En el carrito se avisa cuántas prendas faltan para el siguiente tramo y cada pedido guarda qué descuento se aplicó.',
			], 653),
		]],
		['carritos', 'Carritos abandonados', `
			<p>Cuando una clienta escribe su correo <b>o su celular</b> en <b>Finalizar compra</b> y no termina, el carrito se guarda aquí con sus datos (nombre, cédula, ciudad y dirección si los escribió), también si cierra la página. Si marcó la autorización, recibe hasta 3 recordatorios (2, 24 y 48 h) con un enlace que le devuelve el carrito. Sin autorización, el carrito se ve aquí pero no se le escribe.</p>`, [
			fig('carritos', 'Carritos abandonados', [
				'<b>Indicadores</b> de los últimos 30 días: carritos capturados, pendientes, correos enviados y ventas recuperadas.',
				'<b>Filtro</b> por estado.',
				'<b>Clienta:</b> nombre, correo, celular (tócalo para escribirle por WhatsApp), cédula y ciudad.',
				'<b>Estado:</b> Pendiente (recibe recordatorios), Pendiente · sin autorización (no recibe correos), Recuperado (compró desde el correo), Compró, Vació el carrito o Vencido.',
			], 845),
		]],
		['correos', 'Correos', '<p>Mira cómo le llega cada correo a la clienta con un pedido real. La vista previa no envía nada.</p>', [
			fig('correos', 'Correos', [
				'<b>Pedido de ejemplo</b> para la vista previa.',
				'<b>Correos a la clienta</b> (pedido en espera, en proceso, completado, cancelado, reembolsado, nota, guía de envío y carritos abandonados), con su estado Activo o Apagado.',
				'<b>Para, asunto y remitente.</b>',
				'<b>Vista previa</b> del correo. Debajo, "Enviar prueba" manda una copia con "[Prueba]" en el asunto.',
			], 732),
		]],
		['contenido', 'Contenido', '<p>Lo que antes se cambiaba en WordPress: banners, guía de tallas, políticas y preguntas frecuentes. Se guarda en los mismos campos de ACF (se ve igual en wp-admin) y la tienda se actualiza sola.</p>', [
			fig('contenido', 'Banners del inicio', [
				'<b>Pestañas:</b> Banners, Guía de tallas, Políticas y Preguntas frecuentes.',
				'<b>Banner:</b> imagen para computador (1920 × 1080) y para celular (1200 × 1200), enlace y "Texto de la imagen" (lo leen Google y los lectores de pantalla). Ordénalos con las flechas o bórralos.',
				'<b>Subir</b> o cambiar la imagen.',
			], 831),
		]],
		['general', 'Preguntas comunes', `
			<p><b>Hice un cambio y no lo veo en la tienda:</b> la tienda guarda las páginas para que carguen rápido y se actualizan solas al guardar. Si no lo ves, recarga la página (Ctrl + F5) o espera un minuto.</p>
			<p><b>Cerrar sesión:</b> "Salir" arriba a la derecha te deja en esta misma pantalla de acceso.</p>
			<p><b>Soporte:</b> <a href="mailto:contacto@ufoweb.co">contacto@ufoweb.co</a>.</p>`, []],
	];

	const figHtml = (f) => `<figure class="bk-g-hfig">
			<a class="bk-g-hfig__img" href="${esc(C.helpImg + f.img)}.webp?v=${esc(C.ver)}" target="_blank" rel="noopener" aria-label="Ver la imagen completa: ${esc(f.title)}">
				<img src="${esc(C.helpImg + f.img)}.webp?v=${esc(C.ver)}" width="1280" height="${f.h}" loading="lazy" decoding="async" alt="Captura de ${esc(f.title)} con los pasos numerados"></a>
			<figcaption><h3>${esc(f.title)}</h3><ol class="bk-g-hsteps">${f.steps.map((s) => `<li>${s}</li>`).join('')}</ol></figcaption>
		</figure>`;

	function viewHelp() {
		const items = C.scope === 'seo' ? HELP.filter(([k]) => k === 'seo' || k === 'general') : HELP;
		main.innerHTML = `<h1 class="bk-g-h1">Ayuda</h1>
			<p class="bk-g-hint">Cómo funciona cada sección. Los números de cada imagen corresponden a los pasos de su lista; toca la imagen para verla en grande.</p>
			<nav class="bk-g-hnav" aria-label="Secciones de la ayuda">${items.map(([k, t]) => `<a href="#ayuda" data-help="${k}">${esc(t)}</a>`).join('')}</nav>
			<div class="bk-g-help">${items.map(([k, t, body, figs = []], i) => `<details class="bk-g-card" id="ayuda-${k}" ${i === 0 ? 'open' : ''}><summary><h2>${esc(t)}</h2></summary><div class="bk-g-help__body">${body}${figs.map(figHtml).join('')}</div></details>`).join('')}</div>`;
	}

	/* ============================================================================
	 * Eventos
	 * ========================================================================== */
	document.addEventListener('click', async (e) => {
		const t = e.target;
		// Salir de un formulario con cambios.
		const link = t.closest('a[href^="#"]');
		if (link && dirty && ($('#bk-gd') || $('#bk-gcp') || $('#bk-gcb') || $('#bk-gcg')) && !window.confirm('Tienes cambios sin guardar. ¿Salir sin guardar?')) { e.preventDefault(); return; }
		if (link) dirty = false;

		// Ayuda: índice de secciones.
		const hl = t.closest('[data-help]');
		if (hl) {
			e.preventDefault();
			const d = $(`#ayuda-${hl.dataset.help}`);
			if (d) { d.open = true; d.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); d.querySelector('summary')?.focus({ preventScroll: true }); }
			return;
		}

		// Descuentos.
		const dm = t.closest('[data-dmodule]');
		if (dm) {
			await busy(dm, async () => {
				try { await api('discounts/module', { method: 'POST', body: {} }); D = await api('discounts'); A.forget(''); toast('Descuentos encendidos: ya se ven en la tienda'); paintList(); } catch (e) { toast(e.message, 'err'); }
			});
			return;
		}
		const tg = t.closest('[data-dtoggle]');
		if (tg) {
			const [kind, id] = tg.dataset.dtoggle.split(':');
			const r = (kind === 'campana' ? D.campaigns : D.qty).find((x) => x.id === id);
			await busy(tg, async () => {
				D = await api('discounts', { method: 'POST', body: { type: kind === 'campana' ? 'campaign' : 'qty', rule: { ...r, active: !r.active } } });
				toast(r.active ? 'Pausado' : 'Activado');
				paintList();
			});
			return;
		}
		const del = t.closest('[data-ddel]');
		if (del) {
			const [kind, id] = del.dataset.ddel.split(':');
			if (!window.confirm('¿Borrar este descuento? No se puede deshacer.')) return;
			await busy(del, async () => { D = await api('discounts/delete', { method: 'POST', body: { type: kind === 'campana' ? 'campaign' : 'qty', id } }); toast('Descuento borrado'); paintList(); });
			return;
		}
		if (t.closest('[data-tadd]')) { readTiers(); const last = F.tiers[F.tiers.length - 1] || { min: 0, pct: 0 }; F.tiers.push({ min: Number(last.min) + 1, pct: Number(last.pct) + 5 }); $('#bk-gd-tiers').innerHTML = tiersHtml(); dirty = true; $$('[name="tmin"]').pop()?.focus(); return; }
		const tdel = t.closest('[data-tdel]');
		if (tdel) { readTiers(); F.tiers.splice(Number(tdel.dataset.tdel), 1); $('#bk-gd-tiers').innerHTML = tiersHtml(); dirty = true; $('[name="tmin"]')?.focus(); return; }
		const pick = t.closest('[data-pick]');
		if (pick) {
			const key = pick.dataset.pick;
			const p = JSON.parse(pick.dataset.p);
			F[`${key}_info`] = F[`${key}_info`] || [];
			if (!F[`${key}_info`].some((x) => x.id === p.id) && F[`${key}_info`].length < (key === 'featured' ? 12 : 200)) F[`${key}_info`].push(p);
			readForm();
			const wrap = pick.closest('.bk-gd-pick');
			wrap.outerHTML = pickHtml(key, wrap.querySelector('.bk-gd-pick__t').textContent, wrap.querySelector('small.bk-g-hint')?.textContent || '');
			dirty = true;
			$(`[data-psearch="${key}"]`)?.focus();
			return;
		}
		const unpick = t.closest('[data-unpick]');
		if (unpick) {
			const [key, id] = unpick.dataset.unpick.split(':');
			F[`${key}_info`] = (F[`${key}_info`] || []).filter((x) => String(x.id) !== id);
			const wrap = unpick.closest('.bk-gd-pick');
			wrap.outerHTML = pickHtml(key, wrap.querySelector('.bk-gd-pick__t').textContent, wrap.querySelector('small.bk-g-hint')?.textContent || '');
			dirty = true;
			$(`[data-psearch="${key}"]`)?.focus();
			return;
		}

		// Contenido: banners.
		const bmove = t.closest('[data-bmove]');
		if (bmove) {
			readBanners();
			const [i, dir] = bmove.dataset.bmove.split(':').map(Number);
			[B.slides[i], B.slides[i + dir]] = [B.slides[i + dir], B.slides[i]];
			paintBanners(); dirty = true;
			$(`[data-bmove="${i + dir}:${dir}"]`)?.focus();
			return;
		}
		const bdel = t.closest('[data-bdel]');
		if (bdel) {
			if (!window.confirm('¿Quitar este banner del inicio? (La imagen sigue en la Mediateca.)')) return;
			readBanners(); B.slides.splice(Number(bdel.dataset.bdel), 1); paintBanners(); dirty = true; return;
		}
		if (t.closest('[data-badd]')) { readBanners(); B.slides.push({ pc: null, mo: null, link: '', alt: '' }); paintBanners(); dirty = true; $$('[data-up]').filter((x) => x.dataset.up.startsWith(`s${B.slides.length - 1}:`))[0]?.closest('label')?.focus(); return; }
		const rm = t.closest('[data-rm]');
		if (rm) { setSlot(rm.dataset.rm, null); dirty = true; return; }

		// Contenido: preguntas (orden) y papelera.
		const qmove = t.closest('[data-qmove]');
		if (qmove) {
			const ul = qmove.closest('[data-group]');
			const ids = $$('.bk-gc-post a[href*="#contenido/preguntas/"]', ul).map((a) => Number(a.getAttribute('href').split('/').pop()));
			const [i, dir] = qmove.dataset.qmove.split(':').map(Number);
			[ids[i], ids[i + dir]] = [ids[i + dir], ids[i]];
			const others = P.items.filter((x) => !ids.includes(x.id)).map((x) => x.id);
			await busy(qmove, async () => {
				P = { type: 'faq', ...(await api('content/posts/faq/order', { method: 'POST', body: { ids: [...others, ...ids] } })) };
				paintPosts(); toast('Orden guardado');
				$(`[data-qmove="${i + dir}:${dir}"]`)?.focus();
			});
			return;
		}
		const pdel = t.closest('[data-pdel]');
		if (pdel) {
			if (!window.confirm('¿Enviar a la papelera? Se puede recuperar en wp-admin.')) return;
			await busy(pdel, async () => {
				P = { type: P.type, ...(await api(`content/posts/${P.type}/delete`, { method: 'POST', body: { id: Number(pdel.dataset.pdel) } })) };
				dirty = false; toast('Enviado a la papelera');
				location.hash = `#contenido/${P.type === 'faq' ? 'preguntas' : 'politicas'}`;
			});
			return;
		}

		// Editor de texto.
		const cmd = t.closest('[data-for] [data-cmd]');
		if (cmd) {
			const ed = document.getElementById(cmd.closest('[data-for]').dataset.for);
			ed.focus();
			if (cmd.dataset.cmd === 'createLink') {
				const url = window.prompt('Dirección del enlace (https://… o /pagina/)');
				if (url) document.execCommand('createLink', false, url.trim());
			} else {
				document.execCommand(cmd.dataset.cmd, false, cmd.dataset.arg ? `<${cmd.dataset.arg}>` : null);
			}
			dirty = true;
		}
	});

	// Imagen de un hueco (banner, línea o guía).
	const setSlot = (key, img) => {
		const [where, field] = key.split(':');
		if (where.startsWith('s')) { readBanners(); B.slides[Number(where.slice(1))][field] = img; paintBanners(); }
		else if (where === 'l') { readBanners(); B.lines[field] = img; paintBanners(); }
		else if (where === 'g') { const f = $('#bk-gcg'); const alts = { guide_alt: f.guide_alt.value, shapes_alt: f.shapes_alt.value }; G[field] = img ? { ...img, alt: alts[`${field}_alt`] } : null; paintGuide(); const f2 = $('#bk-gcg'); f2.guide_alt.value = alts.guide_alt; f2.shapes_alt.value = alts.shapes_alt; }
		dirty = true;
	};

	document.addEventListener('change', async (e) => {
		const t = e.target;
		if (t.closest('#bk-gd, #bk-gcp, #bk-gcb, #bk-gcg')) dirty = true;
		if (t.name === 'scope' && t.closest('#bk-gd')) {
			$('#bk-gd-cats').hidden = t.value !== 'cats';
			$('#bk-gd-prods').hidden = t.value !== 'products';
		}
		const up = t.closest('[data-up]');
		if (up && up.files[0]) {
			const slot = up.closest('.bk-gc-slot');
			slot.classList.add('is-loading');
			try { setSlot(up.dataset.up, await uploadImage(up.files[0])); toast('Imagen lista: recuerda guardar'); } catch (er) { toast(er.message, 'err'); slot.classList.remove('is-loading'); }
		}
	});
	document.addEventListener('input', (e) => {
		const t = e.target;
		if (t.closest('#bk-gd, #bk-gcp, #bk-gcb, #bk-gcg')) dirty = true;
		if (t.matches('[data-psearch]')) searchProducts(t);
		if ((t.name === 'tmin' || t.name === 'tpct') && $('#bk-gd-tprev')) { readTiers(); $('#bk-gd-tprev').textContent = tierPreview(); }
	});
	// Pegar en el editor: solo texto.
	document.addEventListener('paste', (e) => {
		if (!e.target.closest?.('.bk-g-rich')) return;
		e.preventDefault();
		document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
	});

	document.addEventListener('submit', async (e) => {
		const f = e.target;
		if (f.id === 'bk-gd') { e.preventDefault(); saveRule(f.querySelector('[type="submit"]')); return; }
		if (f.id === 'bk-gcb') {
			e.preventDefault();
			readBanners();
			if (B.slides.some((s) => !s.pc && !s.mo)) { showErr(f, { message: 'Hay un banner sin imagen: sube al menos la de computador o quítalo.' }); return; }
			await busy(f.querySelector('[type="submit"]'), async () => {
				try {
					B = await api('content/banners', { method: 'POST', body: { slides: B.slides.map((s) => ({ pc: s.pc?.id || 0, mo: s.mo?.id || 0, link: s.link || '', alt: s.alt ?? '', from: s.from ?? null })), lines: Object.fromEntries(Object.entries(B.lines).map(([k, v]) => [k, v?.id || 0])) } });
					paintBanners(); toast('Banners guardados');
				} catch (er) { showErr(f, er); }
			});
			return;
		}
		if (f.id === 'bk-gcg') {
			e.preventDefault();
			await busy(f.querySelector('[type="submit"]'), async () => {
				G = await api('content/guide', { method: 'POST', body: { guide: G.guide?.id || 0, shapes: G.shapes?.id || 0, guide_alt: f.guide_alt.value, shapes_alt: f.shapes_alt.value } });
				paintGuide(); toast('Guía de tallas guardada');
			});
			return;
		}
		if (f.id === 'bk-gcp') {
			e.preventDefault();
			clearErr(f);
			const body = { id: Number(f.dataset.id), title: f.title.value.trim(), text: cleanHtml($('#bk-gc-text').innerHTML), cat: f.cat ? Number(f.cat.value) : 0, status: f.publish.checked ? 'publish' : 'draft' };
			await busy(f.querySelector('[type="submit"]'), async () => {
				try {
					P = { type: P.type, ...(await api(`content/posts/${P.type}/save`, { method: 'POST', body })) };
					dirty = false; toast('Guardado');
					location.hash = `#contenido/${P.type === 'faq' ? 'preguntas' : 'politicas'}`;
				} catch (er) { showErr(f, er); }
			});
		}
	});

	window.addEventListener('beforeunload', (e) => { if (dirty && ($('#bk-gd') || $('#bk-gcp') || $('#bk-gcb') || $('#bk-gcg'))) { e.preventDefault(); e.returnValue = ''; } });

	window.bkGMore = {
		view(tab, sub) {
			if (tab === 'descuentos') viewDiscounts(sub || []);
			else if (tab === 'contenido') viewContent(sub || []);
			else viewHelp();
		},
	};
})();
