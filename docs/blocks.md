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
(instant, no request), otherwise the REST render endpoint. Either way it renders
the same content extensions the published post does, including tabs, details,
spoilers, code groups, img fences, list-tables and semantic spans. Extensions
that depend on a setting (table of contents, heading permalinks, heading shift,
smart quotes) are applied server-side only, so those appear in the published
post rather than in the instant preview.

### Toolbar

When the block is selected in Write/Split mode, the block toolbar offers:

- Heading (H1-H6), Strong (`*`), Emphasis (`/`), Underline (`_`), inline code
  (`` ` ``), link, image (opens the media library - see [Images](images.md))
- Lists (bullet / ordered / task), blockquote, table (rows x columns), code
  block, admonition (note / tip / info / warning / danger / success / example /
  quote), media embed (YouTube / Vimeo / auto URL), divider
- Footnote (`^[…]`), math (inline `` $`…` `` / display `` $$`…` ``), citation
  (`[@key]`), definition list (`:: term` / `:  definition`)
- **Import & convert** - paste Markdown, Djot, BBCode or HTML and convert it to
  Carve, inserted at the cursor

Keyboard shortcuts in the source editor: `Ctrl/Cmd+B` strong, `Ctrl/Cmd+I`
emphasis, `Ctrl/Cmd+U` underline, `Ctrl/Cmd+K` link.

The inline marks toggle. Clicking Strong on an already strong selection, or
pressing its shortcut again, removes the delimiters instead of adding a second
pair, and the selection stays on the text. This covers strong, emphasis,
underline, inline code, strike, highlight, superscript and subscript, in the
toolbar and through the keymap, and matches what the visual editor already did.
A doubled delimiter is literal text in Carve (`**text**` renders as five
characters, not as a mark), so the toggle leaves a longer run alone rather than
stripping a pair from it. With no selection, the caret inside a mark removes it
and the caret anywhere else inserts a wrapped placeholder.

> [!NOTE]
> Carve inline syntax differs from Markdown/Djot: `*strong*`, `/emphasis/`
> (italic) and `_underline_`.

The block inspector adds a word count, an Import shortcut, a Clear action and a
short syntax cheat sheet. It also includes the AST-aware [Carve Workbench](workbench.md):
document health, heading navigation, a command palette, semantic changes since
the last save, and a CSL-JSON citation library. Pasting another format into the source editor also
offers a one-click "Convert to Carve".

## Carve Slides (`carve/slides`)

A Carve deck rendered as a progressively-enhanced slide presentation; slides are
separated by a standalone `---` line. Theme (signal / paper / night) and layout
(standard / wide / compact) are set in the block inspector.
