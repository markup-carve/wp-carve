# Profiles & rendering

`WpCarve\Converter` wraps carve-php's `CarveConverter`, building one converter
per context (`post` / `comment`) from the plugin settings.

## Content profiles

A profile is carve-php's allow-list of which constructs are permitted. Set
`post_profile` and `comment_profile` to one of:

| Profile | Use for | Notes |
| --- | --- | --- |
| `full` | Trusted authors | Everything carve-php supports. |
| `article` | Normal posts (default) | Headings, lists, tables, code, media, admonitions. |
| `comment` | User comments (default) | Inline + basic blocks; no raw HTML or risky blocks. |
| `minimal` | Tight contexts | Inline formatting only. |
| `none` | Raw | No profile restriction (sanitization still applies). |

## Sanitization

Rendered Carve is always sanitized: carve-php's XSS hardening (script/style
stripping, URL sanitization) runs on every surface, and the generated markup
additionally passes through WordPress `wp_kses` with the Carve allowlist. There
is no setting or capability that disables it - raw `<script>`/`<style>` can never
reach output. Adjust the allowlist via the `wpcarve_allowed_html` filter.

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
