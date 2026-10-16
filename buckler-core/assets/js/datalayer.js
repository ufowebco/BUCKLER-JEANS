/**
 * Comercio electrónico de GA4 en el dataLayer (lado del navegador). Los eventos de página (view_item, view_cart,
 * begin_checkout, purchase) los imprime el servidor (includes/pages/datalayer.php); aquí van los de acciones:
 * add_to_cart y remove_from_cart (carrito flotante → bkEcom.storeItem; carrito → enlace "Quitar"),
 * add_payment_info (al enviar el checkout) y la compra que la pasarela confirma después de cargar la página de gracias.
 */
(() => {
	'use strict';
	const D = window.bkDL || {};
	const dl = (window.dataLayer = window.dataLayer || []);
	const push = (event, ecommerce, extra = {}) => { dl.push({ ecommerce: null }); dl.push({ event, ecommerce, ...extra }); };
	const currency = D.currency || 'COP';

	// Item de la Store API → item de GA4 (con línea y prenda si es el producto de la ficha).
	const fromStore = (it, qty) => {
		const minor = it.prices?.currency_minor_unit ?? 0;
		const price = Math.round((Number(it.prices?.price || 0) / 10 ** minor) * 100) / 100;
		const sku = it.sku || String(it.id);
		const base = D.product && D.product.item_id === sku ? { ...D.product } : { item_id: sku, item_name: it.name, item_brand: 'Buckler Jeans' };
		delete base.item_variant;
		const variant = (it.variation || []).map((v) => v.value).join(' / ');
		return { ...base, ...(variant ? { item_variant: variant } : {}), price, quantity: Number(qty) || 1 };
	};

	const storeItem = (event, it, qty) => {
		const item = fromStore(it, qty);
		push(event, { currency, value: Math.round(item.price * item.quantity * 100) / 100, items: [item] });
	};
	window.bkEcom = { push, storeItem };

	// Carrito: enlace "Quitar" (remove_item=CLAVE) → remove_from_cart con los datos de view_cart.
	document.addEventListener('click', (e) => {
		const a = e.target.closest?.('a.remove[href*="remove_item="]');
		if (!a || !D.cart) return;
		const key = new URL(a.href, location.href).searchParams.get('remove_item');
		const item = D.cart[key];
		if (item) push('remove_from_cart', { currency, value: Math.round(item.price * item.quantity * 100) / 100, items: [item] });
	}, true);

	// Checkout: al enviar el pedido → add_payment_info (una vez por página).
	let paid = false;
	document.addEventListener('submit', (e) => {
		if (paid || !D.checkout || !e.target.matches?.('form.checkout')) return;
		paid = true;
		const m = e.target.querySelector('input[name="payment_method"]:checked');
		const label = m ? (e.target.querySelector(`label[for="${m.id}"]`)?.textContent || m.value).trim().replace(/\s+/g, ' ') : '';
		push('add_payment_info', { currency, value: D.checkout.value, payment_type: label, items: D.checkout.items });
	}, true);

	// Gracias con el pago aún en confirmación: pregunta cada 5 s (2 min) y empuja purchase al confirmarse.
	if (D.wait) {
		let tries = 0;
		const ask = async () => {
			tries += 1;
			try {
				const r = await fetch(`${D.wait.url}?order=${D.wait.order}&key=${encodeURIComponent(D.wait.key)}`, { credentials: 'same-origin', cache: 'no-store' });
				const d = await r.json();
				if (d.status === 'paid' && d.event) { dl.push({ ecommerce: null }); dl.push(d.event); return; }
				if (['sent', 'invalid', 'cancelled', 'refunded'].includes(d.status)) return;
			} catch (err) { /* Red: reintenta. */ }
			if (tries < 24) setTimeout(ask, 5000);
		};
		setTimeout(ask, 3000);
	}
})();
