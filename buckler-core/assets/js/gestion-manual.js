/**
 * Gestión de tienda: pedido manual (#pedidos/nuevo). Para clientas especiales o ventas por WhatsApp: se eligen las
 * prendas (buscador por nombre o REF, tallas con lo que queda), los datos de la clienta, el medio de pago y el estado.
 * Al guardar, el pedido descuenta el inventario (las prendas quedan reservadas).
 * Depende de window.bkGApp (gestion.js). Datos: bk/v1/gestion/manual/products, orders/manual.
 */
(() => {
	'use strict';

	const A = window.bkGApp;
	if (!A) return;
	const { C, api, esc, $, $$, toast, main } = A;

	const money = (n) => `$${Math.round(Number(n) || 0).toLocaleString('es-CO')}`;
	const digits = (v) => String(v ?? '').replace(/\D/g, '');
	let lines = []; // { id, name, size, sku, img, qty, price, base, stock }
	let found = [];
	let timer = 0;
	let reqSeq = 0;

	const STATUS = [
		['pending', 'Reservado, sin pagar', 'Queda en "Pendiente de pago". Las prendas se reservan hasta que lo pagues o lo canceles.'],
		['on-hold', 'En espera', 'Para revisar el pago (por ejemplo, una transferencia por confirmar).'],
		['processing', 'Pagado', 'Queda "En proceso", listo para despachar.'],
	];

	const leaveGuard = (e) => { if (lines.length) { e.preventDefault(); e.returnValue = ''; } };

	function view() {
		A.closeDrawer?.(); // Si venía de ver un pedido, el panel lateral no queda encima del formulario.
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
	const inCart = (id) => lines.filter((l) => l.id === id).reduce((a, l) => a + l.qty, 0);

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
		if (!lines.length) { box.innerHTML = '<p class="bk-g-empty bk-gm-empty">Todavía no hay prendas en el pedido.</p>'; return; }
		box.innerHTML = `<h3 class="bk-gm-sub">En el pedido</h3><ul class="bk-gm-lines">${lines.map((l, i) => `<li class="bk-gm-line">
			${l.img ? `<img src="${esc(l.img)}" alt="" width="44" height="44">` : '<span class="bk-gm-noimg" aria-hidden="true"></span>'}
			<div class="bk-gm-line__name"><b>${esc(l.name)}</b><small>Talla ${esc(l.size)}${l.sku ? ` · REF ${esc(l.sku)}` : ''}${l.stock !== null ? ` · quedan ${l.stock}` : ''}</small></div>
			<label class="bk-gm-price"><span>Precio c/u</span><input inputmode="numeric" data-price="${i}" value="${Math.round(l.price).toLocaleString('es-CO')}" aria-label="Precio de ${esc(l.name)} talla ${esc(l.size)}"></label>
			<div class="bk-gm-qty" role="group" aria-label="Cantidad de ${esc(l.name)} talla ${esc(l.size)}">
				<button type="button" data-qty="${i}" data-d="-1" aria-label="Quitar una">−</button><output>${l.qty}</output>
				<button type="button" data-qty="${i}" data-d="1" aria-label="Agregar una" ${l.stock !== null && l.qty >= l.stock ? 'disabled' : ''}>+</button></div>
			<b class="bk-gm-sum">${money(l.price * l.qty)}</b>
			<button type="button" class="bk-gm-del" data-del="${i}" aria-label="Quitar ${esc(l.name)} talla ${esc(l.size)} del pedido">${C.icons.trash || '×'}</button>
		</li>`).join('')}</ul>`;
	}

	function paintTotal() {
		const box = $('#bk-gm-total');
		if (!box) return;
		const sub = lines.reduce((a, l) => a + l.price * l.qty, 0);
		const ship = Number(digits($('#bk-gm [name=shipping]')?.value)) || 0;
		const n = lines.reduce((a, l) => a + l.qty, 0);
		box.innerHTML = n ? `<span>${n} prenda${n === 1 ? '' : 's'} · Subtotal ${money(sub)}${ship ? ` · Envío ${money(ship)}` : ' · Envío gratis'}</span><b>Total ${money(sub + ship)}</b>` : '';
	}

	function paint() { paintResults(); paintLines(); paintTotal(); syncNotify(); }

	function syncNotify() {
		const f = $('#bk-gm');
		if (!f) return;
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
			if (cur) { if (s.stock === null || cur.qty < s.stock) cur.qty++; } else lines.push({ id: s.id, name: p.name, size: s.label, sku: p.sku, img: p.img, qty: 1, price: s.price, base: s.price, stock: s.stock });
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
			if (l.qty < 1) lines.splice(Number(q.dataset.qty), 1);
			else if (l.stock !== null && l.qty > l.stock) l.qty = l.stock;
			paint();
			return;
		}
		const del = e.target.closest('[data-del]');
		if (del) { lines.splice(Number(del.dataset.del), 1); paint(); $('#bk-gm-q')?.focus(); }
	});
	document.addEventListener('submit', async (e) => {
		const f = e.target.closest('#bk-gm');
		if (!f) return;
		e.preventDefault();
		showErr('');
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
	window.addEventListener('hashchange', () => { if (!location.hash.startsWith('#pedidos/nuevo')) window.removeEventListener('beforeunload', leaveGuard); });

	window.bkGManual = { view };
})();
