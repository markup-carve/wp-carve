/**
 * Carve serializer for Tiptap / ProseMirror.
 *
 * Converts a Tiptap JSON document (editor.getJSON()) to Carve markup. This is
 * the foundation: it covers the core block and inline constructs. Carve-specific
 * containers register their own serialization via the node's `attrs.carveSrc`
 * round-trip source where available.
 *
 * Carve inline delimiters differ from Markdown/Djot:
 *   /italic/  *bold*  _underline_  ~strike~  ^sup^  ,,sub,,  ==highlight==  `code`
 */

export function serializeToCarve( doc ) {
	let output = '';

	function serializeNode( node, depth = 0 ) {
		if ( ! node ) {
			return;
		}

		switch ( node.type ) {
			case 'doc':
				( node.content || [] ).forEach( ( child, i ) => {
					serializeNode( child, depth );
					if ( i < ( node.content || [] ).length - 1 ) {
						output += '\n';
					}
				} );
				break;

			case 'paragraph':
				output += serializeInline( node.content ) + '\n';
				break;

			case 'heading':
				output +=
					'#'.repeat( node.attrs?.level || 1 ) +
					' ' +
					serializeInline( node.content ) +
					'\n';
				break;

			case 'bulletList':
			case 'orderedList':
			case 'taskList': {
				let num = node.attrs?.start || 1;
				( node.content || [] ).forEach( ( item ) => {
					const indent = '  '.repeat( depth );
					if ( node.type === 'bulletList' ) {
						output += indent + '- ';
					} else if ( node.type === 'orderedList' ) {
						output += indent + num + '. ';
						num++;
					} else {
						const checked = item.attrs?.checked ? 'x' : ' ';
						output += indent + '- [' + checked + '] ';
					}
					serializeListItem( item, depth );
				} );
				break;
			}

			case 'blockquote':
				( node.content || [] ).forEach( ( child, i ) => {
					serializeNodeToString( child )
						.replace( /\n$/, '' )
						.split( '\n' )
						.forEach( ( line ) => {
							output += '> ' + line + '\n';
						} );
					if ( i < ( node.content || [] ).length - 1 ) {
						output += '>\n';
					}
				} );
				break;

			case 'codeBlock': {
				const lang = String( node.attrs?.language || '' );
				const code = ( node.content || [] ).map( ( c ) => c.text || '' ).join( '' );
				const fence = findSafeFence( code );
				output += fence + ( lang ? ' ' + lang : '' ) + '\n' + code + '\n' + fence + '\n';
				break;
			}

			case 'horizontalRule':
				output += '---\n';
				break;

			case 'image':
				output += '![' + ( node.attrs?.alt || '' ) + '](' + ( node.attrs?.src || '' ) + ')\n';
				break;

			case 'carveDiv': {
				const cls = node.attrs?.class || '';
				output += ':::' + ( cls ? ' ' + cls : '' ) + '\n';
				( node.content || [] ).forEach( ( child, i ) => {
					serializeNode( child, depth );
					if ( i < ( node.content || [] ).length - 1 ) {
						output += '\n';
					}
				} );
				output += ':::\n';
				break;
			}

			default:
				// Unknown node: fall back to its round-trip source or plain text.
				if ( node.attrs?.carveSrc ) {
					output += ensureTrailingNewline( node.attrs.carveSrc );
				} else if ( node.content ) {
					output += serializeInline( node.content ) + '\n';
				}
		}
	}

	function serializeListItem( item, depth ) {
		const children = item.content || [];
		children.forEach( ( child, i ) => {
			if ( child.type === 'paragraph' ) {
				// First paragraph sits on the marker line; later blocks indent.
				if ( i === 0 ) {
					output += serializeInline( child.content ) + '\n';
				} else {
					serializeNode( child, depth + 1 );
				}
			} else {
				serializeNode( child, depth + 1 );
			}
		} );
	}

	function serializeNodeToString( node ) {
		const saved = output;
		output = '';
		serializeNode( node, 0 );
		const result = output;
		output = saved;
		return result;
	}

	function serializeInline( content ) {
		if ( ! content ) {
			return '';
		}
		let result = '';

		content.forEach( ( node ) => {
			if ( node.type === 'text' ) {
				let t = node.text || '';
				const marks = node.marks || [];
				const has = ( type ) => marks.some( ( m ) => m.type === type );
				const link = marks.find( ( m ) => m.type === 'link' );

				// Innermost to outermost.
				if ( has( 'code' ) ) t = '`' + t + '`';
				if ( has( 'subscript' ) ) t = ',,' + t + ',,';
				if ( has( 'superscript' ) ) t = '^' + t + '^';
				if ( has( 'strike' ) ) t = '~' + t + '~';
				if ( has( 'highlight' ) ) t = '==' + t + '==';
				if ( has( 'underline' ) ) t = '_' + t + '_';
				if ( has( 'italic' ) ) t = '/' + t + '/';
				if ( has( 'bold' ) ) t = '*' + t + '*';
				if ( link && link.attrs?.href ) {
					t = '[' + t + '](' + link.attrs.href + ')';
				}

				result += t;
			} else if ( node.type === 'hardBreak' ) {
				result += '\\\n';
			} else if ( node.type === 'image' ) {
				result += '![' + ( node.attrs?.alt || '' ) + '](' + ( node.attrs?.src || '' ) + ')';
			}
		} );

		return result;
	}

	function ensureTrailingNewline( value ) {
		return value.endsWith( '\n' ) ? value : value + '\n';
	}

	function findSafeFence( code ) {
		// Use a backtick fence one longer than the longest run inside the code.
		let longest = 0;
		const matches = code.match( /`+/g ) || [];
		matches.forEach( ( m ) => {
			longest = Math.max( longest, m.length );
		} );
		return '`'.repeat( Math.max( 3, longest + 1 ) );
	}

	serializeNode( doc, 0 );
	return output.replace( /\n{3,}/g, '\n\n' ).trim() + '\n';
}

export default serializeToCarve;
