/* Carve settings: tab switching + dependent-field visibility. */
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
		const root = document.querySelector( '.wpcarve-settings' );
		if ( ! root ) {
			return;
		}

		// Tabs.
		const tabs = root.querySelectorAll( '.wpcarve-tabs .nav-tab' );
		const panels = root.querySelectorAll( '.wpcarve-panel' );

		function activate( id ) {
			tabs.forEach( function ( t ) {
				t.classList.toggle( 'nav-tab-active', t.dataset.tab === id );
			} );
			panels.forEach( function ( p ) {
				p.classList.toggle( 'is-active', p.dataset.panel === id );
			} );
			try {
				window.history.replaceState( null, '', '#' + id );
			} catch ( e ) {}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				activate( tab.dataset.tab );
			} );
		} );

		const hash = ( window.location.hash || '' ).replace( '#', '' );
		if ( hash && root.querySelector( '.wpcarve-panel[data-panel="' + hash + '"]' ) ) {
			activate( hash );
		}

		// Dependent fields: a card with data-depends="key" is shown only when the
		// checkbox for that key is checked.
		const deps = root.querySelectorAll( '.wpcarve-card[data-depends]' );

		function inputFor( key ) {
			return root.querySelector( 'input[name="wpcarve_settings[' + key + ']"]' );
		}

		function sync() {
			deps.forEach( function ( card ) {
				const ctrl = inputFor( card.dataset.depends );
				card.classList.toggle( 'is-hidden', !! ctrl && ! ctrl.checked );
			} );
		}

		root.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.matches( 'input[type="checkbox"]' ) ) {
				sync();
			}
		} );
		sync();
	} );
} )();
