# Visual editor (foundation)

The plugin ships a **foundation** of a Tiptap-based WYSIWYG editor for the Carve
block. It is opt-in (`visual_editor_mode` setting, off by default) and experimental:
the core constructs work and round-trip to Carve; the long tail of
carve-specific constructs is being filled in incrementally.

## Enabling it

Set **Settings → Carve Markup → Visual editor** to `enabled` (or
`enabled_default` to open blocks in Visual). The Carve block then shows a
**Visual** tab alongside Write / Split / Preview. Write/Split are the source
textarea + live preview; Visual mounts the Tiptap editor.

## How it works

- The editor core (extension kit + serializer) is the org's **shared
  `carve-grammars/tiptap`** package (`CarveKit` + `serializeToCarve`), the same
  one `carve-wysiwyg` uses. wp-carve only adds a keyboard map and an empty-state
  placeholder on top.
- It's **bundled locally** with esbuild into `assets/js/vendor/carve-editor.js`
  (run `npm run build:editor`; `npm run build` builds both the engine and the
  editor). No CDN at runtime. The bundle is **lazy-loaded** via dynamic
  `import()` only when the user switches to Visual mode.
- The editor is seeded with HTML rendered from the current Carve source (via the
  JS engine or the REST render endpoint).
- On every edit the document is serialized back to **Carve markup**
  (`serializeToCarve()`) and stored as the block's `carve` attribute. Source mode
  always reflects the canonical Carve.
- The toolbar is the **WordPress block toolbar** (same as Write mode, only the
  actions differ - Tiptap commands here vs source inserts in Write): headings,
  strong/emphasis/underline/inline-code, link, image, lists, quote, table, code
  block, admonitions, media embed, footnote, math, and clear formatting.
- **Distraction-free**: a full-screen toggle in the mode bar expands the editor
  over the whole viewport (Write/Split).

## Lossy round-trip warning

Because the editor serializes rendered HTML back to Carve, a few constructs
still don't survive exactly. On entering Visual mode the block round-trips the
current source (seed -> serialize). Pure whitespace / reflow is ignored; only
real content drift counts. If something would change, a **modal blocks entry**
and shows a line diff of what would be affected - you either **Edit in Visual
anyway** (approved once for the session) or go **Back to Write** to keep it
exact. When nothing would change, Visual mode opens straight away.

## Architecture

| File | Role |
| --- | --- |
| `carve-grammars/tiptap` (npm) | **Shared** `CarveKit` extension bundle + `serializeToCarve` - the round-trip core, maintained upstream and used by carve-wysiwyg too. |
| `assets/js/tiptap/visual-editor.js` | wp-carve editor shell: mounts Tiptap with `CarveKit` + the keymap, wires change events. |
| `assets/js/tiptap/extensions/carve-keymap.js` | wp-carve-local keyboard map (Ctrl/Cmd+1-6 headings, clear, Enter reset). |
| `assets/blocks/carve/index.js` | The block UI: mode tabs, WordPress block toolbar, gating modal, context controls. |

## Coverage

Round-trip coverage is owned by `carve-grammars/tiptap` (headings, marks incl.
critic insert/delete, lists incl. tasks, blockquotes, code blocks, tables with
alignment, admonition divs, math, footnotes, definition lists, spans,
abbreviations). Constructs it can't round-trip exactly are caught by the warning
above rather than silently changed. New constructs are added **upstream** in
`carve-grammars` so every consumer (wp-carve, carve-wysiwyg, the playground)
benefits.

## Extending

Add or improve editor constructs in the shared **`carve-grammars`** repo
(`tiptap/extensions/` + the serializer). wp-carve picks them up on the next
`carve-grammars` bump + `npm run build:editor`. wp-carve-local additions are
limited to WordPress glue (the block UI, keymap, gating).
