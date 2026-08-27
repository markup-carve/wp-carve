# Engine extensions

carve-php ships more extensions than this plugin registers. Every one of them is
reachable through the [`wpcarve_converter`](hooks.md) action without patching
the plugin, so "not registered by default" is never "unavailable".

This page accounts for each absence. An unexplained one reads as an oversight
rather than a decision, and the question gets re-asked at every release.

## Registered by default

`Citations`, `CodeGroup`, `Details`, `FencedRender`, `Frontmatter`,
`HeadingLevelShift`, `HeadingPermalinks`, `ImgFence`, `ListTable`, `MediaEmbed`,
`SemanticSpan`, `SmartQuotes`, `Spoiler`, `TableOfContents`, `TabNormalize`,
`Tabs`, `Torchlight`.

Most sit behind a setting - see [settings](settings.md).

## Not registered, because the construct already works

Registering these would change nothing.

| extension | why |
| --- | --- |
| `Admonition` | `::: note` already renders an `<aside class="admonition note">` |
| `InlineFootnotes` | `^[a note]` already renders a numbered footnote with a backlink |

Note the separator: `::: note` opens an admonition and `:::note` does not.

## Not registered, because it is rendered elsewhere

| extension | why |
| --- | --- |
| `MathBlock` | math renders client-side, through the vendored KaTeX auto-render pass the plugin enqueues |

## Not registered, because it is the site's editorial decision

Each of these rewrites or generates content on every post. A plugin default
would be making an editorial choice on the site's behalf, so they are off and
the choice is yours.

| extension | what it does | the risk in enabling it blindly |
| --- | --- | --- |
| `Autolink` | bare URLs become links | changes existing prose |
| `ExternalLinks` | adds `target`/`rel` to off-site links | needs the site's own host list |
| `Mentions` | `@name` and `#tag` become links | only the site knows where they point |
| `Wikilinks` | `[[Page]]` becomes a link | needs a resolver against real posts |
| `HeadingNumbers` | numbers every heading | changes every heading on the site |
| `PlusBullet` | `+` opens a list | reinterprets text that was not a list |
| `LowercaseHeadingIds` | lowercases heading anchors | **changes existing anchors, breaking inbound links** |
| `AsciiHeadingIds` | transliterates anchors to ASCII | same, and it mangles non-English titles |
| `DefaultAttributes` | applies attributes the author did not write | surprising in a round-tripping editor |

## Not registered, because the construct is not part of this plugin's surface

Each would need its own settings, styles and documentation before it could be
turned on for a site.

`CodeCallouts`, `ColorSwatch`, `Glossary`, `Index`, `HeadingReference`,
`TocPlacement`.

## Enabling one

Gate on the context. Apply round-trippable content extensions for both `post`
and `editor`; apply anything that injects generated markup to `post` only, so
the visual editor does not serialize it back into the source.

``` php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;

add_action('wpcarve_converter', function (CarveConverter $converter, string $context): void {
    if ($context !== 'post') {
        return;
    }

    $converter->addExtension(new ExternalLinksExtension(
        internalHosts: [wp_parse_url(home_url(), PHP_URL_HOST)],
        target: '_blank',
    ));
}, 10, 2);
```

`Mentions` and `Wikilinks` take the site's own URL shapes:

``` php
new MentionsExtension(mentionUrl: '/author/{name}', tagUrl: '/tag/{name}');

new WikilinksExtension(urlGenerator: static function (string $page): string {
    $post = get_page_by_path(sanitize_title($page), OBJECT, ['post', 'page']);

    return $post ? (string)get_permalink($post) : '#';
});
```
