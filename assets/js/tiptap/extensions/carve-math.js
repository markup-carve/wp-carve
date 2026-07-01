import { Node } from '@tiptap/core';

/**
 * Inline math node. carve-php renders math as
 *   <span class="math inline">\(TEX\)</span>
 *   <span class="math display">\[TEX\]</span>
 * and Carve source is $`TEX` (inline) / $$`TEX` (display).
 */
function stripDelims( text ) {
	return ( text || '' )
		.trim()
		.replace( /^\\[([]/, '' )
		.replace( /\\[)\]]$/, '' )
		.trim();
}

export const CarveMath = Node.create( {
	name: 'carveMath',
	group: 'inline',
	inline: true,
	atom: true,

	addAttributes() {
		return {
			tex: { default: '' },
			display: { default: false },
		};
	},

	parseHTML() {
		return [
			{ tag: 'span.math.display', getAttrs: ( el ) => ( { display: true, tex: stripDelims( el.textContent ) } ) },
			{ tag: 'span.math.inline', getAttrs: ( el ) => ( { display: false, tex: stripDelims( el.textContent ) } ) },
		];
	},

	renderHTML( { node } ) {
		const display = !! node.attrs.display;
		const body = display ? '\\[' + node.attrs.tex + '\\]' : '\\(' + node.attrs.tex + '\\)';
		return [ 'span', { class: 'math ' + ( display ? 'display' : 'inline' ) }, body ];
	},
} );

export default CarveMath;
