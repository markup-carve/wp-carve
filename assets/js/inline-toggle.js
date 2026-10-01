/* Toggle arithmetic for the inline-mark toolbar buttons, shared by the block
   source editor, the classic source editor and the comment toolbar.

   Carve's emphasis delimiters are single characters, so wrapping an already
   marked span a second time produces a doubled run that renders as literal
   text. A second click therefore removes the mark. A run longer than the
   delimiter is literal text and is never unwrapped, so the toggle cannot turn
   `**text**` into a mark its author never wrote. */
( function () {
	'use strict';

	// Length of the unbroken run of `character` starting at `index` and walking
	// in `step`, counting the character at `index` itself.
	function run( value, index, step, character ) {
		let length = 0;
		for ( let i = index; i >= 0 && i < value.length && value[ i ] === character; i += step ) {
			length++;
		}

		return length;
	}

	function isCode( before, after ) {
		return before === '`' && after === '`';
	}

	// A boundary pair qualifies as this mark's delimiters only when the run of
	// the delimiter character is exactly as long as the delimiter. Asymmetric
	// braced pairs have no run to measure, so an exact match is enough.
	function pairs( value, openStart, closeStart, before, after ) {
		if ( value.slice( openStart, openStart + before.length ) !== before ) {
			return false;
		}
		if ( value.slice( closeStart, closeStart + after.length ) !== after ) {
			return false;
		}
		if ( before.length !== 1 || after.length !== 1 || before !== after ) {
			return true;
		}

		return run( value, openStart, -1, before ) === 1
			&& run( value, openStart, 1, before ) === 1
			&& run( value, closeStart, -1, after ) === 1
			&& run( value, closeStart, 1, after ) === 1;
	}

	// Drop the delimiters and, for a code span the apply side padded because
	// its content touches a backtick, the one space of padding with them.
	function remove( value, openStart, openEnd, closeStart, closeEnd, caret ) {
		let inner = value.slice( openEnd, closeStart );
		if (
			inner.length >= 2
			&& inner.startsWith( ' ' )
			&& inner.endsWith( ' ' )
			&& ( inner[ 1 ] === '`' || inner[ inner.length - 2 ] === '`' )
		) {
			inner = inner.slice( 1, -1 );
		}
		const next = value.slice( 0, openStart ) + inner + value.slice( closeEnd );
		if ( caret === undefined ) {
			return { value: next, start: openStart, end: openStart + inner.length };
		}
		const at = Math.min( Math.max( openStart, caret - ( openEnd - openStart ) ), openStart + inner.length );

		return { value: next, start: at, end: at };
	}

	function apply( value, start, end, before, after, content ) {
		let open = before;
		let close = after;
		let padding = '';
		if ( isCode( before, after ) ) {
			const runs = content.match( /`+/g ) || [];
			const fence = '`'.repeat( Math.max( 1, ...runs.map( ( found ) => found.length + 1 ) ) );
			open = fence;
			close = fence;
			padding = content.startsWith( '`' ) || content.endsWith( '`' ) ? ' ' : '';
		}
		const markup = open + padding + content + padding + close;
		const at = start + open.length + padding.length;

		return {
			value: value.slice( 0, start ) + markup + value.slice( end ),
			start: at,
			end: at + content.length,
		};
	}

	// The delimiters that bracket the caret on its own line, when they are this
	// mark's and nothing else sits between them and the caret.
	function enclosing( value, caret, before, after ) {
		const lineStart = value.lastIndexOf( '\n', caret - 1 ) + 1;
		let lineEnd = value.indexOf( '\n', caret );
		if ( lineEnd < 0 ) {
			lineEnd = value.length;
		}
		const openStart = value.lastIndexOf( before, caret - before.length );
		const closeStart = value.indexOf( after, caret );
		if ( openStart < lineStart || openStart + before.length > caret ) {
			return null;
		}
		if ( closeStart < caret || closeStart < 0 || closeStart + after.length > lineEnd ) {
			return null;
		}
		if ( ! pairs( value, openStart, closeStart, before, after ) ) {
			return null;
		}

		return { openStart: openStart, openEnd: openStart + before.length, closeStart: closeStart };
	}

	function toggle( value, start, end, before, after, placeholder ) {
		if ( start === end ) {
			const found = enclosing( value, start, before, after );
			if ( found ) {
				return remove( value, found.openStart, found.openEnd, found.closeStart, found.closeStart + after.length, start );
			}

			return apply( value, start, end, before, after, placeholder || '' );
		}

		const code = isCode( before, after );
		const lead = code ? run( value, start, 1, '`' ) : before.length;
		const trail = code ? run( value, end - 1, -1, '`' ) : after.length;
		// Delimiters inside the selection: the user selected the markers too.
		if (
			lead > 0
			&& ( ! code || lead === trail )
			&& start + lead <= end - trail
			&& ( code
				? run( value, start - 1, -1, '`' ) === 0 && run( value, end, 1, '`' ) === 0
				: pairs( value, start, end - after.length, before, after ) )
		) {
			return remove( value, start, start + lead, end - trail, end );
		}

		// Delimiters just outside the selection: the user selected the text.
		const left = code ? run( value, start - 1, -1, '`' ) : before.length;
		const right = code ? run( value, end, 1, '`' ) : after.length;
		if (
			left > 0
			&& start - left >= 0
			&& ( ! code || left === right )
			&& ( code
				? value.slice( start - left, start ) === '`'.repeat( left )
					&& value.slice( end, end + right ) === '`'.repeat( right )
					&& run( value, start - left - 1, -1, '`' ) === 0
					&& run( value, end + right, 1, '`' ) === 0
				: pairs( value, start - before.length, end, before, after ) )
		) {
			return remove( value, start - left, start, end, end + right );
		}

		return apply( value, start, end, before, after, value.slice( start, end ) );
	}

	window.wpCarveInlineToggle = { toggle: toggle };
}() );
