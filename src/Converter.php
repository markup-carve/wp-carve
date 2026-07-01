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
     * Render Carve source to HTML for a given context ('post' or 'comment').
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

        $html = $this->converterFor($context, $profileOverride, $safe)->convert($this->abbreviationDefs() . $carve);

        /**
         * Filter the rendered HTML before it is returned to WordPress.
         *
         * @param string $html The rendered HTML.
         * @param string $carve The original Carve source.
         * @param string $context 'post' or 'comment'.
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
        $cacheKey = $context
            . ($profileOverride !== null && $profileOverride !== '' ? ':' . $profileOverride : '')
            . ($safe !== null ? ($safe ? ':safe' : ':unsafe') : '');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $isComment = $context === 'comment';
        $profileName = $profileOverride !== null && $profileOverride !== ''
            ? $profileOverride
            : (string)($this->settings[$isComment ? 'comment_profile' : 'post_profile'] ?? ($isComment ? 'comment' : 'article'));
        $safeMode = $isComment ? true : ($safe ?? (bool)($this->settings['safe_mode'] ?? true));
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
            $this->addPostExtensions($converter);
        }

        /**
         * Allow add-ons to register further carve-php extensions on the converter.
         *
         * @param \MarkupCarve\Carve\CarveConverter $converter
         * @param string $context
         */
        do_action('wp_carve_converter', $converter, $context);

        return $this->cache[$cacheKey] = $converter;
    }

    private function addPostExtensions(CarveConverter $converter): void
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
        if ($shift > 0) {
            $converter->addExtension(new HeadingLevelShiftExtension(shift: $shift));
        }

        if (!empty($s['toc_enabled'])) {
            $position = (string)($s['toc_position'] ?? 'top');
            $converter->addExtension(new TableOfContentsExtension(
                minLevel: (int)($s['toc_min_level'] ?? 2),
                maxLevel: (int)($s['toc_max_level'] ?? 4),
                listType: (string)($s['toc_list_type'] ?? 'ul'),
                position: $position === 'none' ? null : $position,
            ));
        }

        if (!empty($s['permalinks_enabled'])) {
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
