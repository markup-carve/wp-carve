# Visual editor

The plugin ships an opt-in Tiptap WYSIWYG editor for the Carve block
(`visual_editor_mode`, off by default). On Carve 0.2 it round-trips through
HTML: carve-grammars 0.2 has no Tiptap editor kit yet
([carve-grammars#906](https://github.com/markup-carve/carve-grammars/issues/906)).

## Enabling it

Set **Settings → Carve Markup → Visual editor** to `enabled` (or
`enabled_default` to open blocks in Visual). The Carve block then shows a
**Visual** tab alongside Write / Split / Preview. Write/Split are the source
textarea + live preview; Visual mounts the Tiptap editor.

## How it works

- The editor is Tiptap with its standard extensions (headings, marks, lists,
  task lists, quotes, code blocks, tables, links, images). It is seeded with the
  carve-js 0.2 `render()` of the block source.
- On every edit the editor HTML is converted back to Carve with the carve-js 0.2
  HTML importer (`importDocument(html, { from: 'html' })`) and stored as the
  block's `carve` attribute. An untouched document keeps its exact source.
- It's **bundled locally** with esbuild into `assets/js/vendor/carve-editor.js`
  (run `npm run build:editor`; `npm run build` builds both the engine and the
  editor). No CDN at runtime. The bundle is **lazy-loaded** via dynamic
  `import()` only when the user switches to Visual mode.
- The toolbar is the **WordPress block toolbar** (same as Write mode, only the
  actions differ). The admonition, details, spoiler and media-embed buttons do
  nothing in Visual mode until the 0.2 editor kit exists; use Write mode for
  those constructs.

## Lossy round-trip warning

HTML cannot carry everything Carve writes: div classes and titles, attributes,
fence info, task states beyond checked and unchecked, and the author's spelling
choices. On entering Visual mode the block compares the editable serialization
with the current source. Pure whitespace / reflow is ignored; only
rendered-content drift counts. If an edit could change content, a **modal
blocks entry** and shows a line diff of what would be affected - you either
**Edit in Visual anyway** (approved once for the session) or go **Back to
Write** to keep it exact.

## Architecture

| File | Role |
| --- | --- |
| `@markup-carve/carve/v2` (npm) | `render()` seeds the editor, `importDocument()` serializes it. |
| `assets/js/tiptap/visual-editor.js` | wp-carve editor shell: mounts Tiptap, retains exact untouched source, and wires change events. |
| `assets/blocks/carve/index.js` | The block UI: mode tabs, WordPress block toolbar, gating modal, context controls. |

## Native construct blocks

The inserter also provides focused blocks for admonitions, code groups, and
tables with row or column spans. Each stores the construct's Carve source and
uses the same server renderer as the general Carve block. This keeps advanced
syntax visible and copyable instead of hiding it in opaque block attributes.
Use the general Carve block when several constructs belong in one document.

Visual sessions are revision-safe: if Undo, a revision restore, or another
editor changes the block source while Visual mode is open, the stale visual
serialization is refused. The newer source remains intact and the writer is
asked to reopen Visual mode from that revision.

## Extending

Editor constructs belong in the shared **`carve-grammars`** editor kit once it
exists for 0.2 (carve-grammars#906). wpcarve-local additions are limited to
WordPress glue (the block UI, keymap, gating).
