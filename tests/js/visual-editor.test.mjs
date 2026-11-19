/**
 * The visual editor on Carve 0.2: loaded from the carve-js 0.2 tree through the
 * carve-grammars editor kit and written back by the carve-js writer.
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

const { carveContent, embedSource, initVisualEditor, serializeToCarve } = await import('../../assets/js/tiptap/visual-editor.js');

test('an editor document serializes to Carve 0.2 source', () => {
	const doc = { type: 'doc', content: [
		{ type: 'heading', attrs: { level: 2 }, content: [ { type: 'text', text: 'T' } ] },
		{ type: 'paragraph', content: [ { type: 'text', text: 'Some ' }, { type: 'text', text: 'em', marks: [ { type: 'italic' } ] }, { type: 'text', text: ' and ' }, { type: 'text', text: 'strong', marks: [ { type: 'bold' } ] }, { type: 'text', text: '.' } ] },
	] };
	assert.equal(serializeToCarve(doc), '== T\n\nSome /em/ and *strong*.\n');
});

test('an unedited document keeps its source, structure the HTML pivot lost included', () => {
	const source = '{.box}\n::: note "Read this"\n- [?] maybe\n- [ ] open\n:::\n';
	assert.equal(serializeToCarve(carveContent(source), source), source);
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

test('the block toolbar commands write admonitions, divs and embeds', async () => {
	const el = document.createElement('div');
	document.body.appendChild(el);
	const editor = await initVisualEditor(el, 'Text.\n');
	editor.editor.commands.selectAll();
	assert.equal(editor.editor.chain().toggleCarveDiv({ class: 'note' }).run(), true);
	assert.equal(editor.getCarve(), '::: note\nText.\n:::\n');
	editor.setCarve('Text.\n');
	editor.editor.commands.selectAll();
	assert.equal(editor.editor.chain().toggleCarveDiv({ class: 'spoiler' }).run(), true);
	assert.equal(editor.getCarve(), '::: spoiler\nText.\n:::\n');
	editor.setCarve('See \n');
	editor.editor.commands.setTextSelection(5);
	assert.equal(editor.editor.chain().setCarveEmbed({ src: 'https://www.youtube.com/watch?v=abc123' }).run(), true);
	assert.match(editor.getCarve(), /:youtube\[abc123\]/);
	editor.destroy();
	el.remove();
});

test('embed sources name YouTube and Vimeo by id', () => {
	assert.equal(embedSource('https://youtu.be/xyz'), ':youtube[xyz]');
	assert.equal(embedSource('https://vimeo.com/123'), ':vimeo[123]');
	assert.equal(embedSource('https://example.test/v.mp4'), ':media[https://example.test/v.mp4]');
});
