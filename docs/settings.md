# Settings

All options live in a single `wp_carve_settings` option (see
`WpCarve\Settings`). Defaults are typed; the stored array is merged over them, so
reads always get a fully-populated value. Configure under **Settings → Carve
Markup**.

## Rendering surfaces

| Key | Default | What it does |
| --- | --- | --- |
| `enable_posts` | `true` | Allow the per-post "Render as Carve" toggle on posts. |
| `enable_pages` | `true` | Allow the toggle on pages. |
| `enable_comments` | `false` | Render comments as Carve (always in safe mode) and show the comment toolbar. |
| `enable_shortcode` | `true` | Register the `[carve]…[/carve]` shortcode. |
| `enable_excerpts` | `true` | Render `get_the_excerpt()` from Carve source for Carve posts. |

## Engine

| Key | Default | What it does |
| --- | --- | --- |
| `safe_mode` | `true` | XSS hardening for posts (comments are always safe). |
| `post_profile` | `article` | Content profile for posts: `full` / `article` / `comment` / `minimal` / `none`. |
| `comment_profile` | `comment` | Content profile for comments. |

See [Profiles & rendering](profiles.md) for what each profile means.

## Extensions

| Key | Default | What it does |
| --- | --- | --- |
| `heading_shift` | `0` | Shift all heading levels down by N (e.g. `1` turns `#` into `<h2>`). |
| `toc_enabled` | `false` | Insert a table of contents. |
| `toc_position` | `top` | `top` / `bottom` / `none` (manual marker). |
| `toc_min_level` | `2` | Lowest heading level included. |
| `toc_max_level` | `4` | Highest heading level included. |
| `toc_list_type` | `ul` | `ul` (bulleted) or `ol` (numbered) TOC. |
| `permalinks_enabled` | `false` | Add click-to-copy heading anchors. |
| `smart_quotes` | `false` | Curly quotes / dashes. |
| `smart_quotes_locale` | `en` | Locale for quote glyphs (e.g. `en`, `de`, `fr`). |
| `mermaid_enabled` | `false` | Render ` ```mermaid ` fenced blocks as diagrams. |
| `chart_enabled` | `false` | Render ` ```chart ` blocks with Chart.js. |
| `vega_enabled` | `false` | Render ` ```vega-lite ` blocks with Vega-Lite. |
| `graphviz_enabled` | `false` | Render ` ```graphviz ` (DOT) blocks. |
| `wavedrom_enabled` | `false` | Render ` ```wavedrom ` timing diagrams. |
| `abc_enabled` | `false` | Render ` ```abc ` music notation. |
| `media_embed_enabled` | `false` | Render `:youtube[ID]` / `:vimeo[ID]` / `:media[URL]` as responsive embeds (safe `<a>` link under safe mode). |
| `torchlight_enabled` | `false` | Server-side syntax highlighting (bundled `torchlight/engine`; just toggle on). |

Each diagram renderer is off by default; its JavaScript loads only on pages that both enable and use it. Custom renderers (registered via `wp_carve_diagram_renderers`) add their own `{name}_enabled` key automatically. See [hooks.md](hooks.md).
| `torchlight_theme` | `github-light` | Default Torchlight theme name (override per block with a `{theme=...}` attribute line). |
| `torchlight_line_numbers` | `false` | Show Torchlight line numbers for every highlighted code block. |
| `normalize_tabs` | `false` | Convert leading tabs to spaces. |
| `tab_width` | `2` | Spaces per tab when normalizing. |

## Innovations

| Key | Default | What it does |
| --- | --- | --- |
| `live_preview` | `true` | In-browser instant block preview (needs the bundled `assets/js/vendor/carve.js`). |
| `visual_editor_mode` | `disabled` | Tiptap "Visual" tab in the Carve block: `disabled` / `enabled` / `enabled_default` (open in Visual). See [Visual editor](visual-editor.md). |
| `paste_ingest` | `true` | Paste Markdown / Djot / BBCode / HTML and convert to Carve. |
| `frontmatter_meta` | `true` | Map `---` frontmatter to excerpt / SEO / meta. |
| `render_cache` | `true` | Cache rendered HTML in post meta on save. |
