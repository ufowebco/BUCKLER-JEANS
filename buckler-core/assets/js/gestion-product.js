/**
 * Gestión de tienda: crear y editar productos (#productos/nuevo, #productos/editar/ID,
 * #productos/nuevo?desde=ID → mismo modelo en la otra línea).
 * Depende de window.bkGApp (gestion.js). Datos: bk/v1/gestion/product-form, products/{id}/form, media.
 */
(() => {
	'use strict';

	const A = window.bkGApp;
	if (!A) return;
	const { C, api, esc, $, $$, toast, busy, main } = A;

	let meta = null; // Líneas, tallas y prendas (se pide una vez).
	let st = null; // Estado del formulario.
	let dirty = false;

	const money = (v) => { const n = String(v ?? '').replace(/\D/g, ''); return n ? `$${Number(n).toLocaleString('es-CO')}` : ''; };
	const digits = (v) => String(v ?? '').replace(/\D/g, '');
	const fmt = (v) => { const n = digits(v); return n ? Number(n).toLocaleString('es-CO') : ''; };
	const mb = (b) => `${Math.round(b / 1048576)} MB`;
	const otherLine = (line) => Object.keys(meta.lines).find((k) => k !== line) || '';

	/* --- Limpieza de la descripción: solo formato básico, sin atributos (ni scripts) ------------- */
	const ALLOWED = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'UL', 'OL', 'LI', 'H3', 'A']);
	const cleanHtml = (html) => {
		const doc = new DOMParser().parseFromString(`<div>${html || ''}</div>`, 'text/html');
		const walk = (node) => [...node.childNodes].map((n) => {
			if (n.nodeType === 3) return esc(n.textContent);
			if (n.nodeType !== 1) return '';
			const inner = walk(n);
			if (!ALLOWED.has(n.tagName)) return n.tagName === 'DIV' ? `<p>${inner}</p>` : inner;
			const tag = ({ B: 'strong', I: 'em' })[n.tagName] || n.tagName.toLowerCase();
			if (tag === 'br') return '<br>';
			const href = tag === 'a' && /^https?:\/\//i.test(n.getAttribute('href') || '') ? ` href="${esc(n.getAttribute('href'))}"` : '';
			return `<${tag}${href}>${inner}</${tag}>`;
		}).join('');
		return walk(doc.body.firstChild).replace(/<p>\s*<\/p>/g, '').trim();
	};

	/* --- Vista ----------------------------------------------------------------------------- */
	async function view(sub, params) {
		const seq = A.seq();
		main.innerHTML = '<div class="bk-g-loading">Cargando…</div>';
		try {
			meta = meta || await api('product-form');
			let d;
			if (sub[0] === 'editar' && /^\d+$/.test(sub[1] || '')) d = await api(`products/${sub[1]}/form`);
			else if (sub[0] === 'nuevo' && /^\d+$/.test(params.get('desde') || '')) d = await api(`products/${params.get('desde')}/form?twin=1`);
			else if (sub[0] === 'nuevo') d = { id: 0, name: '', sku: '', line: '', cats: [], all_cats: [], is_new: true, status: 'publish', short: '', description: '', regular: '', sale: '', mixed: false, sizes: {}, images: [], video: null };
			else { location.hash = '#productos'; return; }
			if (seq !== A.seq()) return;
			st = { ...d, all_cats: [...(d.all_cats || [])], images: [...d.images], uploading: 0, origRegular: d.regular, origSale: d.sale };
			render();
			dirty = false;
		} catch (e) { main.innerHTML = `<p class="bk-g-error">${esc(e.message)}</p>`; }
	}

	const sizesHtml = () => {
		const line = meta.lines[st.line];
		if (!line) return '<p class="bk-g-hint">Elige primero la línea para ver sus tallas.</p>';
		return `<table class="bk-gp-sizes"><thead><tr><th scope="col">Talla</th><th scope="col">Stock</th></tr></thead><tbody>
			${line.sizes.map((z) => {
				const cur = st.sizes[z.slug];
				const on = cur ? cur.enabled : !st.id;
				const stock = cur && cur.stock != null ? cur.stock : '';
				return `<tr><th scope="row"><label class="bk-gp-size"><input type="checkbox" name="size_on" value="${esc(z.slug)}" ${on ? 'checked' : ''}><span>${esc(z.name)}</span></label></th>
					<td><input type="number" name="stock_${esc(z.slug)}" min="0" step="1" inputmode="numeric" value="${esc(stock)}" placeholder="0" aria-label="Stock talla ${esc(z.name)}" ${on ? '' : 'disabled'}></td></tr>`;
			}).join('')}</tbody></table>`;
	};

	const catsHtml = () => {
		const line = meta.lines[st.line];
		if (!line) return '<p class="bk-g-hint">Elige primero la línea.</p>';
		return line.cats.length ? `<div class="bk-gp-chips">${line.cats.map((c) => `<label class="bk-gp-chip"><input type="checkbox" name="cats" value="${c.id}" ${st.cats.includes(c.id) ? 'checked' : ''}><span>${esc(c.name)}</span></label>`).join('')}</div>` : '<p class="bk-g-hint">Esta línea no tiene prendas (subcategorías).</p>';
	};

	// Todas las categorías de la tienda (árbol). Se sincroniza con "Prenda" y con la línea.
	const treeHtml = () => `<details class="bk-gp-tree"><summary>Todas las categorías <small>(${st.all_cats.length} marcadas)</small></summary>
		<p class="bk-g-hint">Marca o desmarca cualquier categoría de la tienda. "Nuevo" y "¡Sale!" también se ajustan solos al cambiar el interruptor Nuevo o el precio rebajado.</p>
		<ul>${meta.allCats.map((c) => `<li style="--d:${c.depth}"><label class="bk-gp-tcat"><input type="checkbox" name="all_cats" value="${c.id}" ${st.all_cats.includes(c.id) ? 'checked' : ''}><span>${esc(c.name)}</span><small>${c.count}</small></label></li>`).join('')}</ul></details>`;
	const setTree = (id, on) => {
		const i = st.all_cats.indexOf(id);
		if (on && i < 0) st.all_cats.push(id);
		if (!on && i >= 0) st.all_cats.splice(i, 1);
		const box = document.querySelector(`#bk-gp [name="all_cats"][value="${id}"]`);
		if (box) box.checked = on;
		const sum = document.querySelector('#bk-gp .bk-gp-tree summary small');
		if (sum) sum.textContent = `(${st.all_cats.length} marcadas)`;
	};

	const imagesHtml = () => `<ul class="bk-gp-imgs" aria-label="Fotos del producto">
		${st.images.map((im, i) => `<li class="bk-gp-img">
			<img src="${esc(im.url)}" alt="Foto ${i + 1}" width="120" height="160" loading="lazy">
			${i === 0 ? '<span class="bk-gp-main">Principal</span>' : ''}
			<div class="bk-gp-imgbar">
				<button type="button" class="bk-g-icon" data-img-move="${i}" data-dir="-1" ${i === 0 ? 'disabled' : ''} aria-label="Mover la foto ${i + 1} antes">${C.icons.left}</button>
				<button type="button" class="bk-g-icon" data-img-move="${i}" data-dir="1" ${i === st.images.length - 1 ? 'disabled' : ''} aria-label="Mover la foto ${i + 1} después">${C.icons.right}</button>
				<button type="button" class="bk-g-icon is-danger" data-img-del="${i}" aria-label="Quitar la foto ${i + 1}">${C.icons.trash}</button>
			</div></li>`).join('')}
		${Array.from({ length: st.uploading }, () => '<li class="bk-gp-img is-loading"><span>Subiendo…</span></li>').join('')}
		<li class="bk-gp-add"><label class="bk-gp-drop" data-drop>
			${C.icons.image}<span>Agregar fotos</span><small>JPG, PNG o WebP · se optimizan al subir</small>
			<input type="file" name="photos" accept="image/jpeg,image/png,image/webp" multiple class="bk-g-sr"></label></li>
	</ul>`;

	const videoHtml = () => st.video ? `<div class="bk-gp-video">
			<video src="${esc(st.video.url)}" muted playsinline controls preload="metadata" width="160"></video>
			<button type="button" class="bk-g-btn is-ghost is-small" data-video-del>${C.icons.trash} Quitar video</button></div>`
		: `<label class="bk-gp-drop is-row">${C.icons.video}<span>Agregar video</span><small>MP4 · máx. ${mb(meta.maxVideo)} · se muestra en la galería de la ficha</small>
			<input type="file" name="video" accept="video/mp4" class="bk-g-sr"></label>`;

	const discount = () => {
		const r = Number(digits(st.regular)); const s = Number(digits(st.sale));
		return r && s && s < r ? `${Math.round((1 - s / r) * 100)} % de descuento` : '';
	};

	function render() {
		const lines = meta.lines;
		const isEdit = !!st.id;
		const other = st.line ? otherLine(st.line) : '';
		main.innerHTML = `
			<a class="bk-g-back" href="#productos">${C.icons.back} Productos</a>
			<h1 class="bk-g-h1">${isEdit ? `Editar: ${esc(st.name)}` : 'Nuevo producto'}</h1>
			${st.from ? `<p class="bk-gp-note">Base copiada de otro producto: revisa el nombre, sube las fotos de la ${esc(lines[st.line]?.label || '')} y escribe el stock de cada talla.</p>` : ''}
			<form id="bk-gp" class="bk-gp" novalidate>
				<div class="bk-gp-col">
					<section class="bk-g-card"><h2>Información</h2>
						<label class="bk-gp-field"><span>Nombre del producto <b class="bk-gp-req" aria-hidden="true">*</b></span>
							<input name="name" required maxlength="200" value="${esc(st.name)}" autocomplete="off" aria-describedby="bk-gp-name-h">
							<small id="bk-gp-name-h">Así aparece en la tienda. Ej.: JEAN BOTA ANCHA DAMA – OXFORD – TIRO ALTO</small></label>
						<label class="bk-gp-field"><span>REF <b class="bk-gp-req" aria-hidden="true">*</b></span>
							<input name="sku" required maxlength="60" value="${esc(st.sku)}" autocomplete="off" aria-describedby="bk-gp-sku-h">
							<small id="bk-gp-sku-h">${lines[st.line]?.suffix ? `En ${esc(lines[st.line].label)} la REF termina en${esc(lines[st.line].suffix)} (ej.: 816${esc(lines[st.line].suffix)}).` : 'Debe ser única. El mismo modelo en Curvy lleva PLUS al final.'}</small></label>
						<fieldset class="bk-gp-field" data-field="line"><legend>Línea <b class="bk-gp-req" aria-hidden="true">*</b></legend>
							${isEdit ? `<p class="bk-gp-static">${esc(lines[st.line]?.label || 'Sin línea')}</p>` : `<div class="bk-gp-lines">${Object.entries(lines).map(([k, l]) => `<label class="bk-gp-line"><input type="radio" name="line" value="${k}" ${st.line === k ? 'checked' : ''} required><span><b>${esc(l.label)}</b><small>${esc(l.note)}</small></span></label>`).join('')}</div>`}
						</fieldset>
						<fieldset class="bk-gp-field" data-field="cats"><legend>Prenda</legend><div id="bk-gp-cats">${catsHtml()}</div></fieldset>
						<div id="bk-gp-tree">${treeHtml()}</div>
						<label class="bk-g-switch"><input type="checkbox" name="is_new" ${st.is_new ? 'checked' : ''}><span>Mostrar como <b>Nuevo</b> (etiqueta y sección "Lo nuevo")</span></label>
					</section>
					<section class="bk-g-card" data-field="images"><h2>Fotos y video</h2>
						<p class="bk-g-hint">La primera foto es la principal. Usa fotos verticales del mismo tamaño. El texto alternativo se completa solo con el nombre.</p>
						<div id="bk-gp-imgs">${imagesHtml()}</div>
						<div id="bk-gp-video">${videoHtml()}</div>
					</section>
					<section class="bk-g-card"><h2>Descripción</h2>
						<label class="bk-gp-field"><span>Descripción corta</span>
							<textarea name="short" rows="3" maxlength="600" aria-describedby="bk-gp-short-h">${esc(st.short)}</textarea>
							<small id="bk-gp-short-h">Aparece junto al precio. 1 o 2 frases.</small></label>
						<div class="bk-gp-field"><span id="bk-gp-desc-l">Descripción completa</span>
							<div class="bk-gp-editor">
								<div class="bk-gp-tools" role="toolbar" aria-label="Formato">
									<button type="button" data-cmd="bold" aria-label="Negrita"><b>B</b></button>
									<button type="button" data-cmd="insertUnorderedList" aria-label="Lista con viñetas">• Lista</button>
								</div>
								<div id="bk-gp-desc" class="bk-gp-desc" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="bk-gp-desc-l">${cleanHtml(st.description)}</div>
							</div></div>
					</section>
				</div>
				<div class="bk-gp-col is-side">
					<section class="bk-g-card"><h2>Precio</h2>
						${st.mixed ? '<p class="bk-gp-note">Las tallas tienen precios distintos. Si cambias el precio aquí, se aplica a todas las tallas.</p>' : ''}
						<label class="bk-gp-field"><span>Precio normal <b class="bk-gp-req" aria-hidden="true">*</b></span>
							<span class="bk-gp-money"><i>$</i><input name="regular" inputmode="numeric" autocomplete="off" value="${esc(fmt(st.regular))}" required></span></label>
						<label class="bk-gp-field"><span>Precio rebajado</span>
							<span class="bk-gp-money"><i>$</i><input name="sale" inputmode="numeric" autocomplete="off" value="${esc(fmt(st.sale))}" aria-describedby="bk-gp-disc"></span>
							<small id="bk-gp-disc">${discount() || 'Opcional. Con precio rebajado el producto entra a ¡Sale!'}</small></label>
					</section>
					<section class="bk-g-card" data-field="sizes"><h2>Tallas y stock</h2>
						<p class="bk-g-hint">Marca las tallas que vendes y escribe cuántas unidades hay.</p>
						<div id="bk-gp-sizes">${sizesHtml()}</div>
					</section>
					<section class="bk-g-card bk-gp-actions"><h2>Publicación</h2>
						${isEdit ? `<label class="bk-gp-field"><span>Estado</span><select name="status"><option value="publish" ${st.status === 'publish' ? 'selected' : ''}>Publicado (visible en la tienda)</option><option value="draft" ${st.status !== 'publish' ? 'selected' : ''}>Borrador (oculto)</option></select></label>` : ''}
						<p class="bk-g-formerr" role="alert" hidden></p>
						<div class="bk-gp-btns">
							${isEdit ? '<button type="submit" class="bk-g-btn" data-status="">Guardar cambios</button>'
								: '<button type="submit" class="bk-g-btn" data-status="publish">Publicar</button><button type="submit" class="bk-g-btn is-ghost" data-status="draft">Guardar borrador</button>'}
						</div>
						${isEdit ? `<ul class="bk-gp-links">
							${st.url ? `<li><a href="${esc(st.url)}" target="_blank" rel="noopener">${C.icons.open} Ver en la tienda</a></li>` : ''}
							${other ? `<li><a href="#productos/nuevo?desde=${st.id}">${C.icons.copy} Crear este modelo en ${esc(meta.lines[other].label)}</a></li>` : ''}
							${st.edit_url ? `<li><a href="${esc(st.edit_url)}" target="_blank" rel="noopener">${C.icons.ext} Abrir en WooCommerce</a></li>` : ''}
						</ul>` : ''}
					</section>
				</div>
			</form>`;
		main.focus();
	}

	/* --- Lectura y validación --------------------------------------------------------------- */
	const form = () => $('#bk-gp');
	const readForm = () => {
		const f = form();
		if (!st.id) st.line = f.querySelector('[name="line"]:checked')?.value || '';
		st.name = f.name.value.trim();
		st.sku = f.sku.value.trim();
		st.cats = $$('[name="cats"]:checked', f).map((c) => Number(c.value));
		st.is_new = f.is_new.checked;
		st.short = f.short.value.trim();
		st.regular = digits(f.regular.value);
		st.sale = digits(f.sale.value);
		const sizes = {};
		$$('[name="size_on"]', f).forEach((c) => {
			const stock = f[`stock_${c.value}`].value;
			sizes[c.value] = { on: c.checked, stock: stock === '' ? 0 : Math.max(0, parseInt(stock, 10) || 0) };
		});
		return sizes;
	};

	const showErr = (msg, field) => {
		const f = form();
		const box = $('.bk-g-formerr', f);
		box.textContent = msg;
		box.hidden = false;
		$$('[aria-invalid]', f).forEach((el) => el.removeAttribute('aria-invalid'));
		const target = f.querySelector(`[name="${field}"]`) || f.querySelector(`[data-field="${field}"] input, [data-field="${field}"]`);
		if (target) {
			if (target.matches('input,select,textarea')) target.setAttribute('aria-invalid', 'true');
			target.scrollIntoView({ block: 'center', behavior: 'smooth' });
			(target.matches('input,select,textarea') ? target : target.querySelector('input,button') || target).focus({ preventScroll: true });
		}
		toast(msg, 'err');
	};

	const validate = (sizes, status) => {
		if (st.name.length < 3) return ['Escribe el nombre del producto.', 'name'];
		if (!st.sku) return ['Escribe la REF del producto.', 'sku'];
		if (!st.line) return ['Elige la línea (Slim o Curvy).', 'line'];
		if (!Object.values(sizes).some((z) => z.on)) return ['Activa al menos una talla.', 'sizes'];
		if (!Number(st.regular)) return ['Escribe el precio normal.', 'regular'];
		if (st.sale && Number(st.sale) >= Number(st.regular)) return ['El precio rebajado debe ser menor que el precio normal.', 'sale'];
		if (st.uploading) return ['Espera a que terminen de subir las fotos.', 'images'];
		if (status === 'publish' && !st.images.length) return ['Sube al menos una foto para publicar (o guárdalo como borrador).', 'images'];
		return null;
	};

	async function save(btn) {
		const f = form();
		const sizes = readForm();
		const status = btn.dataset.status || f.status?.value || 'draft';
		const bad = validate(sizes, status);
		if (bad) { showErr(...bad); return; }
		$('.bk-g-formerr', f).hidden = true;
		const body = {
			name: st.name, sku: st.sku, line: st.line, cats: st.cats, all_cats: st.all_cats, is_new: st.is_new, status,
			short: st.short, description: cleanHtml($('#bk-gp-desc').innerHTML),
			regular: st.regular, sale: st.sale,
			prices: !st.id || st.regular !== digits(st.origRegular) || st.sale !== digits(st.origSale),
			sizes, images: st.images.map((i) => i.id), video: st.video?.id || 0,
		};
		await busy(btn, async () => {
			try {
				const d = await api(st.id ? `products/${st.id}/form` : 'products', { method: 'POST', body });
				dirty = false;
				A.forget?.('products');
				if (!st.id) {
					toast(status === 'publish' ? 'Producto publicado' : 'Borrador guardado');
					location.hash = `#productos/editar/${d.id}`;
					return;
				}
				st = { ...d, all_cats: [...(d.all_cats || [])], images: [...d.images], uploading: 0, origRegular: d.regular, origSale: d.sale };
				render();
				toast('Cambios guardados');
			} catch (e) {
				if (e.field) { showErr(e.message, e.field); return; }
				throw e;
			}
		});
	}

	/* --- Subidas ------------------------------------------------------------------------- */
	const upload = async (file) => {
		const fd = new FormData();
		fd.append('file', file);
		const res = await fetch(`${C.api}media`, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce }, body: fd });
		const data = await res.json().catch(() => ({}));
		if (!res.ok) throw new Error(data.message || 'No se pudo subir el archivo.');
		return data;
	};

	// Reduce en el navegador las fotos grandes del celular (lado mayor 2400 px, JPEG 88 %): sube más rápido
	// y no choca con el límite de subida del servidor. Si el navegador no puede, se sube el original.
	const MAX_SIDE = 2400;
	const shrink = async (file) => {
		if (!window.createImageBitmap || (file.size < 1.5 * 1048576 && file.type !== 'image/png')) return file;
		try {
			const bmp = await createImageBitmap(file);
			const k = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
			if (k === 1 && file.size < 1.5 * 1048576) return file;
			const cv = Object.assign(document.createElement('canvas'), { width: Math.round(bmp.width * k), height: Math.round(bmp.height * k) });
			const ctx = cv.getContext('2d');
			ctx.fillStyle = '#fff'; // PNG con transparencia → fondo blanco.
			ctx.fillRect(0, 0, cv.width, cv.height);
			ctx.drawImage(bmp, 0, 0, cv.width, cv.height);
			const blob = await new Promise((res) => cv.toBlob(res, 'image/jpeg', 0.88));
			return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' }) : file;
		} catch { return file; }
	};

	const refreshImages = () => { $('#bk-gp-imgs').innerHTML = imagesHtml(); };

	async function addPhotos(files) {
		const list = [...files].filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type));
		if (list.length < files.length) toast('Algunos archivos no son fotos JPG, PNG o WebP.', 'err');
		if (!list.length) return;
		st.uploading += list.length;
		refreshImages();
		for (const original of list) { // Una a la vez: no satura el servidor.
			try {
				const file = await shrink(original);
				if (file.size > meta.maxImage) throw new Error(`pesa más de ${mb(meta.maxImage)}; redúcela antes de subirla.`);
				const d = await upload(file);
				st.images.push({ id: d.id, url: d.url });
				dirty = true;
			} catch (e) { toast(`${original.name}: ${e.message}`, 'err'); }
			st.uploading--;
			if ($('#bk-gp-imgs')) refreshImages();
		}
	}

	async function addVideo(file) {
		if (file.type !== 'video/mp4') { toast('El video debe ser MP4.', 'err'); return; }
		if (file.size > meta.maxVideo) { toast(`El video pesa más de ${mb(meta.maxVideo)}.`, 'err'); return; }
		const box = $('#bk-gp-video');
		box.innerHTML = '<p class="bk-g-loading">Subiendo video…</p>';
		st.uploading++;
		try { const d = await upload(file); st.video = { id: d.id, url: d.url }; dirty = true; } catch (e) { toast(e.message, 'err'); }
		st.uploading--;
		if ($('#bk-gp-video')) $('#bk-gp-video').innerHTML = videoHtml();
	}

	/* --- Eventos ------------------------------------------------------------------------- */
	document.addEventListener('submit', (e) => {
		if (e.target.id !== 'bk-gp') return;
		e.preventDefault();
		save(e.submitter || $('#bk-gp [type="submit"]'));
	});

	document.addEventListener('change', (e) => {
		const f = e.target.closest('#bk-gp');
		if (!f) return;
		dirty = true;
		const t = e.target;
		if (t.name === 'line') {
			const prev = [...st.cats];
			Object.values(meta.lines).forEach((l) => setTree(l.term, false));
			prev.forEach((id) => setTree(id, false));
			readForm();
			setTree(meta.lines[st.line].term, true);
			st.cats = [];
			st.sizes = {};
			$('#bk-gp-cats').innerHTML = catsHtml();
			$('#bk-gp-sizes').innerHTML = sizesHtml();
			$('#bk-gp-sku-h').textContent = meta.lines[st.line].suffix ? `En ${meta.lines[st.line].label} la REF termina en${meta.lines[st.line].suffix} (ej.: 816${meta.lines[st.line].suffix}).` : 'Debe ser única. El mismo modelo en Curvy lleva PLUS al final.';
		}
		if (t.name === 'cats') setTree(Number(t.value), t.checked);
		if (t.name === 'all_cats') {
			setTree(Number(t.value), t.checked);
			const chip = f.querySelector(`[name="cats"][value="${t.value}"]`);
			if (chip) chip.checked = t.checked;
		}
		if (t.name === 'size_on') {
			const stock = f[`stock_${t.value}`];
			stock.disabled = !t.checked;
			if (t.checked) stock.focus();
		}
		if (t.name === 'photos' && t.files.length) { addPhotos(t.files); t.value = ''; }
		if (t.name === 'video' && t.files[0]) addVideo(t.files[0]);
	});

	document.addEventListener('input', (e) => {
		const f = e.target.closest('#bk-gp');
		if (!f) return;
		dirty = true;
		if (e.target.name === 'regular' || e.target.name === 'sale') {
			st.regular = digits(f.regular.value);
			st.sale = digits(f.sale.value);
			$('#bk-gp-disc').textContent = discount() || 'Opcional. Con precio rebajado el producto entra a ¡Sale!';
		}
	});

	document.addEventListener('focusout', (e) => {
		if (e.target.closest('#bk-gp') && (e.target.name === 'regular' || e.target.name === 'sale')) e.target.value = fmt(e.target.value);
	});

	document.addEventListener('click', (e) => {
		const t = e.target;
		// Cambios sin guardar: confirmar antes de salir por un enlace interno.
		const link = t.closest('a[href^="#"]');
		if (link && dirty && form() && !window.confirm('Tienes cambios sin guardar. ¿Salir sin guardar?')) { e.preventDefault(); return; }
		if (link && form()) dirty = false;

		const mv = t.closest('[data-img-move]');
		if (mv) {
			const i = Number(mv.dataset.imgMove); const j = i + Number(mv.dataset.dir);
			[st.images[i], st.images[j]] = [st.images[j], st.images[i]];
			dirty = true;
			refreshImages();
			$(`[data-img-move="${j}"][data-dir="${mv.dataset.dir}"]`)?.focus() || $(`[data-img-move="${j}"]`)?.focus();
			return;
		}
		const del = t.closest('[data-img-del]');
		if (del) {
			st.images.splice(Number(del.dataset.imgDel), 1);
			dirty = true;
			refreshImages();
			($('[data-img-del]') || $('[name="photos"]'))?.focus();
			return;
		}
		if (t.closest('[data-video-del]')) {
			st.video = null;
			dirty = true;
			$('#bk-gp-video').innerHTML = videoHtml();
			$('[name="video"]')?.focus();
			return;
		}
		const cmd = t.closest('#bk-gp [data-cmd]');
		if (cmd) {
			$('#bk-gp-desc').focus();
			document.execCommand(cmd.dataset.cmd, false, null);
			dirty = true;
		}
	});

	// Pegar en la descripción: solo texto (evita estilos y basura de Word/WhatsApp/ChatGPT).
	document.addEventListener('paste', (e) => {
		if (!e.target.closest?.('#bk-gp-desc')) return;
		e.preventDefault();
		document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
	});

	// Arrastrar y soltar fotos.
	['dragover', 'dragleave', 'drop'].forEach((ev) => document.addEventListener(ev, (e) => {
		const zone = e.target.closest?.('[data-drop]');
		if (!zone) return;
		e.preventDefault();
		zone.classList.toggle('is-over', ev === 'dragover');
		if (ev === 'drop' && e.dataTransfer.files.length) addPhotos(e.dataTransfer.files);
	}));

	window.addEventListener('beforeunload', (e) => { if (dirty && form()) { e.preventDefault(); e.returnValue = ''; } });

	window.bkGProduct = { view };
	if (/^#productos\/./.test(location.hash)) A.route();
})();
