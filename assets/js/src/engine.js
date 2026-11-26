// In-browser Carve 0.2 engine for instant editor preview and the workbench.
// Bundled by `npm run build` into ../vendor/carve.js (IIFE), exposing
// `window.wpCarveEngine.carveToHtml`. The plugin loads it before the editor
// script; when it is absent the editor falls back to the REST endpoint.
import { render, renderWithDocumentTransforms, lint, parse, encodeTree, stringOffsetOfByte, tabsExtensions, tabsRenderer } from '@markup-carve/carve'
import { codeGroup, details, imgFence, listTable, semanticSpan, smartQuotes, spoiler, tabNormalize } from '@markup-carve/carve/extensions'
import { analyzeDocument, semanticChanges } from './workbench.js'

// The content extensions by the names of WpCarve\Extensions::CATALOG, so the
// preview registers what the server render registers.
const EXTENSIONS = {
  tabs: () => ({ extensions: tabsExtensions, renderers: { 'carve.tabs': tabsRenderer } }),
  details,
  spoiler,
  codeGroup,
  listTable,
  // The server registers the `img` fence only.
  imgFence: () => {
    const bundle = imgFence()
    return {
      extensions: bundle.extensions.filter((d) => d.name === 'carve.imgFence.img'),
      renderers: { 'carve.imgFence.img': bundle.renderers['carve.imgFence.img'] },
    }
  },
  semanticSpan,
}

export const EXTENSION_NAMES = Object.keys(EXTENSIONS)

// The preview matches the PHP post render: heading ids on the headings
// (`sections: false`), and the typography settings the block passes in.
// Settings-dependent extras (TOC, permalinks, heading shift) stay server-side.
export function renderOptions(settings = {}) {
  const names = Array.isArray(settings.extensions) ? settings.extensions : EXTENSION_NAMES
  const bundles = names.filter((name) => Object.hasOwn(EXTENSIONS, name)).map((name) => EXTENSIONS[name]())
  const smart = Boolean(settings.smartQuotes)
  if (smart && settings.quoteLocale) {
    bundles.push(smartQuotes({ locale: String(settings.quoteLocale) }))
  }
  const options = {
    sections: false,
    smartTypography: smart,
    extensions: bundles.flatMap((b) => b.extensions),
    renderers: Object.assign({}, ...bundles.map((b) => b.renderers)),
  }
  if (settings.softBreak === 'space' || settings.softBreak === 'br') {
    options.softBreak = settings.softBreak
  }
  const transforms = Number.isInteger(settings.tabWidth) ? tabNormalize(settings.tabWidth).transforms ?? [] : []
  return { options, transforms }
}

export function carveToHtml(source, settings) {
  const { options, transforms } = renderOptions(settings)
  return transforms.length > 0
    ? renderWithDocumentTransforms(String(source ?? ''), transforms, options).html
    : render(String(source ?? ''), options)
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
  // `settings`: the enabled extension names and typography settings the
  // plugin localizes as `wpCarve.preview`; all extensions when omitted.
  carveToHtml,
  extensionNames: EXTENSION_NAMES,
}
