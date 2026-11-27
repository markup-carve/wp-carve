import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { Window } from 'happy-dom';

const script = await readFile(new URL('../../assets/js/code-blocks.js', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../assets/css/carve.css', import.meta.url), 'utf8');

function render(attributes, code = 'one\ntwo\nthree\nfour\nfive\n') {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = `<div class="wpcarve"><pre ${attributes}><code>${code}</code></pre></div>`;
	win.eval(script);
	win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
	return win;
}

test('plain code and its gutter highlight the Carve line ranges', () => {
	const win = render('class="line-numbers" data-line-start="42" data-highlight="1,3-5"');
	assert.equal(win.document.querySelectorAll('code .wpcarve-line-highlight').length, 4);
	assert.equal(win.document.querySelectorAll('.wpcarve-line-numbers .wpcarve-line-highlight').length, 4);
	assert.equal(win.document.querySelector('.wpcarve-line-numbers').style.counterReset, 'ln 41');
	win.close();
});

test('empty code rows keep a line box beside the gutter', () => {
	const win = render('class="line-numbers" data-highlight="3"', 'one\n\nthree\n');
	const rows = win.document.querySelectorAll('code > span');
	assert.equal(rows.length, 3);
	assert.equal(rows[1].innerHTML, '');
	const style = win.document.createElement('style');
	style.textContent = styles;
	win.document.head.appendChild(style);
	assert.equal(win.getComputedStyle(rows[1]).minHeight, '1lh');
	win.close();
});

test('highlight works without a gutter and invalid input highlights nothing', () => {
	const win = render('data-highlight="2"');
	assert.equal(win.document.querySelectorAll('code .wpcarve-line-highlight').length, 1);
	assert.equal(win.document.querySelectorAll('.wpcarve-line-numbers').length, 0);
	win.close();
	const invalid = render('class="line-numbers" data-highlight="1,x"');
	assert.equal(invalid.document.querySelectorAll('.wpcarve-line-highlight').length, 0);
	invalid.close();
});

test('captioned fallback code reads options on its figure', () => {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = '<div class="wpcarve"><figure class="line-numbers" data-line-start="42" data-highlight="1,3-5"><pre><code>one\ntwo\nthree\nfour\nfive\n</code></pre><figcaption>Sample</figcaption></figure></div>';
	win.eval(script);
	win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
	assert.equal(win.document.querySelectorAll('code .wpcarve-line-highlight').length, 4);
	assert.equal(win.document.querySelectorAll('.wpcarve-line-numbers .wpcarve-line-highlight').length, 4);
	assert.equal(win.document.querySelector('.wpcarve-line-numbers').style.counterReset, 'ln 41');
	assert.ok(win.document.querySelector('pre').classList.contains('line-numbers'));
	win.close();
});

test('dark dual-theme rows keep the selected line wash visible', () => {
	const win = new Window({ url: 'http://localhost/' });
	win.document.body.innerHTML = `<style>${styles}</style><div class="wpcarve"><pre><code class="phiki-themes torchlight"><div class="line line-highlight"><span class="token">a</span></div></code></pre></div>`;
	win.document.documentElement.dataset.theme = 'dark';
	assert.equal(win.getComputedStyle(win.document.querySelector('.line-highlight')).backgroundColor, 'rgba(255, 209, 102, 0.22)');
	assert.equal(win.getComputedStyle(win.document.querySelector('.line-highlight .token')).backgroundColor, 'transparent');
	win.close();
});
