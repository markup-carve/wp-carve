import test from 'node:test'
import assert from 'node:assert/strict'
import { parse, lintCarve, citations, toAstJson, diffAst } from '@markup-carve/carve'
import { analyzeDocument, bibliographyEntries, semanticChanges } from '../../assets/js/src/workbench.js'

const engine = { parse, lintCarve, citations, toAstJson, diffAst }

test('builds an outline with source locations', () => {
	const result = analyzeDocument('# One\n\n## Two', engine)
	assert.deepEqual(result.outline, [
		{ level: 1, text: 'One', line: 1 },
		{ level: 2, text: 'Two', line: 3 },
	])
})

test('reports citations missing from both document and CSL bibliography', () => {
	const result = analyzeDocument('See [@known] and [@missing].', engine, '[{"id":"known","title":"A"}]')
	assert.deepEqual(result.diagnostics.filter((d) => d.rule === 'unresolved-citation').map((d) => d.message), [
		'Citation “missing” has no in-document or CSL-JSON entry.',
	])
})

test('validates CSL entry ids and detects duplicates', () => {
	assert.throws(() => bibliographyEntries('[{"title":"No id"}]'), /needs a non-empty string id/)
	assert.throws(() => bibliographyEntries('[{"id":"a"},{"id":"a"}]'), /Duplicate/)
})

test('produces structural rather than line-oriented changes', () => {
	const changes = semanticChanges('# Old', '# New', engine)
	assert.ok(changes.some((change) => change.type === 'text' || change.type === 'heading'))
})

test('keeps the severity the engine linter assigns and defaults the rest to warning', () => {
	const stub = {
		...engine,
		lintCarve: () => [
			{ line: 1, column: 1, rule: 'thematic-break-spelling', message: 'hint', severity: 'Hint' },
			{ line: 2, column: 1, rule: 'plain', message: 'plain' },
		],
	}
	const severities = analyzeDocument('Text', stub).diagnostics.map((d) => [d.rule, d.severity])
	assert.deepEqual(severities, [['thematic-break-spelling', 'Hint'], ['plain', 'warning']])
})
