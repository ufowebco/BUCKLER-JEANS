/**
 * Video en la galería de producto.
 * - Se reproduce (silenciado) cuando su diapositiva está activa y visible; se pausa al salir.
 * - Botón accesible de reproducir/pausar (sin dead clicks: tocar el video también alterna).
 * - preload="none": no descarga nada hasta que la clienta llega al video.
 */
(() => {
	'use strict';

	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

	const init = (slide) => {
		const video = slide.querySelector('video');
		const btn = slide.querySelector('.bk-gallery-video__toggle');
		if (!video) return;

		let onScreen = false;
		let userPaused = reduceMotion.matches;

		const render = () => {
			const playing = !video.paused;
			slide.classList.toggle('is-playing', playing);
			if (btn) {
				btn.setAttribute('aria-pressed', String(playing));
				btn.setAttribute('aria-label', playing ? 'Pausar video' : 'Reproducir video');
			}
		};

		const play = () => {
			video.preload = 'auto';
			const p = video.play();
			if (p && p.catch) p.catch(() => render());
		};

		// Diapositiva activa: flexslider añade .flex-active-slide; sin slider, siempre activa.
		const isActive = () => !slide.closest('.flex-viewport') || slide.classList.contains('flex-active-slide');

		const sync = () => {
			if (isActive() && onScreen && !userPaused) {
				play();
			} else if (!video.paused) {
				video.pause();
			}
		};

		const toggle = () => {
			if (video.paused) {
				userPaused = false;
				play();
			} else {
				userPaused = true;
				video.pause();
			}
		};

		btn?.addEventListener('click', (e) => { e.preventDefault(); toggle(); });
		video.addEventListener('click', toggle);
		video.addEventListener('play', render);
		video.addEventListener('pause', render);

		new MutationObserver(sync).observe(slide, { attributes: true, attributeFilter: ['class'] });
		new IntersectionObserver(([entry]) => { onScreen = entry.isIntersecting; sync(); }, { threshold: 0.5 }).observe(slide);
		render();
	};

	const boot = () => document.querySelectorAll('.bk-gallery-video').forEach(init);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
