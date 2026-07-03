/**
 * Carve visual (WYSIWYG) editor - foundation.
 *
 * A Tiptap-based editor that edits rendered Carve HTML and serializes back to
 * Carve markup on every change. This module + its imports are bundled locally
 * with esbuild (assets/js/vendor/carve-editor.js) - no CDN at runtime.
 *
 * Round-trip note: the editor is seeded with HTML (rendered from Carve by the
 * server/JS engine) and serializes the edited document back to Carve via
 * serializeToCarve(). The extension kit + serializer are the org's shared core
 * (carve-grammars/tiptap), used by carve-wysiwyg too; wpcarve only adds the
 * keyboard map + an empty-state placeholder on top.
 */

import { Editor } from '@tiptap/core';
import Placeholder from '@tiptap/extension-placeholder';
import { CarveKit, serializeToCarve } from 'carve-grammars/tiptap';

export { serializeToCarve };

/**
 * Mount a Carve visual editor inside a container.
 *
 * The toolbar is NOT rendered here: the Carve block renders it through the
 * WordPress block toolbar (BlockControls) so Visual mode matches Write mode.
 * The returned `editor` instance is what those controls drive.
 *
 * @param {HTMLElement} container    Host element (cleared on mount).
 * @param {string}      initialHtml  Rendered-Carve HTML to seed the editor.
 * @param {Function}    onChange     Receives Carve markup on every edit.
 * @return {Promise<Object>} Control object: { getCarve, setHtml, destroy, editor }.
 */
// Carve/HTML renderers emit code blocks as `<code>...\n</code>` (a trailing
// newline before the close). ProseMirror keeps that newline verbatim, so the
// editor shows a spurious blank last line in every code block. Strip it from
// the seed HTML (the serializer already drops it on the way out).
function trimCodeNewlines( html ) {
	return ( html || '' ).replace( /\n(<\/code>)/g, '$1' );
}

export async function initVisualEditor( container, initialHtml, onChange ) {
	// CarveKit already bundles the keymap (Ctrl/Cmd+1-6, clear, Enter reset).
	const extensions = [
		CarveKit,
		Placeholder.configure( { placeholder: 'Start writing Carve…' } ),
	];

	container.innerHTML = '';

	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wpcarve wpcarve-ve-surface';
	container.appendChild( surfaceEl );

	const editor = new Editor( {
		element: surfaceEl,
		extensions,
		content: trimCodeNewlines( initialHtml ) || '<p></p>',
		onUpdate: ( { editor: ed } ) => {
			if ( onChange ) {
				onChange( serializeToCarve( ed.getJSON() ) );
			}
		},
	} );

	return {
		editor,
		getCarve: () => serializeToCarve( editor.getJSON() ),
		setHtml: ( html ) => editor.commands.setContent( trimCodeNewlines( html ) || '<p></p>' ),
		destroy: () => editor.destroy(),
	};
}

export default initVisualEditor;
