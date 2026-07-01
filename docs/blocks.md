# Carve blocks

The plugin registers two blocks under the **Text** category.

## Carve (`carve/markup`)

Writes raw Carve and renders it server-side. The editor has four modes, switched
with the tab bar at the top of the block:

- **Write** - the Carve source in a monospace editor.
- **Split** - source and a live preview side by side.
- **Visual** - a Tiptap WYSIWYG editor (only shown when `visual_editor_mode` is
  enabled; see [Visual editor](visual-editor.md)).
- **Preview** - the rendered result only.

The preview uses the in-browser Carve engine when the JS bundle is built
(instant, no request), otherwise the REST render endpoint.

### Toolbar

When the block is selected in Write/Split mode, the block toolbar offers:

- Heading (H1-H6), Strong (`*`), Emphasis (`/`), Underline (`_`), inline code
  (`` ` ``), link, image
- Lists (bullet / ordered / task), blockquote, table (rows x columns), code
  block, admonition (note / tip / info / warning / danger / success / example /
  quote), media embed (YouTube / Vimeo / auto URL), divider
- Footnote (`^[…]`), math (inline `` $`…` `` / display `` $$`…` ``), citation
  (`[@key]`), definition list (`:: term` / `:  definition`)
- **Import & convert** - paste Markdown, Djot, BBCode or HTML and convert it to
  Carve, inserted at the cursor

Keyboard shortcuts in the source editor: `Ctrl/Cmd+B` strong, `Ctrl/Cmd+I`
emphasis, `Ctrl/Cmd+U` underline, `Ctrl/Cmd+K` link.

> [!NOTE]
> Carve inline syntax differs from Markdown/Djot: `*strong*`, `/emphasis/`
> (italic) and `_underline_`.

The block inspector adds a word count, an Import shortcut, a Clear action and a
short syntax cheat sheet. Pasting another format into the source editor also
offers a one-click "Convert to Carve".

## Carve Slides (`carve/slides`)

A Carve deck rendered as a progressively-enhanced slide presentation; slides are
separated by a standalone `---` line. Theme (signal / paper / night) and layout
(standard / wide / compact) are set in the block inspector.
