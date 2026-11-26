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
 *
 * The colon-spelled semantic fixture holds the span fallback both engines
 * write for that spelling.
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
	// Browsers have it; carve-js extensions fall back to plain output without it.
	structuredClone,
	console,
	URL,
	Intl,
});

const sources = {
	imgfence:
		'``` img\n<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>\n```\n',
	listtable: '{header-rows=1}\n::: list-table\n- - A\n  - B\n- - 1\n  - 2\n:::\n',
	semantic: 'A :samp[word] here.\n',
	codegroup: "::: code-group\n```php\nx = 1;\n```\n\n```js\nconst a = 1;\n```\n:::\n",
	details: "::: details \"More\"\nHidden body.\n:::\n",
	spoiler: "::: spoiler \"Reveal\"\nSecret.\n:::\n",
	tabs: "::: tabs\n::: tab \"One\"\nFirst.\n:::\n::: tab \"Two\"\nSecond.\n:::\n:::\n",
	semanticbraced: "Press [Ctrl]{kbd} for [out]{samp}.\n",
};

const names = {
	imgfence: 'an img fence renders a sanitized image, not a code block',
	listtable: 'a list-table renders the same table as the front end',
	semantic: 'a colon-spelled semantic span renders the same fallback as the front end',
	codegroup: 'a code group renders the same tab panels as the front end',
	details: 'a details block renders the same disclosure as the front end',
	spoiler: 'a spoiler renders the same disclosure as the front end',
	tabs: 'tabs render the same radio widget as the front end',
	semanticbraced: 'braced semantic spans render the same elements as the front end',
};

const pending = {};

for (const [key, source] of Object.entries(sources)) {
	test(names[key], { skip: pending[key] ?? false }, () => {
		assert.equal(
			scope.wpCarveEngine.carveToHtml(source).trimEnd(),
			frontEnd[key].trimEnd(),
		);
	});
}
