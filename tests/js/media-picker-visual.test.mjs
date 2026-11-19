/**
 * The visual editor inserts a pick as the source the source editors write, read
 * by the carve-grammars editor kit, so the proof runs the real editor: insert,
 * then write the document back.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { Window } from 'happy-dom';

const win = new Window({ url: 'http://localhost/' });
globalThis.window = win;
globalThis.document = win.document;
for (const k of ['DOMParser', 'Node', 'Element', 'HTMLElement', 'navigator', 'getComputedStyle', 'MutationObserver', 'requestAnimationFrame', 'cancelAnimationFrame']) {
	if (globalThis[k] === undefined && win[k] !== undefined) {
		try {
			globalThis[k] = win[k];
		} catch {
			/* read-only global (navigator) - ignore */
		}
	}
}

const { initVisualEditor } = await import('../../assets/js/tiptap/visual-editor.js');

const pickerSource = readFileSync(new URL('../../assets/js/media-picker.js', import.meta.url), 'utf8');

function loadPicker() {
	const scope = {};
	scope.window = scope;
	runInNewContext(pickerSource, { window: scope });

	return scope.wpCarveMedia;
}

const media = loadPicker();

const attachment = {
	id: 3000,
	url: 'https://site.test/wp-content/uploads/2025/10/timeline-1.png',
	alt: 'A release timeline',
	sizes: {
		full: { url: 'https://site.test/wp-content/uploads/2025/10/timeline-1.png' },
		large: { url: 'https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png' },
	},
};

/** What the block does with a pick in Visual mode (insertVisualImage), then the document it writes. */
async function serialize(chosen) {
	const el = document.createElement('div');
	document.body.appendChild(el);
	const editor = await initVisualEditor(el, '');
	editor.insertCarve(media.toSource(chosen));
	const out = editor.getCarve().trim();
	editor.destroy();
	el.remove();

	return out;
}

test('a sized visual pick serializes to the same source the source editors write', async () => {
	const chosen = media.pick(attachment, 'large');

	assert.equal(await serialize(chosen), media.toSource(chosen));
	assert.equal(
		await serialize(chosen),
		'![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png){.wp-image-3000}'
	);
});

test('a captioned visual pick serializes the caption as a caption line', async () => {
	const chosen = media.pick({ ...attachment, caption: 'Carve releases through 2025' }, 'full');
	const written = await serialize(chosen);

	assert.equal(
		written,
		'![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1.png){.wp-image-3000}\n'
		+ '^ Carve releases through 2025'
	);
	assert.equal(written, media.toSource(chosen));
});
