/* global wp, wpCarve */
( function ( wp, cfg ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement: el, useState, useEffect, useRef } = wp.element;
	const { useBlockProps } = wp.blockEditor;
	const { TextareaControl, Notice, Button } = wp.components;
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

	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps();
		const [ html, setHtml ] = useState( '' );
		const [ ingest, setIngest ] = useState( null );
		const timer = useRef( null );

		useEffect( () => {
			if ( timer.current ) {
				clearTimeout( timer.current );
			}
			timer.current = setTimeout( () => {
				renderPreview( attributes.carve || '', setHtml );
			}, 200 );
			return () => clearTimeout( timer.current );
		}, [ attributes.carve ] );

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
			el( TextareaControl, {
				label: __( 'Carve source', 'carve-markup' ),
				value: attributes.carve || '',
				onChange: ( carve ) => setAttributes( { carve } ),
				rows: 10,
				onPaste,
				__nextHasNoMarginBottom: true,
			} ),
			el( 'div', {
				className: 'wp-carve-preview',
				dangerouslySetInnerHTML: { __html: html },
			} )
		);
	}

	registerBlockType( 'carve/markup', {
		edit: Edit,
		save: () => null, // dynamic (server-rendered)
	} );
} )( window.wp, window.wpCarve );
