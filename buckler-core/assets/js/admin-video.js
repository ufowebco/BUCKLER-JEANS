/**
 * Caja "Video del producto": elegir un video de la Mediateca o quitarlo.
 */
jQuery(($) => {
	'use strict';

	let frame;
	const $id = $('#bk_video_id');
	const $url = $('#bk_video_url');

	$('#bk_video_pick').on('click', (e) => {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({
				title: 'Elegir video del producto',
				button: { text: 'Usar este video' },
				library: { type: 'video' },
				multiple: false,
			});
			frame.on('select', () => {
				const file = frame.state().get('selection').first().toJSON();
				$id.val(file.id);
				$url.val(file.url);
			});
		}
		frame.open();
	});

	$('#bk_video_clear').on('click', (e) => {
		e.preventDefault();
		$id.val('');
		$url.val('');
	});

	// Si escriben una URL a mano, se descarta el ID anterior.
	$url.on('input', () => $id.val(''));
});
