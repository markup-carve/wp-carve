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

- Tiptap is **bundled locally** with esbuild into
  `assets/js/vendor/carve-editor.js` (run `npm run build:editor`; `npm run build`
  builds both the engine and the editor). No CDN at runtime. The bundle is
  **lazy-loaded** via dynamic `import()` only when the user switches to Visual
  mode. Sources live under `assets/js/tiptap/` (build inputs, excluded from the
  dist).
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
| `assets/js/tiptap/visual-editor.js` | Editor shell: mounts Tiptap, toolbar, change wiring. |
| `assets/js/tiptap/carve-kit.js` | Assembles StarterKit + Carve marks/nodes. |
| `assets/js/tiptap/serializer.js` | Tiptap JSON -> Carve markup. |
| `assets/js/tiptap/extensions/` | Carve-specific node/mark extensions (e.g. `CarveDiv`). |

## Coverage

**Round-trips today:** headings, paragraphs, bold (`*`), italic (`/`), underline
(`_`), strike (`~`), superscript (`^`), subscript (`,,`), highlight (`==`), inline
code, links, bullet / ordered / task lists, blockquotes, fenced code blocks,
horizontal rules, images, admonition divs (all 8 types), inline / display math
(`` $`…` `` / `` $$`…` ``), definition lists, tables, and footnotes (ref +
definitions; footnote bodies round-trip as plain text), and media embeds
(`:youtube[ID]` / `:vimeo[ID]` round-trip exactly; `:media[URL]` canonicalizes to
the provider form - identical output, but the source text changes).

**Not yet:** tabs, code-groups, frontmatter, attributes, and formatted footnote
bodies. Anything that would not survive a round-trip is caught by the warning
below rather than silently changed. Add support by creating a node/mark
extension under `extensions/`, a matching `case` in `serializeToCarve()`, and an
entry in `carve-kit.js` - see `CarveDiv` / `CarveMath` for the pattern.

Every tiptap file carries a `?ver` query threaded through `import.meta.url`, so
editing any module busts the browser's ES-module cache for the whole graph.

## Extending

```js
// assets/js/tiptap/extensions/carve-kbd.js
import { Mark } from '@tiptap/core';

export const CarveKbd = Mark.create( {
	name: 'carveKbd',
	parseHTML() { return [ { tag: 'kbd' } ]; },
	renderHTML() { return [ 'kbd', 0 ]; },
} );
```

Register it in `carve-kit.js`, export it from `extensions/index.js`, and add the
serializer mapping. Marks apply innermost-to-outermost in `serializeInline()`.
