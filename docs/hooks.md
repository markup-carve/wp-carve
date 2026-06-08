# Hooks

## Filters

### `wp_carve_rendered_html`

Filter the rendered HTML before it is returned to WordPress.

```php
add_filter('wp_carve_rendered_html', function (string $html, string $carve, string $context): string {
    // $context is 'post' or 'comment'.
    return $html;
}, 10, 3);
```

### `wp_carve_mermaid_src`

Override the Mermaid script URL (default: the vendored single-file UMD build).

```php
add_filter('wp_carve_mermaid_src', fn (string $url): string => 'https://example.test/mermaid.min.js');
```

### `wp_carve_katex_base`

Override the base URL for KaTeX assets (css / js / `contrib/auto-render.min.js`).

```php
add_filter('wp_carve_katex_base', fn (string $base): string => 'https://example.test/katex');
```

## Actions

### `wp_carve_converter`

Register additional carve-php extensions on the converter as it is built (once
per context).

```php
use Carve\CarveConverter;

add_action('wp_carve_converter', function (CarveConverter $converter, string $context): void {
    if ($context === 'post') {
        $converter->addExtension(new MyExtension());
    }
}, 10, 2);
```

This is the supported extension point: anything carve-php exposes as an
`ExtensionInterface` can be wired in without patching the plugin.
