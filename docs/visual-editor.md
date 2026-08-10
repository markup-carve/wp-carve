# Visual editor

The plugin ships an opt-in Tiptap WYSIWYG editor for the Carve block
(`visual_editor_mode`, off by default). The shared Carve AST bridge maps rich
constructs into the editor and preserves unsupported subtrees as source.

## Enabling it

Set **Settings → Carve Markup → Visual editor** to `enabled` (or
`enabled_default` to open blocks in Visual). The Carve block then shows a
**Visual** tab alongside Write / Split / Preview. Write/Split are the source
textarea + live preview; Visual mounts the Tiptap editor.

## How it works

- The editor core (AST loader + extension kit + serializer) is the org's
  **shared `carve-grammars/tiptap`** package (`carveToProseMirror`, `CarveKit`
  and `serializeToCarve`), the same one `carve-wysiwyg` uses. wp-carve adds an
  empty-state placeholder and WordPress integration on top.
- It's **bundled locally** with esbuild into `assets/js/vendor/carve-editor.js`
  (run `npm run build:editor`; `npm run build` builds both the engine and the
  editor). No CDN at runtime. The bundle is **lazy-loaded** via dynamic
  `import()` only when the user switches to Visual mode.
- The editor is seeded directly from Carve source. Preservation mode keeps an
  untouched open/save byte-for-byte identical; it avoids the old HTML pivot
  where source-only attributes, comments and raw constructs could disappear.
- On every edit the document is serialized back to **Carve markup**
  (`serializeToCarve()`) and stored as the block's `carve` attribute. Source mode
  reflects the current Carve. If editing would change the rendered result, the
  approval dialog is shown before the editor becomes interactive.
- The toolbar is the **WordPress block toolbar** (same as Write mode, only the
  actions differ - Tiptap commands here vs source inserts in Write): headings,
  strong/emphasis/underline/inline-code, link, image, lists, quote, table, code
  block, admonitions, media embed, footnote, math, and clear formatting.
- **Distraction-free**: a full-screen toggle in the mode bar expands the editor
  over the whole viewport (Write/Split).

## Lossy round-trip warning

The AST bridge can preserve a construct as source without making every part of
it richly editable. On entering Visual mode the block compares the editable
serialization with the current source. Pure whitespace / reflow is ignored;
only rendered-content drift counts. If an edit could change content, a **modal blocks entry**
and shows a line diff of what would be affected - you either **Edit in Visual
anyway** (approved once for the session) or go **Back to Write** to keep it
exact. When nothing would change, Visual mode opens straight away.

## Architecture

| File | Role |
| --- | --- |
| `carve-grammars/tiptap` (npm) | Shared AST loader, `CarveKit`, preservation nodes, and serializer. |
| `assets/js/tiptap/visual-editor.js` | wp-carve editor shell: mounts Tiptap, retains exact untouched source, and wires change events. |
| `assets/blocks/carve/index.js` | The block UI: mode tabs, WordPress block toolbar, gating modal, context controls. |

## Coverage

Round-trip coverage is owned by `carve-grammars/tiptap` (headings, marks incl.
critic insert/delete, lists incl. tasks, blockquotes, code blocks, tables with
alignment, admonition divs, math, footnotes, definition lists, spans,
abbreviations, and nested containers - tabs, code groups and
admonition-in-admonition, whose fences widen so the outer never closes early).
Constructs it cannot edit without changing are caught by the warning above
rather than silently changed. New rich mappings are added **upstream** in `carve-grammars`
so every consumer (wp-carve, carve-wysiwyg, the playground) benefits.

The upstream mounted-editor corpus ratchet currently keeps rendered output
equivalent for 729 of 892 conformance documents on both Tiptap 2 and 3. The
remaining 163 are protected by this warning gate; most exercise deliberately
malformed or source/indentation-sensitive syntax rather than ordinary authoring
constructs.

## Extending

Add or improve editor constructs in the shared **`carve-grammars`** repo
(`tiptap/extensions/` + the serializer). wp-carve picks them up on the next
`carve-grammars` bump + `npm run build:editor`. wpcarve-local additions are
limited to WordPress glue (the block UI, keymap, gating).
