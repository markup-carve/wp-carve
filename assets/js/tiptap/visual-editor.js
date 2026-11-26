/**
 * Carve visual (WYSIWYG) editor.
 *
 * A Tiptap editor on the Carve 0.2 editor kit (carve-grammars `./tiptap`): it is
 * loaded from the carve-js 0.2 tree and written back by the carve-js writer, so
 * divs, titles, attributes, fence info and task states keep their structure
 * after an edit. This module and its imports are bundled locally with esbuild
 * (assets/js/vendor/carve-editor.js) - no CDN at runtime.
 */

import { Editor, Extension } from '@tiptap/core';
import Placeholder from '@tiptap/extension-placeholder';
import * as carve from '@markup-carve/carve';
import { CarveKit, carveToProseMirror, proseMirrorToTree, serializeToCarve as writeCarve } from '@markup-carve/carve-grammars/tiptap';

const ADMONITION_KINDS = new Set( [ 'note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote' ] );

/**
 * The editor document of Carve 0.2 source.
 *
 * @param {string} source Carve source.
 * @return {Object} ProseMirror JSON.
 */
export function carveContent( source ) {
	return carveToProseMirror( String( source ?? '' ), { engine: carve } );
}

/**
 * Carve 0.2 source for an editor document.
 *
 * @param {Object} json   ProseMirror JSON (`editor.getJSON()`).
 * @param {string} source The source the document was loaded from; kept where the edit left it alone.
 * @return {string} Carve source.
 */
export function serializeToCarve( json, source ) {
	try {
		return writeCarve( json, { engine: carve, source } );
	} catch ( error ) {
		// A tree the writer cannot spell (text typed against a symbol) is written with its loss report dropped.
		if ( ! ( error instanceof carve.CarveWriteError ) ) {
			throw error;
		}
		return carve.writeWithDiagnostics( proseMirrorToTree( json ) ).source;
	}
}

/**
 * The Carve source of a media embed: YouTube and Vimeo by id, anything else by URL.
 *
 * @param {string} src Video URL.
 * @return {string} Carve source.
 */
export function embedSource( src ) {
	const url = String( src ?? '' );
	let parsed = null;
	try {
		parsed = new URL( url );
	} catch {
		return `:media[${ url }]`;
	}
	const host = parsed.hostname.replace( /^(?:www|m)\./, '' );
	const path = parsed.pathname.split( '/' ).filter( Boolean );
	let youtube = null;
	if ( host === 'youtu.be' ) {
		youtube = path[ 0 ];
	} else if ( host === 'youtube.com' ) {
		youtube = parsed.searchParams.get( 'v' ) ?? ( [ 'embed', 'shorts' ].includes( path[ 0 ] ) ? path[ 1 ] : null );
	}
	if ( youtube && /^[\w-]+$/.test( youtube ) ) {
		return `:youtube[${ youtube }]`;
	}
	if ( host === 'vimeo.com' || host === 'player.vimeo.com' ) {
		const id = path.find( ( part ) => /^\d+$/.test( part ) );
		if ( id ) {
			return `:vimeo[${ id }]`;
		}
	}
	return `:media[${ url }]`;
}

// The block toolbar's container, embed and insert commands, on the kit's nodes.
const WpCarveCommands = Extension.create( {
	name: 'wpcarveCommands',
	addCommands() {
		return {
			toggleCarveDiv: ( { class: name } = {} ) => ( { commands } ) => ( ADMONITION_KINDS.has( name )
				? commands.toggleWrap( 'carveAdmonition', { kind: name } )
				: commands.toggleWrap( 'carveDiv', { carve: name ? { attrs: { classes: [ name ] } } : null } ) ),
			setCarveEmbed: ( { src } ) => ( { commands } ) => commands.insertContent( carveContent( embedSource( src ) ).content?.[ 0 ]?.content ?? [] ),
			// One paragraph (an uncaptioned image) goes in at the caret, like the source editors insert it.
			insertCarve: ( source ) => ( { commands } ) => {
				const content = carveContent( source ).content ?? [];
				const inline = content.length === 1 && content[ 0 ].type === 'paragraph';
				return commands.insertContent( inline ? content[ 0 ].content ?? [] : content );
			},
		};
	},
} );

/**
 * Mount a Carve visual editor inside a container.
 *
 * The toolbar is NOT rendered here: the Carve block renders it through the
 * WordPress block toolbar (BlockControls) so Visual mode matches Write mode.
 * The returned `editor` instance is what those controls drive.
 *
 * @param {HTMLElement} container    Host element (cleared on mount).
 * @param {string}      initialCarve Carve source used to seed the editor.
 * @param {Function}    onChange     Receives Carve markup on every edit.
 * @return {Promise<Object>} Control object: { getCarve, getEditableCarve, setCarve, insertCarve, destroy, editor }.
 */
export async function initVisualEditor( container, initialCarve, onChange ) {
	container.innerHTML = '';

	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wpcarve wpcarve-ve-surface';
	container.appendChild( surfaceEl );

	let sourceSnapshot = initialCarve || '';
	const editor = new Editor( {
		element: surfaceEl,
		extensions: [
			CarveKit,
			WpCarveCommands,
			Placeholder.configure( { placeholder: 'Start writing Carve…' } ),
		],
		content: carveContent( sourceSnapshot ),
		onUpdate: () => {
			if ( onChange ) {
				onChange( serializeCurrent() );
			}
		},
	} );

	function serializeCurrent() {
		return serializeToCarve( editor.getJSON(), sourceSnapshot );
	}

	return {
		editor,
		getCarve: serializeCurrent,
		// What an edit of this document would write: the writer's output for the whole tree.
		getEditableCarve: () => serializeToCarve( editor.getJSON() ),
		setCarve: ( source ) => {
			sourceSnapshot = source || '';
			return editor.commands.setCarveContent( carveContent( sourceSnapshot ), { emitUpdate: false } );
		},
		insertCarve: ( source ) => editor.chain().focus().insertCarve( source ).run(),
		destroy: () => editor.destroy(),
	};
}

export default initVisualEditor;
