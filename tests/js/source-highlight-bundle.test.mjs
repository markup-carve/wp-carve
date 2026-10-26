import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { Window } from 'happy-dom';

test( 'source highlighter leaves the theme Prism and rendered code alone', async () => {
	const window = new Window( { url: 'http://localhost/' } );
	window.document.body.innerHTML = '<code class="language-js"><span class="server-token">const x = 1;</span></code>';
	const themePrism = { themePlugin: true };
	window.Prism = themePrism;
	window.eval( readFileSync( new URL( '../../assets/js/vendor/carve-source-highlight.js', import.meta.url ), 'utf8' ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 30 ) );
	assert.equal( window.Prism, themePrism );
	assert.match( window.document.querySelector( 'code' ).innerHTML, /server-token/ );
	assert.match( window.wpCarveHighlight.highlightHtml( '# Heading' ), /token title/ );
	window.happyDOM.abort();
} );

test( 'textarea highlighting tracks edits and scroll without replacing the input', () => {
	const window = new Window( { url: 'http://localhost/' } );
	window.document.body.innerHTML = '<form><textarea id="comment"></textarea></form>';
	window.eval( readFileSync( new URL( '../../assets/js/vendor/carve-source-highlight.js', import.meta.url ), 'utf8' ) );
	const textarea = window.document.querySelector( 'textarea' );
	const wrap = window.wpCarveHighlight.attachTextarea( textarea );
	textarea.value = 'A *strong* line';
	textarea.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	assert.equal( textarea.form, window.document.querySelector( 'form' ) );
	assert.match( wrap.querySelector( 'pre' ).innerHTML, /token bold/ );
	textarea.scrollTop = 12;
	textarea.dispatchEvent( new window.Event( 'scroll' ) );
	assert.equal( wrap.querySelector( 'pre' ).scrollTop, 12 );
	window.happyDOM.abort();
} );
