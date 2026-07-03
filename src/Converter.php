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
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Extension\SmartQuotesExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TabNormalizeExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
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

        // Defense in depth: in safe mode the engine already escapes raw HTML and
        // strips event handlers, but the generated markup additionally passes
        // through wp_kses so only allowlisted tags/attributes ever reach output.
        // Unsafe mode is reserved for unfiltered_html authors (see
        // Plugin::safeForAuthor()), matching how core treats their post content.
        if ($this->resolveSafeMode($context, $safe)) {
            $html = self::sanitizeHtml($html);
        }

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
     * Effective safe mode for a render: comments are always safe; elsewhere an
     * explicit $safe wins, then the site setting (default on).
     */
    private function resolveSafeMode(string $context, ?bool $safe): bool
    {
        if ($context === 'comment') {
            return true;
        }

        return $safe ?? (bool)($this->settings['safe_mode'] ?? true);
    }

    /**
     * Run rendered HTML through wp_kses with the Carve allowlist. No-op outside
     * WordPress (unit tests); the engine's own safe mode still applies there.
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
        $safeMode = $this->resolveSafeMode($context, $safe);
        // Key on the RESOLVED safe value (not the nullable input) so the cache
        // can never return a converter whose safe mode differs from behavior.
        $cacheKey = $context
            . ($profileOverride !== null && $profileOverride !== '' ? ':' . $profileOverride : '')
            . ($safeMode ? ':safe' : ':unsafe');
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
        // Known limit shared with titled admonitions: a quoted summary seeds as
        // an admonition-title paragraph, and carve-grammars serializes it back
        // into the body (text kept, summary wrapper lost) - the block's lossy
        // guard warns before such an edit is applied.
        if (!$forEditor) {
            $converter->addExtension(new CodeGroupExtension());
            $converter->addExtension(new TabsExtension());
            $converter->addExtension(new DetailsExtension());
            $converter->addExtension(new SpoilerExtension());
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
