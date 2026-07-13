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
const { carveToHtml } = await import('@markup-carve/carve');
const { CarveKit, serializeToCarve } = await import('carve-grammars/tiptap');

function normHtml(html) {
	return (html || '')
		.replace(/<([a-z0-9]+)((?:\s[^<>]*?)?)\s*(\/?)>/gi, (m, tag, attrs, sl) => {
			const parts = (attrs.match(/[\w-]+(?:="[^"]*")?/g) || []).sort();
			return '<' + tag.toLowerCase() + (parts.length ? ' ' + parts.join(' ') : '') + sl + '>';
		})
		.replace(/\s+/g, ' ')
		.trim();
}

function roundTrip(carve) {
	const el = document.createElement('div');
	document.body.appendChild(el);
	const editor = new Editor({ element: el, extensions: [CarveKit], content: carveToHtml(carve) });
	const out = serializeToCarve(editor.getJSON());
	editor.destroy();
	el.remove();
	return out;
}

// carve -> [expected substring in the rendered HTML]
const CASES = {
	// Semantic inline marks (SemanticSpanExtension). Bare `=x=` highlight is the
	// #41 "bare highlight" form; the braced `{=x=}` is the canonical one.
	highlightBraced: [ 'A {=highlighted=} word.', '<mark>highlighted</mark>' ],
	highlightBare: [ 'A =highlighted= word.', '<mark>highlighted</mark>' ],
	insert: [ 'An {+inserted+} word.', '<ins>inserted</ins>' ],
	delete: [ 'A {-deleted-} word.', '<del>deleted</del>' ],
	// Spoiler container (SpoilerExtension) -> div.spoiler, kept raw in the seed.
	spoiler: [ '::: spoiler\nHidden body.\n:::', '<div class="spoiler">' ],
	// Thematic break.
	horizontalRule: [ 'Above.\n\n---\n\nBelow.', '<hr>' ],
};

for (const [name, [carve, expected]] of Object.entries(CASES)) {
	test(`renders the expected element: ${name}`, () => {
		assert.ok(
			carveToHtml(carve).includes(expected),
			`expected ${JSON.stringify(expected)} in render of ${JSON.stringify(carve)}\n  got: ${carveToHtml(carve)}`,
		);
	});

	test(`round-trips: ${name}`, () => {
		const before = normHtml(carveToHtml(carve));
		const after = normHtml(carveToHtml(roundTrip(carve)));
		assert.equal(after, before, `render changed on round-trip\n  carve: ${JSON.stringify(carve)}\n  serialized: ${JSON.stringify(roundTrip(carve))}`);
	});
}
