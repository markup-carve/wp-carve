import assert from 'node:assert/strict';
import test from 'node:test';
import { highlightHtml, highlightRanges } from '../../assets/js/src/highlight-core.js';

test( 'source highlighting recognizes Carve syntax and escapes HTML', () => {
	const source = '# Title\n\nA *strong* and /emphasis/ line.\n\n- [/] In progress\n\n```php\n$x = 1;\n```\n<script>';
	const html = highlightHtml( source );
	assert.match( html, /token title/ );
	assert.match( html, /token bold/ );
	assert.match( html, /token italic/ );
	assert.match( html, /token code-block/ );
	assert.match( html, /&lt;script>/ );
	assert.doesNotMatch( html, /<script>/ );
} );

test( 'CodeMirror ranges retain the source offsets through nested tokens', () => {
	const source = '# Title\n- [/] In progress\n';
	const ranges = highlightRanges( source );
	assert.ok( ranges.some( ( range ) => source.slice( range.from, range.to ).includes( 'Title' ) && range.scopes.includes( 'title' ) ) );
	assert.ok( ranges.some( ( range ) => source.slice( range.from, range.to ) === '[/]' && range.scopes.includes( 'list' ) ) );
	for ( const range of ranges ) {
		assert.ok( range.from < range.to );
		assert.ok( range.to <= source.length );
	}
} );
