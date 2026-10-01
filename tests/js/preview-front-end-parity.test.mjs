/**
 * The in-browser engine renders the live block preview, and the block takes
 * that path for the 'post' context only - the same context the front end
 * publishes. So every content extension the PHP post path enables
 * unconditionally has to be registered in the preview bundle too, or the
 * preview shows raw markup for a construct the front end renders.
 *
 * These run the BUILT bundle, not the source module, because the registration
 * only ships once `npm run build:engine` has run. A test against the source
 * would pass on a tree whose bundle is stale.
 *
 * The expectations in fixtures/front-end-renders.json were produced by the PHP
 * post path (WpCarve\Converter::toHtml with the 'post' context). PHP appends a
 * trailing newline the JS renderer does not, so the comparison is on trimmed
 * output; everything else matches byte for byte.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const bundle = await readFile(
	new URL('../../assets/js/vendor/carve.js', import.meta.url),
	'utf8',
);
const frontEnd = JSON.parse(
	await readFile(new URL('./fixtures/front-end-renders.json', import.meta.url), 'utf8'),
);

const scope = {};
runInNewContext(bundle, {
	window: scope,
	globalThis: scope,
	self: scope,
	TextEncoder,
	TextDecoder,
	console,
	URL,
	Intl,
});

const sources = {
	imgfence:
		'``` img\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>\n```\n',
	listtable: '{header-rows=1}\n::: list-table\n- - A\n  - B\n- - 1\n  - 2\n:::\n',
	semantic: 'A :samp[word] here.\n',
};

const names = {
	imgfence: 'an img fence renders a sanitized image, not a code block',
	listtable: 'a list-table renders a table, not a nested bullet list',
	semantic: 'a colon-spelled semantic span renders its element, not a class',
};

for (const [key, source] of Object.entries(sources)) {
	test(names[key], () => {
		assert.equal(
			scope.wpCarveEngine.carveToHtml(source).trimEnd(),
			frontEnd[key].trimEnd(),
		);
	});
}
