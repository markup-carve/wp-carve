# Hooks

## Filters

### `wpcarve_source`

Filter the raw Carve source **before** conversion (e.g. inject snippets, expand
tokens). Runs before abbreviation defs are prepended.

```php
add_filter('wpcarve_source', function (string $carve, string $context): string {
    return str_replace('{{year}}', gmdate('Y'), $carve);
}, 10, 2);
```

### `wpcarve_rendered_html`

Filter the rendered HTML before it is returned to WordPress.

```php
add_filter('wpcarve_rendered_html', function (string $html, string $carve, string $context): string {
    // $context is 'post', 'comment', or 'editor' (the visual-editor seed - see below).
    return $html;
}, 10, 3);
```

### `wpcarve_allowed_html`

Filter the wp_kses allowlist applied to all rendered Carve HTML (sanitization is
unconditional). Starts from the core `post` allowlist plus task-list checkboxes
and media-embed iframes.

```php
add_filter('wpcarve_allowed_html', function (array $allowed): array {
    $allowed['video'] = ['src' => true, 'controls' => true];
    return $allowed;
});
```

### `wpcarve_media_oembed`

Return `false` to disable the WordPress oEmbed fallback for standalone
`:youtube[…]` / `:vimeo[…]` / `:media[…]` when the media-embed extension is off.

```php
add_filter('wpcarve_media_oembed', '__return_false');
```

### `wpcarve_auto_og_image`

Return `false` to suppress the automatic `og:image` (first `![](url)` in a Carve
post without a featured image).

```php
add_filter('wpcarve_auto_og_image', '__return_false');
```

### `wpcarve_diagram_renderers`

Register a custom diagram renderer (or modify a built-in). Each entry gets a
`{name}_enabled` setting, conditional script loading, and a `FencedRenderExtension`
registration automatically - no plugin change.

```php
add_filter('wpcarve_diagram_renderers', function (array $renderers): array {
    $renderers['nomnoml'] = [
        'label'  => 'nomnoml UML',
        'class'  => 'nomnoml',          // fence word + CSS class
        'preset' => null,                // null => generic FencedRenderExtension
        'mode'   => 'text',              // 'text' | 'json'
        'libs'   => [],                  // file names under assets/js/vendor/
        'src'    => ['https://example.test/graphre.js', 'https://example.test/nomnoml.js'],
        'init'   => '/* JS that renders .wpcarve .nomnoml elements */',
        'url'    => 'https://nomnoml.com',          // shown as a link icon on the card
        'preview'=> 'https://example.test/nomnoml.svg', // popover thumbnail
    ];
    return $renderers;
});
```

The built-in types (mermaid, chart, vega, graphviz, wavedrom, abc) are the default
contents of this same array.

### `wpcarve_diagram_src`

Override a diagram library URL (e.g. point a built-in renderer at a CDN). Receives
the default URL, the renderer name, and the library file name.

```php
add_filter('wpcarve_diagram_src', function (string $url, string $name, string $lib): string {
    return $name === 'mermaid' ? 'https://example.test/mermaid.min.js' : $url;
}, 10, 3);
```

### `wpcarve_katex_base`

Override the base URL for KaTeX assets (css / js / `contrib/auto-render.min.js`).

```php
add_filter('wpcarve_katex_base', fn (string $base): string => 'https://example.test/katex');
```

### `wpcarve_preview_rate_limit`

Number of anonymous comment-preview requests (`POST /wp-json/carve/v1/preview-comment`)
allowed per window before the endpoint responds `429`. Default `30`. Return a
value `<= 0` to disable the throttle. Users who can edit posts are never
throttled. Raise it for a site behind a shared-IP CDN or reverse proxy.

```php
add_filter('wpcarve_preview_rate_limit', fn (int $max): int => 100);
```

### `wpcarve_preview_rate_window`

Length of the comment-preview rate-limit window, in seconds. Default
`MINUTE_IN_SECONDS` (60).

```php
add_filter('wpcarve_preview_rate_window', fn (int $seconds): int => 30);
```

## Actions

### `wpcarve_converter`

Register additional carve-php extensions on the converter as it is built (once
per context).

`$context` is one of:

- `post` - front-end post/page rendering.
- `comment` - comment rendering (uses the comment profile).
- `editor` - the **visual-editor seed**. The Visual (WYSIWYG) editor renders the
  source to HTML, then serializes that HTML back to Carve on every edit. Any
  *generated* markup (a table of contents, heading permalink anchors, shifted
  heading levels) would be frozen into the source on that round trip, so the
  built-in extensions that emit it are skipped for `editor`.

Gate accordingly: apply **round-trippable content extensions** for both `post`
and `editor` so Visual mode previews and round-trips faithfully, but apply
extensions that **inject generated markup** for `post` only.

```php
use MarkupCarve\Carve\CarveConverter;

add_action('wpcarve_converter', function (CarveConverter $converter, string $context): void {
    // Round-trippable content extension: also wanted in the visual editor.
    if (in_array($context, ['post', 'editor'], true)) {
        $converter->addExtension(new MyContentExtension());
    }
    // Generated markup that can't survive the round trip: front-end only.
    if ($context === 'post') {
        $converter->addExtension(new MyTocLikeExtension());
    }
}, 10, 2);
```

This is the supported extension point: anything carve-php exposes as an
`ExtensionInterface` can be wired in without patching the plugin.
