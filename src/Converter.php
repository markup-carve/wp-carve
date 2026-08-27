<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\HeadingLevelShiftExtension;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\ImgFenceExtension;
use MarkupCarve\Carve\Extension\ListTableExtension;
use MarkupCarve\Carve\Extension\MentionsExtension;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Extension\SmartQuotesExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TabNormalizeExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use MarkupCarve\Carve\SafeMode;
use MarkupCarve\MediaEmbed\MediaEmbedExtension;
use WpCarve\Extension\TorchlightExtension;

/**
 * WordPress-facing wrapper around the carve-php CarveConverter.
 *
 * Builds a converter per context (post vs comment) applying the configured
 * content profile and feature extensions, and renders Carve to HTML or plain
 * text.
 */
class Converter
{
    /**
     * @var array<string, \MarkupCarve\Carve\CarveConverter>
     */
    private array $cache = [];

    /**
     * @param array<string, mixed> $settings Resolved plugin settings.
     */
    public function __construct(private array $settings)
    {
    }

    public static function fromSettings(): self
    {
        return new self(Settings::all());
    }

    /**
     * Render Carve source to HTML for a given context ('post', 'comment', or
     * 'editor' - the visual-editor seed, which omits non-round-trippable markup).
     *
     * $profileOverride forces a specific content profile (full / article /
     * comment / minimal / none) regardless of the context default - used by the
     * Carve block's per-block profile attribute.
     */

    /**
     * @param string $carve
     * @param string $context
     * @param string|null $profileOverride
     * @param bool|null $safe
@param array<int, mixed>|null $bibliography
     * @param string $citationMode
     */
    public function toHtml(
        string $carve,
        string $context = 'post',
        ?string $profileOverride = null,
        ?bool $safe = null,
        ?array $bibliography = null,
        string $citationMode = 'numbered',
    ): string {
        if (trim($carve) === '') {
            return '';
        }

        /**
         * Filter the raw Carve source before it is converted.
         *
         * @param string $carve The Carve source.
         * @param string $context 'post', 'comment', or 'editor'.
         */
        $carve = (string)apply_filters('wpcarve_source', $carve, $context);

        // Site-wide abbreviation defs are prepended for rendering, but the visual
        // editor seed must not carry them: they render <abbr> spans that serialize
        // back into per-post source (freezing a global setting into the post). So
        // the editor context renders the source alone.
        $abbrevDefs = $context === 'editor' ? '' : $this->abbreviationDefs();
        $html = $this->converterFor($context, $profileOverride, $safe, $bibliography, $citationMode)->convert($abbrevDefs . $carve);

        // JSON diagram configs (chart, vega-lite) ship in a script tag the
        // engine emits - but wp_kses strips every script tag, and wptexturize
        // then curls the quotes of the leftover text, so the config could
        // never reach the front-end JS intact. Move it into a data attribute:
        // kses allows data-* globally and texturize ignores attributes.
        // Chart.js configs additionally get their dataset appended as a plain
        // table - readable without JS, indexable, and screen-reader friendly.
        // The container carries engine-authored attributes besides the class
        // - carve-php 0.1.6 began emitting role="img" and an aria-label - so
        // they are matched and carried over rather than dropped. Requiring the
        // class to be the only attribute silently stopped matching when those
        // arrived, which left the config in a script tag for kses to strip.
        $html = (string)preg_replace_callback(
            '/<div class="([^"]*)"([^>]*)>\s*<script type="application\/json">(.*?)<\/script>\s*<\/div>/s',
            static function (array $m): string {
                $out = '<div class="' . $m[1] . '"' . $m[2] . ' data-carve-json="' . esc_attr($m[3]) . '"></div>';
                if (preg_match('/(^| )chart( |$)/', $m[1]) === 1) {
                    $out .= self::chartDataTable($m[3]);
                }

                return $out;
            },
            $html,
        );

        // See the note in addPostExtensions: an external link with no target
        // still gets an empty `target=""`. Anchored to the attribute run this
        // extension writes, so a `target=""` an author wrote by hand in
        // borrowed HTML is left alone.
        if (!empty($this->settings['external_links']) && empty($this->settings['external_links_new_tab'])) {
            $html = (string)preg_replace('/(<a\b[^>]*?) target=""/', '$1', $html);
        }

        // Rendering is always sanitized: the engine escapes raw HTML and strips
        // event handlers, and the generated markup additionally passes through
        // wp_kses so only allowlisted tags/attributes ever reach output. There is
        // no unsafe/raw-HTML passthrough - script/style can never be emitted.
        $html = self::sanitizeHtml($html);

        /**
         * Filter the rendered HTML before it is returned to WordPress.
         *
         * @param string $html The rendered HTML.
         * @param string $carve The original Carve source.
         * @param string $context 'post', 'comment', or 'editor'.
         */
        return (string)apply_filters('wpcarve_rendered_html', $html, $carve, $context);
    }

    /**
     * Render Carve source as plain text for a given context. The text renderer
     * emits no markup; HTML safe mode and HTML-only output processing therefore
     * do not apply.
     */
    public function toText(string $carve, string $context = 'post', ?string $profileOverride = null): string
    {
        if (trim($carve) === '') {
            return '';
        }

        /**
         * Filter the raw Carve source before it is converted.
         *
         * @param string $carve The Carve source.
         * @param string $context 'post', 'comment', or 'editor'.
         */
        $carve = (string)apply_filters('wpcarve_source', $carve, $context);

        $abbrevDefs = $context === 'editor' ? '' : $this->abbreviationDefs();

        return $this->textConverterFor($context, $profileOverride)->convert($abbrevDefs . $carve);
    }

    /**
     * A collapsed data table for a Chart.js config: rows per label, one column
     * per dataset. The chart itself needs JS; this fallback is always in the
     * DOM, so the numbers stay accessible, indexable and copyable. Returns ''
     * for configs without a labels/datasets shape (Vega specs etc.).
     */
    public static function chartDataTable(string $json): string
    {
        $config = json_decode($json, true);
        if (!is_array($config)) {
            return '';
        }
        $labels = $config['data']['labels'] ?? null;
        $datasets = $config['data']['datasets'] ?? null;
        if (!is_array($labels) || $labels === [] || !is_array($datasets) || $datasets === []) {
            return '';
        }

        $head = '<th scope="col"></th>';
        foreach ($datasets as $i => $set) {
            $name = is_array($set) && isset($set['label']) && is_scalar($set['label'])
                ? (string)$set['label']
                /* translators: %d: 1-based index of an unnamed chart data series. */
                : sprintf(__('Series %d', 'carve-markup'), $i + 1);
            $head .= '<th scope="col">' . esc_html($name) . '</th>';
        }

        $rows = '';
        foreach (array_values($labels) as $r => $label) {
            $rows .= '<tr><th scope="row">' . esc_html(is_scalar($label) ? (string)$label : '') . '</th>';
            foreach ($datasets as $set) {
                $value = is_array($set) && isset($set['data'][$r]) && is_scalar($set['data'][$r])
                    ? (string)$set['data'][$r]
                    : '';
                $rows .= '<td>' . esc_html($value) . '</td>';
            }
            $rows .= '</tr>';
        }

        return '<details class="wpcarve-chart-data"><summary>' . esc_html__('Chart data', 'carve-markup') . '</summary>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></details>';
    }

    /**
     * Site-wide abbreviation definitions, from a `KEY: expansion` per line
     * setting, emitted as Carve `*[KEY]: expansion` definition lines that render
     * nothing themselves but turn matching words into <abbr> across all content.
     */
    private function abbreviationDefs(): string
    {
        $raw = (string)($this->settings['abbreviations'] ?? '');
        if (trim($raw) === '') {
            return '';
        }
        $defs = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key === '' || $value === '') {
                continue;
            }
            $defs[] = '*[' . $key . ']: ' . $value;
        }

        return $defs !== [] ? implode("\n", $defs) . "\n\n" : '';
    }

    /**
     * The engine safe-mode configuration for a context. Sanitization is always
     * on - there is no setting or capability that turns it off.
     *
     * Comments are untrusted: raw HTML is stripped outright (and the style
     * attribute blocked) by the engine, before wp_kses runs.
     *
     * Authored surfaces (posts, pages, blocks, the editor seed) use ALLOW, so
     * that raw HTML - written with Djot's explicit `=html` raw block / inline
     * syntax and only permitted by a profile that allows raw nodes (the Full
     * profile; the default Article profile still denies it) - renders instead of
     * being escaped. That raw HTML then passes through wp_kses with the Carve
     * allowlist (see toHtml/allowedHtml), which is the authoritative gate:
     * <script>/<style>, event handlers and unsafe URL schemes can never reach
     * output. This matches how core sanitizes author post content.
     */
    private function safeModeFor(string $context): SafeMode
    {
        if ($context === 'comment') {
            return SafeMode::strict();
        }

        return SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_ALLOW);
    }

    /**
     * Run rendered HTML through wp_kses with the Carve allowlist. This is the
     * authoritative sanitization gate: it strips <script>/<style>, drops event
     * handlers, and sanitizes inline styles and URL schemes. No-op only outside
     * WordPress (the unit suite has no wp_kses); the WP-integration checks
     * exercise the real filter.
     */
    public static function sanitizeHtml(string $html): string
    {
        if ($html === '' || !function_exists('wp_kses')) {
            return $html;
        }

        return wp_kses($html, self::allowedHtml());
    }

    /**
     * Allowed tags/attributes for sanitizing rendered Carve output: the core
     * `post` allowlist (which already permits class, id, role, and any aria- or
     * data- attribute globally) plus the elements Carve generates beyond it - task-list
     * checkboxes and media-embed iframes. URL attributes stay restricted to
     * core's allowed protocols, so javascript: URIs never survive.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedHtml(): array
    {
        $allowed = function_exists('wp_kses_allowed_html')
            ? wp_kses_allowed_html('post')
            : [];

        $allowed['input'] = [
            'type' => true,
            'checked' => true,
            'disabled' => true,
            'class' => true,
            'id' => true,
            // Radio groups for the CSS-only tabs / code-group widgets share a
            // `name` so exactly one panel shows at a time. Core's `post`
            // allowlist has no global `name`, so without this wp_kses strips it,
            // ungrouping the radios and breaking panel switching.
            'name' => true,
        ];
        $allowed['label'] = [
            'for' => true,
            'class' => true,
        ];
        $allowed['iframe'] = [
            'src' => true,
            'width' => true,
            'height' => true,
            'title' => true,
            'class' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'frameborder' => true,
            'loading' => true,
            'referrerpolicy' => true,
        ];

        /**
         * Filter the allowed HTML tags/attributes for sanitizing rendered Carve.
         *
         * @param array<string, array<string, bool>> $allowed wp_kses-style allowlist.
         */
        if (function_exists('apply_filters')) {
            /** @var array<string, array<string, bool>> */
            return apply_filters('wpcarve_allowed_html', $allowed);
        }

        return $allowed;
    }

    /**
     * @param string $context
     * @param string|null $profileOverride
     * @param bool|null $safe
@param array<int, mixed>|null $bibliography
     * @param string $citationMode
     */
    private function converterFor(
        string $context,
        ?string $profileOverride = null,
        ?bool $safe = null,
        ?array $bibliography = null,
        string $citationMode = 'numbered',
    ): CarveConverter {
        $isComment = $context === 'comment';
        // The visual editor seeds itself from rendered HTML and serializes it
        // back to Carve source on every edit. Generated, non-round-trippable
        // markup (a TOC nav, heading permalink anchors, shifted heading levels,
        // rendered diagram containers) would be frozen into the source on that
        // round trip, so the 'editor' context renders like a post but omits those
        // extensions (and the abbreviation defs, see toHtml()).
        $isEditor = $context === 'editor';
        $safeMode = $this->safeModeFor($context);
        $cacheKey = $context
            . ($profileOverride !== null && $profileOverride !== '' ? ':' . $profileOverride : '')
            . ':cite:' . $citationMode . ':' . md5((string)wp_json_encode($bibliography));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $profileName = $profileOverride !== null && $profileOverride !== ''
            ? $profileOverride
            : (string)($this->settings[$isComment ? 'comment_profile' : 'post_profile'] ?? ($isComment ? 'comment' : 'article'));
        $softBreak = (string)($this->settings[$isComment ? 'comment_soft_break' : 'post_soft_break'] ?? 'newline');

        $converter = new CarveConverter(
            safeMode: $safeMode,
            profile: $this->profile($profileName),
            softBreakMode: SoftBreakMode::tryFrom($softBreak) ?? SoftBreakMode::Newline,
        );

        // Carve preserves tabs by default; opt into normalization for consistent display.
        if (!empty($this->settings['normalize_tabs'])) {
            $converter->addExtension(new TabNormalizeExtension(width: (int)($this->settings['tab_width'] ?? 2)));
        }

        if (!$isComment) {
            $this->addPostExtensions($converter, $isEditor, $bibliography, $citationMode);
        }

        /**
         * Allow add-ons to register further carve-php extensions on the converter.
         *
         * The 'editor' context is the visual-editor seed: add-ons should apply
         * round-trippable content extensions for 'post' and 'editor' alike, but
         * generated markup (TOC-like) for 'post' only. See docs/hooks.md.
         *
         * @param \MarkupCarve\Carve\CarveConverter $converter
         * @param string $context 'post', 'comment', or 'editor'.
         */
        do_action('wpcarve_converter', $converter, $context);

        return $this->cache[$cacheKey] = $converter;
    }

    /**
     * Build the plain-text target with the same context profile and
     * parser-level configuration as the HTML target. Render-only extensions
     * are intentionally omitted because their output is HTML UI markup.
     */
    private function textConverterFor(string $context, ?string $profileOverride = null): CarveConverter
    {
        $isComment = $context === 'comment';
        $cacheKey = 'text:' . $context
            . ($profileOverride !== null && $profileOverride !== '' ? ':' . $profileOverride : '');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $profileName = $profileOverride !== null && $profileOverride !== ''
            ? $profileOverride
            : (string)($this->settings[$isComment ? 'comment_profile' : 'post_profile'] ?? ($isComment ? 'comment' : 'article'));
        $converter = CarveConverter::plainText()->setProfile($this->profile($profileName));

        // Footnote references remain useful in prose, but definitions are
        // document-end apparatus and should not become excerpt/search text.
        $renderer = $converter->getRenderer();
        if ($renderer instanceof PlainTextRenderer) {
            $renderer->on('render.footnote', static function (RenderEvent $event): void {
                $event->setHtml('');
            });
        }

        if (!empty($this->settings['normalize_tabs'])) {
            $converter->addExtension(new TabNormalizeExtension(width: (int)($this->settings['tab_width'] ?? 2)));
        }

        /**
         * Allow add-ons to register parser-level carve-php extensions on the
         * plain-text converter.
         *
         * @param \MarkupCarve\Carve\CarveConverter $converter
         * @param string $context 'post', 'comment', or 'editor'.
         */
        do_action('wpcarve_converter', $converter, $context);

        return $this->cache[$cacheKey] = $converter;
    }

    /**
     * @param \MarkupCarve\Carve\CarveConverter $converter
     * @param bool $forEditor Rendering to seed the visual editor. Skips generated
     *   markup that cannot survive the HTML -> Carve round trip (TOC, heading
     *   permalinks, heading level shift, diagram renderers), mirroring wp-djot's
     *   editor render path. All content-authoring extensions stay enabled.
     *   (Abbreviation defs are handled separately in toHtml().)
     */

    /**
     * The hosts that count as this site, so everything else is "external".
     *
     * A site can be reachable on more than one host - a staging domain, a
     * multisite mapping, a CDN - and only the site knows which. The filter is
     * the answer to that; the default is the one host WordPress is sure of.
     *
     * @return array<int, string>
     */
    private static function internalHosts(): array
    {
        $hosts = [];
        if (function_exists('home_url') && function_exists('wp_parse_url')) {
            $host = wp_parse_url((string)home_url(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        /** @var array<int, string> $filtered */
        $filtered = (array)apply_filters('wpcarve_internal_hosts', $hosts);

        return array_values(array_filter(array_map('strval', $filtered), static fn (string $h): bool => $h !== ''));
    }

    /**
     * A `{name}` URL template for an author or tag archive.
     *
     * Built from `home_url()` rather than `get_author_posts_url()` because the
     * extension needs a TEMPLATE and those functions need a resolved term - and
     * because a mention may name someone who is not a user here. Sites that
     * have moved their author base, or that route mentions somewhere else
     * entirely, replace the whole template through the filter.
     */
    private static function archiveTemplate(string $filter, string $base): string
    {
        $template = '/' . $base . '/{name}/';
        if (function_exists('home_url')) {
            $template = (string)home_url('/' . $base . '/{name}/');
        }

        return (string)apply_filters($filter, $template, $base);
    }

    /**
     * Resolve `[[Page Title]]` against real content.
     *
     * An unresolved link deliberately becomes `#` rather than disappearing or
     * guessing a URL: the anchor stays in the document, keeps its `wikilink`
     * class and its `data-wikilink` title, and a theme can style
     * `a.wikilink[href="#"]` as a broken link the way a wiki does. Silently
     * dropping it would hide the fact that a page is missing, which is the one
     * thing this construct is for.
     */
    private static function wikilinkUrl(string $page): string
    {
        /** @var string|null $resolved */
        $resolved = apply_filters('wpcarve_wikilink_url', null, $page);
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        if (!function_exists('get_page_by_path') || !function_exists('sanitize_title')) {
            return '#';
        }

        $post = get_page_by_path(sanitize_title($page), OBJECT, ['post', 'page']);
        if ($post === null || !function_exists('get_permalink')) {
            return '#';
        }

        return (string)get_permalink($post);
    }

    /**
     * @param \MarkupCarve\Carve\CarveConverter $converter
     * @param bool $forEditor
     * @param array<int, mixed>|null $bibliography
     * @param string $citationMode
     */
    private function addPostExtensions(
        CarveConverter $converter,
        bool $forEditor = false,
        ?array $bibliography = null,
        string $citationMode = 'numbered',
    ): void {
        $s = $this->settings;

        // carve-php ships more extensions than this method registers, and every
        // one of them is reachable through the `wpcarve_converter` action
        // without patching the plugin. docs/extensions.md accounts for each
        // absence - an unexplained one reads as an oversight rather than a
        // decision, and the question gets re-asked every release.
        //
        // Semantic inline spans round-trip; always on.
        $converter->addExtension(new SemanticSpanExtension());
        $converter->addExtension(new CitationsExtension(
            mode: $citationMode === 'author-date' ? 'author-date' : 'numbered',
            bibliography: $bibliography,
        ));

        // These turn ::: fenced divs into HTML5 <details>/tab interfaces the
        // visual editor cannot parse back (their content is lost on the round
        // trip). The editor seed keeps the raw generic <div class="..."> instead,
        // which carveDiv round-trips; the front end still gets the rich markup.
        // A quoted summary seeds as an admonition-title paragraph, which
        // carve-grammars' carveDiv captures as a title attribute and serializes
        // back as ::: class "title" - titled disclosures and admonitions
        // round-trip losslessly.
        if (!$forEditor) {
            $converter->addExtension(new CodeGroupExtension());
            $converter->addExtension(new TabsExtension());
            $converter->addExtension(new DetailsExtension());
            $converter->addExtension(new SpoilerExtension());
            $converter->addExtension(new ListTableExtension());
            // ```img SVG fence, rendered on the front end only. Sandbox mode
            // (the default): the sanitized SVG is encoded into a
            // data:image/svg+xml <img> the browser isolates. Inline mode is
            // deliberately NOT enabled - it would put author SVG in the live
            // page DOM, unsafe for user-submitted content. The editor seed
            // keeps the raw ```img source so it round-trips.
            $converter->addExtension(new ImgFenceExtension());
        }

        $shift = (int)($s['heading_shift'] ?? 0);
        if ($shift > 0 && !$forEditor) {
            $converter->addExtension(new HeadingLevelShiftExtension(shift: $shift));
        }

        if (!empty($s['toc_enabled']) && !$forEditor) {
            $position = (string)($s['toc_position'] ?? 'top');
            $converter->addExtension(new TableOfContentsExtension(
                minLevel: (int)($s['toc_min_level'] ?? 2),
                maxLevel: (int)($s['toc_max_level'] ?? 4),
                listType: (string)($s['toc_list_type'] ?? 'ul'),
                position: $position === 'none' ? null : $position,
                // Render the TOC as a collapsible disclosure (closed by default,
                // opens on click) so a long contents list stays out of the way.
                collapsible: true,
                summary: __('Table of Contents', 'carve-markup'),
            ));
        }

        if (!empty($s['permalinks_enabled']) && !$forEditor) {
            $converter->addExtension(new HeadingPermalinksExtension(showOnHover: true));
        }

        // Numbering is generated markup, not content, so it stays out of the
        // editor - serializing it back would freeze a site setting into the
        // post source, the same reason permalinks are gated above.
        if (!empty($s['heading_numbers']) && !$forEditor) {
            $converter->addExtension(new HeadingNumbersExtension());
        }

        if (!empty($s['external_links'])) {
            // ExternalLinksExtension sets `target` unconditionally, so an empty
            // one is written as `target=""` rather than omitted
            // (markup-carve/carve-php#1823). Marking a link as external and
            // opening it in a new tab are separate choices - the first is a
            // security and provenance hint, the second is a browsing
            // preference many sites deliberately do not impose - so the empty
            // attribute is stripped below rather than the option removed.
            $converter->addExtension(new ExternalLinksExtension(
                internalHosts: self::internalHosts(),
                target: !empty($s['external_links_new_tab']) ? '_blank' : '',
                nofollow: !empty($s['external_links_nofollow']),
            ));
        }

        if (!empty($s['mentions_enabled'])) {
            $converter->addExtension(new MentionsExtension(
                mentionUrl: self::archiveTemplate('wpcarve_mention_url', 'author'),
                tagUrl: self::archiveTemplate('wpcarve_tag_url', 'tag'),
            ));
        }

        if (!empty($s['wikilinks_enabled'])) {
            $converter->addExtension(new WikilinksExtension(
                urlGenerator: static fn (string $page): string => self::wikilinkUrl($page),
            ));
        }

        if (!empty($s['smart_quotes'])) {
            $converter->addExtension(new SmartQuotesExtension(locale: (string)($s['smart_quotes_locale'] ?? 'en')));
        }

        // Diagram renderers turn a fenced block (```mermaid, ```chart, ...) into a
        // rendered container whose fence language is lost on the HTML -> Carve
        // round trip, degrading the diagram to a plain code block. The editor seed
        // keeps the raw fence instead - it round-trips and stays editable.
        foreach ($forEditor ? [] : Diagrams::all() as $name => $diagram) {
            if (empty($s[Diagrams::settingKey($name)])) {
                continue;
            }
            $preset = $diagram['preset'] ?? null;
            if (is_string($preset) && method_exists(FencedRenderExtension::class, $preset)) {
                $converter->addExtension(FencedRenderExtension::$preset());

                continue;
            }
            $class = (string)($diagram['class'] ?? $name);
            $mode = ($diagram['mode'] ?? 'text') === 'json'
                ? FencedRenderExtension::MODE_JSON
                : FencedRenderExtension::MODE_TEXT;
            // The fence word(s) this instance claims: the class plus any
            // aliases (e.g. `puml` -> `<pre class="plantuml">`), so alias
            // fences work even without a dedicated carve-php preset factory.
            $languages = array_merge([$class], array_map('strval', (array)($diagram['aliases'] ?? [])));
            $converter->addExtension(new FencedRenderExtension(
                language: $languages,
                cssClass: $class,
                contentMode: $mode,
            ));
        }

        if (!empty($s['media_embed_enabled']) && class_exists(MediaEmbedExtension::class)) {
            $converter->addExtension(new MediaEmbedExtension());
        }

        // Torchlight rewrites <pre> blocks into highlighted line spans that the
        // visual editor cannot fold back into a code block - the whole fence
        // degrades to inline-code lines on round-trip. Editor ingest needs the
        // plain fence, like the other display-only extensions above.
        if (!empty($s['torchlight_enabled']) && !$forEditor && class_exists(TorchlightExtension::class)) {
            $converter->addExtension(new TorchlightExtension(
                (string)($s['torchlight_theme'] ?? 'github-light'),
                (bool)($s['torchlight_line_numbers'] ?? false),
                (string)($s['torchlight_theme_dark'] ?? ''),
            ));
        }
    }

    private function profile(string $name): ?Profile
    {
        return match ($name) {
            'full' => Profile::full(),
            'comment' => Profile::comment(),
            'minimal' => Profile::minimal(),
            'none' => null,
            default => Profile::article(),
        };
    }
}
