/**
 * The engine promotes an image that blank lines separate out of its paragraph,
 * so a block image and an inline one are different structures. `img` is
 * inline by default, so without a stylesheet rule the two render identically.
 * These cases pin the computed `display` the real stylesheet produces over the
 * real engine's output, rather than the text of the rule.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { Window } from 'happy-dom';
import { carveToHtml } from '@markup-carve/carve';

const styles = await readFile(new URL('../../assets/css/carve.css', import.meta.url), 'utf8');

const cases = [
	['inline pair, two images on consecutive lines', 'inline', '![](a.png)\n![](b.png)\n'],
	['blank-line pair, each promoted out of its paragraph', 'block', '![](a.png)\n\n![](b.png)\n'],
	['block image in a blockquote', 'block', '> ![](a.png)\n'],
	['block image in an admonition', 'block', '::: note\n![](a.png)\n:::\n'],
	['block image in a generic container', 'block', '::: sidebar\n![](a.png)\n:::\n'],
	['figure image under a caption', 'block', '![](a.png)\n^ a caption\n'],
	['block image after a heading, inside its section', 'block', '# h\n\n![](a.png)\n'],
	['inline image in a heading', 'inline', '# h with ![](a.png)\n'],
	['inline image in a table cell', 'inline', '| a |\n|---|\n| ![](a.png) |\n'],
	['inline image inside a link', 'inline', '[![](a.png)](https://example.com)\n'],
	['inline image in a tight list item', 'inline', '1. text ![](a.png) here\n2. y\n'],
];

function displays(html) {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = `<style>${styles}</style><div class="wpcarve">${html}</div>`;
	const found = [...win.document.querySelectorAll('.wpcarve img')]
		.map((img) => win.getComputedStyle(img).display);
	win.close?.();

	return found;
}

for (const [name, expected, source] of cases) {
	test(`${name} renders as ${expected}`, () => {
		const html = carveToHtml(source);
		const found = displays(html);
		assert.ok(found.length > 0, `no image rendered for: ${source}`);
		for (const display of found) {
			if (expected === 'block') {
				assert.equal(display, 'block', `expected a block image, got "${display}" in: ${html}`);
			} else {
				assert.notEqual(display, 'block', `expected an inline image, got block in: ${html}`);
			}
		}
	});
}
