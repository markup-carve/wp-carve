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
				preview.classList.remove( 'wpcarve-preview-error' );
				preview.innerHTML = res.html || '';
				syncFromSource();
			} )
			.catch( ( error ) => {
				preview.classList.add( 'wpcarve-preview-error' );
				preview.textContent = error.message || cfg.previewError || 'Preview failed.';
			} );
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

	// The selection as offsets into sourceValue(). Every toolbar action positions
	// the caret in this one coordinate system; CodeMirror's line/ch pairs only
	// appear where CodeMirror's own API demands them.
	function selectionRange() {
		if ( cm ) {
			return {
				start: cm.indexFromPos( cm.getCursor( 'from' ) ),
				end: cm.indexFromPos( cm.getCursor( 'to' ) ),
			};
		}
		const start = textarea.selectionStart || 0;

		return { start: start, end: textarea.selectionEnd || start };
	}

	// Write `next` over the document with the smallest edit that produces it and
	// leave the selection at `from`..`to`, both offsets into `next`. Line-local
	// arithmetic placed the caret wrong whenever an edit changed a line's length
	// or spanned lines, so there is one index-based path for both editors.
	function applyEdit( next, from, to ) {
		const edit = splice( sourceValue(), next );
		if ( cm ) {
			cm.replaceRange( edit.text, cm.posFromIndex( edit.from ), cm.posFromIndex( edit.to ) );
			cm.setSelection( cm.posFromIndex( from ), cm.posFromIndex( to ) );
			cm.focus();

			return;
		}
		textarea.setRangeText( edit.text, edit.from, edit.to );
		textarea.setSelectionRange( from, to );
		textarea.focus();
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	// Replace the selection with `text` and select `selectFrom`..`selectTo`,
	// counted from the start of the insert. With no range the caret lands at its
	// end rather than selecting the whole template.
	function insertText( text, selectFrom, selectTo ) {
		const value = sourceValue();
		const range = selectionRange();
		const from = selectFrom === undefined ? text.length : selectFrom;
		const to = selectTo === undefined ? from : selectTo;
		applyEdit(
			value.slice( 0, range.start ) + text + value.slice( range.end ),
			range.start + from,
			range.start + to,
		);
	}

	function writeLinked( selection, markup ) {
		const value = sourceValue();
		const at = selection.from + markup.length;
		applyEdit( value.slice( 0, selection.from ) + markup + value.slice( selection.to ), at, at );
	}

	function currentLinkedSelection() {
		const range = selectionRange();

		return { from: range.start, to: range.end, text: sourceValue().slice( range.start, range.end ) };
	}

	function insertImage() {
		const media = window.wpCarveMedia;
		if ( ! media || ! media.available() ) {
			insertLinked( 'image' );

			return;
		}
		const selection = currentLinkedSelection();
		const value = sourceValue();
		const before = value.slice( 0, selection.from );
		const after = value.slice( selection.to );
		media.open( {
			fallback: () => insertLinked( 'image' ),
			onSelect: ( chosen ) => writeLinked( selection, media.toSourceAt( chosen, before, after ) ),
		} );
	}

	function insertLinked( kind ) {
		const selection = currentLinkedSelection();
		const url = window.prompt( kind === 'link' ? cfg.linkUrlLabel : cfg.imageUrlLabel, '' );
		if ( ! url || ! url.trim() ) {
			return;
		}
		const label = selection.text || window.prompt( kind === 'link' ? cfg.linkTextLabel : cfg.imageAltLabel, '' );
		if ( label === null || ( kind === 'link' && ! label.trim() ) ) {
			return;
		}
		const escapedLabel = label.replace( /\\/g, '\\\\' ).replace( /([\[\]])/g, '\\$1' );
		const target = encodeURI( url.trim() ).replace( /%25([0-9a-fA-F]{2})/g, '%$1' ).replace( /\(/g, '%28' ).replace( /\)/g, '%29' );
		const markup = ( kind === 'image' ? '![' : '[' ) + escapedLabel + '](' + target + ')';
		writeLinked( selection, markup );
	}

	function prefixLines( prefix, heading ) {
		const value = sourceValue();
		const range = selectionRange();
		const lineStart = value.lastIndexOf( '\n', Math.max( 0, range.start - 1 ) ) + 1;
		const last = range.end > range.start && value[ range.end - 1 ] === '\n' ? range.end - 1 : range.end;
		let lineEnd = value.indexOf( '\n', last );
		if ( lineEnd < 0 ) {
			lineEnd = value.length;
		}
		// Per line: where it started, how much of its head the prefix replaces, and
		// how far the lines above it have already pushed the text along. Enough to
		// carry an offset in the old document over to the new one.
		const spans = [];
		let from = lineStart;
		let shift = 0;
		const replaced = value.slice( lineStart, lineEnd ).split( '\n' ).map( ( line ) => {
			const body = heading ? line.replace( /^#{1,6}\s+/, '' ) : line;
			const stripped = line.length - body.length;
			spans.push( { from: from, stripped: stripped, shift: shift } );
			shift += prefix.length - stripped;
			from += line.length + 1;

			return prefix + body;
		} ).join( '\n' );

		// An offset inside a head the prefix replaced has nowhere of its own to go,
		// so it clamps to the start of the body text.
		function carried( index ) {
			for ( let at = spans.length - 1; at >= 0; at-- ) {
				if ( index >= spans[ at ].from ) {
					const within = Math.max( 0, index - spans[ at ].from - spans[ at ].stripped );

					return spans[ at ].from + spans[ at ].shift + prefix.length + within;
				}
			}

			return index;
		}

		const next = value.slice( 0, lineStart ) + replaced + value.slice( lineEnd );
		applyEdit( next, carried( range.start ), carried( range.end ) );
	}

	function insertBlock( insert ) {
		const value = sourceValue();
		const range = selectionRange();
		let start = range.start;
		const end = range.end;
		const selected = value.slice( start, end );
		const fence = /^(?:(`{3,}[^\n]*)|(:{3,}[^\n]*))\n\n(`{3,}|:{3,})$/.exec( insert );
		if ( selected && fence ) {
			const opener = fence[ 1 ] || fence[ 2 ];
			const marker = /^(`{3,}|:{3,})/.exec( opener )[ 0 ];
			const character = marker[ 0 ];
			const width = selected.split( '\n' ).reduce( ( longest, line ) => {
				const run = line.match( new RegExp( '^' + character + '{3,}' ) );

				return Math.max( longest, run ? run[ 0 ].length + 1 : 0 );
			}, marker.length );
			const boundary = character.repeat( width );
			insert = boundary + opener.slice( marker.length ) + '\n' + selected.replace( /\n$/, '' ) + '\n' + boundary;
		} else if ( selected ) {
			// Tables, dividers and other templates follow selected text.
			// Keep that text when the template has no content slot.
			start = end;
		}
		const before = start > 0 && value[ start - 1 ] !== '\n' ? '\n\n' : '';
		const after = end < value.length && value[ end ] !== '\n' ? '\n\n' : '';
		// The caret belongs on the template's empty body line where it has one, and
		// never on a selection of the whole template: that is the jump the author
		// sees, and typing over it would wipe the template out.
		const body = insert.indexOf( '\n\n' );
		const at = start + before.length + ( body < 0 ? insert.length : body + 1 );
		const next = value.slice( 0, start ) + before + insert + after + value.slice( end );
		applyEdit( next, at, at );
	}

	// Smallest edit that turns `previous` into `next`, so a toggle keeps the
	// native and CodeMirror undo stacks granular instead of replacing the document.
	function splice( previous, next ) {
		let head = 0;
		while ( head < previous.length && head < next.length && previous[ head ] === next[ head ] ) {
			head++;
		}
		let tail = 0;
		while (
			tail < previous.length - head
			&& tail < next.length - head
			&& previous[ previous.length - 1 - tail ] === next[ next.length - 1 - tail ]
		) {
			tail++;
		}

		return { from: head, to: previous.length - tail, text: next.slice( head, next.length - tail ) };
	}

	// A second click removes the mark: in Carve a doubled delimiter renders as
	// literal text, so wrapping twice would break the markup. Selection, caret
	// inside an existing mark and caret in plain text all go through the one
	// shared toggle, which reports where the caret belongs afterwards.
	function toggleInline( open, close ) {
		const value = sourceValue();
		const range = selectionRange();
		const next = window.wpCarveInlineToggle.toggle( value, range.start, range.end, open, close, '' );
		applyEdit( next.value, next.start, next.end );
	}

	function insertFootnote() {
		const value = sourceValue();
		const range = selectionRange();
		const text = '^[note]';
		// The anchor follows the selected text, with the placeholder selected so
		// the next keystroke replaces the word and not the whole anchor.
		applyEdit(
			value.slice( 0, range.end ) + text + value.slice( range.end ),
			range.end + 2,
			range.end + text.length - 1,
		);
	}

	function insertMath() {
		const range = selectionRange();
		const content = sourceValue().slice( range.start, range.end ) || 'x';
		const runs = content.match( /`+/g ) || [];
		const fence = '`'.repeat( Math.max( 1, ...runs.map( ( run ) => run.length + 1 ) ) );
		const padding = content.startsWith( '`' ) || content.endsWith( '`' ) ? ' ' : '';
		const at = 1 + fence.length + padding.length;
		insertText( '$' + fence + padding + content + padding + fence, at, at + content.length );
	}

	function insertCitation() {
		const range = selectionRange();
		const key = sourceValue().slice( range.start, range.end ).replace( /^@/, '' ) || 'key';
		insertText( '[@' + key + ']', 2, 2 + key.length );
	}

	if ( toolbar ) {
		toolbar.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-wpcarve-open]' );
			if ( ! button ) {
				return;
			}
			const action = button.dataset.wpcarveAction || 'wrap';
			const insert = button.dataset.wpcarveInsert || '';
			if ( action === 'image' ) {
				insertImage();
			} else if ( action === 'link' ) {
				insertLinked( action );
			} else if ( action === 'prefix' || action === 'heading' ) {
				prefixLines( insert, action === 'heading' );
			} else if ( action === 'block' ) {
				insertBlock( insert );
			} else if ( button.dataset.wpcarveOpen && button.dataset.wpcarveClose ) {
				toggleInline( button.dataset.wpcarveOpen, button.dataset.wpcarveClose );
			}
		} );
	}
	if ( moreInsert ) {
		moreInsert.addEventListener( 'change', () => {
			const kind = moreInsert.value;
			if ( kind === 'media' ) {
				const range = selectionRange();
				const url = window.prompt( cfg.mediaUrlLabel || 'Media URL', sourceValue().slice( range.start, range.end ) );
				if ( url && url.trim() ) {
					insertText( ':media[' + url.trim().replace( /([\\\[\]])/g, '\\$1' ) + ']' );
				}
			} else if ( kind === 'footnote' ) {
				insertFootnote();
			} else if ( kind === 'math' ) {
				insertMath();
			} else if ( kind === 'citation' ) {
				insertCitation();
			} else if ( kind ) {
				insertBlock( kind );
			}
			moreInsert.value = '';
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
