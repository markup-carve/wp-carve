// In-browser Carve 0.2 engine for instant editor preview and the workbench.
// Bundled by `npm run build` into ../vendor/carve.js (IIFE), exposing
// `window.wpCarveEngine.carveToHtml`. The plugin loads it before the editor
// script; when it is absent the editor falls back to the REST endpoint.
import { render, lint, parse, encodeTree, stringOffsetOfByte, tabsExtensions, tabsRenderer } from '@markup-carve/carve'
import { analyzeDocument, semanticChanges } from './workbench.js'

// The preview matches the PHP post render where carve-js 0.2 can: the standard
// tabs widget, heading ids on the headings (`sections: false`) and smart
// typography off, as the plugin setting defaults. The 0.1 details, spoiler,
// code-group, list-table, img-fence and semantic-span extensions are not in
// the 0.2 catalog; settings-dependent extras (TOC, permalinks, heading shift)
// stay server-side as before.
const RENDER_OPTIONS = {
  sections: false,
  smartTypography: false,
  extensions: tabsExtensions,
  renderers: { 'carve.tabs': tabsRenderer },
}

function position(source, byte) {
  const before = source.slice(0, stringOffsetOfByte(source, byte))
  const lines = before.split('\n')
  return { line: lines.length, column: lines[lines.length - 1].length + 1 }
}

export function lintCarve(source) {
  return lint(source).map((d) => ({
    ...(d.range && !d.file ? position(source, d.range.startOffset) : { line: 1, column: 1 }),
    rule: d.code,
    message: d.message,
    severity: d.severity,
  }))
}

export function toAstJson(tree) {
  return JSON.parse(encodeTree(tree))
}

window.wpCarveEngine = {
  lintCarve,
  parse: (source) => parse(String(source ?? '')),
  toAstJson,
  analyzeDocument(source, bibliography = '') {
    return analyzeDocument(String(source ?? ''), window.wpCarveEngine, bibliography)
  },
  semanticChanges(before, after) {
    return semanticChanges(String(before ?? ''), String(after ?? ''), window.wpCarveEngine)
  },
  // `options.bibliography` and `options.citationMode` are accepted for callers;
  // citation rendering waits for the 0.2 extension catalog (carve-php#5252).
  carveToHtml(source) {
    return render(String(source ?? ''), RENDER_OPTIONS)
  },
}
