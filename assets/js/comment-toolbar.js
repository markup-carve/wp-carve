/* Carve comment toolbar: insert common Carve syntax into the comment field. */
( function () {
	'use strict';

	const BUTTONS = [
		{ label: 'B', title: 'Strong', wrap: [ '*', '*' ] },
		{ label: 'I', title: 'Italic', wrap: [ '/', '/' ] },
		{ label: 'S', title: 'Strike', wrap: [ '~', '~' ] },
		{ label: '<>', title: 'Code', wrap: [ '`', '`' ] },
		{ label: 'Link', title: 'Link', wrap: [ '[', '](https://)' ] },
		{ label: 'Quote', title: 'Quote', line: '> ' },
		{ label: 'List', title: 'List item', line: '- ' },
	];

	function surround( ta, before, after ) {
		const s = ta.selectionStart;
		const e = ta.selectionEnd;
		const sel = ta.value.slice( s, e );
		ta.value = ta.value.slice( 0, s ) + before + sel + after + ta.value.slice( e );
		ta.focus();
		ta.selectionStart = s + before.length;
		ta.selectionEnd = e + before.length;
	}

	function prefixLine( ta, prefix ) {
		const s = ta.selectionStart;
		const lineStart = ta.value.lastIndexOf( '\n', s - 1 ) + 1;
		ta.value = ta.value.slice( 0, lineStart ) + prefix + ta.value.slice( lineStart );
		ta.focus();
		ta.selectionStart = ta.selectionEnd = s + prefix.length;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const ta = document.getElementById( 'comment' );
		if ( ! ta || document.querySelector( '.wpcarve-comment-toolbar' ) ) {
			return;
		}
		const bar = document.createElement( 'div' );
		bar.className = 'wpcarve-comment-toolbar';
		BUTTONS.forEach( function ( b ) {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.textContent = b.label;
			btn.title = b.title;
			btn.addEventListener( 'click', function () {
				if ( b.wrap ) {
					surround( ta, b.wrap[ 0 ], b.wrap[ 1 ] );
				} else if ( b.line ) {
					prefixLine( ta, b.line );
				}
			} );
			bar.appendChild( btn );
		} );

		// Preview toggle: renders through the public preview-comment endpoint
		// (comment profile + strict safe mode), so what commenters see is what
		// gets published. Config comes via wp_localize_script.
		const cfg = window.wpCarveComment;
		if ( cfg && cfg.previewUrl ) {
			const pane = document.createElement( 'div' );
			pane.className = 'wpcarve wpcarve-comment-preview';
			pane.hidden = true;

			const toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'wpcarve-comment-preview-toggle';
			toggle.textContent = cfg.previewLabel;
			toggle.addEventListener( 'click', function () {
				if ( ! pane.hidden ) {
					pane.hidden = true;
					ta.style.display = '';
					toggle.textContent = cfg.previewLabel;
					ta.focus();
					return;
				}
				const src = ta.value.trim();
				pane.textContent = src ? '\u2026' : cfg.emptyText;
				pane.hidden = false;
				ta.style.display = 'none';
				toggle.textContent = cfg.writeLabel;
				if ( ! src ) {
					return;
				}
				fetch( cfg.previewUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { carve: src } ),
				} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( data ) {
						if ( pane.hidden ) {
							return;
						}
						pane.innerHTML = data && data.html ? data.html : cfg.errorText;
					} )
					.catch( function () {
						if ( ! pane.hidden ) {
							pane.textContent = cfg.errorText;
						}
					} );
			} );
			bar.appendChild( toggle );
			ta.parentNode.insertBefore( pane, ta.nextSibling );
		}

		ta.parentNode.insertBefore( bar, ta );
	} );
} )();
