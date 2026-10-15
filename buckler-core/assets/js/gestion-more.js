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
			: { ...base, name: 'Compra más y ahorra', tiers: [{ min: 2, pct: 10 }, { min: 3, pct: 15 }, { min: 4, pct: 20 }], count: 'cart' };
	};

	function paintList() {
		const card = (r, kind) => {
			const [l, cls] = stateLbl[r.state] || [r.state, ''];
			return `<li class="bk-gd-item">
				<div class="bk-gd-item__main"><b>${esc(r.name)}</b>
					<span class="bk-gd-item__pct">${kind === 'campana' ? `−${pctTxt(r.pct)} %` : r.tier_labels.map((t) => `${esc(t.label)}: −${t.pct} %`).join(' · ')}</span>
					<small>${esc(r.start_text)} → ${esc(r.end_text)} · ${esc(scopeTxt(r))}${r.exclude_sale ? ` · sin ${esc(D.saleCat || '¡Sale!')}` : ''}${kind === 'cantidad' ? ` · ${r.count === 'product' ? 'solo del mismo modelo' : 'combinando productos'}` : ''}</small></div>
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
								<label class="bk-gp-line"><input type="radio" name="count" value="cart" ${F.count !== 'product' ? 'checked' : ''}><span><b>Combinando productos (recomendado)</b><small>Suma todas las prendas de la promoción en el carrito. Ej.: 1 jean + 1 falda = 2 prendas</small></span></label>
								<label class="bk-gp-line"><input type="radio" name="count" value="product" ${F.count === 'product' ? 'checked' : ''}><span><b>Solo del mismo modelo</b><small>Ej.: 2 jeans iguales en distintas tallas</small></span></label></fieldset>`}
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
			F.count = f.querySelector('[name="count"]:checked')?.value || 'cart';
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
	 * Ayuda (centro de ayuda)
	 * ========================================================================== */
	// Cada tarea lleva una o más capturas limpias (tools/local/help-shots.js → assets/img/ayuda/*.webp) y sus marcas
	// en assets/img/ayuda/marks.json ({captura: {h, marks: [{n, x, y, w, h}]}} en % de la imagen). Los números de las
	// marcas son los números de los pasos: al pasar el cursor (o el foco) por un paso se ilumina su marca y al revés.
	const task = (t, shots, steps, tip = '') => ({ t, shots, steps, tip });
	const screen = (img, t, steps) => ({ img, t, steps });
	const MODULES = [
		{ k: 'pedidos', t: 'Pedidos', icon: 'orders', intro: 'Todos los pedidos de la tienda con búsqueda, filtros y las marcas de trabajo del día: pago confirmado, impreso y despachado.', tasks: [
			task('Atender los pedidos del día', ['g-pedidos-dia'], [
				'Toca <b>Buscar pedidos nuevos</b>. La lista también se revisa sola cada 10 minutos y los nuevos aparecen resaltados.',
				'Cuando verifiques el pago (Wompi, Bold, Addi o transferencia), marca <b>Pago confirmado</b>.',
				'Toca <b>Imprimir</b>: sale la hoja con la clienta, la dirección y las prendas con talla y REF. El pedido queda como <b>Impreso</b>.',
				'Empaca con la hoja y, cuando salga a la transportadora, marca <b>Despachado</b>.',
				'Toca el <b>número del pedido</b> para abrirlo y enviar la guía (siguiente tarea).',
			], 'Con el filtro <b>Impresión → Sin imprimir</b> ves solo los que faltan.'),
			task('Enviar la guía de envío', ['g-guia'], [
				'Elige la <b>transportadora</b>.',
				'Escribe el <b>número de guía</b>.',
				'Deja marcada la casilla para que el pedido pase a <b>Completado</b>.',
				'Toca <b>Ver correo antes de enviar</b>, revisa el correo y toca <b>Confirmar y enviar</b>.',
			], 'La clienta recibe un correo con la guía y también la ve en Mi cuenta.'),
			task('Buscar un pedido', ['g-buscar'], [
				'Escribe el número (3846 o #3846), el consecutivo (PEDIDO-3841), la cédula, el nombre, el correo o el celular.',
				'Acota con los filtros: estado, pago, impresión, despacho y fechas.',
			]),
			task('Cambiar el estado de un pedido', ['g-estado'], [
				'Abre el pedido y elige el <b>Estado</b>: En espera (falta el pago), Procesando (pagado), Completado (enviado), Cancelado o Reembolsado.',
				'Si hace falta, ajusta las marcas de pago, impreso y despachado.',
				'Toca <b>Guardar estado</b>. Queda en el historial con tu nombre y la hora.',
			], 'Al cancelar, las unidades vuelven al stock. La clienta recibe el correo de ese estado si está activo (míralo en Correos).'),
			task('Corregir los datos de la clienta', ['g-cliente'], [
				'En <b>Clienta</b> toca <b>Editar datos</b>.',
				'Corrige nombre, cédula, celular, correo, departamento, ciudad o dirección.',
				'Si el envío va a otra dirección, desmarca <b>Enviar a la misma dirección</b> y escribe los datos de envío.',
				'Toca <b>Guardar datos</b>. El cambio queda en el historial y en las notas.',
			], 'Solo cambia ese pedido (no la cuenta de la clienta). Si ya lo imprimiste, vuelve a imprimirlo.'),
			task('Adjuntar un comprobante o factura', ['g-archivos'], [
				'Abre el pedido y baja hasta <b>Archivos del pedido</b>.',
				'Toca <b>Subir archivo</b> y elige el PDF o la foto (hasta 15 MB). En la lista de pedidos se ve 📎 con la cantidad.',
			]),
			task('Escribirle a la clienta', ['g-escribir', 'g-escribir-2'], [
				'Toca <b>WhatsApp</b> junto al celular: se abre el chat con su número.',
				'O escribe una nota en <b>Notas</b>…',
				'…marca <b>Enviar a la clienta</b> para que le llegue por correo…',
				'…y toca <b>Añadir nota</b>. Queda guardada en el pedido.',
			]),
		], screens: [
			screen('pedidos', 'La lista de pedidos', [
				'<b>Buscar</b> por número, consecutivo, cédula, nombre, correo o celular.',
				'<b>Filtros:</b> estado, pago confirmado, impreso, despachado y fechas.',
				'<b>Número del pedido:</b> abre el detalle a la derecha.',
				'<b>Pago confirmado, Impreso y Despachado</b> se guardan al instante.',
				'<b>Imprimir</b> la hoja del pedido (queda como "Impreso").',
			]),
			screen('pedido', 'El detalle del pedido', [
				'<b>Estado</b> del pedido.', '<b>Marcas</b> de pago, impreso y despachado.', '<b>Guardar estado</b> e <b>Imprimir</b>.',
				'<b>Guía de envío</b> con vista previa del correo.', '<b>Clienta:</b> cédula, celular con WhatsApp, correo y dirección (con "Editar datos").',
			]),
			screen('pedido-historial', 'Archivos, historial y notas', [
				'<b>Archivos del pedido</b> (los mismos de ACF "Archivos Pedidos").', '<b>Historial:</b> qué pasó, cuándo y quién.', '<b>Notas</b> internas o para la clienta.',
			]),
		] },
		{ k: 'productos', t: 'Productos', icon: 'prod', intro: 'Crea y edita productos, precios, stock por talla y fotos sin entrar a WordPress.', tasks: [
			task('Subir un producto nuevo', ['g-prod-nuevo-1', 'g-prod-nuevo-2'], [
				'En <b>Productos</b> toca <b>Nuevo producto</b> y escribe el <b>Nombre</b> como se verá en la tienda.',
				'Escribe la <b>REF</b>. En Línea Curvy termina en PLUS (816 PLUS).',
				'Elige la <b>Línea</b>: Slim (6 a 12) o Curvy (14 a 24).',
				'Elige la <b>Prenda</b> (jeans, faldas, chaquetas…).',
				'Si es lanzamiento, activa <b>Mostrar como Nuevo</b>.',
				'Escribe el <b>Precio normal</b> (el rebajado solo si está en ¡Sale!).',
				'En <b>Tallas y stock</b> marca las tallas y escribe las unidades de cada una.',
				'Toca <b>Agregar fotos</b> y elige todas a la vez. La primera es la principal.',
				'Escribe la <b>Descripción corta</b> (junto al precio) y la completa (tela, tiro, bota, cuidados).',
				'Toca <b>Publicar</b>, o <b>Guardar borrador</b> para terminarlo después.',
			], 'Usa fotos verticales del mismo tamaño. Se optimizan y se enderezan solas al subirlas.'),
			task('Fotos: ordenar, girar y quitar', ['g-fotos'], [
				'<b>Agregar fotos</b>: puedes elegir varias a la vez.',
				'Con las <b>flechas</b> cambias el orden. La primera es la principal.',
				'Si una quedó acostada, usa <b>Girar a la izquierda / derecha</b>: se gira al instante en la tienda.',
				'La <b>papelera</b> quita la foto del producto.',
				'Toca <b>Guardar cambios</b> para guardar el orden y las fotos quitadas.',
			]),
			task('Crear el mismo modelo en la otra línea', ['g-otra-linea', 'g-otra-linea-2'], [
				'En el producto ya creado toca <b>Crear este modelo en Línea Curvy</b> (o Slim).',
				'Se copia la base: nombre, REF (con o sin PLUS), prenda, precio y descripción.',
				'Revisa el <b>nombre</b> y la <b>REF</b>.',
				'Escribe el <b>stock</b> de cada talla, sube las fotos de esa línea y toca <b>Publicar</b>.',
			], 'En la ficha de cada uno sale el enlace "ver este modelo en la otra línea".'),
			task('Cambiar precio o poner en ¡Sale!', ['g-precio'], [
				'En la lista toca <b>Tallas y precios</b>.',
				'Cambia el <b>Precio normal</b> de la talla…',
				'…o escribe el <b>Precio rebajado</b> para ponerla en ¡Sale! (vacío = sin rebaja).',
				'Toca <b>Guardar</b> en esa fila.',
			], 'Para una promoción por fechas en muchos productos usa una campaña en Descuentos: no hay que tocar precios y se apaga sola.'),
			task('Actualizar el stock de una talla', ['g-stock'], [
				'Busca el producto por nombre o REF.',
				'Toca <b>Tallas y precios</b>.',
				'Escribe las unidades en <b>Stock</b> (0 = agotada).',
				'Toca <b>Guardar</b> en esa fila.',
			], 'Las ventas descuentan el stock solas. En Resumen ves las tallas que se están agotando.'),
			task('Marcar o quitar "Nuevo"', ['g-nuevo'], [
				'Toca la <b>estrella</b>: el producto lleva la etiqueta NUEVO y sale en "Lo nuevo". Tócala otra vez para quitarlo.',
			]),
			task('Ocultar un producto sin borrarlo', ['g-ocultar'], [
				'En <b>Editar</b> → <b>Estado</b> elige <b>Borrador (oculto)</b>.',
				'Toca <b>Guardar cambios</b>.',
			]),
		], screens: [
			screen('productos', 'La lista de productos', [
				'<b>Buscar</b> por nombre o REF y filtrar.', '<b>Estrella</b> = Nuevo.', '<b>Tallas y precios</b> sin abrir el producto.', '<b>Editar</b> el producto completo.', '<b>Nuevo producto</b>.',
			]),
			screen('producto-editar', 'Editar producto', [
				'<b>Nombre y REF.</b>', '<b>Prenda</b> (y "Todas las categorías").', '<b>Mostrar como Nuevo.</b>', '<b>Precio normal y rebajado.</b>', '<b>Tallas y stock.</b>',
			]),
		] },
		{ k: 'descuentos', t: 'Descuentos', icon: 'percent', intro: 'Campañas por porcentaje y descuentos por cantidad que se encienden y se apagan solos en las fechas que elijas. No se suman: a cada prenda se le aplica el más alto.', tasks: [
			task('Crear una campaña (ej.: 20 % el fin de semana)', ['g-camp-1', 'g-camp-2'], [
				'En <b>Descuentos</b> toca <b>Nueva campaña</b>.',
				'Escribe el <b>Nombre</b>: se ve en la barra de arriba y en las fichas.',
				'Escribe el <b>Porcentaje</b>.',
				'Elige <b>Desde</b> y <b>Hasta</b> (hora de Colombia).',
				'En <b>A qué productos</b> elige toda la tienda o categorías (¡Sale! queda fuera).',
				'Deja marcado <b>Activo</b> y toca <b>Guardar</b> al final del formulario.',
			], 'Para revisarla antes de que la vean las clientas, ponle una fecha de inicio futura.'),
			task('Crear un descuento por cantidad', ['g-cant-1', 'g-cant-2'], [
				'Toca <b>Nuevo descuento por cantidad</b>.',
				'Escribe el nombre y las fechas.',
				'En los tramos pon desde cuántas prendas y el porcentaje (ej.: 2 prendas −10 %).',
				'<b>Agregar tramo</b> para el siguiente (ej.: 3 o más −15 %).',
				'En <b>Cómo se cuentan</b> deja "Combinando productos" (suma cualquier prenda) y toca <b>Guardar</b>.',
			], 'En el carrito la clienta ve cuántas prendas le faltan para el siguiente tramo.'),
			task('Pausar, editar o borrar un descuento', ['g-desc-lista'], [
				'<b>Pausar</b> lo apaga sin borrarlo; <b>Activar</b> lo enciende otra vez.',
				'<b>Editar</b> cambia porcentaje, fechas o productos.',
				'La <b>papelera</b> lo borra.',
			], 'Si ves "Los descuentos están apagados en la tienda", toca <b>Encender descuentos</b>.'),
		], screens: [
			screen('descuentos-tienda', 'Así lo ve la clienta', [
				'<b>Anuncio</b> en la barra de arriba mientras dura la campaña.', '<b>Precio tachado</b> y precio con descuento.', '<b>Recuadro</b> con la campaña y los tramos por cantidad.',
			]),
		] },
		{ k: 'carritos', t: 'Carritos abandonados', icon: 'cart', intro: 'Clientas que escribieron su correo o celular en Finalizar compra y no terminaron. Reciben hasta 3 recordatorios (2, 24 y 48 h) con un enlace que les devuelve el carrito.', tasks: [
			task('Recuperar un carrito', ['g-carritos'], [
				'Toca <b>Actualizar listado</b> para ver los más recientes.',
				'Mira el <b>Estado</b>: Pendiente (recibe recordatorios), Recuperado (compró desde el correo), Compró, Vació el carrito o Vencido.',
				'Toca el <b>celular</b> para escribirle por WhatsApp con su nombre y lo que dejó.',
			]),
		], screens: [
			screen('carritos', 'La pantalla de carritos', [
				'<b>Indicadores</b> de los últimos 30 días.', '<b>Filtro</b> por estado.', '<b>Clienta:</b> nombre, correo, celular, cédula y ciudad.', '<b>Estado</b> de cada carrito.',
			]),
		] },
		{ k: 'correos', t: 'Correos', icon: 'mail', intro: 'Mira cómo le llega cada correo a la clienta con un pedido real. La vista previa no envía nada.', tasks: [
			task('Revisar un correo antes de que llegue', ['g-correos', 'g-correos-2'], [
				'Elige un <b>pedido de ejemplo</b>.',
				'Elige el <b>correo</b> (pedido en proceso, completado, guía, carrito abandonado…).',
				'Revisa la <b>vista previa</b>.',
				'Para verlo en tu bandeja, escribe tu correo y toca <b>Enviar prueba</b> (llega con "[Prueba]").',
			]),
		], screens: [] },
		{ k: 'contenido', t: 'Contenido', icon: 'layout', intro: 'Banners, guía de tallas, políticas y preguntas frecuentes. Se guarda en los mismos campos de ACF y la tienda se actualiza sola.', tasks: [
			task('Cambiar los banners del inicio', ['g-banners', 'g-banners-2'], [
				'Entra a <b>Contenido → Banners</b>.',
				'Sube la imagen para <b>computador</b> (1920 × 1080).',
				'Sube la imagen para <b>celular</b> (1200 × 1200).',
				'Escribe el <b>Texto de la imagen</b>: lo que dice el banner (lo leen Google y los lectores de pantalla).',
				'Escribe el <b>Enlace</b> al que lleva el banner.',
				'Ordénalos con las flechas o quítalos con la papelera, y toca <b>Guardar banners</b> al final.',
			], 'El primer banner es el que carga más rápido: úsalo para lo más importante.'),
			task('Cambiar la guía de tallas', ['g-tallas', 'g-tallas-2'], [
				'Entra a <b>Contenido → Guía de tallas</b>.',
				'Toca <b>Cambiar</b> y sube la imagen nueva.',
				'Escribe el texto de la imagen.',
				'Toca <b>Guardar guía</b>.',
			]),
			task('Editar una pregunta frecuente', ['g-faq-1', 'g-faq-2'], [
				'Entra a <b>Contenido → Preguntas frecuentes</b>.',
				'Para una nueva toca <b>Nueva pregunta</b>…',
				'…o toca <b>Editar</b> en la que quieras cambiar.',
				'Escribe la <b>pregunta</b>.',
				'Escribe la <b>respuesta</b> (negrita y listas con la barra).',
				'Desmarca <b>Publicada</b> si quieres dejarla en borrador.',
				'Toca <b>Guardar</b>.',
			], 'Las políticas se editan igual en la pestaña Políticas.'),
		], screens: [] },
		{ k: 'seo', t: 'SEO y ADS', icon: 'chart', intro: 'De dónde llegan las visitas y si las etiquetas de Google y Meta están bien puestas. Las visitas se cuentan sin cookies y sin contar robots ni al equipo.', tasks: [
			task('Revisar las visitas', ['seo'], [
				'¿Falta una etiqueta? Escribe a <a href="mailto:contacto@ufoweb.co">contacto@ufoweb.co</a> con el ID y qué debe medir.',
				'Pestañas: Visitas, Etiquetas Google y Meta, y Clases y eventos rastreables.',
				'<b>Visitantes</b> de hoy, 7 y 30 días.',
				'<b>Periodo</b> del gráfico. Debajo: páginas más vistas, de dónde llegan, campañas, ciudades y dispositivos.',
			]),
			task('Revisar las etiquetas de Google y Meta', ['seo-etiquetas'], [
				'Abre <b>Etiquetas Google y Meta</b>.',
				'Toca <b>Revisar ahora</b> después de un cambio en GTM o Meta.',
				'Mira qué etiquetas hay, cuáles faltan y si alguna está duplicada.',
			]),
		], screens: [] },
		{ k: 'resumen', t: 'Resumen', icon: 'home', intro: 'La primera pantalla: cómo va la venta y qué hay por hacer hoy.', tasks: [], screens: [
			screen('resumen', 'El resumen', [
				'<b>Menú</b> de secciones (en pantallas medianas, pasa el cursor para ver el nombre).', '<b>Ventas pagadas</b> de hoy y del mes.', '<b>Pedidos por atender</b>: toca uno para verlos.',
				'<b>Tallas por agotarse</b> (1 o 2 unidades).', '<b>Ver tienda</b> y <b>Salir</b>.',
			]),
		] },
		{ k: 'problemas', t: 'Si algo no funciona', icon: 'help', intro: 'Respuestas rápidas a lo que más se pregunta.', faq: [
			['Hice un cambio y no lo veo en la tienda', 'La tienda guarda las páginas para que carguen rápido y se actualizan solas al guardar. Recarga con <b>Ctrl + F5</b> (en el celular cierra y abre la pestaña) o espera un minuto.'],
			['¿Por dónde entro?', 'Siempre por <b>buckler.com.co/ingreso/</b> o <b>buckler.com.co/gestion-tienda/</b> con tu usuario y contraseña. Por seguridad, wp-admin lleva al inicio si no has iniciado sesión.'],
			['Olvidé mi contraseña', 'En la pantalla de acceso toca <b>¿Olvidaste tu contraseña?</b> y escribe tu correo: te llega un enlace para crear una nueva.'],
			['Una foto se ve acostada', 'Abre el producto y toca <b>Girar a la derecha</b> o <b>a la izquierda</b> sobre esa foto. El giro se guarda al instante.'],
			['Un descuento no aparece', 'Revisa que esté <b>Activo</b>, que la fecha de inicio ya haya pasado y que el producto no esté en ¡Sale!. Si ves "Los descuentos están apagados", toca <b>Encender descuentos</b>.'],
			['Soporte', 'Escribe a <a href="mailto:contacto@ufoweb.co">contacto@ufoweb.co</a> con una captura de pantalla de lo que ves.'],
		] },
	];

	let MARKS = null;
	const loadMarks = async () => {
		if (MARKS) return MARKS;
		try { MARKS = await (await fetch(`${C.helpImg}marks.json?v=${encodeURIComponent(C.ver)}`)).json(); } catch { MARKS = {}; }
		return MARKS;
	};
	const shotHtml = (name, title, i = 0) => {
		const m = MARKS[name] || { h: 800, marks: [] };
		const src = `${C.helpImg}${name}.webp?v=${encodeURIComponent(C.ver)}`;
		return `<figure class="bk-g-shot">
			<button type="button" class="bk-g-shot__zoom" data-hzoom aria-label="Ampliar la imagen${i ? ` ${i + 1}` : ''}: ${esc(title)}">
				<span class="bk-g-shot__frame" style="aspect-ratio:1280 / ${m.h}"><img src="${esc(src)}" width="1280" height="${m.h}" loading="lazy" decoding="async" alt="Pantalla: ${esc(title)}">
				${m.marks.map((k) => `<span class="bk-g-mk" data-n="${k.n}" style="left:${k.x}%;top:${k.y}%;width:${k.w}%;height:${k.h}%"><b class="${k.r ? 'is-r' : ''}">${k.n}</b></span>`).join('')}</span>
				<span class="bk-g-shot__hint">${C.icons.search} Ampliar</span></button></figure>`;
	};
	const stepsHtml = (steps) => `<ol class="bk-g-hsteps">${steps.map((s, i) => `<li data-n="${i + 1}">${s}</li>`).join('')}</ol>`;
	const taskHtml = (m, x, i) => `<details class="bk-g-task" id="ayuda-${m.k}-${i}">
		<summary><span class="bk-g-task__n">${i + 1}</span><span class="bk-g-task__t">${esc(x.t)}</span><small>${x.steps.length} paso${x.steps.length === 1 ? '' : 's'}</small></summary>
		<div class="bk-g-task__body">
			<div class="bk-g-task__steps">${stepsHtml(x.steps)}${x.tip ? `<p class="bk-g-htip">${C.icons.info}<span>${x.tip}</span></p>` : ''}</div>
			<div class="bk-g-task__shots">${x.shots.map((s, j) => shotHtml(s, x.t, j)).join('')}</div>
		</div></details>`;
	const screenHtml = (s) => `<details class="bk-g-task is-screen">
		<summary><span class="bk-g-task__n">${C.icons.eye}</span><span class="bk-g-task__t">Conoce la pantalla: ${esc(s.t)}</span></summary>
		<div class="bk-g-task__body"><div class="bk-g-task__steps">${stepsHtml(s.steps)}</div><div class="bk-g-task__shots">${shotHtml(s.img, s.t)}</div></div></details>`;
	const moduleHtml = (m) => `<section class="bk-g-hmod" id="ayuda-${m.k}" aria-labelledby="ayuda-${m.k}-t">
		<header class="bk-g-hmod__head"><span class="bk-g-hmod__ico">${C.icons[m.icon] || ''}</span><div><h2 id="ayuda-${m.k}-t">${esc(m.t)}</h2><p>${m.intro}</p></div></header>
		${m.tasks?.length ? `<h3 class="bk-g-hgroup">Cómo hacerlo</h3>${m.tasks.map((x, i) => taskHtml(m, x, i)).join('')}` : ''}
		${m.screens?.length ? `<h3 class="bk-g-hgroup">Conoce la pantalla</h3>${m.screens.map(screenHtml).join('')}` : ''}
		${m.faq ? m.faq.map(([q, a]) => `<details class="bk-g-task is-faq"><summary><span class="bk-g-task__n">?</span><span class="bk-g-task__t">${esc(q)}</span></summary><div class="bk-g-task__body is-text"><p>${a}</p></div></details>`).join('') : ''}
	</section>`;

	async function viewHelp() {
		const mods = C.scope === 'seo' ? MODULES.filter((m) => m.k === 'seo' || m.k === 'problemas') : MODULES;
		main.innerHTML = '<div class="bk-g-loading">Cargando ayuda…</div>';
		await loadMarks();
		const n = mods.reduce((a, m) => a + (m.tasks?.length || 0), 0);
		main.innerHTML = `<div class="bk-g-hhero"><h1 class="bk-g-h1">Centro de ayuda</h1>
				<p>${n} guías paso a paso con la pantalla real. Pasa el cursor por un paso para ver dónde está en la imagen; toca la imagen para ampliarla.</p>
				<label class="bk-g-hsearch"><span class="bk-g-sr">Buscar en la ayuda</span>${C.icons.search}<input type="search" id="bk-g-hq" placeholder="¿Qué quieres hacer? Ej.: guía, descuento, foto, banner" autocomplete="off"></label>
				<p class="bk-g-hint" id="bk-g-hq-n" role="status" aria-live="polite"></p></div>
			<div class="bk-g-helpwrap">
				<nav class="bk-g-hside" aria-label="Secciones de la ayuda"><ul>${mods.map((m) => `<li><a href="#ayuda" data-help="${m.k}">${C.icons[m.icon] || ''}<span>${esc(m.t)}</span>${m.tasks?.length ? `<small>${m.tasks.length}</small>` : ''}</a></li>`).join('')}</ul></nav>
				<div class="bk-g-help">${mods.map(moduleHtml).join('')}</div>
			</div>
			<dialog class="bk-g-lightbox" id="bk-g-lightbox" aria-label="Imagen ampliada"><button type="button" class="bk-g-icon bk-g-lightbox__x" data-hclose aria-label="Cerrar">${C.icons.x}</button><div class="bk-g-lightbox__body"></div></dialog>`;
		$('.bk-g-task')?.setAttribute('open', '');
	}
	// Paso ↔ marca: se iluminan juntos dentro de la misma tarea.
	const hot = (el, on) => {
		const box = el.closest('.bk-g-task, .bk-g-lightbox');
		const n = el.dataset.n;
		if (!box || !n) return;
		box.querySelectorAll('.is-hot').forEach((x) => x.classList.remove('is-hot'));
		if (on) box.querySelectorAll(`[data-n="${n}"]`).forEach((x) => x.classList.add('is-hot'));
	};
	['mouseover', 'focusin'].forEach((ev) => document.addEventListener(ev, (e) => { const el = e.target.closest?.('.bk-g-hsteps li[data-n], .bk-g-mk[data-n]'); if (el) hot(el, true); }));
	['mouseout', 'focusout'].forEach((ev) => document.addEventListener(ev, (e) => { const el = e.target.closest?.('.bk-g-hsteps li[data-n], .bk-g-mk[data-n]'); if (el) hot(el, false); }));

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
			if (d) { d.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); d.querySelector('summary')?.focus({ preventScroll: true }); }
			return;
		}
		// Ayuda: ampliar una captura (con sus marcas) y cerrar.
		const zm = t.closest('[data-hzoom]');
		if (zm) {
			const dlg = $('#bk-g-lightbox');
			const task = zm.closest('.bk-g-task');
			task?.querySelectorAll('.is-hot').forEach((x) => x.classList.remove('is-hot'));
			$('.bk-g-lightbox__body', dlg).innerHTML = `<div class="bk-g-lightbox__shot">${zm.querySelector('.bk-g-shot__frame').outerHTML}</div>${task ? `<div class="bk-g-lightbox__steps"><h2>${task.querySelector('.bk-g-task__t').innerHTML}</h2>${task.querySelector('.bk-g-hsteps').outerHTML}</div>` : ''}`;
			dlg.showModal();
			return;
		}
		if (t.closest('[data-hclose]') || t.matches?.('.bk-g-lightbox')) { $('#bk-g-lightbox')?.close(); return; }

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
		if (t.id === 'bk-g-hq') helpSearch(t.value);
	});
	// Ayuda: buscar sin tildes en las guías y en las figuras; abre lo que coincide y oculta el resto.
	const fold = (x) => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
	function helpSearch(q) {
		const words = fold(q).split(/\s+/).filter((w) => w.length > 1);
		let n = 0;
		document.querySelectorAll('.bk-g-hmod').forEach((sec) => {
			let any = false;
			sec.querySelectorAll('.bk-g-task').forEach((el) => {
				const hit = !words.length || words.every((w) => fold(el.textContent).includes(w));
				el.hidden = !hit;
				if (words.length) el.open = hit && n < 3; // Abre los primeros resultados.
				if (hit && words.length) { any = true; n++; }
			});
			sec.querySelectorAll('.bk-g-hgroup').forEach((h) => {
				let el = h.nextElementSibling; let vis = false;
				while (el && !el.matches('.bk-g-hgroup')) { if (!el.hidden) vis = true; el = el.nextElementSibling; }
				h.hidden = !vis;
			});
			sec.hidden = !!words.length && !any;
		});
		const out = $('#bk-g-hq-n');
		if (out) out.textContent = words.length ? (n ? `${n} resultado${n === 1 ? '' : 's'}` : 'Sin resultados. Prueba con otra palabra o escribe a contacto@ufoweb.co.') : '';
	}
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
