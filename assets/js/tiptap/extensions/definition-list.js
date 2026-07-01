import { Node } from 'https://esm.sh/@tiptap/core@2';

/**
 * Carve definition lists. carve-php renders:
 *   <dl><dt>Term</dt><dd>Definition</dd></dl>
 * Carve source (§4.5):
 *   :: Term
 *   :  Definition
 */
export const DefinitionList = Node.create( {
	name: 'definitionList',
	group: 'block',
	content: '(definitionTerm | definitionDescription)+',
	parseHTML() {
		return [ { tag: 'dl' } ];
	},
	renderHTML() {
		return [ 'dl', 0 ];
	},
} );

export const DefinitionTerm = Node.create( {
	name: 'definitionTerm',
	content: 'inline*',
	defining: true,
	parseHTML() {
		return [ { tag: 'dt' } ];
	},
	renderHTML() {
		return [ 'dt', 0 ];
	},
} );

export const DefinitionDescription = Node.create( {
	name: 'definitionDescription',
	content: 'block+',
	defining: true,
	parseHTML() {
		return [ { tag: 'dd' } ];
	},
	renderHTML() {
		return [ 'dd', 0 ];
	},
} );

export default DefinitionList;
