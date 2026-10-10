/* Enter in a list item, shared by the block source editor and the classic
   source editor: `edit( text, cursor )` returns the edit that continues the
   list, or null to leave Enter alone.

   A marker with no content after it is paragraph text in Carve, so Enter on a
   marker-only line removes the marker instead of continuing. `+` is the
   continuation marker, not a bullet, and is never continued. */
( function () {
	'use strict';

	const ITEM = /^( *)(?:([-*])|(\d+|[a-zA-Z]+)?([.)]))(\{[^{}\n]*\})?( +)/;
	// Lines that open or are a block of their own, never paragraph text.
	const BLOCK = /^ *(?:#|`{3}|~{3}|:|\{|%|\||(?:-{3,}|\*{3,}|_{3,}) *$)/;
	const TASK = /^\[[ xX\-_>?]\](?: +|$)/;
	const ROMAN = [
		[ 1000, 'm' ], [ 900, 'cm' ], [ 500, 'd' ], [ 400, 'cd' ], [ 100, 'c' ], [ 90, 'xc' ],
		[ 50, 'l' ], [ 40, 'xl' ], [ 10, 'x' ], [ 9, 'ix' ], [ 5, 'v' ], [ 4, 'iv' ], [ 1, 'i' ],
	];

	function toRoman( value ) {
		let out = '';
		for ( const [ amount, digits ] of ROMAN ) {
			while ( value >= amount ) {
				out += digits;
				value -= amount;
			}
		}

		return out;
	}

	// Only the canonical spelling counts, so `iiii.` is not read as 4.
	function fromRoman( spelling ) {
		const lower = spelling.toLowerCase();
		if ( ! /^[ivxlcdm]+$/.test( lower ) ) {
			return 0;
		}
		let value = 0;
		for ( let at = 0; at < lower.length; at++ ) {
			const digit = { i: 1, v: 5, x: 10, l: 50, c: 100, d: 500, m: 1000 }[ lower[ at ] ];
			const next = { i: 1, v: 5, x: 10, l: 50, c: 100, d: 500, m: 1000 }[ lower[ at + 1 ] ] || 0;
			value += digit < next ? -digit : digit;
		}

		return value > 0 && toRoman( value ) === lower ? value : 0;
	}

	function matchItem( line ) {
		const match = ITEM.exec( line );
		if ( ! match ) {
			return null;
		}
		const value = match[ 3 ];
		// A bare dot is decimal; a bare `)` is prose.
		if ( match[ 4 ] === ')' && value === undefined ) {
			return null;
		}
		if ( value && value.length > 1 && /^[a-zA-Z]+$/.test( value ) ) {
			const sameCase = value === value.toLowerCase() || value === value.toUpperCase();
			if ( ! sameCase || ! fromRoman( value ) ) {
				return null;
			}
		}
		// Only a bullet item takes a task box; on an ordered item it is content.
		const task = match[ 2 ] ? TASK.exec( line.slice( match[ 0 ].length ) ) : null;

		return {
			indent: match[ 1 ],
			bullet: match[ 2 ],
			value: value,
			delimiter: match[ 4 ],
			separator: match[ 6 ],
			task: !! task,
			length: match[ 0 ].length + ( task ? task[ 0 ].length : 0 ),
		};
	}

	function consecutive( first, second, roman ) {
		if ( ! second || ! /^[a-zA-Z]+$/.test( second ) ) {
			return false;
		}
		if ( roman ) {
			return fromRoman( second ) === fromRoman( first ) + 1;
		}

		return second.length === 1 && second.charCodeAt( 0 ) === first.charCodeAt( 0 ) + 1;
	}

	// PART 9 §11 N2/N3: the list's first item fixes the dialect, and a single
	// roman letter there is settled by the item after it.
	function isRoman( items ) {
		const first = items[ 0 ];
		if ( first.length > 1 ) {
			return true;
		}
		if ( ! /^[ivxlcdm]$/i.test( first ) ) {
			return false;
		}
		const second = items[ 1 ];
		if ( consecutive( first, second, true ) ) {
			return true;
		}
		if ( consecutive( first, second, false ) ) {
			return false;
		}

		return /^i$/i.test( first );
	}

	// The nearest sibling letter marker of this list in direction `step`, or
	// null. Deeper lines are nested content; a shallower one ends the list.
	function sibling( lines, index, item, step ) {
		for ( let at = index + step; at >= 0 && at < lines.length; at += step ) {
			const line = lines[ at ];
			if ( ! line.trim() ) {
				continue;
			}
			const indent = /^ */.exec( line )[ 0 ].length;
			if ( indent > item.indent.length ) {
				continue;
			}
			const other = indent === item.indent.length ? matchItem( line ) : null;
			// Text right under a non-blank line is a lazy continuation of an item.
			if ( ! other && at > 0 && lines[ at - 1 ].trim() && ! BLOCK.test( line ) ) {
				continue;
			}
			const letters = other && other.value && /^[a-zA-Z]+$/.test( other.value );
			const sameCase = letters && ( other.value === other.value.toUpperCase() ) === ( item.value === item.value.toUpperCase() );
			if ( ! sameCase || other.delimiter !== item.delimiter ) {
				return null;
			}

			return { index: at, value: other.value };
		}

		return null;
	}

	// The list's first two letter markers, which settle its dialect.
	function leadingPair( lines, index, item ) {
		let first = { index: index, value: item.value };
		for ( let before = sibling( lines, index, item, -1 ); before; before = sibling( lines, before.index, item, -1 ) ) {
			first = before;
		}
		const second = sibling( lines, first.index, item, 1 );

		return [ first.value, second ? second.value : undefined ];
	}

	function nextValue( lines, index, item ) {
		const value = item.value;
		if ( value === undefined ) {
			return '';
		}
		if ( /^\d+$/.test( value ) ) {
			const next = String( Number( value ) + 1 );

			return value.startsWith( '0' ) ? next.padStart( value.length, '0' ) : next;
		}
		const upper = value === value.toUpperCase();
		if ( isRoman( leadingPair( lines, index, item ) ) ) {
			const number = fromRoman( value );
			if ( ! number ) {
				return null;
			}
			const next = toRoman( number + 1 );

			return upper ? next.toUpperCase() : next;
		}
		if ( value.length !== 1 || /z/i.test( value ) ) {
			return null;
		}

		return String.fromCharCode( value.charCodeAt( 0 ) + 1 );
	}

	// A list marker inside fenced code or a `%%%` comment is not a list. A fence left open
	// inside a list item ends with that item, at the first line indented less.
	function inFence( lines, index ) {
		let open = null;
		for ( let at = 0; at <= index; at++ ) {
			const line = lines[ at ];
			if ( open && line.trim() && /^ */.exec( line )[ 0 ].length < open.indent ) {
				open = null;
			}
			// A fence may open on an item's marker line, at the item's content column.
			const item = matchItem( line );
			const body = item ? ' '.repeat( item.length ) + line.slice( item.length ) : line;
			const fence = at < index ? /^( *)(`{3,}|~{3,}|%{3,})(.*)$/.exec( open ? line : body ) : null;
			if ( ! fence ) {
				continue;
			}
			if ( ! open ) {
				open = { run: fence[ 2 ], indent: fence[ 1 ].length };
			} else if ( fence[ 2 ][ 0 ] === open.run[ 0 ] && fence[ 2 ].length >= open.run.length && ! fence[ 3 ].trim() ) {
				open = null;
			}
		}

		return open !== null;
	}

	// No list interrupts a paragraph: in a run of lines opened by paragraph text
	// at this item's indent or shallower, a marker line is more of that paragraph.
	function interruptsParagraph( lines, index, item ) {
		let top = null;
		for ( let at = index - 1; at >= 0 && lines[ at ].trim(); at-- ) {
			top = lines[ at ];
			if ( BLOCK.test( top ) ) {
				break;
			}
		}
		if ( top === null || /^ */.exec( top )[ 0 ].length > item.indent.length ) {
			return false;
		}

		return ! matchItem( top ) && ! BLOCK.test( top );
	}

	function edit( text, cursor ) {
		const lineStart = text.lastIndexOf( '\n', cursor - 1 ) + 1;
		let lineEnd = text.indexOf( '\n', cursor );
		if ( lineEnd < 0 ) {
			lineEnd = text.length;
		}
		const line = text.slice( lineStart, lineEnd );
		const item = matchItem( line );
		if ( ! item || cursor < lineStart + item.length ) {
			return null;
		}
		const lines = text.split( '\n' );
		const index = text.slice( 0, lineStart ).split( '\n' ).length - 1;
		if ( inFence( lines, index ) || interruptsParagraph( lines, index, item ) ) {
			return null;
		}
		if ( ! line.slice( item.length ).trim() ) {
			return { from: lineStart, to: lineEnd, text: '', cursor: lineStart };
		}
		let marker = item.bullet;
		if ( marker === undefined ) {
			const value = nextValue( lines, index, item );
			if ( value === null ) {
				return null;
			}
			marker = value + item.delimiter;
		}
		const prefix = '\n' + item.indent + marker + item.separator + ( item.task ? '[ ] ' : '' );

		return { from: cursor, to: cursor, text: prefix, cursor: cursor + prefix.length };
	}

	// Shift+Enter and every other chord keep the editor's own newline, and an
	// Enter that commits an IME composition belongs to the IME.
	function isPlainEnter( event ) {
		return event.key === 'Enter'
			&& ! event.shiftKey && ! event.altKey && ! event.ctrlKey && ! event.metaKey
			&& ! event.isComposing && event.keyCode !== 229;
	}

	window.wpCarveListContinuation = { edit: edit, isPlainEnter: isPlainEnter };
}() );
