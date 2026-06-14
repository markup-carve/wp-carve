/* global wp, wpCarve */
( function ( wp, cfg ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, useState, useEffect, useRef } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { TextareaControl, Notice, Button, PanelBody, SelectControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	cfg = cfg || {};

	// Innovation A: prefer the in-browser Carve engine for instant preview.
	// `window.wpCarveEngine` is set by the optional carve-js module bundle
	// (assets/js/vendor/carve.js). Falls back to the REST render endpoint.
	function renderPreview( source, done ) {
		const engine = window.wpCarveEngine;
		if ( cfg.livePreview && engine && typeof engine.carveToHtml === 'function' ) {
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
			data: { carve: source, context: 'post' },
		} )
			.then( ( res ) => done( res.html || '' ) )
			.catch( () => done( '' ) );
	}

	// Foundation: optional Tiptap visual editor. Lazy-loaded as an ES module
	// (assets/js/tiptap/visual-editor.js) only when the user switches to Visual
	// mode, so the CDN-backed Tiptap bundle never loads for source-only editing.
	function VisualMode( { attributes, setAttributes } ) {
		const hostRef = useRef( null );
		const ctlRef = useRef( null );

		useEffect( () => {
			let active = true;
			let ctl = null;
			// Seed the editor with HTML rendered from the current Carve source.
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
					} )
					.catch( () => {} );
			} );
			return () => {
				active = false;
				if ( ctl ) {
					ctl.destroy();
				}
			};
			// Mount once per Visual-mode entry; edits flow out via onChange.
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		return el( 'div', { className: 'wp-carve-ve', ref: hostRef } );
	}

	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps();
		const [ html, setHtml ] = useState( '' );
		const [ ingest, setIngest ] = useState( null );
		const [ visual, setVisual ] = useState( false );
		const timer = useRef( null );

		useEffect( () => {
			if ( visual ) {
				return undefined;
			}
			if ( timer.current ) {
				clearTimeout( timer.current );
			}
			timer.current = setTimeout( () => {
				renderPreview( attributes.carve || '', setHtml );
			}, 200 );
			return () => clearTimeout( timer.current );
		}, [ attributes.carve, visual ] );

		function onPaste( event ) {
			if ( ! cfg.pasteIngest ) {
				return;
			}
			const text = ( event.clipboardData || window.clipboardData ).getData( 'text' );
			// Only offer conversion when the paste smells like another format.
			if ( ! text || ! /[<\[]|\*\*|^#{1,6}\s/m.test( text ) ) {
				return;
			}
			setIngest( text );
		}

		function doIngest() {
			wp.apiFetch( {
				url: cfg.restIngest,
				method: 'POST',
				data: { source: ingest, from: 'auto' },
			} )
				.then( ( res ) => {
					setAttributes( { carve: ( attributes.carve || '' ) + res.carve } );
					setIngest( null );
				} )
				.catch( () => setIngest( null ) );
		}

		const modeToggle =
			cfg.visualEditor &&
			el(
				Button,
				{
					variant: 'secondary',
					isSmall: true,
					className: 'wp-carve-mode-toggle',
					onClick: () => setVisual( ! visual ),
				},
				visual
					? __( 'Source', 'carve-markup' )
					: __( 'Visual', 'carve-markup' )
			);

		return el(
			'div',
			blockProps,
			ingest &&
				el(
					Notice,
					{ status: 'info', isDismissible: true, onRemove: () => setIngest( null ) },
					__( 'Pasted content looks like Markdown/Djot/HTML/BBCode.', 'carve-markup' ),
					' ',
					el(
						Button,
						{ variant: 'primary', onClick: doIngest },
						__( 'Convert to Carve', 'carve-markup' )
					)
				),
			modeToggle,
			visual
				? el( VisualMode, { attributes, setAttributes } )
				: el( TextareaControl, {
						label: __( 'Carve source', 'carve-markup' ),
						value: attributes.carve || '',
						onChange: ( carve ) => setAttributes( { carve } ),
						rows: 10,
						onPaste,
						__nextHasNoMarginBottom: true,
				  } ),
			! visual &&
				el( 'div', {
					className: 'wp-carve wp-carve-preview',
					dangerouslySetInnerHTML: { __html: html },
				} )
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
