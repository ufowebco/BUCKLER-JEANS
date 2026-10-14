/**
 * Captura del correo en el checkout para recordatorios de carrito abandonado.
 * Envía al salir del campo (o al marcar la autorización) y solo si el correo es válido.
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
	let consentTouched = false; // Solo se borra el carrito si la clienta DESMARCA la casilla a propósito.

	const send = () => {
		const email = (emailEl()?.value || '').trim();
		const consent = cfg.mode === 'notice' || !!consentEl()?.checked;
		const key = `${email}|${consent}`;
		if (key === last || (!valid(email) && consent)) return;
		if (!consent && !consentTouched) return; // Sin autorización no se envía nada.
		last = key;

		const body = new URLSearchParams({
			nonce: cfg.nonce,
			email,
			consent: consent ? '1' : '',
			first_name: ($('#billing_first_name')?.value || '').trim(),
			phone: ($('#billing_phone')?.value || '').trim(),
		});
		fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => { last = ''; });
	};
	const queue = () => { clearTimeout(timer); timer = setTimeout(send, 400); };

	document.addEventListener('change', (e) => {
		if (e.target === consentEl()) consentTouched = true;
		if (e.target === emailEl() || e.target === consentEl() || e.target.id === 'billing_first_name' || e.target.id === 'billing_phone') queue();
	});
	document.addEventListener('focusout', (e) => { if (e.target === emailEl()) queue(); });

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

	// Si el correo ya venía lleno (clienta registrada o restaurada desde el correo).
	if (valid((emailEl()?.value || '').trim())) queue();
})();
