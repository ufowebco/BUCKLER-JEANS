/**
 * Captura del correo en el checkout para recordatorios de carrito abandonado.
 * Guarda el carrito en cuanto hay un correo válido o un celular (al escribir, al salir del campo y al cerrar o
 * cambiar de pestaña con sendBeacon). Sin la autorización se guarda para la tienda, pero no se envían correos.
 * Funciona con el checkout clásico (Divi / shortcode) y con el de bloques.
 */
(() => {
	'use strict';

	const cfg = window.bkAc;
	if (!cfg) return;

	const $ = (sel) => document.querySelector(sel);
	const emailEl = () => $('#billing_email') || $('.wc-block-checkout #email') || $('input[type="email"][autocomplete="email"]');
	const consentEl = () => $('#bk_ac_consent');
	const valid = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);

	let last = '';
	let timer = 0;

	const val = (id) => ($(`#${id}`)?.value || '').trim();
	// Campos que se guardan con el carrito (si existen en el formulario).
	const FIELDS = { first_name: 'billing_first_name', last_name: 'billing_last_name', phone: 'billing_phone', id_number: 'billing_id', city: 'billing_city', state: 'billing_state', address: 'billing_address_1' };
	const IDS = Object.values(FIELDS);

	const payload = () => {
		const email = (emailEl()?.value || '').trim();
		const phone = val('billing_phone');
		// Basta con un correo válido O un celular (7+ dígitos): la tienda siempre ve el carrito.
		if (!valid(email) && phone.replace(/\D/g, '').length < 7) return null;
		const consent = cfg.mode === 'notice' || !!consentEl()?.checked;
		const data = { nonce: cfg.nonce, email: valid(email) ? email : '', consent: consent ? '1' : '' };
		for (const [k, id] of Object.entries(FIELDS)) data[k] = val(id);
		const key = JSON.stringify(data);
		if (key === last) return null;
		last = key;
		return new URLSearchParams(data);
	};
	const send = () => {
		clearTimeout(timer);
		const body = payload();
		if (body) fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => { last = ''; });
	};
	// Al cerrar la pestaña o salir: el navegador entrega el beacon aunque la página ya no exista.
	const flush = () => {
		clearTimeout(timer);
		const body = payload();
		if (body && !(navigator.sendBeacon && navigator.sendBeacon(cfg.url, body))) send();
	};
	const queue = (ms = 400) => { clearTimeout(timer); timer = setTimeout(send, ms); };
	const watched = (el) => el && (el === emailEl() || el === consentEl() || IDS.includes(el.id));

	document.addEventListener('input', (e) => { if (watched(e.target)) queue(1200); });
	document.addEventListener('change', (e) => { if (watched(e.target)) queue(); });
	document.addEventListener('focusout', (e) => { if (watched(e.target)) queue(); });
	document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
	window.addEventListener('pagehide', flush);

	// Modo "notice": aviso informativo bajo el correo (sin casilla).
	if (cfg.mode === 'notice' && cfg.notice) {
		const place = () => {
			const el = emailEl();
			if (!el || document.querySelector('.bk-ac-notice')) return;
			const p = document.createElement('p');
			p.className = 'bk-ac-notice';
			p.textContent = cfg.notice;
			p.style.cssText = 'margin:6px 0 0;font-size:.8125rem;color:#5b6773;';
			(el.closest('.form-row, .wc-block-components-text-input') || el).insertAdjacentElement('afterend', p);
		};
		place();
		document.body.addEventListener('updated_checkout', place);
	}

	// Si los datos ya venían llenos (clienta registrada o restaurada desde el correo).
	queue();
})();
