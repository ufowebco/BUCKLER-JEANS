/**
 * Herramientas → Administrador del menú. Usa jQuery UI Sortable y wp.media, que ya vienen con WordPress.
 */
(function ($) {
	'use strict';
	const T = (window.bkAm && window.bkAm.i18n) || {};
	const $list = $('#bk-am-list');
	let dirty = false;

	/* --- Ámbito: el selector de usuario solo cuando corresponde --------------------- */
	const syncScope = () => {
		const user = $('[data-bk-scope][value="usuario"]').is(':checked');
		$('[data-bk-user]').prop('disabled', !user).closest('label').toggle(user);
	};
	$('[data-bk-scope]').on('change', syncScope);
	syncScope();
	$('.bk-am-scope').on('submit', function () {
		if (!$('[data-bk-scope][value="usuario"]').is(':checked')) $(this).find('[data-bk-user]').val('');
	});

	if (!$list.length) {
		initLogo();
		return;
	}

	/* --- Estado ---------------------------------------------------------------- */
	const state = () => {
		const order = [];
		const hidden = [];
		const hiddenSub = [];
		const custom = [];
		$list.children('li').each(function () {
			const $li = $(this);
			const id = $li.data('id');
			const type = $li.data('type');
			order.push(id);
			if (type === 'head') custom.push({ id, type, label: $li.find('.bk-am-head-input').val() });
			if (type === 'sep') custom.push({ id, type });
			if ((type === 'item' || type === 'wpsep') && $li.find('.bk-am-visible').length && !$li.find('.bk-am-visible').is(':checked')) hidden.push(id);
			$li.find('.bk-am-sub').each(function () { if (!this.checked && !this.disabled) hiddenSub.push($(this).data('id')); });
		});
		return { order, hidden, hidden_sub: hiddenSub, custom };
	};

	/* --- Vista previa ---------------------------------------------------------- */
	const esc = (s) => $('<div>').text(s).html();
	const preview = () => {
		const $p = $('[data-bk-preview]').empty();
		let lastSep = true;
		$list.children('li').each(function () {
			const $li = $(this);
			const type = $li.data('type');
			if ($li.hasClass('is-hidden')) return;
			if (type === 'sep' || type === 'wpsep') {
				if (!lastSep) $p.append('<li class="is-sep"></li>');
				lastSep = true;
				return;
			}
			lastSep = false;
			if (type === 'head') { $p.append(`<li class="is-head">${esc($li.find('.bk-am-head-input').val() || '')}</li>`); return; }
			const icon = $li.find('.bk-am-icon').html() || '';
			const title = $li.find('.bk-am-title').clone().children('code').remove().end().text().trim();
			$p.append(`<li class="is-item${$li.find('.is-nocap').length ? ' is-nocap' : ''}">${icon}<span>${esc(title)}</span></li>`);
		});
	};
	const changed = () => { dirty = true; preview(); };

	/* --- Arrastrar y soltar + teclado ------------------------------------------- */
	// El asa es un <button> (accesible por teclado): jQuery UI cancela por defecto el arrastre desde botones.
	$list.sortable({ handle: '.bk-am-handle', cancel: 'input, textarea, select, option, summary, .bk-am-del', axis: 'y', placeholder: 'bk-am-placeholder', update: changed });
	$list.on('keydown', '.bk-am-handle', function (e) {
		if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
		e.preventDefault();
		const $li = $(this).closest('li');
		if (e.key === 'ArrowUp' && $li.prev().length) $li.insertBefore($li.prev());
		if (e.key === 'ArrowDown' && $li.next().length) $li.insertAfter($li.next());
		$(this).trigger('focus');
		$('[data-bk-live]').text((T.moved || '%d').replace('%d', $li.index() + 1));
		changed();
	});

	/* --- Visibilidad, encabezados, separadores ---------------------------------- */
	$list.on('change', '.bk-am-visible', function () { $(this).closest('li').toggleClass('is-hidden', !this.checked); changed(); });
	$list.on('change input', '.bk-am-sub, .bk-am-head-input', changed);
	$list.on('click', '.bk-am-del', function () {
		if (!window.confirm(T.confirmDel || '¿Eliminar?')) return;
		$(this).closest('li').remove();
		changed();
	});
	const rid = () => Math.random().toString(36).slice(2, 10).replace(/[^a-z0-9]/g, 'x').padEnd(6, 'x');
	$('[data-bk-add]').on('click', function () {
		const type = $(this).data('bk-add');
		const id = `bk-${type}-${rid()}`;
		const del = `<button type="button" class="button-link bk-am-del">${esc(T.remove || 'Eliminar')}</button>`;
		const handle = `<button type="button" class="bk-am-handle" aria-label="${esc(type === 'head' ? T.heading : T.separator)}"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>`;
		const body = type === 'head'
			? `<label class="bk-am-title"><span class="screen-reader-text">${esc(T.heading)}</span><input type="text" class="bk-am-head-input" value="${esc(T.heading || '')}" maxlength="40"></label><span class="bk-am-badge is-custom">${esc(T.heading)}</span>`
			: `<span class="bk-am-title bk-am-sep-line">${esc(T.separator)}</span>`;
		const $li = $(`<li class="bk-am-row is-${type}" data-id="${id}" data-type="${type}">${handle}${body}${del}</li>`);
		$list.prepend($li);
		$li.find('input').trigger('focus').trigger('select');
		changed();
	});

	/* --- Guardar / restablecer ------------------------------------------------- */
	$('[data-bk-save]').on('submit', function () {
		$(this).find('[data-bk-data]').val(JSON.stringify(state()));
		dirty = false;
	});
	$('[data-bk-reset]').on('submit', function (e) {
		if (!window.confirm(T.confirmReset || '¿Restablecer?')) e.preventDefault();
		else dirty = false;
	});
	window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = T.unsaved || ''; } });

	preview();
	initLogo();

	/* --- Logo (Mediateca) ------------------------------------------------------- */
	function initLogo() {
		const $pick = $('[data-bk-logo-pick]');
		if (!$pick.length || !window.wp || !wp.media) return;
		let frame;
		$pick.on('click', () => {
			if (!frame) {
				frame = wp.media({ title: T.chooseLogo, button: { text: T.useLogo }, library: { type: 'image' }, multiple: false });
				frame.on('select', () => {
					const a = frame.state().get('selection').first().toJSON();
					const url = (a.sizes && (a.sizes.medium || a.sizes.full) || a).url;
					$('[data-bk-logo-id]').val(a.id);
					$('[data-bk-logo-prev]').html($('<img alt="">').attr('src', url));
					$('[data-bk-logo-clear]').prop('hidden', false);
				});
			}
			frame.open();
		});
		$('[data-bk-logo-clear]').on('click', function () {
			$('[data-bk-logo-id]').val('0');
			$('[data-bk-logo-prev]').empty();
			$(this).prop('hidden', true);
		});
	}
})(jQuery);
