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

const TOOLBAR = [
	{ label: 'B', title: 'Bold', run: ( e ) => e.chain().focus().toggleBold().run() },
	{ label: 'I', title: 'Italic', run: ( e ) => e.chain().focus().toggleItalic().run() },
	{ label: 'U', title: 'Underline', run: ( e ) => e.chain().focus().toggleUnderline().run() },
	{ label: 'S', title: 'Strikethrough', run: ( e ) => e.chain().focus().toggleStrike().run() },
	{ label: 'H', title: 'Highlight', run: ( e ) => e.chain().focus().toggleHighlight().run() },
	{ label: 'x²', title: 'Superscript', run: ( e ) => e.chain().focus().toggleSuperscript().run() },
	{ label: 'x₂', title: 'Subscript', run: ( e ) => e.chain().focus().toggleSubscript().run() },
	{ label: '<>', title: 'Inline code', run: ( e ) => e.chain().focus().toggleCode().run() },
	{ sep: true },
	{ label: 'H1', title: 'Heading 1', run: ( e ) => e.chain().focus().toggleHeading( { level: 1 } ).run() },
	{ label: 'H2', title: 'Heading 2', run: ( e ) => e.chain().focus().toggleHeading( { level: 2 } ).run() },
	{ label: 'H3', title: 'Heading 3', run: ( e ) => e.chain().focus().toggleHeading( { level: 3 } ).run() },
	{ sep: true },
	{ label: '“ ”', title: 'Quote', run: ( e ) => e.chain().focus().toggleBlockquote().run() },
	{ label: '• List', title: 'Bullet list', run: ( e ) => e.chain().focus().toggleBulletList().run() },
	{ label: '1. List', title: 'Ordered list', run: ( e ) => e.chain().focus().toggleOrderedList().run() },
	{ label: 'Code', title: 'Code block', run: ( e ) => e.chain().focus().toggleCodeBlock().run() },
	{ label: '―', title: 'Divider', run: ( e ) => e.chain().focus().setHorizontalRule().run() },
	{ sep: true },
	{
		label: 'Link',
		title: 'Link',
		run: ( e ) => {
			const url = window.prompt( 'Link URL' );
			if ( url === null ) {
				return;
			}
			const chain = e.chain().focus();
			( url === '' ? chain.unsetLink() : chain.setLink( { href: url } ) ).run();
		},
	},
	{
		label: 'Image',
		title: 'Image',
		run: ( e ) => {
			const src = window.prompt( 'Image URL' );
			if ( ! src ) {
				return;
			}
			const alt = window.prompt( 'Alt text', '' ) || '';
			e.chain().focus().setImage( { src, alt } ).run();
		},
	},
	{ sep: true },
	{ label: 'Note', title: 'Note', run: ( e ) => e.chain().focus().toggleCarveDiv( { class: 'note' } ).run() },
	{ label: 'Tip', title: 'Tip', run: ( e ) => e.chain().focus().toggleCarveDiv( { class: 'tip' } ).run() },
	{ label: 'Warning', title: 'Warning', run: ( e ) => e.chain().focus().toggleCarveDiv( { class: 'warning' } ).run() },
	{ label: 'Danger', title: 'Danger', run: ( e ) => e.chain().focus().toggleCarveDiv( { class: 'danger' } ).run() },
	{ sep: true },
	{ label: '⨯ Clear', title: 'Clear formatting (Ctrl/Cmd+\\)', run: ( e ) => e.chain().focus().clearNodes().unsetAllMarks().run() },
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
	const { buildCarveExtensions } = await import( './carve-kit.js' + MODULE_VER );
	( { serializeToCarve } = await import( './serializer.js' + MODULE_VER ) );
	const extensions = await buildCarveExtensions( { ver: MODULE_VER } );

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
		if ( btn.sep ) {
			const s = document.createElement( 'span' );
			s.className = 'wp-carve-ve-sep';
			s.setAttribute( 'aria-hidden', 'true' );
			toolbarEl.appendChild( s );
			return;
		}
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
