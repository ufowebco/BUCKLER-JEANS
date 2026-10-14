/**
 * Páginas limpias: galería de la ficha, tallas accesibles, guía de tallas (dialog) y pestañas del inicio.
 * JS propio sin dependencias. La compra sigue usando el formulario y el JS de variaciones de WooCommerce.
 */
(() => {
	'use strict';

	const T = (window.bkPages && window.bkPages.i18n) || {};
	const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
	const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* --- Galería de la ficha -------------------------------------------------- */
	$$('[data-bk-pg]').forEach((pg) => {
		const track = pg.querySelector('.bk-pg__track');
		const slides = $$('.bk-pg__slide', track);
		const thumbs = $$('.bk-pg__thumb', pg);
		if (!track || slides.length < 2) return;
		const current = () => Math.round(track.scrollLeft / track.clientWidth);
		const go = (i) => {
			const n = Math.max(0, Math.min(slides.length - 1, i));
			track.scrollTo({ left: n * track.clientWidth, behavior: reduce ? 'auto' : 'smooth' });
		};
		$$('.bk-pg__nav', pg).forEach((b) => b.addEventListener('click', () => go(current() + Number(b.dataset.dir))));
		thumbs.forEach((b) => b.addEventListener('click', () => go(Number(b.dataset.go))));
		track.addEventListener('keydown', (e) => {
			if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
			if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
		});
		let raf = 0;
		track.addEventListener('scroll', () => {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(() => {
				const i = current();
				thumbs.forEach((b, k) => b.toggleAttribute('aria-current', k === i));
				// El video solo suena/corre cuando está a la vista.
				slides.forEach((s, k) => { const v = s.querySelector('video'); if (v && k !== i && !v.paused) v.pause(); });
			});
		}, { passive: true });
	});

	/* --- Tallas: el <select> de WooCommerce se muestra como botones (radiogroup) ---- */
	$$('form.variations_form').forEach((form) => {
		let variations = [];
		try { variations = JSON.parse(form.dataset.product_variations || '[]') || []; } catch (e) { variations = []; }
		$$('select[name^="attribute_"]', form).forEach((select) => {
			const attr = select.name;
			const cell = select.closest('td') || select.parentElement;
			// Marcado del plugin anterior (si sigue activo): se oculta, se usan los botones propios.
			$$('.variable-items-wrapper, .active-option', cell).forEach((el) => { el.hidden = true; });
			select.classList.add('bk-visually-hidden');
			select.removeAttribute('style');
			select.setAttribute('tabindex', '-1');
			select.setAttribute('aria-hidden', 'true');

			const label = (form.querySelector(`label[for="${select.id}"]`) || {}).textContent || T.size || 'Talla';
			const group = document.createElement('div');
			group.className = 'bk-sizes';
			group.setAttribute('role', 'radiogroup');
			group.setAttribute('aria-label', label.trim());
			const inStock = (value) => !variations.length || variations.some((v) => v.is_in_stock && (v.attributes[attr] === value || v.attributes[attr] === ''));

			const opts = [...select.options].filter((o) => o.value);
			// Tallas numéricas en orden (6, 8, 10, 12) aunque el atributo venga desordenado.
			if (opts.every((o) => /^\d+([.,]\d+)?$/.test(o.value))) opts.sort((x, y) => parseFloat(x.value) - parseFloat(y.value));
			opts.forEach((o) => {
				const b = document.createElement('button');
				b.type = 'button';
				b.className = 'bk-size';
				b.setAttribute('role', 'radio');
				b.dataset.value = o.value;
				const ok = inStock(o.value);
				b.textContent = o.textContent.trim() === 'instock' || o.textContent.trim() === 'outofstock' ? o.value : o.textContent.trim();
				if (!ok) {
					b.classList.add('is-out');
					b.setAttribute('aria-disabled', 'true');
					b.setAttribute('aria-label', `${b.textContent}, ${T.soldOut || 'agotada'}`);
				}
				group.appendChild(b);
			});
			cell.prepend(group);

			const sync = () => {
				const btns = $$('.bk-size', group);
				btns.forEach((b) => {
					const on = b.dataset.value === select.value;
					b.setAttribute('aria-checked', String(on));
					b.tabIndex = on || (!select.value && b === btns.find((x) => !x.classList.contains('is-out'))) ? 0 : -1;
				});
			};
			const choose = (b) => {
				if (b.classList.contains('is-out')) return;
				select.value = b.dataset.value;
				select.dispatchEvent(new Event('change', { bubbles: true }));
				sync();
			};
			group.addEventListener('click', (e) => { const b = e.target.closest('.bk-size'); if (b) choose(b); });
			group.addEventListener('keydown', (e) => {
				const btns = $$('.bk-size:not(.is-out)', group);
				const i = btns.indexOf(document.activeElement);
				if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].includes(e.key) || i < 0) return;
				e.preventDefault();
				const n = btns[(i + (e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : btns.length - 1)) % btns.length];
				n.focus();
				choose(n);
			});
			select.addEventListener('change', sync);
			// WooCommerce restablece las opciones con "Limpiar": volver a sincronizar.
			form.addEventListener('reset_data', sync);
			if (window.jQuery) window.jQuery(form).on('reset_data woocommerce_update_variation_values', sync);
			sync();
		});
	});

	/* --- Diálogos (guía de tallas) ----------------------------------------------- */
	document.addEventListener('click', (e) => {
		const open = e.target.closest('[data-bk-dialog]');
		if (open) {
			const d = document.getElementById(open.dataset.bkDialog);
			if (d && d.showModal) { d.showModal(); d.querySelector('[data-bk-close]')?.focus(); }
			return;
		}
		const close = e.target.closest('[data-bk-close]');
		if (close) { close.closest('dialog')?.close(); return; }
		if (e.target.tagName === 'DIALOG') e.target.close(); // Clic en el fondo.
	});

	/* --- Pestañas (Lo nuevo Slim / Curvy) ---------------------------------------- */
	$$('[role="tablist"]').forEach((list) => {
		const tabs = $$('[role="tab"]', list);
		const show = (tab, focus) => {
			tabs.forEach((t) => {
				const on = t === tab;
				t.setAttribute('aria-selected', String(on));
				t.tabIndex = on ? 0 : -1;
				const panel = document.getElementById(t.getAttribute('aria-controls'));
				if (panel) panel.hidden = !on;
			});
			if (focus) tab.focus();
		};
		list.addEventListener('click', (e) => { const t = e.target.closest('[role="tab"]'); if (t) show(t); });
		list.addEventListener('keydown', (e) => {
			const i = tabs.indexOf(document.activeElement);
			if (i < 0) return;
			if (e.key === 'ArrowRight') { e.preventDefault(); show(tabs[(i + 1) % tabs.length], true); }
			if (e.key === 'ArrowLeft') { e.preventDefault(); show(tabs[(i + tabs.length - 1) % tabs.length], true); }
		});
	});
})();
