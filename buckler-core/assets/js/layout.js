/**
 * Header [bk_header]: anuncios, menú de la tienda, buscador móvil, menú lateral y contador del carrito.
 * Sin dependencias. jQuery solo se usa (si existe) para escuchar los eventos de carrito de WooCommerce.
 */
(() => {
	'use strict';

	const header = document.querySelector('[data-bk-header]');
	if (!header) return;

	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
	const desktop = window.matchMedia('(min-width: 1100px)');
	const root = document.documentElement;

	/* --- Barra de anuncios: rota cada 5 s, se pausa con botón, hover o foco ---- */
	const announce = header.querySelector('.bk-announce');
	if (announce) {
		const items = [...announce.querySelectorAll('.bk-announce__item')];
		const toggle = announce.querySelector('.bk-announce__toggle');
		let i = 0;
		let timer = 0;
		let paused = reduceMotion.matches;
		let hover = false;
		const show = (n) => {
			items[i].classList.remove('is-active');
			i = (n + items.length) % items.length;
			items[i].classList.add('is-active');
		};
		const run = () => {
			clearInterval(timer);
			if (!paused && !hover && items.length > 1) timer = setInterval(() => show(i + 1), 5000);
		};
		const label = () => {
			if (!toggle) return;
			toggle.classList.toggle('is-paused', paused);
			toggle.setAttribute('aria-label', paused ? toggle.dataset.labelPlay : toggle.dataset.labelPause);
		};
		toggle?.addEventListener('click', () => { paused = !paused; label(); run(); });
		announce.addEventListener('mouseenter', () => { hover = true; run(); });
		announce.addEventListener('mouseleave', () => { hover = false; run(); });
		announce.addEventListener('focusin', () => { hover = true; run(); });
		announce.addEventListener('focusout', () => { hover = false; run(); });
		label();
		run();
	}

	/* --- Altura del header para elementos pegajosos (barra de filtros, etc.) --- */
	const sticky = header.closest('.et-l--header') || header;
	const setH = () => root.style.setProperty('--bk-header-h', `${Math.round(sticky.getBoundingClientRect().height)}px`);
	if ('ResizeObserver' in window) new ResizeObserver(setH).observe(sticky);
	setH();

	/* --- Sombra al hacer scroll ---------------------------------------------- */
	const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
	window.addEventListener('scroll', onScroll, { passive: true });
	onScroll();

	/* --- Página actual en el menú ------------------------------------------- */
	header.querySelectorAll('.bk-nav__link[href], .bk-drawer__link[href]').forEach((a) => {
		const u = new URL(a.href);
		if (u.pathname === location.pathname && u.search === location.search) a.setAttribute('aria-current', 'page');
	});

	/* --- Menú desplegable de la tienda (clic, teclado y hover con retardo) ---- */
	header.querySelectorAll('.has-mega').forEach((li) => {
		const btn = li.querySelector('button[aria-controls]');
		const panel = document.getElementById(btn.getAttribute('aria-controls'));
		if (!panel) return;
		let closeTimer = 0;
		let openTimer = 0;

		const set = (open) => {
			btn.setAttribute('aria-expanded', String(open));
			panel.hidden = !open;
		};
		const isOpen = () => btn.getAttribute('aria-expanded') === 'true';

		btn.addEventListener('click', () => set(!isOpen()));
		li.addEventListener('mouseenter', () => {
			if (!desktop.matches) return;
			clearTimeout(closeTimer);
			openTimer = setTimeout(() => set(true), 120);
		});
		li.addEventListener('mouseleave', () => {
			clearTimeout(openTimer);
			closeTimer = setTimeout(() => set(false), 220);
		});
		li.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && isOpen()) {
				set(false);
				btn.focus();
			}
		});
		li.addEventListener('focusout', (e) => {
			if (!li.contains(e.relatedTarget)) set(false);
		});
		document.addEventListener('click', (e) => {
			if (isOpen() && !li.contains(e.target)) set(false);
		});
	});

	/* --- Buscador móvil ------------------------------------------------------ */
	const searchBtn = header.querySelector('.bk-search-toggle');
	if (searchBtn) {
		const box = document.getElementById(searchBtn.getAttribute('aria-controls'));
		searchBtn.addEventListener('click', () => {
			const open = searchBtn.getAttribute('aria-expanded') !== 'true';
			searchBtn.setAttribute('aria-expanded', String(open));
			box.hidden = !open;
			if (open) box.querySelector('input[type="search"]')?.focus();
		});
	}

	/* --- Menú lateral (diálogo modal accesible) ------------------------------ */
	const burger = header.querySelector('.bk-burger');
	const drawer = burger && document.getElementById(burger.getAttribute('aria-controls'));
	if (drawer) {
		// Se mueve al <body> para que el position:fixed no dependa del header sticky.
		document.body.appendChild(drawer);
		const panel = drawer.querySelector('.bk-drawer__panel');
		const focusables = () => [...panel.querySelectorAll('a[href], button, summary, input')].filter((el) => el.offsetParent !== null);

		const open = () => {
			drawer.hidden = false;
			burger.setAttribute('aria-expanded', 'true');
			root.classList.add('bk-drawer-open');
			focusables()[0]?.focus();
		};
		const close = () => {
			drawer.hidden = true;
			burger.setAttribute('aria-expanded', 'false');
			root.classList.remove('bk-drawer-open');
			burger.focus();
		};

		burger.addEventListener('click', open);
		drawer.querySelector('.bk-drawer__close').addEventListener('click', close);
		drawer.addEventListener('click', (e) => { if (e.target === drawer) close(); });
		drawer.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				close();
				return;
			}
			if (e.key !== 'Tab') return;
			const f = focusables();
			const first = f[0];
			const last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
			else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
		});
		desktop.addEventListener('change', (e) => { if (e.matches && !drawer.hidden) close(); });
		window.addEventListener('pageshow', () => { if (!drawer.hidden) close(); });
	}

	/* --- Contador del carrito (Store API; solo si hay carrito) ------------------ */
	const countEls = document.querySelectorAll('[data-bk-cart-count]');
	const cartLink = header.querySelector('[data-bk-cart]');
	const label = cartLink?.querySelector('.bk-sr-only');
	const render = (n) => {
		countEls.forEach((el) => {
			el.textContent = n > 99 ? '99+' : String(n);
			el.hidden = n < 1;
		});
		if (label) label.textContent = n > 0 ? `Carrito, ${n} ${n === 1 ? 'producto' : 'productos'}` : 'Carrito vacío';
	};
	const hasCartCookie = () => /(?:^|;\s*)woocommerce_items_in_cart=1/.test(document.cookie);
	const refresh = (bump) => {
		if (!hasCartCookie()) { render(0); return; }
		fetch((window.bkCore && window.bkCore.cartApi) || '/wp-json/wc/store/v1/cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then((r) => (r.ok ? r.json() : null))
			.then((cart) => {
				if (!cart) return;
				render(cart.items_count || 0);
				if (bump && cartLink) {
					cartLink.classList.remove('is-bump');
					void cartLink.offsetWidth;
					cartLink.classList.add('is-bump');
				}
			})
			.catch(() => {});
	};
	refresh(false);
	window.addEventListener('pageshow', (e) => { if (e.persisted) refresh(false); });
	if (window.jQuery) {
		window.jQuery(document.body).on('added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed', () => refresh(true));
	}
	// API pública para el carrito flotante (fase 3).
	window.bkCart = { refresh, set: render };
})();
