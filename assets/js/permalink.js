/* Carve heading permalinks: click the ¶ to copy the anchor URL. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		const a = e.target.closest( '.wp-carve .permalink' );
		if ( ! a ) {
			return;
		}
		e.preventDefault();
		const url = a.href || ( location.origin + location.pathname + a.getAttribute( 'href' ) );
		navigator.clipboard.writeText( url ).then( function () {
			const old = a.getAttribute( 'aria-label' );
			a.setAttribute( 'aria-label', 'Link copied' );
			a.classList.add( 'is-copied' );
			setTimeout( function () {
				a.setAttribute( 'aria-label', old || 'Permalink' );
				a.classList.remove( 'is-copied' );
			}, 1500 );
		} );
		// Still update the address bar to the anchor.
		if ( a.hash ) {
			history.replaceState( null, '', a.hash );
		}
	} );
} )();
