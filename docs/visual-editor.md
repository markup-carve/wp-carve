# Visual editor

The plugin ships an opt-in Tiptap WYSIWYG editor for the Carve block
(`visual_editor_mode`, off by default). It runs on the Carve 0.2 Tiptap editor
kit from carve-grammars (`@markup-carve/carve-grammars/tiptap`).

## Enabling it

Set **Settings → Carve Markup → Visual editor** to `enabled` (or
`enabled_default` to open blocks in Visual). The Carve block then shows a
**Visual** tab alongside Write / Split / Preview. Write/Split are the source
textarea + live preview; Visual mounts the Tiptap editor.

## How it works

- The editor is Tiptap with the carve-grammars `CarveKit`: the standard nodes
  and marks (headings, lists, task lists, quotes, code blocks, tables, links,
  images) plus divs and admonitions with their titles and classes, figures and
  captions, definition lists, line blocks, math, footnotes and comments. It is
  loaded from the carve-js 0.2 tree of the block source.
- On every edit the editor document is turned back into the tree and written by
  the carve-js 0.2 writer, then stored as the block's `carve` attribute. An
  untouched document keeps its exact source, and after an edit the blocks the
  edit did not touch keep theirs.
- It's **bundled locally** with esbuild into `assets/js/vendor/carve-editor.js`
  (run `npm run build:editor`; `npm run build` builds both the engine and the
  editor). No CDN at runtime. The bundle is **lazy-loaded** via dynamic
  `import()` only when the user switches to Visual mode.
- The toolbar is the **WordPress block toolbar** (same as Write mode, only the
  actions differ). The admonition, details, spoiler and media-embed buttons
  wrap the selection in the matching container or insert `:youtube[id]`,
  `:vimeo[id]` or `:media[url]`.

## Lossy round-trip warning

An edited block is written in the writer's canonical spelling, which can differ
from the author's (heading markers, escapes, list numbering). On entering Visual
mode the block compares the editable serialization with the current source. Pure whitespace / reflow is ignored; only
rendered-content drift counts. If an edit could change content, a **modal
blocks entry** and shows a line diff of what would be affected - you either
**Edit in Visual anyway** (approved once for the session) or go **Back to
Write** to keep it exact.

## Architecture

| File | Role |
| --- | --- |
| `@markup-carve/carve/v2` (npm) | `parse()` reads the block source, `write()` writes the edited tree. |
| `@markup-carve/carve-grammars/tiptap` (npm) | `CarveKit`, `carveToProseMirror()` and `serializeToCarve()`: the 0.2 editor kit. |
| `assets/js/tiptap/visual-editor.js` | wp-carve editor shell: mounts Tiptap with the kit, keeps the loaded source, adds the block toolbar commands, and wires change events. |
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

Editor constructs belong in the shared **`carve-grammars`** editor kit.
wpcarve-local additions are limited to WordPress glue (the block UI, toolbar
commands, keymap, gating).
