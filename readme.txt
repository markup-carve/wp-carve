=== Carve Markup ===
Contributors: dereuromark
Tags: carve, markup, markdown, djot, editor
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Write posts, pages and comments in Carve markup: a visual editor, live preview, multi-format paste, frontmatter-to-meta and a REST API.

== Description ==

Carve is a post-Djot, post-Markdown markup language. This plugin renders Carve to HTML in WordPress, powered by the markup-carve/carve-php engine.

Features: per-post "Render as Carve" mode, a [carve] shortcode, a Gutenberg block with a visual (WYSIWYG) editor, comment support, content profiles and safe mode, table of contents, heading permalinks, smart quotes, Mermaid, and WP-CLI migration.

Beyond a typical markup plugin:

* Visual editor with Write / Split / Visual / Preview tabs. Edit visually or in source; every change round-trips to canonical Carve, and you are only warned when visual editing would change the rendered output.
* Instant in-browser preview using the Carve JS engine (no server round-trip).
* Paste or import Markdown, Djot, BBCode or HTML and convert to Carve in place; export any post as a .carve file.
* Map typed frontmatter (yaml/json/toml) to excerpt, SEO and post meta.
* Cache rendered HTML at save time; render via REST for headless WordPress.

== Installation ==

1. Install into wp-content/plugins and run `composer install --no-dev`.
2. Optional: `npm install && npm run build` for instant in-browser preview.
3. Activate, then configure under Settings -> Carve Markup.

== Changelog ==

= 0.1.0 =
* Initial release: render Carve in posts, pages and comments; [carve] shortcode and carve/markup + carve/slides blocks.
* Visual (WYSIWYG) editor with Write / Split / Visual / Preview tabs, a unified toolbar, an in-block code-language picker and keyboard shortcuts.
* Import Markdown / Djot / BBCode / HTML and export posts as .carve.
* Live in-browser preview, frontmatter-to-meta, content profiles + safe mode, table of contents, heading permalinks, smart quotes, diagram renderers, media embeds, bundled syntax highlighting, render caching and a REST endpoint.
