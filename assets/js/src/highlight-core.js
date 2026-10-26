import Prism from 'prismjs';
import '@markup-carve/carve-grammars/prism/carve.js';

const grammar = Prism.languages.carve;

export function highlightHtml( source ) {
	if ( source.length > 100000 ) {
		return Prism.util.encode( source );
	}
	return Prism.highlight( source, grammar, 'carve' );
}

export function highlightRanges( source ) {
	const ranges = [];
	let offset = 0;

	function visit( value, scopes ) {
		if ( typeof value === 'string' ) {
			if ( value && scopes.length ) {
				ranges.push( { from: offset, to: offset + value.length, scopes } );
			}
			offset += value.length;
			return;
		}
		if ( Array.isArray( value ) ) {
			value.forEach( ( part ) => visit( part, scopes ) );
			return;
		}
		const alias = Array.isArray( value.alias ) ? value.alias : value.alias ? [ value.alias ] : [];
		visit( value.content, [ ...scopes, value.type, ...alias ] );
	}

	visit( Prism.tokenize( source, grammar ), [] );
	return ranges;
}
