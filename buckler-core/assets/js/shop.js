/**
 * Tienda [bk_tienda]: filtros sin recargar, "Ver más", historial del navegador y panel de filtros móvil.
 * Sin JS todo funciona igual con recargas normales (formularios GET y enlaces).
 */
(() => {
	'use strict';

	const SEL = '[data-bk-shop]';
	if (!document.querySelector(SEL)) return;

	const desktop = window.matchMedia('(min-width: 1024px)');
	let live = document.getElementById('bk-shop-live');
	if (!live) {
		live = document.createElement('div');
		live.id = 'bk-shop-live';
		live.className = 'bk-sr-only';
		live.setAttribute('role', 'status');
		live.setAttribute('aria-live', 'polite');
		document.body.appendChild(live);
	}
	let ctrl = null;

	// URL limpia desde un formulario: arreglos unidos por comas, sin vacíos.
	const formUrl = (form) => {
		const url = new URL(form.action, location.href);
		const params = new Map();
		for (const [k, v] of new FormData(form).entries()) {
			if (v === '') continue;
			const key = k.replace(/\[\]$/, '');
			params.set(key, params.has(key) ? `${params.get(key)},${v}` : v);
		}
		const keep = new URLSearchParams(url.search);
		['s', 'post_type'].forEach((k) => { if (keep.has(k) && !params.has(k)) params.set(k, keep.get(k)); });
		url.search = new URLSearchParams([...params]).toString();
		return url.toString();
	};

	const panelOpen = () => document.querySelector(`${SEL} .bk-shop__filters.is-open`) !== null;

	const setPanel = (open) => {
		const shop = document.querySelector(SEL);
		const panel = shop?.querySelector('.bk-shop__filters');
		const btn = shop?.querySelector('.bk-shop__filter-btn');
		if (!panel || !btn) return;
		panel.classList.toggle('is-open', open);
		btn.setAttribute('aria-expanded', String(open));
		panel.setAttribute('aria-modal', String(open && !desktop.matches));
		document.documentElement.classList.toggle('bk-shop-lock', open && !desktop.matches);
		if (open) panel.querySelector('input, button')?.focus();
	};

	const navigate = async (url, { push = true, keepPanel = false, focusId = '' } = {}) => {
		const shop = document.querySelector(SEL);
		if (!shop) { location.href = url; return; }
		ctrl?.abort();
		ctrl = new AbortController();
		shop.classList.add('is-loading');
		shop.setAttribute('aria-busy', 'true');
		try {
			const res = await fetch(url, { credentials: 'same-origin', signal: ctrl.signal, headers: { 'X-BK-Shop': '1' } });
			const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
			const next = doc.querySelector(SEL);
			if (!next) { location.href = url; return; }
			shop.replaceWith(next);
			if (push) history.pushState({ bkShop: true }, '', url);
			window.bkCards?.boot?.();
			if (keepPanel) setPanel(true);
			if (focusId) document.getElementById(focusId)?.focus({ preventScroll: true });
			live.textContent = next.querySelector('.bk-shop__count')?.textContent.trim() || '';
			if (!keepPanel && !focusId) {
				const top = next.getBoundingClientRect().top + window.scrollY - 120;
				if (window.scrollY > top) window.scrollTo({ top, behavior: 'smooth' });
			}
		} catch (e) {
			if (e.name !== 'AbortError') location.href = url;
		}
	};

	// Cambios en los filtros: se aplican al instante (en móvil el panel queda abierto con los conteos al día).
	document.addEventListener('change', (e) => {
		const input = e.target;
		const form = input.closest?.(`${SEL} .bk-shop__form, ${SEL} .bk-shop__sort`);
		if (!form) return;
		navigate(formUrl(form), { keepPanel: panelOpen(), focusId: input.id });
	});

	document.addEventListener('submit', (e) => {
		const form = e.target.closest?.(`${SEL} form`);
		if (!form) return;
		e.preventDefault();
		setPanel(false);
		navigate(formUrl(form));
	});

	document.addEventListener('click', async (e) => {
		const shop = e.target.closest(SEL);
		if (!shop) return;

		if (e.target.closest('.bk-shop__filter-btn')) { setPanel(!panelOpen()); return; }
		if (e.target.closest('.bk-shop__fclose')) { setPanel(false); shop.querySelector('.bk-shop__filter-btn')?.focus(); return; }

		const link = e.target.closest('a[data-bk-shop-link]');
		if (link && !e.metaKey && !e.ctrlKey) {
			e.preventDefault();
			e.stopImmediatePropagation();
			setPanel(false);
			navigate(link.href);
			return;
		}

		// "Ver más": añade la siguiente página debajo.
		const more = e.target.closest('a[data-bk-shop-more]');
		if (more && !e.metaKey && !e.ctrlKey) {
			e.preventDefault();
			e.stopImmediatePropagation();
			if (more.classList.contains('is-busy')) return;
			more.classList.add('is-busy');
			try {
				const res = await fetch(more.href, { credentials: 'same-origin' });
				const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
				const list = shop.querySelector('ul.products');
				// La respuesta trae la lista completa hasta esa página: se añaden solo las que faltan.
				const items = [...doc.querySelectorAll(`${SEL} ul.products > li`)].slice(list.children.length);
				const first = items[0];
				items.forEach((li) => list.appendChild(document.importNode(li, true)));
				shop.querySelector('.bk-shop__pager').replaceWith(document.importNode(doc.querySelector(`${SEL} .bk-shop__pager`), true));
				history.replaceState({ bkShop: true }, '', more.href);
				window.bkCards?.boot?.();
				// Foco en el primer producto nuevo (teclado y lector de pantalla).
				if (first) list.querySelector(`li:nth-child(${list.children.length - items.length + 1}) .bk-card__link, li:nth-child(${list.children.length - items.length + 1}) a`)?.focus({ preventScroll: true });
				live.textContent = shop.querySelector('.bk-shop__progress')?.textContent.trim() || '';
			} catch (err) {
				location.href = more.href;
			}
		}
	}, true);

	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && panelOpen()) {
			setPanel(false);
			document.querySelector(`${SEL} .bk-shop__filter-btn`)?.focus();
		}
	});

	window.addEventListener('popstate', (e) => {
		if (e.state?.bkShop || document.querySelector(SEL)) navigate(location.href, { push: false });
	});
	history.replaceState({ bkShop: true }, '', location.href);
	desktop.addEventListener('change', () => setPanel(false));
})();
