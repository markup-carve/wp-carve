# Carve Markup for WordPress

[![CI](https://github.com/markup-carve/wp-carve/actions/workflows/ci.yml/badge.svg)](https://github.com/markup-carve/wp-carve/actions/workflows/ci.yml)
[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/carve-markup)](https://wordpress.org/plugins/carve-markup/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/carve-markup)](https://wordpress.org/plugins/carve-markup/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/carve-markup)](https://wordpress.org/plugins/carve-markup/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)
[![WordPress 6.3+](https://img.shields.io/badge/WordPress-6.3%2B-blue.svg)](https://wordpress.org)

A WordPress plugin for the [Carve](https://github.com/markup-carve/carve) markup language - a Djot-inspired, post-Markdown dialect. 
Render Carve in posts, pages and comments, powered by the [`markup-carve/carve-php`](https://github.com/markup-carve/carve-php) engine.

## Features

- **Carve rendering** in posts/pages (per-post "Render as Carve" toggle), the `[carve]…[/carve]` shortcode, and comments.
- **Gutenberg block** with a source editor and preview.
- **In-browser live preview.** Carve has a real JS engine (`@markup-carve/carve`), so the block previews **instantly client-side**, no server round-trip. Run `npm run build` to bundle the engine (`assets/js/vendor/carve.js`); without it the editor falls back to the REST render endpoint.
- **Content profiles** (`full` / `article` / `comment` / `minimal`) + **safe mode** (XSS hardening) via carve-php's `Profile` + `SafeMode`.
- **Table of contents**, **heading permalinks**, **smart quotes**, **Mermaid**, **tab normalization**, **Torchlight syntax highlighting** - carve-php extensions, toggled in settings.
- **Media embeds** via `:youtube[ID]` / `:vimeo[ID]` / `:media[URL]`, with a WordPress **oEmbed fallback** when the media-embed extension is off.
- **Multi-format paste.** Paste Markdown / Djot / BBCode / HTML and convert to Carve in place, using carve-php's `*ToCarve` converters. `POST /wp-json/carve/v1/ingest`.
- **Frontmatter → meta/SEO.** Typed `---yaml` / `---json` / `---toml` frontmatter maps to excerpt, SEO description (Yoast/AIOSEO when present), canonical, and `_wpcarve_frontmatter` meta. Non-destructive.
- **Bulk migrate**: convert existing posts to Carve in bulk. `wp carve migrate` - analyzes each post (skips block-editor / foreign-shortcode content), auto-detects Markdown vs HTML, converts safely (`--dry-run`, `--force`); or **Tools → Carve Migrate** for the same thing without CLI (the post list doubles as the dry-run preview). `wp carve lint` - read-only health check reporting render errors and visual-editor round-trip caveats.
- **Import / export**: Tools → Carve Import loads a Markdown / Djot / HTML / Carve file as a draft; an "Export Carve" row action downloads a post's `.crv` source.
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

## REST API

| Route | Body | Returns |
| --- | --- | --- |
| `POST /wp-json/carve/v1/render` | `{ carve, context }` | `{ html }` |
| `POST /wp-json/carve/v1/ingest` | `{ source, from }` | `{ carve, from }` |

Both require the `edit_posts` capability.

## Hooks

- `wpcarve_rendered_html` (filter): `(string $html, string $carve, string $context)`
- `wpcarve_converter` (action): `(\MarkupCarve\Carve\CarveConverter $converter, string $context)` - register further carve-php extensions.
- `wpcarve_source` (filter): `(string $carve, string $context)` - modify source before conversion.

See [docs/hooks.md](docs/hooks.md) for the full list (oEmbed, OG image, diagram renderer API, KaTeX base).

## Documentation

See [`docs/`](docs/README.md): [settings](docs/settings.md),
[profiles & rendering](docs/profiles.md), [hooks](docs/hooks.md),
[WP-CLI](docs/wp-cli.md), [Carve syntax](docs/syntax.md).

## Roadmap

- Visual editor: a Tiptap WYSIWYG editor ships behind the `visual_editor_mode` setting; headings, marks, lists, quotes, code blocks, tables, math, footnotes, admonitions and nested containers (tabs, code-groups) all round-trip (see [docs/visual-editor.md](docs/visual-editor.md)). Interactive per-widget editing of tabs/code-groups (a tab bar in the editor) is future work.
- Native per-construct blocks (admonition, code-group, table-with-spans).
- Lossless HTML ↔ Carve round-trip editing.
