# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Bulk migration without WP-CLI: a **Tools → Carve Migrate** screen runs the
  same analysis and conversion as `wp carve migrate`. The post list is the
  dry-run preview (detected source per post, flagged posts explained), eligible
  posts are pre-checked, and an optional Force converts block-editor/shortcode
  posts too. Guarded by nonce and the `edit_others_posts` capability.

### Security

- The public comment-preview REST endpoint (`POST /carve/v1/preview-comment`)
  now refuses when Carve comment rendering is disabled and throttles anonymous
  callers with a per-IP fixed-window rate limit (default 30/minute, filterable
  via `wpcarve_preview_rate_limit` / `wpcarve_preview_rate_window`), so the
  unauthenticated renderer can no longer be abused as a CPU-amplification
  vector. Users who can edit posts are exempt.

### Fixed

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

[Unreleased]: https://github.com/markup-carve/wp-carve/compare/0.1.0...HEAD
[0.1.0]: https://github.com/markup-carve/wp-carve/releases/tag/0.1.0
