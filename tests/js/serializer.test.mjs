/**
 * Dependency-free unit tests for the Tiptap -> Carve serializer.
 * Run with: node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { serializeToCarve } from '../../assets/js/tiptap/serializer.js';

const doc = ( content ) => ( { type: 'doc', content } );
const para = ( ...content ) => ( { type: 'paragraph', content } );
const txt = ( text, marks ) => ( { type: 'text', text, marks } );
const out = ( d ) => serializeToCarve( d ).trim();

test( 'inline marks use Carve delimiters', () => {
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'bold' } ] ) ) ] ) ), '*x*' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'italic' } ] ) ) ] ) ), '/x/' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'underline' } ] ) ) ] ) ), '_x_' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'strike' } ] ) ) ] ) ), '~x~' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'highlight' } ] ) ) ] ) ), '==x==' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'code' } ] ) ) ] ) ), '`x`' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'superscript' } ] ) ) ] ) ), '^x^' );
	assert.equal( out( doc( [ para( txt( 'x', [ { type: 'subscript' } ] ) ) ] ) ), ',,x,,' );
} );

test( 'link', () => {
	assert.equal(
		out( doc( [ para( txt( 'go', [ { type: 'link', attrs: { href: 'https://x.test' } } ] ) ) ] ) ),
		'[go](https://x.test)'
	);
} );

test( 'headings', () => {
	assert.equal( out( doc( [ { type: 'heading', attrs: { level: 2 }, content: [ txt( 'Hi' ) ] } ] ) ), '## Hi' );
} );

test( 'inline math + footnote ref + media embed', () => {
	assert.equal( out( doc( [ para( { type: 'carveMath', attrs: { tex: 'x^2', display: false } } ) ] ) ), '$`x^2`' );
	assert.equal( out( doc( [ para( { type: 'carveMath', attrs: { tex: 'x', display: true } } ) ] ) ), '$$`x`' );
	assert.equal( out( doc( [ para( txt( 'a' ), { type: 'footnoteRef', attrs: { label: '1' } } ) ] ) ), 'a[^1]' );
	assert.equal( out( doc( [ para( { type: 'mediaEmbed', attrs: { provider: 'youtube', mediaId: 'abc' } } ) ] ) ), ':youtube[abc]' );
} );

test( 'definition list', () => {
	const dl = {
		type: 'definitionList',
		content: [
			{ type: 'definitionTerm', content: [ txt( 'Term' ) ] },
			{ type: 'definitionDescription', content: [ para( txt( 'Def' ) ) ] },
		],
	};
	assert.equal( out( doc( [ dl ] ) ), ':: Term\n:  Def' );
} );

test( 'table becomes a GFM pipe table', () => {
	const cell = ( t ) => ( { type: 'tableCell', content: [ para( txt( t ) ) ] } );
	const table = {
		type: 'table',
		content: [
			{ type: 'tableRow', content: [ cell( 'A' ), cell( 'B' ) ] },
			{ type: 'tableRow', content: [ cell( '1' ), cell( '2' ) ] },
		],
	};
	assert.equal( out( doc( [ table ] ) ), '| A | B |\n| --- | --- |\n| 1 | 2 |' );
} );

test( 'admonition div', () => {
	const div = { type: 'carveDiv', attrs: { class: 'note' }, content: [ para( txt( 'hi' ) ) ] };
	assert.equal( out( doc( [ div ] ) ), '::: note\nhi\n:::' );
} );
