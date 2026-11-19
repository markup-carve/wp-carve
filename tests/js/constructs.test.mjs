/**
 * Construct regression tests for the editor stack (carve-js + carve-grammars +
 * the shipped extension set), complementing roundtrip.test.mjs.
 *
 * The block editor churned heavily (semantic marks, bare highlight, spoilers,
 * critic-style ins/del) with almost no JS coverage. Each case here asserts two
 * things the recent fixes depend on:
 *   1. the Carve source still renders to the expected semantic element, and
 *   2. that render survives a Visual-editor round-trip unchanged (the lossy
 *      guard's equivalence check).
 *
 * Cases were verified against the installed carve-js / carve-grammars before
 * being pinned here - only constructs that genuinely round-trip are included.
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

const { Editor } = await import('@tiptap/core');
const carve = await import('@markup-carve/carve/v2');
const carveToHtml = (source) => carve.render(source);
const { CarveKit, carveToProseMirror, serializeToCarve } = await import('@markup-carve/carve-grammars/tiptap');

function normHtml(html) {
	return (html || '')
		.replace(/<([a-z0-9]+)((?:\s[^<>]*?)?)\s*(\/?)>/gi, (m, tag, attrs, sl) => {
			const parts = (attrs.match(/[\w-]+(?:="[^"]*")?/g) || []).sort();
			return '<' + tag.toLowerCase() + (parts.length ? ' ' + parts.join(' ') : '') + sl + '>';
		})
		.replace(/\s+/g, ' ')
		.trim();
}

/** Load into the editor and write the tree back with the writer, as an edit would. */
function roundTrip(source) {
	const el = document.createElement('div');
	document.body.appendChild(el);
	const editor = new Editor({
		element: el,
		extensions: [CarveKit],
		content: carveToProseMirror(source, { engine: carve }),
	});
	const out = serializeToCarve(editor.getJSON(), { engine: carve });
	editor.destroy();
	el.remove();
	return out;
}

// carve -> [expected substring in the rendered HTML]
const CASES = {
	// Semantic inline marks. Carve 0.2 has only the braced `{=x=}` highlight; a bare `=x=` is text.
	highlightBraced: [ 'A {=highlighted=} word.', '<mark>highlighted</mark>' ],
	insert: [ 'An {+inserted+} word.', '<ins>inserted</ins>' ],
	delete: [ 'A {-deleted-} word.', '<del>deleted</del>' ],
	// Spoiler container (SpoilerExtension) -> div.spoiler, kept raw in the seed.
	spoiler: [ '::: spoiler\nHidden body.\n:::', '<div class="spoiler">' ],
	// Thematic break.
	horizontalRule: [ 'Above.\n\n***\n\nBelow.', '<hr>' ],
	literalInline: [ 'Pronounce !`/kaet/`.', '<p>Pronounce /kaet/.</p>' ],
	// The 0.2 render escapes raw HTML by default; the editor still keeps the raw node.
	rawInline: [ 'A `<br>`{=html} break.', '&lt;br&gt;' ],
};

for (const [name, [source, expected]] of Object.entries(CASES)) {
	test(`renders the expected element: ${name}`, () => {
		assert.ok(
			carveToHtml(source).includes(expected),
			`expected ${JSON.stringify(expected)} in render of ${JSON.stringify(source)}\n  got: ${carveToHtml(source)}`,
		);
	});

	test(`round-trips: ${name}`, () => {
		const before = normHtml(carveToHtml(source));
		const after = normHtml(carveToHtml(roundTrip(source)));
		assert.equal(after, before, `render changed on round-trip\n  carve: ${JSON.stringify(source)}\n  serialized: ${JSON.stringify(roundTrip(source))}`);
	});
}

test('inline comments, literals, and raw inline payloads survive the editor', () => {
	const source = 'Keep %{ review this }%, !`literal`, and `<b>`{=html}.\n';
	assert.equal(serializeToCarve(carveToProseMirror(source, { engine: carve }), { engine: carve, source }), source);
	assert.equal(normHtml(carveToHtml(roundTrip(source))), normHtml(carveToHtml(source)));
});
