import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import palette from '../assets/syntax-palette.json' with { type: 'json' }

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const file = resolve(root, 'assets/css/carve.css')
const source = readFileSync(file, 'utf8')
const start = '/* BEGIN generated Carve syntax palette */'
const end = '/* END generated Carve syntax palette */'
const vars = (colors) => Object.entries(colors).map(([key, value]) =>
  `  --wpcarve-syntax-${key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}: ${value};`).join('\n')
const block = `${start}
.wpcarve-highlight-editor, .wpcarve-document-workspace {
${vars(palette.light)}
}
.wpcarve-comment-highlight.is-dark,
:root[data-theme="dark"] :is(.wpcarve-highlight-editor:not(.wpcarve-comment-highlight), .wpcarve-document-workspace) {
${vars(palette.dark)}
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) :is(.wpcarve-highlight-editor:not(.wpcarve-comment-highlight), .wpcarve-document-workspace) {
${vars(palette.dark).split('\n').map((line) => `  ${line}`).join('\n')}
  }
}

.wpcarve-highlight-layer .token.list,
.wpcarve-document-workspace .wpcarve-token-list { color: var(--wpcarve-syntax-list); }
.wpcarve-highlight-layer .token.list .token.constant,
.wpcarve-document-workspace .wpcarve-token-list.wpcarve-token-constant { color: var(--wpcarve-syntax-task); }
.wpcarve-highlight-layer .token.definition-list,
.wpcarve-document-workspace .wpcarve-token-definition-list { color: inherit; }
.wpcarve-highlight-layer .token.definition-term,
.wpcarve-highlight-layer .token.definition-list > .token.punctuation,
.wpcarve-document-workspace .wpcarve-token-definition-term,
.wpcarve-document-workspace .wpcarve-token-definition-list.wpcarve-token-punctuation { color: var(--wpcarve-syntax-definition); }
.wpcarve-highlight-layer .token.code-block > .token.language,
.wpcarve-document-workspace .wpcarve-token-code-block.wpcarve-token-language { color: var(--wpcarve-syntax-language); }
.wpcarve-highlight-layer .token.code-block > .token.punctuation,
.wpcarve-document-workspace .wpcarve-token-code-block.wpcarve-token-punctuation { color: var(--wpcarve-syntax-muted); }
.wpcarve-highlight-layer .token.caption,
.wpcarve-document-workspace .wpcarve-token-caption { color: var(--wpcarve-syntax-caption-text); font-style: italic; }
.wpcarve-highlight-layer .token.caption > .token.punctuation,
.wpcarve-document-workspace .wpcarve-token-caption.wpcarve-token-punctuation { color: var(--wpcarve-syntax-caption-marker); font-weight: 700; font-style: normal; }
.wpcarve-highlight-layer .token.table,
.wpcarve-document-workspace .wpcarve-token-table { color: inherit; }
.wpcarve-highlight-layer .token.table-boundary,
.wpcarve-highlight-layer .token.table-separator,
.wpcarve-document-workspace :is(.wpcarve-token-table-boundary, .wpcarve-token-table-separator) { color: var(--wpcarve-syntax-table-boundary); }
.wpcarve-highlight-layer .token.table-header,
.wpcarve-document-workspace .wpcarve-token-table-header { color: var(--wpcarve-syntax-table-header); font-weight: 650; }
.wpcarve-highlight-layer :is(.token.table-operator, .token.table-alignment),
.wpcarve-document-workspace :is(.wpcarve-token-table-operator, .wpcarve-token-table-alignment) { color: var(--wpcarve-syntax-table-operator); font-weight: 650; }
.wpcarve-highlight-layer .token.table-span,
.wpcarve-document-workspace .wpcarve-token-table-span { color: var(--wpcarve-syntax-table-span); font-weight: 650; }
.wpcarve-highlight-layer :is(.token.table-continuation-marker, .token.table-continuation),
.wpcarve-document-workspace :is(.wpcarve-token-table-continuation-marker, .wpcarve-token-table-continuation) { color: var(--wpcarve-syntax-table-continuation); font-weight: 650; }
.wpcarve-document-workspace .wpcarve-token-table-boundary { color: var(--wpcarve-syntax-table-boundary); }
${end}`
const from = source.indexOf(start)
const to = source.indexOf(end)
if ((from < 0) !== (to < 0) || (from >= 0 && to < from)) throw new Error('carve.css has incomplete palette markers')
const expected = from < 0 ? `${source.trimEnd()}\n\n${block}\n` : source.slice(0, from) + block + source.slice(to + end.length)
if (process.argv.includes('--check')) {
  if (from < 0 || source !== expected) throw new Error('carve.css syntax palette differs from assets/syntax-palette.json')
} else {
  writeFileSync(file, expected)
}
