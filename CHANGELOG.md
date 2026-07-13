# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.3] - 2026-07-14

### Fixed

- Mermaid re-renders no longer produce "Syntax error in text": the source
  stash raced mermaid's own DOMContentLoaded auto-run and could capture an
  already-rendered SVG; diagrams are now stashed and parked before the vendor
  scripts run (data-processed blocks the auto-run) and unparked by the
  scheme-aware renderer.
- Mermaid diagrams follow the effective color scheme: the initial render
  picks the mermaid theme from the site toggle (or OS preference) instead of
  always rendering light, and diagrams re-render on scheme changes alongside
  charts.
- All plugin dark-mode surface styles (admonitions, tabs, code groups, TOC
  boxes, comment tabs) now honor a site-level `<html data-theme="dark|light">`
  toggle in both directions, instead of only the OS color-scheme preference -
  matching the behavior the code blocks already had.
- Pasting Carve into the block no longer offers a bogus "convert from
  Markdown" prompt: the sniff triggered on any `[`, `**`, or `# ` - all
  ordinary Carve syntax. Carve-distinctive structure (`:::` fences, `|=`
  table headers, `{.class}` attribute lines) now suppresses the offer, and
  only signals a Carve document cannot contain (HTML tags, BBCode,
  double-delimiter emphasis, setext underlines, `* ` bullets) trigger it.
- Excerpts for carve/markup block posts: they previously fell through to
  core, which drops the dynamic block (empty excerpt) - or, when the block
  comment is malformed (an unescaped `-->` inside the attribute JSON ends
  the comment early), leaks the raw serialized block markup into archive
  pages. The excerpt now renders from the carve source, salvaging the
  attribute JSON even from malformed comments.
- Carve code fences no longer paint GitHub-light token colors onto dark base
  themes: the carve-tuned overlay now ships a dark palette and picks it by the
  base theme's background luminance (the normalized theme `type` field is
  unreliable).
- Closing inline delimiters (`*bold*`, `/italic/`, `~strike~`, ...) keep their
  color: the vendored phiki recovers capture offsets with a first-occurrence
  search, so a closing delimiter identical to the opening one lost its scope.
  `scripts/patch-phiki-offsets.php` patches the vendored copy (Composer hooks
  + the dist build) until the upstream fix ships.
- Underlined text inside carve fences is styled again (the overlay targeted
  `markup.underline.carve` while the grammar emits `markup.underline.text.carve`).
- Dark-mode code highlighting no longer leaves parts of a token in its light
  color: the phiki dark rules now recolor every `span` in a highlighted block,
  not only spans carrying a `.token` class (phiki emits some without it).
- Code-group tab strips (`::: code-group`) now follow the active theme in dark
  mode instead of staying hardcoded light, matching the OS preference and the
  site theme toggle in both directions.
- The phiki offset patcher (`scripts/patch-phiki-offsets.php`) now fails loudly
  (writes to STDERR and exits non-zero) when it cannot read or write the target
  file, instead of reporting success after a silent I/O failure.
- The front-end `diagrams.js` and the local `carve.js` engine bundle are now
  cache-busted by file mtime like the other local assets, so a rebuild is picked
  up without a plugin version bump.

### Added

- Optional dark-mode code theme (`torchlight_theme_dark` setting): when set,
  code blocks render both palettes in one markup (phiki dual-theme custom
  properties) and switch by the visitor's `prefers-color-scheme`. A site-level
  toggle wins over the OS preference in both directions via the de-facto
  `<html data-theme="dark|light">` convention.
- The carve overlay now covers every language construct: bold-italic, math,
  mentions, tags, attribute lines, captions, inner fence markers and language
  words, admonition fences, headings, footnote references, images, and
  sub/superscript delimiters - previously these fell back to the base theme
  (often unstyled).

## [0.1.2] - 2026-07-13

### Added

- Diagram export (opt-in via the `diagram_export` setting, off by default):
  hovering a rendered diagram on the front end reveals a **Download** control
  (and **Copy SVG** for the SVG renderers - Mermaid, Graphviz, WaveDrom, ABC).
  SVG diagrams save as `.svg`; canvas renderers (Chart.js, Vega-Lite) save as
  `.png`.
- Bulk migration without WP-CLI: a **Tools → Carve Migrate** screen runs the
  same analysis and conversion as `wp carve migrate`. The post list is the
  dry-run preview (detected source per post, flagged posts explained), eligible
  posts are pre-checked, and an optional Force converts block-editor/shortcode
  posts too. Guarded by nonce and the `edit_others_posts` capability.

### Security

- The paste-ingest endpoint (`POST /carve/v1/ingest`) now rejects input over
  512 KB with a `413`, bounding the conversion work an authenticated editor can
  trigger.
- The public comment-preview REST endpoint (`POST /carve/v1/preview-comment`)
  now refuses when Carve comment rendering is disabled and throttles anonymous
  callers with a per-IP fixed-window rate limit (default 30/minute, filterable
  via `wpcarve_preview_rate_limit` / `wpcarve_preview_rate_window`), so the
  unauthenticated renderer can no longer be abused as a CPU-amplification
  vector. Users who can edit posts are exempt.

### Fixed

- `window.wpCarveDiagrams.rerenderCharts` is no longer dropped: the object was
  reassigned wholesale when `run` was attached, removing the chart theme
  re-render helper set earlier.
- An admonition with a custom title (`::: tip "Pro tip"`) keeps its per-type
  icon: the icon now renders on the title line itself; previously the whole
  icon-plus-label pseudo-element was suppressed together with the auto label.
- `[carve]` shortcode content is now excluded from `wptexturize` (which runs
  before shortcodes on `the_content`), so a fence title like
  `::: tab "Overview"` keeps its straight quotes and parses as a fence instead
  of degrading to a literal paragraph. As a second line of defense, typographic
  quotes on `:::` opener lines are straightened back before parsing (they can
  still arrive pre-curled from widgets or word-processor paste); quotes in
  ordinary prose are left untouched.
- The render cache now invalidates when a render-affecting setting changes (TOC,
  smart quotes, torchlight theme, diagram toggles, ...), not only on a
  plugin/engine upgrade. Previously such a change left every already-saved post
  serving its stale cached HTML until re-saved.

### Changed

- Minimum WordPress raised to **6.3** (from 6.0): the blocks use Block API v3,
  which requires 6.3, so the previous floor was inaccurate.
- The front-end stylesheet loads only on views that actually render Carve (a
  Carve post/block, a `[carve]` shortcode in the queried content, or a comment
  surface) instead of on every page. The new `wpcarve_enqueue_styles` filter
  forces it on or off for surfaces the detection cannot see.

## [0.1.1] - 2026-07-08

### Added

- Collapsible table of contents: the generated TOC renders as a
  `<details>`/`<summary>` disclosure, closed by default and opened on click,
  via carve-php's new opt-in `collapsible` option.

### Changed

- The block's live in-browser preview now renders tabs, details, spoilers and
  code groups as interactive widgets, matching the published front-end output
  (previously they showed as raw `<div>`s).

### Fixed

- Content tabs and code groups now switch correctly: added the missing tab
  styles and allowed the radio group `name` through sanitization, so exactly
  one panel shows at a time.
- Inline code now has a background so it reads as code, and images are
  constrained to their container width (`max-width: 100%`).

## [0.1.0] - 2026-07-02

Initial release.

### Added

- Render Carve markup in posts, pages and comments, powered by
  `markup-carve/carve-php`. Per-post "Render as Carve" opt-in, a `[carve]`
  shortcode, and `carve/markup` + `carve/slides` Gutenberg blocks.
- Visual (WYSIWYG) editor for the Carve block, built on the shared
  `carve-grammars` Tiptap core: Write / Split / Visual / Preview tabs, a unified
  block toolbar, an in-block code-language picker, and keyboard shortcuts. Every
  edit serializes back to canonical Carve; a render-aware guard only warns when
  visual editing would actually change the rendered output.
- Import and export: paste or upload Markdown / Djot / BBCode / HTML and convert
  to Carve, and export any post as a `.crv` file.
- Live in-browser preview using the Carve JS engine (no server round-trip).
- Multi-format paste: convert Markdown, Djot, BBCode or HTML to Carve in place.
- Frontmatter-to-meta mapping (yaml/json/toml) for excerpt, SEO and post meta.
- Content profiles (`full` / `article` / `comment` / `minimal` / `none`) and a
  safe mode that is always on for comments (normative XSS hardening).
- Table of contents, heading permalinks, locale-aware smart quotes, and a set
  of diagram renderers (Mermaid, Chart.js, Vega-Lite, Graphviz, WaveDrom, ABC).
- Media embeds via `markup-carve/carve-php-media-embed`: `:youtube[ID]`,
  `:vimeo[ID]` and `:media[URL]`, opt-in through the `media_embed_enabled`
  setting (renders a safe link under safe mode).
- Bundled server-side syntax highlighting via `torchlight/engine` (offline
  TextMate grammars, no API token), with `[tl! highlight|++|--|focus]`
  annotations and a per-block `{theme=...}` override.
- Render caching at save time and a REST endpoint for headless WordPress.
- WP-CLI migration command.

[Unreleased]: https://github.com/markup-carve/wp-carve/compare/0.1.3...HEAD
[0.1.3]: https://github.com/markup-carve/wp-carve/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/markup-carve/wp-carve/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/markup-carve/wp-carve/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/markup-carve/wp-carve/releases/tag/0.1.0
