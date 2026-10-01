/**
 * A `{.diff}` row's add/remove wash is the row's own background, so anything
 * inside the row that paints an opaque background covers it. The dark-mode
 * rules repaint every descendant span with the editor background, and the
 * syntax token spans are the ones that cover the code.
 *
 * These read the computed background the real stylesheet produces over real
 * emitted markup, not the text of the rule: a rule-presence check cannot see
 * an occlusion. happy-dom does not evaluate
 * `@media (prefers-color-scheme: dark)`, so it reaches only the
 * `data-theme="dark"` spelling. Chromium confirmed both spellings, with a
 * light-mode control, and block-editor-contract pins the two selectors
 * against each other so a fix to one cannot drift from the other.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { Window } from 'happy-dom';

const styles = await readFile(new URL('../../assets/css/carve.css', import.meta.url), 'utf8');

// Emitted by src/Extension/TorchlightExtension.php for a `{.diff}` php fence
// with line numbers and both a light and a dark theme configured. The
// dual-theme `phiki-themes` class is what the dark rules key on, so a
// single-theme fixture would never reach them.
const fence = await readFile(
	new URL('./fixtures/diff-fence-dual-theme.html', import.meta.url),
	'utf8',
);

function styled(callback) {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = `<style>${styles}</style><div class="wpcarve">${fence}</div>`;
	win.document.documentElement.setAttribute('data-theme', 'dark');
	const computed = (selector) => win.getComputedStyle(win.document.querySelector(selector));
	try {
		return callback(computed);
	} finally {
		win.close?.();
	}
}

for (const row of ['remove', 'add']) {
	test(`a token span in a diff ${row} row does not repaint the row wash`, () => {
		styled((computed) => {
			assert.equal(computed(`.line.diff.${row} span.token`).backgroundColor, '');
		});
	});
}

test('a token span outside a diff row still takes the dark editor background', () => {
	styled((computed) => {
		assert.equal(computed('.line:not(.diff) span.token').backgroundColor, '#24292e');
	});
});

test('a diff row token keeps its dark syntax color', () => {
	styled((computed) => {
		assert.equal(computed('.line.diff.remove span.token').color, '#e1e4e8');
	});
});
