/**
 * The shipped bundle has to be a build of the engine this repository PINS.
 *
 * `assets/js/vendor/carve.js` carries no version string - esbuild's minifier
 * drops `LIB_VERSION` because the entry module never reads it - and it has no
 * provenance header either, so nothing in the repository could say which
 * carve-js a committed bundle was built from. Swapping in the bundle from the
 * previous engine release passed `npm run test:js`, `phpunit`, `phpstan` and
 * the corpus sweep, all four green.
 *
 * What that let through: on carve-js 0.1.9 a cross-reference whose target
 * differs only in case still resolved, and on carve-php 0.1.11 it renders as
 * literal text. So an author writing `</#getting-started>` against a heading id
 * of `Getting-Started` saw a working link in the block preview and published
 * escaped source text. preview-front-end-parity.test.mjs could not see it: it
 * covers three constructs against a frozen fixture.
 *
 * This compares the bundle against the installed package rather than against a
 * recorded version, because a comparison needs no bookkeeping to keep current -
 * bump the pin, and the expectation moves with it.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import { carveToHtml, tabs, details, spoiler, codeGroup, imgFence, listTable, semanticSpan, citations } from '@markup-carve/carve';

const bundle = await readFile(new URL('../../assets/js/vendor/carve.js', import.meta.url), 'utf8');
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

// The same list assets/js/src/engine.js registers, so a difference is the
// engine's and not the extension set's.
const extensions = () => [
	codeGroup(),
	tabs(),
	details(),
	spoiler(),
	imgFence(),
	listTable(),
	semanticSpan(),
	citations({ mode: 'numbered', bibliography: [] }),
];

/**
 * Shapes around the boundaries the engine has moved recently, not a
 * construct tour: a case-only cross-reference, a colon-fence metadata slot, an
 * over-indented block under a list item, a fence in a description body. Each is
 * a place where two engine releases disagreed.
 */
const shapes = {
	'cross-reference case': '{#Getting-Started}\n# Getting Started\n\nSee </#getting-started> and </#Getting-Started>.\n',
	'heading id case': '{#Tip}\n# Upper\n\n{#tip}\n# Lower\n\n</#Tip> and </#tip>\n',
	'collapsed reference case': 'See [Plan][] and [plan][].\n\n# Plan\n',
	'colon fence metadata slot': '::: note"Title\nBody.\n:::\n',
	'over-indented block in a list item': '- item\n\n      # not a heading\n\nflush\n',
	'fence in a description body': ':: t\n: ```\ncode\n```\n',
	'table body metadata': '{header-rows=1 body-rows=1,1}\n| H | G |\n| a | b |\n| c | d |\n',
};

for (const [name, source] of Object.entries(shapes)) {
	test(`the shipped bundle renders ${name} like the pinned engine`, () => {
		assert.equal(
			scope.wpCarveEngine.carveToHtml(source),
			carveToHtml(source, { extensions: extensions() }),
		);
	});
}

test('the comparison reaches the bundle at all', () => {
	// Without this a bundle that exposed nothing would make every case above
	// compare undefined to undefined and pass.
	assert.equal(typeof scope.wpCarveEngine?.carveToHtml, 'function');
	assert.match(scope.wpCarveEngine.carveToHtml('*bold*'), /<strong>bold<\/strong>/);
});
