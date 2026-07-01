import { Node, mergeAttributes } from '@tiptap/core';

/**
 * Carve generic-div / admonition container node.
 *
 * Serializes to a `::: class` … `:::` fenced div in Carve. This is the reference
 * carve-specific node extension; further constructs (footnotes, tabs, code
 * groups) follow the same shape.
 *
 *   editor.chain().focus().setCarveDiv({ class: 'warning' }).run()
 */
export const CarveDiv = Node.create( {
	name: 'carveDiv',

	group: 'block',

	content: 'block+',

	defining: true,

	addAttributes() {
		return {
			class: {
				default: null,
				parseHTML: ( element ) => {
					const explicit = element.getAttribute( 'data-carve-class' );
					if ( explicit ) {
						return explicit;
					}
					// carve-php renders admonitions as
					// <aside class="admonition TYPE">; keep the type, drop the
					// structural tokens.
					const rest = ( element.className || '' )
						.split( /\s+/ )
						.filter( ( c ) => c && c !== 'carve-div' && c !== 'admonition' );
					return rest[ 0 ] || null;
				},
				renderHTML: ( attributes ) => {
					if ( ! attributes.class ) {
						return {};
					}
					return { 'data-carve-class': attributes.class };
				},
			},
		};
	},

	parseHTML() {
		return [
			{ tag: 'div.carve-div' },
			// carve-php's canonical admonition output.
			{ tag: 'aside.admonition' },
			// Authored/legacy generic-div forms.
			{ tag: 'div.note' },
			{ tag: 'div.tip' },
			{ tag: 'div.info' },
			{ tag: 'div.warning' },
			{ tag: 'div.caution' },
			{ tag: 'div.danger' },
		];
	},

	renderHTML( { HTMLAttributes } ) {
		const classes = [ 'carve-div' ];
		if ( HTMLAttributes[ 'data-carve-class' ] ) {
			classes.push( HTMLAttributes[ 'data-carve-class' ] );
		}
		return [ 'div', mergeAttributes( HTMLAttributes, { class: classes.join( ' ' ) } ), 0 ];
	},

	addCommands() {
		return {
			setCarveDiv:
				( attributes ) =>
				( { commands } ) =>
					commands.wrapIn( this.name, attributes ),
			toggleCarveDiv:
				( attributes ) =>
				( { commands } ) =>
					commands.toggleWrap( this.name, attributes ),
			unsetCarveDiv:
				() =>
				( { commands } ) =>
					commands.lift( this.name ),
		};
	},
} );

export default CarveDiv;
