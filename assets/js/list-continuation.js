/* Enter and Tab in a list item, shared by the block source editor and the
   classic source editor: `edit( text, cursor )` returns the edit that continues
   the list, or null to leave Enter alone. `indent( text, start, end, outdent )`
   moves the selected items one nesting level, or returns null to leave Tab alone.

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

	function markerOf( item ) {
		return item.bullet !== undefined ? item.bullet : ( item.value || '' ) + item.delimiter;
	}

	// Attributes and a task box do not count: a child nests at marker plus separator.
	function contentColumn( item ) {
		return item.indent.length + markerOf( item ).length + item.separator.length;
	}

	function leading( line ) {
		return /^ */.exec( line )[ 0 ].length;
	}

	function isLazy( lines, at ) {
		return at > 0 && !! lines[ at - 1 ].trim() && ! BLOCK.test( lines[ at ] );
	}

	function listItem( lines, index ) {
		const item = matchItem( lines[ index ] );
		if ( ! item || inFence( lines, index ) || interruptsParagraph( lines, index, item ) ) {
			return null;
		}

		return item;
	}

	// The nearest item above at this item's column (`sibling`) or shallower
	// (`parent`), skipping nested content and lazy lines.
	function itemAbove( lines, index, item, parent ) {
		const column = item.indent.length;
		for ( let at = index - 1; at >= 0; at-- ) {
			const line = lines[ at ];
			if ( ! line.trim() ) {
				continue;
			}
			const indent = leading( line );
			if ( indent > column || ( parent && indent === column ) ) {
				continue;
			}
			const other = listItem( lines, at );
			if ( other ) {
				return parent || indent === column ? { index: at, item: other } : null;
			}
			if ( ! isLazy( lines, at ) ) {
				return null;
			}
		}

		return null;
	}

	// The last line of the item: its deeper lines and lazy lines. After a blank
	// line only a block at the content column still belongs to it.
	function itemEnd( lines, index, item ) {
		let end = index;
		for ( let at = index + 1; at < lines.length; at++ ) {
			const line = lines[ at ];
			if ( ! line.trim() ) {
				continue;
			}
			const floor = at - 1 === end ? item.indent.length + 1 : contentColumn( item );
			if ( leading( line ) >= floor ) {
				end = at;
			} else if ( at - 1 === end && ! listItem( lines, at ) && isLazy( lines, at ) ) {
				end = at;
			} else {
				break;
			}
		}

		return end;
	}

	// The item a new child at `column` would join: the last one at that column
	// between the parent and `index`.
	function lastChild( lines, parentIndex, index, column ) {
		for ( let at = index - 1; at > parentIndex; at-- ) {
			const line = lines[ at ];
			if ( ! line.trim() || leading( line ) > column ) {
				continue;
			}
			const other = leading( line ) === column ? listItem( lines, at ) : null;

			return other ? { index: at, item: other } : null;
		}

		return null;
	}

	function firstValue( lines, index, item ) {
		const value = item.value;
		if ( /^\d+$/.test( value ) ) {
			return '1';
		}
		const first = isRoman( leadingPair( lines, index, item ) ) ? 'i' : 'a';

		return value === value.toUpperCase() ? first.toUpperCase() : first;
	}

	function ordered( item ) {
		return item.bullet === undefined && item.value !== undefined;
	}

	// Moves the item on line `index` one level and records each changed line's
	// prefix in `changes`. False when the item cannot move.
	function moveItem( lines, index, item, outdent, changes ) {
		const anchor = itemAbove( lines, index, item, outdent );
		if ( ! anchor ) {
			return false;
		}
		const column = outdent ? anchor.item.indent.length : contentColumn( anchor.item );
		let marker = markerOf( item );
		if ( outdent && ordered( item ) && ordered( anchor.item ) ) {
			const value = nextValue( lines, anchor.index, anchor.item );
			marker = value === null ? marker : value + anchor.item.delimiter;
		} else if ( ! outdent && ordered( item ) ) {
			const joined = lastChild( lines, anchor.index, index, column );
			const value = joined && ordered( joined.item ) ? nextValue( lines, joined.index, joined.item ) : null;
			marker = value === null ? firstValue( lines, index, item ) + item.delimiter : value + joined.item.delimiter;
		}
		const end = itemEnd( lines, index, item );
		const oldPrefix = item.indent.length + markerOf( item ).length;
		const newPrefix = column + marker.length;
		const delta = newPrefix - oldPrefix;
		const saved = lines.slice( index, end + 1 );
		const local = [ { index: index, cut: oldPrefix, delta: delta } ];
		lines[ index ] = ' '.repeat( column ) + marker + lines[ index ].slice( oldPrefix );
		for ( let at = index + 1; at <= end; at++ ) {
			const indent = leading( lines[ at ] );
			if ( ! lines[ at ].trim() || indent <= item.indent.length ) {
				continue;
			}
			// A lazy line short of the item's content column must stay short of
			// the new parent's too, or it turns into a block of that parent.
			const lazy = ! outdent && indent < contentColumn( item );
			const shift = lazy ? Math.min( 0, column - 1 - indent ) : Math.max( delta, -indent );
			if ( ! shift ) {
				continue;
			}
			lines[ at ] = shift > 0 ? ' '.repeat( shift ) + lines[ at ] : lines[ at ].slice( -shift );
			local.push( { index: at, cut: Math.max( 0, -shift ), delta: shift } );
		}
		// The move must land where it aimed: a marker that now reads as paragraph
		// text, or nests under another item, is put back.
		const moved = listItem( lines, index );
		const parent = moved ? itemAbove( lines, index, moved, true ) : null;
		const expected = outdent ? itemAbove( lines, anchor.index, anchor.item, true ) : anchor;
		if ( ! moved || ( parent ? parent.index : -1 ) !== ( expected ? expected.index : -1 ) ) {
			lines.splice( index, saved.length, ...saved );
			return false;
		}
		changes.push( ...local );

		return true;
	}

	// Shift+Tab's plain fallback: up to two leading spaces.
	function shiftPlain( lines, at, outdent, changes ) {
		if ( outdent ) {
			const cut = Math.min( 2, leading( lines[ at ] ) );
			lines[ at ] = lines[ at ].slice( cut );
			changes.push( { index: at, cut: cut, delta: -cut } );
		} else {
			lines[ at ] = '  ' + lines[ at ];
			changes.push( { index: at, cut: 0, delta: 2 } );
		}
	}

	function indent( text, start, end, outdent ) {
		const lines = text.split( '\n' );
		const lineOf = ( offset ) => text.slice( 0, offset ).split( '\n' ).length - 1;
		const first = lineOf( start );
		const last = end > start && text[ end - 1 ] === '\n' ? lineOf( end ) - 1 : lineOf( end );
		const changes = [];
		const plain = [];
		let moved = false;
		let covered = -1;
		for ( let at = first; at <= last; at++ ) {
			if ( at <= covered ) {
				continue;
			}
			const item = listItem( lines, at );
			const itemLast = item ? itemEnd( lines, at, item ) : at;
			if ( item && moveItem( lines, at, item, outdent, changes ) ) {
				moved = true;
				covered = itemLast;
			} else if ( ! item && lines[ at ].trim() ) {
				// An item that cannot move stays put, so its children keep their column.
				plain.push( at );
			}
		}
		if ( ! moved ) {
			return null;
		}
		plain.forEach( ( at ) => shiftPlain( lines, at, outdent, changes ) );

		const original = text.split( '\n' );
		const offsetIn = ( rows, row, column ) => rows.slice( 0, row ).reduce( ( sum, line ) => sum + line.length + 1, 0 ) + column;
		const map = ( offset ) => {
			const row = lineOf( offset );
			const column = offset - offsetIn( original, row, 0 );
			const change = changes.find( ( entry ) => entry.index === row );
			if ( ! change ) {
				return offsetIn( lines, row, column );
			}
			const mapped = column >= change.cut ? column + change.delta : Math.min( column, change.cut + change.delta );

			return offsetIn( lines, row, Math.max( 0, mapped ) );
		};
		const rows = changes.map( ( entry ) => entry.index );
		const top = Math.min( ...rows );
		const bottom = Math.max( ...rows );

		return {
			from: offsetIn( original, top, 0 ),
			to: offsetIn( original, bottom, original[ bottom ].length ),
			text: lines.slice( top, bottom + 1 ).join( '\n' ),
			start: map( start ),
			end: map( end ),
		};
	}

	// Shift+Enter and every other chord keep the editor's own newline, and an
	// Enter that commits an IME composition belongs to the IME.
	function isPlainEnter( event ) {
		return event.key === 'Enter'
			&& ! event.shiftKey && ! event.altKey && ! event.ctrlKey && ! event.metaKey
			&& ! event.isComposing && event.keyCode !== 229;
	}

	window.wpCarveListContinuation = { edit: edit, indent: indent, isPlainEnter: isPlainEnter };
}() );
