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

// A block image needs the paragraph's trailing margin as well as its
// `display`: two stacked images carry no spacing of their own and sit flush
// otherwise. happy-dom does not expand `margin-block` into the physical
// longhands, so the cascaded value is what these read - which still proves the
// selector matched, and Chromium supplies the resolved pixels.
const spacing = [
	['stacked pair', 'block', '![](a.png)\n\n![](b.png)\n'],
	['block image before a paragraph', 'block', '![](a.png)\n\ntext\n'],
	['block image in a blockquote', 'block', '> ![](a.png)\n'],
	['figure image under a caption', 'figure', '![](a.png)\n^ a caption\n'],
	['inline pair in one paragraph', 'inline', '![](a.png)\n![](b.png)\n'],
	['inline image in a link', 'inline', '[![](a.png)](https://example.com)\n'],
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

function margins(html) {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = `<style>${styles}</style><div class="wpcarve">${html}</div>`;
	const found = [...win.document.querySelectorAll('.wpcarve img')].map((img) => {
		return win.getComputedStyle(img).getPropertyValue('margin-block');
	});
	win.close?.();

	return found;
}

for (const [name, expected, source] of spacing) {
	const label = { block: 'the block margin', figure: 'no trailing margin', inline: 'no block margin' }[expected];

	test(`${name} carries ${label}`, () => {
		const html = carveToHtml(source);
		const found = margins(html);
		assert.ok(found.length > 0, `no image rendered for: ${source}`);
		for (const margin of found) {
			if (expected === 'block') {
				assert.equal(margin, '0 1em', `expected a trailing block margin, got "${margin}" in: ${html}`);
			} else if (expected === 'figure') {
				// The figure carries the rhythm, so the trailing margin would only
				// push the caption away from the image it captions.
				assert.equal(margin, '0', `a figure image must not push its caption away: ${html}`);
			} else {
				assert.equal(margin, '', `an inline image must not gain a block margin: ${html}`);
			}
		}
	});
}

// `video` is paired with `img` in the rule, and only raw HTML can produce one.
test('a block video carries the block margin and renders as a block', () => {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = `<style>${styles}</style><div class="wpcarve"><video src="a.mp4"></video></div>`;
	const style = win.getComputedStyle(win.document.querySelector('video'));
	assert.equal(style.display, 'block');
	assert.equal(style.getPropertyValue('margin-block'), '0 1em');
	win.close?.();
});
