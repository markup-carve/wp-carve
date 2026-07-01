/**
 * Carve visual (WYSIWYG) editor - foundation.
 *
 * A Tiptap-based editor that edits rendered Carve HTML and serializes back to
 * Carve markup on every change. This module + its imports are bundled locally
 * with esbuild (assets/js/vendor/carve-editor.js) - no CDN at runtime.
 *
 * Round-trip note: the editor is seeded with HTML (rendered from Carve by the
 * server/JS engine) and serializes the edited document back to Carve via
 * serializeToCarve(). Core constructs round-trip cleanly; carve-specific
 * containers are covered incrementally by the extensions in ./extensions.
 */

import { Editor } from '@tiptap/core';
import { buildCarveExtensions } from './carve-kit.js';
import { serializeToCarve } from './serializer.js';

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
export async function initVisualEditor( container, initialHtml, onChange ) {
	const extensions = buildCarveExtensions( {} );

	container.innerHTML = '';

	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wp-carve wp-carve-ve-surface';
	container.appendChild( surfaceEl );

	const editor = new Editor( {
		element: surfaceEl,
		extensions,
		content: initialHtml || '<p></p>',
		onUpdate: ( { editor: ed } ) => {
			if ( onChange ) {
				onChange( serializeToCarve( ed.getJSON() ) );
			}
		},
	} );

	return {
		editor,
		getCarve: () => serializeToCarve( editor.getJSON() ),
		setHtml: ( html ) => editor.commands.setContent( html || '<p></p>' ),
		destroy: () => editor.destroy(),
	};
}

export default initVisualEditor;
