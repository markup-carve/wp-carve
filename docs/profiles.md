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
| `admonition` | renders | renders | literal | literal | renders | No |
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
| `directive` | renders | renders | stripped | stripped | renders | No |
| `block_extension`¹ | renders | renders | literal | literal | renders | No |
| `raw_block` | renders | literal | literal | literal | renders | Yes |
| `footnote` | renders | renders | stripped | stripped | renders | No |
| `frontmatter` | stripped | stripped | stripped | stripped | stripped | No |
| `definition_list` | renders | renders | literal | literal | renders | No |
| `definition_term` | renders | renders | literal | literal | renders | No |
| `definition_description` | renders | renders | literal | literal | renders | No |
| `section` | renders | renders | literal | literal | renders | No |
| `line_block` | renders | renders | literal | literal | renders | No |
| `comment` | stripped | stripped | stripped | stripped | stripped | No |
| `figure` | renders | renders | literal | literal | renders | Yes |
| `figure_group` | renders | renders | literal | literal | renders | Yes |
| `abbreviation_def` | renders | renders | stripped | stripped | renders | No |
| `link_reference_definition` | renders | renders | renders | literal | renders | No |
| `citation_definition` | renders | renders | literal | stripped | renders | No |
| `autolink` | renders | renders | renders | literal | renders | No |
| `text` | renders | renders | renders | renders | renders | No |
| `emphasis` | renders | renders | renders | renders | renders | No |
| `strong` | renders | renders | renders | renders | renders | No |
| `underline` | renders | renders | renders | renders | renders | No |
| `strike` | renders | renders | renders | renders | renders | No |
| `inline_extension` | renders | renders | renders | renders | renders | Yes |
| `mention` | literal | literal | literal | literal | literal | No |
| `code` | renders | renders | renders | renders | renders | No |
| `link` | renders | renders | renders | literal | renders | No |
| `image` | renders | renders | literal | literal | renders | Yes |
| `non_breaking_space` | renders | renders | renders | renders | renders | No |
| `soft_break` | renders | renders | renders | renders | renders | No |
| `hard_break` | renders | renders | renders | renders | renders | No |
| `raw_inline` | renders | literal | literal | literal | renders | Yes |
| `escaped_text` | renders | renders | renders | renders | renders | No |
| `footnote_ref` | renders | renders | literal | literal | renders | No |
| `inline_footnote` | renders | renders | literal | literal | renders | No |
| `heading_ref` | renders | renders | literal | literal | renders | No |
| `citation_group` | renders | renders | literal | stripped | renders | No |
| `citation` | renders | renders | literal | stripped | renders | No |
| `ruby`¹ | renders | renders | literal | literal | renders | No |
| `small_caps`¹ | renders | renders | literal | literal | renders | No |
| `caption_number`¹ | renders | renders | literal | literal | renders | No |
| `span` | renders | renders | literal | literal | renders | No |
| `superscript` | renders | renders | renders | renders | renders | No |
| `subscript` | renders | renders | renders | renders | renders | No |
| `highlight` | renders | renders | renders | literal | renders | No |
| `insert` | renders | renders | renders | renders | renders | No |
| `delete` | renders | renders | renders | renders | renders | No |
| `substitution` | renders | renders | literal | literal | renders | No |
| `critic_comment` | renders | renders | literal | literal | renders | No |
| `symbol`¹ | renders | renders | literal | literal | renders | No |
| `math` | renders | renders | literal | literal | renders | Yes |
| `abbreviation` | renders | renders | literal | literal | renders | No |
| `caption` | renders | renders | literal | literal | renders | Yes |
| `cell_wrap`¹ | renders | renders | renders | renders | renders | No |
| `literal_inline` | renders | renders | renders | renders | renders | No |

¹ This engine type has no independent source spelling in the fixture set. Its cells follow the carve-php profile definition.

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

Carve is Djot-based, so a literal `<div>` typed in the source is **text**, not
HTML. Raw HTML is only produced by Djot's explicit raw syntax - a `` `=html ``
fenced block or an inline `` `<tag>`{=html} `` span - and only when the active
profile permits raw nodes. The **`full`** and **`none`** profiles permit
them; `article` (the default), `comment`, and `minimal` deny them, so their raw
blocks render as escaped text.

When raw HTML is permitted, it is rendered and then filtered by `wp_kses` (layer
2 above): safe tags and sanitized inline `style` attributes survive, while
`<script>`, `<style>` blocks, event handlers and unsafe URLs are removed. This
mirrors how WordPress core sanitizes author-supplied HTML in post content.

## Line breaks

A single source newline inside a paragraph is a soft break: it stays inside the
paragraph and the browser collapses it to a space. There is no configurable
soft-break mode. For a visible line break use a trailing backslash `\` (a hard
break, always renders as `<br>`) or a `::: |` line block (poetry, addresses).

## Extensions

Enabled extensions are added to the post converter (most are post-only; tab
normalization applies to both):

- `HeadingLevelShiftExtension` — `heading_shift`
- `TableOfContentsExtension` — `toc_*`
- `HeadingPermalinksExtension` — `permalinks_enabled`
- `SmartQuotesExtension` — `smart_quotes` + `smart_quotes_locale`
- `MermaidExtension` — `mermaid_enabled`
- `TorchlightExtension` — `torchlight_enabled` + `torchlight_theme` + `torchlight_line_numbers` (plugin-local; see below)
- `TabNormalizeExtension` — `normalize_tabs` + `tab_width`

### Torchlight (server-side highlighting)

`WpCarve\Extension\TorchlightExtension` hooks carve-php's `render.code_block`
event and replaces each fenced block with themed, highlighted HTML. It uses `torchlight/engine`, which highlights locally with TextMate grammars -
no API token, no network. The package is bundled with the plugin; enable it with
the `torchlight_enabled` setting.
`carve`, `csv` and `tsv` fences use the grammars from carve-grammars, so a
`csv` or `tsv` body shows its separators and quoted fields. A `csv` fence inside
a `carve` fence does too; a nested `tsv` fence does not, because a `carve` body
has its tabs expanded to spaces before highlighting.
Line numbers come from Torchlight's server-side gutter. Enable them globally
with `torchlight_line_numbers`, or for one block with a preceding attribute line:

````text
{.line-numbers data-line-start=10 title="bootstrap.php"}
``` php
require __DIR__ . '/vendor/autoload.php';
```
````

The global `torchlight_theme` can be overridden per block with a `theme`
attribute on the preceding attribute line - handy for a dark sample on an
otherwise light page:

````text
{theme=dracula}
``` php
require __DIR__ . '/vendor/autoload.php';
```
````

An unknown theme name falls back to carve-php's plain (un-highlighted) output
for that block.

Torchlight also handles in-code annotations such as `[tl! highlight]`,
`[tl! ++]`, `[tl! --]`, and `[tl! focus]` for highlighted, added, removed, and
focused lines. Carve does not support Djot-style language strings like
```` ``` php # ````; use the preceding attribute line instead.

To register further carve-php extensions of your own, use the
`wpcarve_converter` action - see [Hooks](hooks.md).
