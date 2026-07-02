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
        $carve = (string)apply_filters('wp_carve_source', $carve, $context);

        $html = $this->converterFor($context, $profileOverride, $safe)->convert($this->abbreviationDefs() . $carve);

        /**
         * Filter the rendered HTML before it is returned to WordPress.
         *
         * @param string $html The rendered HTML.
         * @param string $carve The original Carve source.
         * @param string $context 'post', 'comment', or 'editor'.
         */
        return (string)apply_filters('wp_carve_rendered_html', $html, $carve, $context);
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

    private function converterFor(string $context, ?string $profileOverride = null, ?bool $safe = null): CarveConverter
    {
        $isComment = $context === 'comment';
        // The visual editor seeds itself from rendered HTML and serializes it
        // back to Carve source on every edit. Generated, non-round-trippable
        // markup (a TOC nav, heading permalink anchors, shifted heading levels)
        // would be frozen into the source on that round trip, so the 'editor'
        // context renders like a post but omits those extensions.
        $isEditor = $context === 'editor';
        $safeMode = $isComment ? true : ($safe ?? (bool)($this->settings['safe_mode'] ?? true));
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
        do_action('wp_carve_converter', $converter, $context);

        return $this->cache[$cacheKey] = $converter;
    }

    /**
     * @param \MarkupCarve\Carve\CarveConverter $converter
     * @param bool $forEditor Rendering to seed the visual editor. Skips generated
     *   markup that cannot survive the HTML -> Carve round trip (TOC, heading
     *   permalinks, heading level shift), mirroring wp-djot's editor render path.
     *   All content-authoring extensions stay enabled.
     */
    private function addPostExtensions(CarveConverter $converter, bool $forEditor = false): void
    {
        $s = $this->settings;

        // Always-on content extensions (parity with wp-djot): tabbed code groups,
        // generic tabs, details/spoiler disclosures, and semantic inline spans.
        $converter->addExtension(new CodeGroupExtension());
        $converter->addExtension(new TabsExtension());
        $converter->addExtension(new DetailsExtension());
        $converter->addExtension(new SpoilerExtension());
        $converter->addExtension(new SemanticSpanExtension());

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

        foreach (Diagrams::all() as $name => $diagram) {
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
