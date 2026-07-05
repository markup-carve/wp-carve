/**
 * Visual-editor round-trip smoke test.
 *
 * Exercises the exact path the Carve block uses: Carve -> HTML (carve-js) ->
 * Tiptap (carve-grammars CarveKit) -> Carve (serializeToCarve). A construct
 * "round-trips" when the rendered output is unchanged, which is what the block's
 * lossy guard checks. The deep coverage lives in carve-grammars; this guards the
 * wired-up stack (carve-js + carve-grammars + the shipped extension set).
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

// Same equivalence used by the block's lossy guard: sort tag attributes and
// collapse whitespace so visually-identical HTML compares equal.
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

const CASES = {
	heading: '# Title\n\nA paragraph.',
	marks: 'Text with *strong*, /emphasis/, _underline_, `code`, ~strike~, ^sup^.',
	links: 'A [link](https://example.com) and ![alt](https://example.com/i.png) inline.',
	bulletList: '- one\n- two\n  - nested',
	orderedList: '1. one\n2. two',
	taskList: '- [ ] todo\n- [x] done',
	blockquote: '> quoted line',
	codeBlock: '```php\n$x = 1;\n```',
	// The editor seed keeps diagram fences raw (the diagram renderer is skipped
	// for the editor context), so the fence language must survive the round trip.
	mermaidFence: '```mermaid\ngraph TD; A-->B;\n```',
	admonition: '::: note\nBody.\n:::',
	// Quoted container titles must survive (carve-grammars carveDiv title attr).
	admonitionTitled: '::: note "Custom"\nBody.\n:::',
	detailsTitled: '::: details "More"\nBody text.\n:::',
	// A double quote in the title survives via the {title="..."} attribute line.
	detailsQuoteTitle: '{title="Say \\"hi\\""}\n::: details\nBody text.\n:::',
	math: 'Inline $`E=mc^2` here.',
	footnote: 'See it.[^1]\n\n[^1]: The note.',
	definitionList: ':: Term\n:  Definition.',
	table: '|= A |= B |\n| 1 | 2 |',
	mentionCitation: 'A citation [@smith2020] and a #tag.',
	span: 'A [styled span]{.mark #s1}.',
};

for (const [name, carve] of Object.entries(CASES)) {
	test(`round-trips: ${name}`, () => {
		const before = normHtml(carveToHtml(carve));
		const after = normHtml(carveToHtml(roundTrip(carve)));
		assert.equal(after, before, `render changed on round-trip\n  carve: ${JSON.stringify(carve)}\n  serialized: ${JSON.stringify(roundTrip(carve))}`);
	});
}
