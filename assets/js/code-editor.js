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
	const previewWrap = document.querySelector( '.wpcarve-live-preview-wrap' );
	const editorWrap = document.getElementById( 'wp-content-wrap' );
	const modeButtons = document.querySelectorAll( '[data-wpcarve-mode]' );
	const toolbar = document.querySelector( '.wpcarve-document-toolbar' );
	const moreInsert = document.querySelector( '.wpcarve-more-insert' );
	const scrollSync = document.querySelector( '.wpcarve-scroll-sync input' );
	let workspace = null;
	if ( editorWrap && previewWrap ) {
		workspace = document.createElement( 'div' );
		workspace.className = 'wpcarve-document-workspace';
		editorWrap.parentNode.insertBefore( workspace, editorWrap );
		workspace.append( editorWrap, previewWrap );
	}

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
				syncFromSource();
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

	let syncingScroll = false;
	function syncEnabled() {
		return document.body.dataset.wpcarveDocumentMode === 'split' && ( ! scrollSync || scrollSync.checked );
	}

	function withScrollLock( callback ) {
		if ( syncingScroll ) {
			return;
		}
		syncingScroll = true;
		callback();
		window.requestAnimationFrame( () => {
			syncingScroll = false;
		} );
	}

	function syncFromSource() {
		if ( ! preview || ! syncEnabled() ) {
			return;
		}
		const info = cm ? cm.getScrollInfo() : {
			top: textarea.scrollTop,
			height: textarea.scrollHeight,
			clientHeight: textarea.clientHeight,
		};
		const sourceRange = Math.max( 1, info.height - info.clientHeight );
		const previewRange = Math.max( 0, preview.scrollHeight - preview.clientHeight );
		withScrollLock( () => {
			preview.scrollTop = ( info.top / sourceRange ) * previewRange;
		} );
	}

	function syncFromPreview() {
		if ( ! preview || ! syncEnabled() ) {
			return;
		}
		const previewRange = Math.max( 1, preview.scrollHeight - preview.clientHeight );
		const ratio = preview.scrollTop / previewRange;
		withScrollLock( () => {
			if ( cm ) {
				const info = cm.getScrollInfo();
				cm.scrollTo( null, ratio * Math.max( 0, info.height - info.clientHeight ) );
			} else {
				textarea.scrollTop = ratio * Math.max( 0, textarea.scrollHeight - textarea.clientHeight );
			}
		} );
	}

	if ( cm ) {
		cm.on( 'scroll', syncFromSource );
	} else {
		textarea.addEventListener( 'scroll', syncFromSource, { passive: true } );
	}
	if ( preview ) {
		preview.addEventListener( 'scroll', syncFromPreview, { passive: true } );
	}
	if ( scrollSync ) {
		scrollSync.addEventListener( 'change', syncFromSource );
	}

	function sourceValue() {
		return cm ? cm.getValue() : textarea.value;
	}

	function replaceSelection( open, close, insert ) {
		if ( cm ) {
			const selected = cm.getSelection();
			const value = insert || ( open + ( selected || '' ) + close );
			cm.replaceSelection( value, 'around' );
			cm.focus();
			if ( ! insert && ! selected ) {
				const cursor = cm.getCursor();
				cm.setCursor( { line: cursor.line, ch: Math.max( 0, cursor.ch - close.length ) } );
			}
			return;
		}

		const start = textarea.selectionStart || 0;
		const end = textarea.selectionEnd || start;
		const selected = textarea.value.slice( start, end );
		const value = insert || ( open + selected + close );
		textarea.setRangeText( value, start, end, 'end' );
		if ( ! insert && ! selected ) {
			textarea.setSelectionRange( start + open.length, start + open.length );
		}
		textarea.focus();
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	function prefixLines( prefix, heading ) {
		if ( cm ) {
			const from = cm.getCursor( 'from' );
			const to = cm.getCursor( 'to' );
			const lastLine = to.ch === 0 && to.line > from.line ? to.line - 1 : to.line;
			cm.operation( () => {
				for ( let line = lastLine; line >= from.line; line-- ) {
					if ( heading ) {
						const current = cm.getLine( line );
						const stripped = current.replace( /^#{1,6}\s+/, '' );
						cm.replaceRange( prefix + stripped, { line, ch: 0 }, { line, ch: current.length } );
					} else {
						cm.replaceRange( prefix, { line, ch: 0 } );
					}
				}
			} );
			cm.focus();
			return;
		}

		const start = textarea.selectionStart || 0;
		const end = textarea.selectionEnd || start;
		const lineStart = textarea.value.lastIndexOf( '\n', Math.max( 0, start - 1 ) ) + 1;
		let lineEnd = textarea.value.indexOf( '\n', end );
		if ( lineEnd < 0 ) {
			lineEnd = textarea.value.length;
		}
		const selectedLines = textarea.value.slice( lineStart, lineEnd );
		const replacement = selectedLines.split( '\n' ).map( ( line ) =>
			heading ? prefix + line.replace( /^#{1,6}\s+/, '' ) : prefix + line
		).join( '\n' );
		textarea.setRangeText( replacement, lineStart, lineEnd, 'select' );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		textarea.focus();
	}

	function insertBlock( insert ) {
		const current = sourceValue();
		let before = '';
		let after = '';
		if ( cm ) {
			const from = cm.indexFromPos( cm.getCursor( 'from' ) );
			const to = cm.indexFromPos( cm.getCursor( 'to' ) );
			before = from > 0 && current[ from - 1 ] !== '\n' ? '\n\n' : '';
			after = to < current.length && current[ to ] !== '\n' ? '\n\n' : '';
		} else {
			const from = textarea.selectionStart || 0;
			const to = textarea.selectionEnd || from;
			before = from > 0 && current[ from - 1 ] !== '\n' ? '\n\n' : '';
			after = to < current.length && current[ to ] !== '\n' ? '\n\n' : '';
		}
		replaceSelection( '', '', before + insert + after );
	}

	if ( toolbar ) {
		toolbar.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-wpcarve-open]' );
			if ( ! button ) {
				return;
			}
			const action = button.dataset.wpcarveAction || 'wrap';
			const insert = button.dataset.wpcarveInsert || '';
			if ( action === 'prefix' || action === 'heading' ) {
				prefixLines( insert, action === 'heading' );
			} else if ( action === 'block' ) {
				insertBlock( insert );
			} else {
				replaceSelection(
					button.dataset.wpcarveOpen || '',
					button.dataset.wpcarveClose || '',
					insert
				);
			}
		} );
	}
	if ( moreInsert ) {
		moreInsert.addEventListener( 'change', () => {
			if ( moreInsert.value ) {
				insertBlock( moreInsert.value );
				moreInsert.value = '';
			}
		} );
	}

	function setMode( mode ) {
		const showEditor = mode !== 'preview';
		const showPreview = mode !== 'write';
		document.body.dataset.wpcarveDocumentMode = mode;
		if ( workspace ) {
			workspace.dataset.mode = mode;
		}
		if ( editorWrap ) {
			editorWrap.hidden = ! showEditor;
		}
		if ( previewWrap ) {
			previewWrap.hidden = ! showPreview;
		}
		if ( toolbar ) {
			toolbar.hidden = ! showEditor;
		}
		modeButtons.forEach( ( button ) => {
			const active = button.dataset.wpcarveMode === mode;
			button.classList.toggle( 'button-primary', active );
			button.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		if ( showPreview ) {
			render( sourceValue() );
		}
		if ( cm && showEditor ) {
			window.setTimeout( () => cm.refresh(), 0 );
		}
		if ( mode === 'split' ) {
			window.setTimeout( syncFromSource, 0 );
		}
	}

	modeButtons.forEach( ( button ) => {
		button.addEventListener( 'click', () => setMode( button.dataset.wpcarveMode || 'write' ) );
	} );
	setMode( 'write' );
	}
} )( window.wp || {}, window.wpCarve );
