# Engine extensions

On Carve 0.2 an extension is an `ExtensionDefinition` passed in the engine's
`extensions` option. The 0.2 extension API is provisional. Add your own through
the [`wpcarve_extensions`](hooks.md#wpcarve_extensions) filter; the 0.1
`wpcarve_converter` action and its `ExtensionInterface` classes are gone.

## What the plugin registers

| Feature | 0.2 mechanism | Setting |
| --- | --- | --- |
| Tabs (`:::: tabs`) | carve-php standard `Extension\Tabs` | `extension_tabs` |
| Details, spoiler (`::: details`, `::: spoiler`) | `Extension\Details`, `Extension\Spoiler` | `extension_details`, `extension_spoiler` |
| Code group (`::: code-group`) | `Extension\CodeGroup` | `extension_code_group` |
| List table (`::: list-table`) | `Extension\ListTable` | `extension_list_table` |
| SVG image fence (```` ```img ````) | carve-php standard `Extension\ImgFence` | `extension_img_fence` |
| Semantic spans (`[Ctrl]{kbd}`, `[HTML]{abbr="..."}`) | `Extension\SemanticSpan` | `extension_semantic_span` |
| Heading numbers | `Navigation::headingNumbers()` | `heading_numbers` |
| Citations (`:cite[key]` with a CSL-JSON bibliography) | `Extension\Citations` | block panel |
| Torchlight highlighting | `codeBlockRenderer` option | `torchlight_enabled` |
| Tab normalization | `Transforms::tabNormalize()` | `normalize_tabs`, `tab_width` |
| Smart-quote locale | `SmartQuotes::bundle()` | `smart_quotes_locale` |
| Diagram fences (Mermaid, Chart.js, Vega-Lite, Graphviz, WaveDrom, ABC, PlantUML) | `WpCarve\Extension\DiagramFences` | one per diagram |
| Wiki links (`:wiki[Page]`) | carve-php standard `carve.wiki` definition plus a document transform that writes the link | `wikilinks_enabled` |
| Heading level shift | document transform | `heading_shift` |
| Mentions and tags | core syntax, `social` option for the archive URLs | `mentions_enabled` |
| Smart typography | core, `smartTypography` option | `smart_quotes` |
| Soft breaks | core, `softBreak` option | `post_soft_break`, `comment_soft_break` |
| Frontmatter | core `frontmatter` node | always on |
| Table of contents, `::: toc`, heading permalinks, external link attributes | host processing of the rendered HTML | `toc_enabled`, `permalinks_enabled`, `external_links` |

`::: toc` renders wherever the author wrote it, whether or not `toc_enabled` is
on; that setting controls the contents list the plugin injects at the top or
bottom of a post. Generated markup stays out of the visual-editor seed, so the
editor serializes the directive back to `::: toc`.

The `extension_*` toggles are the plugin's extension catalog
(`WpCarve\Extensions`). The in-browser preview reads the same enabled names
and registers the carve-js bundle of each, so the preview and the published
post agree.

## Waiting for the 0.2 engine

- Media embeds: [carve-php-media-embed#23](https://github.com/markup-carve/carve-php-media-embed/issues/23).

## Adding one

Gate on the context. Apply round-trippable content extensions for both `post`
and `editor`; apply anything that injects generated markup to `post` only, so
the visual editor does not serialize it back into the source.

``` php
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Node;

add_filter('wpcarve_extensions', function (array $extensions, string $context): array {
    if ($context !== 'post') {
        return $extensions;
    }

    $extensions[] = new ExtensionDefinition(
        'acme.shout',
        '1',
        matcher: ['form' => 'fence', 'name' => 'shout'],
        transform: static function (Claim $claim): Node {
            $node = new Node('block_extension', []);
            $node->f = ['name' => 'acme.shout', 'version' => '1', 'fallback' => [$claim->copyNode()]];

            return $node;
        },
        renderer: static fn (Node $node, RenderHost $host): string => '<p class="shout">LOUD</p>',
    );

    return $extensions;
}, 10, 2);
```

The renderer is trusted host code: its HTML is inserted unchanged, and then
passes through `wp_kses` with the rest of the post.
