/**
 * Carrito flotante (Store API de WooCommerce).
 * - Se abre al tocar el icono del carrito y al añadir un producto.
 * - Cambiar cantidad / quitar sin recargar; sugerencias por talla con "Añadir" en un toque.
 * - Ficha de producto: añadir sin recargar; si la Store API falla, se envía el formulario normal.
 * Accesible: diálogo modal, foco atrapado, Esc, anuncios para lectores de pantalla.
 */
(() => {
	'use strict';

	const cfg = window.bkSc;
	const root = document.getElementById('bk-sc');
	if (!cfg || !root) return;

	const t = cfg.i18n;
	const body = root.querySelector('[data-bk-sc-body]');
	const foot = root.querySelector('[data-bk-sc-foot]');
	const countEl = root.querySelector('[data-bk-sc-count]');
	const live = root.querySelector('[data-bk-sc-live]');
	const panel = root.querySelector('.bk-sc__panel');
	let nonce = '';
	let lastFocus = null;
	let busy = false;

	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const say = (msg) => { live.textContent = ''; setTimeout(() => { live.textContent = msg; }, 50); };
	const icon = (name) => (cfg.icons && cfg.icons[name]) || '';

	/* --- API ------------------------------------------------------------------ */
	let warming = null;
	// Pide el nonce de la Store API por adelantado (mientras la clienta elige talla), no al pulsar "Añadir".
	const warm = () => {
		nonce = nonce || window.bkStoreNonce || '';
		if (nonce || warming) return warming;
		warming = api('cart').catch(() => null).finally(() => { warming = null; });
		return warming;
	};
	const api = async (path, opts = {}) => {
		nonce = nonce || window.bkStoreNonce || '';
		if (opts.method === 'POST' && !nonce) await (warming || api('cart')); // Obtiene el nonce de la Store API.
		const res = await fetch(cfg.store + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(nonce ? { Nonce: nonce } : {}) },
			body: opts.body ? JSON.stringify(opts.body) : undefined,
		});
		const n = res.headers.get('Nonce');
		if (n) nonce = n;
		const data = await res.json().catch(() => null);
		if (!res.ok) throw Object.assign(new Error(data?.message || t.error), { data });
		return data;
	};

	/* --- Formato de precios (unidades menores de la Store API) ------------------- */
	const money = (minor, c) => {
		const unit = c.currency_minor_unit ?? 0;
		const value = Number(minor) / 10 ** unit;
		const [int, dec] = value.toFixed(unit).split('.');
		const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, c.currency_thousand_separator ?? '.');
		return `${c.currency_prefix ?? ''}${grouped}${dec ? (c.currency_decimal_separator ?? ',') + dec : ''}${c.currency_suffix ?? ''}`;
	};

	/* --- Copia local del carrito: el panel abre al instante y se actualiza por detrás ----
	 * El servidor tarda ~3 s por petición. Se guarda solo lo que pinta el panel (productos, totales,
	 * cantidad) en sessionStorage (se borra al cerrar la pestaña). Sin la cookie de carrito = vacío seguro. */
	const KEY = 'bk_cart_snap';
	const hasCookie = () => /(?:^|;\s*)woocommerce_items_in_cart=1/.test(document.cookie);
	const slim = (c) => (c ? { items: c.items || [], items_count: c.items_count || 0, totals: c.totals || {} } : null);
	const snapshot = (cart) => {
		const sn = slim(cart);
		window.bkCartData = sn;
		try { sessionStorage.setItem(KEY, JSON.stringify(sn)); } catch (e) { /* Modo privado. */ }
	};
	const cached = () => {
		if (!hasCookie()) return { items: [], items_count: 0, totals: {} };
		if (window.bkCartData) return window.bkCartData;
		try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
	};
	window.bkCartSnapshot = snapshot; // El contador del header comparte su lectura del carrito.

	/* --- Abrir / cerrar -------------------------------------------------------- */
	const focusables = () => [...panel.querySelectorAll('a[href], button:not([disabled]), input, select')].filter((el) => el.offsetParent !== null);

	const open = async (opts = {}) => {
		if (root.hidden) {
			lastFocus = document.activeElement;
			root.hidden = false;
			document.documentElement.classList.add('bk-sc-open');
			requestAnimationFrame(() => root.classList.add('is-open'));
			panel.querySelector('.bk-sc__close').focus();
		}
		if (opts.pending) {
			// Respuesta inmediata mientras el servidor añade el producto.
			foot.hidden = true;
			body.setAttribute('aria-busy', 'true');
			body.innerHTML = `<p class="bk-sc__msg bk-sc__msg--pending">${esc(t.adding || '…')}</p>`;
		} else if (opts.cart) {
			render(opts.cart);
		} else {
			const snap = cached();
			if (snap) render(snap); // Al instante con la última copia conocida.
			if (!hasCookie()) return; // Sin carrito: nada que consultar.
			if (!snap) body.setAttribute('aria-busy', 'true');
			try { render(await api('cart')); } catch (e) { if (!snap) renderError(); }
		}
	};

	const close = () => {
		root.classList.remove('is-open');
		document.documentElement.classList.remove('bk-sc-open');
		setTimeout(() => { root.hidden = true; }, 250);
		lastFocus?.focus?.();
	};

	root.addEventListener('click', (e) => { if (e.target.closest('[data-bk-sc-close]')) close(); });
	root.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') { close(); return; }
		if (e.key !== 'Tab') return;
		const f = focusables();
		if (!f.length) return;
		if (e.shiftKey && document.activeElement === f[0]) { f[f.length - 1].focus(); e.preventDefault(); }
		else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { f[0].focus(); e.preventDefault(); }
	});
	window.addEventListener('pageshow', (e) => { if (e.persisted && !root.hidden) close(); });

	/* --- Pintar ---------------------------------------------------------------- */
	const renderError = () => {
		body.setAttribute('aria-busy', 'false');
		body.innerHTML = `<p class="bk-sc__msg">${esc(t.error)}</p>`;
	};

	const render = (cart) => {
		if (cart && cart.items) snapshot(cart);
		body.setAttribute('aria-busy', 'false');
		const n = cart.items_count || 0;
		countEl.textContent = n ? `(${n})` : '';
		window.bkCart?.set?.(n);

		if (!cart.items?.length) {
			foot.hidden = true;
			body.innerHTML = `<div class="bk-sc__empty">
				<p class="bk-sc__empty-title">${esc(t.empty)}</p><p>${esc(t.emptyText)}</p>
				<div class="bk-sc__empty-links">${cfg.lines.map((l) => `<a class="bk-sc__btn bk-sc__btn--line" href="${esc(l.url)}">${esc(l.label)}</a>`).join('')}</div>
			</div>`;
			return;
		}

		const items = cart.items.map((it) => {
			const img = it.images?.[0];
			const attrs = (it.variation || []).map((v) => `${/talla/i.test(v.attribute) ? 'Talla' : esc(v.attribute)}: ${esc(v.value)}`).join(' · ');
			const max = it.quantity_limits?.maximum ?? 99;
			return `<li class="bk-sc__item" data-key="${esc(it.key)}">
				${img ? `<img src="${esc(img.thumbnail)}" alt="" width="72" height="108" loading="lazy">` : '<span class="bk-sc__noimg"></span>'}
				<div class="bk-sc__info">
					<a class="bk-sc__name" href="${esc(it.permalink)}">${esc(it.name)}</a>
					${attrs ? `<span class="bk-sc__meta">${attrs}</span>` : ''}
					<div class="bk-sc__row">
						<div class="bk-sc__qty" role="group" aria-label="${esc(t.qty)}">
							<button type="button" data-act="dec" aria-label="${esc(t.less)} ${esc(it.name)}" ${it.quantity <= 1 ? 'disabled' : ''}>−</button>
							<span aria-live="polite">${it.quantity}</span>
							<button type="button" data-act="inc" aria-label="${esc(t.more)} ${esc(it.name)}" ${it.quantity >= max ? 'disabled' : ''}>+</button>
						</div>
						<button type="button" class="bk-sc__remove" data-act="remove" aria-label="${esc(t.remove)} ${esc(it.name)}">${icon('trash') || esc(t.remove)}</button>
					</div>
				</div>
				<span class="bk-sc__price">${money(it.totals.line_subtotal, it.totals)}</span>
			</li>`;
		}).join('');

		body.innerHTML = `<p class="bk-sc__ship">${icon('truck')}${esc(t.shipping)}</p>
			<ul class="bk-sc__items">${items}</ul>
			<section class="bk-sc__suggest" data-bk-sc-suggest hidden></section>`;

		const tot = cart.totals;
		const discount = Number(tot.total_discount || 0);
		const total = Number(tot.total_price);
		const addi = cfg.addi > 1 ? `<p class="bk-sc__addi">${esc(t.addi.replace('%1$d', cfg.addi).replace('%2$s', money(Math.ceil(total / cfg.addi), tot)))}</p>` : '';
		foot.hidden = false;
		foot.innerHTML = `
			<dl class="bk-sc__totals">
				<div><dt>${esc(t.subtotal)}</dt><dd>${money(tot.total_items, tot)}</dd></div>
				${discount ? `<div class="is-discount"><dt>${esc(t.discount)}</dt><dd>−${money(discount, tot)}</dd></div>` : ''}
				<div class="is-total"><dt>${esc(t.total)}</dt><dd>${money(total, tot)}</dd></div>
			</dl>
			${addi}
			<a class="bk-sc__btn bk-sc__btn--primary" href="${esc(cfg.checkout)}">${esc(t.checkout)}</a>
			<a class="bk-sc__link" href="${esc(cfg.cart)}">${esc(t.viewCart)}</a>`;

		loadSuggestions();
	};

	/* --- Sugerencias por talla ----------------------------------------------------- */
	const loadSuggestions = async () => {
		const box = body.querySelector('[data-bk-sc-suggest]');
		if (!box) return;
		try {
			// Las sugerencias dependen de lo que hay en el carrito: se reutilizan mientras no cambie.
			const sig = (window.bkCartData?.items || []).map((i) => `${i.id}x${i.quantity}`).join(',');
			let data = null;
			try { const c = JSON.parse(sessionStorage.getItem('bk_sug') || 'null'); if (c && c.sig === sig) data = c.data; } catch (e) { data = null; }
			if (!data) {
				const res = await fetch(cfg.suggest, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
				data = await res.json();
				try { sessionStorage.setItem('bk_sug', JSON.stringify({ sig, data })); } catch (e) { /* Modo privado. */ }
			}
			if (!data.items?.length) return;
			box.innerHTML = `<h3 class="bk-sc__stitle">${esc(t.suggest)} ${esc(data.size)}</h3>
				<ul class="bk-sc__srow">${data.items.map((s) => `<li class="bk-sc__sug">
					<a href="${esc(s.url)}" tabindex="-1" aria-hidden="true"><img src="${esc(s.image)}" alt="" width="120" height="180" loading="lazy"></a>
					<a class="bk-sc__sname" href="${esc(s.url)}">${esc(s.name)}</a>
					<span class="bk-sc__sprice">${esc(s.price)}</span>
					<button type="button" class="bk-sc__btn bk-sc__btn--add" data-sug='${esc(JSON.stringify({ id: s.id, variation: s.variation, name: s.name }))}'>+ ${esc(t.add)} ${esc(data.size)}</button>
				</li>`).join('')}</ul>`;
			box.hidden = false;
		} catch (e) { /* Sin sugerencias: el carrito sigue funcionando. */ }
	};

	/* --- Acciones dentro del panel ------------------------------------------------- */
	body.addEventListener('click', async (e) => {
		const btn = e.target.closest('button[data-act], button[data-sug]');
		if (!btn || busy) return;
		busy = true;
		btn.classList.add('is-busy');
		btn.setAttribute('aria-busy', 'true');
		try {
			let cart;
			if (btn.dataset.sug) {
				const s = JSON.parse(btn.dataset.sug);
				cart = await api('cart/add-item', { method: 'POST', body: { id: s.id, quantity: 1, variation: s.variation } });
				say(`${t.added}: ${s.name}`);
				fireJq('added_to_cart');
			} else {
				const li = btn.closest('[data-key]');
				const key = li.dataset.key;
				const qty = Number(li.querySelector('.bk-sc__qty span').textContent);
				if (btn.dataset.act === 'remove') {
					cart = await api('cart/remove-item', { method: 'POST', body: { key } });
					say(t.removed);
					fireJq('removed_from_cart');
				} else {
					cart = await api('cart/update-item', { method: 'POST', body: { key, quantity: qty + (btn.dataset.act === 'inc' ? 1 : -1) } });
				}
			}
			render(cart);
			panel.querySelector('.bk-sc__close').focus({ preventScroll: true });
		} catch (err) {
			say(err.message || t.error);
			btn.classList.remove('is-busy');
			btn.removeAttribute('aria-busy');
		} finally {
			busy = false;
		}
	});

	const fireJq = (ev) => { if (window.jQuery) window.jQuery(document.body).trigger(ev, [{}, '', window.jQuery()]); };

	/* --- Abrir desde el icono del carrito del header ---------------------------------- */
	document.addEventListener('click', (e) => {
		const link = e.target.closest('[data-bk-cart]');
		if (!link || e.metaKey || e.ctrlKey) return;
		e.preventDefault();
		e.stopImmediatePropagation(); // Evita la barra de "navegando" de core.js.
		open();
	}, true);

	/* --- Añadir al carrito sin recargar en la ficha de producto -------------------------- */
	document.addEventListener('submit', async (e) => {
		const form = e.target;
		if (!(form instanceof HTMLFormElement) || !form.matches('form.cart') || form.closest('.bk-sc')) return;
		const btn = form.querySelector('.single_add_to_cart_button');
		if (!btn || btn.classList.contains('disabled') || form.dataset.bkNative) return;

		const fd = new FormData(form);
		const productId = Number(fd.get('variation_id')) || Number(fd.get('add-to-cart')) || Number(btn.value);
		if (!productId) return; // Formulario no estándar: que siga normal.
		e.preventDefault();
		e.stopImmediatePropagation();

		const variation = [];
		for (const [k, v] of fd.entries()) {
			if (k.startsWith('attribute_') && v) variation.push({ attribute: k.replace(/^attribute_/, ''), value: v });
		}
		btn.classList.add('is-busy');
		btn.setAttribute('aria-busy', 'true');
		open({ pending: true });
		try {
			const cart = await api('cart/add-item', { method: 'POST', body: { id: productId, quantity: Number(fd.get('quantity')) || 1, variation } });
			render(cart);
			window.bkCartSynced = Date.now();
			fireJq('added_to_cart');
			say(t.added);
			await open({ cart });
		} catch (err) {
			close();
			if (err.data?.code) {
				// Error de negocio (sin stock, cantidad, etc.): se muestra junto al botón.
				let box = form.querySelector('.bk-form-msg');
				if (!box) {
					box = document.createElement('p');
					box.className = 'bk-form-msg';
					box.setAttribute('role', 'alert');
					btn.insertAdjacentElement('beforebegin', box);
				}
				box.innerHTML = esc(new DOMParser().parseFromString(err.message, 'text/html').body.textContent);
			} else {
				// Falla de red/API: envío normal del formulario (nunca se pierde la venta).
				form.dataset.bkNative = '1';
				form.requestSubmit(btn);
			}
		} finally {
			btn.classList.remove('is-busy');
			btn.removeAttribute('aria-busy');
		}
	}, true);

	// Productos simples añadidos por AJAX desde grillas (evento de WooCommerce).
	if (window.jQuery) {
		window.jQuery(document.body).on('added_to_cart', (ev, fragments, hash, $btn) => {
			if ($btn && $btn.length && !$btn.closest('.bk-sc').length) open();
		});
	}

	// Calentar el nonce en cuanto la clienta interactúa con el formulario de compra.
	['pointerdown', 'focusin', 'change'].forEach((ev) => document.addEventListener(ev, (e) => {
		if (e.target.closest?.('form.cart')) warm();
	}, { passive: true }));

	window.bkSideCart = { open, close };
})();
