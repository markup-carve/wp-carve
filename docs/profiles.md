# Profiles & rendering

`WpCarve\Converter` wraps carve-php's `CarveConverter`, building one converter
per context (`post` / `comment`) from the plugin settings.

## Content profiles

The table covers carve-php's block and inline profile vocabulary. Each cell
shows the output of a short source sample through `WpCarve\Converter`, including
WordPress `wp_kses`, with post context except for `comment`. “Literal” means the
source remains visible as text; “stripped” means the sampled construct disappears.
The browser request column marks constructs that can make the browser fetch
media or supporting assets. Links require a reader click and are not marked.
The `full`, `article`, and `none` profiles can emit remote images. With optional
features enabled, media embeds load provider frames, PlantUML fences use Kroki,
and math loads local
KaTeX assets. The `wpcarve_katex_base` filter can point those assets at a CDN.
The comment converter does not register media embeds or diagram renderers.

<!-- profile-capabilities:start -->
| Construct | `full` | `article` | `comment` | `minimal` | `none` | Browser request |
| --- | --- | --- | --- | --- | --- | --- |
| `paragraph` | renders | renders | renders | renders | renders | No |
| `heading` | renders | renders | literal | literal | renders | No |
| `code_block` | renders | renders | renders | literal | renders | Yes |
| `block_quote` | renders | renders | renders | literal | renders | No |
| `list` | renders | renders | renders | renders | renders | No |
| `list_item` | renders | renders | renders | renders | renders | No |
| `table` | renders | renders | literal | literal | renders | No |
| `table_row` | renders | renders | literal | literal | renders | No |
| `table_cell` | renders | renders | literal | literal | renders | No |
| `thematic_break` | renders | renders | stripped | stripped | renders | No |
| `div` | renders | renders | literal | literal | renders | No |
| `admonition` | renders | renders | literal | literal | renders | No |
| `directive` | renders | renders | stripped | stripped | renders | No |
| `raw_block` | renders | literal | literal | literal | renders | Yes |
| `footnote` | renders | renders | stripped | stripped | renders | No |
| `frontmatter` | stripped | stripped | stripped | stripped | stripped | No |
| `definition_list` | renders | renders | literal | literal | renders | No |
| `definition_term` | renders | renders | literal | literal | renders | No |
| `definition_description` | renders | renders | literal | literal | renders | No |
| `section` | literal | literal | literal | literal | literal | No |
| `line_block` | renders | renders | literal | literal | renders | No |
| `comment` | stripped | stripped | stripped | stripped | stripped | No |
| `figure` | renders | renders | literal | literal | renders | Yes |
| `figure_group` | renders | renders | literal | literal | renders | Yes |
| `caption` | renders | renders | literal | literal | renders | Yes |
| `abbreviation_def` | renders | renders | stripped | stripped | renders | No |
| `link_reference_definition` | renders | renders | renders | literal | renders | No |
| `citation_definition` | literal | literal | literal | literal | literal | No |
| `text` | renders | renders | renders | renders | renders | No |
| `inline_extension` | renders | renders | renders | renders | renders | Yes |
| `emphasis` | renders | renders | renders | renders | renders | No |
| `strong` | renders | renders | renders | renders | renders | No |
| `underline` | renders | renders | renders | renders | renders | No |
| `strike` | renders | renders | renders | renders | renders | No |
| `mention` | literal | literal | literal | literal | literal | No |
| `code` | renders | renders | renders | renders | renders | No |
| `literal_inline` | renders | renders | renders | renders | renders | No |
| `link` | renders | renders | renders | literal | renders | No |
| `autolink` | renders | renders | renders | literal | renders | No |
| `image` | renders | renders | literal | literal | renders | Yes |
| `non_breaking_space` | renders | renders | renders | renders | renders | No |
| `soft_break` | renders | renders | renders | renders | renders | No |
| `hard_break` | renders | renders | renders | renders | renders | No |
| `raw_inline` | renders | literal | literal | literal | renders | Yes |
| `escaped_text` | renders | renders | renders | renders | renders | No |
| `footnote_ref` | renders | renders | literal | literal | renders | No |
| `inline_footnote` | renders | renders | literal | literal | renders | No |
| `heading_ref` | renders | renders | literal | literal | renders | No |
| `citation_group` | literal | literal | literal | literal | literal | No |
| `citation` | literal | literal | literal | literal | literal | No |
| `span` | renders | renders | literal | literal | renders | No |
| `superscript` | renders | renders | renders | renders | renders | No |
| `subscript` | renders | renders | renders | renders | renders | No |
| `highlight` | renders | renders | renders | literal | renders | No |
| `insert` | renders | renders | renders | renders | renders | No |
| `delete` | renders | renders | renders | renders | renders | No |
| `substitution` | renders | renders | renders | renders | renders | No |
| `critic_comment` | renders | renders | stripped | stripped | renders | No |
| `math` | renders | renders | literal | literal | renders | Yes |
| `abbreviation` | renders | renders | literal | literal | renders | No |

Every cell is measured by rendering the construct's sample through the plugin.

<!-- profile-capabilities:end -->

## Sanitization

Rendered Carve is always sanitized - there is **no setting or capability that
disables it**. Two layers run on every surface:

1. carve-php's engine hardening: unsafe URL schemes (`javascript:`, `vbscript:`,
   `data:`, `file:`) and `on*` event handlers are stripped from generated markup;
   comments additionally strip raw HTML outright.
2. WordPress `wp_kses` with the Carve allowlist, applied to the final output.
   This is the authoritative gate: `<script>`/`<style>` and event handlers can
   never reach output. Adjust the allowlist via the `wpcarve_allowed_html` filter.

### The one `data:` URI that survives

`data:` is not in `wp_allowed_protocols()`, so kses strips it everywhere with a
single exception: the `src` of an `<img>`, holding a `data:image/svg+xml` URI
spelled the way the `img` fence writes one (unreserved characters and percent
escapes only). The fence renders an author's SVG as a sandboxed image, and that
is the URI it points at; without the exception every `img` fence would render a
broken image.

Two things keep it safe, and the second is the one that matters:

- carve-php sanitizes the SVG body before encoding it - a tokenizer with a
  presentational allowlist that drops `<script>`, `<foreignObject>`, `on*`
  handlers, the `<style>` element and every external reference.
- A browser renders an SVG referenced by `<img src>` in a restricted mode: no
  script execution, no external fetches, no access to the embedding document,
  whatever the SVG contains.

The exception is that narrow on purpose. `data:` on an `<a href>`, on an
`<iframe src>`, or carrying any other media type is still stripped - an iframe
would run script in the SVG document, which an `<img>` does not. The engine's
opt-in inline-`<svg>` mode is also not enabled, because that would put the
author's SVG in the live page DOM where only the string sanitizer stands between
it and the reader, and a string sanitizer is not browser-grade.

## Raw HTML

A literal `<div>` typed in the source is **text**, not HTML. Raw HTML is only
produced by Carve's explicit raw syntax - a `` ```=html `` fenced block or an
inline `` `<tag>`{=html} `` span. Carve 0.2 escapes it unless the render passes
`rawHtml` (upgrade row U-076). The plugin passes `rawHtml` on authored surfaces
(posts, pages, blocks) and never for comments, and the profile still decides:
**`full`** and **`none`** permit raw nodes; `article` (the default), `comment`,
and `minimal` deny them, so their raw blocks render as escaped text.

When raw HTML is permitted, it is rendered and then filtered by `wp_kses` (layer
2 above): safe tags and sanitized inline `style` attributes survive, while
`<script>`, `<style>` blocks, event handlers and unsafe URLs are removed. This
mirrors how WordPress core sanitizes author-supplied HTML in post content.

## Line breaks

A single source newline inside a paragraph is a soft break: it stays inside the
paragraph and the browser collapses it to a space. The `post_soft_break` and
`comment_soft_break` settings have no Carve 0.2 option yet, so soft breaks render
as newlines. For a visible line break use a trailing backslash `` \ `` (a hard
break, always renders as `<br>`) or a `::: |` line block (poetry, addresses).

## Features

Settings map to the 0.2 engine like this (most are post-only):

- `heading_shift` - a document transform that raises heading levels.
- `toc_*`, `permalinks_enabled`, `external_links*` - processing of the
  rendered HTML.
- `smart_quotes` - the core `smartTypography` option (the locale has no 0.2
  counterpart).
- `mentions_enabled` - core mentions and tags with the archive URLs in the
  `social` option.
- `wikilinks_enabled` - the standard `:wiki[Page]` definition and a document
  transform that writes the link.
- `<diagram>_enabled` - the `DiagramFences` extension provider.
- `torchlight_*`, `normalize_tabs`, `tab_width`, `heading_numbers`,
  `media_embed_enabled` - waiting for the engine; see
  [extensions](extensions.md#waiting-for-the-0-2-engine).

### Torchlight (server-side highlighting)

`WpCarve\Extension\TorchlightExtension::highlight()` turns one code block into
themed, highlighted HTML with `torchlight/engine`, which highlights locally with
TextMate grammars - no API token, no network. On Carve 0.2 it plugs into the
engine's host code-block renderer, which has not landed yet
([carve-php#5250](https://github.com/markup-carve/carve-php/issues/5250)), so
code blocks currently render unhighlighted even with `torchlight_enabled` on.
`carve`, `csv` and `tsv` fences use the grammars from carve-grammars (the 0.2
Carve grammar on this branch).

Line numbers come from Torchlight's server-side gutter. Enable them globally
with `torchlight_line_numbers`, or for one block with a preceding attribute line:

````text
{.line-numbers data-line-start=10 title="bootstrap.php"}
``` php
require __DIR__ . '/vendor/autoload.php';
```
````

The global `torchlight_theme` can be overridden per block with a `theme`
attribute on the preceding attribute line:

````text
{theme=dracula}
``` php
require __DIR__ . '/vendor/autoload.php';
```
````

An unknown theme name falls back to the plain (un-highlighted) output for that
block. Torchlight also handles in-code annotations such as `[tl! highlight]`,
`[tl! ++]`, `[tl! --]`, and `[tl! focus]`.

To register carve-php extensions of your own, use the `wpcarve_extensions`
filter - see [Hooks](hooks.md#wpcarve_extensions).

## Budgets

Comments and `[carve]` shortcodes use the untrusted content budgets. The default source limit is 65,536 bytes, above ordinary comment sizes and below carve-php's comment profile limit. The default nesting limit is four levels of block containers (quotes, lists, divs, admonitions, description lists), which the plugin measures on the parsed tree because Carve 0.2 has no per-render nesting option. Change either value under Settings > Carve Markup. Input that exceeds a budget appears as escaped source in the normal Carve wrapper. Comment moderation shows whether source size or nesting caused the fallback. The fallback is evaluated on each render, so raising a limit takes effect without clearing the post render cache.
