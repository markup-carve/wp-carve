# Profiles & rendering

`WpCarve\Converter` wraps carve-php's `CarveConverter`, building one converter
per context (`post` / `comment`) from the plugin settings.

## Content profiles

A profile is carve-php's allow-list of which constructs are permitted. Set
`post_profile` and `comment_profile` to one of:

| Profile | Use for | Notes |
| --- | --- | --- |
| `full` | Trusted authors | Everything carve-php supports, including raw HTML (see below). |
| `article` | Normal posts (default) | Headings, lists, tables, code, media, admonitions; no raw HTML. |
| `comment` | User comments (default) | Inline + basic blocks; no raw HTML or risky blocks. |
| `minimal` | Tight contexts | Inline formatting only. |
| `none` | Raw | No profile restriction (sanitization still applies). |

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
profile permits raw nodes. Only the **`full`** profile does; `article` (the
default), `comment` and `minimal` deny it, so their raw blocks render as escaped
text.

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
