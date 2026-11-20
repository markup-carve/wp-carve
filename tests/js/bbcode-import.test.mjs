/**
 * BBCode import is carve-js only in Carve 0.2, so the block editor converts
 * it in the browser with assets/js/vendor/carve-import.js. The server answers
 * 422 for it (PasteController). These run the BUILT bundle, like the preview
 * parity tests, so a stale bundle fails here.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import { Window } from 'happy-dom';
import { importBbcode, looksLikeBbcode } from '../../assets/js/src/import.js';

const bundle = await readFile(new URL('../../assets/js/vendor/carve-import.js', import.meta.url), 'utf8');
const scope = {};
// The browser builds of the Markdown libraries in the bundle read `document`.
const { document } = new Window();
runInNewContext(bundle, { window: scope, globalThis: scope, self: scope, document, TextEncoder, TextDecoder, console, URL, Intl, structuredClone });

test('the bundle exposes the BBCode importer', () => {
	assert.equal(typeof scope.wpCarveImport?.importBbcode, 'function');
	assert.equal(typeof scope.wpCarveImport?.looksLikeBbcode, 'function');
});

test('BBCode converts to Carve in the ingest response shape', () => {
	const res = scope.wpCarveImport.importBbcode('[b]bold[/b] and [url=https://example.test]a link[/url]\n[list][*]one[*]two[/list]');
	assert.equal(res.from, 'bbcode');
	assert.match(res.carve, /\*bold\*/);
	assert.match(res.carve, /\[a link\]\(https:\/\/example\.test\)/);
	assert.match(res.carve, /^- one$/m);
	assert.equal(res.report.source_format, 'bbcode');
	assert.ok(Array.isArray(res.report.diagnostics));
});

test('the source module and the bundle agree', () => {
	const source = '[quote]Quoted [i]text[/i][/quote]';
	// Objects from the VM realm have other prototypes, so compare as JSON.
	assert.equal(JSON.stringify(scope.wpCarveImport.importBbcode(source)), JSON.stringify(importBbcode(source)));
});

test('the BBCode sniff matches the server sniff', () => {
	assert.equal(looksLikeBbcode('[b]bold[/b]'), true);
	assert.equal(looksLikeBbcode('[quote]<p>x</p>[/quote]'), true);
	assert.equal(looksLikeBbcode('Some **bold** text'), false);
	assert.equal(looksLikeBbcode('<p>A paragraph</p>'), false);
});
