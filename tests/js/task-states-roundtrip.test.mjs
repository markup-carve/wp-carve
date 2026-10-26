import test from 'node:test';
import assert from 'node:assert/strict';
import { Window } from 'happy-dom';

const window = new Window({ url: 'http://localhost/' });
globalThis.window = window;
globalThis.document = window.document;
for (const name of ['DOMParser', 'Node', 'Element', 'HTMLElement', 'navigator', 'getComputedStyle', 'MutationObserver']) {
	if (globalThis[name] === undefined && window[name] !== undefined) {
		try { globalThis[name] = window[name]; } catch { /* read-only global */ }
	}
}

const { Editor } = await import('@tiptap/core');
const { carveToHtml } = await import('@markup-carve/carve');
const { CarveKit, carveToProseMirror, serializeToCarve } = await import('@markup-carve/carve-grammars/tiptap');

test('visual editor keeps progress, blocked, and open alias task states', () => {
	const source = '- [/] progress\n- [!] blocked\n- [_] open alias';
	const element = document.createElement('div');
	document.body.appendChild(element);
	const editor = new Editor({ element, extensions: [CarveKit], content: carveToProseMirror(source, { unsupported: 'preserve' }) });
	const output = serializeToCarve({ ...editor.getJSON(), attrs: undefined });
	editor.destroy();
	element.remove();
	assert.equal(output, source);
	assert.equal(carveToHtml(output), carveToHtml(source));
});
