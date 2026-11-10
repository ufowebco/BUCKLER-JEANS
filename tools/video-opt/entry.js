import { Input, Output, Conversion, BlobSource, BufferTarget, Mp4OutputFormat, MP4, QTFF, Quality, canEncodeVideo } from 'mediabunny';

/**
 * Buckler Core: reduce un video de producto en el navegador antes de subirlo (WebCodecs).
 * Devuelve { file, w, h, secs } o lanza un Error con .code ('unsupported' | 'invalid' | 'cancel').
 */
async function optimize(file, o = {}, onProgress) {
	const fail = (code, msg) => Object.assign(new Error(msg || code), { code });
	if (typeof VideoEncoder === 'undefined' || typeof VideoDecoder === 'undefined') throw fail('unsupported');
	const input = new Input({ source: new BlobSource(file), formats: [MP4, QTFF] });
	const track = await input.getPrimaryVideoTrack();
	if (!track) throw fail('invalid');
	const sw = track.displayWidth, sh = track.displayHeight;
	const max = o.side || 1080;
	const k = Math.min(1, max / Math.min(sw, sh));
	const even = (n) => Math.max(2, Math.round(n / 2) * 2);
	const w = even(sw * k), h = even(sh * k);
	const bitrate = Math.round((o.bitrate || 3000000) * Math.min(1, (w * h) / (1080 * 1920) + 0.25));
	let codec = null;
	for (const c of o.codecs || ['avc']) {
		if (await canEncodeVideo(c, { width: w, height: h, bitrate })) { codec = c; break; }
	}
	if (!codec) throw fail('unsupported');
	const target = new BufferTarget();
	const output = new Output({ format: new Mp4OutputFormat({ fastStart: 'in-memory' }), target });
	const conversion = await Conversion.init({
		input, output,
		video: { width: w, height: h, fit: 'contain', frameRate: o.fps || 30, codec, quality: new Quality({ bitrate }), forceTranscode: true, allowTransformationMetadata: false },
		audio: { discard: true },
		showWarnings: false,
	});
	if (!conversion.isValid) throw fail('unsupported');
	if (onProgress) conversion.onProgress = (p) => onProgress(p);
	if (o.signal) o.signal.addEventListener('abort', () => conversion.cancel().catch(() => {}), { once: true });
	await conversion.execute();
	if (o.signal && o.signal.aborted) throw fail('cancel');
	if (!target.buffer) throw fail('invalid');
	const name = file.name.replace(/\.[^.]+$/, '') + '.mp4';
	return { file: new File([target.buffer], name, { type: 'video/mp4' }), w, h, sw, sh, codec };
}
window.bkVideoOptimize = optimize;
