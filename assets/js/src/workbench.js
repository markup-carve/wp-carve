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
	for ( const match of source.matchAll( /\[(?:[^\]\n]*?)(?:-?@)([A-Za-z0-9_][\w:.#$%&+?<>~\/-]*)[^\]\n]*\](?!:)/g ) ) {
		if ( ! keys.includes( match[ 1 ] ) ) keys.push( match[ 1 ] );
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
		ast = engine.parse( source, { extensions: [ engine.citations() ] } );
		diagnostics.push( ...engine.lintCarve( source ).map( ( item ) => ( { ...item, severity: 'warning' } ) ) );
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
		line: source.slice( 0, source.indexOf( '@' + key ) ).split( '\n' ).length,
		column: 1,
		rule: 'unresolved-citation',
		message: 'Citation “' + key + '” has no in-document or CSL-JSON entry.',
		severity: 'warning',
	} ) );
	return { ast, outline: ast ? outlineFromAst( ast ) : [], diagnostics, citations: citationKeys( source ), bibliography };
}

export function semanticChanges( before, after, engine ) {
	if ( before === after ) return [];
	try {
		const extensions = [ engine.citations() ];
		return engine.diffAst( engine.toAstJson( engine.parse( before, { extensions } ) ), engine.toAstJson( engine.parse( after, { extensions } ) ) );
	} catch ( error ) {
		return [ { kind: 'changed', type: 'document', path: '', detail: error.message } ];
	}
}
