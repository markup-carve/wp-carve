<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

use Carve\CarveConverter;
use Carve\Extension\HeadingPermalinksExtension;
use Carve\Extension\MermaidExtension;
use Carve\Extension\SmartQuotesExtension;
use Carve\Extension\TableOfContentsExtension;
use Carve\Extension\TabNormalizeExtension;
use Carve\Profile;

/**
 * WordPress-facing wrapper around the carve-php CarveConverter.
 *
 * Builds a converter per context (post vs comment) applying the configured
 * content profile, safe mode and feature extensions, and renders Carve to HTML.
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

        $renderer = $converter->getHtmlRenderer();
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

        unset($renderer);

        return $this->cache[$context] = $converter;
    }

    private function addPostExtensions(CarveConverter $converter): void
    {
        $s = $this->settings;

        if (!empty($s['toc_enabled'])) {
            $converter->addExtension(new TableOfContentsExtension(
                minLevel: (int)($s['toc_min_level'] ?? 2),
                maxLevel: (int)($s['toc_max_level'] ?? 4),
            ));
        }

        if (!empty($s['permalinks_enabled'])) {
            $converter->addExtension(new HeadingPermalinksExtension());
        }

        if (!empty($s['smart_quotes'])) {
            $converter->addExtension(new SmartQuotesExtension());
        }

        if (!empty($s['mermaid_enabled'])) {
            $converter->addExtension(new MermaidExtension());
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
