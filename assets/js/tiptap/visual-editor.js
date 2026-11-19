/**
 * Carve visual (WYSIWYG) editor.
 *
 * A Tiptap-based editor seeded from the Carve 0.2 HTML render and serialized
 * back to Carve with the 0.2 HTML importer on every change. This module and its
 * imports are bundled locally with esbuild (assets/js/vendor/carve-editor.js) -
 * no CDN at runtime.
 *
 * Carve 0.2 has no Tiptap editor kit yet (carve-grammars `next2` removed the
 * 0.1 CarveKit and serializer), so constructs without an HTML equivalent come
 * back in the importer's spelling. Unchanged documents keep their source.
 */

import { Editor, Extension } from '@tiptap/core';
import Placeholder from '@tiptap/extension-placeholder';
import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import Table from '@tiptap/extension-table';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TableRow from '@tiptap/extension-table-row';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import Underline from '@tiptap/extension-underline';
import { importDocument, render } from '@markup-carve/carve/v2';

/**
 * Carve 0.2 source for editor HTML.
 *
 * @param {string} html Editor HTML.
 * @return {string} Carve source.
 */
export function serializeToCarve( html ) {
	return importDocument( String( html ?? '' ), { from: 'html' } ).source;
}

// The block toolbar still offers the 0.1 kit's div and embed commands; without
// that kit (carve-grammars#906) they do nothing instead of throwing.
const PendingKitCommands = Extension.create( {
	name: 'wpcarvePendingKitCommands',
	addCommands() {
		return {
			toggleCarveDiv: () => () => false,
			setCarveEmbed: () => () => false,
		};
	},
} );

function editorContent( source ) {
	return source ? render( source, { sections: false, smartTypography: false } ) : '<p></p>';
}

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
 * @return {Promise<Object>} Control object: { getCarve, setCarve, destroy, editor }.
 */
export async function initVisualEditor( container, initialCarve, onChange ) {
	const extensions = [
		StarterKit,
		Underline,
		Highlight,
		Subscript,
		Superscript,
		Link.configure( { openOnClick: false } ),
		Image,
		Table,
		TableRow,
		TableHeader,
		TableCell,
		TaskList,
		TaskItem.configure( { nested: true } ),
		PendingKitCommands,
		Placeholder.configure( { placeholder: 'Start writing Carve…' } ),
	];

	container.innerHTML = '';

	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wpcarve wpcarve-ve-surface';
	container.appendChild( surfaceEl );

	let sourceSnapshot = initialCarve || '';
	let documentSnapshot = '';
	const editor = new Editor( {
		element: surfaceEl,
		extensions,
		content: editorContent( initialCarve ),
		onUpdate: () => {
			if ( onChange ) {
				onChange( serializeCurrent() );
			}
		},
	} );
	documentSnapshot = JSON.stringify( editor.getJSON() );

	function serializeCurrent() {
		return JSON.stringify( editor.getJSON() ) === documentSnapshot ? sourceSnapshot : serializeToCarve( editor.getHTML() );
	}

	return {
		editor,
		getCarve: serializeCurrent,
		getEditableCarve: () => serializeToCarve( editor.getHTML() ),
		setCarve: ( source ) => {
			sourceSnapshot = source || '';
			const result = editor.commands.setContent( editorContent( source ), { emitUpdate: false } );
			documentSnapshot = JSON.stringify( editor.getJSON() );
			return result;
		},
		destroy: () => editor.destroy(),
	};
}

export default initVisualEditor;
