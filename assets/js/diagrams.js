/* Carve diagram renderers: client-side init for the built-in fenced-render
 * types. Each block is rendered only if its library is present (the plugin
 * enqueues a library only when its type is enabled and used on the page). */
( function () {
	'use strict';

	var rootDoc = document;

	function each( sel, fn ) {
		rootDoc.querySelectorAll( '.wpcarve ' + sel ).forEach( function ( el, i ) {
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
		// The engine emits the config in <script type="application/json">, but
		// the render pipeline sanitizes with wp_kses, which strips script tags
		// and leaves the raw JSON as the element's text. Accept both carriers,
		// and clear the element so the raw text never shows next to the render.
		var s = el.querySelector( 'script[type="application/json"]' );
		var text = el.dataset.carveJson || ( s ? s.textContent : el.textContent );
		if ( ! text || ! text.trim() ) {
			return null;
		}
		try {
			var cfg = JSON.parse( text );
			el.textContent = '';
			return cfg;
		} catch ( e ) {
			window.console && console.error( 'carve diagram json:', e );
			return null;
		}
	}

	// Charts re-render on color-scheme changes, so their grid/tick colors can
	// follow the page theme. Track what was drawn.
	var charts = [];

	function inkColor( el ) {
		var c = getComputedStyle( el ).color;
		var m = c.match( /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/ );

		return {
			ink: c,
			grid: m ? 'rgba(' + m[ 1 ] + ',' + m[ 2 ] + ',' + m[ 3 ] + ',0.15)' : c,
		};
	}

	// Fallback series palette (Chart.js's own default is a near-invisible
	// gray wash); hues hold up on light and dark surfaces.
	var PALETTE = [ '#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7' ];

	function drawChart( el, cfg ) {
		var theme = inkColor( el );
		window.Chart.defaults.color = theme.ink;
		window.Chart.defaults.borderColor = theme.grid;
		cfg = JSON.parse( JSON.stringify( cfg ) );
		( ( cfg.data || {} ).datasets || [] ).forEach( function ( set, i ) {
			var hue = PALETTE[ i % PALETTE.length ];
			if ( set.borderColor === undefined ) {
				set.borderColor = hue;
			}
			if ( set.backgroundColor === undefined ) {
				set.backgroundColor = hue + ( cfg.type === 'line' ? '33' : 'B3' );
			}
		} );
		cfg.options = Object.assign(
			{
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
			},
			cfg.options || {}
		);
		var canvas = document.createElement( 'canvas' );
		el.appendChild( canvas );

		return new window.Chart( canvas, cfg );
	}

	function rerenderCharts() {
		if ( ! window.Chart ) {
			return;
		}
		charts.forEach( function ( item ) {
			item.chart.destroy();
			item.el.textContent = '';
			item.chart = drawChart( item.el, item.cfg );
			// The clear above removed the export controls; re-add them for
			// front-end charts (decorate() marks those with data-carveTools).
			if ( item.el.dataset.carveTools ) {
				addTools( item.el );
			}
		} );
	}

	// The theme's toggle can dispatch this; the OS preference change is
	// listened to directly. Deferred past typical color transitions - reading
	// the computed ink synchronously would capture the OLD color at t=0 of a
	// `transition: color` on body.
	function scheduleRerender() {
		setTimeout( rerenderCharts, 300 );
	}

	document.addEventListener( 'wpcarve:scheme-change', scheduleRerender );
	var schemeQuery = window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' );
	if ( schemeQuery ) {
		// Older Safari exposes addListener only; an unguarded addEventListener
		// would throw here and abort every diagram on the page.
		if ( schemeQuery.addEventListener ) {
			schemeQuery.addEventListener( 'change', scheduleRerender );
		} else if ( schemeQuery.addListener ) {
			schemeQuery.addListener( scheduleRerender );
		}
	}
	window.wpCarveDiagrams = window.wpCarveDiagrams || {};
	window.wpCarveDiagrams.rerenderCharts = rerenderCharts;

	// --- Export controls (front-end only): copy/download a rendered diagram ---
	var l10n = window.wpCarveDiagramsL10n || {};
	function label( key, fallback ) {
		return l10n[ key ] || fallback;
	}

	function triggerDownload( blob, name ) {
		var url = URL.createObjectURL( blob );
		var a = document.createElement( 'a' );
		a.href = url;
		a.download = name;
		document.body.appendChild( a );
		a.click();
		a.remove();
		setTimeout( function () {
			URL.revokeObjectURL( url );
		}, 1000 );
	}

	// Types that render to an <svg> (the rest, e.g. Chart.js, use a <canvas>).
	var SVG_TYPES = { mermaid: 1, graphviz: 1, wavedrom: 1, abc: 1 };

	function diagramType( el ) {
		var names = [ 'mermaid', 'chart', 'vega-lite', 'graphviz', 'wavedrom', 'abc' ];
		for ( var i = 0; i < names.length; i++ ) {
			if ( el.classList.contains( names[ i ] ) ) {
				return names[ i ];
			}
		}

		return 'diagram';
	}

	function svgMarkup( el ) {
		var svg = el.querySelector( 'svg' );
		if ( ! svg ) {
			return null;
		}
		var clone = svg.cloneNode( true );
		if ( ! clone.getAttribute( 'xmlns' ) ) {
			clone.setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}

		return new XMLSerializer().serializeToString( clone );
	}

	function addTools( el ) {
		if ( el.querySelector( ':scope > .wpcarve-diagram-tools' ) ) {
			return;
		}
		var type = diagramType( el );
		var tools = document.createElement( 'div' );
		tools.className = 'wpcarve-diagram-tools';

		var dl = document.createElement( 'button' );
		dl.type = 'button';
		dl.className = 'wpcarve-diagram-btn';
		dl.textContent = label( 'download', 'Download' );
		dl.addEventListener( 'click', function () {
			var markup = svgMarkup( el );
			if ( markup ) {
				triggerDownload(
					new Blob( [ markup ], { type: 'image/svg+xml;charset=utf-8' } ),
					'diagram-' + type + '.svg'
				);

				return;
			}
			// Canvas-based renderers (Chart.js, Vega default) export as PNG.
			var canvas = el.querySelector( 'canvas' );
			if ( canvas && canvas.toBlob ) {
				canvas.toBlob( function ( blob ) {
					if ( blob ) {
						triggerDownload( blob, 'diagram-' + type + '.png' );
					}
				} );
			}
		} );
		tools.appendChild( dl );

		if ( SVG_TYPES[ type ] ) {
			var copy = document.createElement( 'button' );
			copy.type = 'button';
			copy.className = 'wpcarve-diagram-btn';
			copy.textContent = label( 'copy', 'Copy SVG' );
			copy.addEventListener( 'click', function () {
				var markup = svgMarkup( el );
				if ( ! markup || ! navigator.clipboard ) {
					return;
				}
				navigator.clipboard.writeText( markup ).then( function () {
					var prev = copy.textContent;
					copy.textContent = label( 'copied', 'Copied' );
					setTimeout( function () {
						copy.textContent = prev;
					}, 1200 );
				} );
			} );
			tools.appendChild( copy );
		}

		el.appendChild( tools );
	}

	// Register hover-to-add controls on each diagram container. Deferred to first
	// hover so the tools are attached AFTER the (sometimes async) render has
	// cleared and repopulated the container - avoiding a race that would wipe the
	// buttons. The buttons themselves read the SVG/canvas at click time.
	function decorate( doc ) {
		doc.querySelectorAll(
			'.wpcarve .mermaid, .wpcarve .chart, .wpcarve .vega-lite, .wpcarve .graphviz, .wpcarve .wavedrom, .wpcarve .abc'
		).forEach( function ( el ) {
			if ( el.dataset.carveTools ) {
				return;
			}
			el.dataset.carveTools = '1';
			el.addEventListener( 'pointerenter', function once() {
				el.removeEventListener( 'pointerenter', once );
				addTools( el );
			} );
		} );
	}

	function run() {
		// Mermaid (text in <pre class="mermaid">).
		if ( window.mermaid ) {
			try {
				window.mermaid.initialize( { startOnLoad: false, theme: 'default' } );
				window.mermaid.run( { nodes: rootDoc.querySelectorAll( '.wpcarve .mermaid' ) } );
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
				charts.push( { el: el, cfg: cfg, chart: drawChart( el, cfg ) } );
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
			'.wpcarve .mermaid, .wpcarve .chart, .wpcarve .vega-lite, .wpcarve .graphviz, .wpcarve .wavedrom, .wpcarve .abc'
		);
		if ( ! targets.length ) {
			return;
		}
		// Front-end only: attach copy/download controls (the editor preview,
		// which calls wpCarveDiagrams.run() directly, stays chrome-free).
		decorate( document );
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

	// Re-runnable entry point for dynamic surfaces (the block editor preview
	// re-renders its pane on every keystroke - inside the editor-canvas
	// iframe, so callers pass their document). Idempotent per element via
	// the data-carveRendered guard.
	// Assign onto the existing object (see above) so rerenderCharts, set
	// earlier, is not dropped by replacing wpCarveDiagrams wholesale.
	window.wpCarveDiagrams.run = function ( doc ) {
		rootDoc = doc || document;
		try {
			run();
		} finally {
			rootDoc = document;
		}
	};
} )();
