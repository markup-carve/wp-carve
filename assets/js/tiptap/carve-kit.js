/**
 * Carve Tiptap extension kit.
 *
 * Assembles the StarterKit plus the inline marks Carve needs (underline,
 * highlight, sub/superscript, link) and the carve-specific node extensions.
 * Tiptap is bundled locally (esbuild) - no CDN at runtime.
 */

import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';
import {
	CarveDiv,
	CarveMath,
	DefinitionList,
	DefinitionTerm,
	DefinitionDescription,
	FootnoteRef,
	FootnoteSection,
	MediaEmbed,
	CarveKeymap,
} from './extensions/index.js';

/**
 * Build the ordered extension array for a Carve editor.
 *
 * @param {Object} options
 * @param {string} [options.placeholder] Placeholder text for an empty doc.
 * @return {Array} Tiptap extensions.
 */
export function buildCarveExtensions( options = {} ) {
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
		CarveKeymap,
	];
}

export default buildCarveExtensions;
