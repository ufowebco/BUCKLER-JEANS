/**
 * Gestión de tienda: pedido manual (#pedidos/nuevo). Para clientas especiales o ventas por WhatsApp: se eligen las
 * prendas (buscador por nombre o REF, tallas con lo que queda), los datos de la clienta, el medio de pago y el estado.
 * Al guardar, el pedido descuenta el inventario (las prendas quedan reservadas).
 * También "Cambiar prendas" de un pedido (#pedidos/prendas/ID): quitar, cambiar talla o agregar prendas; al confirmar
 * se pregunta si cada prenda que sale vuelve al inventario y se avisa si el total cambia (sin correo a la clienta).
 * Depende de window.bkGApp (gestion.js). Datos: bk/v1/gestion/manual/products, orders/manual, orders/{id}/items.
 */
(() => {
	'use strict';

	const A = window.bkGApp;
	if (!A) return;
	const { C, api, esc, $, $$, toast, main } = A;

	const money = (n) => `$${Math.round(Number(n) || 0).toLocaleString('es-CO')}`;
	const digits = (v) => String(v ?? '').replace(/\D/g, '');
	let lines = []; // { id, name, size, sku, img, qty, price, base, stock, max, item?, origQty? }
	let mode = 'new'; // 'new' = pedido manual · 'edit' = cambiar prendas de un pedido
	let edit = null; // { id, number, total, shipping, holds }
	let removed = []; // Líneas del pedido que salen (modo edit).
	let pending = null; // Vista previa del cambio (modo edit).
	let found = [];
	let timer = 0;
	let reqSeq = 0;

	const STATUS = [
		['pending', 'Reservado, sin pagar', 'Queda en "Pendiente de pago". Las prendas se reservan hasta que lo pagues o lo canceles.'],
		['on-hold', 'En espera', 'Para revisar el pago (por ejemplo, una transferencia por confirmar).'],
		['processing', 'Pagado', 'Queda "En proceso", listo para despachar.'],
	];

	const changed = () => mode === 'edit' ? (removed.length > 0 || lines.some((l) => !l.item || l.qty !== l.origQty)) : lines.length > 0;
	const leaveGuard = (e) => { if (changed()) { e.preventDefault(); e.returnValue = ''; } };

	function view() {
		A.closeDrawer?.(); // Si venía de ver un pedido, el panel lateral no queda encima del formulario.
		mode = 'new';
		edit = null;
		removed = [];
		lines = [];
		found = [];
		window.addEventListener('beforeunload', leaveGuard);
		const states = C.coStates || {};
		const pays = C.manualPayments || {};
		main.innerHTML = `<div class="bk-g-h1row"><h1 class="bk-g-h1">Nuevo pedido manual</h1><a class="bk-g-link" href="#pedidos">← Volver a pedidos</a></div>
			<p class="bk-g-hint bk-gm-lead">Para clientas especiales o ventas por WhatsApp. <b>Las prendas quedan reservadas apenas guardes</b> (se descuentan del inventario); si cancelas el pedido, vuelven solas.</p>
			<form class="bk-gm" id="bk-gm" novalidate>
				<section class="bk-g-card" aria-labelledby="bk-gm-h1">
					<h2 id="bk-gm-h1">1. Prendas</h2>
					<label class="bk-g-search bk-gm-search"><span class="bk-g-sr">Buscar prenda por nombre o REF</span>${C.icons.search}
						<input type="search" id="bk-gm-q" placeholder="Busca por nombre o REF (ej. 9898, falda, flare)" autocomplete="off" aria-describedby="bk-gm-qhint"></label>
					<p class="bk-g-hint" id="bk-gm-qhint">Toca una talla para agregarla. Las tallas agotadas no se pueden elegir.</p>
					<div class="bk-gm-results" id="bk-gm-results" aria-live="polite"></div>
					<div id="bk-gm-lines"></div>
					<p class="bk-g-sr" id="bk-gm-live" role="status" aria-live="polite"></p>
				</section>
				<section class="bk-g-card" aria-labelledby="bk-gm-h2">
					<h2 id="bk-gm-h2">2. Clienta</h2>
					<div class="bk-g-custgrid">
						<label>Nombre *<input name="first_name" autocomplete="off" required></label>
						<label>Apellidos<input name="last_name" autocomplete="off"></label>
						<label>Celular *<input name="phone" type="tel" inputmode="tel" autocomplete="off" required placeholder="10 dígitos"></label>
						<label>Correo<input name="email" type="email" inputmode="email" autocomplete="off" placeholder="Opcional"></label>
						<label>Cédula<input name="id_number" inputmode="numeric" autocomplete="off"></label>
						<label>Departamento<select name="state"><option value="">Elige…</option>${Object.entries(states).map(([k, n]) => `<option value="${esc(k)}">${esc(n)}</option>`).join('')}</select></label>
						<label>Ciudad<input name="city" autocomplete="off"></label>
						<label>Dirección<input name="address_1" autocomplete="off"></label>
						<label class="is-wide">Apartamento, barrio o indicaciones<input name="address_2" autocomplete="off"></label>
					</div>
				</section>
				<section class="bk-g-card" aria-labelledby="bk-gm-h3">
					<h2 id="bk-gm-h3">3. Pago y estado</h2>
					<div class="bk-g-custgrid">
						<label>Medio de pago<select name="payment">${Object.entries(pays).map(([k, n]) => `<option value="${esc(k)}">${esc(n)}</option>`).join('')}</select></label>
						<label>Costo de envío<input name="shipping" inputmode="numeric" autocomplete="off" placeholder="$0 (gratis)"></label>
					</div>
					<fieldset class="bk-gm-status"><legend>¿Cómo queda el pedido?</legend>
						${STATUS.map(([k, t, d], i) => `<label class="bk-gm-opt"><input type="radio" name="status" value="${k}" ${i === 0 ? 'checked' : ''}><span><b>${t}</b><small>${d}</small></span></label>`).join('')}
					</fieldset>
					<label class="bk-gm-check"><input type="checkbox" name="notify" disabled><span>Enviar correo de confirmación a la clienta <small>(necesita correo; solo para "En espera" o "Pagado")</small></span></label>
					<label class="bk-g-field bk-gm-note">Nota interna (solo la ve el equipo)<textarea name="note" rows="2" placeholder="Ej. clienta especial, recoge en bodega, paga el viernes…"></textarea></label>
				</section>
				<div class="bk-gm-foot">
					<div class="bk-gm-total" id="bk-gm-total" aria-live="polite"></div>
					<p class="bk-gm-err" role="alert" id="bk-gm-err"></p>
					<button type="submit" class="bk-g-btn" id="bk-gm-save">Crear pedido y reservar prendas</button>
				</div>
			</form>`;
		paint();
		$('#bk-gm-q').focus();
	}

	const stockText = (s) => (s.stock === null ? 'disponible' : s.stock === 0 ? 'agotada' : `quedan ${s.stock}`);
	// Unidades de esa talla que salen del inventario por este formulario (en un cambio, las que el pedido ya tenía
	// apartadas no cuentan).
	const inCart = (id) => lines.filter((l) => l.id === id).reduce((a, l) => a + (l.item && edit?.holds ? Math.max(0, l.qty - l.origQty) : l.qty), 0);
	const lineMax = (l) => (l.max === undefined ? l.stock : l.max);

	function paintResults() {
		const box = $('#bk-gm-results');
		if (!box) return;
		const q = $('#bk-gm-q').value.trim();
		if (q.length < 2) { box.innerHTML = ''; return; }
		if (!found.length) { box.innerHTML = '<p class="bk-g-empty">No encontramos prendas con ese nombre o REF.</p>'; return; }
		box.innerHTML = `<ul class="bk-gm-list">${found.map((p) => `<li class="bk-gm-prod">
			${p.img ? `<img src="${esc(p.img)}" alt="" width="48" height="48" loading="lazy">` : '<span class="bk-gm-noimg" aria-hidden="true"></span>'}
			<div><b>${esc(p.name)}</b>${p.sku ? `<small>REF ${esc(p.sku)}</small>` : ''}
				<div class="bk-gm-sizes" role="group" aria-label="Tallas de ${esc(p.name)}">${p.sizes.map((s) => {
					const left = s.stock === null ? Infinity : s.stock - inCart(s.id);
					return `<button type="button" class="bk-gm-size" data-add="${s.id}" data-p="${p.id}" ${left <= 0 ? 'disabled' : ''} aria-label="Agregar talla ${esc(s.label)}, ${esc(stockText(s))}, ${esc(s.money)}">${esc(s.label)}<small>${esc(stockText(s))}</small></button>`;
				}).join('')}</div></div></li>`).join('')}</ul>`;
	}

	function paintLines() {
		const box = $('#bk-gm-lines');
		if (!box) return;
		if (!lines.length && !removed.length) { box.innerHTML = '<p class="bk-g-empty bk-gm-empty">Todavía no hay prendas en el pedido.</p>'; return; }
		box.innerHTML = `<h3 class="bk-gm-sub">${mode === 'edit' ? 'Queda en el pedido' : 'En el pedido'}</h3>` + (lines.length ? '' : '<p class="bk-g-empty bk-gm-empty">El pedido debe quedar con al menos una prenda.</p>') + `<ul class="bk-gm-lines">${lines.map((l, i) => `<li class="bk-gm-line">
			${l.img ? `<img src="${esc(l.img)}" alt="" width="44" height="44">` : '<span class="bk-gm-noimg" aria-hidden="true"></span>'}
			<div class="bk-gm-line__name"><b>${esc(l.name)}</b><small>Talla ${esc(l.size)}${l.sku ? ` · REF ${esc(l.sku)}` : ''}${l.item ? ' · ya estaba en el pedido' : (l.stock !== null ? ` · quedan ${l.stock}` : '')}</small>
				${l.item && l.sku ? `<button type="button" class="bk-gm-swap" data-swap="${i}">Cambiar talla o prenda</button>` : ''}</div>
			${l.item ? `<div class="bk-gm-price"><span>Precio c/u</span><b>${money(l.price)}</b></div>` : `<label class="bk-gm-price"><span>Precio c/u</span><input inputmode="numeric" data-price="${i}" value="${Math.round(l.price).toLocaleString('es-CO')}" aria-label="Precio de ${esc(l.name)} talla ${esc(l.size)}"></label>`}
			<div class="bk-gm-qty" role="group" aria-label="Cantidad de ${esc(l.name)} talla ${esc(l.size)}">
				<button type="button" data-qty="${i}" data-d="-1" aria-label="Quitar una">−</button><output>${l.qty}</output>
				<button type="button" data-qty="${i}" data-d="1" aria-label="Agregar una" ${lineMax(l) !== null && l.qty >= lineMax(l) ? 'disabled' : ''}>+</button></div>
			<b class="bk-gm-sum">${money(l.price * l.qty)}</b>
			<button type="button" class="bk-gm-del" data-del="${i}" aria-label="Quitar ${esc(l.name)} talla ${esc(l.size)} del pedido">${C.icons.trash || '×'}</button>
		</li>`).join('')}</ul>${removed.length ? `<h3 class="bk-gm-sub">Sale del pedido</h3><ul class="bk-gm-lines bk-gm-out">${removed.map((l, i) => `<li class="bk-gm-line is-out">
			${l.img ? `<img src="${esc(l.img)}" alt="" width="44" height="44">` : '<span class="bk-gm-noimg" aria-hidden="true"></span>'}
			<div class="bk-gm-line__name"><b>${esc(l.name)}</b><small>Talla ${esc(l.size)} · ${l.origQty} prenda${l.origQty === 1 ? '' : 's'}</small></div>
			<button type="button" class="bk-g-btn is-ghost is-small" data-undo="${i}">Deshacer</button></li>`).join('')}</ul>` : ''}`;
	}

	function paintTotal() {
		const box = $('#bk-gm-total');
		if (!box) return;
		const sub = lines.reduce((a, l) => a + l.price * l.qty, 0);
		const ship = mode === 'edit' ? edit.shipping : Number(digits($('#bk-gm [name=shipping]')?.value)) || 0;
		const n = lines.reduce((a, l) => a + l.qty, 0);
		if (mode === 'edit') {
			const nt = sub + ship;
			const same = Math.abs(nt - edit.total) < 1;
			box.innerHTML = `<span>${n} prenda${n === 1 ? '' : 's'}${ship ? ` · Envío ${money(ship)}` : ''}</span>${same ? `<b>Total ${money(nt)}</b>` : `<b>Total ${money(edit.total)} → ${money(nt)}</b>`}`;
			return;
		}
		box.innerHTML = n ? `<span>${n} prenda${n === 1 ? '' : 's'} · Subtotal ${money(sub)}${ship ? ` · Envío ${money(ship)}` : ' · Envío gratis'}</span><b>Total ${money(sub + ship)}</b>` : '';
	}

	function paint() { paintResults(); paintLines(); paintTotal(); syncNotify(); }

	function syncNotify() {
		const f = $('#bk-gm');
		if (!f || mode === 'edit') return;
		const st = f.querySelector('[name=status]:checked')?.value;
		const ok = /\S+@\S+\.\S+/.test(f.email.value) && st !== 'pending';
		f.notify.disabled = !ok;
		if (!ok) f.notify.checked = false;
	}

	async function search(q) {
		const seq = ++reqSeq;
		try {
			const d = await api(`manual/products?q=${encodeURIComponent(q)}`);
			if (seq !== reqSeq) return;
			found = d.items || [];
			paintResults();
		} catch (e) { if (seq === reqSeq) $('#bk-gm-results').innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
	}

	const showErr = (msg, field) => {
		const box = $('#bk-gm-err');
		if (box) box.textContent = msg || '';
		const el = field && $(`#bk-gm [name=${field}]`);
		if (el) el.focus();
		else if (field === 'items') $('#bk-gm-q')?.focus();
	};

	// Eventos (delegados, una sola vez).
	document.addEventListener('input', (e) => {
		if (!e.target.closest('#bk-gm')) return;
		if (e.target.id === 'bk-gm-q') {
			clearTimeout(timer);
			const q = e.target.value.trim();
			if (q.length < 2) { found = []; paintResults(); return; }
			timer = setTimeout(() => search(q), 300);
			return;
		}
		if (e.target.dataset.price !== undefined) {
			const l = lines[Number(e.target.dataset.price)];
			if (l) { l.price = Number(digits(e.target.value)) || 0; paintTotal(); e.target.closest('.bk-gm-line').querySelector('.bk-gm-sum').textContent = money(l.price * l.qty); }
			return;
		}
		if (e.target.name === 'shipping') paintTotal();
		if (e.target.name === 'email') syncNotify();
	});
	document.addEventListener('change', (e) => {
		if (!e.target.closest('#bk-gm')) return;
		if (e.target.name === 'status') syncNotify();
		if (e.target.dataset.price !== undefined) paintLines();
	});
	document.addEventListener('click', (e) => {
		if (!e.target.closest('#bk-gm')) return;
		const add = e.target.closest('[data-add]');
		if (add) {
			const p = found.find((x) => String(x.id) === add.dataset.p);
			const s = p && p.sizes.find((x) => String(x.id) === add.dataset.add);
			if (!s) return;
			const cur = lines.find((l) => l.id === s.id);
			if (cur) { if (lineMax(cur) === null || cur.qty < lineMax(cur)) cur.qty++; } else lines.push({ id: s.id, name: p.name, size: s.label, sku: p.sku, img: p.img, qty: 1, price: s.price, base: s.price, stock: s.stock });
			showErr('');
			paint();
			// Aviso solo para lectores de pantalla: la prenda ya se ve en "En el pedido" (un aviso visual tapaba el botón).
			const live = $('#bk-gm-live');
			if (live) live.textContent = `Agregada: ${p.name}, talla ${s.label}. ${lines.reduce((a, l) => a + l.qty, 0)} prendas en el pedido.`;
			return;
		}
		const q = e.target.closest('[data-qty]');
		if (q) {
			const l = lines[Number(q.dataset.qty)];
			if (!l) return;
			l.qty += Number(q.dataset.d);
			if (l.qty < 1) { lines.splice(Number(q.dataset.qty), 1); if (l.item) { l.qty = l.origQty; removed.push(l); } }
			else if (lineMax(l) !== null && l.qty > lineMax(l)) l.qty = lineMax(l);
			paint();
			return;
		}
		const del = e.target.closest('[data-del]');
		if (del) {
			const [l] = lines.splice(Number(del.dataset.del), 1);
			if (l?.item) { l.qty = l.origQty; removed.push(l); }
			paint();
			$('#bk-gm-q')?.focus();
			return;
		}
		const undo = e.target.closest('[data-undo]');
		if (undo) { const [l] = removed.splice(Number(undo.dataset.undo), 1); if (l) lines.push(l); paint(); return; }
		const swap = e.target.closest('[data-swap]');
		if (swap) {
			// Cambio de talla: la prenda sale del pedido y se buscan sus otras tallas para elegir la nueva.
			const i = Number(swap.dataset.swap);
			const l = lines[i];
			if (!l) return;
			lines.splice(i, 1);
			l.qty = l.origQty;
			removed.push(l);
			const q2 = $('#bk-gm-q');
			q2.value = l.sku;
			search(l.sku);
			paint();
			q2.focus();
			const live = $('#bk-gm-live');
			if (live) live.textContent = `${l.name} talla ${l.size} sale del pedido. Elige la talla o prenda nueva en los resultados.`;
		}
	});
	document.addEventListener('submit', async (e) => {
		const f = e.target.closest('#bk-gm');
		if (!f) return;
		e.preventDefault();
		showErr('');
		if (mode === 'edit') { reviewEdit(); return; }
		if (!lines.length) { showErr('Agrega al menos una prenda.', 'items'); return; }
		if (!f.first_name.value.trim()) { showErr('Escribe el nombre de la clienta.', 'first_name'); return; }
		let ph = digits(f.phone.value);
		if (ph.length === 12 && ph.startsWith('57')) ph = ph.slice(2);
		if (ph.length !== 10) { showErr('El celular debe tener 10 dígitos.', 'phone'); return; }
		if (f.email.value.trim() && !/\S+@\S+\.\S+/.test(f.email.value)) { showErr('El correo no es válido.', 'email'); return; }
		const btn = $('#bk-gm-save');
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		const customer = {};
		['first_name', 'last_name', 'phone', 'email', 'id_number', 'state', 'city', 'address_1', 'address_2'].forEach((k) => { customer[k] = f[k].value; });
		try {
			const d = await api('orders/manual', {
				method: 'POST',
				body: {
					customer,
					items: lines.map((l) => ({ id: l.id, qty: l.qty, price: l.price !== l.base ? l.price : '' })),
					shipping: Number(digits(f.shipping.value)) || 0,
					payment: f.payment.value,
					status: f.querySelector('[name=status]:checked').value,
					notify: f.notify.checked,
					note: f.note.value,
				},
			});
			lines = [];
			window.removeEventListener('beforeunload', leaveGuard);
			A.forget('orders');
			A.forget('summary');
			toast(`Pedido #${d.number} creado · ${d.total}. Prendas reservadas.`);
			location.hash = '#pedidos';
			setTimeout(() => A.showOrder(d.id), 300);
		} catch (x) {
			showErr(x.message, x.field);
			btn.disabled = false;
			btn.removeAttribute('aria-busy');
		}
	});
	window.addEventListener('hashchange', () => { if (mode === 'new' && !location.hash.startsWith('#pedidos/nuevo')) window.removeEventListener('beforeunload', leaveGuard); });

	/* --- Cambiar prendas de un pedido -------------------------------------------------------- */
	async function editItems(id) {
		A.closeDrawer?.();
		mode = 'edit';
		lines = [];
		removed = [];
		found = [];
		pending = null;
		main.innerHTML = '<div class="bk-g-loading">Cargando prendas del pedido…</div>';
		let d;
		try { d = await api(`orders/${id}/items`); } catch (x) { main.innerHTML = `<p class="bk-g-error">${esc(x.message)}</p><p><a class="bk-g-link" href="#pedidos">← Volver a pedidos</a></p>`; return; }
		if (mode !== 'edit' || !location.hash.startsWith(`#pedidos/prendas/${id}`)) return;
		edit = { id: d.id, number: d.number, total: d.total, shipping: d.shipping, holds: d.holds };
		lines = d.lines.map((l) => ({ ...l, origQty: l.qty, base: l.price, max: l.stock === null ? null : (d.holds ? l.qty + l.stock : l.stock) }));
		window.addEventListener('beforeunload', leaveGuard);
		main.innerHTML = `<div class="bk-g-h1row"><h1 class="bk-g-h1">Cambiar prendas · Pedido #${esc(d.number)}</h1><a class="bk-g-link" href="#pedidos" data-back="${d.id}">← Volver al pedido</a></div>
			<p class="bk-g-hint bk-gm-lead">Quita, cambia de talla o agrega prendas. Antes de guardar te mostramos el resumen: ahí eliges si cada prenda que sale <b>vuelve al inventario</b> y ves si cambia el valor. No se envía correo a la clienta.</p>
			<form class="bk-gm is-edit" id="bk-gm" novalidate>
				<section class="bk-g-card" aria-labelledby="bk-gm-h1">
					<h2 id="bk-gm-h1">Prendas</h2>
					<div id="bk-gm-lines"></div>
					<h3 class="bk-gm-sub">Agregar una prenda o talla</h3>
					<label class="bk-g-search bk-gm-search"><span class="bk-g-sr">Buscar prenda por nombre o REF</span>${C.icons.search}
						<input type="search" id="bk-gm-q" placeholder="Busca por nombre o REF (ej. 9898, falda, flare)" autocomplete="off" aria-describedby="bk-gm-qhint"></label>
					<p class="bk-g-hint" id="bk-gm-qhint">Toca una talla para agregarla. Las tallas agotadas no se pueden elegir.</p>
					<div class="bk-gm-results" id="bk-gm-results" aria-live="polite"></div>
					<p class="bk-g-sr" id="bk-gm-live" role="status" aria-live="polite"></p>
				</section>
				<div class="bk-gm-foot">
					<div class="bk-gm-total" id="bk-gm-total" aria-live="polite"></div>
					<p class="bk-gm-err" role="alert" id="bk-gm-err"></p>
					<button type="submit" class="bk-g-btn" id="bk-gm-save">Revisar cambio</button>
				</div>
			</form>
			<dialog class="bk-gm-dlg" id="bk-gm-dlg" aria-labelledby="bk-gm-dlgt"></dialog>`;
		paint();
	}

	const editBody = () => lines.map((l) => (l.item ? { item: l.item, qty: l.qty } : { id: l.id, qty: l.qty, price: l.price !== l.base ? l.price : '' }));

	async function reviewEdit() {
		if (!changed()) { showErr('No hay cambios en las prendas.', 'items'); return; }
		if (!lines.length) { showErr('El pedido debe quedar con al menos una prenda. Si la clienta no se lleva nada, cancela el pedido.', 'items'); return; }
		const btn = $('#bk-gm-save');
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		try {
			pending = await api(`orders/${edit.id}/items`, { method: 'POST', body: { lines: editBody(), confirm: false } });
			openConfirm(pending);
		} catch (x) { showErr(x.message, x.field); }
		btn.disabled = false;
		btn.removeAttribute('aria-busy');
	}

	function openConfirm(p) {
		const dlg = $('#bk-gm-dlg');
		const up = p.diff > 0;
		const alert = Math.abs(p.diff) >= 1 ? `<div class="bk-gm-alert" role="alert">${C.icons.info || ''}<p><b>El valor del pedido va a cambiar: de ${esc(p.old_money)} a ${esc(p.new_money)}.</b>
			${up ? `La clienta debe pagar <b>${esc(p.diff_money)}</b> más.` : `Hay que devolverle <b>${esc(p.diff_money)}</b> a la clienta.`}</p></div>` : '';
		dlg.innerHTML = `<form method="dialog" class="bk-gm-dlg__in" id="bk-gm-dlgf">
			<h2 id="bk-gm-dlgt">Confirmar cambio · Pedido #${esc(edit.number)}</h2>
			${p.out.length ? `<h3>Sale del pedido</h3><ul class="bk-gm-chg">${p.out.map((x) => `<li><b>${x.qty} × ${esc(x.label)}</b>
				${x.back ? `<label class="bk-gm-check"><input type="checkbox" name="restock" value="${x.item}" checked><span>Vuelve al inventario${x.back < x.qty ? ` (${x.back} de ${x.qty})` : ''} <small>Desmárcalo si la prenda llegó dañada o no regresa.</small></span></label>`
					: '<small>Esta prenda no se había descontado del inventario: no hay nada que devolver.</small>'}</li>`).join('')}</ul>` : ''}
			${p.in.length ? `<h3>Entra al pedido</h3><ul class="bk-gm-chg">${p.in.map((x) => `<li><b>${x.qty} × ${esc(x.label)}</b>${p.holds ? '<small>Se descuenta del inventario al confirmar.</small>' : ''}</li>`).join('')}</ul>` : ''}
			${alert}
			<p class="bk-g-hint">${alert ? '' : `El total sigue igual: ${esc(p.new_money)}. `}No se envía correo a la clienta.</p>
			<p class="bk-gm-err" role="alert" id="bk-gm-dlgerr"></p>
			<div class="bk-gm-dlg__btns"><button type="button" class="bk-g-btn is-ghost" data-dlg-close>Volver</button>
				<button type="button" class="bk-g-btn" data-dlg-ok>Confirmar cambio</button></div></form>`;
		dlg.showModal();
		dlg.querySelector('[data-dlg-ok]').focus();
	}

	async function confirmEdit(btn) {
		const dlg = $('#bk-gm-dlg');
		const restock = {};
		(pending?.out || []).forEach((x) => { const c = dlg.querySelector(`[name=restock][value="${x.item}"]`); restock[x.item] = !!c?.checked; });
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		try {
			const d = await api(`orders/${edit.id}/items`, { method: 'POST', body: { lines: editBody(), restock, confirm: true } });
			const id = edit.id;
			lines = [];
			removed = [];
			window.removeEventListener('beforeunload', leaveGuard);
			dlg.close();
			A.forget('orders');
			A.forget('summary');
			toast(`Prendas cambiadas · Total ${d.new_money}`);
			location.hash = '#pedidos';
			setTimeout(() => A.showOrder(id), 300);
		} catch (x) {
			$('#bk-gm-dlgerr').textContent = x.message;
			btn.disabled = false;
			btn.removeAttribute('aria-busy');
		}
	}

	document.addEventListener('click', (e) => {
		if (e.target.closest('#bk-gm-dlg [data-dlg-close]')) { $('#bk-gm-dlg').close(); $('#bk-gm-save')?.focus(); return; }
		const ok = e.target.closest('#bk-gm-dlg [data-dlg-ok]');
		if (ok) { confirmEdit(ok); return; }
		const back = e.target.closest('[data-back]');
		if (back && mode === 'edit') { const id = back.dataset.back; setTimeout(() => A.showOrder(id), 300); }
	});
	window.addEventListener('hashchange', () => { if (!location.hash.startsWith('#pedidos/prendas/')) { if (mode === 'edit') window.removeEventListener('beforeunload', leaveGuard); } });

	window.bkGManual = { view, editItems };
})();
