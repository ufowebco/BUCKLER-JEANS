/**
 * Tarjetas de producto: slider de galería (flechas, puntos, deslizar) y carga diferida de las fotos extra.
 * Delegado en document: funciona también con grillas cargadas por AJAX (filtros, paginación).
 */
(() => {
	'use strict';

	const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

	// Carga las fotos 2..n de una tarjeta (solo la primera vez que la clienta interactúa).
	const hydrate = (card) => {
		if (!card || card.dataset.bkLoaded) return;
		card.dataset.bkLoaded = '1';
		card.querySelectorAll('img[data-bk-src]').forEach((img) => {
			if (img.dataset.bkSrcset) img.srcset = img.dataset.bkSrcset;
			img.src = img.dataset.bkSrc;
			img.removeAttribute('data-bk-src');
		});
	};

	const state = (card) => {
		const track = card.querySelector('.bk-card__track');
		const n = track ? track.children.length : 0;
		const i = track && track.clientWidth ? Math.round(track.scrollLeft / track.clientWidth) : 0;
		return { track, n, i };
	};

	const render = (card) => {
		const { n, i } = state(card);
		card.querySelectorAll('.bk-card__dots i').forEach((d, k) => d.classList.toggle('on', k === i));
		const prev = card.querySelector('.bk-card__prev');
		const next = card.querySelector('.bk-card__next');
		if (prev) prev.disabled = i <= 0;
		if (next) next.disabled = i >= n - 1;
	};

	const go = (card, dir) => {
		hydrate(card);
		const { track, n, i } = state(card);
		if (!track) return;
		const to = Math.min(n - 1, Math.max(0, i + dir));
		track.scrollTo({ left: to * track.clientWidth, behavior: reduce.matches ? 'auto' : 'smooth' });
	};

	document.addEventListener('click', (e) => {
		const arrow = e.target.closest('.bk-card__arrow');
		if (!arrow) return;
		e.preventDefault();
		e.stopPropagation();
		go(arrow.closest('[data-bk-card]'), arrow.classList.contains('bk-card__next') ? 1 : -1);
	});

	// Precarga al acercarse con el cursor o al tocar (la foto ya está lista al deslizar).
	const warm = (e) => hydrate(e.target.closest?.('[data-bk-card]'));
	document.addEventListener('pointerover', warm, { passive: true });
	document.addEventListener('touchstart', warm, { passive: true });
	document.addEventListener('focusin', warm);

	// Puntos sincronizados al deslizar.
	document.addEventListener('scroll', (e) => {
		const t = e.target;
		if (t instanceof Element && t.classList.contains('bk-card__track')) {
			const card = t.closest('[data-bk-card]');
			hydrate(card);
			requestAnimationFrame(() => render(card));
		}
	}, { capture: true, passive: true });

	const boot = () => document.querySelectorAll('[data-bk-card]').forEach(render);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	window.bkCards = { boot };
})();
