/* Carve comment editor: Write/Preview tabs + formatting toolbar above the
   comment field. Preview renders through the public preview-comment REST
   endpoint (comment profile + strict safe mode), so commenters see exactly
   what would be published. */
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
		ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	function prefixLine( ta, prefix ) {
		const s = ta.selectionStart;
		const lineStart = ta.value.lastIndexOf( '\n', s - 1 ) + 1;
		ta.value = ta.value.slice( 0, lineStart ) + prefix + ta.value.slice( lineStart );
		ta.focus();
		ta.selectionStart = ta.selectionEnd = s + prefix.length;
		ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const ta = document.getElementById( 'comment' );
		if ( ! ta || document.querySelector( '.wpcarve-comment-toolbar' ) ) {
			return;
		}

		const cfg = window.wpCarveComment || {};
		const hasPreview = !! cfg.previewUrl;
		let current = 'write';

		// Formatting toolbar.
		const bar = document.createElement( 'div' );
		bar.className = 'wpcarve-comment-toolbar';
		BUTTONS.forEach( function ( b ) {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.textContent = b.label;
			btn.title = b.title;
			btn.addEventListener( 'click', function () {
				if ( hasPreview && current !== 'write' ) {
					switchTab( 'write' );
				}
				if ( b.wrap ) {
					surround( ta, b.wrap[ 0 ], b.wrap[ 1 ] );
				} else if ( b.line ) {
					prefixLine( ta, b.line );
				}
			} );
			bar.appendChild( btn );
		} );

		if ( ! hasPreview ) {
			ta.parentNode.insertBefore( bar, ta );
			return;
		}

		// Write / Preview tabs (parity with wp-djot's comment editor).
		const tabs = document.createElement( 'div' );
		tabs.className = 'wpcarve-comment-tabs';
		tabs.setAttribute( 'role', 'tablist' );

		function makeTab( label, active ) {
			const tab = document.createElement( 'button' );
			tab.type = 'button';
			tab.className = 'wpcarve-tab' + ( active ? ' is-active' : '' );
			tab.textContent = label;
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'aria-selected', String( active ) );
			tabs.appendChild( tab );

			return tab;
		}

		const writeTab = makeTab( cfg.writeLabel || 'Write', true );
		const previewTab = makeTab( cfg.previewLabel || 'Preview', false );

		// Preview pane: .wpcarve so rendered constructs get the same styling
		// as published comments.
		const pane = document.createElement( 'div' );
		pane.className = 'wpcarve wpcarve-comment-preview';
		pane.hidden = true;

		let requestSeq = 0;

		function setState( text, stateClass ) {
			pane.innerHTML = '';
			const p = document.createElement( 'p' );
			p.className = stateClass;
			p.textContent = text;
			pane.appendChild( p );
		}

		function renderPreview() {
			const src = ta.value.trim();
			if ( ! src ) {
				setState( cfg.emptyText || 'Nothing to preview yet.', 'wpcarve-preview-empty' );
				return;
			}
			setState( cfg.loadingText || 'Rendering preview…', 'wpcarve-preview-loading' );
			const seq = ++requestSeq;
			fetch( cfg.previewUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { carve: src } ),
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( seq !== requestSeq || current !== 'preview' ) {
						return;
					}
					if ( data && data.html ) {
						// Server response is fully wp_kses-sanitized.
						pane.innerHTML = data.html;
					} else {
						setState( cfg.errorText || 'Preview failed.', 'wpcarve-preview-error' );
					}
				} )
				.catch( function () {
					if ( seq === requestSeq && current === 'preview' ) {
						setState( cfg.errorText || 'Preview failed.', 'wpcarve-preview-error' );
					}
				} );
		}

		function switchTab( tab ) {
			current = tab;
			const isWrite = tab === 'write';
			writeTab.classList.toggle( 'is-active', isWrite );
			writeTab.setAttribute( 'aria-selected', String( isWrite ) );
			previewTab.classList.toggle( 'is-active', ! isWrite );
			previewTab.setAttribute( 'aria-selected', String( ! isWrite ) );
			ta.style.display = isWrite ? '' : 'none';
			bar.style.display = isWrite ? '' : 'none';
			pane.hidden = isWrite;
			if ( isWrite ) {
				ta.focus();
			} else {
				renderPreview();
			}
		}

		writeTab.addEventListener( 'click', function () {
			switchTab( 'write' );
		} );
		previewTab.addEventListener( 'click', function () {
			switchTab( 'preview' );
		} );

		ta.parentNode.insertBefore( tabs, ta );
		ta.parentNode.insertBefore( bar, ta );
		ta.parentNode.insertBefore( pane, ta.nextSibling );
	} );
} )();
