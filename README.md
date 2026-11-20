# Carve Markup for WordPress

[![CI](https://github.com/markup-carve/wp-carve/actions/workflows/ci.yml/badge.svg)](https://github.com/markup-carve/wp-carve/actions/workflows/ci.yml)
[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/carve-markup)](https://wordpress.org/plugins/carve-markup/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/carve-markup)](https://wordpress.org/plugins/carve-markup/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/carve-markup)](https://wordpress.org/plugins/carve-markup/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)
[![WordPress 6.3+](https://img.shields.io/badge/WordPress-6.3%2B-blue.svg)](https://wordpress.org)

A WordPress plugin for the [Carve](https://github.com/markup-carve/carve) markup language - lightweight markup for documents and the web.
Render Carve in posts, pages and comments, powered by the [`markup-carve/carve-php`](https://github.com/markup-carve/carve-php) engine.

> [!NOTE]
> This branch (`next2`) targets Carve 0.2. The engines are pinned to unreleased
> `next2` commits of carve-php, carve-js and carve-grammars. Differences from
> 0.1 are listed under [Carve 0.2 status](#carve-02-status).

[Watch the WordPress demo](https://www.youtube.com/watch?v=FT_IMW8ABng)

## Features

- **Carve rendering** in posts/pages (per-post "Render as Carve" toggle), the `[carve]…[/carve]` shortcode, and comments.
- **Gutenberg block** with a source editor and preview. Its optional Tiptap
  visual editor is seeded from the carve-js 0.2 render and writes Carve back
  through the 0.2 HTML importer; an untouched document keeps its exact source.
- **Focused native blocks** for admonitions, code groups, and tables with spans,
  while the general Carve block remains best for complete documents.
- **In-browser live preview.** Carve has a real JS engine (`@markup-carve/carve`), so the block previews **instantly client-side**, no server round-trip. Run `npm run build` to bundle the engine (`assets/js/vendor/carve.js`); without it the editor falls back to the REST render endpoint.
- **Content profiles** (`full` / `article` / `comment` / `minimal`) through the 0.2 `profile` option. Raw HTML renders only on authored surfaces whose profile allows it (`rawHtml`), and everything passes through `wp_kses`.
- **Table of contents**, **heading permalinks**, **external link attributes**, **heading level shift**, **heading numbers**, **wiki links**, **smart typography** with a quote locale, **soft-break mode**, **tab normalization**, **Torchlight** highlighting, **Mermaid** and the other diagram fences - toggled in settings.
- **Extensions by name.** Tabs, details, spoiler, code group, list table, the SVG image fence and semantic spans each have a toggle under **Settings → Carve Markup → Content → Extensions**. The server render and the live preview register the same set.
- **Media embeds** via `:youtube[ID]` / `:vimeo[ID]` / `:media[URL]`, with a WordPress **oEmbed fallback** when the media-embed extension is off.
- **Paste conversion.** Paste or import Markdown, Djot, HTML or BBCode and convert it to Carve in place. The server converts Markdown, Djot and HTML (`POST /wp-json/carve/v1/ingest`); BBCode is converted in the browser by the carve-js importer (`assets/js/vendor/carve-import.js`), because Carve 0.2 imports BBCode in carve-js only.
- **Frontmatter → meta/SEO.** Typed `---yaml` / `---json` / `---toml` frontmatter maps to excerpt, SEO description (Yoast/AIOSEO when present), canonical, and `_wpcarve_frontmatter` meta. Non-destructive.
- **Bulk migrate**: convert existing posts to Carve in bulk. `wp carve migrate` - analyzes each post (skips block-editor / foreign-shortcode content), auto-detects Markdown vs HTML, converts safely (`--dry-run`, `--force`); or **Tools → Carve Migrate** for the same thing without CLI (the post list doubles as the dry-run preview). `wp carve lint` - read-only health check reporting render errors and visual-editor round-trip caveats.
- **Import / export**: Tools → Carve Import loads a Markdown, Djot, HTML or Carve file as a draft; an "Export Carve" row action downloads a post's `.crv` source.
- **Render caching + REST.** Rendered HTML is cached in post meta at save (fast views); `POST /wp-json/carve/v1/render` serves headless WordPress.

## Requirements

- PHP 8.2+, WordPress 6.3+ (the blocks use Block API v3)

## Installation (from source)

```bash
cd wp-content/plugins
git clone https://github.com/markup-carve/wp-carve.git
cd wp-carve
composer install --no-dev
npm install && npm run build   # optional: enables innovation A (instant preview)
```

Activate "Carve Markup", then configure under **Settings → Carve Markup**.

## Before you deactivate

Carve Documents keep readable source in `post_content`, and comments keep it in
`comment_content`. Carve blocks keep their source in a block attribute and their
rendered HTML as fallback content. The HTML remains visible after deactivation,
but editing the Carve source requires the plugin.

Use **Tools → Before you deactivate** to convert Carve blocks to core HTML blocks.
The tool keeps a private copy of each source and offers separate `.crv`
downloads while the plugin is active. The same conversion is available through
`wp carve detach`. Run `wp carve backfill` if older posts need saved HTML
without conversion.
For new content that you expect to move between systems with its source, use
[Carve Documents](docs/carve-documents.md).

## REST API

| Route | Body | Returns |
| --- | --- | --- |
| `POST /wp-json/carve/v1/render` | `{ carve, context }` | `{ html, include_warnings }` |
| `POST /wp-json/carve/v1/ingest` | `{ source, from }` | `{ carve, from, report }` |

Both require the `edit_posts` capability. `from` is `markdown`, `djot`, `html`
or `auto`. BBCode (given or detected) answers `422` with `browser: true`: the
server cannot import it on Carve 0.2, and the block editor converts it with
carve-js instead.

## Hooks

- `wpcarve_rendered_html` (filter): `(string $html, string $carve, string $context)`
- `wpcarve_extensions` (filter): `(array $extensions, string $context)` - add carve-php 0.2 extension definitions (replaces the 0.1 `wpcarve_converter` action).
- `wpcarve_source` (filter): `(string $carve, string $context)` - modify source before conversion.

See [docs/hooks.md](docs/hooks.md) for the full list (oEmbed, OG image, diagram renderer API, KaTeX base).

## Carve 0.2 status

- **BBCode** paste and import run in the browser only: Carve 0.2 imports
  BBCode in carve-js, not carve-php. The REST ingest endpoint, file import and
  bulk migration do not take BBCode.
- **Smart-quote locale** under the `article`, `comment` and `minimal`
  profiles: carve-php drops the locale's quotes there and writes the default
  ones ([carve-php#5415](https://github.com/markup-carve/carve-php/issues/5415));
  the `full` profile and the live preview use them.
- **Plain-text excerpts** come from the engine's `plain` writer, without
  footnotes.

## Documentation

See [`docs/`](docs/README.md): [settings](docs/settings.md),
[profiles & rendering](docs/profiles.md), [hooks](docs/hooks.md),
[WP-CLI](docs/wp-cli.md), [Carve syntax](docs/syntax.md).

## Roadmap

- Visual editor: a Tiptap WYSIWYG editor ships behind the `visual_editor_mode` setting; headings, marks, lists, quotes, code blocks, tables, math, footnotes, admonitions and nested containers (tabs, code-groups) all round-trip (see [docs/visual-editor.md](docs/visual-editor.md)). Interactive per-widget editing of tabs/code-groups (a tab bar in the editor) is future work.
- Lossless HTML ↔ Carve round-trip editing.
