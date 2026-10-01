// Innovation A: in-browser Carve engine for instant editor preview.
// Bundled by `npm run build` into ../vendor/carve.js (IIFE), exposing
// `window.wpCarveEngine.carveToHtml`. The plugin loads it before the editor
// script; when it is absent the editor falls back to the REST endpoint.
import { carveToHtml, tabs, details, spoiler, codeGroup, citations, imgFence, listTable, semanticSpan, lintCarve, parse, toAstJson, diffAst } from '@markup-carve/carve'
import { analyzeDocument, semanticChanges } from './workbench.js'

// The block preview must match the published front-end render, so every content
// extension the PHP post path enables unconditionally is enabled here:
// CodeGroup, Tabs, Details and Spoiler, plus ImgFence, ListTable and
// SemanticSpan. Without them the preview showed raw markup where the front end
// rendered the real thing - a `<div class="tab">` for tabs and details, a
// `<pre><code class="language-img">` of escaped SVG source for an img fence, a
// nested bullet list for a list-table, and `<span class="ext-samp">` instead of
// `<samp>` for the deprecated `:samp[...]` spelling.
//
// Settings-dependent, non-round-trippable extras (TOC, permalinks, heading
// shift, smart quotes) stay out - they need PHP settings and are added
// server-side only. This does not render the visual-editor seed either: the
// block takes the in-browser path for the 'post' context only, and the
// 'editor' context falls through to the server render, which is where PHP
// drops what cannot round-trip.
const EXTENSIONS = [
  codeGroup(),
  tabs(),
  details(),
  spoiler(),
  imgFence(),
  listTable(),
  semanticSpan(),
]

window.wpCarveEngine = {
  citations,
  lintCarve,
  parse,
  toAstJson,
  diffAst,
  analyzeDocument(source, bibliography = '') {
    return analyzeDocument(String(source ?? ''), window.wpCarveEngine, bibliography)
  },
  semanticChanges(before, after) {
    return semanticChanges(String(before ?? ''), String(after ?? ''), window.wpCarveEngine)
  },
  carveToHtml(source, options = {}) {
    const bibliography = Array.isArray(options.bibliography) ? options.bibliography : []
    const citationMode = options.citationMode === 'author-date' ? 'author-date' : 'numbered'
    return carveToHtml(String(source ?? ''), { extensions: [...EXTENSIONS, citations({ mode: citationMode, bibliography })] })
  },
}
