<?php

declare(strict_types=1);

namespace WpCarve;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings store. Wraps a single `wp_carve_settings` option with typed
 * defaults so the rest of the plugin reads a fully-populated array.
 */
class Settings
{
    /**
     * @var string
     */
    public const OPTION = 'wp_carve_settings';

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
            'safe_mode' => true,
            'post_profile' => 'article',
            'comment_profile' => 'comment',
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
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        update_option(self::OPTION, $values + self::all());
    }
}
