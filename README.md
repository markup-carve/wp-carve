# Carve Markup for WordPress (wp-carve)

A WordPress plugin for the [Carve](https://github.com/markup-carve/carve) markup language — a post-Djot, post-Markdown dialect. Render Carve in posts, pages and comments, powered by the [`markup-carve/carve-php`](https://github.com/markup-carve/carve-php) engine.

Modeled on [`wp-djot`](https://github.com/php-collective/wp-djot), with four things wp-djot doesn't do (see **Beyond wp-djot**).

## Features

- **Carve rendering** in posts/pages (per-post "Render as Carve" toggle), the `[carve]…[/carve]` shortcode, and comments.
- **Gutenberg block** with a source editor and preview.
- **Content profiles** (`full` / `article` / `comment` / `minimal`) + **safe mode** (XSS hardening) via carve-php's `Profile` + `SafeMode`.
- **Table of contents**, **heading permalinks**, **smart quotes**, **Mermaid**, **tab normalization** — carve-php extensions, toggled in settings.
- **WP-CLI migration**: `wp carve migrate --from=html|markdown`.

## Beyond wp-djot

- **A — In-browser live preview.** Carve has a real JS engine (`@markup-carve/carve`), so the block previews **instantly client-side**, no server round-trip. Run `npm run build` to bundle the engine (`assets/js/vendor/carve.js`); without it the editor falls back to the REST render endpoint.
- **B — Multi-format paste.** Paste Markdown / Djot / BBCode / HTML and convert to Carve in place, using carve-php's `*ToCarve` converters. `POST /wp-json/carve/v1/ingest`.
- **C — Frontmatter → meta/SEO.** Typed `---yaml` / `---json` / `---toml` frontmatter maps to excerpt, SEO description (Yoast/AIOSEO when present), canonical, and `_wp_carve_frontmatter` meta. Non-destructive.
- **E — Render caching + REST.** Rendered HTML is cached in post meta at save (fast views); `POST /wp-json/carve/v1/render` serves headless WordPress.

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
- `wp_carve_converter` (action): `(\Carve\CarveConverter $converter, string $context)` — register further carve-php extensions.

## Roadmap

- Full TipTap-style WYSIWYG visual editor (wp-djot has one; v0.1 ships a source+preview block).
- Native per-construct blocks (admonition, code-group, table-with-spans).
- Torchlight syntax highlighting extension.
- Lossless HTML ↔ Carve round-trip editing.

## License

MIT
