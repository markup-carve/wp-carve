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

import { buildCarveExtensions } from './carve-kit.js';
import { serializeToCarve } from './serializer.js';

let editorInstance = null;

const TOOLBAR = [
	{ label: 'B', title: 'Bold', run: ( e ) => e.chain().focus().toggleBold().run() },
	{ label: 'I', title: 'Italic', run: ( e ) => e.chain().focus().toggleItalic().run() },
	{ label: 'U', title: 'Underline', run: ( e ) => e.chain().focus().toggleUnderline().run() },
	{ label: 'S', title: 'Strike', run: ( e ) => e.chain().focus().toggleStrike().run() },
	{ label: '<>', title: 'Code', run: ( e ) => e.chain().focus().toggleCode().run() },
	{ label: 'H2', title: 'Heading', run: ( e ) => e.chain().focus().toggleHeading( { level: 2 } ).run() },
	{ label: '“ ”', title: 'Quote', run: ( e ) => e.chain().focus().toggleBlockquote().run() },
	{ label: '• List', title: 'Bullet list', run: ( e ) => e.chain().focus().toggleBulletList().run() },
	{ label: '1. List', title: 'Ordered list', run: ( e ) => e.chain().focus().toggleOrderedList().run() },
	{ label: 'Note', title: 'Admonition', run: ( e ) => e.chain().focus().toggleCarveDiv( { class: 'note' } ).run() },
];

/**
 * Mount a Carve visual editor inside a container.
 *
 * @param {HTMLElement} container    Host element (cleared on mount).
 * @param {string}      initialHtml  Rendered-Carve HTML to seed the editor.
 * @param {Function}    onChange     Receives Carve markup on every edit.
 * @return {Promise<Object>} Control object: { getCarve, setHtml, destroy, editor }.
 */
export async function initVisualEditor( container, initialHtml, onChange ) {
	const { Editor } = await import( 'https://esm.sh/@tiptap/core@2' );
	const extensions = await buildCarveExtensions( {} );

	if ( editorInstance ) {
		editorInstance.destroy();
		editorInstance = null;
	}

	container.innerHTML = '';

	const toolbarEl = document.createElement( 'div' );
	toolbarEl.className = 'wp-carve-ve-toolbar';
	const surfaceEl = document.createElement( 'div' );
	surfaceEl.className = 'wp-carve wp-carve-ve-surface';
	container.appendChild( toolbarEl );
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

	TOOLBAR.forEach( ( btn ) => {
		const b = document.createElement( 'button' );
		b.type = 'button';
		b.textContent = btn.label;
		b.title = btn.title;
		b.addEventListener( 'click', ( ev ) => {
			ev.preventDefault();
			btn.run( editorInstance );
		} );
		toolbarEl.appendChild( b );
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
