/* Carve code blocks: copy button + optional line numbers. */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		document.querySelectorAll( '.wp-carve pre > code' ).forEach( function ( code ) {
			const pre = code.parentElement;
			if ( ! pre || pre.dataset.carveEnhanced ) {
				return;
			}
			pre.dataset.carveEnhanced = '1';
			pre.style.position = pre.style.position || 'relative';

			// Copy button.
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'wp-carve-copy';
			btn.textContent = 'Copy';
			btn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( code.innerText ).then( function () {
					btn.textContent = 'Copied';
					setTimeout( function () {
						btn.textContent = 'Copy';
					}, 1500 );
				} );
			} );
			pre.appendChild( btn );

			// Line numbers when the <pre> opted in (class "line-numbers").
			if ( pre.classList.contains( 'line-numbers' ) ) {
				const lines = code.innerHTML.replace( /\n$/, '' ).split( '\n' ).length;
				const gutter = document.createElement( 'span' );
				gutter.className = 'wp-carve-line-numbers';
				gutter.setAttribute( 'aria-hidden', 'true' );
				for ( let i = 1; i <= lines; i++ ) {
					gutter.appendChild( document.createElement( 'span' ) );
				}
				pre.insertBefore( gutter, code );
			}
		} );
	} );
} )();
