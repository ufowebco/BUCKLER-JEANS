/**
 * Buckler Core: JS global (sin dependencias, ~4 KB).
 *
 * 1. Rage clicks: ignora clics repetidos sobre un mismo control y bloquea los que están "ocupados".
 * 2. Dead clicks: respuesta visible a todo clic (barra de navegación, estados de carga, mensajes
 *    en línea en lugar de alert()) y tarjetas de producto clicables en toda su superficie.
 * 3. Quick back: feedback inmediato al navegar y restauración limpia al volver (bfcache).
 * 4. Carrusel accesible de banners [bk_hero].
 */
(() => {
	'use strict';

	const cfg = window.bkCore || { i18n: {} };
	const t = (k) => cfg.i18n[k] || k;
	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
	const root = document.documentElement;
	// Las protecciones de clics solo actúan con el módulo "clicks" encendido (clase en <body>).
	const clicks = document.body.classList.contains('bk-m-clicks');

	/* ------------------------------------------------------------------ *
	 * Región viva para lectores de pantalla
	 * ------------------------------------------------------------------ */
	let live;
	const announce = (msg) => {
		if (!live) {
			live = document.createElement('div');
			live.className = 'bk-sr-only';
			live.setAttribute('role', 'status');
			live.setAttribute('aria-live', 'polite');
			document.body.appendChild(live);
		}
		live.textContent = '';
		window.setTimeout(() => { live.textContent = msg; }, 60);
	};

	/* ------------------------------------------------------------------ *
	 * 1. Rage clicks
	 * ------------------------------------------------------------------ */
	const CLICKABLE = 'a[href], button, [role="button"], input[type="submit"], input[type="button"]';
	// Controles donde el clic repetido es intencional.
	const MULTI_OK = '[data-bk-multi], .quantity, .qty, .bk-carousel, .flex-control-nav, .flex-direction-nav, .pswp, .bk-qty';
	const REPEAT_MS = 450;
	const lastClick = new WeakMap();

	const isBusy = (el) => el.classList.contains('is-busy') || el.classList.contains('loading') || el.getAttribute('aria-busy') === 'true';

	if (clicks) document.addEventListener('click', (e) => {
		const el = e.target.closest(CLICKABLE);
		if (!el) return;

		if (isBusy(el)) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}
		if (el.closest(MULTI_OK)) return;

		const now = performance.now();
		const prev = lastClick.get(el);
		lastClick.set(el, now);
		if (prev !== undefined && now - prev < REPEAT_MS) {
			e.preventDefault();
			e.stopImmediatePropagation();
		}
	}, true);

	/* ------------------------------------------------------------------ *
	 * 2a. Feedback al navegar (barra superior) + 3. Quick back
	 * ------------------------------------------------------------------ */
	if (clicks) document.addEventListener('click', (e) => {
		if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
		const a = e.target.closest('a[href]');
		if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
		const url = new URL(a.href, location.href);
		if (url.origin !== location.origin) return;
		if (url.pathname === location.pathname && url.search === location.search && url.hash) return; // Ancla interna.
		if (a.getAttribute('href').startsWith('#') || a.closest('.woocommerce-product-gallery, .pswp')) return;
		root.classList.add('bk-navigating');
		a.classList.add('is-busy');
		// Seguridad: si la navegación no ocurre (descarga, respuesta 204…), se libera el enlace.
		window.setTimeout(() => {
			root.classList.remove('bk-navigating');
			a.classList.remove('is-busy');
		}, 8000);
	});

	// Al volver con el botón Atrás (bfcache) la página debe quedar usable al instante.
	window.addEventListener('pageshow', () => {
		root.classList.remove('bk-navigating');
		document.querySelectorAll('.is-busy').forEach((el) => el.classList.remove('is-busy'));
		document.querySelectorAll('form[data-bk-submitted]').forEach((f) => delete f.dataset.bkSubmitted);
	});

	/* ------------------------------------------------------------------ *
	 * 2b. Formularios: sin doble envío (el checkout lo controla WooCommerce)
	 * ------------------------------------------------------------------ */
	if (clicks) document.addEventListener('submit', (e) => {
		const form = e.target;
		if (!(form instanceof HTMLFormElement) || form.matches('form.checkout, [data-bk-allow-resubmit]')) return;
		if (form.dataset.bkSubmitted) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}
		form.dataset.bkSubmitted = '1';
		const btn = e.submitter || form.querySelector('[type="submit"]');
		btn?.classList.add('is-busy');
		btn?.setAttribute('aria-busy', 'true');
		// Si otro script cancela el envío (AJAX), se libera el formulario.
		window.setTimeout(() => {
			if (e.defaultPrevented) {
				delete form.dataset.bkSubmitted;
				btn?.classList.remove('is-busy');
				btn?.removeAttribute('aria-busy');
			}
		}, 0);
	}, true);

	/* ------------------------------------------------------------------ *
	 * 2c. Producto variable sin talla: mensaje en línea en vez de alert()
	 * Se ejecuta en captura, antes del manejador de WooCommerce que abre el alert.
	 * ------------------------------------------------------------------ */
	if (clicks) document.addEventListener('click', (e) => {
		const btn = e.target.closest('.single_add_to_cart_button.disabled');
		if (!btn) return;
		const form = btn.closest('form.variations_form');
		if (!form) return;
		e.preventDefault();
		e.stopImmediatePropagation();

		const unavailable = btn.classList.contains('wc-variation-is-unavailable');
		const msg = unavailable ? t('unavailable') : t('chooseSize');
		let box = form.querySelector('.bk-form-msg');
		if (!box) {
			box = document.createElement('p');
			box.className = 'bk-form-msg';
			box.setAttribute('role', 'alert');
			(form.querySelector('.variations') || form).insertAdjacentElement('afterend', box);
		}
		box.textContent = msg;

		const variations = form.querySelector('.variations');
		if (variations) {
			variations.classList.remove('bk-needs-choice');
			void variations.offsetWidth; // Reinicia la animación.
			variations.classList.add('bk-needs-choice');
			variations.scrollIntoView({ block: 'center', behavior: reduceMotion.matches ? 'auto' : 'smooth' });
			const empty = [...form.querySelectorAll('.variations select')].find((s) => !s.value);
			const target = empty?.closest('tr, .value')?.querySelector('button:not([disabled]), [role="radio"], [tabindex="0"], select');
			(target || empty)?.focus({ preventScroll: true });
		}
	}, true);

	// Al elegir una talla se limpia el aviso.
	if (clicks) document.addEventListener('change', (e) => {
		const form = e.target.closest?.('form.variations_form');
		if (!form) return;
		form.querySelector('.bk-form-msg')?.remove();
		form.querySelector('.variations')?.classList.remove('bk-needs-choice');
	});

	/* ------------------------------------------------------------------ *
	 * 2d. Añadir al carrito por AJAX: anuncio accesible (WooCommerce usa eventos jQuery)
	 * ------------------------------------------------------------------ */
	if (clicks && window.jQuery) {
		window.jQuery(document.body).on('added_to_cart', () => announce(t('added')));
	}

	/* ------------------------------------------------------------------ *
	 * 2e. Tarjetas de producto: toda la tarjeta lleva a la ficha (no hay zonas "muertas")
	 * ------------------------------------------------------------------ */
	if (clicks) document.addEventListener('click', (e) => {
		if (e.defaultPrevented || e.button !== 0) return;
		const card = e.target.closest('.products .product, .bk-card');
		if (!card || e.target.closest('a, button, input, select, label, textarea, [role="button"], .bk-carousel')) return;
		const link = card.querySelector('a.woocommerce-LoopProduct-link, a.bk-card__link, a[href*="/producto/"]');
		if (!link) return;
		if (e.metaKey || e.ctrlKey) {
			window.open(link.href, '_blank', 'noopener');
		} else {
			link.click();
		}
	});

	/* ------------------------------------------------------------------ *
	 * 4. Carrusel [bk_hero]
	 * ------------------------------------------------------------------ */
	const initCarousel = (el) => {
		const track = el.querySelector('.bk-carousel__track');
		const slides = [...el.querySelectorAll('.bk-carousel__slide')];
		const dots = [...el.querySelectorAll('.bk-carousel__dot')];
		const toggle = el.querySelector('.bk-carousel__toggle');
		if (!track || slides.length < 2) return;

		const delay = Math.max(0, parseInt(el.dataset.autoplay, 10) || 0) * 1000;
		let index = 0;
		let timer = 0;
		let userPaused = reduceMotion.matches || !delay;
		let hovered = false;
		let visible = true;

		const setState = (i) => {
			index = i;
			slides.forEach((s, n) => {
				const on = n === i;
				s.toggleAttribute('aria-hidden', !on);
				s.querySelectorAll('a, button').forEach((f) => (on ? f.removeAttribute('tabindex') : f.setAttribute('tabindex', '-1')));
			});
			dots.forEach((d, n) => (n === i ? d.setAttribute('aria-current', 'true') : d.removeAttribute('aria-current')));
		};

		// Banners diferidos (data-bk-src/srcset): se cargan antes de mostrarlos.
		const load = (n) => {
			const sl = slides[(n + slides.length) % slides.length];
			sl?.querySelectorAll('[data-bk-srcset], [data-bk-src]').forEach((m) => {
				if (m.dataset.bkSrcset) { m.srcset = m.dataset.bkSrcset; m.removeAttribute('data-bk-srcset'); }
				if (m.dataset.bkSrc) { m.src = m.dataset.bkSrc; m.removeAttribute('data-bk-src'); }
			});
		};
		const go = (i) => {
			const n = (i + slides.length) % slides.length;
			load(n);
			load(n + 1);
			track.scrollTo({ left: n * track.clientWidth, behavior: reduceMotion.matches ? 'auto' : 'smooth' });
			setState(n);
		};

		const stop = () => { window.clearInterval(timer); timer = 0; };
		// No rota hasta la primera interacción: la página carga quieta (mejor Speed Index y menos distracción).
		let armed = false;
		const start = () => {
			stop();
			if (armed && !userPaused && !hovered && visible && !document.hidden) timer = window.setInterval(() => go(index + 1), delay);
		};
		const arm = () => {
			if (armed) return;
			armed = true;
			load(1); // El siguiente banner se descarga mientras se ve el primero.
			['pointermove', 'pointerdown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach((e) => window.removeEventListener(e, arm));
			start();
		};
		['pointermove', 'pointerdown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach((e) => window.addEventListener(e, arm, { passive: true }));
		const renderToggle = () => {
			if (!toggle) return;
			toggle.classList.toggle('is-paused', userPaused);
			toggle.setAttribute('aria-label', userPaused ? t('play') : t('pause'));
		};

		el.querySelector('.bk-carousel__prev')?.addEventListener('click', () => { go(index - 1); start(); });
		el.querySelector('.bk-carousel__next')?.addEventListener('click', () => { go(index + 1); start(); });
		dots.forEach((d, n) => d.addEventListener('click', () => { go(n); start(); }));
		toggle?.addEventListener('click', () => { userPaused = !userPaused; renderToggle(); start(); });

		// Pausa al pasar el cursor o al enfocar (WCAG 2.2.2).
		el.addEventListener('mouseenter', () => { hovered = true; stop(); });
		el.addEventListener('mouseleave', () => { hovered = false; start(); });
		el.addEventListener('focusin', () => { hovered = true; stop(); });
		el.addEventListener('focusout', (e) => { if (!el.contains(e.relatedTarget)) { hovered = false; start(); } });
		el.addEventListener('keydown', (e) => {
			if (e.key === 'ArrowRight') { go(index + 1); e.preventDefault(); }
			if (e.key === 'ArrowLeft') { go(index - 1); e.preventDefault(); }
		});

		// Sincroniza el índice al deslizar con el dedo.
		let raf = 0;
		track.addEventListener('scroll', () => {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(() => {
				const n = Math.round(track.scrollLeft / track.clientWidth);
				load(n); load(n + 1);
				if (n !== index && slides[n]) setState(n);
			});
		}, { passive: true });

		// Solo rota si está en pantalla y la pestaña está visible.
		new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; start(); }, { threshold: 0.25 }).observe(el);
		document.addEventListener('visibilitychange', start);

		setState(0);
		renderToggle();
		start();
	};

	const boot = () => document.querySelectorAll('.bk-carousel').forEach(initCarousel);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
