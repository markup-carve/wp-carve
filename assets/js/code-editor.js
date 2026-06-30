/* global wp, wpCarve */
( function ( wp, cfg ) {
	'use strict';

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => init( wp, cfg ) );
	} else {
		init( wp, cfg );
	}

	function init( wp, cfg ) {
	cfg = cfg || {};

	const textarea = document.getElementById( 'content' );
	if ( ! textarea ) {
		return;
	}
	const preview = document.getElementById( 'wp-carve-live-preview' );

	function render( source ) {
		const engine = window.wpCarveEngine;
		if ( cfg.livePreview && engine && typeof engine.carveToHtml === 'function' ) {
			try {
				if ( preview ) {
					preview.innerHTML = engine.carveToHtml( source );
				}
				return;
			} catch ( e ) {
				// fall through to the REST render endpoint
			}
		}
		if ( ! preview || ! cfg.restRender || ! wp || ! wp.apiFetch ) {
			return;
		}
		wp.apiFetch( {
			url: cfg.restRender,
			method: 'POST',
			data: { carve: source, context: 'post' },
		} )
			.then( ( res ) => {
				preview.innerHTML = res.html || '';
			} )
			.catch( () => {} );
	}

	// Turn the classic editor textarea into a plain code editor. Falls back to
	// the bare textarea when CodeMirror is unavailable (cfg.codeEditor null).
	let cm = null;
	if ( cfg.codeEditor && wp && wp.codeEditor && wp.codeEditor.initialize ) {
		const instance = wp.codeEditor.initialize( textarea, cfg.codeEditor );
		cm = instance && instance.codemirror;
	}

	let timer = null;
	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( () => {
			render( cm ? cm.getValue() : textarea.value );
		}, 250 );
	}

	if ( cm ) {
		cm.on( 'change', schedule );
	} else {
		textarea.addEventListener( 'input', schedule );
	}
	}
} )( window.wp || {}, window.wpCarve );
