# Carve Documents

A Carve Document stores the entire post body as raw, portable `.crv` source.
It is the source-first alternative to placing a `carve/markup` block inside a
normal Gutenberg post.

Create one under **Posts → Add Carve Document**, or import an existing `.crv`,
Markdown, Djot, or HTML file under **Tools → Carve Import**. The editor provides:

- **Write** for the source editor and its formatting toolbar. Strong,
  emphasis, underline and inline code toggle: a second click removes the
  mark rather than doubling the delimiter. Every button leaves the caret where
  the next keystroke belongs, and never selects the markup it just inserted:
  selected text stays selected under its new delimiters, a bare caret lands
  between them, a list or heading prefix carries the selection along with the
  text it shifts, and a block template puts the caret on its empty body line.
  Enter in a list item continues the list, Enter on an empty item ends it,
  and Tab / Shift+Tab nest an item under the one above or move it back out;
  the rules are the block editor's (see [Blocks](blocks.md)).
- **Split** for viewport-height source and preview panes with bidirectional
  proportional scroll sync (enabled by default and independently toggleable).
- **Preview** for a full-width rendered preview.
- **Download .crv** for a lossless local copy.
- **Move into a Carve block** when the document needs to be composed with other
  Gutenberg blocks.
- **Move to Carve Document** in a Carve block's Tools sidebar saves current
  edits and reverses the operation. It is offered only when the post contains
  exactly one Carve block, so sibling blocks can never be discarded silently.

The toolbar inserts Carve syntax for strong/emphasis/underline, inline and
block code (labeled "Code" and "Code block"), links, images, quotes, lists,
tasks, tables, and footnotes. The Image button opens the media library; see
[Images](images.md). Direct source editing remains available for every Carve
construct.

Internally, Carve Documents use `_wpcarve_enabled = 1`. Content migration tools
must preserve this post meta along with `post_content`; the built-in importer and
exporter do so automatically.
