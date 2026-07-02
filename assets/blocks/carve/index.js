/* global wp, wpCarve */
( function ( wp, cfg ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, useState, useEffect, useRef } = wp.element;
	const { InspectorControls, BlockControls, useBlockProps } = wp.blockEditor;
	const {
		TextareaControl,
		Notice,
		Button,
		ButtonGroup,
		PanelBody,
		SelectControl,
		RangeControl,
		Modal,
		ToolbarGroup,
		ToolbarButton,
		ToolbarDropdownMenu,
	} = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	cfg = cfg || {};

	const ADMONITIONS = [
		'note',
		'tip',
		'info',
		'warning',
		'danger',
		'success',
		'example',
		'quote',
	];

	function cap( s ) {
		return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
	}

	// Innovation A: prefer the in-browser Carve engine for instant preview.
	// `window.wpCarveEngine` is set by the optional carve-js module bundle
	// (assets/js/vendor/carve.js). Falls back to the REST render endpoint.
	function renderPreview( source, done, profile, forceServer ) {
		const engine = window.wpCarveEngine;
		// The in-browser engine can't apply a WordPress content profile, so a
		// block that overrides the profile falls through to the server render.
		// forceServer is available for callers that need server-faithful HTML.
		if ( ! forceServer && ! profile && cfg.livePreview && engine && typeof engine.carveToHtml === 'function' ) {
			try {
				done( engine.carveToHtml( source ) );
				return;
			} catch ( e ) {
				// fall through to server render
			}
		}
		wp.apiFetch( {
			url: cfg.restRender,
			method: 'POST',
			data: { carve: source, context: 'post', profile: profile || '' },
		} )
			.then( ( res ) => done( res.html || '' ) )
			.catch( () => done( '' ) );
	}

	// Foundation: optional Tiptap visual editor. Lazy-loaded as an ES module
	// (assets/js/tiptap/visual-editor.js) only when the user switches to Visual
	// mode, so the CDN-backed Tiptap bundle never loads for source-only editing.
	// Normalize Carve to comparable non-empty, whitespace-collapsed lines so a
	// round-trip diff ignores cosmetic reflow.
	function normalizeLines( carve ) {
		return ( carve || '' )
			.split( '\n' )
			.map( ( l ) => l.replace( /\s+/g, ' ' ).trim() )
			.filter( Boolean );
	}

	// Lines present in `a` but not `b` (multiset-aware), capped.
	function missingFrom( a, b ) {
		const pool = b.slice();
		const out = [];
		a.forEach( ( line ) => {
			const i = pool.indexOf( line );
			if ( i === -1 ) {
				out.push( line );
			} else {
				pool.splice( i, 1 );
			}
		} );
		return out;
	}

	function VisualMode( { attributes, setAttributes, approved, onApprove, onExit } ) {
		const hostRef = useRef( null );
		const ctlRef = useRef( null );
		const [ lossy, setLossy ] = useState( null );
		const [ ready, setReady ] = useState( false );
		const [ failed, setFailed ] = useState( false );
		// eslint-disable-next-line no-unused-vars
		const [ tick, setTick ] = useState( 0 );

		useEffect( () => {
			let active = true;
			let ctl = null;
			// Seed the editor from the SERVER render (carve-php + extensions):
			// media embeds seed as real iframes stamped with data-carve-source,
			// so they preview as video and round-trip losslessly. Admonitions
			// round-trip too (aside.admonition is parsed). Footnotes still gate
			// (a known carve-grammars parse gap, same under either engine).
			renderPreview( attributes.carve || '', ( html ) => {
				if ( ! active || ! hostRef.current ) {
					return;
				}
				import( /* webpackIgnore: true */ cfg.visualEditor )
					.then( ( mod ) =>
						mod.initVisualEditor( hostRef.current, html, ( carve ) =>
							setAttributes( { carve } )
						)
					)
					.then( ( instance ) => {
						if ( ! active ) {
							instance.destroy();
							return;
						}
						ctl = instance;
						ctlRef.current = instance;
						// Re-render the toolbar as the cursor moves so context
						// controls (code language, admonition type) track the node.
						instance.editor.on( 'selectionUpdate', () => setTick( ( t ) => t + 1 ) );
						// Round-trip check: seed -> serialize back. Ignore pure
						// whitespace / reflow (normalizeLines collapses it); only
						// real content drift gates entry.
						const original = normalizeLines( attributes.carve );
						const roundtrip = normalizeLines( instance.getCarve() );
						const removed = missingFrom( original, roundtrip );
						const added = missingFrom( roundtrip, original );
						if ( removed.length || added.length ) {
							setLossy( { removed, added } );
						}
						setReady( true );
					} )
					.catch( () => setFailed( true ) );
			}, attributes.profile || '', true );
			return () => {
				active = false;
				if ( ctl ) {
					ctl.destroy();
				}
			};
			// Mount once per Visual-mode entry; edits flow out via onChange.
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		// Gate: when the round-trip would change content and the user has not yet
		// approved, block the editor behind a modal.
		const gated = ready && lossy && ! approved;

		// Visual-mode toolbar rendered through the SAME WordPress block toolbar as
		// Write mode, so both look identical - only the actions differ (Tiptap
		// commands here vs source-text inserts in Write).
		function cmd( fn ) {
			return () => {
				const ed = ctlRef.current && ctlRef.current.editor;
				if ( ed ) {
					fn( ed.chain().focus() ).run();
				}
			};
		}
		function insertNode( node ) {
			return () => {
				const ed = ctlRef.current && ctlRef.current.editor;
				if ( ed ) {
					ed.chain().focus().insertContent( node ).run();
				}
			};
		}
		function promptLink() {
			const ed = ctlRef.current && ctlRef.current.editor;
			if ( ! ed ) {
				return;
			}
			const url = window.prompt( __( 'Link URL', 'carve-markup' ) );
			if ( url === null ) {
				return;
			}
			const chain = ed.chain().focus();
			( url === '' ? chain.unsetLink() : chain.setLink( { href: url } ) ).run();
		}
		function promptImage() {
			const ed = ctlRef.current && ctlRef.current.editor;
			if ( ! ed ) {
				return;
			}
			const src = window.prompt( __( 'Image URL', 'carve-markup' ) );
			if ( ! src ) {
				return;
			}
			ed.chain().focus().setImage( { src, alt: window.prompt( __( 'Alt text', 'carve-markup' ), '' ) || '' } ).run();
		}

		function promptEmbed() {
			const ed = ctlRef.current && ctlRef.current.editor;
			if ( ! ed ) {
				return;
			}
			const url = window.prompt( __( 'YouTube / Vimeo URL or video ID:', 'carve-markup' ), '' );
			if ( ! url ) {
				return;
			}
			// A bare id (no scheme) is assumed to be YouTube. carve-grammars
			// serializes the src to :youtube[id] / :vimeo[id] / :media[url].
			const src = /^https?:|^\/\//.test( url ) ? url : 'https://www.youtube.com/watch?v=' + url;
			ed.chain().focus().setCarveEmbed( { src } ).run();
		}

		// Context controls: attribute editors for the node the cursor is in.
		// (Code-block language has its own in-block picker from carve-grammars.)
		const ed = ctlRef.current && ctlRef.current.editor;
		const setAttr = ( name, attrs ) => () => ed && ed.chain().focus().updateAttributes( name, attrs ).run();
		const contextGroup =
			ed &&
			ed.isActive( 'carveDiv' ) &&
			el(
				ToolbarGroup,
				null,
				el( ToolbarDropdownMenu, {
					icon: 'info',
					label: __( 'Admonition type', 'carve-markup' ),
					controls: ADMONITIONS.map( ( t ) => ( { title: cap( t ), onClick: setAttr( 'carveDiv', { class: t } ) } ) ),
				} )
			);

		const visualToolbar =
			ready && ! gated &&
			el(
				BlockControls,
				null,
				el(
					ToolbarGroup,
					null,
					el( ToolbarDropdownMenu, {
						icon: 'heading',
						label: __( 'Heading', 'carve-markup' ),
						controls: [ 1, 2, 3, 4, 5, 6 ].map( ( n ) => ( { title: 'H' + n, onClick: cmd( ( c ) => c.toggleHeading( { level: n } ) ) } ) ),
					} ),
					el( ToolbarButton, { icon: 'editor-bold', title: __( 'Strong (bold)', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleBold() ) } ),
					el( ToolbarButton, { icon: 'editor-italic', title: __( 'Emphasis (italic)', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleItalic() ) } ),
					el( ToolbarButton, { icon: 'editor-underline', title: __( 'Underline', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleUnderline() ) } ),
					el( ToolbarButton, { icon: 'editor-code', title: __( 'Inline code', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleCode() ) } ),
					el( ToolbarButton, { icon: 'admin-links', title: __( 'Link', 'carve-markup' ), onClick: promptLink } ),
					el( ToolbarButton, { icon: 'format-image', title: __( 'Image', 'carve-markup' ), onClick: promptImage } )
				),
				el(
					ToolbarGroup,
					null,
					el( ToolbarDropdownMenu, {
						icon: 'editor-ul',
						label: __( 'List', 'carve-markup' ),
						controls: [
							{ title: __( 'Bullet list', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleBulletList() ) },
							{ title: __( 'Ordered list', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleOrderedList() ) },
						],
					} ),
					el( ToolbarButton, { icon: 'editor-quote', title: __( 'Blockquote', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleBlockquote() ) } ),
					el( ToolbarButton, { icon: 'editor-table', title: __( 'Table', 'carve-markup' ), onClick: cmd( ( c ) => c.insertTable( { rows: 3, cols: 3, withHeaderRow: true } ) ) } ),
					el( ToolbarButton, { icon: 'editor-code', title: __( 'Code block', 'carve-markup' ), onClick: cmd( ( c ) => c.toggleCodeBlock() ) } ),
					el( ToolbarDropdownMenu, {
						icon: 'info',
						label: __( 'Admonition', 'carve-markup' ),
						controls: ADMONITIONS.map( ( t ) => ( { title: cap( t ), onClick: cmd( ( c ) => c.toggleCarveDiv( { class: t } ) ) } ) ),
					} ),
					el( ToolbarButton, { icon: 'format-video', title: __( 'Media embed', 'carve-markup' ), onClick: promptEmbed } ),
					el( ToolbarButton, { icon: 'minus', title: __( 'Divider', 'carve-markup' ), onClick: cmd( ( c ) => c.setHorizontalRule() ) } )
				),
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, { icon: 'format-aside', title: __( 'Footnote', 'carve-markup' ), onClick: insertNode( { type: 'carveFootnote', attrs: { label: 'note' } } ) } ),
					el( ToolbarDropdownMenu, {
						icon: 'calculator',
						label: __( 'Math', 'carve-markup' ),
						controls: [
							{ title: __( 'Inline math', 'carve-markup' ), onClick: insertNode( { type: 'carveMath', attrs: { tex: 'x', display: false } } ) },
							{ title: __( 'Display math', 'carve-markup' ), onClick: insertNode( { type: 'carveMath', attrs: { tex: 'x', display: true } } ) },
						],
					} ),
					el( ToolbarButton, { icon: 'editor-removeformatting', title: __( 'Clear formatting', 'carve-markup' ), onClick: cmd( ( c ) => c.clearNodes().unsetAllMarks() ) } )
				),
				contextGroup
			);

		const modal =
			gated &&
			el(
				Modal,
				{
					title: __( 'Visual editing may change this content', 'carve-markup' ),
					onRequestClose: onExit,
					className: 'wp-carve-ve-modal',
				},
				el(
					'p',
					null,
					__( 'The visual editor rebuilds the source from rendered HTML, so these constructs would not survive a round-trip exactly. Edit in the Visual tab anyway, or go back to the Write tab to keep them intact.', 'carve-markup' )
				),
				el(
					'pre',
					{ className: 'wp-carve-ve-diff' },
					lossy.removed.slice( 0, 15 ).map( ( l, i ) => el( 'div', { key: 'r' + i, className: 'wp-carve-diff-del' }, '- ' + l ) ),
					lossy.added.slice( 0, 15 ).map( ( l, i ) => el( 'div', { key: 'a' + i, className: 'wp-carve-diff-add' }, '+ ' + l ) )
				),
				el(
					'div',
					{ className: 'wp-carve-ve-modal-actions' },
					el( Button, { variant: 'secondary', onClick: onExit }, __( 'Back to Write', 'carve-markup' ) ),
					' ',
					el( Button, { variant: 'primary', onClick: onApprove }, __( 'Edit in Visual anyway', 'carve-markup' ) )
				)
			);

		const failNotice =
			failed &&
			el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'The visual editor could not load. Switch to Write mode to keep editing.', 'carve-markup' ),
				' ',
				el( Button, { variant: 'secondary', size: 'small', onClick: onExit }, __( 'Back to Write', 'carve-markup' ) )
			);

		return el(
			'div',
			{ className: 'wp-carve-ve-wrap' },
			failNotice,
			visualToolbar,
			modal,
			el( 'div', {
				className: 'wp-carve-ve',
				ref: hostRef,
				// Keep it mounted (needed to compute the round-trip) but hidden
				// while the approval modal is up.
				style: gated ? { display: 'none' } : undefined,
			} )
		);
	}

	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps( { className: 'wp-carve-block' } );
		const source = attributes.carve || '';

		const hasVisual = !! cfg.visualEditor;
		const startMode = hasVisual && cfg.startMode === 'visual' ? 'visual' : 'write';
		const [ mode, setMode ] = useState( startMode );
		const [ html, setHtml ] = useState( '' );
		const [ ingest, setIngest ] = useState( null );
		const [ tableOpen, setTableOpen ] = useState( false );
		const [ cols, setCols ] = useState( 3 );
		const [ rows, setRows ] = useState( 2 );
		const [ importOpen, setImportOpen ] = useState( false );
		const [ importFrom, setImportFrom ] = useState( 'auto' );
		const [ importText, setImportText ] = useState( '' );
		// Approve entering Visual mode once per block session when lossy.
		const [ visualApproved, setVisualApproved ] = useState( false );
		const [ fullscreen, setFullscreen ] = useState( false );
		const taRef = useRef( null );
		const previewRef = useRef( null );
		const timer = useRef( null );

		const showPreview = mode === 'preview' || mode === 'split';

		useEffect( () => {
			if ( mode === 'visual' || ! showPreview ) {
				return undefined;
			}
			clearTimeout( timer.current );
			// The Preview tab renders server-side (carve-php) for full front-end
			// fidelity - media embeds show as real players, etc. Split stays on
			// the instant in-browser engine for live-typing speed.
			const forceServer = mode === 'preview';
			timer.current = setTimeout( () => renderPreview( source, setHtml, attributes.profile || '', forceServer ), 200 );
			return () => clearTimeout( timer.current );
		}, [ source, mode, showPreview, attributes.profile ] );

		// --- source manipulation (operate on the raw textarea selection) ---
		function setVal( value, selStart, selEnd ) {
			setAttributes( { carve: value } );
			window.requestAnimationFrame( () => {
				const ta = taRef.current;
				if ( ta ) {
					ta.focus();
					ta.selectionStart = selStart;
					ta.selectionEnd = selEnd === undefined ? selStart : selEnd;
				}
			} );
		}

		function sel() {
			const ta = taRef.current;
			if ( ! ta ) {
				return { value: source, start: source.length, end: source.length };
			}
			return { value: source, start: ta.selectionStart, end: ta.selectionEnd };
		}

		function wrap( before, after, placeholder ) {
			const { value, start, end } = sel();
			const chosen = start !== end ? value.slice( start, end ) : placeholder || '';
			const next = value.slice( 0, start ) + before + chosen + after + value.slice( end );
			setVal( next, start + before.length, start + before.length + chosen.length );
		}

		function inlineInsert( text, selFrom, selLen ) {
			const { value, start, end } = sel();
			const next = value.slice( 0, start ) + text + value.slice( end );
			const s = start + ( selFrom || 0 );
			setVal( next, s, s + ( selLen || 0 ) );
		}

		function linePrefix( prefix ) {
			const { value, start, end } = sel();
			const lineStart = value.lastIndexOf( '\n', start - 1 ) + 1;
			const seg = value.slice( lineStart, end );
			const replaced = seg
				.split( '\n' )
				.map( ( l ) => prefix + l )
				.join( '\n' );
			const next = value.slice( 0, lineStart ) + replaced + value.slice( end );
			setVal( next, lineStart, lineStart + replaced.length );
		}

		function blockInsert( text ) {
			const { value, start } = sel();
			const before = value.slice( 0, start );
			const after = value.slice( start );
			const pre = before && ! before.endsWith( '\n\n' ) ? ( before.endsWith( '\n' ) ? '\n' : '\n\n' ) : '';
			const post = after && ! after.startsWith( '\n' ) ? '\n\n' : '';
			const next = before + pre + text + post + after;
			const pos = before.length + pre.length + text.length;
			setVal( next, pos, pos );
		}

		// Set (or toggle off) the heading level of the selected lines, replacing
		// any existing #-markers instead of stacking more.
		function setHeadingLine( level ) {
			const { value, start, end } = sel();
			const lineStart = value.lastIndexOf( '\n', start - 1 ) + 1;
			const seg = value.slice( lineStart, end );
			const lines = seg.split( '\n' );
			const marker = '#'.repeat( level ) + ' ';
			const exact = new RegExp( '^#{' + level + '} ' );
			const allSame = lines.every( ( l ) => exact.test( l ) );
			const replaced = lines
				.map( ( l ) => {
					const stripped = l.replace( /^#{1,6}\s+/, '' );
					return allSame ? stripped : marker + stripped;
				} )
				.join( '\n' );
			const next = value.slice( 0, lineStart ) + replaced + value.slice( end );
			setVal( next, lineStart, lineStart + replaced.length );
		}

		// Strip Carve inline + line markers from the selection ("clear formatting").
		function stripFormatting() {
			const { value, start, end } = sel();
			if ( start === end ) {
				return;
			}
			let seg = value.slice( start, end );
			seg = seg
				.replace( /\*(.+?)\*/g, '$1' )
				// Italic /../: skip `//` and `://` (URLs) - opening slash must not
				// follow `:` or a slash, and the run must contain no slash.
				.replace( /(^|[^:/])\/([^/\n]+?)\//g, '$1$2' )
				.replace( /_(.+?)_/g, '$1' )
				.replace( /~(.+?)~/g, '$1' )
				.replace( /==(.+?)==/g, '$1' )
				.replace( /`(.+?)`/g, '$1' )
				.replace( /\^(.+?)\^/g, '$1' )
				.replace( /,,(.+?),,/g, '$1' );
			seg = seg
				.split( '\n' )
				.map( ( l ) =>
					l
						.replace( /^\s*#{1,6}\s+/, '' )
						.replace( /^\s*>\s?/, '' )
						.replace( /^\s*[-*+]\s+(\[[ xX]\]\s+)?/, '' )
						.replace( /^\s*\d+\.\s+/, '' )
				)
				.join( '\n' );
			const next = value.slice( 0, start ) + seg + value.slice( end );
			setVal( next, start, start + seg.length );
		}

		function buildTable( c, r ) {
			const head = '| ' + Array.from( { length: c }, ( _, i ) => 'Col ' + ( i + 1 ) ).join( ' | ' ) + ' |';
			const rule = '| ' + Array.from( { length: c }, () => '---' ).join( ' | ' ) + ' |';
			const body = Array.from( { length: r }, () =>
				'| ' + Array.from( { length: c }, () => '   ' ).join( ' | ' ) + ' |'
			).join( '\n' );
			return head + '\n' + rule + '\n' + body;
		}

		function outdentLines() {
			const { value, start, end } = sel();
			const lineStart = value.lastIndexOf( '\n', start - 1 ) + 1;
			const seg = value.slice( lineStart, end );
			const replaced = seg
				.split( '\n' )
				.map( ( l ) => l.replace( /^ {1,2}/, '' ) )
				.join( '\n' );
			const next = value.slice( 0, lineStart ) + replaced + value.slice( end );
			setVal( next, lineStart, lineStart + replaced.length );
		}

		function onKeyDown( e ) {
			// Tab / Shift+Tab indent-outdent the selected lines.
			if ( e.key === 'Tab' ) {
				e.preventDefault();
				if ( e.shiftKey ) {
					outdentLines();
				} else {
					linePrefix( '  ' );
				}
				return;
			}
			if ( ! ( e.ctrlKey || e.metaKey ) ) {
				return;
			}
			const k = e.key.toLowerCase();
			const code = e.code;
			let handled = true;
			if ( e.shiftKey ) {
				if ( k === 'x' ) {
					wrap( '~', '~', 'strike' );
				} else if ( k === 'h' ) {
					wrap( '==', '==', 'highlight' );
				} else if ( k === 'e' ) {
					blockInsert( '```\n\n```' );
				} else if ( k === '.' ) {
					linePrefix( '> ' );
				} else if ( code === 'Digit8' ) {
					linePrefix( '- ' );
				} else if ( code === 'Digit7' ) {
					linePrefix( '1. ' );
				} else if ( k === 'i' ) {
					inlineInsert( '![alt](https://)', 2, 3 );
				} else {
					handled = false;
				}
			} else if ( k === 'b' ) {
				wrap( '*', '*', 'bold' );
			} else if ( k === 'i' ) {
				wrap( '/', '/', 'italic' );
			} else if ( k === 'u' ) {
				wrap( '_', '_', 'underline' );
			} else if ( k === 'e' ) {
				wrap( '`', '`', 'code' );
			} else if ( k === 'k' ) {
				inlineInsert( '[text](https://)', 1, 4 );
			} else if ( k === '.' ) {
				wrap( '^', '^', 'sup' );
			} else if ( k === ',' ) {
				wrap( ',,', ',,', 'sub' );
			} else if ( /^Digit[1-6]$/.test( code ) ) {
				setHeadingLine( Number( code.slice( 5 ) ) );
			} else if ( k === '\\' ) {
				stripFormatting();
			} else {
				handled = false;
			}
			if ( handled ) {
				e.preventDefault();
			}
		}

		function onPaste( event ) {
			if ( ! cfg.pasteIngest ) {
				return;
			}
			const text = ( event.clipboardData || window.clipboardData ).getData( 'text' );
			// Only offer conversion when the paste smells like another format.
			if ( ! text || ! /[<[]|\*\*|^#{1,6}\s/m.test( text ) ) {
				return;
			}
			setIngest( text );
		}

		function ingestNow( raw, from ) {
			return wp.apiFetch( {
				url: cfg.restIngest,
				method: 'POST',
				data: { source: raw, from: from || 'auto' },
			} );
		}

		function doPasteIngest() {
			ingestNow( ingest, 'auto' )
				.then( ( res ) => {
					setAttributes( { carve: source + res.carve } );
					setIngest( null );
				} )
				.catch( () => setIngest( null ) );
		}

		function doImport() {
			ingestNow( importText, importFrom )
				.then( ( res ) => {
					blockInsert( res.carve || '' );
					setImportOpen( false );
					setImportText( '' );
				} )
				.catch( () => setImportOpen( false ) );
		}

		// --- toolbar ---
		const toolbar =
			( mode === 'write' || mode === 'split' ) &&
			el(
				BlockControls,
				null,
				el(
					ToolbarGroup,
					null,
					el( ToolbarDropdownMenu, {
						icon: 'heading',
						label: __( 'Heading', 'carve-markup' ),
						controls: [ 1, 2, 3, 4, 5, 6 ].map( ( n ) => ( {
							title: 'H' + n,
							onClick: () => setHeadingLine( n ),
						} ) ),
					} ),
					el( ToolbarButton, { icon: 'editor-bold', title: __( 'Strong (bold)', 'carve-markup' ), onClick: () => wrap( '*', '*', 'bold' ) } ),
					el( ToolbarButton, { icon: 'editor-italic', title: __( 'Emphasis (italic)', 'carve-markup' ), onClick: () => wrap( '/', '/', 'italic' ) } ),
					el( ToolbarButton, { icon: 'editor-underline', title: __( 'Underline', 'carve-markup' ), onClick: () => wrap( '_', '_', 'underline' ) } ),
					el( ToolbarButton, { icon: 'editor-code', title: __( 'Inline code', 'carve-markup' ), onClick: () => wrap( '`', '`', 'code' ) } ),
					el( ToolbarButton, { icon: 'admin-links', title: __( 'Link', 'carve-markup' ), onClick: () => inlineInsert( '[text](https://)', 1, 4 ) } ),
					el( ToolbarButton, { icon: 'format-image', title: __( 'Image', 'carve-markup' ), onClick: () => inlineInsert( '![alt](https://)', 2, 3 ) } )
				),
				el(
					ToolbarGroup,
					null,
					el( ToolbarDropdownMenu, {
						icon: 'editor-ul',
						label: __( 'List', 'carve-markup' ),
						controls: [
							{ title: __( 'Bullet list', 'carve-markup' ), onClick: () => linePrefix( '- ' ) },
							{ title: __( 'Ordered list', 'carve-markup' ), onClick: () => linePrefix( '1. ' ) },
							{ title: __( 'Task list', 'carve-markup' ), onClick: () => linePrefix( '- [ ] ' ) },
						],
					} ),
					el( ToolbarButton, { icon: 'editor-quote', title: __( 'Blockquote', 'carve-markup' ), onClick: () => linePrefix( '> ' ) } ),
					el( ToolbarButton, { icon: 'editor-table', title: __( 'Table', 'carve-markup' ), onClick: () => setTableOpen( true ) } ),
					el( ToolbarButton, { icon: 'editor-code', title: __( 'Code block', 'carve-markup' ), onClick: () => blockInsert( '```\n\n```' ) } ),
					el( ToolbarDropdownMenu, {
						icon: 'info',
						label: __( 'Admonition', 'carve-markup' ),
						controls: ADMONITIONS.map( ( t ) => ( {
							title: cap( t ),
							onClick: () => blockInsert( '::: ' + t + '\n\n:::' ),
						} ) ),
					} ),
					el( ToolbarDropdownMenu, {
						icon: 'format-video',
						label: __( 'Media embed', 'carve-markup' ),
						controls: [
							{ title: 'YouTube', onClick: () => inlineInsert( ':youtube[VIDEO_ID]', 9, 8 ) },
							{ title: 'Vimeo', onClick: () => inlineInsert( ':vimeo[VIDEO_ID]', 7, 8 ) },
							{ title: __( 'Auto (URL)', 'carve-markup' ), onClick: () => inlineInsert( ':media[https://]', 7, 8 ) },
						],
					} ),
					el( ToolbarButton, { icon: 'minus', title: __( 'Divider', 'carve-markup' ), onClick: () => blockInsert( '---' ) } )
				),
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, { icon: 'format-aside', title: __( 'Footnote', 'carve-markup' ), onClick: () => inlineInsert( '^[note]', 2, 4 ) } ),
					el( ToolbarDropdownMenu, {
						icon: 'calculator',
						label: __( 'Math', 'carve-markup' ),
						controls: [
							{ title: __( 'Inline math', 'carve-markup' ), onClick: () => inlineInsert( '$`x`', 2, 1 ) },
							{ title: __( 'Display math', 'carve-markup' ), onClick: () => blockInsert( '$$`x`' ) },
						],
					} ),
					el( ToolbarButton, { icon: 'book', title: __( 'Citation', 'carve-markup' ), onClick: () => inlineInsert( '[@key]', 2, 3 ) } ),
					el( ToolbarButton, { icon: 'editor-justify', title: __( 'Definition list', 'carve-markup' ), onClick: () => blockInsert( ':: Term\n:  Definition' ) } ),
					el( ToolbarButton, { icon: 'editor-removeformatting', title: __( 'Clear formatting', 'carve-markup' ), onClick: stripFormatting } )
				)
			);

		// --- mode tabs ---
		const modes = [ [ 'write', __( 'Write', 'carve-markup' ) ], [ 'split', __( 'Split', 'carve-markup' ) ] ];
		if ( hasVisual ) {
			modes.push( [ 'visual', __( 'Visual', 'carve-markup' ) ] );
		}
		modes.push( [ 'preview', __( 'Preview', 'carve-markup' ) ] );

		const tabs = el(
			'div',
			{ className: 'wp-carve-modes' },
			el(
				ButtonGroup,
				null,
				modes.map( ( [ m, label ] ) =>
					el(
						Button,
						{
							key: m,
							size: 'small',
							variant: mode === m ? 'primary' : 'secondary',
							isPressed: mode === m,
							onClick: () => setMode( m ),
						},
						label
					)
				)
			),
			el(
				Button,
				{
					size: 'small',
					variant: 'tertiary',
					className: 'wp-carve-fullscreen-toggle',
					isPressed: fullscreen,
					onClick: () => setFullscreen( ! fullscreen ),
					icon: fullscreen ? 'fullscreen-exit-alt' : 'fullscreen-alt',
					label: fullscreen ? __( 'Exit full screen', 'carve-markup' ) : __( 'Distraction-free', 'carve-markup' ),
					showTooltip: true,
				}
			)
		);

		// In Split mode, scrolling the source scrolls the preview proportionally.
		function syncScroll() {
			if ( mode !== 'split' ) {
				return;
			}
			const ta = taRef.current;
			const pv = previewRef.current;
			if ( ! ta || ! pv ) {
				return;
			}
			const ratio = ta.scrollTop / Math.max( 1, ta.scrollHeight - ta.clientHeight );
			pv.scrollTop = ratio * Math.max( 0, pv.scrollHeight - pv.clientHeight );
		}

		const sourceField = el( 'textarea', {
			ref: taRef,
			className: 'wp-carve-source',
			'aria-label': __( 'Carve source', 'carve-markup' ),
			value: source,
			spellCheck: false,
			rows: mode === 'split' ? 18 : 12,
			onChange: ( e ) => setAttributes( { carve: e.target.value } ),
			onKeyDown,
			onPaste,
			onScroll: syncScroll,
		} );

		const previewField = el( 'div', {
			ref: previewRef,
			className: 'wp-carve wp-carve-preview',
			dangerouslySetInnerHTML: { __html: html },
		} );

		let body;
		if ( mode === 'visual' ) {
			body = el( VisualMode, {
				attributes,
				setAttributes,
				approved: visualApproved,
				onApprove: () => setVisualApproved( true ),
				onExit: () => setMode( 'write' ),
			} );
		} else if ( mode === 'preview' ) {
			body = previewField;
		} else if ( mode === 'split' ) {
			body = el( 'div', { className: 'wp-carve-split' }, sourceField, previewField );
		} else {
			body = sourceField;
		}

		const words = source.trim() ? source.trim().split( /\s+/ ).length : 0;

		return el(
			'div',
			{ ...blockProps, className: ( blockProps.className || '' ) + ( fullscreen ? ' is-carve-fullscreen' : '' ) },
			toolbar,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Tools', 'carve-markup' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Content profile', 'carve-markup' ),
						help: __( 'Override the site default for this block.', 'carve-markup' ),
						value: attributes.profile || '',
						options: [
							{ label: __( 'Default (site setting)', 'carve-markup' ), value: '' },
							{ label: __( 'Full', 'carve-markup' ), value: 'full' },
							{ label: __( 'Article', 'carve-markup' ), value: 'article' },
							{ label: __( 'Comment', 'carve-markup' ), value: 'comment' },
							{ label: __( 'Minimal', 'carve-markup' ), value: 'minimal' },
							{ label: __( 'None', 'carve-markup' ), value: 'none' },
						],
						onChange: ( profile ) => setAttributes( { profile } ),
						__nextHasNoMarginBottom: true,
					} ),
					el( 'p', { className: 'wp-carve-count' }, words + ' ' + __( 'words', 'carve-markup' ) ),
					cfg.pasteIngest &&
						el(
							Button,
							{ variant: 'secondary', size: 'small', onClick: () => setImportOpen( true ) },
							__( 'Import & convert…', 'carve-markup' )
						),
					cfg.pasteIngest && ' ',
					el(
						Button,
						{ variant: 'secondary', size: 'small', isDestructive: true, onClick: () => setAttributes( { carve: '' } ) },
						__( 'Clear', 'carve-markup' )
					)
				),
				el(
					PanelBody,
					{ title: __( 'Carve syntax', 'carve-markup' ), initialOpen: false },
					el(
						'ul',
						{ className: 'wp-carve-cheat' },
						[
							'*strong*  /emphasis/  _underline_  `code`',
							'# Heading   > quote   --- divider',
							'- bullet   1. ordered   - [ ] task',
							'[text](url)   ![alt](url)',
							'::: note … :::  (admonition/container)',
							':youtube[ID]  :media[url]',
							'^[footnote]   $`math`   [@cite]',
							':: term   :  definition',
						].map( ( line, i ) => el( 'li', { key: i }, el( 'code', null, line ) ) )
					)
				),
				el(
					PanelBody,
					{ title: __( 'Keyboard shortcuts', 'carve-markup' ), initialOpen: false },
					el(
						'ul',
						{ className: 'wp-carve-cheat' },
						[
							[ 'Ctrl/Cmd + B', __( 'Strong', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + I', __( 'Emphasis', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + U', __( 'Underline', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + E', __( 'Inline code', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + K', __( 'Link', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + .', __( 'Superscript', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + ,', __( 'Subscript', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + 1…6', __( 'Heading level', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + X', __( 'Strikethrough', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + H', __( 'Highlight', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + E', __( 'Code block', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + .', __( 'Blockquote', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + 8', __( 'Bullet list', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + 7', __( 'Ordered list', 'carve-markup' ) ],
							[ 'Ctrl/Cmd + Shift + I', __( 'Image', 'carve-markup' ) ],
							[ 'Tab / Shift + Tab', __( 'Indent / outdent', 'carve-markup' ) ],
						].map( ( [ keys, label ], i ) =>
							el( 'li', { key: i }, el( 'code', null, keys ), ' — ' + label )
						)
					)
				)
			),
			tabs,
			ingest &&
				el(
					Notice,
					{ status: 'info', isDismissible: true, onRemove: () => setIngest( null ) },
					__( 'Pasted content looks like Markdown/Djot/HTML/BBCode.', 'carve-markup' ),
					' ',
					el( Button, { variant: 'primary', size: 'small', onClick: doPasteIngest }, __( 'Convert to Carve', 'carve-markup' ) )
				),
			body,
			tableOpen &&
				el(
					Modal,
					{ title: __( 'Insert table', 'carve-markup' ), onRequestClose: () => setTableOpen( false ) },
					el( RangeControl, { label: __( 'Columns', 'carve-markup' ), value: cols, min: 1, max: 8, onChange: setCols } ),
					el( RangeControl, { label: __( 'Rows', 'carve-markup' ), value: rows, min: 1, max: 20, onChange: setRows } ),
					el(
						Button,
						{ variant: 'primary', onClick: () => { blockInsert( buildTable( cols, rows ) ); setTableOpen( false ); } },
						__( 'Insert', 'carve-markup' )
					)
				),
			importOpen &&
				el(
					Modal,
					{ title: __( 'Import & convert', 'carve-markup' ), onRequestClose: () => setImportOpen( false ) },
					el( SelectControl, {
						label: __( 'Source format', 'carve-markup' ),
						value: importFrom,
						options: [
							{ label: __( 'Auto-detect', 'carve-markup' ), value: 'auto' },
							{ label: 'Markdown', value: 'markdown' },
							{ label: 'Djot', value: 'djot' },
							{ label: 'BBCode', value: 'bbcode' },
							{ label: 'HTML', value: 'html' },
						],
						onChange: setImportFrom,
						__nextHasNoMarginBottom: true,
					} ),
					el( TextareaControl, {
						label: __( 'Paste source to convert', 'carve-markup' ),
						value: importText,
						onChange: setImportText,
						rows: 8,
						__nextHasNoMarginBottom: true,
					} ),
					el(
						Button,
						{ variant: 'primary', disabled: ! importText, onClick: doImport },
						__( 'Convert & insert', 'carve-markup' )
					)
				)
		);
	}

	registerBlockType( 'carve/markup', {
		edit: Edit,
		save: () => null, // dynamic (server-rendered)
	} );

	function SlidesEdit( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps( {
			className: 'wp-carve-slides-editor',
		} );

		return el(
			'div',
			blockProps,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Presentation', 'carve-markup' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Theme', 'carve-markup' ),
						value: attributes.theme || 'signal',
						options: [
							{ label: __( 'Signal', 'carve-markup' ), value: 'signal' },
							{ label: __( 'Paper', 'carve-markup' ), value: 'paper' },
							{ label: __( 'Night', 'carve-markup' ), value: 'night' },
						],
						onChange: ( theme ) => setAttributes( { theme } ),
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label: __( 'Layout', 'carve-markup' ),
						value: attributes.layout || 'standard',
						options: [
							{ label: __( 'Standard', 'carve-markup' ), value: 'standard' },
							{ label: __( 'Wide', 'carve-markup' ), value: 'wide' },
							{ label: __( 'Compact', 'carve-markup' ), value: 'compact' },
						],
						onChange: ( layout ) => setAttributes( { layout } ),
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el( TextareaControl, {
				label: __( 'Carve slide source', 'carve-markup' ),
				help: __( 'Separate slides with a standalone --- line.', 'carve-markup' ),
				value: attributes.carve || '',
				onChange: ( carve ) => setAttributes( { carve } ),
				rows: 14,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				'div',
				{ className: 'wp-carve-slides-preview-frame' },
				el( ServerSideRender, {
					block: 'carve/slides',
					attributes,
				} )
			)
		);
	}

	registerBlockType( 'carve/slides', {
		edit: SlidesEdit,
		save: () => null,
	} );
} )( window.wp, window.wpCarve );
