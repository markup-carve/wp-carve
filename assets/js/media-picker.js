/* global wp, wpCarveMediaL10n */
/**
 * The media library picker, and the one mapping from a library selection to
 * Carve source. Three editors insert images (classic source, block source,
 * block visual), and each used to raise its own `window.prompt` for a URL.
 *
 * The `wp-image-N` class is the load-bearing part. Carve renders at
 * `the_content` priority 9 and core's `wp_filter_content_tags` runs at 12, so
 * core reads that class afterwards and injects width, height, loading,
 * decoding, srcset and sizes from the attachment id. Hand-authored `srcset`
 * and `sizes` cannot work instead: they are not in the kses img allowlist, so
 * Converter::allowedHtml() strips them.
 */
( function ( window ) {
	'use strict';

	const l10n = window.wpCarveMediaL10n || {};

	/**
	 * A label inside `![...]` must not close the bracket pair, and has to stay
	 * on one line: a blank line, or a line that opens a heading, a fence or a
	 * blockquote, ends the paragraph and leaves the two halves of the image
	 * construct as literal text. Collapsed the way a caption is.
	 */
	function escapeLabel( text ) {
		return String( text == null ? '' : text )
			.replace( /\s+/g, ' ' )
			.trim()
			.replace( /\\/g, '\\\\' )
			.replace( /([\[\]])/g, '\\$1' );
	}

	/** A destination inside `(...)` must not close the parenthesis pair. */
	function escapeTarget( text ) {
		return encodeURI( String( text == null ? '' : text ) )
			.replace( /%25([0-9a-fA-F]{2})/g, '%$1' )
			.replace( /\(/g, '%28' )
			.replace( /\)/g, '%29' );
	}

	/**
	 * A caption line folds the lines after it like a paragraph, so a library
	 * caption with hard breaks has to become one line or it swallows whatever
	 * the author wrote next.
	 */
	function flattenCaption( text ) {
		return String( text == null ? '' : text ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * The library selection, reduced to the fields Carve source owns. Alt and
	 * caption are read once here; from then on the source is authoritative and
	 * a later library edit does not write back, because the source has to
	 * render where no media library exists.
	 *
	 * The class keeps the FULL-SIZE attachment id even when the author picked a
	 * smaller size. That is what core expects, and it is what lets core derive
	 * the whole srcset for the size that was picked.
	 */
	function pick( attachment, size ) {
		const data = attachment || {};
		const sizes = data.sizes || {};
		const chosen = size && sizes[ size ] ? sizes[ size ] : null;
		const id = parseInt( data.id, 10 );

		return {
			id: id > 0 ? id : 0,
			url: String( ( chosen && chosen.url ) || data.url || '' ),
			alt: String( data.alt || '' ),
			caption: flattenCaption( data.caption ),
			className: id > 0 ? 'wp-image-' + id : '',
		};
	}

	/** The inline `![alt](url){.wp-image-N}`, with no caption line. */
	function toInline( selection ) {
		const chosen = selection || {};

		return '![' + escapeLabel( chosen.alt ) + '](' + escapeTarget( chosen.url ) + ')'
			+ ( chosen.className ? '{.' + chosen.className + '}' : '' );
	}

	/** The full Carve source: the image, plus a caption line when there is one. */
	function toSource( selection ) {
		const chosen = selection || {};
		const caption = flattenCaption( chosen.caption );

		return toInline( chosen ) + ( caption ? '\n^ ' + caption : '' );
	}

	/**
	 * A caption attaches to an image that is its own block, and it then FOLDS
	 * the lines after it like a paragraph. So a captioned image inserted
	 * mid-paragraph needs a break on both sides: without the leading one the
	 * caption is not a caption, and without the trailing one the caption
	 * swallows whatever followed the cursor.
	 */
	function toSourceAt( selection, before, after ) {
		const source = toSource( selection );
		if ( source.indexOf( '\n' ) === -1 ) {
			return source;
		}
		const head = String( before == null ? '' : before );
		const tail = String( after == null ? '' : after );

		return ( head !== '' && ! /\n$/.test( head ) ? '\n' : '' )
			+ source
			+ ( tail !== '' && ! /^\n/.test( tail ) ? '\n' : '' );
	}

	/** Whether the media modal can be opened at all on this screen. */
	function available() {
		return !! ( window.wp && typeof window.wp.media === 'function' );
	}

	/**
	 * Open core's media modal. `onSelect` receives a `pick()`. When wp.media is
	 * missing the `fallback` runs instead, which is how each editor keeps its
	 * prompt path.
	 */
	function open( options ) {
		const opts = options || {};
		if ( ! available() ) {
			if ( typeof opts.fallback === 'function' ) {
				opts.fallback();
			}

			return null;
		}
		const frame = window.wp.media( {
			title: opts.title || l10n.frameTitle || 'Select or upload an image',
			button: { text: opts.buttonText || l10n.frameButton || 'Insert into Carve' },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			const first = frame.state().get( 'selection' ).first();
			if ( ! first ) {
				return;
			}
			// The size the author chose in the modal decides the URL. The
			// display settings only exist on the insert state, so a frame
			// opened without them falls back to the full size.
			let size = '';
			const state = frame.state();
			if ( typeof state.display === 'function' ) {
				const display = state.display( first );
				if ( display && typeof display.get === 'function' ) {
					size = display.get( 'size' ) || '';
				}
			}
			if ( typeof opts.onSelect === 'function' ) {
				opts.onSelect( pick( first.toJSON(), size ) );
			}
		} );
		frame.open();

		return frame;
	}

	window.wpCarveMedia = {
		available: available,
		escapeLabel: escapeLabel,
		escapeTarget: escapeTarget,
		flattenCaption: flattenCaption,
		open: open,
		pick: pick,
		toInline: toInline,
		toSource: toSource,
		toSourceAt: toSourceAt,
	};
} )( window );
