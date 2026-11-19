/**
 * The visual editor on Carve 0.2: seeded from the carve-js v2 render and
 * serialized with the v2 HTML importer (no 0.2 Tiptap kit yet, carve-grammars#906).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
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

const { initVisualEditor, serializeToCarve } = await import('../../assets/js/tiptap/visual-editor.js');

test('editor HTML serializes to Carve 0.2 source', () => {
	assert.equal(serializeToCarve('<h2>T</h2><p>Some <em>em</em> and <strong>strong</strong>.</p>'), '== T\n\nSome /em/ and *strong*.\n');
});

test('an edit is written back as 0.2 source', async () => {
	const el = document.createElement('div');
	document.body.appendChild(el);
	const changes = [];
	const editor = await initVisualEditor(el, 'Some /em/ text.\n', (carve) => changes.push(carve));
	editor.editor.commands.setContent('<p>Some <em>em</em> text and <strong>more</strong>.</p>');
	assert.equal(editor.getCarve(), 'Some /em/ text and *more*.\n');
	editor.destroy();
	el.remove();
});
