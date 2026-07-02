# Carve Markup for WordPress (wp-carve)

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
- **Frontmatter → meta/SEO.** Typed `---yaml` / `---json` / `---toml` frontmatter maps to excerpt, SEO description (Yoast/AIOSEO when present), canonical, and `_wp_carve_frontmatter` meta. Non-destructive.
- **WP-CLI migration**: `wp carve migrate` - analyzes each post (skips block-editor / foreign-shortcode content), auto-detects Markdown vs HTML, converts safely (`--dry-run`, `--force`).
- **Import / export**: Tools → Carve Import loads a Markdown / Djot / HTML / Carve file as a draft; an "Export Carve" row action downloads a post's `.carve` source.
- **Render caching + REST.** Rendered HTML is cached in post meta at save (fast views); `POST /wp-json/carve/v1/render` serves headless WordPress.

## Requirements

- PHP 8.2+, WordPress 6.0+

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

- `wp_carve_rendered_html` (filter): `(string $html, string $carve, string $context)`
- `wp_carve_converter` (action): `(\MarkupCarve\Carve\CarveConverter $converter, string $context)` - register further carve-php extensions.
- `wp_carve_source` (filter): `(string $carve, string $context)` - modify source before conversion.

See [docs/hooks.md](docs/hooks.md) for the full list (oEmbed, OG image, diagram renderer API, KaTeX base).

## Documentation

See [`docs/`](docs/README.md): [settings](docs/settings.md),
[profiles & rendering](docs/profiles.md), [hooks](docs/hooks.md),
[WP-CLI](docs/wp-cli.md), [Carve syntax](docs/syntax.md).

## Roadmap

- Visual editor: a Tiptap WYSIWYG **foundation** ships behind the `visual_editor_mode` setting (core constructs round-trip; see [docs/visual-editor.md](docs/visual-editor.md)). Full per-construct parity (footnotes, tables, tabs, code-groups, math) is in progress.
- Native per-construct blocks (admonition, code-group, table-with-spans).
- Lossless HTML ↔ Carve round-trip editing.

## License

MIT
