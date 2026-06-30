# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-30

Initial release.

### Added

- Render Carve markup in posts, pages and comments, powered by
  `markup-carve/carve-php`. Per-post "Render as Carve" opt-in, a `[carve]`
  shortcode, and `carve/markup` + `carve/slides` Gutenberg blocks.
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
