/**
 * The visual editor inserts NODES, so the proof that it produces the same
 * Carve source as the two source editors has to run the real carve-grammars
 * serializer over the node tree the block builds.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { Window } from 'happy-dom';

const win = new Window({ url: 'http://localhost/' });
globalThis.window = win;
globalThis.document = win.document;
for (const k of ['DOMParser', 'Node', 'Element', 'HTMLElement', 'navigator', 'getComputedStyle', 'MutationObserver']) {
	if (globalThis[k] === undefined && win[k] !== undefined) {
		try {
			globalThis[k] = win[k];
		} catch {
			/* read-only global (navigator) - ignore */
		}
	}
}

const { Editor } = await import('@tiptap/core');
const { CarveKit, serializeToCarve } = await import('@markup-carve/carve-grammars/tiptap');

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

/** The node tree assets/blocks/carve/index.js builds from a pick. */
function visualNodes(chosen) {
	const attrs = { src: chosen.url, alt: chosen.alt };
	if (chosen.className) {
		attrs.class = chosen.className;
	}
	if (!chosen.caption) {
		return { type: 'paragraph', content: [{ type: 'image', attrs }] };
	}

	return {
		type: 'carveFigure',
		content: [
			{ type: 'paragraph', content: [{ type: 'image', attrs }] },
			{ type: 'carveCaption', content: [{ type: 'text', text: chosen.caption }] },
		],
	};
}

function serialize(node) {
	const editor = new Editor({
		extensions: [CarveKit.configure({})],
		content: { type: 'doc', content: [node] },
	});
	const out = serializeToCarve(editor.getJSON()).trim();
	editor.destroy();

	return out;
}

test('a sized visual pick serializes to the same source the source editors write', () => {
	const chosen = media.pick(attachment, 'large');

	assert.equal(serialize(visualNodes(chosen)), media.toSource(chosen));
	assert.equal(
		serialize(visualNodes(chosen)),
		'![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1-1024x585.png){.wp-image-3000}'
	);
});

test('a captioned visual pick serializes the caption as a caption line', () => {
	const chosen = media.pick({ ...attachment, caption: 'Carve releases through 2025' }, 'full');
	const written = serialize(visualNodes(chosen));

	assert.equal(
		written,
		'![A release timeline](https://site.test/wp-content/uploads/2025/10/timeline-1.png){.wp-image-3000}\n'
		+ '^ Carve releases through 2025'
	);
	assert.equal(written, media.toSource(chosen));
});
