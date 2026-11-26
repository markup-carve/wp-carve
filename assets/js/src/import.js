// BBCode import for the block editor. Carve 0.2 imports BBCode in carve-js
// only, so the server answers 422 for it and the block converts here.
// Bundled by `npm run build:import` into ../vendor/carve-import.js (IIFE),
// exposing `window.wpCarveImport`.
import { importDocument } from '@markup-carve/carve/import'

// Same signal as PasteController::sniff(), which checks BBCode first.
const BBCODE = /\[(b|i|u|url|img|code|quote)\b/i

export function looksLikeBbcode(source) {
  return BBCODE.test(String(source ?? ''))
}

function fidelity(diagnostic) {
  if (String(diagnostic.code).includes('dropped')) return 'dropped'
  return diagnostic.severity === 'hint' ? 'normalized' : 'approximated'
}

// The response shape of POST carve/v1/ingest, so the block handles both alike.
export function importBbcode(source) {
  const result = importDocument(String(source ?? ''), { from: 'bbcode' })
  return {
    carve: result.source,
    from: 'bbcode',
    report: {
      schemaVersion: 2,
      source_format: 'bbcode',
      diagnostics: result.diagnostics.map((d) => ({
        code: d.code,
        message: d.message,
        severity: d.severity,
        fidelity: fidelity(d),
        element: d.foreignType ?? '',
      })),
    },
  }
}

if (typeof window !== 'undefined') {
  window.wpCarveImport = { importBbcode, looksLikeBbcode }
}
