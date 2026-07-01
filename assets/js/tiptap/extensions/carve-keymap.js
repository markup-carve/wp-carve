import { Extension } from '@tiptap/core';

/**
 * Unified Carve keyboard shortcuts for the visual editor.
 *
 * Tiptap only binds Mod-Alt-1..6 for headings, so plain Ctrl/Cmd+1..6 fell
 * through to the browser (switching tabs). These bindings match the Write-mode
 * shortcuts, all return true so the browser default is prevented, and use
 * toggle* commands so pressing a heading level on an existing heading REPLACES
 * it (or toggles back to a paragraph) rather than stacking.
 */
export const CarveKeymap = Extension.create( {
	name: 'carveKeymap',

	addKeyboardShortcuts() {
		const editor = this.editor;
		const heading = ( level ) => () => editor.chain().focus().toggleHeading( { level } ).run();

		return {
			'Mod-1': heading( 1 ),
			'Mod-2': heading( 2 ),
			'Mod-3': heading( 3 ),
			'Mod-4': heading( 4 ),
			'Mod-5': heading( 5 ),
			'Mod-6': heading( 6 ),
			'Mod-e': () => editor.chain().focus().toggleCode().run(),
			'Mod-.': () => editor.chain().focus().toggleSuperscript().run(),
			'Mod-,': () => editor.chain().focus().toggleSubscript().run(),
			'Mod-Shift-x': () => editor.chain().focus().toggleStrike().run(),
			'Mod-Shift-h': () => editor.chain().focus().toggleHighlight().run(),
			'Mod-Shift-e': () => editor.chain().focus().toggleCodeBlock().run(),
			'Mod-Shift-.': () => editor.chain().focus().toggleBlockquote().run(),
			'Mod-Shift-8': () => editor.chain().focus().toggleBulletList().run(),
			'Mod-Shift-7': () => editor.chain().focus().toggleOrderedList().run(),
			// Clear formatting: node type back to paragraph + drop all marks.
			'Mod-\\': () => editor.chain().focus().clearNodes().unsetAllMarks().run(),
			// Enter at the END of any textblock starts a fresh, mark-free
			// paragraph - so heading, bold, italic, etc. all reset on a new line
			// instead of carrying over. Mid-block splits and lists keep the
			// default behavior.
			Enter: () => {
				const { $from, empty } = editor.state.selection;
				if ( ! empty ) {
					return false;
				}
				const parent = $from.parent;
				const atEnd = $from.parentOffset === parent.content.size;
				const isPlainTextblock = parent.isTextblock && parent.type.name !== 'codeBlock';
				// Only reset for a top-level block (directly under doc). Inside
				// lists, blockquotes or admonition containers, keep the default
				// split so the user can't get trapped and nesting stays intact.
				const topLevel = $from.depth === 1;
				if ( ! atEnd || ! isPlainTextblock || ! topLevel ) {
					return false;
				}
				return editor
					.chain()
					.insertContentAt( $from.after(), { type: 'paragraph' } )
					.setTextSelection( $from.after() + 1 )
					// Belt-and-suspenders: drop any stored marks so the new line is
					// not bold/italic/etc. even if the fresh paragraph inherited them.
					.command( ( { tr, dispatch } ) => {
						if ( dispatch ) {
							dispatch( tr.setStoredMarks( [] ) );
						}
						return true;
					} )
					.run();
			},
		};
	},
} );

export default CarveKeymap;
