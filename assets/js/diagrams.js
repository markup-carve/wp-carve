/* Carve diagram renderers: client-side init for the built-in fenced-render
 * types. Each block is rendered only if its library is present (the plugin
 * enqueues a library only when its type is enabled and used on the page). */
( function () {
	'use strict';

	function each( sel, fn ) {
		document.querySelectorAll( '.wp-carve ' + sel ).forEach( function ( el, i ) {
			if ( el.dataset.carveRendered ) {
				return;
			}
			el.dataset.carveRendered = '1';
			try {
				fn( el, i );
			} catch ( e ) {
				el.dataset.carveRendered = '';
				window.console && console.error( 'carve diagram:', e );
			}
		} );
	}

	function json( el ) {
		var s = el.querySelector( 'script[type="application/json"]' );
		return s ? JSON.parse( s.textContent ) : null;
	}

	function run() {
		// Mermaid (text in <pre class="mermaid">).
		if ( window.mermaid ) {
			try {
				window.mermaid.initialize( { startOnLoad: false, theme: 'default' } );
				window.mermaid.run( { querySelector: '.wp-carve .mermaid' } );
			} catch ( e ) {
				window.console && console.error( 'carve mermaid:', e );
			}
		}

		// Chart.js (json in <div class="chart">).
		if ( window.Chart ) {
			each( '.chart', function ( el ) {
				var cfg = json( el );
				if ( ! cfg ) {
					return;
				}
				var canvas = document.createElement( 'canvas' );
				el.appendChild( canvas );
				new window.Chart( canvas, cfg );
			} );
		}

		// Vega-Lite (json in <div class="vega-lite">).
		if ( window.vegaEmbed ) {
			each( '.vega-lite', function ( el ) {
				var spec = json( el );
				if ( ! spec ) {
					return;
				}
				var target = document.createElement( 'div' );
				el.appendChild( target );
				window.vegaEmbed( target, spec, { actions: false } );
			} );
		}

		// Graphviz / DOT (text in <pre class="graphviz">), via viz.js (async wasm).
		if ( window.Viz && window.Viz.instance ) {
			window.Viz.instance().then( function ( viz ) {
				each( '.graphviz', function ( el ) {
					var svg = viz.renderSVGElement( el.textContent.trim() );
					el.textContent = '';
					el.appendChild( svg );
				} );
			} );
		}

		// WaveDrom (json in <pre class="wavedrom">).
		if ( window.WaveDrom ) {
			each( '.wavedrom', function ( el, i ) {
				var src = JSON.parse( el.textContent.trim() );
				var holder = document.createElement( 'div' );
				holder.id = 'WaveDrom_Display_carve_' + i;
				el.textContent = '';
				el.appendChild( holder );
				window.WaveDrom.RenderWaveForm( i, src, 'WaveDrom_Display_carve_' );
			} );
		}

		// ABC music notation (text in <pre class="abc">).
		if ( window.ABCJS ) {
			each( '.abc', function ( el ) {
				var src = el.textContent;
				var holder = document.createElement( 'div' );
				el.textContent = '';
				el.appendChild( holder );
				window.ABCJS.renderAbc( holder, src );
			} );
		}
	}

	// Lazy: defer the (heavy) render until a diagram scrolls near the viewport.
	// run() is idempotent, so a single trigger renders everything present.
	function lazyRun() {
		var targets = document.querySelectorAll(
			'.wp-carve .mermaid, .wp-carve .chart, .wp-carve .vega-lite, .wp-carve .graphviz, .wp-carve .wavedrom, .wp-carve .abc'
		);
		if ( ! targets.length ) {
			return;
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			run();
			return;
		}
		var ran = false;
		var io = new IntersectionObserver(
			function ( entries ) {
				for ( var i = 0; i < entries.length; i++ ) {
					if ( entries[ i ].isIntersecting ) {
						if ( ! ran ) {
							ran = true;
							run();
						}
						io.disconnect();
						break;
					}
				}
			},
			{ rootMargin: '200px' }
		);
		targets.forEach( function ( t ) {
			io.observe( t );
		} );
	}

	if ( document.readyState !== 'loading' ) {
		lazyRun();
	} else {
		document.addEventListener( 'DOMContentLoaded', lazyRun );
	}
} )();
