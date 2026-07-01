/**
 * Carve Tiptap extension kit.
 *
 * Assembles the StarterKit plus the inline marks Carve needs (underline,
 * highlight, sub/superscript, link) and the carve-specific node extensions.
 * Tiptap is loaded from the esm.sh CDN at runtime, so no local npm build of
 * Tiptap is required for the editor foundation.
 */

// Version query forwarded from this module's URL so extension files bust with
// the rest of the tiptap graph.
const MODULE_VER = new URL( import.meta.url ).search;

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
		{ default: Image },
		{ default: Placeholder },
		{ default: Table },
		{ default: TableRow },
		{ default: TableHeader },
		{ default: TableCell },
	] = await Promise.all( [
		import( 'https://esm.sh/@tiptap/starter-kit@2' ),
		import( 'https://esm.sh/@tiptap/extension-underline@2' ),
		import( 'https://esm.sh/@tiptap/extension-highlight@2' ),
		import( 'https://esm.sh/@tiptap/extension-subscript@2' ),
		import( 'https://esm.sh/@tiptap/extension-superscript@2' ),
		import( 'https://esm.sh/@tiptap/extension-link@2' ),
		import( 'https://esm.sh/@tiptap/extension-image@2' ),
		import( 'https://esm.sh/@tiptap/extension-placeholder@2' ),
		import( 'https://esm.sh/@tiptap/extension-table@2' ),
		import( 'https://esm.sh/@tiptap/extension-table-row@2' ),
		import( 'https://esm.sh/@tiptap/extension-table-header@2' ),
		import( 'https://esm.sh/@tiptap/extension-table-cell@2' ),
	] );

	const V = MODULE_VER || options.ver || '';
	const [
		{ CarveDiv },
		{ CarveMath },
		{ DefinitionList, DefinitionTerm, DefinitionDescription },
		{ FootnoteRef, FootnoteSection },
		{ MediaEmbed },
	] = await Promise.all( [
		import( './extensions/carve-div.js' + V ),
		import( './extensions/carve-math.js' + V ),
		import( './extensions/definition-list.js' + V ),
		import( './extensions/footnote.js' + V ),
		import( './extensions/media-embed.js' + V ),
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
		Image,
		Placeholder.configure( { placeholder: options.placeholder || 'Start writing Carve…' } ),
		Table.configure( { resizable: false } ),
		TableRow,
		TableHeader,
		TableCell,
		CarveDiv,
		CarveMath,
		DefinitionList,
		DefinitionTerm,
		DefinitionDescription,
		FootnoteRef,
		FootnoteSection,
		MediaEmbed,
	];
}

export default buildCarveExtensions;
