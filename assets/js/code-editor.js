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
	const preview = document.getElementById( 'wpcarve-live-preview' );

	// Always render the preview server-side: the endpoint applies the post
	// author's safe-mode policy (raw HTML is escaped unless the author may post
	// unfiltered HTML), so previewing another user's content can't inject script
	// into this page. The in-browser engine is not used here because it would
	// emit raw HTML live regardless of that policy.
	const postIdEl = document.getElementById( 'post_ID' );
	const postId = postIdEl ? ( parseInt( postIdEl.value, 10 ) || 0 ) : 0;

	function render( source ) {
		if ( ! preview || ! cfg.restRender || ! wp || ! wp.apiFetch ) {
			return;
		}
		wp.apiFetch( {
			url: cfg.restRender,
			method: 'POST',
			data: { carve: source, context: 'post', post_id: postId },
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
