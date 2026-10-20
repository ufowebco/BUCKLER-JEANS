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
		// Fotos diferidas (data-bk-src): se cargan la actual y la siguiente; el póster del video, al acercarse.
		const load = (k) => {
			const sl = slides[k];
			if (!sl) return;
			sl.querySelectorAll('img[data-bk-src]').forEach((img) => {
				if (img.dataset.bkSrcset) img.srcset = img.dataset.bkSrcset;
				img.src = img.dataset.bkSrc;
				img.removeAttribute('data-bk-src');
			});
			const v = sl.querySelector('video[data-poster]');
			if (v) { v.poster = v.dataset.poster; v.removeAttribute('data-poster'); }
		};
		// Video: se reproduce solo (silenciado, en bucle, sin controles) al llegar a él; tocarlo lo pausa.
		let userPaused = false;
		const sync = (i) => {
			load(i); load(i + 1);
			slides.forEach((s, k) => {
				const v = s.querySelector('video');
				if (!v) return;
				if (k === i && !reduce && !userPaused) v.play().catch(() => {});
				else if (k !== i && !v.paused) v.pause();
			});
		};
		$$('video', track).forEach((v) => {
			const toggle = () => { if (v.paused) { userPaused = false; v.play().catch(() => {}); } else { userPaused = true; v.pause(); } };
			v.addEventListener('click', toggle);
			v.addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); } });
			v.addEventListener('play', () => v.closest('.bk-pg__slide').classList.remove('is-paused'));
			v.addEventListener('pause', () => v.closest('.bk-pg__slide').classList.add('is-paused'));
		});
		thumbs.forEach((b) => ['pointerenter', 'focus'].forEach((ev) => b.addEventListener(ev, () => load(Number(b.dataset.go)), { passive: true })));
		let raf = 0;
		let last = 0;
		track.addEventListener('scroll', () => {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(() => {
				const i = current();
				thumbs.forEach((b, k) => b.toggleAttribute('aria-current', k === i));
				if (i !== last) { last = i; sync(i); }
			});
		}, { passive: true });
		// La segunda foto se adelanta cuando la página ya cargó (deslizar se siente inmediato).
		const idle = window.requestIdleCallback || ((f) => setTimeout(f, 1200));
		window.addEventListener('load', () => idle(() => load(1)), { once: true });
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

			// ¡Últimas unidades!: aviso bajo los botones al elegir una talla con 1–lowMax prendas (max_qty de Woo).
			const lowMax = Number(T.lowMax) || 0;
			let low = null;
			const lowSync = () => {
				if (!lowMax) return;
				const v = variations.find((x) => x.attributes[attr] === select.value && x.is_in_stock);
				const n = v && Number(v.max_qty) > 0 && Number(v.max_qty) <= lowMax ? Number(v.max_qty) : 0;
				if (!low) {
					low = document.createElement('p');
					low.className = 'bk-size-low';
					low.setAttribute('aria-live', 'polite');
					group.after(low);
				}
				low.textContent = n ? (n === 1 ? T.lowOne : String(T.lowMany || '').replace('%d', n)) : '';
				low.hidden = !n;
			};
			if (lowMax) {
				$$('.bk-size', group).forEach((b) => {
					const v = variations.find((x) => x.attributes[attr] === b.dataset.value && x.is_in_stock);
					if (v && Number(v.max_qty) > 0 && Number(v.max_qty) <= lowMax) b.classList.add('is-low');
				});
				select.addEventListener('change', lowSync);
			}

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

	/* --- Guía de tallas: "Encuentra tu talla" y filtros de siluetas ------------------ */
	$$('[data-bk-sg-finder]').forEach((f) => {
		const rows = JSON.parse(f.dataset.sizes || '[]'); // [talla, cintura min, max, cadera min, max, línea]
		const names = JSON.parse(f.dataset.lines || '{}');
		const out = f.querySelector('[data-bk-sg-out]');
		const box = f.closest('dialog') || document;
		const num = (v) => parseFloat(String(v).replace(',', '.'));
		// La talla más pequeña cuyo rango cubre la medida (entre dos rangos, la siguiente).
		const pick = (v, a, b) => { const r = rows.find((x) => v <= x[b] + 0.5); return r ? r : null; };
		const run = () => {
			const w = num(f.w.value), h = num(f.h.value);
			$$('tr.is-match', box).forEach((tr) => tr.classList.remove('is-match'));
			if (!(w > 30) && !(h > 30)) { out.textContent = out.dataset.empty || out.textContent; return; }
			const rw = w > 30 ? pick(w, 1, 2) : null, rh = h > 30 ? pick(h, 3, 4) : null;
			if ((w > 30 && !rw) || (h > 30 && !rh)) { out.textContent = 'Escríbenos por WhatsApp y te asesoramos con tu talla.'; return; }
			const r = [rw, rh].filter(Boolean).sort((x, y) => rows.indexOf(y) - rows.indexOf(x))[0];
			const size = r[0];
			const inLines = [...new Set(rows.filter((x) => x[0] === size).map((x) => x[5]))];
			out.innerHTML = `Tu talla: <b>${size}</b> <span>· ${inLines.map((l) => names[l] || l).join(' o ')}</span>`;
			const own = box.querySelector('[role="tab"][aria-selected="true"]');
			if (own && !inLines.includes(own.dataset.bkSgTab)) box.querySelector(`[data-bk-sg-tab="${inLines[0]}"]`)?.click();
			$$(`tr[data-size="${CSS.escape(size)}"]`, box).forEach((tr) => tr.classList.add('is-match'));
			if (window.bkTrack) window.bkTrack('bk_size_finder', { size });
		};
		out.dataset.empty = out.textContent;
		f.addEventListener('input', run);
		f.addEventListener('submit', (e) => e.preventDefault());
	});
	document.addEventListener('click', (e) => {
		const b = e.target.closest('[data-bk-sil-g]');
		if (!b) return;
		const dlg = b.closest('dialog');
		$$('[data-bk-sil-g]', dlg).forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
		$$('.bk-sil', dlg).forEach((c) => { c.hidden = b.dataset.bkSilG !== 'all' && c.dataset.g !== b.dataset.bkSilG; });
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

	/* --- Carrito: cantidad con − / + y actualización automática ------------------- */
	const cartWrap = document.querySelector('.bk-woo--cart');
	if (cartWrap) {
		const t = (window.bkPages && window.bkPages.i18n) || {};
		let timer = 0;
		let refocus = null;
		const enhance = () => {
			const form = cartWrap.querySelector('.woocommerce-cart-form');
			if (!form) return;
			form.classList.add('is-auto');
			$$('.quantity', form).forEach((q) => {
				const input = q.querySelector('input.qty');
				if (q.dataset.bkQty || !input || input.type === 'hidden') return;
				q.dataset.bkQty = '1';
				const mk = (step, label, text) => {
					const b = document.createElement('button');
					b.type = 'button';
					b.className = 'bk-qty-btn';
					b.dataset.step = String(step);
					b.setAttribute('aria-label', label);
					b.textContent = text;
					return b;
				};
				q.prepend(mk(-1, t.less || 'Quitar uno', '−'));
				q.append(mk(1, t.more || 'Añadir uno', '+'));
				sync(q);
			});
			if (refocus) {
				const el = form.querySelector(`input[name="${refocus.name}"]`);
				const target = el && (refocus.step ? el.closest('.quantity').querySelector(`.bk-qty-btn[data-step="${refocus.step}"]`) : el);
				if (target && !target.disabled) {
					target.focus();
					// WooCommerce puede mover el foco al aviso "Carrito actualizado": se reafirma.
					setTimeout(() => { if (document.activeElement !== target && target.isConnected) target.focus(); }, 300);
				}
				refocus = null;
			}
		};
		const sync = (q) => {
			const input = q.querySelector('input.qty');
			const v = parseInt(input.value, 10) || 0;
			const max = parseInt(input.max, 10) || Infinity;
			q.querySelector('.bk-qty-btn[data-step="-1"]').disabled = v <= 1;
			q.querySelector('.bk-qty-btn[data-step="1"]').disabled = v >= max;
		};
		const update = (input, step) => {
			clearTimeout(timer);
			timer = setTimeout(() => {
				const btn = cartWrap.querySelector('button[name="update_cart"]');
				if (!btn) return;
				refocus = { name: input.name, step };
				btn.disabled = false;
				btn.removeAttribute('aria-disabled');
				btn.click();
			}, 650);
		};
		cartWrap.addEventListener('click', (e) => {
			const b = e.target.closest('.bk-qty-btn');
			if (!b) return;
			const q = b.closest('.quantity');
			const input = q.querySelector('input.qty');
			const max = parseInt(input.max, 10) || Infinity;
			const v = Math.min(max, Math.max(1, (parseInt(input.value, 10) || 0) + Number(b.dataset.step)));
			if (String(v) === input.value) return;
			input.value = String(v);
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});
		cartWrap.addEventListener('change', (e) => {
			const input = e.target.closest('input.qty');
			if (!input) return;
			sync(input.closest('.quantity'));
			const active = document.activeElement;
			update(input, active && active.classList.contains('bk-qty-btn') ? active.dataset.step : '');
		});
		enhance();
		if (window.jQuery) window.jQuery(document.body).on('updated_wc_div updated_cart_totals', enhance);
	}
})();
