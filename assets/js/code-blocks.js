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

	const ICON_COPY =
		'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
	const ICON_DONE =
		'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';

	ready( function () {
		document.querySelectorAll( '.wpcarve pre > code' ).forEach( function ( code ) {
			const pre = code.parentElement;
			if ( ! pre || pre.dataset.carveEnhanced ) {
				return;
			}
			pre.dataset.carveEnhanced = '1';
			pre.style.position = pre.style.position || 'relative';

			// Surface the language as data-lang so the pill (pre[data-lang]::after)
			// shows it. Torchlight already sets data-lang; this covers plain blocks
			// (<code class="language-php">).
			if ( ! pre.dataset.lang ) {
				const langMatch = ( code.className || '' ).match( /language-([\w+#-]+)/ );
				if ( langMatch && langMatch[ 1 ] !== 'text' ) {
					pre.dataset.lang = langMatch[ 1 ];
				}
			}

			// Copy button (icon).
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'wpcarve-copy';
			btn.setAttribute( 'aria-label', 'Copy code' );
			btn.title = 'Copy code';
			btn.innerHTML = ICON_COPY;
			btn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( code.innerText ).then( function () {
					btn.innerHTML = ICON_DONE;
					btn.classList.add( 'is-copied' );
					setTimeout( function () {
						btn.innerHTML = ICON_COPY;
						btn.classList.remove( 'is-copied' );
					}, 1500 );
				} );
			} );
			pre.appendChild( btn );

			// Plain Carve fallback line numbers; Torchlight renders its own gutter.
			if ( pre.classList.contains( 'line-numbers' ) && ! code.classList.contains( 'torchlight' ) ) {
				const lines = code.innerHTML.replace( /\n$/, '' ).split( '\n' ).length;
				const gutter = document.createElement( 'span' );
				gutter.className = 'wpcarve-line-numbers';
				gutter.setAttribute( 'aria-hidden', 'true' );
				for ( let i = 1; i <= lines; i++ ) {
					gutter.appendChild( document.createElement( 'span' ) );
				}
				pre.insertBefore( gutter, code );
			}
		} );
	} );
} )();
