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

### `wp_carve_diagram_renderers`

Register a custom diagram renderer (or modify a built-in). Each entry gets a
`{name}_enabled` setting, conditional script loading, and a `FencedRenderExtension`
registration automatically - no plugin change.

```php
add_filter('wp_carve_diagram_renderers', function (array $renderers): array {
    $renderers['nomnoml'] = [
        'label'  => 'nomnoml UML',
        'class'  => 'nomnoml',          // fence word + CSS class
        'preset' => null,                // null => generic FencedRenderExtension
        'mode'   => 'text',              // 'text' | 'json'
        'libs'   => [],                  // file names under assets/js/vendor/
        'src'    => ['https://example.test/graphre.js', 'https://example.test/nomnoml.js'],
        'init'   => '/* JS that renders .wp-carve .nomnoml elements */',
        'url'    => 'https://nomnoml.com',          // shown as a link icon on the card
        'preview'=> 'https://example.test/nomnoml.svg', // popover thumbnail
    ];
    return $renderers;
});
```

The built-in types (mermaid, chart, vega, graphviz, wavedrom, abc) are the default
contents of this same array.

### `wp_carve_diagram_src`

Override a diagram library URL (e.g. point a built-in renderer at a CDN). Receives
the default URL, the renderer name, and the library file name.

```php
add_filter('wp_carve_diagram_src', function (string $url, string $name, string $lib): string {
    return $name === 'mermaid' ? 'https://example.test/mermaid.min.js' : $url;
}, 10, 3);
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
