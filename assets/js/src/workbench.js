function textOf( node ) {
	if ( ! node || typeof node !== 'object' ) return '';
	if ( typeof node.value === 'string' ) return node.value;
	return [ 'children', 'inline', 'content', 'caption', 'title' ]
		.flatMap( ( key ) => Array.isArray( node[ key ] ) ? node[ key ] : [] )
		.map( textOf ).join( '' );
}

function walk( node, visit ) {
	if ( ! node || typeof node !== 'object' ) return;
	visit( node );
	Object.values( node ).forEach( ( value ) => {
		if ( Array.isArray( value ) ) value.forEach( ( child ) => walk( child, visit ) );
		else if ( value && typeof value === 'object' && value.type ) walk( value, visit );
	} );
}

export function outlineFromAst( ast ) {
	const items = [];
	walk( ast, ( node ) => {
		if ( node.type === 'heading' ) {
			items.push( { level: node.level, text: textOf( node ), line: node.pos?.startLine || 1 } );
		}
	} );
	return items;
}

function citationKeys( source ) {
	const keys = [];
	// `:cite[key]` is the 0.2 spelling (U-411); `[@key]` the 0.1 one.
	const patterns = [ /:cite\[\s*([A-Za-z0-9_][\w:.#$%&+?<>~\/-]*)[^\]\n]*\]/g, /\[(?:[^\]\n]*?)(?:-?@)([A-Za-z0-9_][\w:.#$%&+?<>~\/-]*)[^\]\n]*\](?!:)/g ];
	for ( const pattern of patterns ) {
		for ( const match of source.matchAll( pattern ) ) {
			if ( ! keys.includes( match[ 1 ] ) ) keys.push( match[ 1 ] );
		}
	}
	return keys;
}

function definitionKeys( source ) {
	return [ ...source.matchAll( /^\[@([A-Za-z0-9_][\w:.#$%&+?<>~\/-]*)\]:/gm ) ].map( ( m ) => m[ 1 ] );
}

export function bibliographyEntries( json ) {
	if ( ! String( json || '' ).trim() ) return [];
	const parsed = JSON.parse( json );
	if ( ! Array.isArray( parsed ) ) throw new Error( 'CSL-JSON must be an array of entries.' );
	const ids = new Set();
	return parsed.map( ( entry ) => {
		if ( ! entry || typeof entry !== 'object' || typeof entry.id !== 'string' || ! entry.id.trim() ) {
			throw new Error( 'Every CSL-JSON entry needs a non-empty string id.' );
		}
		if ( ids.has( entry.id ) ) throw new Error( 'Duplicate CSL-JSON id: ' + entry.id );
		ids.add( entry.id );
		return entry;
	} );
}

export function analyzeDocument( source, engine, bibliographyJson = '' ) {
	let ast;
	let bibliography = [];
	const diagnostics = [];
	try {
		ast = engine.parse( source );
		diagnostics.push( ...engine.lintCarve( source ).map( ( item ) => ( { ...item, severity: item.severity ?? 'warning' } ) ) );
	} catch ( error ) {
		diagnostics.push( { line: 1, column: 1, rule: 'parse-error', message: error.message, severity: 'error' } );
	}
	try {
		bibliography = bibliographyEntries( bibliographyJson );
	} catch ( error ) {
		diagnostics.push( { line: 1, column: 1, rule: 'bibliography-json', message: error.message, severity: 'error' } );
	}
	const known = new Set( [ ...definitionKeys( source ), ...bibliography.map( ( entry ) => entry.id ) ] );
	citationKeys( source ).filter( ( key ) => ! known.has( key ) ).forEach( ( key ) => diagnostics.push( {
		line: source.slice( 0, Math.max( 0, source.indexOf( key ) ) ).split( '\n' ).length,
		column: 1,
		rule: 'unresolved-citation',
		message: 'Citation “' + key + '” has no in-document or CSL-JSON entry.',
		severity: 'warning',
	} ) );
	return { ast, outline: ast ? outlineFromAst( ast ) : [], diagnostics, citations: citationKeys( source ), bibliography };
}

function nodeText( node ) {
	return textOf( node ).slice( 0, 80 );
}

/**
 * Structural changes between two encoded trees: top-level blocks compared by
 * type and content, positions ignored. carve-js 0.2 has no AST diff
 * (`diffAst` is gone), so the workbench keeps this small host diff.
 */
export function diffTrees( before, after ) {
	const strip = ( node ) => JSON.stringify( node, ( key, value ) => ( key === 'pos' ? undefined : value ) );
	const a = before?.children ?? [];
	const b = after?.children ?? [];
	const changes = [];
	const length = Math.max( a.length, b.length );
	for ( let i = 0; i < length; i++ ) {
		const path = '/children/' + i;
		if ( ! a[ i ] ) {
			changes.push( { kind: 'added', type: b[ i ].type, path, detail: nodeText( b[ i ] ) } );
		} else if ( ! b[ i ] ) {
			changes.push( { kind: 'removed', type: a[ i ].type, path, detail: nodeText( a[ i ] ) } );
		} else if ( strip( a[ i ] ) !== strip( b[ i ] ) ) {
			changes.push( { kind: 'changed', type: b[ i ].type, path, detail: nodeText( b[ i ] ) } );
		}
	}
	return changes;
}

export function semanticChanges( before, after, engine ) {
	if ( before === after ) return [];
	try {
		return diffTrees( engine.toAstJson( engine.parse( before ) ), engine.toAstJson( engine.parse( after ) ) );
	} catch ( error ) {
		return [ { kind: 'changed', type: 'document', path: '', detail: error.message } ];
	}
}
