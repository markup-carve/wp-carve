<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use Carve\CarveConverter;
use Carve\Converter\MarkdownToCarve;
use Carve\Extension\FencedRenderExtension;
use Carve\Extension\HeadingLevelShiftExtension;
use Carve\Extension\HeadingPermalinksExtension;
use Carve\Extension\SmartQuotesExtension;
use Carve\Extension\TableOfContentsExtension;
use Carve\Extension\TabNormalizeExtension;
use Carve\Profile;
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
     * @var array<string, \Carve\CarveConverter>
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
     */
    public function toHtml(string $carve, string $context = 'post'): string
    {
        if (trim($carve) === '') {
            return '';
        }

        // Markdown mode: rewrite Markdown into Carve before rendering, so legacy
        // Markdown content renders correctly.
        if (!empty($this->settings['markdown_mode'])) {
            $carve = (new MarkdownToCarve())->convert($carve);
        }

        $html = $this->converterFor($context)->convert($carve);

        /**
         * Filter the rendered HTML before it is returned to WordPress.
         *
         * @param string $html The rendered HTML.
         * @param string $carve The original Carve source.
         * @param string $context 'post' or 'comment'.
         */
        return (string)apply_filters('wp_carve_rendered_html', $html, $carve, $context);
    }

    private function converterFor(string $context): CarveConverter
    {
        if (isset($this->cache[$context])) {
            return $this->cache[$context];
        }

        $isComment = $context === 'comment';
        $profileName = (string)($this->settings[$isComment ? 'comment_profile' : 'post_profile'] ?? ($isComment ? 'comment' : 'article'));
        $safeMode = $isComment ? true : (bool)($this->settings['safe_mode'] ?? true);

        $converter = new CarveConverter(
            safeMode: $safeMode,
            profile: $this->profile($profileName),
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
         * @param \Carve\CarveConverter $converter
         * @param string $context
         */
        do_action('wp_carve_converter', $converter, $context);

        return $this->cache[$context] = $converter;
    }

    private function addPostExtensions(CarveConverter $converter): void
    {
        $s = $this->settings;

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

        if (!empty($s['mermaid_enabled'])) {
            $converter->addExtension(FencedRenderExtension::mermaid());
        }

        if (!empty($s['torchlight_enabled']) && class_exists(TorchlightExtension::class)) {
            $converter->addExtension(new TorchlightExtension((string)($s['torchlight_theme'] ?? 'github-light')));
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
