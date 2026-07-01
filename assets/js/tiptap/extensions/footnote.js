import { Node } from 'https://esm.sh/@tiptap/core@2';

/**
 * Footnotes. carve-php renders a use-site reference
 *   <a id="fnrefN" href="#fnN" role="doc-noteref"><sup>N</sup></a>
 * and, at the end of the document, an endnotes section
 *   <section role="doc-endnotes"><hr><ol><li id="fnN"><p>body<a role="doc-backlink">↩</a></p></li></ol></section>
 *
 * Carve source: `text[^N]` at the use site and `[^N]: body` for the definition.
 *
 * The section is modeled as an atom that carries the definitions as an
 * attribute, so the inner <hr>/<ol> is consumed (not re-serialized as a list)
 * and the footnotes round-trip. Footnote bodies round-trip as plain text;
 * inline formatting inside a body is not preserved.
 */
export const FootnoteRef = Node.create( {
	name: 'footnoteRef',
	group: 'inline',
	inline: true,
	atom: true,
	// Win over the Link mark / Superscript, which also match <a> / <sup>.
	priority: 1000,

	addAttributes() {
		return {
			label: { default: '1' },
		};
	},

	parseHTML() {
		return [
			{
				// Rule-level priority so this node wins over the Link mark and
				// Superscript, which also match <a> / <sup> (default priority 50).
				tag: 'a[role="doc-noteref"]',
				priority: 100,
				getAttrs: ( el ) => ( { label: ( el.textContent || '1' ).trim() || '1' } ),
			},
		];
	},

	renderHTML( { node } ) {
		return [
			'a',
			{ role: 'doc-noteref', href: '#fn' + node.attrs.label },
			[ 'sup', {}, String( node.attrs.label ) ],
		];
	},
} );

export const FootnoteSection = Node.create( {
	name: 'footnoteSection',
	group: 'block',
	atom: true,
	selectable: true,
	// Consume the whole endnotes subtree before generic hr/ol/li rules.
	priority: 200,

	addAttributes() {
		return {
			defs: {
				default: [],
				parseHTML: ( el ) => {
					const items = Array.from( el.querySelectorAll( 'li' ) );
					return items.map( ( li ) => {
						const id = li.getAttribute( 'id' ) || '';
						const label = id.replace( /^fn/, '' ) || String( items.indexOf( li ) + 1 );
						const backlink = li.querySelector( '[role="doc-backlink"]' );
						if ( backlink ) {
							backlink.remove();
						}
						return { label, body: ( li.textContent || '' ).trim() };
					} );
				},
			},
		};
	},

	parseHTML() {
		return [ { tag: 'section[role="doc-endnotes"]' } ];
	},

	renderHTML( { node } ) {
		const defs = Array.isArray( node.attrs.defs ) ? node.attrs.defs : [];
		return [
			'section',
			{ role: 'doc-endnotes', class: 'wp-carve-ve-footnotes', contenteditable: 'false' },
			[ 'hr', {} ],
			[
				'ol',
				{},
				...defs.map( ( d ) => [ 'li', { id: 'fn' + d.label }, String( d.body || '' ) ] ),
			],
		];
	},
} );

export default FootnoteSection;
