/**
 * Carve Tiptap extension kit.
 *
 * Assembles the StarterKit plus the inline marks Carve needs (underline,
 * highlight, sub/superscript, link) and the carve-specific node extensions.
 * Tiptap is loaded from the esm.sh CDN at runtime, so no local npm build of
 * Tiptap is required for the editor foundation.
 */

import { CarveDiv } from './extensions/index.js';

/**
 * Build the ordered extension array for a Carve editor.
 *
 * @param {Object} options
 * @param {string} [options.placeholder] Placeholder text for an empty doc.
 * @return {Promise<Array>} Tiptap extensions.
 */
export async function buildCarveExtensions( options = {} ) {
	const [
		{ default: StarterKit },
		{ default: Underline },
		{ default: Highlight },
		{ default: Subscript },
		{ default: Superscript },
		{ default: Link },
		{ default: Placeholder },
	] = await Promise.all( [
		import( 'https://esm.sh/@tiptap/starter-kit@2' ),
		import( 'https://esm.sh/@tiptap/extension-underline@2' ),
		import( 'https://esm.sh/@tiptap/extension-highlight@2' ),
		import( 'https://esm.sh/@tiptap/extension-subscript@2' ),
		import( 'https://esm.sh/@tiptap/extension-superscript@2' ),
		import( 'https://esm.sh/@tiptap/extension-link@2' ),
		import( 'https://esm.sh/@tiptap/extension-placeholder@2' ),
	] );

	return [
		StarterKit.configure( {
			// StarterKit ships bold/italic/strike/code, lists, blockquote,
			// headings, code block, horizontal rule, hard break.
			heading: { levels: [ 1, 2, 3, 4, 5, 6 ] },
		} ),
		Underline,
		Highlight,
		Subscript,
		Superscript,
		Link.configure( { openOnClick: false } ),
		Placeholder.configure( { placeholder: options.placeholder || 'Start writing Carve…' } ),
		CarveDiv,
	];
}

export default buildCarveExtensions;
