# Settings

All options live in a single `wpcarve_settings` option (see
`WpCarve\Settings`). Defaults are typed; the stored array is merged over them, so
reads always get a fully-populated value. Configure under **Settings → Carve
Markup**.

## Rendering surfaces

| Key | Default | What it does |
| --- | --- | --- |
| `enable_posts` | `true` | Allow the per-post "Render as Carve" toggle on posts. |
| `enable_pages` | `true` | Allow the toggle on pages. |
| `enable_comments` | `false` | Render comments as Carve (using the comment profile) and show the comment toolbar. |
| `enable_shortcode` | `true` | Register the `[carve]…[/carve]` shortcode. |
| `enable_excerpts` | `true` | Render `get_the_excerpt()` from Carve source for Carve posts. |

## Engine

| Key | Default | What it does |
| --- | --- | --- |
| `post_profile` | `article` | Content profile for posts: `full` / `article` / `comment` / `minimal` / `none`. Only `full` permits raw HTML (still sanitized via `wp_kses`); the others escape it. |
| `comment_profile` | `comment` | Content profile for comments. |
| `post_soft_break` | `newline` | How a single newline in a paragraph renders in posts: `newline` / `space` / `br`. |
| `comment_soft_break` | `newline` | Same for comments (set to `br` so commenters' line breaks show). |
| `abbreviations` | `''` | Site-wide abbreviations, one `KEY: expansion` per line; matching words get an `<abbr>` tooltip everywhere. |

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
| `heading_numbers` | `false` | Prefix each heading with its section number (1, 1.1, 1.2). Front end only - it is generated markup, so it never reaches the visual editor. |
| `external_links` | `false` | Add `rel="noopener noreferrer"` to links pointing off this site. The site's own host comes from `home_url()`; add others with the `wpcarve_internal_hosts` filter. |
| `external_links_new_tab` | `false` | Also add `target="_blank"`. Separate from the above because marking a link external and forcing a new tab are different decisions. |
| `external_links_nofollow` | `false` | Also add `nofollow` to the `rel`. |
| `mentions_enabled` | `false` | Turn `@name` into an author-archive link and `#tag` into a tag-archive link. Templates come from `home_url()`; override with `wpcarve_mention_url` / `wpcarve_tag_url` if your author base or permalinks differ. |
| `wikilinks_enabled` | `false` | Turn `[[Page Title]]` into a link to the matching post or page. Unresolved links become `href="#"` and keep their `wikilink` class, so a theme can style `a.wikilink[href="#"]` as a broken link. Override resolution with `wpcarve_wikilink_url`. |
| `smart_quotes` | `false` | Curly quotes / dashes. |
| `smart_quotes_locale` | `en` | Locale for quote glyphs (e.g. `en`, `de`, `fr`). |
| `mermaid_enabled` | `false` | Render ` ```mermaid ` fenced blocks as diagrams. |
| `chart_enabled` | `false` | Render ` ```chart ` blocks with Chart.js. |
| `vega_enabled` | `false` | Render ` ```vega-lite ` blocks with Vega-Lite. |
| `graphviz_enabled` | `false` | Render ` ```graphviz ` (DOT) blocks. |
| `wavedrom_enabled` | `false` | Render ` ```wavedrom ` timing diagrams. |
| `abc_enabled` | `false` | Render ` ```abc ` music notation. |
| `plantuml_enabled` | `false` | Render ` ```plantuml ` / ` ```puml ` blocks via the Kroki service (external request). |
| `diagram_export` | `false` | Show hover **Copy SVG** / **Download** controls on rendered diagrams (front end). |
| `media_embed_enabled` | `false` | Render `:youtube[ID]` / `:vimeo[ID]` / `:media[URL]` as responsive embeds (falls back to a sanitized `<a>` link). |
| `torchlight_enabled` | `false` | Server-side syntax highlighting (bundled `torchlight/engine`; just toggle on). |

Each diagram renderer is off by default; its JavaScript loads only on pages that both enable and use it. Custom renderers (registered via `wpcarve_diagram_renderers`) add their own `{name}_enabled` key automatically. See [hooks.md](hooks.md).

**PlantUML** has no browser library, so it renders differently from the others: the `plantuml`/`puml` source is POSTed to the [Kroki](https://kroki.io) service, which returns an SVG that is embedded inline. This is the one renderer that makes an **external request** at view time.

> ⚠️ **Privacy / GDPR:** the default `https://kroki.io` is a **third-party service outside your domain**, so enabling PlantUML sends the diagram source there on render. For confidential content, or under a strict privacy/GDPR posture, point the `wpcarve_kroki_server` filter (see [hooks.md](hooks.md)) at a **self-hosted or localhost Kroki** so no data leaves your site, and disclose the external call in your privacy policy where required.

With `diagram_export` enabled (off by default), hovering a rendered diagram on the front end reveals a **Download** control (and, for the SVG renderers - Mermaid, Graphviz, WaveDrom, ABC, PlantUML - a **Copy SVG**). SVG diagrams export as `.svg`; canvas renderers (Chart.js, Vega-Lite) export as `.png`. The block-editor preview stays chrome-free.
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

## Attribution and original source

| Key | Default | What it does |
| --- | --- | --- |
| `attribution_enabled` | `false` | Append “Written with Carve” and link to the Carve project on public, singular Carve posts. |
| `attribution_plugin_link` | `true` | Include a link to the official Carve Markup WordPress plugin when attribution is shown. |
| `source_access` | `none` | Publish the stored `.crv` source as `none`, inline `view`, `download`, or `both`. |

The two settings are independent: crediting Carve never publishes source by
itself. Each post can inherit or override both choices in its **Carve** editor
panel. Drafts, password-protected posts, feeds, excerpts, archives, and
non-Carve posts expose neither footer nor source. The download endpoint checks
the post and its effective source setting again on every request.

Source access is deliberately explicit because original markup can contain
comments, frontmatter, reference definitions, or other material omitted from
the rendered page. Whole-post mode returns `post_content` byte-for-byte; a post
made from Carve blocks returns the blocks' Carve attributes without Gutenberg's
serialization comments.
