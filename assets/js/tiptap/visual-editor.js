/**
 * Carve visual (WYSIWYG) editor.
 *
 * A Tiptap-based editor that loads Carve through the shared AST bridge and
 * serializes back to Carve markup on every change. This module and its imports are bundled locally
 * with esbuild (assets/js/vendor/carve-editor.js) - no CDN at runtime.
 *
 * The editor is seeded directly from the Carve AST. This preserves source
 * attributes and unsupported constructs that an HTML pivot cannot represent.
 * The extension kit, loader and serializer are shared with carve-wysiwyg;
 * wpcarve only adds the empty-state placeholder.
 */

import { Editor } from '@tiptap/core';
import Placeholder from '@tiptap/extension-placeholder';
import { CarveKit, carveToProseMirror, serializeToCarve } from '@markup-carve/carve-grammars/tiptap';

export { serializeToCarve };

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
function editorContent( source ) {
	return source ? carveToProseMirror( source, { unsupported: 'preserve' } ) : '<p></p>';
}

export async function initVisualEditor( container, initialCarve, onChange ) {
	// CarveKit already bundles the keymap (Ctrl/Cmd+1-6, clear, Enter reset).
	const extensions = [
		CarveKit,
		Placeholder.configure( { placeholder: 'Start writing Carve…' } ),
	];

	container.innerHTML = '';

	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wpcarve wpcarve-ve-surface';
	container.appendChild( surfaceEl );

	let content = editorContent( initialCarve );
	let sourceSnapshot = initialCarve || '';
	let documentSnapshot = '';
	const editor = new Editor( {
		element: surfaceEl,
		extensions,
		content,
		onUpdate: ( { editor: ed } ) => {
			if ( onChange ) {
				onChange( serializeCurrent() );
			}
		},
	} );
	documentSnapshot = JSON.stringify( editor.getJSON() );

	function serializeCurrent() {
		const doc = editor.getJSON();
		return JSON.stringify( doc ) === documentSnapshot ? sourceSnapshot : serializeToCarve( doc );
	}

	function serializeWithoutEnvelope() {
		const doc = editor.getJSON();
		return serializeToCarve( { ...doc, attrs: undefined } );
	}

	return {
		editor,
		getCarve: serializeCurrent,
		getEditableCarve: serializeWithoutEnvelope,
		setCarve: ( source ) => {
			content = editorContent( source );
			sourceSnapshot = source || '';
			const result = editor.commands.setContent( content, { emitUpdate: false } );
			documentSnapshot = JSON.stringify( editor.getJSON() );
			return result;
		},
		destroy: () => editor.destroy(),
	};
}

export default initVisualEditor;
