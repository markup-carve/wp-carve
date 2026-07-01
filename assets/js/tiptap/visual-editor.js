/**
 * Carve visual (WYSIWYG) editor - foundation.
 *
 * A Tiptap-based editor that edits rendered Carve HTML and serializes back to
 * Carve markup on every change. Tiptap is imported from the esm.sh CDN at
 * runtime, so the editor works without bundling Tiptap locally.
 *
 * Round-trip note: the editor is seeded with HTML (rendered from Carve by the
 * server/JS engine) and serializes the edited document back to Carve via
 * serializeToCarve(). Core constructs round-trip cleanly; carve-specific
 * containers are covered incrementally by the extensions in ./extensions.
 */

// Version query this module was loaded with (e.g. "?ver=123"); forwarded to
// sibling imports so the whole tiptap ES-module graph busts together.
const MODULE_VER = new URL( import.meta.url ).search;

let editorInstance = null;
let serializeToCarve = null;

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
	const { Editor } = await import( 'https://esm.sh/@tiptap/core@2' );
	const { buildCarveExtensions } = await import( './carve-kit.js' + MODULE_VER );
	( { serializeToCarve } = await import( './serializer.js' + MODULE_VER ) );
	const extensions = await buildCarveExtensions( { ver: MODULE_VER } );

	if ( editorInstance ) {
		editorInstance.destroy();
		editorInstance = null;
	}

	container.innerHTML = '';

	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wp-carve wp-carve-ve-surface';
	container.appendChild( surfaceEl );

	editorInstance = new Editor( {
		element: surfaceEl,
		extensions,
		content: initialHtml || '<p></p>',
		onUpdate: ( { editor } ) => {
			if ( onChange ) {
				onChange( serializeToCarve( editor.getJSON() ) );
			}
		},
	} );

	return {
		editor: editorInstance,
		getCarve: () => serializeToCarve( editorInstance.getJSON() ),
		setHtml: ( html ) => editorInstance.commands.setContent( html || '<p></p>' ),
		destroy: () => {
			if ( editorInstance ) {
				editorInstance.destroy();
				editorInstance = null;
			}
		},
	};
}

export default initVisualEditor;
