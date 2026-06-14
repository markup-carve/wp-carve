# Visual editor (foundation)

The plugin ships a **foundation** of a Tiptap-based WYSIWYG editor for the Carve
block. It is opt-in (`visual_editor` setting, off by default) and experimental:
the core constructs work and round-trip to Carve; the long tail of
carve-specific constructs is being filled in incrementally.

## Enabling it

Turn on **Settings → Carve Markup → Visual editor**. The Carve block then shows a
**Visual / Source** toggle. Source mode is the existing textarea + live preview;
Visual mode mounts the Tiptap editor.

## How it works

- Tiptap loads from the [esm.sh](https://esm.sh) CDN at runtime, so no local
  Tiptap build is required. The editor module
  (`assets/js/tiptap/visual-editor.js`) is **lazy-loaded** only when the user
  switches to Visual mode.
- The editor is seeded with HTML rendered from the current Carve source (via the
  JS engine or the REST render endpoint).
- On every edit the document is serialized back to **Carve markup**
  (`serializeToCarve()`) and stored as the block's `carve` attribute. Source mode
  always reflects the canonical Carve.

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
horizontal rules, images, and `::: note` admonition divs.

**Not yet:** footnotes, definition lists, tables, tabs, code-groups, math,
frontmatter, attributes. These currently round-trip best edited in Source mode.
Add them by creating a node/mark extension under `extensions/`, a matching
`case` in `serializeToCarve()`, and an entry in `carve-kit.js` - see `CarveDiv`
for the pattern.

## Extending

```js
// assets/js/tiptap/extensions/carve-kbd.js
import { Mark } from 'https://esm.sh/@tiptap/core@2';

export const CarveKbd = Mark.create( {
	name: 'carveKbd',
	parseHTML() { return [ { tag: 'kbd' } ]; },
	renderHTML() { return [ 'kbd', 0 ]; },
} );
```

Register it in `carve-kit.js`, export it from `extensions/index.js`, and add the
serializer mapping. Marks apply innermost-to-outermost in `serializeInline()`.
