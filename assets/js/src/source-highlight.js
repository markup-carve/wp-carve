import { highlightHtml, highlightRanges } from './highlight-core.js';

function markCodeMirror( cm ) {
	let marks = [];
	let timer;
	let cachedSource = null;
	let cachedRanges = [];
	const update = () => {
		clearTimeout( timer );
		timer = setTimeout( () => {
			const source = cm.getValue();
			if ( source !== cachedSource ) {
				cachedSource = source;
				cachedRanges = source.length <= 100000 ? highlightRanges( source ) : [];
			}
			let next = cachedRanges;
			if ( next.length > 5000 && typeof cm.getViewport === 'function' ) {
				const viewport = cm.getViewport();
				const from = cm.indexFromPos( { line: Math.max( 0, viewport.from - 100 ), ch: 0 } );
				const to = cm.indexFromPos( { line: viewport.to + 100, ch: 0 } );
				next = next.filter( ( range ) => range.to > from && range.from < to );
			}
			cm.operation( () => {
				marks.forEach( ( mark ) => mark.clear() );
				marks = next.slice( 0, 5000 ).map( ( range ) => cm.markText(
					cm.posFromIndex( range.from ),
					cm.posFromIndex( range.to ),
					{ className: range.scopes.map( ( scope ) => 'wpcarve-token-' + scope ).join( ' ' ) },
				) );
			} );
		}, 120 );
	};
	cm.on( 'change', update );
	cm.on( 'viewportChange', update );
	update();
	return () => {
		clearTimeout( timer );
		cm.off( 'change', update );
		cm.off( 'viewportChange', update );
		marks.forEach( ( mark ) => mark.clear() );
	};
}

function attachTextarea( textarea ) {
	const computed = getComputedStyle( textarea );
	const names = [
		'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'tabSize', 'boxSizing',
		'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
		'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderRadius',
	];
	const style = Object.fromEntries( [ ...names, 'backgroundColor', 'color', 'margin' ].map( ( name ) => [ name, computed[ name ] ] ) );
	const parentWidth = textarea.parentElement.getBoundingClientRect().width;
	const inputWidth = textarea.getBoundingClientRect().width;
	let ancestor = textarea.parentElement;
	while ( style.backgroundColor === 'rgba(0, 0, 0, 0)' && ancestor ) {
		style.backgroundColor = getComputedStyle( ancestor ).backgroundColor;
		ancestor = ancestor.parentElement;
	}
	if ( style.backgroundColor === 'rgba(0, 0, 0, 0)' ) {
		style.backgroundColor = '#fff';
	}
	const wrap = document.createElement( 'div' );
	wrap.className = 'wpcarve-highlight-editor has-highlight wpcarve-comment-highlight';
	wrap.style.margin = style.margin;
	if ( parentWidth > 0 && inputWidth > 0 ) {
		wrap.style.width = Math.min( 100, inputWidth / parentWidth * 100 ) + '%';
	}
	const layer = document.createElement( 'pre' );
	layer.className = 'wpcarve-highlight-layer';
	layer.setAttribute( 'aria-hidden', 'true' );
	textarea.parentNode.insertBefore( wrap, textarea );
	wrap.append( layer, textarea );
	textarea.style.width = '100%';
	textarea.style.boxSizing = 'border-box';
	textarea.style.margin = '0';
	const ink = style.color;
	for ( const name of names ) {
		layer.style[ name ] = style[ name ];
	}
	layer.style.background = style.backgroundColor;
	layer.style.color = ink;
	layer.style.boxSizing = 'border-box';
	if ( window.ResizeObserver ) {
		new window.ResizeObserver( () => {
			const resized = getComputedStyle( textarea );
			for ( const name of names ) {
				if ( name !== 'boxSizing' ) {
					layer.style[ name ] = resized[ name ];
				}
			}
		} ).observe( wrap );
	}
	const rgb = style.backgroundColor.match( /\d+/g );
	if ( rgb && rgb.length >= 3 && ( 0.2126 * rgb[ 0 ] + 0.7152 * rgb[ 1 ] + 0.0722 * rgb[ 2 ] ) < 120 ) {
		wrap.classList.add( 'is-dark' );
	}
	textarea.style.color = 'transparent';
	textarea.style.webkitTextFillColor = 'transparent';
	textarea.style.caretColor = ink;
	const update = () => { layer.innerHTML = highlightHtml( textarea.value ) + '\n'; };
	textarea.addEventListener( 'input', update );
	textarea.addEventListener( 'scroll', () => {
		layer.scrollTop = textarea.scrollTop;
		layer.scrollLeft = textarea.scrollLeft;
	} );
	update();
	return wrap;
}

window.wpCarveHighlight = { highlightHtml, highlightRanges, markCodeMirror, attachTextarea };
