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

### `wpcarve_source_footer_html`

Filter the complete attribution/source footer after its safe built-in markup is
assembled. Receives the footer HTML, post, and effective source mode (`none`,
`view`, `download`, or `both`). Returning an empty string suppresses it.

```php
add_filter('wpcarve_source_footer_html', function (string $html, WP_Post $post, string $mode): string {
    return $html;
}, 10, 3);
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

### `wpcarve_enqueue_styles`

Filter whether the front-end stylesheet loads on the current view. It defaults
to `true` only where Carve is rendered (a Carve post/block, a `[carve]`
shortcode in the queried content, or an open comment form). Return `true` to
force it - e.g. when a widget or page builder injects `[carve]` outside the
queried post content - or `false` to suppress it.

```php
add_filter('wpcarve_enqueue_styles', function (bool $enqueue): bool {
    return $enqueue || is_active_sidebar('footer');
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

### `wpcarve_kroki_server`

Base URL of the [Kroki](https://kroki.io) server used to render PlantUML diagrams
(the `plantuml`/`puml` renderer has no browser library, so `diagrams.js` POSTs the
source to Kroki and embeds the returned SVG). Default `https://kroki.io`. Point it
at a self-hosted Kroki instance to keep diagram sources off the public service.

```php
add_filter('wpcarve_kroki_server', fn (string $base): string => 'https://kroki.internal');
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

### `wpcarve_internal_hosts`

Hosts that count as this site, so everything else is external. Used when
`external_links` is on. Defaults to the host of `home_url()`; add staging
domains, mapped multisite hosts or a CDN.

``` php
add_filter('wpcarve_internal_hosts', function (array $hosts): array {
    $hosts[] = 'staging.example.com';

    return $hosts;
});
```

### `wpcarve_mention_url` / `wpcarve_tag_url`

The `{name}` URL template a mention or tag links to, when `mentions_enabled`
is on. Defaults to `home_url('/author/{name}/')` and `home_url('/tag/{name}/')`.
Replace the whole template if your author base or permalink structure differs,
or to route mentions somewhere other than an archive.

``` php
add_filter('wpcarve_mention_url', fn (string $template): string => 'https://social.example/@{name}');
```

### `wpcarve_wikilink_url`

Resolve `[[Page Title]]` yourself when `wikilinks_enabled` is on. Return a URL
string to use it, or `null` to fall through to the default lookup (a post or
page whose slug matches the sanitized title). An unresolved link becomes `#`
and keeps its `wikilink` class, so it can be styled as a broken link rather
than silently dropped.

``` php
add_filter('wpcarve_wikilink_url', function (?string $url, string $page): ?string {
    $match = get_posts(['title' => $page, 'numberposts' => 1, 'post_type' => 'docs']);

    return $match ? (string)get_permalink($match[0]) : $url;
}, 10, 2);
```

## Actions

### `wpcarve_converter`

Register additional carve-php extensions on the converter as it is built (once
per context).

`$context` is one of:

- `post` - front-end post/page rendering.
- `comment` - comment rendering (uses the comment profile).
- `feed` - RSS and Atom output. Renders like `post` but in the engine's static
  mode, because nothing in a feed reader runs this plugin's JavaScript, so a
  diagram's hydration container would arrive empty. Register content extensions
  for it alongside `post`; anything that needs client-side JavaScript to appear
  is pointless here.
- `editor` - the compatibility render used by linting and REST clients that
  request an editor-safe HTML seed. The built-in Gutenberg visual editor now
  loads source through the JavaScript AST bridge, but this context remains for
  integrations that consume editor-safe rendered HTML.

Gate accordingly: apply **round-trippable content extensions** for both `post`
and `editor`, but apply
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
