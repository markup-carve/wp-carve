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
| `none` | Raw | No profile restriction (relies on safe mode alone). |

## Safe mode

`safe_mode` enables carve-php's XSS hardening (script/style stripping, URL
sanitization). Posts honor the setting; **comments are always rendered in safe
mode** regardless, since their source is untrusted.

## Soft-break mode

Controls how a single newline inside a paragraph renders:

| Mode | Result |
| --- | --- |
| `newline` | Preserved as a newline in the HTML source (default). |
| `space` | Joined with a space (Markdown-like paragraph flow). |
| `br` | Hard `<br>` line break. |

## Extensions

Enabled extensions are added to the post converter (most are post-only; tab
normalization applies to both):

- `HeadingLevelShiftExtension` — `heading_shift`
- `TableOfContentsExtension` — `toc_*`
- `HeadingPermalinksExtension` — `permalinks_enabled`
- `SmartQuotesExtension` — `smart_quotes` + `smart_quotes_locale`
- `MermaidExtension` — `mermaid_enabled`
- `TorchlightExtension` — `torchlight_enabled` + `torchlight_theme` (plugin-local; see below)
- `TabNormalizeExtension` — `normalize_tabs` + `tab_width`

### Torchlight (server-side highlighting)

`WpCarve\Extension\TorchlightExtension` hooks carve-php's `render.code_block`
event and replaces each fenced block with themed, highlighted HTML. It uses
`torchlight/engine`, which highlights locally with TextMate grammars - no API
token, no network. Install the suggested package:

```bash
composer require torchlight/engine
```

If the package is absent the extension no-ops and code blocks render plain.

To register further carve-php extensions of your own, use the
`wp_carve_converter` action - see [Hooks](hooks.md).
