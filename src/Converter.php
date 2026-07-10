<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\HeadingLevelShiftExtension;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\ListTableExtension;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Extension\SmartQuotesExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TabNormalizeExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use MarkupCarve\Carve\SafeMode;
use MarkupCarve\MediaEmbed\MediaEmbedExtension;
use WpCarve\Extension\TorchlightExtension;

/**
 * WordPress-facing wrapper around the carve-php CarveConverter.
 *
 * Builds a converter per context (post vs comment) applying the configured
 * content profile, safe mode and feature extensions, and renders Carve to
 * HTML.
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
    public function toHtml(string $carve, string $context = 'post', ?string $profileOverride = null, ?bool $safe = null): string
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

        // Site-wide abbreviation defs are prepended for rendering, but the visual
        // editor seed must not carry them: they render <abbr> spans that serialize
        // back into per-post source (freezing a global setting into the post). So
        // the editor context renders the source alone.
        $abbrevDefs = $context === 'editor' ? '' : $this->abbreviationDefs();
        $html = $this->converterFor($context, $profileOverride, $safe)->convert($abbrevDefs . $carve);

        // JSON diagram configs (chart, vega-lite) ship in a script tag the
        // engine emits - but wp_kses strips every script tag, and wptexturize
        // then curls the quotes of the leftover text, so the config could
        // never reach the front-end JS intact. Move it into a data attribute:
        // kses allows data-* globally and texturize ignores attributes.
        // Chart.js configs additionally get their dataset appended as a plain
        // table - readable without JS, indexable, and screen-reader friendly.
        $html = (string)preg_replace_callback(
            '/<div class="([^"]*)">\s*<script type="application\/json">(.*?)<\/script>\s*<\/div>/s',
            static function (array $m): string {
                $out = '<div class="' . $m[1] . '" data-carve-json="' . esc_attr($m[2]) . '"></div>';
                if (preg_match('/(^| )chart( |$)/', $m[1]) === 1) {
                    $out .= self::chartDataTable($m[2]);
                }

                return $out;
            },
            $html,
        );

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

    private function converterFor(string $context, ?string $profileOverride = null, ?bool $safe = null): CarveConverter
    {
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
            . ($profileOverride !== null && $profileOverride !== '' ? ':' . $profileOverride : '');
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
            $this->addPostExtensions($converter, $isEditor);
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
     * @param \MarkupCarve\Carve\CarveConverter $converter
     * @param bool $forEditor Rendering to seed the visual editor. Skips generated
     *   markup that cannot survive the HTML -> Carve round trip (TOC, heading
     *   permalinks, heading level shift, diagram renderers), mirroring wp-djot's
     *   editor render path. All content-authoring extensions stay enabled.
     *   (Abbreviation defs are handled separately in toHtml().)
     */
    private function addPostExtensions(CarveConverter $converter, bool $forEditor = false): void
    {
        $s = $this->settings;

        // Semantic inline spans round-trip; always on.
        $converter->addExtension(new SemanticSpanExtension());

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
            $converter->addExtension(new HeadingPermalinksExtension());
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
            $converter->addExtension(new FencedRenderExtension(
                language: $class,
                cssClass: $class,
                contentMode: $mode,
            ));
        }

        if (!empty($s['media_embed_enabled']) && class_exists(MediaEmbedExtension::class)) {
            $converter->addExtension(new MediaEmbedExtension());
        }

        if (!empty($s['torchlight_enabled']) && class_exists(TorchlightExtension::class)) {
            $converter->addExtension(new TorchlightExtension(
                (string)($s['torchlight_theme'] ?? 'github-light'),
                (bool)($s['torchlight_line_numbers'] ?? false),
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
