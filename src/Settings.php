<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings store. Wraps a single `wpcarve_settings` option with typed
 * defaults so the rest of the plugin reads a fully-populated array.
 */
class Settings
{
    /**
     * @var string
     */
    public const OPTION = 'wpcarve_settings';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [
            'enable_posts' => true,
            'enable_pages' => true,
            'enable_comments' => false,
            'enable_shortcode' => true,
            'enable_excerpts' => true,
            'post_profile' => 'article',
            'comment_profile' => 'comment',
            'post_soft_break' => 'newline',
            'comment_soft_break' => 'newline',
            'abbreviations' => '',
            'heading_shift' => 0,
            'toc_enabled' => false,
            'toc_position' => 'top',
            'toc_min_level' => 2,
            'toc_max_level' => 4,
            'toc_list_type' => 'ul',
            'permalinks_enabled' => false,
            'smart_quotes' => false,
            'smart_quotes_locale' => 'en',
            'torchlight_enabled' => false,
            'torchlight_theme' => 'github-light',
            'torchlight_line_numbers' => false,
            'diagram_export' => false,
            'normalize_tabs' => false,
            'media_embed_enabled' => false,
            'tab_width' => 2,
            // Innovation toggles.
            'live_preview' => true,
            'visual_editor_mode' => 'disabled',
            'paste_ingest' => true,
            'frontmatter_meta' => true,
            'render_cache' => true,
        ];

        // Diagram renderer toggles (default off), derived from the registry so
        // custom renderers added via the filter also get a persisted setting.
        foreach (array_keys(Diagrams::all()) as $name) {
            $defaults[Diagrams::settingKey($name)] = false;
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        // Back-compat: the boolean `visual_editor` became the 3-way
        // `visual_editor_mode` (disabled / enabled / enabled_default).
        if (!isset($stored['visual_editor_mode']) && isset($stored['visual_editor'])) {
            $stored['visual_editor_mode'] = $stored['visual_editor'] ? 'enabled' : 'disabled';
        }
        unset($stored['visual_editor']);

        return $stored + self::defaults();
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Settings that never change the post HTML the render cache stores: surface
     * gates, admin/editor features, and comment-only rendering options (the
     * cache is written and read for the 'post' context only, so comment profile
     * and soft-break settings cannot affect it). Excluded from the render
     * signature so toggling them does not needlessly invalidate cached renders.
     *
     * @var array<int, string>
     */
    private const NON_RENDER_KEYS = [
        'enable_posts',
        'enable_pages',
        'enable_comments',
        'enable_shortcode',
        'enable_excerpts',
        'live_preview',
        'visual_editor_mode',
        'paste_ingest',
        'frontmatter_meta',
        'render_cache',
        'comment_profile',
        'comment_soft_break',
        'diagram_export',
    ];

    /**
     * A short, stable fingerprint of every setting that influences rendered
     * output (profiles, soft breaks, abbreviations, TOC, permalinks, smart
     * quotes, torchlight, diagram toggles, ...). Folded into the render cache
     * key so changing any render-affecting setting invalidates stale cached
     * HTML - surface/editor-only toggles (see NON_RENDER_KEYS) do not.
     *
     * A denylist rather than an allowlist so a newly added render setting is
     * covered by default (fresh render) instead of silently serving stale HTML.
     */
    public static function renderSignature(): string
    {
        $settings = self::all();
        foreach (self::NON_RENDER_KEYS as $key) {
            unset($settings[$key]);
        }
        ksort($settings);

        return substr(md5((string)json_encode($settings)), 0, 12);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        update_option(self::OPTION, $values + self::all());
    }
}
